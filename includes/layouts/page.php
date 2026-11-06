<?php
declare(strict_types=1);

require_once __DIR__ . '/head.php';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/sidebar.php';
require_once __DIR__ . '/footer.php';
require_once __DIR__ . '/../../services/admin-panel-service.php';

function render_admin_institution_selector(): void
{
    $user = current_user();
    $isPlatformAdmin = current_platform_admin() !== null;
    if (!$isPlatformAdmin && ($user === null || $user['user_type'] !== 'super_admin')) {
        return;
    }

    $institutions = admin_panel_institution_options(db_connect());
    $selectedInstitutionId = $isPlatformAdmin
        ? platform_admin_selected_institution_id()
        : admin_selected_institution_id();
    ?>
    <section class="dashboard-panel admin-institution-context d-flex flex-wrap justify-content-between align-items-center gap-3 p-3 mb-3" aria-label="Institution data filter">
        <div>
            <span class="eyebrow"><?php echo $isPlatformAdmin ? 'Platform administrator workspace' : 'Super-admin workspace'; ?></span>
            <h2 class="h6 mb-1">Institution data filter</h2>
            <p class="text-muted small mb-0">Choose one institution. Your platform account has full access to its admin workspace.</p>
        </div>
        <form method="get" action="<?php echo e(route_url('/admin/dashboard')); ?>" class="d-flex flex-wrap align-items-end gap-2">
            <label class="form-label small mb-0" for="admin-institution-selector">Institution</label>
            <select class="form-select form-select-sm" id="admin-institution-selector" name="institution_id" onchange="this.form.submit()">
                <option value="">Select an institution</option>
                <?php foreach ($institutions as $institution): ?>
                    <option value="<?php echo e((string) $institution['id']); ?>"<?php echo (int) $institution['id'] === $selectedInstitutionId ? ' selected' : ''; ?>>
                        <?php echo e($institution['name'] . ' (' . ucfirst((string) $institution['status']) . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <noscript><button class="btn btn-cobalt btn-sm mt-2" type="submit">Open institution</button></noscript>
        </form>
    </section>
    <?php
}

function render_app_page_start(
    string $title,
    string $subtitle,
    string $context = 'admin',
    array $pageStyles = []
): void
{
    $institutionBranding = institution_branding_get_for_current_user();
    render_page_head($title, $pageStyles, $institutionBranding);
    ?>
<body class="app-page<?php echo $context === 'admin' ? ' app-page-admin' : ''; ?><?php echo $context === 'admin' && current_platform_admin() !== null ? ' app-page-platform-admin' : ''; ?>">
<?php render_site_header($context); ?>
<div class="app-layout">
    <?php render_sidebar_nav($context); ?>
    <main class="app-main">
        <div class="app-content container-fluid">
            <div class="mobile-sidebar-control">
                <button class="btn btn-outline-primary" type="button" data-bs-toggle="offcanvas" data-bs-target="#app-sidebar" aria-controls="app-sidebar">
                    <i class="bi bi-list me-1" aria-hidden="true"></i>Menu
                </button>
            </div>
            <div class="page-heading">
                <div>
                    <span class="eyebrow"><?php echo e(ucfirst($context)); ?> workspace</span>
                    <h1><?php echo e($title); ?></h1>
                    <p><?php echo e($subtitle); ?></p>
                </div>
                <span class="page-heading-mark" aria-hidden="true"><i class="bi bi-mortarboard"></i></span>
            </div>
<?php if ($context === 'admin'): render_admin_institution_selector(); endif; ?>
<?php
}

function render_app_page_end(string $context = 'admin'): void
{
    ?>
        </div>
    </main>
</div>
<?php render_page_footer($context); ?>
<?php
}
