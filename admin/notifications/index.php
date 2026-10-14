<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/admin-panel-service.php';

require_http_methods(['GET', 'POST']);
require_placeholder_route_access();
$institutionId = admin_panel_institution_id();
$db = db_connect();
$user = current_user();
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    $announcementId = filter_var($_POST['announcement_id'] ?? null, FILTER_VALIDATE_INT);
    $status = $_POST['status'] ?? null;
    if ($announcementId === false || !is_string($status)) {
        $errors[] = 'The announcement action is invalid.';
    } else {
        try {
            admin_panel_set_announcement_status($db, $institutionId, (int) $user['id'], (int) $announcementId, $status);
            set_flash('success', 'Announcement status updated.');
            safe_redirect('/admin/notifications/index', 303);
        } catch (InvalidArgumentException | OutOfBoundsException $exception) {
            $errors[] = $exception->getMessage();
        } catch (Throwable $exception) {
            log_error('admin_announcement_status_update_failed', [
                'error_type' => get_class($exception),
                'error_code' => $exception->getCode(),
            ]);
            $errors[] = 'The announcement status could not be updated. Please try again.';
        }
    }
}
$announcements = admin_panel_simple_list($db, $institutionId, 'announcements');

render_app_page_start('Announcements', 'Manage institution notices and their optional batch visibility targets.', 'admin', ['admin-dashboard.css']);
?>
<?php echo render_flash_messages(); ?>
<?php foreach ($errors as $error): ?><div class="alert alert-danger" role="alert"><?php echo e($error); ?></div><?php endforeach; ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item active" aria-current="page">Announcements</li></ol></nav>
<section class="dashboard-panel admin-recent-payments-panel">
    <div class="dashboard-panel-heading"><div><h2>Institution announcements</h2><p>Global notices have no batch targets; targeted notices are restricted to linked families in those batches.</p></div><a class="btn btn-cobalt" href="<?php echo e(route_url('/admin/notifications/create')); ?>">Create announcement</a></div>
    <?php if ($announcements === []): ?><div class="admin-panel-empty"><i class="bi bi-megaphone" aria-hidden="true"></i><strong>No announcements yet</strong><span>Create a draft or published notice for this institution.</span></div><?php else: ?>
        <div class="table-responsive"><table class="table admin-panel-table"><thead><tr><th>Title</th><th>Status</th><th>Visibility</th><th>Publishes</th><th>Expires</th><th>Created by</th><th>Created</th><th>Action</th></tr></thead><tbody><?php foreach ($announcements as $announcement): ?><tr><td><?php echo e($announcement['title']); ?></td><td><span class="admin-status-badge is-<?php echo e((string) $announcement['status']); ?>"><?php echo e(ucfirst((string) $announcement['status'])); ?></span></td><td><?php echo (int) $announcement['target_batch_count'] === 0 ? 'All eligible families' : e(number_format((int) $announcement['target_batch_count']) . ' batches'); ?></td><td><?php echo e($announcement['publish_at'] !== null ? date('j M Y, g:i a', strtotime((string) $announcement['publish_at'])) : 'Immediately / not scheduled'); ?></td><td><?php echo e($announcement['expires_at'] !== null ? date('j M Y, g:i a', strtotime((string) $announcement['expires_at'])) : 'No expiry'); ?></td><td><?php echo e($announcement['creator_name'] ?? 'System'); ?></td><td><?php echo e(date('j M Y', strtotime((string) $announcement['created_at']))); ?></td><td><form method="post" action="<?php echo e(route_url('/admin/notifications/index')); ?>"><?php echo csrf_field(); ?><input type="hidden" name="announcement_id" value="<?php echo e((string) $announcement['id']); ?>"><input type="hidden" name="status" value="<?php echo $announcement['status'] === 'published' ? 'draft' : 'published'; ?>"><button class="btn btn-sm btn-outline-secondary" type="submit"><?php echo $announcement['status'] === 'published' ? 'Unpublish' : 'Publish'; ?></button></form></td></tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
</section>
<?php render_app_page_end('admin'); ?>
