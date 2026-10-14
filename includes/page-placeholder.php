<?php
declare(strict_types=1);

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/components/alerts.php';
require_once __DIR__ . '/layouts/page.php';

function render_page_placeholder(): void
{
    require_http_methods(['GET', 'HEAD']);
    $scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = rtrim((string) (parse_url(APP_URL, PHP_URL_PATH) ?: ''), '/');
    if ($basePath !== '' && str_starts_with($scriptPath, $basePath . '/')) {
        $scriptPath = substr($scriptPath, strlen($basePath));
    }
    $context = str_starts_with($scriptPath, '/parent/') ? 'parent' : 'admin';
    require_placeholder_route_access();
    $name = pathinfo($scriptPath, PATHINFO_FILENAME);
    if ($name === 'index') {
        $section = basename(dirname($scriptPath));
        $name = $section === $context ? 'overview' : $section;
    }
    $title = ucwords(str_replace(['-', '_'], ' ', $name));

    render_app_page_start($title, 'This screen is a visual placeholder; its data and actions are not implemented.', $context);
    ?>
            <?php echo render_flash_messages(); ?>
            <section class="placeholder-panel card-panel" aria-labelledby="placeholder-title">
                <span class="placeholder-icon" aria-hidden="true"><i class="bi bi-layout-text-window-reverse"></i></span>
                <div>
                    <span class="eyebrow">Interface preview</span>
                    <h2 id="placeholder-title">The <?php echo e(strtolower($title)); ?> interface is not available yet</h2>
                    <p>This page uses the shared FeesPortal layout. No records, actions, or dashboard figures are being shown as live data.</p>
                    <a class="btn btn-cobalt" href="<?php echo e(route_url($context === 'parent' ? '/parent/dashboard' : '/admin/dashboard')); ?>">
                        Return to workspace overview <i class="bi bi-arrow-right ms-2" aria-hidden="true"></i>
                    </a>
                </div>
            </section>
    <?php
    render_app_page_end($context);
}

function render_public_page_placeholder(): void
{
    require_http_methods(['GET', 'HEAD']);
    $scriptPath = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $title = ucwords(str_replace(['-', '_'], ' ', pathinfo($scriptPath, PATHINFO_FILENAME)));

    render_page_head($title);
    ?>
<body class="public-placeholder">
<?php render_site_header('public-placeholder'); ?>
<main class="public-placeholder-main">
    <section class="public-placeholder-card" aria-labelledby="public-placeholder-title">
        <span class="placeholder-icon" aria-hidden="true"><i class="bi bi-shield-lock"></i></span>
        <span class="eyebrow">Setup in progress</span>
        <h1 id="public-placeholder-title"><?php echo e($title); ?> is not available yet</h1>
        <p>This page is a visual placeholder only. No sign-in, registration, recovery, or authentication action is implemented.</p>
        <a class="btn btn-cobalt" href="<?php echo e(route_url('/')); ?>">Return to FeesPortal</a>
    </section>
</main>
<?php render_page_footer('public-placeholder'); ?>
    <?php
}
