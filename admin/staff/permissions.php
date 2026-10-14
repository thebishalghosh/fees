<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$db = db_connect();
$result = $db->query(
    'SELECT r.name AS role_name, p.permission_key, p.description
     FROM roles r
     JOIN role_permissions rp ON rp.role_id = r.id
     JOIN permissions p ON p.id = rp.permission_id
     ORDER BY r.name, p.permission_key'
);
$permissions = $result->fetch_all(MYSQLI_ASSOC);
$result->free();
render_app_page_start('Role Permissions', 'Read-only view of configured role permissions. This page does not grant or change privileges.', 'admin', ['admin-dashboard.css']);
?>
<?php echo render_flash_messages(); ?>
<section class="dashboard-panel">
    <?php if ($permissions === []): ?>
        <div class="admin-panel-empty"><i class="bi bi-shield-lock" aria-hidden="true"></i><strong>No role permissions configured</strong><span>Review the permission seed and role assignments.</span></div>
    <?php else: ?>
        <div class="table-responsive"><table class="table admin-panel-table"><thead><tr><th>Role</th><th>Permission</th><th>Description</th></tr></thead><tbody>
            <?php foreach ($permissions as $permission): ?><tr><td><?php echo e((string) $permission['role_name']); ?></td><td><code><?php echo e((string) $permission['permission_key']); ?></code></td><td><?php echo e((string) $permission['description']); ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>
<?php render_app_page_end('admin'); ?>
