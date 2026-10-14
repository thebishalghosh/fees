<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/page-placeholder.php';
require_once __DIR__ . '/../services/admin-panel-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$institutionId = admin_panel_institution_id();
$data = admin_panel_dashboard_data(db_connect(), $institutionId, user_has_permission('payments.view'));
$counts = $data['counts'];

$cards = [
    ['Active students', number_format((int) $counts['active_students']), 'bi-people', 'students'],
    ['Active parents', number_format((int) $counts['active_parents']), 'bi-person-hearts', 'parents'],
    ['Active staff', number_format((int) $counts['active_staff']), 'bi-person-badge', 'staff'],
    ['Fees assigned', '₹' . number_format((float) $counts['assigned_fees'], 2), 'bi-receipt-cutoff', 'assigned'],
    ['Net collected', '₹' . number_format((float) $counts['collected_amount'], 2), 'bi-wallet2', 'collected'],
    ['Outstanding balance', '₹' . number_format((float) $counts['outstanding_balance'], 2), 'bi-hourglass-split', 'pending'],
    ['Overdue dues', '₹' . number_format((float) $counts['overdue_balance'], 2), 'bi-calendar-x', 'overdue'],
];

render_app_page_start(
    'Dashboard',
    'Institution-scoped enrollment and fee activity from your current records.',
    'admin',
    ['admin-dashboard.css']
);
?>
<?php echo render_flash_messages(); ?>
<section class="dashboard-welcome" aria-labelledby="dashboard-welcome-title">
    <div>
        <span class="dashboard-demo-label"><i class="bi bi-database-check" aria-hidden="true"></i>Live institution data</span>
        <h2 id="dashboard-welcome-title">Welcome to your dashboard</h2>
        <p>Summary values use saved fee assignments, verified payments, and processed refund records for this institution.</p>
    </div>
    <div class="dashboard-today"><i class="bi bi-calendar3" aria-hidden="true"></i><time datetime="<?php echo e(date('Y-m-d')); ?>"><?php echo e(date('l, j F Y')); ?></time></div>
</section>

<section class="dashboard-summary-grid" aria-label="Institution summary">
    <?php foreach ($cards as [$label, $value, $icon, $class]): ?>
        <article class="dashboard-summary-card dashboard-summary-<?php echo e($class); ?>">
            <div class="dashboard-summary-top"><span><?php echo e($label); ?></span><span class="dashboard-summary-icon" aria-hidden="true"><i class="bi <?php echo e($icon); ?>"></i></span></div>
            <strong><?php echo e($value); ?></strong>
            <small><span>Current institution total</span></small>
        </article>
    <?php endforeach; ?>
</section>

<section class="dashboard-chart-grid" aria-label="Fee collection and pending actions">
    <article class="dashboard-panel collection-panel">
        <div class="dashboard-panel-heading"><div><span class="dashboard-section-kicker">VERIFIED COLLECTIONS</span><h2>Monthly Net Collections</h2><p>Last six months; processed refunds subtracted from verified payments.</p></div><span class="dashboard-period"><i class="bi bi-calendar3" aria-hidden="true"></i>6 months</span></div>
        <?php if ($data['month_max'] <= 0): ?>
            <div class="admin-panel-empty"><i class="bi bi-bar-chart" aria-hidden="true"></i><strong>No verified collections in this period</strong><span>Verified payment activity will appear here.</span></div>
        <?php else: ?>
            <div class="dashboard-chart-scroll">
                <div class="admin-dashboard-bars" role="img" aria-label="Monthly verified net fee collections">
                    <?php foreach ($data['monthly_collections'] as $month): ?>
                        <div class="admin-dashboard-bar-column" title="<?php echo e($month['label'] . ': ₹' . number_format($month['amount'], 2)); ?>">
                            <strong><?php echo e(number_format($month['amount'], 0)); ?></strong>
                            <span class="admin-dashboard-bar" style="height: <?php echo e((string) max(3, $month['height'])); ?>px"></span>
                            <small><?php echo e($month['label']); ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <div class="chart-footnote"><span class="chart-key" aria-hidden="true"></span> Net verified payment amount <span>· INR value display</span></div>
    </article>
    <article class="dashboard-panel payment-status-panel">
        <div class="dashboard-panel-heading"><div><span class="dashboard-section-kicker">REQUIRES REVIEW</span><h2>Pending payment actions</h2><p>Records remain pending until normal verification or review.</p></div></div>
        <div class="admin-pending-action-list">
            <?php if (user_has_permission('payments.view')): ?><a href="<?php echo e(route_url('/admin/payments/index?status=pending')); ?>"><span><i class="bi bi-hourglass" aria-hidden="true"></i>Pending payment orders</span><strong><?php echo e(number_format((int) $counts['pending_orders'])); ?></strong></a><?php endif; ?>
            <?php if (user_has_permission('payments.refund')): ?><a href="<?php echo e(route_url('/admin/payments/refunds')); ?>"><span><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Pending refunds</span><strong><?php echo e(number_format((int) $counts['pending_refunds'])); ?></strong></a><?php endif; ?>
            <?php if (user_has_permission('payments.reconcile')): ?><a href="<?php echo e(route_url('/admin/payments/reconcile')); ?>"><span><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>Failed webhook events</span><strong><?php echo e(number_format((int) $counts['failed_webhooks'])); ?></strong></a><?php endif; ?>
            <?php if (!user_has_permission('payments.view') && !user_has_permission('payments.refund') && !user_has_permission('payments.reconcile')): ?><span class="admin-panel-note">No payment actions are available to this account.</span><?php endif; ?>
        </div>
        <p class="admin-panel-note">Dashboard counts are read-only; payment status is changed only through the existing verification, reconciliation, and refund workflows.</p>
    </article>
