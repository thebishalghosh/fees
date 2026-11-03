<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$checks = 0;
$failures = [];
$check = static function (bool $condition, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $label;
    }
};

$_SESSION['user'] = [
    'id' => 81,
    'institution_id' => 1404,
    'user_type' => 'staff',
    'status' => 'active',
    'permissions' => ['dashboard.view'],
];
$check(
    authenticated_institution_id(['admin', 'staff']) === 1404,
    'Resolve the staff institution from the authenticated server-side user'
);
$check(
    authenticated_institution_id(['parent']) === null,
    'Reject institution scope for an incompatible user type'
);

$_SESSION['user']['institution_id'] = 0;
$check(
    authenticated_institution_id(['staff']) === null,
    'Fail closed when the staff session has no valid institution association'
);
unset($_SESSION['user']);

$auth = file_get_contents(__DIR__ . '/../includes/auth.php');
$otp = file_get_contents(__DIR__ . '/../services/otp-service.php');
$panel = file_get_contents(__DIR__ . '/../services/admin-panel-service.php');
$students = file_get_contents(__DIR__ . '/../services/student-management-service.php');
$parents = file_get_contents(__DIR__ . '/../services/parent-management-service.php');
$attendance = file_get_contents(__DIR__ . '/../services/attendance-management-service.php');
$examinations = file_get_contents(__DIR__ . '/../services/examination-results-service.php');
$branding = file_get_contents(__DIR__ . '/../services/institution-branding-service.php');
$pageLayout = file_get_contents(__DIR__ . '/../includes/layouts/page.php');
$headerLayout = file_get_contents(__DIR__ . '/../includes/layouts/header.php');
$headLayout = file_get_contents(__DIR__ . '/../includes/layouts/head.php');
$dashboard = file_get_contents(__DIR__ . '/../admin/dashboard.php');
$dashboardCss = file_get_contents(__DIR__ . '/../public/assets/css/pages/admin-dashboard.css');

$check(
    is_string($auth)
        && str_contains($auth, 'us.institution_id = ?')
        && str_contains($auth, 'u.id = us.user_id AND u.institution_id = us.institution_id')
        && str_contains($auth, "'institution_id' => $institutionId")
        && is_string($otp)
        && str_contains($otp, 'u.id = ? AND u.institution_id = ?')
        && str_contains($otp, "'institution_id' => $institutionId"),
    'Bind the authenticated staff session to the user row institution during OTP verification and revalidation'
);
$check(
    is_string($panel)
        && str_contains($panel, 'WHERE institution_id = ?')
        && str_contains($panel, 'WHERE pay.institution_id = ?')
        && is_string($students)
        && str_contains($students, 's.institution_id = ?')
        && is_string($parents)
        && str_contains($parents, 'p.institution_id = ?')
        && is_string($attendance)
        && str_contains($attendance, "ar.institution_id = ?")
        && is_string($examinations)
        && str_contains($examinations, 'e.institution_id = ?'),
    'Keep dashboard, student, parent, attendance, and examination queries tenant-scoped'
);
$check(
    is_string($branding)
        && str_contains($branding, "authenticated_institution_id(['admin', 'staff', 'parent'])")
        && str_contains($pageLayout, 'institution_branding_get_for_current_user()')
        && str_contains($headerLayout, 'institution_branding_get_for_current_user()'),
    'Resolve shared Staff layout identity and branding from the authenticated institution'
);
$themePosition = is_string($headLayout) ? strrpos($headLayout, '<style>:root{') : false;
$pageStylePosition = is_string($headLayout) ? strrpos($headLayout, 'basename((string) $pageStyle)') : false;
$check(
    is_string($headLayout)
        && str_contains($headLayout, "'--brand-primary' =>")
        && str_contains($headLayout, "'--brand-secondary' =>")
        && str_contains($headLayout, "'--brand-accent' =>")
        && str_contains($headLayout, "'--brand-page-background' =>")
        && str_contains($headLayout, "'--brand-surface-background' =>")
        && is_int($themePosition)
        && is_int($pageStylePosition)
        && $themePosition > $pageStylePosition
        && is_string($dashboard)
        && str_contains($dashboard, 'e($staffName)')
        && is_string($dashboardCss)
        && str_contains($dashboardCss, 'var(--brand-primary)')
        && str_contains($dashboardCss, 'var(--brand-secondary)'),
    'Apply validated institution theme tokens after page styles and greet the authenticated staff member'
);

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'Passed ' . $checks . ' staff institution-context checks.' . PHP_EOL);
