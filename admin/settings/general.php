<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();

$preferences = [
    [
        'title' => 'Regional preferences',
        'description' => 'Timezone and date formatting are controlled by platform configuration and are not institution-editable.',
        'icon' => 'bi-globe2',
        'items' => [
            ['Timezone', (string) app_config()['timezone']],
            ['Date and time format', 'Application defaults'],
            ['Language', 'Not configurable'],
        ],
    ],
    [
        'title' => 'Application preferences',
        'description' => 'Academic year, attendance, and communication are managed in their respective modules.',
        'icon' => 'bi-sliders',
        'items' => [
            ['Academic year', 'Manage in Academic Years'],
            ['Attendance', 'Manage in Attendance'],
            ['Notifications', 'Manage in Announcements'],
        ],
    ],
];

render_app_page_start(
    'System Preferences',
    'Review application-wide preferences and find where institution settings are managed.',
    'admin',
    ['admin-dashboard.css', 'system-preferences.css']
);
?>
<?php echo render_flash_messages(); ?>
<nav aria-label="Breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li>
        <li class="breadcrumb-item active" aria-current="page">System Preferences</li>
    </ol>
</nav>

<section class="dashboard-panel admin-form-panel system-preferences-intro">
    <span class="dashboard-section-kicker">PREFERENCES OVERVIEW</span>
    <h2 class="h5 mt-2">No editable institution preferences are configured yet</h2>
    <p class="text-muted mb-0">
        This page does not store or change settings. Existing platform-level values are shown for reference;
        institution profile and branding remain available from Institution Profile.
    </p>
</section>

<div class="system-preferences-grid">
    <?php foreach ($preferences as $section): ?>
        <?php $sectionId = strtolower(str_replace(' ', '-', $section['title'])); ?>
        <section class="dashboard-panel system-preferences-card" aria-labelledby="<?php echo e($sectionId); ?>">
            <div class="system-preferences-heading">
                <span class="system-preferences-icon" aria-hidden="true"><i class="bi <?php echo e($section['icon']); ?>"></i></span>
                <div>
                    <h2 id="<?php echo e($sectionId); ?>"><?php echo e($section['title']); ?></h2>
                    <p><?php echo e($section['description']); ?></p>
                </div>
            </div>
            <dl class="system-preferences-list">
                <?php foreach ($section['items'] as [$label, $value]): ?>
                    <div>
                        <dt><?php echo e($label); ?></dt>
                        <dd><?php echo e($value); ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        </section>
    <?php endforeach; ?>
</div>

<?php if ((current_user()['user_type'] ?? null) === 'admin'): ?>
<section class="dashboard-panel system-preferences-links">
    <div>
        <h2 class="h6 mb-1">Institution-specific settings</h2>
        <p class="text-muted mb-0">Manage the institution identity and visual branding in the dedicated profile page.</p>
    </div>
    <a class="btn btn-outline-primary" href="<?php echo e(route_url('/admin/settings/institution')); ?>">
        <i class="bi bi-building me-1" aria-hidden="true"></i>Open Institution Profile
    </a>
</section>
<?php endif; ?>
<?php render_app_page_end('admin'); ?>
