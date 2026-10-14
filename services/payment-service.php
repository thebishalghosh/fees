<?php
declare(strict_types=1);

require_once __DIR__ . '/../integrations/payment-gateway/create-order.php';
require_once __DIR__ . '/../integrations/payment-gateway/verify-signature.php';
require_once __DIR__ . '/audit-service.php';

function payment_money_to_paise(string $amount): int
{
    if (preg_match('/^(0|[1-9]\d{0,9})(?:\.(\d{1,2}))?$/D', $amount) !== 1) {
        throw new InvalidArgumentException('The payment amount is invalid.');
    }
    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
    return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
}

function payment_paise_to_money(int $paise): string
{
    if ($paise < 0) {
        throw new InvalidArgumentException('The payment amount cannot be negative.');
    }
    return intdiv($paise, 100) . '.' . str_pad((string) ($paise % 100), 2, '0', STR_PAD_LEFT);
}

function payment_calculate_installment_balance_paise(
    string $dueAmount,
    string $penaltyAmount,
    string $adjustmentAmount,
    string $discountAmount,
    string $amountPaid,
    string $assignmentOutstanding
): int {
    $installmentBalance = payment_money_to_paise($dueAmount)
        + payment_money_to_paise($penaltyAmount)
        + payment_money_to_paise($adjustmentAmount)
        - payment_money_to_paise($discountAmount)
        - payment_money_to_paise($amountPaid);
    return max(0, min($installmentBalance, payment_money_to_paise($assignmentOutstanding)));
}

function payment_razorpay_credentials_are_valid(array $config, string $environment, bool $curlAvailable): bool
{
    $keyId = $config['razorpay_key_id'] ?? null;
    $keySecret = $config['razorpay_key_secret'] ?? null;
    if (!$curlAvailable || !is_string($keyId) || !is_string($keySecret)
        || $keySecret === '' || trim($keySecret) !== $keySecret
        || preg_match('/^rzp_(?:test|live)_[A-Za-z0-9]+$/D', $keyId) !== 1) {
        return false;
    }

    return $environment === 'production'
        ? str_starts_with($keyId, 'rzp_live_')
        : in_array($environment, ['development', 'testing'], true) && str_starts_with($keyId, 'rzp_test_');
}

function payment_razorpay_is_ready(): bool
{
    return payment_razorpay_credentials_are_valid(
        app_config()['payments'],
        (string) app_config()['env'],
        function_exists('curl_init')
    );
}

function payment_require_razorpay_ready(): array
{
    $config = app_config()['payments'];
    if (!payment_razorpay_credentials_are_valid(
        $config,
        (string) app_config()['env'],
        function_exists('curl_init')
    )) {
        throw new RuntimeException('Valid Razorpay API credentials and the PHP cURL extension are required.');
    }

    return $config;
}

