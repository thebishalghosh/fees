<?php
declare(strict_types=1);

putenv('OTP_HASH_KEY=local-test-key-with-more-than-thirty-two-characters');

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../services/otp-service.php';
require_once __DIR__ . '/../services/role-management-service.php';
require_once __DIR__ . '/../includes/layouts/auth.php';
require_once __DIR__ . '/../includes/layouts/page.php';

$checks = 0;
$failures = [];

$check = static function (bool $condition, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $label;
    }
};

$check(normalize_mobile_number('+919876543210') === '+919876543210', 'Accept international mobile format');
$check(normalize_mobile_number('98765 43210') === '9876543210', 'Normalize common mobile separators');
$check(normalize_mobile_number('12345') === null, 'Reject short mobile number');
$check(normalize_mobile_number('++919876543210') === null, 'Reject malformed mobile number');
$check(validate_institution_code('BEI-01'), 'Accept institution code');
$check(!validate_institution_code('../outside'), 'Reject path-like institution code');

$otp = create_login_otp();
$otpHash = hash_login_otp($otp);
$check(preg_match('/^[0-9]{6}$/D', $otp) === 1, 'Generate a six-digit OTP');
$check($otpHash !== $otp, 'Store an HMAC instead of the plain OTP');
$check(login_otp_hash_matches($otp, $otpHash), 'Verify OTP HMAC');
$check(!login_otp_hash_matches('00000', $otpHash), 'Reject malformed OTP');
$check(!login_otp_hash_matches('000000', str_repeat('g', 64)), 'Reject malformed OTP hash');
$check(login_otp_is_usable('pending', 0, 5, true), 'Accept unused, unexpired OTP');
$check(!login_otp_is_usable('verified', 0, 5, true), 'Reject reused OTP');
$check(!login_otp_is_usable('pending', 0, 5, false), 'Reject expired OTP');
$check(!login_otp_is_usable('pending', 5, 5, true), 'Reject exhausted OTP attempts');
$attemptResult = next_login_otp_attempt(4, 5);
$check($attemptResult === ['attempts' => 5, 'status' => 'failed'], 'Fail the challenge at the retry limit');

$token = csrf_token();
$check(verify_csrf_token($token), 'Accept generated CSRF token');
$check(!verify_csrf_token(str_repeat('0', 64)), 'Reject incorrect CSRF token');
$check(e('<script>alert(1)</script>') === '&lt;script&gt;alert(1)&lt;/script&gt;', 'Escape HTML output');
$check(is_safe_redirect('/admin/dashboard.php'), 'Accept local application redirect');
$check(!is_safe_redirect('https://attacker.example/'), 'Reject external redirect');
$check(route_url('/admin/dashboard') === APP_URL . '/admin/dashboard', 'Generate clean routes under the configured base URL');
$check(!str_contains(route_url('/parent/dashboard'), '.php'), 'Do not append PHP extensions to application routes');
$routeWithAllowedCharacters = '/section-name/file.name_part~v1';
$check(
    route_url($routeWithAllowedCharacters) === APP_URL . $routeWithAllowedCharacters,
    'Accept route characters including hyphens, forward slashes, dots, underscores, and tildes'
);
foreach (['/path@name', '/path+name', '/path=name', '/path%2Fother'] as $invalidRoute) {
    try {
        route_url($invalidRoute);
        $check(false, 'Reject disallowed route characters: ' . $invalidRoute);
    } catch (InvalidArgumentException $exception) {
        $check(true, 'Reject disallowed route characters: ' . $invalidRoute);
    }
}
try {
    route_url('/../config/app');
    $check(false, 'Reject traversal in generated routes');
} catch (InvalidArgumentException $exception) {
    $check(true, 'Reject traversal in generated routes');
}

