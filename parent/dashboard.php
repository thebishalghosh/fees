<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/page-placeholder.php';
require_once __DIR__ . '/../services/parent-portal-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();

$db = db_connect();
$context = parent_portal_require_current_parent($db);
$user = $context['user'];
$parent = $context['parent'];
$children = parent_portal_get_children($db, (int) $parent['parent_id'], (int) $user['institution_id']);
$summary = parent_portal_children_summary($children);
$payments = parent_portal_list_payments(
    $db,
    (int) $parent['parent_id'],
    (int) $user['institution_id'],
    [],
    1,
    5
);
$dueSoon = parent_portal_get_upcoming_installments(
    $db,
    (int) $parent['parent_id'],
    (int) $user['institution_id'],
    5
);
$announcements = parent_portal_get_announcements($db, (int) $parent['parent_id'], (int) $user['institution_id'], 4);

render_app_page_start(
    'Parent Dashboard',
    "Track your children's fees, payments and school updates in one place.",
    'parent',
    ['parent-children.css', 'parent-dashboard.css']
);
?>
<?php echo render_flash_messages(); ?>
<section class="parent-dashboard-welcome" aria-labelledby="parent-welcome-title">
    <div>
        <span class="parent-dashboard-eyebrow">Parent Workspace</span>
        <h2 id="parent-welcome-title">Hello, <?php echo e($parent['full_name']); ?></h2>
        <p>Your family overview is ready below.</p>
    </div>
    <a class="parent-dashboard-profile-link" href="<?php echo e(route_url('/parent/profile/index')); ?>">
        <i class="bi bi-person-circle" aria-hidden="true"></i>View Profile
    </a>
</section>

<section class="parent-dashboard-summary" aria-label="Parent dashboard summary">
    <article class="parent-dashboard-summary-card">
        <span class="parent-dashboard-summary-icon" aria-hidden="true"><i class="bi bi-people"></i></span>
        <div><span>Linked Children</span><strong><?php echo e(number_format($summary['total_count'])); ?></strong></div>
    </article>
    <article class="parent-dashboard-summary-card">
        <span class="parent-dashboard-summary-icon" aria-hidden="true"><i class="bi bi-person-check"></i></span>
        <div><span>Active Students</span><strong><?php echo e(number_format($summary['active_count'])); ?></strong></div>
    </article>
    <article class="parent-dashboard-summary-card is-balance">
        <span class="parent-dashboard-summary-icon" aria-hidden="true"><i class="bi bi-cash-stack"></i></span>
        <div><span>Outstanding Fees</span><strong><?php echo $summary['has_fee_access'] ? '₹' . e(number_format($summary['outstanding_amount'], 2)) : '—'; ?></strong></div>
    </article>
    <article class="parent-dashboard-summary-card">
        <span class="parent-dashboard-summary-icon" aria-hidden="true"><i class="bi bi-calendar2-check"></i></span>
        <div><span>Upcoming Installments</span><strong><?php echo e(number_format(count($dueSoon))); ?></strong></div>
    </article>
</section>