function payment_service_prepare_checkout(
    mysqli $db,
    int $parentId,
    int $institutionId,
    int $userId,
    int $installmentId
): array {
    $config = payment_require_razorpay_ready();
    if ($parentId < 1 || $institutionId < 1 || $userId < 1 || $installmentId < 1) {
        throw new InvalidArgumentException('The requested installment is invalid.');
    }

    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            "SELECT i.id AS installment_id, i.due_amount, i.amount_paid, i.penalty_amount,
                    i.discount_amount, i.adjustment_amount, i.status AS installment_status,
                    a.id AS assignment_id, a.outstanding_amount, a.status AS assignment_status,
                    s.id AS student_id, p.id AS parent_id
             FROM installments i
             JOIN student_fee_assignments a
               ON a.institution_id = i.institution_id AND a.id = i.student_fee_assignment_id
             JOIN students s
               ON s.institution_id = a.institution_id AND s.id = a.student_id
             JOIN parent_student_relationships psr
               ON psr.institution_id = s.institution_id AND psr.student_id = s.id
              AND psr.parent_id = ? AND psr.status = 'active' AND psr.can_view_fees = 1
              AND psr.can_make_payments = 1
             JOIN parents p ON p.institution_id = psr.institution_id AND p.id = psr.parent_id
             JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
             WHERE i.institution_id = ? AND i.id = ?
               AND a.status = 'active' AND s.status = 'active'
               AND p.status = 'active' AND u.status = 'active'
               AND u.email_verified_at IS NOT NULL AND u.user_type = 'parent'
             FOR UPDATE"
        );
        $statement->bind_param('iii', $parentId, $institutionId, $installmentId);
        $statement->execute();
        $installment = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($installment)) {
            throw new DomainException('This installment is not available for payment.');
        }
        if (!in_array($installment['installment_status'], ['pending', 'partial', 'overdue'], true)) {
            throw new DomainException('This installment is not payable.');
        }

        $amountPaise = payment_calculate_installment_balance_paise(
            (string) $installment['due_amount'],
            (string) $installment['penalty_amount'],
            (string) $installment['adjustment_amount'],
            (string) $installment['discount_amount'],
            (string) $installment['amount_paid'],
            (string) $installment['outstanding_amount']
        );
        if ($amountPaise < 1) {
            throw new DomainException('No outstanding amount is due for this installment.');
        }

        $existingStatement = $db->prepare(
            "SELECT id, order_number, amount, gateway_order_id, status
             FROM payment_orders
             WHERE institution_id = ? AND installment_id = ? AND parent_id = ?
               AND gateway = 'razorpay'
               AND status IN ('pending', 'created', 'authorized')
             ORDER BY id DESC LIMIT 1 FOR UPDATE"
        );
        $existingStatement->bind_param('iii', $institutionId, $installmentId, $parentId);
        $existingStatement->execute();
        $order = $existingStatement->get_result()->fetch_assoc();
        $existingStatement->close();

        if (is_array($order) && is_string($order['gateway_order_id']) && $order['gateway_order_id'] !== '') {
            if (payment_money_to_paise((string) $order['amount']) !== $amountPaise) {
                throw new DomainException('An existing payment order is being reconciled. Try again later.');
            }
            $attemptQuery = $db->prepare(
                "SELECT id, status,
                        (updated_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE)) AS is_stale
                 FROM payment_attempts
                 WHERE institution_id = ? AND payment_order_id = ?
                 ORDER BY attempt_number DESC LIMIT 1 FOR UPDATE"
            );
            $orderId = (int) $order['id'];
            $attemptQuery->bind_param('ii', $institutionId, $orderId);
            $attemptQuery->execute();
            $attempt = $attemptQuery->get_result()->fetch_assoc();
            $attemptQuery->close();
            if (!is_array($attempt)) {
                throw new RuntimeException('The existing payment order has no recorded attempt.');
            }
            if (in_array($attempt['status'], ['initiated', 'pending'], true)
                && (int) $attempt['is_stale'] === 1) {
                $gatewayOrderId = (string) $order['gateway_order_id'];
                $attemptId = (int) $attempt['id'];
                $db->commit();
                $recoveryState = payment_verification_reconcile_gateway_order(
                    $db,
                    $institutionId,
                    $orderId,
                    $gatewayOrderId
                );
                if ($recoveryState === 'success') {
                    throw new DomainException(
                        'This payment has already been confirmed. Refresh Fee Details to see the updated balance.'
                    );
                }
                if ($recoveryState === 'pending') {
                    throw new DomainException(
                        'Razorpay is still processing this payment. Please wait for its status to be confirmed.'
                    );
                }
                if ($recoveryState === 'no_payment') {
                    $db->begin_transaction();
                    payment_service_set_attempt_status(
                        $db,
                        $institutionId,
                        $orderId,
                        $attemptId,
                        'cancelled'
                    );
                    $db->commit();
                }
                return payment_service_prepare_checkout(
                    $db,
                    $parentId,
                    $institutionId,
                    $userId,
                    $installmentId
                );
            }
            if (in_array($attempt['status'], ['initiated', 'pending'], true)) {
                $db->commit();
                return [
                    'order_id' => $orderId,
                    'attempt_id' => (int) $attempt['id'],
                    'gateway_order_id' => (string) $order['gateway_order_id'],
                    'amount' => (string) $order['amount'],
                    'currency' => 'INR',
                    'key_id' => $config['razorpay_key_id'],
                    'reused' => true,
                ];
            }

            $nextAttempt = $db->prepare(
                'SELECT COALESCE(MAX(attempt_number), 0) + 1 AS next_number
                 FROM payment_attempts WHERE payment_order_id = ?'
            );
            $nextAttempt->bind_param('i', $orderId);
            $nextAttempt->execute();
            $attemptNumber = (int) $nextAttempt->get_result()->fetch_assoc()['next_number'];
            $nextAttempt->close();
            $gateway = 'razorpay';
            $attemptInsert = $db->prepare(
                "INSERT INTO payment_attempts (institution_id, payment_order_id, attempt_number, gateway, status)
                 VALUES (?, ?, ?, ?, 'pending')"
            );
            $attemptInsert->bind_param('iiis', $institutionId, $orderId, $attemptNumber, $gateway);
            $attemptInsert->execute();
            $attemptId = (int) $db->insert_id;
            $attemptInsert->close();
            write_audit_log(
                $db,
                $institutionId,
                $userId,
                'parent',
                'payment.retry_requested',
                'payment_attempt',
                (string) $attemptId,
                ['previous_attempt_id' => (int) $attempt['id']],
                ['payment_order_id' => $orderId, 'attempt_number' => $attemptNumber]
            );
            $db->commit();

            return [
                'order_id' => $orderId,
                'attempt_id' => $attemptId,
                'gateway_order_id' => (string) $order['gateway_order_id'],
                'amount' => (string) $order['amount'],
                'currency' => 'INR',
                'key_id' => $config['razorpay_key_id'],
                'reused' => true,
            ];
        }
        if (is_array($order)) {
            throw new DomainException('A payment order is still being prepared. Try again shortly.');
        }

        $orderNumber = 'RZP-' . strtoupper(bin2hex(random_bytes(12)));
        $idempotencyKey = bin2hex(random_bytes(32));
        $amount = payment_paise_to_money($amountPaise);
        $gateway = 'razorpay';
        $currency = 'INR';
        $orderStatus = 'pending';
        $studentId = (int) $installment['student_id'];
        $orderParentId = (int) $installment['parent_id'];
        $insert = $db->prepare(
            'INSERT INTO payment_orders (
                institution_id, student_id, parent_id, installment_id, order_number, gateway,
                amount, currency, status, idempotency_key
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->bind_param(
            'iiiissssss',
            $institutionId,
            $studentId,
            $orderParentId,
            $installmentId,
            $orderNumber,
            $gateway,
            $amount,
            $currency,
            $orderStatus,
            $idempotencyKey
        );
        $insert->execute();
        $orderId = (int) $db->insert_id;
        $insert->close();

        $attemptNumber = 1;
        $attemptStatus = 'initiated';
        $attemptInsert = $db->prepare(
            'INSERT INTO payment_attempts (institution_id, payment_order_id, attempt_number, gateway, status)
             VALUES (?, ?, ?, ?, ?)'
        );
        $attemptInsert->bind_param('iiiss', $institutionId, $orderId, $attemptNumber, $gateway, $attemptStatus);
        $attemptInsert->execute();
        $attemptId = (int) $db->insert_id;
        $attemptInsert->close();
        write_audit_log(
            $db,
            $institutionId,
            $userId,
            'parent',
            'payment.order_requested',
            'payment_order',
            (string) $orderId,
            null,
            ['order_number' => $orderNumber, 'installment_id' => $installmentId, 'amount' => $amount]
        );
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }

    try {
        $gatewayUpdateTransactionStarted = false;
        $gatewayOrder = razorpay_create_order($orderNumber, $amountPaise, $currency);
        if (!isset($gatewayOrder['id'], $gatewayOrder['amount'], $gatewayOrder['currency'])
            || !is_string($gatewayOrder['id'])
            || preg_match('/^order_[A-Za-z0-9]+$/D', $gatewayOrder['id']) !== 1
            || (int) $gatewayOrder['amount'] !== $amountPaise
            || strtoupper((string) $gatewayOrder['currency']) !== $currency) {
            throw new RuntimeException('Razorpay returned an order with unexpected payment details.');
        }
        $responseJson = json_encode(
            ['id' => $gatewayOrder['id'], 'amount' => $gatewayOrder['amount'], 'currency' => $currency],
            JSON_THROW_ON_ERROR
        );
        $db->begin_transaction();
        $gatewayUpdateTransactionStarted = true;
        $updateOrder = $db->prepare(
            "UPDATE payment_orders SET gateway_order_id = ?, status = 'created'
             WHERE institution_id = ? AND id = ? AND status = 'pending'"
        );
        $gatewayOrderId = $gatewayOrder['id'];
        $updateOrder->bind_param('sii', $gatewayOrderId, $institutionId, $orderId);
        $updateOrder->execute();
        if ($updateOrder->affected_rows !== 1) {
            throw new RuntimeException('The local payment order could not be updated.');
        }
        $updateOrder->close();
        $attemptUpdate = $db->prepare(
            "UPDATE payment_attempts SET gateway_reference = ?, status = 'pending', response_payload = ?
             WHERE institution_id = ? AND id = ?"
        );
        $attemptUpdate->bind_param('ssii', $gatewayOrderId, $responseJson, $institutionId, $attemptId);
        $attemptUpdate->execute();
        $attemptUpdate->close();
        $db->commit();
        $gatewayUpdateTransactionStarted = false;

        return [
            'order_id' => $orderId,
            'attempt_id' => $attemptId,
            'gateway_order_id' => $gatewayOrderId,
            'amount' => $amount,
            'currency' => $currency,
            'key_id' => $config['razorpay_key_id'],
            'reused' => false,
        ];
    } catch (Throwable $exception) {
        $safeError = 'Order creation failed; no checkout was opened.';
        $failedStatus = 'failed';
        if ($gatewayUpdateTransactionStarted) {
            $db->rollback();
        }
        $db->begin_transaction();
        $updateOrder = $db->prepare(
            "UPDATE payment_orders SET status = 'failed'
             WHERE institution_id = ? AND id = ? AND status = 'pending'"
        );
        $updateOrder->bind_param('ii', $institutionId, $orderId);
        $updateOrder->execute();
        $updateOrder->close();
        $attemptUpdate = $db->prepare(
            "UPDATE payment_attempts SET status = ?, error_message = ?
             WHERE institution_id = ? AND id = ?"
        );
        $attemptUpdate->bind_param('ssii', $failedStatus, $safeError, $institutionId, $attemptId);
        $attemptUpdate->execute();
        $attemptUpdate->close();
        $db->commit();
        throw new RuntimeException('Unable to start checkout. Please retry from Fee Details.', 0, $exception);
    }
}

