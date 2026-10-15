<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/admin-panel-service.php';
require_once __DIR__ . '/../../services/notification-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$notifications = admin_panel_notifications(db_connect(), admin_panel_institution_id());

render_app_page_start('Notification delivery history', 'Portal notifications generated for accounts in your institution.', 'admin', ['admin-dashboard.css']);
?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/notifications/index')); ?>">Announcements</a></li><li class="breadcrumb-item active" aria-current="page">Delivery history</li></ol></nav>
<section class="dashboard-panel admin-recent-payments-panel">
    <div class="dashboard-panel-heading"><div><h2>Notification history</h2><p>Generated messages are read-only here; payment status and other source records are never changed by this screen.</p></div></div>
    <?php if ($notifications === []): ?>
        <div class="admin-panel-empty"><i class="bi bi-bell-slash" aria-hidden="true"></i><strong>No generated notifications</strong><span>Notifications appear here after the scheduled generator processes eligible records.</span></div>
    <?php else: ?>
        <div class="table-responsive"><table class="table admin-panel-table">
            <thead><tr><th>Title</th><th>Category</th><th>Recipient</th><th>Student</th><th>Status</th><th>Scheduled</th><th>Created</th></tr></thead>
            <tbody><?php foreach ($notifications as $notification): ?>
                <tr>
                    <td><?php echo e($notification['title']); ?></td>
                    <td><?php echo e(notification_service_type_label((string) $notification['notification_type'])); ?></td>
                    <td><?php echo e($notification['recipient_name']); ?><small><?php echo e($notification['recipient_email'] ?? ''); ?></small></td>
                    <td><?php echo e($notification['student_name'] ?? '—'); ?></td>
                    <td><span class="admin-status-badge is-<?php echo e((string) $notification['status']); ?>"><?php echo e(ucfirst((string) $notification['status'])); ?></span></td>
                    <td><?php echo e($notification['scheduled_at'] !== null ? date('j M Y, g:i a', strtotime((string) $notification['scheduled_at'])) : '—'); ?></td>
                    <td><?php echo e(date('j M Y, g:i a', strtotime((string) $notification['created_at']))); ?></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php render_app_page_end('admin'); ?>