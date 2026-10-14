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

$assignmentId = filter_input(INPUT_POST, 'assignment_id', FILTER_VALIDATE_INT);
$installmentId = filter_input(INPUT_POST, 'installment_id', FILTER_VALIDATE_INT);
if (!is_int($assignmentId) || $assignmentId < 1
    || !is_int($installmentId) || $installmentId < 1) {
    http_response_code(422);
    exit('The requested installment is invalid.');
}

$db = db_connect();
$context = parent_portal_require_current_parent($db);
try {
    $order = payment_service_prepare_checkout(
        $db,
        (int) $context['parent']['parent_id'],
        (int) $context['user']['institution_id'],
        (int) $context['user']['id'],
        $installmentId
    );
} catch (DomainException | InvalidArgumentException $exception) {
    set_flash('warning', $exception->getMessage(), 'parent_payment');
    safe_redirect('/parent/fees/view?id=' . $assignmentId);
} catch (Throwable $exception) {
    log_error('payment_order_creation_failed', [
        'operation' => 'create_order',
        'error_type' => get_class($exception),
        'error_code' => $exception->getCode(),
    ]);
    $safeMessage = $exception instanceof RuntimeException
        && in_array($exception->getMessage(), [
            'Valid Razorpay API credentials and the PHP cURL extension are required.',
            'Only Razorpay test keys are allowed outside production.',
            'The PHP cURL extension is required for Razorpay payments.',
        ], true)
            ? $exception->getMessage()
            : 'Checkout could not be started. Please try again later.';
    set_flash(
        'danger',
        $safeMessage,
        'parent_payment'
    );
    safe_redirect('/parent/fees/view?id=' . $assignmentId);
}

safe_redirect('/parent/payments/checkout?id=' . (int) $order['order_id']);