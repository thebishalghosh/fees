<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/admin-panel-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$refunds = admin_panel_refunds(db_connect(), admin_panel_institution_id());

render_app_page_start('Refund Review', 'Review refund records; this view does not trigger or record a gateway refund.', 'admin', ['admin-dashboard.css']);
?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/payments/index')); ?>">Payments</a></li><li class="breadcrumb-item active" aria-current="page">Refunds</li></ol></nav>
<section class="dashboard-panel admin-recent-payments-panel"><div class="dashboard-panel-heading"><div><h2>Refund requests and outcomes</h2><p><?php echo e(number_format(count($refunds))); ?> recent refund records.</p></div></div>
    <?php if ($refunds === []): ?><div class="admin-panel-empty"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i><strong>No refund records</strong><span>Refund activity will appear here when recorded.</span></div><?php else: ?><div class="table-responsive"><table class="table admin-panel-table"><thead><tr><th>Student</th><th>Parent</th><th>Payment reference</th><th>Reason</th><th>Status</th><th>Date</th><th class="text-end">Amount</th><th></th></tr></thead><tbody><?php foreach ($refunds as $refund): ?><tr><td><?php echo e($refund['student_name']); ?><small><?php echo e($refund['student_code']); ?></small></td><td><?php echo e($refund['parent_name']); ?></td><td><?php echo e($refund['gateway_reference'] ?? 'Payment #' . $refund['payment_id']); ?></td><td><?php echo e($refund['reason']); ?></td><td><span class="admin-status-badge is-<?php echo e((string) $refund['status']); ?>"><?php echo e(ucfirst((string) $refund['status'])); ?></span></td><td><?php echo e(date('j M Y', strtotime((string) $refund['created_at']))); ?></td><td class="text-end"><?php echo e((string) $refund['currency']); ?> <?php echo e(number_format((float) $refund['amount'], 2)); ?></td><td><?php if (user_has_permission('payments.view')): ?><a href="<?php echo e(route_url('/admin/payments/details') . '?id=' . (int) $refund['payment_id']); ?>">Payment</a><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<?php render_app_page_end('admin'); ?>