function payment_service_get_parent_order(
    mysqli $db,
    int $parentId,
    int $institutionId,
    int $orderId,
    bool $requirePaymentPermission = false
): ?array {
    $statement = $db->prepare(
        "SELECT po.id, po.institution_id, po.parent_id, po.student_id, po.installment_id,
                po.order_number, po.gateway, po.amount, po.currency, po.status, po.gateway_order_id,
                s.full_name AS student_name, i.installment_number, i.due_date,
                sfa.id AS assignment_id,
                (SELECT pa.id FROM payment_attempts pa
                 WHERE pa.institution_id = po.institution_id AND pa.payment_order_id = po.id
                 ORDER BY pa.attempt_number DESC LIMIT 1) AS attempt_id,
                (SELECT pa.status FROM payment_attempts pa
                 WHERE pa.institution_id = po.institution_id AND pa.payment_order_id = po.id
                 ORDER BY pa.attempt_number DESC LIMIT 1) AS attempt_status
         FROM payment_orders po
         JOIN students s ON s.institution_id = po.institution_id AND s.id = po.student_id
         JOIN installments i ON i.institution_id = po.institution_id AND i.id = po.installment_id
         JOIN student_fee_assignments sfa
           ON sfa.institution_id = i.institution_id AND sfa.id = i.student_fee_assignment_id
         JOIN parent_student_relationships psr
           ON psr.institution_id = s.institution_id AND psr.student_id = s.id
          AND psr.parent_id = po.parent_id AND psr.status = 'active' AND psr.can_view_fees = 1
         JOIN parents p ON p.institution_id = psr.institution_id AND p.id = psr.parent_id
         JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
         WHERE po.institution_id = ? AND po.parent_id = ? AND po.id = ?
           AND po.gateway = 'razorpay' AND p.status = 'active' AND s.status = 'active'
           AND u.status = 'active' AND u.email_verified_at IS NOT NULL
           AND (? = 0 OR psr.can_make_payments = 1)
         LIMIT 1"
    );
    $requirePermissionFlag = $requirePaymentPermission ? 1 : 0;
    $statement->bind_param('iiii', $institutionId, $parentId, $orderId, $requirePermissionFlag);
    $statement->execute();
    $order = $statement->get_result()->fetch_assoc();
    $statement->close();
    return is_array($order) ? $order : null;
}

function payment_service_set_attempt_status(
    mysqli $db,
    int $institutionId,
    int $orderId,
    int $attemptId,
    string $status,
    ?string $gatewayPaymentId = null
): bool {
    if (!in_array($status, ['success', 'failed', 'cancelled'], true)) {
        throw new InvalidArgumentException('The payment attempt status is invalid.');
    }
    $statement = $db->prepare(
        "UPDATE payment_attempts
         SET status = ?, gateway_reference = COALESCE(?, gateway_reference)
         WHERE institution_id = ? AND payment_order_id = ? AND id = ?
           AND status IN ('initiated', 'pending')"
    );
    $statement->bind_param('ssiii', $status, $gatewayPaymentId, $institutionId, $orderId, $attemptId);
    $statement->execute();
    $updated = $statement->affected_rows === 1;
    $statement->close();
    return $updated;
}