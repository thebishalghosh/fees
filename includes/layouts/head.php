<?php
declare(strict_types=1);

function render_page_head(
    string $title = '',
    array $pageStyles = [],
    ?array $institutionBranding = null,
    bool $loginPage = false
): void
{
    $appName = (string) app_config()['app_name'];
    $pageTitle = trim($title) !== '' ? $title . ' | ' . $appName : $appName;
    $brandingSettings = is_array($institutionBranding['branding'] ?? null)
        ? $institutionBranding['branding']
        : [];
    $theme = [];
    if ($institutionBranding !== null) {
        $primary = $loginPage
            ? ($brandingSettings['login_primary_color'] ?? '#0047AB')
            : ($brandingSettings['brand_primary_color'] ?? '#0047AB');
        $secondary = $brandingSettings['brand_secondary_color'] ?? '#003A8C';
        $accent = $brandingSettings['brand_accent_color'] ?? '#C9A35B';
        $theme = [
            '--brand-primary' => $primary,
            '--brand-secondary' => $secondary,
            '--brand-accent' => $accent,
            '--brand-page-background' => $brandingSettings['brand_page_background'] ?? '#F6F8FC',
            '--brand-surface-background' => $brandingSettings['brand_surface_background'] ?? '#FFFFFF',
            '--cobalt' => $primary,
            '--cobalt-300' => $primary,
            '--cobalt-600' => $secondary,
            '--cobalt-700' => $secondary,
            '--gold' => $accent,
            '--gold-soft' => $accent,
            '--surface' => $brandingSettings['brand_surface_background'] ?? '#FFFFFF',
            '--cobalt-50' => $loginPage
                ? ($brandingSettings['login_background_color'] ?? '#F3F7FD')
                : ($brandingSettings['brand_page_background'] ?? '#F6F8FC'),
            '--brand-sidebar-background' => $brandingSettings['brand_sidebar_background'] ?? '#FFFFFF',
            '--brand-sidebar-text' => $brandingSettings['brand_sidebar_text'] ?? '#5B6782',
            '--brand-header-background' => $brandingSettings['brand_header_background'] ?? '#00183F',
            '--brand-header-text' => $brandingSettings['brand_header_text'] ?? '#FFFFFF',
            '--brand-button-background' => $loginPage
                ? ($brandingSettings['login_primary_color'] ?? '#0047AB')
                : ($brandingSettings['brand_button_background'] ?? '#0047AB'),
            '--brand-button-text' => $brandingSettings['brand_button_text'] ?? '#FFFFFF',
        ];
    }
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?php echo e($appName); ?> — parent and student fee management">
    <meta name="theme-color" content="#00183f">
    <title><?php echo e($pageTitle); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo e(APP_URL); ?>/public/assets/css/variables.css" rel="stylesheet">
    <link href="<?php echo e(APP_URL); ?>/public/assets/css/base.css" rel="stylesheet">
    <link href="<?php echo e(APP_URL); ?>/public/assets/css/layout.css" rel="stylesheet">
    <link href="<?php echo e(APP_URL); ?>/public/assets/css/components.css" rel="stylesheet">
    <link href="<?php echo e(APP_URL); ?>/public/assets/css/responsive.css" rel="stylesheet">
    <?php foreach ($pageStyles as $pageStyle): ?>
        <link href="<?php echo e(APP_URL); ?>/public/assets/css/pages/<?php echo e(basename((string) $pageStyle)); ?>" rel="stylesheet">
    <?php endforeach; ?>
    <?php if ($theme !== []): ?>
        <style>:root{<?php foreach ($theme as $property => $value): ?><?php if (is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/D', $value) === 1): ?><?php echo e($property); ?>:<?php echo e($value); ?>;<?php endif; ?><?php endforeach; ?>}</style>
    <?php endif; ?>
</head>
<?php
}
