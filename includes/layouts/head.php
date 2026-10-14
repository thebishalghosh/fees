<?php
declare(strict_types=1);

function render_page_head(string $title = '', array $pageStyles = []): void
{
    $appName = (string) app_config()['app_name'];
    $pageTitle = trim($title) !== '' ? $title . ' | ' . $appName : $appName;
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
</head>
<?php
}
