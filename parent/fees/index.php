<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/parent-portal-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$db = db_connect();
$context = parent_portal_require_current_parent($db);
$assignments = parent_portal_get_fee_assignments(
    $db,
    (int) $context['parent']['parent_id'],
    (int) $context['user']['institution_id']
);
$totals = [
    'fee' => parent_portal_sum_money($assignments, 'total_fee'),
    'paid' => parent_portal_sum_money($assignments, 'total_paid'),
    'outstanding' => parent_portal_sum_money($assignments, 'outstanding_amount'),
];

render_app_page_start('Fee Overview', 'Review assigned fees and installment status for your linked children.', 'parent', ['parent-children.css']);
?>
<?php echo render_flash_messages(); ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb parent-children-breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/parent/dashboard')); ?>">Overview</a></li><li class="breadcrumb-item active" aria-current="page">Fees</li></ol></nav>
<section class="parent-children-summary" aria-label="Fee summary">
    <article class="parent-children-summary-card"><span class="parent-children-summary-icon is-blue"><i class="bi bi-receipt-cutoff" aria-hidden="true"></i></span><div><span>Assigned fees</span><strong>₹<?php echo e(number_format((float) $totals['fee'], 2)); ?></strong></div></article>
    <article class="parent-children-summary-card"><span class="parent-children-summary-icon is-green"><i class="bi bi-check2-circle" aria-hidden="true"></i></span><div><span>Paid</span><strong>₹<?php echo e(number_format((float) $totals['paid'], 2)); ?></strong></div></article>
    <article class="parent-children-summary-card"><span class="parent-children-summary-icon is-amber"><i class="bi bi-hourglass-split" aria-hidden="true"></i></span><div><span>Outstanding</span><strong>₹<?php echo e(number_format((float) $totals['outstanding'], 2)); ?></strong></div></article>
</section>
<section class="parent-children-panel">
    <div class="parent-children-panel-heading"><div><h2>Fee assignments</h2><p>Balances are read from saved student fee assignment records.</p></div><a class="parent-dashboard-text-link" href="<?php echo e(route_url('/parent/payments/index')); ?>">Payment history</a></div>
    <?php if ($assignments === []): ?>
        <div class="parent-children-empty"><span><i class="bi bi-cash-stack" aria-hidden="true"></i></span><h3>No fee assignments available</h3><p>No active fee assignments are currently available for children whose relationship allows fee viewing.</p></div>
    <?php else: ?>
        <div class="table-responsive"><table class="table parent-payments-table align-middle">
            <thead><tr><th>Student</th><th>Fee structure</th><th>Academic year</th><th>Assigned</th><th>Paid</th><th>Outstanding</th><th>Next due</th><th>Status</th><th></th></tr></thead>
            <tbody><?php foreach ($assignments as $fee): ?>
                <tr>
                    <td><strong><?php echo e($fee['student_name']); ?></strong><br><small><?php echo e($fee['student_code']); ?></small></td>
                    <td><?php echo e($fee['fee_structure_name']); ?></td>
                    <td><?php echo e($fee['academic_year_name']); ?></td>
                    <td>₹<?php echo e(number_format((float) $fee['total_fee'], 2)); ?></td>
                    <td>₹<?php echo e(number_format((float) $fee['total_paid'], 2)); ?></td>
                    <td>₹<?php echo e(number_format((float) $fee['outstanding_amount'], 2)); ?></td>
                    <td><?php echo $fee['next_due_date'] !== null ? e(date('j M Y', strtotime((string) $fee['next_due_date']))) : '—'; ?></td>
                    <td><?php echo e(ucfirst((string) $fee['status'])); ?></td>
                    <td><a class="parent-child-details-link" href="<?php echo e(route_url('/parent/fees/view') . '?id=' . (int) $fee['id']); ?>">Details</a></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php render_app_page_end('parent'); ?>
