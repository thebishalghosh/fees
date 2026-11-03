<?php
declare(strict_types=1);

$profileRoute = file_get_contents(__DIR__ . '/../admin/settings/institution.php');
$sidebar = file_get_contents(__DIR__ . '/../includes/layouts/sidebar.php');
$superAdminRoute = file_get_contents(__DIR__ . '/../super-admin/institutions.php');
$superAdminPage = file_get_contents(__DIR__ . '/../admin/settings/general.php');
$adminService = file_get_contents(__DIR__ . '/../services/admin-panel-service.php');

$checks = [
    [
        is_string($profileRoute)
        && str_contains($profileRoute, "require_user_types(['admin', 'staff'])")
        && strpos($profileRoute, "require_user_types(['admin', 'staff'])")
            < strpos($profileRoute, 'require_placeholder_route_access()')
        && str_contains($profileRoute, 'admin_panel_institution_id()'),
        'Exclude Super Admin at the profile route and retain session-based institution resolution',
    ],
    [
        is_string($sidebar)
        && str_contains($sidebar, "\$href === '/admin/settings/institution'")
        && str_contains($sidebar, "current_user()['user_type'] ?? null) === 'super_admin'"),
        'Hide the Institution Admin profile link from Super Admin navigation',
    ],
    [
        is_string($superAdminPage)
        && str_contains($superAdminPage, "if ((current_user()['user_type'] ?? null) === 'admin'):"),
        'Do not show the Admin profile link in the preferences page to Super Admin',
    ],
    [
        is_string($superAdminRoute)
        && str_contains($superAdminRoute, "require_user_types(['super_admin'])")
        && str_contains($superAdminRoute, "institution_onboarding_update(\$db, \$institutionId, \$_POST)"),
        'Preserve the dedicated Super Admin institution edit route',
    ],
    [
        is_string($adminService)
        && str_contains($adminService, 'function admin_panel_institution_id(): int')
        && str_contains($adminService, "authenticated_institution_id(['super_admin', 'admin', 'staff'])"),
        'Keep profile selection tied to the authenticated user institution, not request parameters',
    ],
];

foreach ($checks as [$passed, $message]) {
    if (!$passed) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

fwrite(STDOUT, 'Passed ' . count($checks) . ' Institution Profile access checks.' . PHP_EOL);
