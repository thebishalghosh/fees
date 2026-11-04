<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/institution-branding-service.php';

$checks = 0;
$failures = [];
$check = static function (bool $condition, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $label;
    }
};

$defaults = institution_branding_default_settings();
$validatedDefaults = institution_branding_validate_settings($defaults);
$check(
    $validatedDefaults['brand_primary_color'] === '#0047AB'
        && $validatedDefaults['login_background_color'] === '#F3F7FD'
        && $validatedDefaults['login_welcome_text'] === '',
    'Provide platform-compatible independent institution branding defaults'
);

$invalidValues = [
    ['brand_primary_color' => 'red'],
    ['brand_sidebar_background' => '#12345'],
    ['brand_header_background' => 'url(https://example.test)'],
    ['login_welcome_text' => str_repeat('x', 121)],
    ['login_welcome_text' => "Hello\nWelcome"],
];
foreach ($invalidValues as $override) {
    $rejected = false;
    try {
        institution_branding_validate_settings(array_replace($defaults, $override));
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    $check($rejected, 'Reject malformed colors and unsafe welcome text');
}

$lowContrastColors = array_replace($defaults, [
    'brand_sidebar_background' => '#FFFFFF',
    'brand_sidebar_text' => '#FFFFFF',
    'brand_header_background' => '#FFFFFF',
    'brand_header_text' => '#FFFFFF',
    'brand_page_background' => '#000000',
    'brand_button_background' => '#FFFFFF',
    'brand_button_text' => '#FFFFFF',
    'login_primary_color' => '#FFFFFF',
]);
$check(
    institution_branding_validate_settings($lowContrastColors) === array_replace(
        $lowContrastColors,
        [
            'brand_sidebar_background' => '#FFFFFF',
            'brand_sidebar_text' => '#FFFFFF',
            'brand_header_background' => '#FFFFFF',
            'brand_header_text' => '#FFFFFF',
            'brand_page_background' => '#000000',
            'brand_button_background' => '#FFFFFF',
            'brand_button_text' => '#FFFFFF',
            'login_primary_color' => '#FFFFFF',
        ]
    ),
    'Accept manually selected colors regardless of text contrast'
);

$validCustomColors = array_replace($defaults, [
    'brand_primary_color' => '#114477',
    'brand_secondary_color' => '#003366',
    'brand_accent_color' => '#AA7733',
    'brand_sidebar_background' => '#FFFFFF',
    'brand_sidebar_text' => '#333333',
    'brand_header_background' => '#102030',
    'brand_header_text' => '#FFFFFF',
    'brand_page_background' => '#F6F8FC',
    'brand_surface_background' => '#FFFFFF',
    'brand_button_background' => '#004477',
    'brand_button_text' => '#FFFFFF',
    'login_background_color' => '#F3F7FD',
    'login_primary_color' => '#004477',
    'login_welcome_text' => 'Welcome to Northside College',
]);
$check(
    institution_branding_validate_settings($validCustomColors) === array_replace(
        $validCustomColors,
        [
            'brand_primary_color' => '#114477',
            'brand_secondary_color' => '#003366',
            'brand_accent_color' => '#AA7733',
            'brand_sidebar_background' => '#FFFFFF',
            'brand_sidebar_text' => '#333333',
            'brand_header_background' => '#102030',
            'brand_header_text' => '#FFFFFF',
            'brand_page_background' => '#F6F8FC',
            'brand_surface_background' => '#FFFFFF',
            'brand_button_background' => '#004477',
            'brand_button_text' => '#FFFFFF',
            'login_background_color' => '#F3F7FD',
            'login_primary_color' => '#004477',
        ]
    ),
    'Accept legible institution-specific HEX branding and welcome text'
);

$service = file_get_contents(__DIR__ . '/../services/institution-branding-service.php');
$page = file_get_contents(__DIR__ . '/../super-admin/institutions.php');
$layout = file_get_contents(__DIR__ . '/../includes/layouts/page.php');
$head = file_get_contents(__DIR__ . '/../includes/layouts/head.php');
$header = file_get_contents(__DIR__ . '/../includes/layouts/header.php');
$authLayout = file_get_contents(__DIR__ . '/../includes/layouts/auth.php');
$profile = file_get_contents(__DIR__ . '/../admin/settings/institution.php');
$publicLogo = file_get_contents(__DIR__ . '/../public/institution-logo.php');
$superAdminLogo = file_get_contents(__DIR__ . '/../super-admin/institution-logo.php');
$previewScript = file_get_contents(__DIR__ . '/../public/assets/js/institution-branding.js');
$schema = file_get_contents(__DIR__ . '/../database/schema.sql');
$check(
    is_string($service)
        && str_contains($service, "institution_id = ? AND setting_key IN")
        && str_contains($service, 'ON DUPLICATE KEY UPDATE')
        && str_contains($service, "'institution.branding_updated'")
        && str_contains($service, "authenticated_institution_id(['admin', 'staff', 'parent'])")
        && str_contains($service, "WHERE code = ? AND status = 'active'"),
    'Store settings per institution, audit writes, and resolve login branding by code'
);
$check(
    is_string($page)
        && str_contains($page, 'institution_branding_save($db, $institutionId, $_POST)')
        && str_contains($page, 'institution_branding_store_logo($_FILES[\'institution_logo\'])')
        && str_contains($page, 'data-branding-preview')
        && str_contains($page, 'data-reset-branding')
        && str_contains($page, '$invalidInstitutionId')
        && str_contains($page, "require_user_types(['super_admin'])")
        && str_contains($page, 'student_count')
        && str_contains($page, 'staff_count'),
    'Provide authorized institution details, secure logo controls, live preview, and real counts'
);
$check(
    is_string($layout)
        && str_contains($layout, 'institution_branding_get_for_current_user()')
        && is_string($head)
        && str_contains($head, 'institutionBranding')
        && is_string($header)
        && str_contains($header, 'institution_branding_logo_version')
        && is_string($authLayout)
        && str_contains($authLayout, 'institution_code')
        && is_string($profile)
        && str_contains($profile, '$logoPreviewVersion'),
    'Resolve portal themes from the authenticated institution and login themes from institution code'
);
$check(
    is_string($previewScript)
        && str_contains($previewScript, "style.setProperty")
        && str_contains($previewScript, "data-reset-branding")
        && str_contains($previewScript, "beforeunload")
        && str_contains($previewScript, 'valuesChanged'),
    'Update preview locally, restore defaults, and warn before navigating away from unsaved changes'
);
$check(
    is_string($publicLogo)
        && is_string($superAdminLogo)
        && str_contains($publicLogo, 'institution_branding_get_for_login_code')
        && str_contains($superAdminLogo, 'institution_onboarding_assert_super_admin')
        && is_string($schema)
        && str_contains($schema, 'UNIQUE KEY uq_system_setting (institution_id, setting_key)'),
    'Serve validated logos through scoped endpoints using the existing institution settings schema'
);

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'Passed ' . $checks . ' institution branding checks.' . PHP_EOL);
