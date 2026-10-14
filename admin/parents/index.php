<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/parent-management-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$institutionId = parent_management_institution_id();
$db = db_connect();
$search = isset($_GET['search']) && is_string($_GET['search']) ? trim($_GET['search']) : '';
if (mb_strlen($search, 'UTF-8') > 255) {
    $search = mb_substr($search, 0, 255, 'UTF-8');
}
$status = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : '';
$link = isset($_GET['link']) && is_string($_GET['link']) ? $_GET['link'] : '';
$allowedStatuses = ['', 'active', 'inactive', 'pending_verification', 'suspended'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}
if (!in_array($link, ['', 'linked', 'unlinked'], true)) {
    $link = '';
}
$requestedPage = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$page = $requestedPage === false || $requestedPage < 1 ? 1 : $requestedPage;
$summary = parent_management_get_summary($db, $institutionId);
$result = parent_management_list($db, $institutionId, $search, $status, $link, $page);
$canManage = user_has_permission('parents.manage');

render_app_page_start(
    'Parent Management',
    'Manage parent profiles and their linked students.',
    'admin',
    ['admin-parents.css']
);
?>
<?php echo render_flash_messages(); ?>
<div class="parents-page-toolbar">
    <nav aria-label="Breadcrumb">
        <ol class="breadcrumb parents-breadcrumb">
            <li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li>
            <li class="breadcrumb-item active" aria-current="page">Parents</li>
        </ol>
    </nav>
    <?php if ($canManage): ?>
        <a class="btn parents-add-button" href="<?php echo e(route_url('/admin/parents/create')); ?>">
            <i class="bi bi-plus-lg" aria-hidden="true"></i><span>Add Parent</span>
        </a>
    <?php endif; ?>
</div>

<section class="parents-summary-grid" aria-label="Parent account summary">
    <article class="parents-summary-card">
        <span class="parents-summary-icon parents-icon-blue" aria-hidden="true"><i class="bi bi-people"></i></span>
        <div><span class="parents-summary-label">Total Parents</span><strong><?php echo e(number_format($summary['total_count'])); ?></strong></div>
    </article>
    <article class="parents-summary-card">
        <span class="parents-summary-icon parents-icon-green" aria-hidden="true"><i class="bi bi-person-check"></i></span>
        <div><span class="parents-summary-label">Active Parents</span><strong><?php echo e(number_format($summary['active_count'])); ?></strong></div>
    </article>
    <article class="parents-summary-card">
        <span class="parents-summary-icon parents-icon-amber" aria-hidden="true"><i class="bi bi-person-dash"></i></span>
        <div><span class="parents-summary-label">Inactive Parents</span><strong><?php echo e(number_format($summary['inactive_count'])); ?></strong></div>
    </article>
    <article class="parents-summary-card">
        <span class="parents-summary-icon parents-icon-violet" aria-hidden="true"><i class="bi bi-person-hearts"></i></span>
        <div><span class="parents-summary-label">Parents Linked to Students</span><strong><?php echo e(number_format($summary['linked_count'])); ?></strong></div>
    </article>
</section>

