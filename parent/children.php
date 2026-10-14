<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/page-placeholder.php';
require_once __DIR__ . '/../services/parent-portal-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();

$user = current_user();
if ($user === null || $user['user_type'] !== 'parent') {
    http_response_code(403);
    exit('Forbidden.');
}
$db = db_connect();
$parent = parent_portal_get_parent_context(
    $db,
    (int) $user['id'],
    (int) $user['institution_id']
);
if ($parent === null) {
    http_response_code(403);
    exit('Forbidden.');
}
$children = parent_portal_get_children(
    $db,
    (int) $parent['parent_id'],
    (int) $user['institution_id']
);
$summary = parent_portal_children_summary($children);

render_app_page_start(
    'My Children',
    'View your linked children’s academic and fee information.',
    'parent',
    ['parent-children.css']
);
?>
<?php echo render_flash_messages(); ?>
<nav aria-label="Breadcrumb">
    <ol class="breadcrumb parent-children-breadcrumb">
        <li class="breadcrumb-item"><a href="<?php echo e(route_url('/parent/dashboard')); ?>">Overview</a></li>
        <li class="breadcrumb-item active" aria-current="page">My Children</li>
    </ol>
</nav>

<section class="parent-children-summary" aria-label="Children summary">
    <article class="parent-children-summary-card">
        <span class="parent-children-summary-icon is-blue" aria-hidden="true"><i class="bi bi-people"></i></span>
        <div><span>Linked children</span><strong><?php echo e(number_format($summary['total_count'])); ?></strong></div>
    </article>
    <article class="parent-children-summary-card">
        <span class="parent-children-summary-icon is-green" aria-hidden="true"><i class="bi bi-person-check"></i></span>
        <div><span>Active students</span><strong><?php echo e(number_format($summary['active_count'])); ?></strong></div>
    </article>
    <?php if ($summary['has_fee_access']): ?>
        <article class="parent-children-summary-card">
            <span class="parent-children-summary-icon is-amber" aria-hidden="true"><i class="bi bi-cash-stack"></i></span>
            <div><span>Outstanding fees</span><strong>₹<?php echo e(number_format($summary['outstanding_amount'], 2)); ?></strong></div>
        </article>
    <?php endif; ?>
</section>

<section class="parent-children-panel" aria-labelledby="children-list-title">
    <div class="parent-children-panel-heading">
        <div>
            <h2 id="children-list-title">Your linked children</h2>
            <p>Student information available through your active institution links.</p>
        </div>
        <span class="parent-children-count"><?php echo e(number_format(count($children))); ?> linked</span>
    </div>

    <?php if ($children === []): ?>
        <div class="parent-children-empty">
            <span aria-hidden="true"><i class="bi bi-people"></i></span>
            <h3>No linked children yet</h3>
            <p>There are no active student links for your account. Please contact your institution administrator if you think this is unexpected.</p>
        </div>
    <?php else: ?>
        <div class="parent-children-grid">
            <?php foreach ($children as $child): ?>
                <article class="parent-child-card">
                    <div class="parent-child-card-heading">
                        <span class="parent-child-avatar" aria-hidden="true"><?php echo e(parent_portal_initials((string) $child['full_name'])); ?></span>
                        <div class="parent-child-identity">
                            <h3><?php echo e($child['full_name']); ?></h3>
                            <span class="parent-child-code">Admission no. <?php echo e($child['student_code']); ?></span>
                        </div>
                        <span class="parent-child-status is-<?php echo e((string) $child['student_status']); ?>"><?php echo e(ucwords(str_replace('_', ' ', (string) $child['student_status']))); ?></span>
                    </div>
                    <dl class="parent-child-facts">
                        <div><dt>Class</dt><dd><?php echo e(!$child['can_view_academics'] ? 'Restricted' : ($child['class_name'] ?? 'Not assigned')); ?></dd></div>
                        <div><dt>Batch</dt><dd><?php echo e(!$child['can_view_academics'] ? 'Restricted' : ($child['batch_name'] ?? 'Not assigned')); ?></dd></div>
                        <div><dt>Academic year</dt><dd><?php echo e(!$child['can_view_academics'] ? 'Restricted' : ($child['academic_year_name'] ?? 'Not assigned')); ?></dd></div>
                        <div><dt>Relationship</dt><dd><?php echo e(ucfirst((string) $child['relationship_type'])); ?></dd></div>
                    </dl>
                    <?php if ($child['outstanding_amount'] !== null): ?>
                        <div class="parent-child-fee">
                            <span>Outstanding fees</span>
                            <strong>₹<?php echo e(number_format((float) $child['outstanding_amount'], 2)); ?></strong>
                        </div>
                    <?php endif; ?>
                    <div class="parent-child-card-footer">
                        <?php if ($child['can_view_academics']): ?>
                            <a class="parent-child-details-link" href="<?php echo e(route_url('/parent/child-details') . '?id=' . (int) $child['id']); ?>">
                                View Details <i class="bi bi-arrow-right" aria-hidden="true"></i>
                            </a>
                        <?php else: ?>
                            <span class="parent-child-restricted"><i class="bi bi-lock" aria-hidden="true"></i> Details not enabled for this link</span>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php render_app_page_end('parent'); ?>
