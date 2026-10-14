<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../services/parent-portal-service.php';
require_once __DIR__ . '/../../services/payment-service.php';
require_once __DIR__ . '/../../includes/flash.php';

require_http_methods(['POST']);
require_placeholder_route_access();
require_valid_csrf_token();
$orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
if (!is_int($orderId) || $orderId < 1) {
    http_response_code(404);
    exit('Payment order not found.');
}
$db = db_connect();
$context = parent_portal_require_current_parent($db);
$parentId = (int) $context['parent']['parent_id'];
$institutionId = (int) $context['user']['institution_id'];
$order = payment_service_get_parent_order($db, $parentId, $institutionId, $orderId, true);
if ($order === null || $order['status'] === 'paid') {
    http_response_code(404);
    exit('Payment order not found.');
}
try {
    $newOrder = payment_service_prepare_checkout(
        $db,
        $parentId,
        $institutionId,
        (int) $context['user']['id'],
        (int) $order['installment_id']
    );
    safe_redirect('/parent/payments/checkout?id=' . (int) $newOrder['order_id']);
} catch (DomainException | InvalidArgumentException $exception) {
    set_flash('warning', $exception->getMessage(), 'parent_payment');
    safe_redirect('/parent/payments/status?id=' . $orderId);
} catch (Throwable $exception) {
    log_error('payment_retry_failed', [
        'operation' => 'retry',
        'error_type' => get_class($exception),
        'error_code' => $exception->getCode(),
    ]);
    set_flash('danger', 'Checkout could not be retried. Please try again later.', 'parent_payment');
    safe_redirect('/parent/payments/status?id=' . $orderId);
}