<section class="parents-list-panel" aria-labelledby="parents-list-title">
    <div class="parents-list-heading">
        <div>
            <div class="parents-title-row">
                <h2 id="parents-list-title">Parent Directory</h2>
                <span class="parents-live-banner"><i class="bi bi-database-check" aria-hidden="true"></i> Live institution data</span>
            </div>
            <p>Parent accounts and active student links for your institution.</p>
        </div>
        <span class="parents-result-count" aria-live="polite"><strong><?php echo e(number_format($result['total'])); ?></strong> records</span>
    </div>

    <form class="parents-filter-toolbar" action="<?php echo e(route_url('/admin/parents/index')); ?>" method="get" role="search">
        <label class="parents-search-field">
            <span class="visually-hidden">Search parents by name, email or phone</span>
            <i class="bi bi-search" aria-hidden="true"></i>
            <input
                class="form-control"
                type="search"
                name="search"
                value="<?php echo e($search); ?>"
                placeholder="Search by parent name, email or phone…"
                autocomplete="off"
            >
        </label>
        <label class="visually-hidden" for="parents-status-filter">Filter by parent status</label>
        <select class="form-select parents-filter-select" id="parents-status-filter" name="status">
            <option value="">All Status</option>
            <?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'pending_verification' => 'Pending verification', 'suspended' => 'Suspended'] as $value => $label): ?>
                <option value="<?php echo e($value); ?>"<?php echo $status === $value ? ' selected' : ''; ?>><?php echo e($label); ?></option>
            <?php endforeach; ?>
        </select>
        <label class="visually-hidden" for="parents-link-filter">Filter by student link</label>
        <select class="form-select parents-filter-select" id="parents-link-filter" name="link">
            <option value="">All Parents</option>
            <option value="linked"<?php echo $link === 'linked' ? ' selected' : ''; ?>>Linked</option>
            <option value="unlinked"<?php echo $link === 'unlinked' ? ' selected' : ''; ?>>Not Linked</option>
        </select>
        <div class="parents-filter-actions">
            <button class="parents-submit-filter" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i>Apply</button>
            <a class="parents-reset-button" href="<?php echo e(route_url('/admin/parents/index')); ?>"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Reset Filters</a>
        </div>
    </form>

    <div class="table-responsive parents-table-scroll">
        <table class="table parents-table align-middle">
            <thead>
                <tr>
                    <th scope="col">Parent</th>
                    <th scope="col">Contact Information</th>
                    <th scope="col">Linked Students</th>
                    <th scope="col">Relationship</th>
                    <th scope="col">Status</th>
                    <th scope="col">Joined Date</th>
                    <th scope="col"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result['parents'] === []): ?>
                    <tr>
                        <td colspan="7">
                            <div class="parents-empty-state">
                                <span aria-hidden="true"><i class="bi bi-search"></i></span>
                                <strong>No parents match these filters</strong>
                                <p>Try a different search or reset the filters.</p>
                                <a class="parents-empty-reset" href="<?php echo e(route_url('/admin/parents/index')); ?>">Reset filters</a>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($result['parents'] as $parent):
                        $initials = implode('', array_map(
                            static fn (string $part): string => strtoupper(substr($part, 0, 1)),
                            array_filter(explode(' ', trim((string) $parent['full_name'])))
                        ));
                        $statusLabel = ucwords(str_replace('_', ' ', (string) $parent['status']));
                        $statusClass = match ($parent['status']) {
                            'active', 'inactive' => $parent['status'],
                            'suspended' => 'suspended',
                            default => 'pending',
                        };
                        ?>
                        <tr>
                            <td>
                                <div class="parent-identity">
                                    <span class="parent-avatar" aria-hidden="true"><?php echo e($initials); ?></span>
                                    <span class="parent-name"><?php echo e($parent['full_name']); ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="parent-contact">
                                    <span><i class="bi bi-envelope" aria-hidden="true"></i><?php echo e($parent['email'] ?? 'Not provided'); ?></span>
                                    <span><i class="bi bi-telephone" aria-hidden="true"></i><?php echo e($parent['mobile']); ?></span>
                                </div>
                            </td>
                            <td>
                                <span class="parent-student-count<?php echo (int) $parent['linked_student_count'] === 0 ? ' is-zero' : ''; ?>">
                                    <i class="bi bi-mortarboard" aria-hidden="true"></i>
                                    <?php echo e((string) $parent['linked_student_count']); ?>
                                    <span><?php echo (int) $parent['linked_student_count'] === 1 ? 'student' : 'students'; ?></span>
                                </span>
                            </td>
                            <td><span class="parent-relationship"><?php echo e($parent['relationship_to_student'] !== null ? ucfirst((string) $parent['relationship_to_student']) : 'Not specified'); ?></span></td>
                            <td><span class="parent-status parent-status-<?php echo e($statusClass); ?>"><i aria-hidden="true"></i><?php echo e($statusLabel); ?></span></td>
                            <td><time datetime="<?php echo e(date('Y-m-d', strtotime((string) $parent['created_at']))); ?>"><?php echo e(date('j M Y', strtotime((string) $parent['created_at']))); ?></time></td>
                            <td>
                                <div class="parent-row-actions" aria-label="Actions for <?php echo e($parent['full_name']); ?>">
                                    <a class="parent-action-button" title="View Parent" aria-label="View <?php echo e($parent['full_name']); ?>" href="<?php echo e(route_url('/admin/parents/view') . '?id=' . (int) $parent['id']); ?>"><i class="bi bi-eye" aria-hidden="true"></i></a>
                                    <?php if ($canManage): ?>
                                        <a class="parent-action-button" title="Edit Parent" aria-label="Edit <?php echo e($parent['full_name']); ?>" href="<?php echo e(route_url('/admin/parents/edit') . '?id=' . (int) $parent['id']); ?>"><i class="bi bi-pencil" aria-hidden="true"></i></a>
                                        <?php if (in_array($parent['status'], ['active', 'inactive', 'pending_verification'], true)): ?>
                                            <?php $deactivateAction = in_array($parent['status'], ['active', 'pending_verification'], true); ?>
                                                <form class="parent-inline-status-form" action="<?php echo e(route_url('/admin/parents/toggle-status')); ?>" method="post" onsubmit="return confirm('Change access for this parent? Historical payments and student links will be preserved.');">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="parent_id" value="<?php echo e((string) $parent['id']); ?>">
                                                    <button class="parent-action-button<?php echo $deactivateAction ? ' is-danger' : ''; ?>" type="submit" title="<?php echo $deactivateAction ? 'Deactivate' : 'Activate / Reactivate'; ?> Parent" aria-label="<?php echo $deactivateAction ? 'Deactivate' : 'Activate or reactivate'; ?> <?php echo e($parent['full_name']); ?>">
                                                        <i class="bi <?php echo $deactivateAction ? 'bi-person-dash' : 'bi-person-check'; ?>" aria-hidden="true"></i>
                                                    </button>
                                                </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($result['total_pages'] > 1): ?>
        <nav class="parents-pagination" aria-label="Parent list pages">
            <span>Page <?php echo e((string) $result['page']); ?> of <?php echo e((string) $result['total_pages']); ?></span>
            <div>
                <?php if ($result['page'] > 1): ?>
                    <?php $previousQuery = http_build_query(['search' => $search, 'status' => $status, 'link' => $link, 'page' => $result['page'] - 1]); ?>
                    <a href="<?php echo e(route_url('/admin/parents/index') . '?' . $previousQuery); ?>"><i class="bi bi-chevron-left" aria-hidden="true"></i>Previous</a>
                <?php endif; ?>
                <?php if ($result['page'] < $result['total_pages']): ?>
                    <?php $nextQuery = http_build_query(['search' => $search, 'status' => $status, 'link' => $link, 'page' => $result['page'] + 1]); ?>
                    <a href="<?php echo e(route_url('/admin/parents/index') . '?' . $nextQuery); ?>">Next<i class="bi bi-chevron-right" aria-hidden="true"></i></a>
                <?php endif; ?>
            </div>
        </nav>
    <?php endif; ?>
</section>
<?php render_app_page_end('admin'); ?>
