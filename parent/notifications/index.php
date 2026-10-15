<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/parent-portal-service.php';

require_http_methods(['GET', 'HEAD', 'POST']);
require_placeholder_route_access();
$db = db_connect();
$context = parent_portal_require_current_parent($db);
$parentId = (int) $context['parent']['parent_id'];
$institutionId = (int) $context['user']['institution_id'];
$userId = (int) $context['user']['id'];
$rawPage = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$page = $rawPage === false || $rawPage < 1 ? 1 : $rawPage;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    $action = $_POST['action'] ?? null;
    if ($action === 'mark_all_read') {
        $updated = notification_service_mark_all_parent_notifications_read(
            $db,
            $parentId,
            $institutionId,
            $userId
        );
        set_flash('success', $updated === 0
            ? 'There are no unread notifications.'
            : 'All notifications were marked as read.');
    } elseif ($action === 'mark_read') {
        $rawNotificationId = filter_var($_POST['notification_id'] ?? null, FILTER_VALIDATE_INT);
        $marked = $rawNotificationId !== false && $rawNotificationId > 0
            && notification_service_mark_parent_notification_read(
                $db,
                $parentId,
                $institutionId,
                $userId,
                (int) $rawNotificationId
            );
        set_flash(
            $marked ? 'success' : 'warning',
            $marked ? 'Notification marked as read.' : 'That notification is not available to your account.'
        );
    } else {
        set_flash('warning', 'The notification action is invalid.');
    }
    safe_redirect('/parent/notifications/index?page=' . $page, 303);
}

$notificationPage = notification_service_get_parent_notifications(
    $db,
    $parentId,
    $institutionId,
    $userId,
    $page
);
if ($page > $notificationPage['page_count']) {
    $page = $notificationPage['page_count'];
    $notificationPage = notification_service_get_parent_notifications(
        $db,
        $parentId,
        $institutionId,
        $userId,
        $page
    );
}
$notifications = $notificationPage['items'];
$unreadCount = notification_service_unread_count($db, $parentId, $institutionId, $userId);

render_app_page_start('Notifications', 'Messages sent to your parent account.', 'parent', ['parent-children.css']);
?>
<?php echo render_flash_messages(); ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb parent-children-breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/parent/dashboard')); ?>">Overview</a></li><li class="breadcrumb-item active" aria-current="page">Notifications</li></ol></nav>
<section class="parent-children-panel">
    <div class="parent-children-panel-heading">
        <div><h2>Your notifications</h2><p>Only notifications addressed to your account and currently authorized linked children are shown.</p></div>
        <?php if ($unreadCount > 0): ?>
            <form method="post" action="<?php echo e(route_url('/parent/notifications/index?page=' . $page)); ?>">
                <?php echo csrf_field(); ?><input type="hidden" name="action" value="mark_all_read">
                <button class="btn btn-outline-primary btn-sm" type="submit">Mark all as read</button>
            </form>
        <?php endif; ?>
    </div>
    <?php if ($notifications === []): ?>
        <div class="parent-children-empty"><span><i class="bi bi-bell-slash" aria-hidden="true"></i></span><h3>No notifications</h3><p>Messages sent to your account will appear here.</p></div>
    <?php else: ?>
        <div class="parent-dashboard-announcement-list parent-announcements-list">
            <?php foreach ($notifications as $notification): ?>
                <?php $isRead = $notification['read_at'] !== null || $notification['status'] === 'read'; ?>
                <article class="parent-notification<?php echo $isRead ? '' : ' is-unread'; ?>">
                    <time datetime="<?php echo e(date('c', strtotime((string) $notification['created_at']))); ?>"><?php echo e(date('j M Y, g:i a', strtotime((string) $notification['created_at']))); ?></time>
                    <h3><?php echo e($notification['title']); ?></h3>
                    <p><?php echo nl2br(e((string) $notification['message'])); ?></p>
                    <span class="parent-notification-meta"><?php echo e(notification_service_type_label((string) $notification['notification_type'])); ?><?php if ($notification['student_name'] !== null): ?> · <?php echo e($notification['student_name']); ?><?php endif; ?> · <?php echo $isRead ? 'Read' : 'Unread'; ?></span>
                    <?php if (!$isRead): ?>
                        <form method="post" action="<?php echo e(route_url('/parent/notifications/index?page=' . $page)); ?>" class="mt-2">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="mark_read">
                            <input type="hidden" name="notification_id" value="<?php echo e((string) $notification['id']); ?>">
                            <button class="btn btn-sm btn-outline-secondary" type="submit">Mark as read</button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
        <?php if ($notificationPage['page_count'] > 1): ?>
            <nav class="d-flex justify-content-between align-items-center mt-3" aria-label="Notification pages">
                <span class="text-muted small">Page <?php echo e((string) $page); ?> of <?php echo e((string) $notificationPage['page_count']); ?></span>
                <div class="d-flex gap-2">
                    <?php if ($page > 1): ?><a class="btn btn-outline-primary btn-sm" href="<?php echo e(route_url('/parent/notifications/index?page=' . ($page - 1))); ?>">Newer</a><?php endif; ?>
                    <?php if ($page < $notificationPage['page_count']): ?><a class="btn btn-outline-primary btn-sm" href="<?php echo e(route_url('/parent/notifications/index?page=' . ($page + 1))); ?>">Older</a><?php endif; ?>
                </div>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php render_app_page_end('parent'); ?>
