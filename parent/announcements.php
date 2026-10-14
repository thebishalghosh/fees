<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/page-placeholder.php';
require_once __DIR__ . '/../services/parent-portal-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$db = db_connect();
$context = parent_portal_require_current_parent($db);
$announcements = parent_portal_get_announcements(
    $db,
    (int) $context['parent']['parent_id'],
    (int) $context['user']['institution_id'],
    100
);

render_app_page_start('Announcements', 'Published notices relevant to your institution and linked children.', 'parent', ['parent-children.css']);
?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb parent-children-breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/parent/dashboard')); ?>">Overview</a></li><li class="breadcrumb-item active" aria-current="page">Announcements</li></ol></nav>
<section class="parent-children-panel">
    <div class="parent-children-panel-heading"><div><h2>Institution announcements</h2><p>Only currently published notices visible to your account are shown.</p></div></div>
    <?php if ($announcements === []): ?>
        <div class="parent-children-empty"><span><i class="bi bi-megaphone" aria-hidden="true"></i></span><h3>No current announcements</h3><p>There are no published notices available for your institution or linked children.</p></div>
    <?php else: ?>
        <div class="parent-dashboard-announcement-list parent-announcements-list">
            <?php foreach ($announcements as $announcement): ?>
                <article><time datetime="<?php echo e(date('c', strtotime((string) ($announcement['publish_at'] ?? $announcement['created_at'])))); ?>"><?php echo e(date('j M Y, g:i a', strtotime((string) ($announcement['publish_at'] ?? $announcement['created_at'])))); ?></time><h3><?php echo e($announcement['title']); ?></h3><p><?php echo nl2br(e((string) $announcement['message'])); ?></p></article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php render_app_page_end('parent'); ?>
