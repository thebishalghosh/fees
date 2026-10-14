<?php
declare(strict_types=1);

require_once __DIR__ . '/page-placeholder.php';
require_once __DIR__ . '/../services/admin-panel-service.php';

if (!isset($adminListTable, $adminListTitle, $adminListDescription)
    || !is_string($adminListTable)
    || !is_string($adminListTitle)
    || !is_string($adminListDescription)) {
    throw new LogicException('The admin list page is not configured.');
}

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$rows = admin_panel_simple_list(db_connect(), admin_panel_institution_id(), $adminListTable);

render_app_page_start($adminListTitle, $adminListDescription, 'admin', ['admin-dashboard.css']);
?>
<?php echo render_flash_messages(); ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item active" aria-current="page"><?php echo e($adminListTitle); ?></li></ol></nav>
<section class="dashboard-panel">
    <?php $canCreateList = $adminListTable === 'classes' || in_array($adminListTable, ['courses', 'batches'], true) || ($adminListTable === 'fee_structures' && user_has_permission('fees.manage')); ?>
    <?php $canEditList = in_array($adminListTable, ['courses', 'batches'], true) || ($adminListTable === 'fee_structures' && user_has_permission('fees.manage')); ?>
    <?php if ($canCreateList): ?><div class="d-flex justify-content-end mb-3"><a class="btn btn-cobalt" href="<?php echo e(route_url(match ($adminListTable) { 'classes' => '/admin/academics/classes/create', 'courses' => '/admin/courses/create', 'batches' => '/admin/batches/create', default => '/admin/fees/create-structure' })); ?>">Create <?php echo e(match ($adminListTable) { 'classes' => 'class', 'courses' => 'course', 'batches' => 'batch', default => 'fee structure' }); ?></a></div><?php endif; ?>
    <?php if ($rows === []): ?>
        <div class="admin-panel-empty"><i class="bi bi-inbox" aria-hidden="true"></i><strong>No records found</strong><span><?php echo e('No ' . strtolower($adminListTitle) . ' have been added for this institution.'); ?></span></div>
    <?php else: ?>
        <div class="table-responsive"><table class="table admin-panel-table align-middle"><thead><tr>
            <?php foreach (array_keys($rows[0]) as $column): ?><th scope="col"><?php echo e(ucwords(str_replace('_', ' ', $column))); ?></th><?php endforeach; ?>
            <?php if ($canEditList): ?><th scope="col">Action</th><?php endif; ?>
        </tr></thead><tbody>
            <?php foreach ($rows as $row): ?><tr>
                <?php foreach ($row as $value): ?><td><?php echo e($value === null || $value === '' ? '—' : (string) $value); ?></td><?php endforeach; ?>
                <?php if ($canEditList): ?><td><a href="<?php echo e(route_url(match ($adminListTable) { 'courses' => '/admin/courses/edit', 'batches' => '/admin/batches/edit', default => '/admin/fees/edit-structure' }) . '?id=' . (int) $row['id']); ?>">Edit</a></td><?php endif; ?>
            </tr><?php endforeach; ?>
        </tbody></table></div>
        <p class="text-muted mb-0"><?php echo e(number_format(count($rows))); ?> record(s)</p>
    <?php endif; ?>
</section>
<?php render_app_page_end('admin'); ?>
