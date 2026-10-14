<?php
declare(strict_types=1);

require_once __DIR__ . '/payment-service.php';
require_once __DIR__ . '/receipt-service.php';
require_once __DIR__ . '/audit-service.php';

function payment_verification_gateway_payment_matches(
    array $gatewayPayment,
    string $expectedOrderId,
    int $expectedAmountPaise,
    string $expectedCurrency = 'INR'
): bool {
    return is_string($gatewayPayment['id'] ?? null)
        && preg_match('/^pay_[A-Za-z0-9]+$/D', $gatewayPayment['id']) === 1
        && ($gatewayPayment['order_id'] ?? null) === $expectedOrderId
        && ($gatewayPayment['amount'] ?? null) === $expectedAmountPaise
        && strtoupper((string) ($gatewayPayment['currency'] ?? '')) === $expectedCurrency;
}

function payment_verification_gateway_payment_is_captured(
    array $gatewayPayment,
    string $expectedOrderId,
    int $expectedAmountPaise,
    string $expectedCurrency = 'INR'
): bool {
    return ($gatewayPayment['status'] ?? null) === 'captured'
        && payment_verification_gateway_payment_matches(
            $gatewayPayment,
            $expectedOrderId,
            $expectedAmountPaise,
            $expectedCurrency
        );
}

function payment_verification_recovery_state(array $paymentStatuses): string
{
    if ($paymentStatuses === []) {
        return 'no_payment';
    }
    if (in_array('captured', $paymentStatuses, true)) {
        return 'captured';
    }
    foreach ($paymentStatuses as $status) {
        if ($status !== 'failed') {
            return 'pending';
        }
    }
    return 'failed';
}

function payment_verification_inspect_gateway_payments(
    array $listedPayments,
    callable $fetchPayment,
    string $gatewayOrderId,
    int $expectedAmountPaise,
    string $expectedCurrency
): array {
    $paymentStatuses = [];
    foreach ($listedPayments as $listedPayment) {
        if (!is_array($listedPayment) || !is_string($listedPayment['id'] ?? null)
            || preg_match('/^pay_[A-Za-z0-9]+$/D', $listedPayment['id']) !== 1) {
            throw new UnexpectedValueException('Razorpay returned a payment without a valid identifier.');
        }
        $gatewayPayment = $fetchPayment($listedPayment['id']);
        if (!is_array($gatewayPayment)
            || !payment_verification_gateway_payment_matches(
                $gatewayPayment,
                $gatewayOrderId,
                $expectedAmountPaise,
                $expectedCurrency
            )) {
            throw new DomainException('A Razorpay payment does not match the saved order.');
        }

        $status = is_string($gatewayPayment['status'] ?? null)
            ? $gatewayPayment['status']
            : '';
        $paymentStatuses[] = $status;
        if ($status === 'captured') {
            return ['state' => 'captured', 'payment' => $gatewayPayment];
        }
    }

    return [
        'state' => payment_verification_recovery_state($paymentStatuses),
        'payment' => null,
    ];
}