$_SESSION['user'] = [
    'id' => 17,
    'institution_id' => 9,
    'role_id' => 2,
    'user_type' => 'admin',
    'status' => 'active',
    'permissions' => ['dashboard.view'],
];
$_SESSION['auth_session'] = [
    'identifier' => str_repeat('a', 32),
    'token_hash' => str_repeat('b', 64),
];
$check(is_authenticated(), 'Recognize a well-formed server-side auth session');
$check(authenticated_dashboard_route() === '/admin/dashboard', 'Route institution administrators to the admin dashboard');
$check(user_has_permission('dashboard.view'), 'Allow a granted permission');
$check(!user_has_permission('payments.refund'), 'Deny an ungranted permission');
$check(
    user_has_compatible_permission('student_fees.create', 'fees.manage') === false,
    'Deny an ungranted permission when its legacy system permission is absent'
);
$_SESSION['user']['permissions'][] = 'fees.manage';
$check(
    user_has_compatible_permission('student_fees.create', 'fees.manage'),
    'Allow a system-role legacy permission fallback'
);
$_SESSION['user']['role_is_system'] = false;
$check(
    !user_has_compatible_permission('student_fees.create', 'fees.manage'),
    'Do not apply a legacy permission fallback to custom roles'
);
$_SESSION['user']['role_is_system'] = true;
$_SESSION['user']['permissions'][] = 'student_fees.create';
$check(
    user_has_compatible_permission('student_fees.create', 'fees.manage'),
    'Allow the granted granular permission'
);
$_SESSION['user'] = [
    'id' => 28,
    'institution_id' => 9,
    'user_type' => 'super_admin',
    'status' => 'active',
    'permissions' => [],
];
$_SESSION['admin_selected_institution_id'] = 14;
$check(user_has_permission('payments.refund'), 'Give super admins full admin-module permissions');
$check(
    authenticated_institution_id(['super_admin', 'admin', 'staff']) === 14,
    'Scope a super admin to the institution selected in the workspace, not their home institution'
);
$check(
    user_can_access_institution(14) && !user_can_access_institution(9),
    'Limit super-admin institution access checks to the selected institution'
);
unset($_SESSION['admin_selected_institution_id']);
$check(
    authenticated_institution_id(['super_admin']) === null,
    'Require super admins to choose an institution instead of falling back to their home institution'
);
$_SESSION['user'] = [
    'id' => 17,
    'institution_id' => 9,
    'user_type' => 'admin',
    'status' => 'active',
    'permissions' => ['dashboard.view'],
];
$check(user_can_access_institution(9), 'Allow the session institution');
$check(!user_can_access_institution(10), 'Deny an unselected institution');
$check(
    authenticated_role_is_valid('staff', 'Class Coordinator', false, 9, 9, true),
    'Allow institution-scoped custom roles for staff accounts'
);
$check(
    !authenticated_role_is_valid('staff', 'Class Coordinator', false, 10, 9, true),
    'Reject a custom role owned by another institution'
);
$check(
    !authenticated_role_is_valid('admin', 'Class Coordinator', false, 9, 9, true),
    'Do not allow a custom role to replace administrator roles'
);
$check(
    !authenticated_role_is_valid('super_admin', 'super_admin', true, null, 9, false),
    'Reject an inactive protected role'
);
$permissionKeys = rbac_permission_keys();
$check(count($permissionKeys) === count(array_unique($permissionKeys)), 'Keep the permission matrix keys unique');
$check(in_array('payments.refund', $permissionKeys, true), 'Include payment refund permissions in the catalog');
$check(in_array('roles.permissions.manage', $permissionKeys, true), 'Include role-permission management in the catalog');
$check(rbac_role_name_is_valid('Attendance Coordinator'), 'Accept valid custom role names');
$check(!rbac_role_name_is_valid(' '), 'Reject empty custom role names');
$check(!rbac_role_name_is_valid(str_repeat('R', 81)), 'Enforce the database role name length');
$routePermission = admin_route_permissions('students/store.php', 'students', 'store');
$check($routePermission === ['students.create', 'students.manage'], 'Guard student creation with a granular permission');
$routePermission = admin_route_permissions('payments/refunds.php', 'payments', 'refunds');
$check($routePermission === ['payments.refund', 'payments.refund'], 'Keep refund access separate from payment viewing');
$authSource = file_get_contents(__DIR__ . '/../includes/auth.php');
$check(
    is_string($authSource)
        && str_contains($authSource, '$institutionId = (int) $user[\'institution_id\'];')
        && !str_contains($authSource, '$institutionId = authenticated_institution_id([\'super_admin\', \'admin\', \'staff\']);'),
    'Revalidate super-admin login sessions using the account institution before workspace institution selection'
);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = [];
$_POST = ['action' => 'create'];
$check(
    admin_route_permissions('academics/years.php', 'academics', 'years') === ['academic_years.create', 'academics.manage'],
    'Require academic-year creation permission for year creation'
);
$check(
    admin_route_permissions('fees/categories.php', 'fees', 'categories') === ['fee_structures.create', 'fees.manage'],
    'Require fee-structure creation permission for category creation'
);
$_POST = ['action' => 'status'];
$check(
    admin_route_permissions('academics/years.php', 'academics', 'years') === ['academic_years.edit', 'academics.manage'],
    'Require academic-year edit permission for lifecycle changes'
);
$check(
    admin_route_permissions('fees/categories.php', 'fees', 'categories') === ['fee_structures.edit', 'fees.manage'],
    'Require fee-structure edit permission for category status changes'
);
$_POST = ['action' => 'save', 'exam_id' => '0'];
$check(
    admin_route_permissions('academics/examinations.php', 'academics', 'examinations') === ['examinations.create', 'academics.manage'],
    'Require examination creation permission when scheduling an examination'
);
$_POST = ['action' => 'save', 'exam_id' => '12'];
$check(
    admin_route_permissions('academics/examinations.php', 'academics', 'examinations') === ['examinations.edit', 'academics.manage'],
    'Require examination edit permission when updating an examination'
);
$_POST = ['action' => 'publication', 'exam_id' => '12', 'published' => '0'];
$check(
    admin_route_permissions('academics/examinations.php', 'academics', 'examinations') === ['results.publish', 'academics.manage'],
    'Require publication permission for both publishing and unpublishing results'
);
$_POST = ['action' => 'save_marks', 'exam_id' => '12'];
$check(
    admin_route_permissions('academics/results.php', 'academics', 'results') === ['results.view', 'academics.manage'],
    'Require result viewing access to reach the marks page'
);
$examinationServiceSource = file_get_contents(__DIR__ . '/../services/examination-results-service.php');
$check(
    is_string($examinationServiceSource)
        && str_contains($examinationServiceSource, "'results.create'")
        && str_contains($examinationServiceSource, "'results.edit'"),
    'Enforce separate create and edit permissions for examination marks'
);
$_POST = ['action' => 'assign_role', 'user_id' => '42', 'role_id' => '5'];
$check(
    admin_route_permissions('staff/index.php', 'staff', 'index') === ['staff.assign_role', 'staff.manage'],
    'Require staff role-assignment permission for role changes'
);
$_POST = ['action' => 'save_permissions', 'role_id' => '5', 'permissions' => []];
$check(
    admin_route_permissions('staff/permissions.php', 'staff', 'permissions') === ['roles.permissions.manage', 'staff.manage'],
    'Require permission-management authorization for permission matrix updates'
);
$_POST = ['action' => 'create', 'name' => 'Coordinator'];
$check(
    admin_route_permissions('staff/permissions.php', 'staff', 'permissions') === ['roles.create', 'staff.manage'],
    'Require role-creation authorization for custom role creation'
);
$_POST = ['action' => 'update', 'role_id' => '5', 'name' => 'Coordinator'];
$check(
    admin_route_permissions('staff/permissions.php', 'staff', 'permissions') === ['roles.edit', 'staff.manage'],
    'Require role-edit authorization for custom role updates'
);
$_POST = ['action' => 'delete', 'role_id' => '5'];
$check(
    admin_route_permissions('staff/permissions.php', 'staff', 'permissions') === ['roles.delete', 'staff.manage'],
    'Require role-deletion authorization for role deletion'
);
$_POST = ['name' => 'Annual fees'];
$check(
    admin_route_permissions('fees/create-structure.php', 'fees', 'create-structure') === ['fee_structures.create', 'fees.manage'],
    'Require fee-structure creation permission for the structure form'
);
$check(
    admin_route_permissions('fees/edit-structure.php', 'fees', 'edit-structure') === ['fee_structures.edit', 'fees.manage'],
    'Require fee-structure edit permission for the edit form'
);
$check(
    admin_route_permissions('fees/assignments.php', 'fees', 'assignments') === ['student_fees.create', 'fees.manage'],
    'Require student-fee creation permission for new assignments'
);
$check(
    admin_route_permissions('academics/create-class.php', 'academics', 'create-class') === ['classes.create', 'academics.manage'],
    'Require class creation permission on the class form route'
);
$_POST = ['announcement_id' => '9', 'status' => 'draft'];
$check(
    admin_route_permissions('notifications/index.php', 'notifications', 'index') === ['announcements.publish', 'notifications.manage'],
    'Require publication permission when unpublishing an announcement'
);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST = [];
$_SESSION['user']['user_type'] = 'staff';
$check(authenticated_dashboard_route() === '/admin/dashboard', 'Route staff to the shared authorized admin dashboard');
$_SESSION['user']['user_type'] = 'parent';
$check(authenticated_dashboard_route() === '/parent/dashboard', 'Route parents to the parent dashboard');
unset($_SESSION['user'], $_SESSION['auth_session']);

