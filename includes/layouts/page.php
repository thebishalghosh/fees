<?php
declare(strict_types=1);

require_once __DIR__ . '/head.php';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/sidebar.php';
require_once __DIR__ . '/footer.php';

function render_app_page_start(
    string $title,
    string $subtitle,
    string $context = 'admin',
    array $pageStyles = []
): void
{
    render_page_head($title, $pageStyles);
    ?>
<body class="app-page">
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
