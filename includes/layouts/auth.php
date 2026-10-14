<?php
declare(strict_types=1);

require_once __DIR__ . '/head.php';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/footer.php';

function render_auth_page_start(string $title, string $description): void
{
    render_page_head($title);
    ?>
<body class="public-placeholder">
<?php render_site_header('public-placeholder'); ?>
<main class="public-placeholder-main">
    <section class="public-placeholder-card auth-card" aria-labelledby="auth-page-title">
        <span class="placeholder-icon" aria-hidden="true"><i class="bi bi-shield-lock"></i></span>
        <span class="eyebrow">Secure sign-in</span>
        <h1 id="auth-page-title"><?php echo e($title); ?></h1>
        <p><?php echo e($description); ?></p>
        <?php echo render_flash_messages(); ?>
<?php
}

function render_auth_page_end(): void
{
    ?>
    </section>
</main>
<?php render_page_footer('public-placeholder'); ?>
<?php
}
