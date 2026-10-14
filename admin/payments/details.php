<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/admin-panel-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$paymentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!is_int($paymentId) || $paymentId < 1) {
    http_response_code(404);
    exit('Payment not found.');
}
$payment = admin_panel_payment_details(db_connect(), admin_panel_institution_id(), $paymentId);
if ($payment === null) {
    http_response_code(404);
    exit('Payment not found.');
}

render_app_page_start('Payment Details', 'Institution-scoped transaction, refund and gateway attempt history.', 'admin', ['admin-dashboard.css']);
?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/payments/index')); ?>">Payments</a></li><li class="breadcrumb-item active" aria-current="page">Payment #<?php echo e((string) $paymentId); ?></li></ol></nav>
<section class="dashboard-panel admin-detail-panel">
    <div class="dashboard-panel-heading"><div><span class="dashboard-section-kicker"><?php echo e($payment['order_number']); ?></span><h2><?php echo e($payment['student_name']); ?></h2><p><?php echo e($payment['student_code']); ?> · parent <?php echo e($payment['parent_name']); ?></p></div><span class="admin-status-badge is-<?php echo e((string) $payment['status']); ?>"><?php echo e(ucwords(str_replace('_', ' ', (string) $payment['status']))); ?></span></div>
    <dl class="admin-detail-facts"><div><dt>Payment amount</dt><dd><?php echo e((string) $payment['currency']); ?> <?php echo e(number_format((float) $payment['amount'], 2)); ?></dd></div><div><dt>Gateway</dt><dd><?php echo e(ucfirst((string) $payment['gateway'])); ?></dd></div><div><dt>Gateway reference</dt><dd><?php echo e($payment['gateway_reference'] ?? $payment['gateway_transaction_id'] ?? 'Not provided'); ?></dd></div><div><dt>Verification time</dt><dd><?php echo e($payment['verified_at'] !== null ? date('j M Y, g:i a', strtotime((string) $payment['verified_at'])) : 'Not verified'); ?></dd></div><div><dt>Order status</dt><dd><?php echo e(ucfirst((string) $payment['order_status'])); ?></dd></div><div><dt>Created</dt><dd><?php echo e(date('j M Y, g:i a', strtotime((string) $payment['created_at']))); ?></dd></div></dl>
    <?php if ($payment['receipt_id'] !== null && user_has_permission('receipts.view')): ?><a class="btn btn-outline-primary" href="<?php echo e(route_url('/admin/receipts/index')); ?>">Receipts</a><?php endif; ?>
</section>
<section class="dashboard-panel admin-subtable-panel"><div class="dashboard-panel-heading"><div><h2>Refund records</h2><p>Only refund requests and completed refund records are shown.</p></div></div>
    <?php if ($payment['refunds'] === []): ?><div class="admin-panel-empty"><strong>No refunds recorded</strong></div><?php else: ?><div class="table-responsive"><table class="table admin-panel-table"><thead><tr><th>Reason</th><th>Requested</th><th>Status</th><th>Processed</th><th class="text-end">Amount</th></tr></thead><tbody><?php foreach ($payment['refunds'] as $refund): ?><tr><td><?php echo e($refund['reason']); ?></td><td><?php echo e(date('j M Y', strtotime((string) $refund['created_at']))); ?></td><td><?php echo e(ucfirst((string) $refund['status'])); ?></td><td><?php echo e($refund['processed_at'] !== null ? date('j M Y', strtotime((string) $refund['processed_at'])) : '—'); ?></td><td class="text-end"><?php echo e((string) $payment['currency']); ?> <?php echo e(number_format((float) $refund['amount'], 2)); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<section class="dashboard-panel admin-subtable-panel"><div class="dashboard-panel-heading"><div><h2>Payment attempts</h2><p>Gateway payloads and secrets are deliberately not displayed.</p></div></div>
    <?php if ($payment['attempts'] === []): ?><div class="admin-panel-empty"><strong>No gateway attempts recorded</strong></div><?php else: ?><div class="table-responsive"><table class="table admin-panel-table"><thead><tr><th>Attempt</th><th>Gateway</th><th>Reference</th><th>Status</th><th>Updated</th></tr></thead><tbody><?php foreach ($payment['attempts'] as $attempt): ?><tr><td><?php echo e((string) $attempt['attempt_number']); ?></td><td><?php echo e(ucfirst((string) $attempt['gateway'])); ?></td><td><?php echo e($attempt['gateway_reference'] ?? '—'); ?></td><td><?php echo e(ucfirst((string) $attempt['status'])); ?></td><td><?php echo e(date('j M Y, g:i a', strtotime((string) $attempt['updated_at']))); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<?php render_app_page_end('admin'); ?>
