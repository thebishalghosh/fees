<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/parent-portal-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$paymentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!is_int($paymentId) || $paymentId < 1) {
    http_response_code(404);
    exit('Payment not found.');
}
$db = db_connect();
$context = parent_portal_require_current_parent($db);
$payment = parent_portal_get_payment(
    $db,
    (int) $context['parent']['parent_id'],
    (int) $context['user']['institution_id'],
    $paymentId
);
if ($payment === null) {
    http_response_code(404);
    exit('Payment not found.');
}

render_app_page_start('Payment Details', 'Payment status and receipt information.', 'parent', ['parent-children.css']);
?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb parent-children-breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/parent/dashboard')); ?>">Overview</a></li><li class="breadcrumb-item"><a href="<?php echo e(route_url('/parent/payments/index')); ?>">Payments</a></li><li class="breadcrumb-item active" aria-current="page">Payment #<?php echo e((string) $payment['id']); ?></li></ol></nav>
<section class="parent-children-panel parent-student-detail">
    <div class="parent-student-detail-heading"><div><span class="parent-child-code"><?php echo e($payment['student_code']); ?> · <?php echo e($payment['order_number']); ?></span><h2><?php echo e($payment['student_name']); ?></h2><span class="parent-child-status is-<?php echo e((string) $payment['status']); ?>"><?php echo e(ucwords(str_replace('_', ' ', (string) $payment['status']))); ?></span></div><a class="parent-child-back-link" href="<?php echo e(route_url('/parent/payments/index')); ?>">Back to Payments</a></div>
    <dl class="parent-fee-summary-list parent-payment-detail-facts">
        <div><dt>Amount</dt><dd><?php echo e((string) $payment['currency']); ?> <?php echo e(number_format((float) $payment['amount'], 2)); ?></dd></div>
        <?php if ((float) $payment['refunded_amount'] > 0 || $payment['status'] === 'refunded'): ?><div><dt>Processed refunds</dt><dd><?php echo e((string) $payment['currency']); ?> <?php echo e(number_format((float) $payment['refunded_amount'], 2)); ?></dd></div><div><dt>Net amount</dt><dd><?php echo e((string) $payment['currency']); ?> <?php echo e(number_format((float) $payment['net_amount'], 2)); ?></dd></div><?php endif; ?>
        <div><dt>Payment method</dt><dd><?php echo e(ucfirst((string) $payment['gateway'])); ?></dd></div>
        <div><dt>Transaction reference</dt><dd><?php echo e($payment['gateway_reference'] ?? $payment['gateway_transaction_id'] ?? 'Not provided'); ?></dd></div>
        <div><dt>Recorded date</dt><dd><?php echo e(date('j M Y, g:i a', strtotime((string) ($payment['verified_at'] ?? $payment['captured_at'] ?? $payment['created_at'])))); ?></dd></div>
        <div><dt>Payment status</dt><dd><?php echo e(ucwords(str_replace('_', ' ', (string) $payment['status']))); ?></dd></div>
        <div><dt>Installment</dt><dd><?php echo $payment['installment_number'] !== null ? e((string) $payment['installment_number'] . ' · due ' . date('j M Y', strtotime((string) $payment['due_date']))) : 'Not linked to an installment'; ?></dd></div>
    </dl>
    <?php if ($payment['assignment_id'] !== null): ?><a class="parent-child-details-link" href="<?php echo e(route_url('/parent/fees/view') . '?id=' . (int) $payment['assignment_id']); ?>">View related fee assignment</a><?php endif; ?>
    <?php if ($payment['receipt_id'] !== null): ?><a class="parent-child-details-link ms-3" href="<?php echo e(route_url('/parent/receipts/view') . '?id=' . (int) $payment['receipt_id']); ?>">View receipt</a><?php endif; ?>
</section>
<?php render_app_page_end('parent'); ?>
