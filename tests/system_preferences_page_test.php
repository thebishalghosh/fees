<?php
declare(strict_types=1);

$preferencesPage = file_get_contents(__DIR__ . '/../admin/settings/general.php');
$profilePage = file_get_contents(__DIR__ . '/../admin/settings/institution.php');
$sidebar = file_get_contents(__DIR__ . '/../includes/layouts/sidebar.php');

$checks = [
    [
        is_string($preferencesPage)
        && str_contains($preferencesPage, "require_placeholder_route_access()")
        && str_contains($preferencesPage, 'render_app_page_start(')
        && str_contains($preferencesPage, "'System Preferences'")
        && str_contains($preferencesPage, "'/admin/settings/institution'")
        && !str_contains($preferencesPage, 'method="post"')
        && !str_contains($preferencesPage, 'institution_branding_save')
        && !str_contains($preferencesPage, 'institution_branding_store_logo'),
        'Keep the old General Settings route as a read-only System Preferences overview',
    ],
    [
        is_string($profilePage)
        && str_contains($profilePage, 'Institution Profile')
        && str_contains($profilePage, 'admin_panel_validate_institution')
        && str_contains($profilePage, 'institution_branding_store_logo')
        && str_contains($profilePage, "route_url('/admin/settings/institution')"),
        'Preserve the institution profile and logo workflows on the dedicated profile route',
    ],
    [
        is_string($sidebar)
        && str_contains($sidebar, "['System Preferences', '/admin/settings/general'")
        && str_contains($sidebar, "['Institution profile', '/admin/settings/institution'")
        && str_contains($sidebar, "\$href === '/admin/settings/institution'"),
        'Expose separate sidebar destinations for preferences and institution profile',
    ],
    [
        is_string($preferencesPage)
        && !str_contains($preferencesPage, 'name="institution_type"')
        && !str_contains($preferencesPage, 'name="principal_name"')
        && !str_contains($preferencesPage, 'name="registration_number"')
        && !str_contains($preferencesPage, 'name="institution_logo"')
        && !str_contains($preferencesPage, 'brand_primary_color'),
        'Do not duplicate institution identity, administration, registration, logo, or branding fields',
    ],
];

foreach ($checks as [$passed, $message]) {
    if (!$passed) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

fwrite(STDOUT, 'Passed ' . count($checks) . ' System Preferences route checks.' . PHP_EOL);
