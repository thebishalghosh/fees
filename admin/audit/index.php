<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/admin-panel-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$searchValue = $_GET['q'] ?? '';
$search = is_string($searchValue) ? trim($searchValue) : '';
if (mb_strlen($search, 'UTF-8') > 100) {
    $search = mb_substr($search, 0, 100, 'UTF-8');
}
$rows = admin_panel_audit_logs(db_connect(), admin_panel_institution_id(), $search);
render_app_page_start('Audit Log', 'Institution activity history. Authentication secrets and audit JSON payloads are not displayed.', 'admin', ['admin-dashboard.css']);
?>
<?php echo render_flash_messages(); ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item active" aria-current="page">Audit Log</li></ol></nav>
<section class="dashboard-panel">
    <form method="get" action="<?php echo e(route_url('/admin/audit/index')); ?>" class="admin-filter-form mb-3">
        <label class="visually-hidden" for="audit-search">Search audit records</label><input id="audit-search" class="form-control" type="search" name="q" maxlength="100" value="<?php echo e($search); ?>" placeholder="Search action, record, or actor">
        <button class="btn btn-cobalt mt-3" type="submit">Search</button>
    </form>
    <?php if ($rows === []): ?>
        <div class="admin-panel-empty"><i class="bi bi-journal-text" aria-hidden="true"></i><strong>No audit records found</strong><span>Activity for this institution will appear here.</span></div>
    <?php else: ?>
        <div class="table-responsive"><table class="table admin-panel-table"><thead><tr><th>Date</th><th>Actor</th><th>Action</th><th>Record</th><th>IP address</th></tr></thead><tbody>
            <?php foreach ($rows as $row): ?><tr><td><?php echo e((string) $row['created_at']); ?></td><td><?php echo e((string) ($row['actor_name'] ?? $row['actor_type'] ?? 'System')); ?></td><td><?php echo e((string) $row['action']); ?></td><td><?php echo e((string) $row['entity_type']); ?><?php if ($row['entity_id'] !== null): ?> #<?php echo e((string) $row['entity_id']); ?><?php endif; ?></td><td><?php echo e((string) ($row['ip_address'] ?? '—')); ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <p class="text-muted mb-0">Showing up to <?php echo e((string) count($rows)); ?> recent records.</p>
    <?php endif; ?>
</section>
<?php render_app_page_end('admin'); ?>
