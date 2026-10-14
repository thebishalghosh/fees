<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/parent-portal-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$db = db_connect();
$context = parent_portal_require_current_parent($db);
$notifications = parent_portal_get_notifications(
    $db,
    (int) $context['parent']['parent_id'],
    (int) $context['user']['institution_id'],
    (int) $context['user']['id']
);

render_app_page_start('Notifications', 'Messages sent to your parent account.', 'parent', ['parent-children.css']);
?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb parent-children-breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/parent/dashboard')); ?>">Overview</a></li><li class="breadcrumb-item active" aria-current="page">Notifications</li></ol></nav>
<section class="parent-children-panel">
    <div class="parent-children-panel-heading"><div><h2>Your notifications</h2><p>Student-specific notifications are shown only while you have an active relationship with that child.</p></div></div>
    <?php if ($notifications === []): ?>
        <div class="parent-children-empty"><span><i class="bi bi-bell-slash" aria-hidden="true"></i></span><h3>No notifications</h3><p>Messages sent to your account will appear here.</p></div>
    <?php else: ?>
        <div class="parent-dashboard-announcement-list parent-announcements-list">
            <?php foreach ($notifications as $notification): ?>
                <article class="parent-notification<?php echo $notification['read_at'] === null ? ' is-unread' : ''; ?>">
                    <time datetime="<?php echo e(date('c', strtotime((string) $notification['created_at']))); ?>"><?php echo e(date('j M Y, g:i a', strtotime((string) $notification['created_at']))); ?></time>
                    <h3><?php echo e($notification['title']); ?></h3>
                    <p><?php echo nl2br(e((string) $notification['message'])); ?></p>
                    <span class="parent-notification-meta"><?php echo e(ucwords(str_replace('_', ' ', (string) $notification['notification_type']))); ?><?php if ($notification['student_name'] !== null): ?> · <?php echo e($notification['student_name']); ?><?php endif; ?> · <?php echo e(ucfirst((string) $notification['status'])); ?></span>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php render_app_page_end('parent'); ?>
