<?php
declare(strict_types=1);

require_once __DIR__ . '/../../services/institution-branding-service.php';
require_once __DIR__ . '/../../services/notification-service.php';
require_once __DIR__ . '/../auth.php';

function render_site_header(string $context = 'public', ?array $previewBranding = null): void
{
    $isPublic = str_starts_with($context, 'public');
    $institutionBranding = $previewBranding;
    $notificationUnreadCount = 0;
    if (!$isPublic) {
        $user = current_user();
        if ($user !== null && ($user['user_type'] ?? null) !== 'super_admin') {
            $institutionBranding = institution_branding_get_for_current_user();
            if ($context === 'parent' && $user['user_type'] === 'parent') {
                $db = db_connect();
                $notificationUnreadCount = notification_service_unread_count_for_user(
                    $db,
                    (int) $user['institution_id'],
                    (int) $user['id']
                );
            }
        }
    }
    $navItems = $isPublic
        ? [
            ['Panels', '#panels'],
            ['Features', '#features'],
            ['Fee plans', '#plans'],
            ['Payments', '#payments'],
            ['Admissions', '#admission'],
            ['Administration', '#admin'],
        ]
        : [
            ['Overview', $context === 'parent' ? '/parent/dashboard' : '/admin/dashboard'],
            ['Students', $context === 'parent' ? '/parent/children' : '/admin/students/index'],
            ['Fees', $context === 'parent' ? '/parent/fees/index' : '/admin/fees/structures'],
        ];
    $brandHref = $context === 'public' ? '#top' : route_url('/');
    ?>
<nav class="navbar navbar-expand-lg site-navbar<?php echo $isPublic ? ' site-navbar-public' : ' site-navbar-app'; ?>" id="site-nav">
    <div class="container">
        <a class="navbar-brand site-brand" href="<?php echo e($brandHref); ?>" aria-label="<?php echo e(is_array($institutionBranding) ? $institutionBranding['name'] : 'FeesPortal'); ?>">
            <?php if (is_array($institutionBranding)
                && institution_branding_logo_file(is_string($institutionBranding['logo_path'] ?? null) ? $institutionBranding['logo_path'] : null) !== null): ?>
                <?php
                $logoVersion = institution_branding_logo_version(
                    is_string($institutionBranding['logo_path'] ?? null) ? $institutionBranding['logo_path'] : null
                );
                $logoUrl = $context === 'public-placeholder'
                    ? route_url('/institution-logo?institution_code=' . rawurlencode((string) $institutionBranding['code']) . '&v=' . $logoVersion)
                    : route_url(($context === 'parent' ? '/parent/institution-logo' : '/admin/institution-logo') . '?v=' . $logoVersion);
                ?>
                <img class="institution-branding-logo" src="<?php echo e($logoUrl); ?>" alt="">
            <?php else: ?>
                <span class="brand-mark" aria-hidden="true"><i class="bi bi-mortarboard-fill"></i></span>
            <?php endif; ?>
            <span class="brand-name"><?php echo e(is_array($institutionBranding) ? ($institutionBranding['short_name'] ?: $institutionBranding['name']) : 'FeesPortal'); ?><small><?php echo e(is_array($institutionBranding) ? ($institutionBranding['short_name'] ? $institutionBranding['name'] : 'Institution portal') : 'Blue Eyes Infosoft'); ?></small></span>
        </a>
        <button class="navbar-toggler site-nav-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#site-menu" aria-controls="site-menu" aria-expanded="false" aria-label="Toggle navigation">
            <i class="bi bi-list" aria-hidden="true"></i>
        </button>
        <div class="collapse navbar-collapse" id="site-menu">
            <ul class="navbar-nav mx-auto">
                <?php foreach ($navItems as [$label, $href]): ?>
                    <?php
                    $navHref = $context === 'public'
                        ? $href
                        : ($isPublic ? route_url('/' . ltrim($href, '/')) : route_url($href));
                    ?>
                    <li class="nav-item"><a class="nav-link" href="<?php echo e($navHref); ?>"><?php echo e($label); ?></a></li>
                <?php endforeach; ?>
            </ul>
            <?php if ($isPublic): ?>
                <div class="d-flex align-items-center gap-2">
                    <?php if (is_authenticated()): ?>
                        <a class="btn btn-outline-light btn-sm px-3 py-2" href="<?php echo e(route_url(authenticated_dashboard_route())); ?>">Go to Dashboard</a>
                    <?php elseif ($context === 'public'): ?>
                        <a class="btn btn-outline-light btn-sm px-3 py-2" href="<?php echo e(route_url('/login')); ?>"
                            data-bs-toggle="modal" data-bs-target="#login-modal"
                            aria-controls="login-modal">Sign in</a>
                    <?php else: ?>
                        <a class="btn btn-outline-light btn-sm px-3 py-2" href="<?php echo e(route_url('/login')); ?>">Sign in</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="d-flex align-items-center gap-3">
                    <?php if (is_array($institutionBranding) && !$isPublic): ?>
                        <span class="workspace-label institution-branding-label">
                            <?php if (institution_branding_logo_file(is_string($institutionBranding['logo_path'] ?? null) ? $institutionBranding['logo_path'] : null) !== null): ?>
                                <img class="institution-branding-logo" src="<?php echo e(route_url(($context === 'parent' ? '/parent/institution-logo' : '/admin/institution-logo') . '?v=' . institution_branding_logo_version((string) $institutionBranding['logo_path']))); ?>" alt="">
                            <?php endif; ?>
                            <?php echo e($institutionBranding['name']); ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($context === 'parent' && $notificationUnreadCount > 0): ?>
                        <a class="btn btn-outline-light btn-sm position-relative" href="<?php echo e(route_url('/parent/notifications/index')); ?>" aria-label="<?php echo e('Notifications, ' . $notificationUnreadCount . ' unread'); ?>">
                            <i class="bi bi-bell me-1" aria-hidden="true"></i>Notifications
                            <span class="badge rounded-pill text-bg-danger ms-1"><?php echo e((string) $notificationUnreadCount); ?></span>
                        </a>
                    <?php elseif ($context === 'parent'): ?>
                        <a class="btn btn-outline-light btn-sm" href="<?php echo e(route_url('/parent/notifications/index')); ?>"><i class="bi bi-bell me-1" aria-hidden="true"></i>Notifications</a>
                    <?php endif; ?>
                    <span class="workspace-label"><i class="bi bi-grid-1x2-fill me-2" aria-hidden="true"></i><?php
                        $workspaceLabel = ucfirst($context) . ' workspace';
                        if ($context === 'parent') {
                            $workspaceLabel = (string) ($_SESSION['user']['full_name'] ?? 'Parent')
                                . ' · Active account';
                        }
                        echo e($workspaceLabel);
                    ?></span>
                    <form method="post" action="<?php echo e(route_url('/logout')); ?>" class="m-0">
                        <?php echo csrf_field(); ?>
                        <button class="btn btn-outline-light btn-sm" type="submit">Sign out</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</nav>
<?php
}