function payment_verification_mark_order_attempts_failed(
    mysqli $db,
    int $institutionId,
    int $orderId
): bool {
    $db->begin_transaction();
    try {
        $orderStatement = $db->prepare(
            'SELECT status FROM payment_orders
             WHERE institution_id = ? AND id = ? FOR UPDATE'
        );
        $orderStatement->bind_param('ii', $institutionId, $orderId);
        $orderStatement->execute();
        $order = $orderStatement->get_result()->fetch_assoc();
        $orderStatement->close();
        if (!is_array($order)) {
            throw new DomainException('The payment order is not available for reconciliation.');
        }
        if ($order['status'] === 'paid') {
            $db->commit();
            return true;
        }
        $paymentStatement = $db->prepare(
            "SELECT id FROM payments
             WHERE institution_id = ? AND payment_order_id = ? AND status = 'success'
             LIMIT 1 FOR UPDATE"
        );
        $paymentStatement->bind_param('ii', $institutionId, $orderId);
        $paymentStatement->execute();
        $successfulPayment = $paymentStatement->get_result()->fetch_assoc();
        $paymentStatement->close();
        if (is_array($successfulPayment)) {
            $db->commit();
            return true;
        }

        $attemptStatement = $db->prepare(
            "SELECT id, status FROM payment_attempts
             WHERE institution_id = ? AND payment_order_id = ?
               AND status IN ('initiated', 'pending')
             ORDER BY attempt_number FOR UPDATE"
        );
        $attemptStatement->bind_param('ii', $institutionId, $orderId);
        $attemptStatement->execute();
        $attempts = $attemptStatement->get_result()->fetch_all(MYSQLI_ASSOC);
        $attemptStatement->close();
        foreach ($attempts as $attempt) {
            $attemptId = (int) $attempt['id'];
            if (payment_service_set_attempt_status($db, $institutionId, $orderId, $attemptId, 'failed')) {
                write_audit_log(
                    $db,
                    $institutionId,
                    null,
                    'system',
                    'payment.attempt_failed',
                    'payment_attempt',
                    (string) $attemptId,
                    ['status' => (string) $attempt['status']],
                    ['status' => 'failed', 'verified_by' => 'razorpay_api']
                );
            }
        }
        $db->commit();
        return false;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function payment_verification_reconcile_gateway_order(
    mysqli $db,
    int $institutionId,
    int $orderId,
    string $gatewayOrderId,
    ?callable $fetchOrderPayments = null,
    ?callable $fetchPayment = null
): string {
    if ($institutionId < 1 || $orderId < 1
        || preg_match('/^order_[A-Za-z0-9]+$/D', $gatewayOrderId) !== 1) {
        throw new InvalidArgumentException('The payment reconciliation request is invalid.');
    }

    $orderStatement = $db->prepare(
        "SELECT amount, currency, status FROM payment_orders
         WHERE institution_id = ? AND id = ? AND gateway = 'razorpay'
           AND gateway_order_id = ? LIMIT 1"
    );
    $orderStatement->bind_param('iis', $institutionId, $orderId, $gatewayOrderId);
    $orderStatement->execute();
    $order = $orderStatement->get_result()->fetch_assoc();
    $orderStatement->close();
    if (!is_array($order)) {
        throw new DomainException('The Razorpay order does not belong to this institution and payment order.');
    }
    if ($order['status'] === 'paid') {
        return 'success';
    }

    $fetchOrderPayments ??= 'razorpay_fetch_order_payments';
    $fetchPayment ??= 'razorpay_fetch_payment';
    if (!is_callable($fetchOrderPayments) || !is_callable($fetchPayment)) {
        throw new LogicException('The Razorpay reconciliation providers are unavailable.');
    }
    $listedPayments = $fetchOrderPayments($gatewayOrderId);
    if (!is_array($listedPayments)) {
        throw new UnexpectedValueException('Razorpay returned an invalid order payment list.');
    }

    $expectedAmountPaise = payment_money_to_paise((string) $order['amount']);
    $expectedCurrency = strtoupper((string) $order['currency']);
    $inspection = payment_verification_inspect_gateway_payments(
        $listedPayments,
        $fetchPayment,
        $gatewayOrderId,
        $expectedAmountPaise,
        $expectedCurrency
    );
    if ($inspection['state'] === 'captured' && is_array($inspection['payment'])) {
        payment_verification_settle_captured(
            $db,
            $institutionId,
            $gatewayOrderId,
            $inspection['payment']
        );
        return 'success';
    }

    $state = (string) $inspection['state'];
    if ($state === 'failed') {
        if (payment_verification_mark_order_attempts_failed($db, $institutionId, $orderId)) {
            return 'success';
        }
    }
    return $state;
}

function payment_verification_installment_is_fully_paid(
        string $amountPaid,
        string $dueAmount,
        string $penaltyAmount,
        string $adjustmentAmount,
        string $discountAmount
): bool {
        $totalDuePaise = payment_money_to_paise($dueAmount)
            + payment_money_to_paise($penaltyAmount)
            + payment_money_to_paise($adjustmentAmount)
            - payment_money_to_paise($discountAmount);

        return payment_money_to_paise($amountPaid) >= $totalDuePaise;
}

function payment_verification_settle_captured(
    mysqli $db,
    int $institutionId,
    string $gatewayOrderId,
    array $gatewayPayment
): int {
    if (!is_int($gatewayPayment['amount'] ?? null)
        || $gatewayPayment['amount'] < 1
        || !payment_verification_gateway_payment_is_captured(
            $gatewayPayment,
            $gatewayOrderId,
            (int) $gatewayPayment['amount']
        )) {
        throw new DomainException('The gateway payment is not a verified captured payment.');
    }

    $db->begin_transaction();
    try {
        $orderStatement = $db->prepare(
            "SELECT id, institution_id, student_id, parent_id, installment_id, order_number,
                    amount, currency, status, gateway_order_id
             FROM payment_orders
             WHERE institution_id = ? AND gateway = 'razorpay' AND gateway_order_id = ?
             LIMIT 2 FOR UPDATE"
        );
        $orderStatement->bind_param('is', $institutionId, $gatewayOrderId);
        $orderStatement->execute();
        $orders = $orderStatement->get_result()->fetch_all(MYSQLI_ASSOC);
        $orderStatement->close();
        if (count($orders) !== 1 || $orders[0]['installment_id'] === null) {
            throw new DomainException('The gateway order is not linked to a payable installment.');
        }

        $order = $orders[0];
        $orderId = (int) $order['id'];
        $expectedPaise = payment_money_to_paise((string) $order['amount']);
        if ($gatewayPayment['amount'] !== $expectedPaise
            || strtoupper((string) $order['currency']) !== 'INR') {
            throw new DomainException('The captured amount does not match the saved payment order.');
        }

        $existingPayment = $db->prepare(
            'SELECT id, gateway_transaction_id, status FROM payments
             WHERE payment_order_id = ? LIMIT 1 FOR UPDATE'
        );
        $existingPayment->bind_param('i', $orderId);
        $existingPayment->execute();
        $existing = $existingPayment->get_result()->fetch_assoc();
        $existingPayment->close();
        if (is_array($existing) && $existing['status'] === 'success') {
            if (!hash_equals((string) $existing['gateway_transaction_id'], $gatewayPayment['id'])) {
                throw new DomainException('A different captured payment is already linked to this order.');
            }
            $db->commit();
            return (int) $existing['id'];
        }

        $installmentId = (int) $order['installment_id'];
        $balanceStatement = $db->prepare(
            'SELECT i.due_amount, i.amount_paid, i.penalty_amount, i.discount_amount,
                    i.adjustment_amount, i.status AS installment_status,
                    a.outstanding_amount, a.status AS assignment_status
             FROM installments i
             JOIN student_fee_assignments a
               ON a.institution_id = i.institution_id AND a.id = i.student_fee_assignment_id
             WHERE i.institution_id = ? AND i.id = ? FOR UPDATE'
        );
        $balanceStatement->bind_param('ii', $institutionId, $installmentId);
        $balanceStatement->execute();
        $balance = $balanceStatement->get_result()->fetch_assoc();
        $balanceStatement->close();
        if (!is_array($balance) || $balance['assignment_status'] !== 'active'
            || !in_array($balance['installment_status'], ['pending', 'partial', 'overdue'], true)) {
            throw new DomainException('The installment is no longer available for settlement.');
        }
        $remainingPaise = payment_calculate_installment_balance_paise(
            (string) $balance['due_amount'],
            (string) $balance['penalty_amount'],
            (string) $balance['adjustment_amount'],
            (string) $balance['discount_amount'],
            (string) $balance['amount_paid'],
            (string) $balance['outstanding_amount']
        );
        if ($expectedPaise > $remainingPaise) {
            throw new DomainException('The saved balance changed after this order was created.');
        }

        $paymentStatus = 'success';
        $paymentGateway = 'razorpay';
        $currency = 'INR';
        $idempotencyKey = 'razorpay:' . $gatewayPayment['id'];
        $gatewayReference = $gatewayOrderId;
        $verifiedAt = date('Y-m-d H:i:s');
        if (is_array($existing)) {
            $paymentId = (int) $existing['id'];
            $updatePayment = $db->prepare(
                "UPDATE payments
                 SET gateway_transaction_id = ?, gateway_reference = ?, amount = ?, currency = ?,
                     status = 'success', verified_at = ?, captured_at = ?, metadata = ?
                 WHERE institution_id = ? AND id = ? AND payment_order_id = ?"
            );
            $metadata = json_encode(['verified_by' => 'razorpay_api'], JSON_THROW_ON_ERROR);
            $amount = payment_paise_to_money($expectedPaise);
            $updatePayment->bind_param(
                'sssssssiii',
                $gatewayPayment['id'],
                $gatewayReference,
                $amount,
                $currency,
                $verifiedAt,
                $verifiedAt,
                $metadata,
                $institutionId,
                $paymentId,
                $orderId
            );
            $updatePayment->execute();
            $updatePayment->close();
        } else {
            $amount = payment_paise_to_money($expectedPaise);
            $installmentIdForPayment = (int) $order['installment_id'];
            $studentId = (int) $order['student_id'];
            $parentId = (int) $order['parent_id'];
            $metadata = json_encode(['verified_by' => 'razorpay_api'], JSON_THROW_ON_ERROR);
            $insertPayment = $db->prepare(
                'INSERT INTO payments (
                    institution_id, payment_order_id, installment_id, student_id, parent_id,
                    gateway, gateway_transaction_id, gateway_reference, amount, currency,
                    status, verified_at, captured_at, idempotency_key, metadata
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insertPayment->bind_param(
                'iiiiissssssssss',
                $institutionId,
                $orderId,
                $installmentIdForPayment,
                $studentId,
                $parentId,
                $paymentGateway,
                $gatewayPayment['id'],
                $gatewayReference,
                $amount,
                $currency,
                $paymentStatus,
                $verifiedAt,
                $verifiedAt,
                $idempotencyKey,
                $metadata
            );
            $insertPayment->execute();
            $paymentId = (int) $db->insert_id;
            $insertPayment->close();
        }

        $newInstallmentPaid = payment_paise_to_money(
            payment_money_to_paise((string) $balance['amount_paid']) + $expectedPaise
        );
        $fullyPaid = payment_verification_installment_is_fully_paid(
            $newInstallmentPaid,
            (string) $balance['due_amount'],
            (string) $balance['penalty_amount'],
            (string) $balance['adjustment_amount'],
            (string) $balance['discount_amount']
        );
        $installmentStatus = $fullyPaid ? 'paid' : 'partial';
        $updateInstallment = $db->prepare(
            "UPDATE installments
             SET amount_paid = ?, status = ?, paid_at = CASE WHEN ? = 'paid' THEN CURRENT_TIMESTAMP ELSE NULL END
             WHERE institution_id = ? AND id = ?"
        );
        $updateInstallment->bind_param(
            'sssii',
            $newInstallmentPaid,
            $installmentStatus,
            $installmentStatus,
            $institutionId,
            $installmentId
        );
        $updateInstallment->execute();
        $updateInstallment->close();

        $updateAssignment = $db->prepare(
            "UPDATE student_fee_assignments
             SET total_paid = total_paid + ?, outstanding_amount = GREATEST(0, outstanding_amount - ?),
                 status = CASE WHEN GREATEST(0, outstanding_amount - ?) = 0 THEN 'completed' ELSE status END
             WHERE institution_id = ? AND id = (
                 SELECT student_fee_assignment_id FROM installments
                 WHERE institution_id = ? AND id = ?
             )"
        );
        $amount = payment_paise_to_money($expectedPaise);
        $updateAssignment->bind_param(
            'sssiii',
            $amount,
            $amount,
            $amount,
            $institutionId,
            $institutionId,
            $installmentId
        );
        $updateAssignment->execute();
        $updateAssignment->close();

        $paidOrderStatus = 'paid';
        $updateOrder = $db->prepare(
            'UPDATE payment_orders SET status = ? WHERE institution_id = ? AND id = ?'
        );
        $updateOrder->bind_param('sii', $paidOrderStatus, $institutionId, $orderId);
        $updateOrder->execute();
        $updateOrder->close();

        $attemptQuery = $db->prepare(
            "SELECT id FROM payment_attempts
             WHERE institution_id = ? AND payment_order_id = ? AND status IN ('initiated', 'pending')
             ORDER BY attempt_number DESC LIMIT 1 FOR UPDATE"
        );
        $attemptQuery->bind_param('ii', $institutionId, $orderId);
        $attemptQuery->execute();
        $attempt = $attemptQuery->get_result()->fetch_assoc();
        $attemptQuery->close();
        if (is_array($attempt)) {
            payment_service_set_attempt_status(
                $db,
                $institutionId,
                $orderId,
                (int) $attempt['id'],
                'success',
                $gatewayPayment['id']
            );
        }

        receipt_service_issue_for_payment(
            $db,
            $institutionId,
            $paymentId,
            (int) $order['student_id'],
            (int) $order['parent_id'],
            $amount,
            (string) $order['order_number']
        );
        write_audit_log(
            $db,
            $institutionId,
            null,
            'system',
            'payment.verified',
            'payment',
            (string) $paymentId,
            null,
            ['order_number' => (string) $order['order_number'], 'amount' => $amount]
        );
        $db->commit();
        return $paymentId;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function payment_verification_verify_checkout(
    mysqli $db,
    int $parentId,
    int $institutionId,
    int $orderId,
    string $gatewayOrderId,
    string $gatewayPaymentId,
    string $signature
): int {
    $config = payment_require_razorpay_ready();
    $order = payment_service_get_parent_order($db, $parentId, $institutionId, $orderId, true);
    if ($order === null
        || !is_string($order['gateway_order_id'])
        || !hash_equals((string) $order['gateway_order_id'], $gatewayOrderId)) {
        throw new DomainException('The payment order is unavailable or has already been settled.');
    }
    if (!razorpay_verify_checkout_signature(
        $gatewayOrderId,
        $gatewayPaymentId,
        $signature,
        $config['razorpay_key_secret']
    )) {
        throw new DomainException('The Razorpay checkout signature could not be verified.');
    }

    $gatewayPayment = razorpay_fetch_payment($gatewayPaymentId);
    if (($gatewayPayment['id'] ?? null) !== $gatewayPaymentId
        || ($gatewayPayment['order_id'] ?? null) !== $gatewayOrderId) {
        throw new DomainException('The Razorpay payment does not match this checkout.');
    }
    if (($gatewayPayment['status'] ?? null) !== 'captured') {
        return 0;
    }

    return payment_verification_settle_captured(
        $db,
        $institutionId,
        $gatewayOrderId,
        $gatewayPayment
    );
}

function payment_reconciliation_check_parent_order(
    mysqli $db,
    int $parentId,
    int $institutionId,
    int $orderId
): string {
    $order = payment_service_get_parent_order($db, $parentId, $institutionId, $orderId, true);
    if ($order === null) {
        throw new DomainException('The payment order is not available to this parent.');
    }
    if ($order['status'] === 'paid') {
        return 'success';
    }
    if (!in_array($order['status'], ['pending', 'created', 'authorized', 'failed', 'cancelled'], true)
        || !is_string($order['gateway_order_id'])
        || $order['gateway_order_id'] === '') {
        return $order['attempt_status'] === 'failed' || $order['status'] === 'failed'
            ? 'failure'
            : 'pending';
    }

    $state = payment_verification_reconcile_gateway_order(
        $db,
        $institutionId,
        $orderId,
        (string) $order['gateway_order_id']
    );
    return match ($state) {
        'success' => 'success',
        'failed' => 'failure',
        default => 'pending',
    };
}