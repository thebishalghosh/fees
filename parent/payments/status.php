<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';
require_once __DIR__ . '/../../services/parent-portal-service.php';
require_once __DIR__ . '/../../services/payment-service.php';
require_once __DIR__ . '/../../services/payment-verification-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$orderId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!is_int($orderId) || $orderId < 1) {
    http_response_code(404);
    exit('Payment order not found.');
}
$db = db_connect();
$context = parent_portal_require_current_parent($db);
$parentId = (int) $context['parent']['parent_id'];
$institutionId = (int) $context['user']['institution_id'];
$order = payment_service_get_parent_order($db, $parentId, $institutionId, $orderId, true);
if ($order === null) {
    http_response_code(404);
    exit('Payment order not found.');
}

$state = isset($_GET['state']) && is_string($_GET['state']) ? $_GET['state'] : '';
$paymentStatus = $order['status'] === 'paid'
    ? 'success'
    : ($order['attempt_status'] === 'failed' || $order['status'] === 'failed' ? 'failure' : 'pending');
if ($paymentStatus === 'pending' && payment_razorpay_is_ready()) {
    try {
        $paymentStatus = payment_reconciliation_check_parent_order(
            $db,
            $parentId,
            $institutionId,
            $orderId
        );
        $order = payment_service_get_parent_order($db, $parentId, $institutionId, $orderId, true) ?? $order;
    } catch (Throwable $exception) {
        log_error('payment_status_check_failed', [
            'operation' => 'status_check',
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
            'reference' => (string) $orderId,
            'source' => $exception->getFile(),
            'line' => $exception->getLine(),
        ]);
        $paymentStatus = 'pending';
    }
}
$isSuccess = $paymentStatus === 'success';
$isFailed = $paymentStatus === 'failure';

render_app_page_start('Payment Status', 'Verified payment updates for your installment.', 'parent', ['parent-children.css']);
?>
<?php echo render_flash_messages('parent_payment'); ?>
<section class="parent-children-panel parent-student-detail">
    <div class="parent-student-detail-heading"><div><span class="parent-child-code"><?php echo e($order['student_name']); ?> · Order <?php echo e($order['order_number']); ?></span><h2><?php echo $isSuccess ? 'Payment successful' : ($isFailed ? 'Payment not completed' : 'Payment pending verification'); ?></h2><p class="mb-0 text-secondary">Installment <?php echo e((string) $order['installment_number']); ?> · <?php echo e((string) $order['currency']); ?> <?php echo e(number_format((float) $order['amount'], 2)); ?></p></div></div>
    <?php if ($isSuccess): ?>
        <p>Your payment has been verified and recorded. The receipt is available from Payment History.</p>
        <a class="btn btn-cobalt" href="<?php echo e(route_url('/parent/payments/index')); ?>">Payment History</a>
    <?php elseif ($isFailed): ?>
        <p>Razorpay has confirmed this attempt did not succeed. You can retry checkout; only a verified captured payment updates your fee balance.</p>
        <form method="post" action="<?php echo e(route_url('/parent/payments/retry')); ?>"><?php echo csrf_field(); ?><input type="hidden" name="order_id" value="<?php echo e((string) $orderId); ?>"><button class="btn btn-cobalt" type="submit">Retry payment</button></form>
    <?php else: ?>
        <p><?php
            if ($state === 'cancelled') {
                echo 'Checkout was closed. The payment remains unconfirmed until Razorpay reports its status.';
            } elseif ($state === 'verification_error') {
                echo 'We could not confirm the checkout response just now. The payment remains unconfirmed until verified with Razorpay.';
            } else {
                echo 'Razorpay has not yet confirmed a captured payment. This page does not mark the installment as paid.';
            }
        ?></p>
        <a class="btn btn-cobalt" href="<?php echo e(route_url('/parent/fees/view') . '?id=' . (int) $order['assignment_id']); ?>">Return to Fee Details</a>
        <a class="btn btn-outline-secondary ms-2" href="<?php echo e(route_url('/parent/payments/status') . '?id=' . $orderId); ?>">Check status again</a>
    <?php endif; ?>
</section>
<?php render_app_page_end('parent'); ?>
