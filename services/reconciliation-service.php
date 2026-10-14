<?php
declare(strict_types=1);

require_once __DIR__ . '/payment-verification-service.php';

function payment_reconciliation_run(mysqli $db, int $limit = 25): array
{
    if ($limit < 1 || $limit > 100) {
        throw new InvalidArgumentException('The reconciliation batch size is invalid.');
    }
    $result = $db->query(
        "SELECT institution_id, id, gateway_order_id
         FROM payment_orders
         WHERE gateway = 'razorpay' AND gateway_order_id IS NOT NULL
           AND status IN ('pending', 'created', 'authorized', 'failed', 'cancelled')
         ORDER BY updated_at ASC, id ASC LIMIT " . $limit
    );
    $orders = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();
    $summary = [
        'checked' => 0,
        'settled' => 0,
        'pending' => 0,
        'failed' => 0,
        'no_payment' => 0,
        'errors' => 0,
    ];

    foreach ($orders as $order) {
        $summary['checked']++;
        $institutionId = (int) $order['institution_id'];
        $orderId = (int) $order['id'];
        try {
            $state = payment_verification_reconcile_gateway_order(
                $db,
                $institutionId,
                $orderId,
                (string) $order['gateway_order_id']
            );
            if (!in_array($state, ['success', 'pending', 'failed', 'no_payment'], true)) {
                throw new UnexpectedValueException('Payment reconciliation returned an unknown state.');
            }
            if ($state === 'success') {
                $summary['settled']++;
            } else {
                $summary[$state]++;
            }
        } catch (Throwable $exception) {
            $summary['errors']++;
            if (function_exists('log_error')) {
                log_error('payment_reconciliation_failed', [
                    'operation' => 'reconcile',
                    'institution_id' => $institutionId,
                    'payment_order_id' => $orderId,
                    'error_type' => get_class($exception),
                    'error_code' => $exception->getCode(),
                ]);
            } else {
                error_log('[FeesApp] payment reconciliation failed: ' . get_class($exception));
            }
        }
    }

    return $summary;
}