$rateScope = 'test:' . bin2hex(random_bytes(16));
$ratePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR . 'feesportal-auth-rate-limit'
    . DIRECTORY_SEPARATOR . hash('sha256', $rateScope) . '.json';
$check(consume_auth_rate_limit($rateScope, 1, 60), 'Allow the first request within a rate window');
$check(!consume_auth_rate_limit($rateScope, 1, 60), 'Block requests over the rate limit');
if (is_file($ratePath) && !unlink($ratePath)) {
    $failures[] = 'Remove test rate-limit state';
}

ob_start();
render_auth_page_start('Authentication test', 'Layout rendering check.');
render_auth_page_end();
$authHtml = (string) ob_get_clean();
$check(str_contains($authHtml, 'Authentication test'), 'Render the authentication layout');

ob_start();
render_app_page_start('Nested test', 'Nested route rendering check.', 'admin');
render_app_page_end('admin');
$nestedHtml = (string) ob_get_clean();
$check(str_contains($nestedHtml, APP_URL . '/public/assets/css/base.css'), 'Render nested application assets');
$check(str_contains($nestedHtml, '/logout'), 'Render the CSRF-protected clean sign-out route');
$check(str_contains($nestedHtml, '<body class="app-page app-page-admin">'), 'Scope the independent-scroll layout to admin pages');

ob_start();
render_app_page_start('Parent test', 'Parent layout rendering check.', 'parent');
render_app_page_end('parent');
$parentLayoutHtml = (string) ob_get_clean();
$check(
    str_contains($parentLayoutHtml, '<body class="app-page">')
        && !str_contains($parentLayoutHtml, 'app-page-admin'),
    'Keep parent pages outside the admin-only scrolling layout'
);

if ($failures !== []) {
    fwrite(STDERR, "FAIL: " . implode('; ', $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'PASS: ' . $checks . ' authentication helper checks.' . PHP_EOL);
