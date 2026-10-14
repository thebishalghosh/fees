<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/admin-panel-service.php';

require_http_methods(['GET', 'POST']);
require_placeholder_route_access();
$db = db_connect();
$institutionId = admin_panel_institution_id();
$user = current_user();
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    $targetId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
    $status = $_POST['status'] ?? null;
    if ($targetId === false || !is_string($status)) {
        $errors[] = 'The staff status request is invalid.';
    } else {
        try {
            admin_panel_set_staff_status($db, $institutionId, (int) $user['id'], (int) $targetId, $status);
            set_flash('success', 'Staff account status updated.');
            safe_redirect('/admin/staff/index', 303);
        } catch (InvalidArgumentException | OutOfBoundsException $exception) {
            $errors[] = $exception->getMessage();
        } catch (Throwable $exception) {
            log_error('admin_staff_status_update_failed', [
                'error_type' => get_class($exception),
                'error_code' => $exception->getCode(),
            ]);
            $errors[] = 'The staff account could not be updated. Please try again.';
        }
    }
}
$accounts = admin_panel_staff_accounts($db, $institutionId);
render_app_page_start('Staff & Administrators', 'Review institution accounts and manage staff activation without changing roles.', 'admin', ['admin-dashboard.css']);
?>
<?php echo render_flash_messages(); ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item active" aria-current="page">Staff &amp; Administrators</li></ol></nav>
<?php foreach ($errors as $error): ?><div class="alert alert-danger" role="alert"><?php echo e($error); ?></div><?php endforeach; ?>
<section class="dashboard-panel">
    <?php if ($accounts === []): ?>
        <div class="admin-panel-empty"><i class="bi bi-person-badge" aria-hidden="true"></i><strong>No staff accounts</strong><span>Staff and administrator accounts for this institution will appear here.</span></div>
    <?php else: ?>
        <div class="table-responsive"><table class="table admin-panel-table align-middle"><thead><tr><th>Name</th><th>Role</th><th>Contact</th><th>Employee</th><th>Status</th><th>Last login</th><th>Manage</th></tr></thead><tbody>
        <?php foreach ($accounts as $account): ?>
            <tr>
                <td><strong><?php echo e((string) $account['full_name']); ?></strong><small>#<?php echo e((string) $account['id']); ?></small></td>
                <td><?php echo e((string) ($account['role_name'] ?? $account['user_type'])); ?></td>
                <td><?php echo e((string) $account['email']); ?><small><?php echo e((string) ($account['mobile'] ?? '')); ?></small></td>
                <?php $staffDetails = implode(' · ', array_filter([(string) ($account['employee_code'] ?? ''), (string) ($account['designation'] ?? ''), (string) ($account['department'] ?? '')], static fn(string $value): bool => $value !== '')); ?>
                <td><?php echo e($staffDetails !== '' ? $staffDetails : '—'); ?></td>
                <td><span class="admin-status-badge is-<?php echo e((string) $account['status']); ?>"><?php echo e(ucwords((string) $account['status'])); ?></span></td>
                <td><?php echo e((string) ($account['last_login_at'] ?? '—')); ?></td>
                <td>
                    <?php if ($account['user_type'] === 'staff' && $account['status'] === 'active'): ?>
                        <form method="post" action="<?php echo e(route_url('/admin/staff/index')); ?>" class="d-inline">
                            <?php echo csrf_field(); ?><input type="hidden" name="user_id" value="<?php echo e((string) $account['id']); ?>">
                            <input type="hidden" name="status" value="inactive"><button class="btn btn-sm btn-outline-danger" type="submit">Deactivate</button>
                        </form>
                    <?php elseif ($account['user_type'] === 'staff' && $account['email_verified_at'] !== null): ?>
                        <form method="post" action="<?php echo e(route_url('/admin/staff/index')); ?>" class="d-inline">
                            <?php echo csrf_field(); ?><input type="hidden" name="user_id" value="<?php echo e((string) $account['id']); ?>"><input type="hidden" name="status" value="active"><button class="btn btn-sm btn-outline-primary" type="submit">Activate</button>
                        </form>
                    <?php elseif ($account['user_type'] === 'staff'): ?>
                        <span class="text-muted">Email verification required</span>
                    <?php else: ?><span class="text-muted">Role-managed</span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>
<?php render_app_page_end('admin'); ?>