</section>

<section class="dashboard-panel admin-recent-payments-panel">
    <div class="dashboard-panel-heading"><div><span class="dashboard-section-kicker">LATEST ACTIVITY</span><h2>Recent transactions</h2><p>Most recently recorded payment rows in this institution.</p></div><?php if (user_has_permission('payments.view')): ?><a class="dashboard-panel-link" href="<?php echo e(route_url('/admin/payments/index')); ?>">All payments <i class="bi bi-arrow-right" aria-hidden="true"></i></a><?php endif; ?></div>
    <?php if (!user_has_permission('payments.view')): ?>
        <div class="admin-panel-empty"><i class="bi bi-shield-lock" aria-hidden="true"></i><strong>Transaction details are restricted</strong><span>Your account does not have payment viewing permission.</span></div>
    <?php elseif ($data['payments'] === []): ?>
        <div class="admin-panel-empty"><i class="bi bi-credit-card" aria-hidden="true"></i><strong>No payment transactions yet</strong><span>Recorded payment activity will appear here.</span></div>
    <?php else: ?>
        <div class="table-responsive"><table class="table admin-panel-table align-middle"><thead><tr><th>Student</th><th>Parent</th><th>Reference</th><th>Date</th><th>Status</th><th class="text-end">Amount</th><th></th></tr></thead><tbody>
            <?php foreach ($data['payments'] as $payment): ?>
                <tr><td><strong><?php echo e($payment['student_name']); ?></strong><small><?php echo e($payment['student_code']); ?></small></td><td><?php echo e($payment['parent_name']); ?></td><td><?php echo e($payment['gateway_reference'] ?? $payment['gateway_transaction_id'] ?? $payment['receipt_number'] ?? '—'); ?></td><td><?php echo e(date('j M Y, g:i a', strtotime((string) $payment['occurred_at']))); ?></td><td><span class="admin-status-badge is-<?php echo e((string) $payment['status']); ?>"><?php echo e(ucwords(str_replace('_', ' ', (string) $payment['status']))); ?></span></td><td class="text-end"><?php echo e((string) $payment['currency']); ?> <?php echo e(number_format((float) $payment['amount'], 2)); ?><?php if ((float) $payment['refunded_amount'] > 0 || $payment['status'] === 'refunded'): ?><small>Net ₹<?php echo e(admin_panel_net_payment_amount($payment)); ?></small><?php endif; ?></td><td><a href="<?php echo e(route_url('/admin/payments/details') . '?id=' . (int) $payment['id']); ?>">View</a></td></tr>
            <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>

<section class="admin-quick-links" aria-label="Quick links">
    <?php foreach ([
        ['Students', '/admin/students/index', 'bi-people', 'students.view'],
        ['Parents', '/admin/parents/index', 'bi-person-hearts', 'parents.view'],
        ['Fee structures', '/admin/fees/structures', 'bi-cash-stack', 'fees.view'],
        ['Reports', '/admin/reports/collections', 'bi-bar-chart', 'reports.view'],
    ] as [$label, $url, $icon, $permission]): ?>
        <?php if (user_has_permission($permission)): ?><a href="<?php echo e(route_url($url)); ?>"><i class="bi <?php echo e($icon); ?>" aria-hidden="true"></i><span><?php echo e($label); ?></span><i class="bi bi-arrow-up-right ms-auto" aria-hidden="true"></i></a><?php endif; ?>
    <?php endforeach; ?>
</section>
<?php render_app_page_end('admin'); ?>
