<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../services/parent-portal-service.php';
require_once __DIR__ . '/../../services/payment-verification-service.php';

require_http_methods(['POST']);
require_placeholder_route_access();
require_valid_csrf_token();
header('Content-Type: application/json; charset=utf-8');

$orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
$gatewayOrderId = $_POST['razorpay_order_id'] ?? null;
$gatewayPaymentId = $_POST['razorpay_payment_id'] ?? null;
$signature = $_POST['razorpay_signature'] ?? null;
if (!is_int($orderId) || $orderId < 1
    || !is_string($gatewayOrderId)
    || !is_string($gatewayPaymentId)
    || !is_string($signature)) {
    http_response_code(422);
    echo json_encode(['status' => 'failure']);
    exit;
}

try {
    $db = db_connect();
    $context = parent_portal_require_current_parent($db);
    $paymentId = payment_verification_verify_checkout(
        $db,
        (int) $context['parent']['parent_id'],
        (int) $context['user']['institution_id'],
        $orderId,
        $gatewayOrderId,
        $gatewayPaymentId,
        $signature
    );
    echo json_encode(['status' => $paymentId > 0 ? 'success' : 'pending']);
} catch (DomainException $exception) {
    log_error('payment_checkout_verification_rejected', [
        'operation' => 'verify_checkout',
        'error_type' => get_class($exception),
        'reference' => (string) $orderId,
        'source' => $exception->getFile(),
        'line' => $exception->getLine(),
    ]);
    http_response_code(422);
    echo json_encode(['status' => 'failure']);
} catch (Throwable $exception) {
    log_error('payment_checkout_verification_failed', [
        'operation' => 'verify_checkout',
        'error_type' => get_class($exception),
        'error_code' => $exception->getCode(),
        'reference' => (string) $orderId,
        'source' => $exception->getFile(),
        'line' => $exception->getLine(),
    ]);
    http_response_code(503);
    echo json_encode(['status' => 'pending']);
}