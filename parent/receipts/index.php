<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/parent-portal-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$db = db_connect();
$context = parent_portal_require_current_parent($db);
$receipts = parent_portal_list_receipts(
    $db,
    (int) $context['parent']['parent_id'],
    (int) $context['user']['institution_id']
);

render_app_page_start('Receipts', 'Issued receipts for verified payments made for your linked children.', 'parent', ['parent-children.css']);
?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb parent-children-breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/parent/dashboard')); ?>">Overview</a></li><li class="breadcrumb-item active" aria-current="page">Receipts</li></ol></nav>
<section class="parent-children-panel">
    <div class="parent-children-panel-heading"><div><h2>Issued receipts</h2><p>Only receipts issued by the institution are listed.</p></div><a class="parent-dashboard-text-link" href="<?php echo e(route_url('/parent/payments/index')); ?>">Payment history</a></div>
    <?php if ($receipts === []): ?>
        <div class="parent-children-empty"><span><i class="bi bi-receipt" aria-hidden="true"></i></span><h3>No issued receipts</h3><p>There are no issued receipts available for your linked children.</p></div>
    <?php else: ?>
        <div class="table-responsive"><table class="table parent-payments-table align-middle"><thead><tr><th>Receipt</th><th>Student</th><th>Issued date</th><th class="text-end">Amount</th><th>Actions</th></tr></thead><tbody><?php foreach ($receipts as $receipt): ?><tr><td><?php echo e($receipt['receipt_number']); ?></td><td><?php echo e($receipt['student_name']); ?><br><small><?php echo e($receipt['student_code']); ?></small></td><td><?php echo e(date('j M Y', strtotime((string) ($receipt['issued_at'] ?? $receipt['created_at'])))); ?></td><td class="text-end"><?php echo e((string) $receipt['currency']); ?> <?php echo e(number_format((float) $receipt['total_amount'], 2)); ?></td><td><a href="<?php echo e(route_url('/parent/receipts/view') . '?id=' . (int) $receipt['id']); ?>">View</a><span class="mx-1" aria-hidden="true">·</span><a href="<?php echo e(route_url('/parent/receipts/download') . '?id=' . (int) $receipt['id']); ?>">Download PDF</a></td></tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
</section>
<?php render_app_page_end('parent'); ?>
