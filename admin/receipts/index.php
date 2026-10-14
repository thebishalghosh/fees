<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/admin-panel-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$receipts = admin_panel_receipts(db_connect(), admin_panel_institution_id());

render_app_page_start('Receipts', 'Issued receipt records and secure downloads for this institution.', 'admin', ['admin-dashboard.css']);
?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item active" aria-current="page">Receipts</li></ol></nav>
<section class="dashboard-panel admin-recent-payments-panel"><div class="dashboard-panel-heading"><div><h2>Issued receipts</h2><p><?php echo e(number_format(count($receipts))); ?> recent receipt records.</p></div><?php if (user_has_permission('payments.view')): ?><a href="<?php echo e(route_url('/admin/payments/index')); ?>">Payment transactions</a><?php endif; ?></div>
    <?php if ($receipts === []): ?><div class="admin-panel-empty"><i class="bi bi-receipt" aria-hidden="true"></i><strong>No receipts available</strong><span>Issued receipts will appear here.</span></div><?php else: ?><div class="table-responsive"><table class="table admin-panel-table"><thead><tr><th>Receipt</th><th>Student</th><th>Parent</th><th>Issued</th><th>Payment</th><th>Status</th><th class="text-end">Amount</th><th></th></tr></thead><tbody><?php foreach ($receipts as $receipt): ?><tr><td><?php echo e($receipt['receipt_number']); ?></td><td><?php echo e($receipt['student_name']); ?><small><?php echo e($receipt['student_code']); ?></small></td><td><?php echo e($receipt['parent_name']); ?></td><td><?php echo e($receipt['issued_at'] !== null ? date('j M Y', strtotime((string) $receipt['issued_at'])) : '—'); ?></td><td><?php echo e(ucwords(str_replace('_', ' ', (string) $receipt['payment_status']))); ?></td><td><?php echo e(ucfirst((string) $receipt['status'])); ?></td><td class="text-end"><?php echo e((string) $receipt['currency']); ?> <?php echo e(number_format((float) $receipt['total_amount'], 2)); ?></td><td><?php if (in_array($receipt['status'], ['issued', 'downloaded'], true) && is_string($receipt['file_path']) && trim($receipt['file_path']) !== ''): ?><a href="<?php echo e(route_url('/admin/receipts/download') . '?id=' . (int) $receipt['id']); ?>">Download</a><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<?php render_app_page_end('admin'); ?>