<div class="parent-dashboard-columns">
    <div class="parent-dashboard-main">
        <section class="parent-dashboard-panel" aria-labelledby="parent-children-heading">
            <div class="parent-dashboard-panel-heading">
                <div><h2 id="parent-children-heading">My Children</h2><p>Students linked to your parent account.</p></div>
                <a class="parent-dashboard-action" href="<?php echo e(route_url('/parent/children')); ?>">View All <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            </div>
            <?php if ($children === []): ?>
                <div class="parent-dashboard-empty">
                    <span class="parent-dashboard-empty-icon" aria-hidden="true"><i class="bi bi-people"></i></span>
                    <strong>No linked students yet</strong>
                    <p>No active student links are recorded. Contact your institution administrator for assistance.</p>
                </div>
            <?php else: ?>
                <div class="parent-dashboard-child-list">
                    <?php foreach (array_slice($children, 0, 4) as $child): ?>
                        <article class="parent-dashboard-child-row">
                            <span class="parent-child-avatar" aria-hidden="true"><?php echo e(parent_portal_initials((string) $child['full_name'])); ?></span>
                            <div class="parent-dashboard-child-info">
                                <strong><?php echo e($child['full_name']); ?></strong>
                                <span><?php echo e((string) $child['student_code']); ?> · <?php echo e($child['class_name'] ?? 'Class not assigned'); ?></span>
                                <small>
                                    <?php if (!empty($child['batch_name'])): ?><?php echo e($child['batch_name']); ?> · <?php endif; ?>
                                    <?php echo e(ucfirst((string) $child['student_status'])); ?>
                                    <?php if (!empty($child['admission_date'])): ?> · Admitted <?php echo e(date('j M Y', strtotime((string) $child['admission_date']))); ?><?php endif; ?>
                                </small>
                            </div>
                            <?php if ($child['can_view_academics']): ?>
                                <a class="parent-dashboard-icon-link" href="<?php echo e(route_url('/parent/child-details') . '?id=' . (int) $child['id']); ?>" aria-label="<?php echo e('View ' . $child['full_name'] . ' details'); ?>"><i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="parent-dashboard-panel" aria-labelledby="parent-deadlines-heading">
            <div class="parent-dashboard-panel-heading">
                <div><h2 id="parent-deadlines-heading">Upcoming Fee Deadlines</h2><p>Unpaid and partially paid installments due soon.</p></div>
                <a class="parent-dashboard-action" href="<?php echo e(route_url('/parent/fees/index')); ?>">Fee Details <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            </div>
            <?php if ($dueSoon === []): ?>
                <div class="parent-dashboard-empty">
                    <span class="parent-dashboard-empty-icon" aria-hidden="true"><i class="bi bi-calendar-check"></i></span>
                    <strong>No upcoming fee deadlines</strong>
                    <p>There are no unpaid installment deadlines to show right now.</p>
                </div>
            <?php else: ?>
                <div class="parent-dashboard-installment-list">
                    <?php foreach ($dueSoon as $installment): ?>
                        <article class="parent-dashboard-installment-row">
                            <div class="parent-dashboard-installment-info">
                                <strong><?php echo e($installment['student_name']); ?></strong>
                                <span>Installment <?php echo e((string) $installment['installment_number']); ?></span>
                                <small>Due <?php echo e(date('j M Y', strtotime((string) $installment['due_date']))); ?></small>
                            </div>
                            <div class="parent-dashboard-installment-side">
                                <strong>₹<?php echo e(number_format((float) $installment['remaining_amount'], 2)); ?></strong>
                                <span class="parent-dashboard-status is-<?php echo e((string) $installment['status']); ?>"><?php echo e(ucfirst((string) $installment['status'])); ?></span>
                                <a href="<?php echo e(route_url('/parent/fees/view') . '?id=' . (int) $installment['assignment_id']); ?>">View Fee Details</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <aside class="parent-dashboard-aside" aria-label="Recent activity">
        <section class="parent-dashboard-panel" aria-labelledby="parent-payments-heading">
            <div class="parent-dashboard-panel-heading">
                <div><h2 id="parent-payments-heading">Recent Payments</h2><p>Latest recorded payment activity.</p></div>
                <a class="parent-dashboard-action" href="<?php echo e(route_url('/parent/payments/index')); ?>">Payment History <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            </div>
            <?php if ($payments['payments'] === []): ?>
                <div class="parent-dashboard-empty">
                    <span class="parent-dashboard-empty-icon" aria-hidden="true"><i class="bi bi-receipt"></i></span>
                    <strong>No payments recorded</strong>
                    <p>Payment activity for your linked children will appear here.</p>
                    <a class="parent-dashboard-action" href="<?php echo e(route_url('/parent/payments/index')); ?>">View Payment History</a>
                </div>
            <?php else: ?>
                <div class="parent-dashboard-payment-list">
                    <?php foreach ($payments['payments'] as $payment): ?>
                        <a class="parent-dashboard-payment-row" href="<?php echo e(route_url('/parent/payments/view') . '?id=' . (int) $payment['id']); ?>">
                            <span class="parent-dashboard-activity-icon" aria-hidden="true"><i class="bi bi-arrow-down-left"></i></span>
                            <span class="parent-dashboard-payment-info"><strong><?php echo e($payment['student_name']); ?></strong><small><?php echo e(date('j M Y', strtotime((string) ($payment['verified_at'] ?? $payment['created_at'])))); ?> · <?php echo e(ucwords(str_replace('_', ' ', (string) $payment['status']))); ?></small></span>
                            <strong class="parent-dashboard-payment-amount"><?php echo e((string) $payment['currency']); ?> <?php echo e(number_format((float) $payment['amount'], 2)); ?></strong>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="parent-dashboard-panel" aria-labelledby="parent-announcements-heading">
            <div class="parent-dashboard-panel-heading">
                <div><h2 id="parent-announcements-heading">Announcements</h2><p>Recent notices from your institution.</p></div>
                <a class="parent-dashboard-action" href="<?php echo e(route_url('/parent/announcements')); ?>">All Notices <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            </div>
            <?php if ($announcements === []): ?>
                <div class="parent-dashboard-empty">
                    <span class="parent-dashboard-empty-icon" aria-hidden="true"><i class="bi bi-megaphone"></i></span>
                    <strong>No current announcements</strong>
                    <p>New notices from your institution will appear here.</p>
                </div>
            <?php else: ?>
                <div class="parent-dashboard-announcement-list">
                    <?php foreach ($announcements as $announcement): ?>
                        <article>
                            <time datetime="<?php echo e(date('Y-m-d', strtotime((string) ($announcement['publish_at'] ?? $announcement['created_at'])))); ?>"><?php echo e(date('j M Y', strtotime((string) ($announcement['publish_at'] ?? $announcement['created_at'])))); ?></time>
                            <h3><?php echo e($announcement['title']); ?></h3>
                            <p><?php echo e(mb_strimwidth((string) $announcement['message'], 0, 180, '…', 'UTF-8')); ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </aside>
</div>
<?php render_app_page_end('parent'); ?>
