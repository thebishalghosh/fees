<?php
declare(strict_types=1);

putenv('OTP_HASH_KEY=local-test-key-with-more-than-thirty-two-characters');

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/components/login-form.php';
require_once __DIR__ . '/../services/otp-service.php';

$checks = 0;
$failures = [];
$check = static function (bool $condition, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $label;
    }
};

$baseAccount = [
    'id' => '71',
    'institution_id' => '12',
    'role_id' => '2',
    'user_type' => 'admin',
    'email' => 'test@test.com',
    'email_verified_at' => '2026-01-01 00:00:00',
    'status' => 'active',
    'role_name' => 'admin',
    'institution_code' => '1234',
    'institution_status' => 'active',
];
$check(
    unique_staff_login_account([$baseAccount], '1234', 'test@test.com') === $baseAccount,
    'Accept an active, verified institution administrator'
);
$lowercaseInstitutionCode = $baseAccount;
$lowercaseInstitutionCode['institution_code'] = 'test1234';
$check(
    unique_staff_login_account([$lowercaseInstitutionCode], 'TEST1234', 'test@test.com') === $lowercaseInstitutionCode
        && unique_staff_login_account([$lowercaseInstitutionCode], 'test1234', 'test@test.com') === $lowercaseInstitutionCode,
    'Accept matching institution codes regardless of letter case'
);
$check(
    unique_staff_login_account([$lowercaseInstitutionCode], 'TEST1235', 'test@test.com') === null,
    'Reject a genuinely different institution code'
);
$check(
    unique_staff_login_account([$baseAccount], 'wrong', 'test@test.com') === null,
    'Reject a mismatched institution code'
);
$check(
    unique_staff_login_account([$baseAccount], '1234', 'other@test.com') === null,
    'Reject a mismatched registered email'
);
$inactiveInstitution = $baseAccount;
$inactiveInstitution['institution_status'] = 'inactive';
$check(unique_staff_login_account([$inactiveInstitution], '1234', 'test@test.com') === null, 'Reject inactive institutions');
$inactiveUser = $baseAccount;
$inactiveUser['status'] = 'inactive';
$check(unique_staff_login_account([$inactiveUser], '1234', 'test@test.com') === null, 'Reject inactive admin accounts');
$unverifiedUser = $baseAccount;
$unverifiedUser['email_verified_at'] = null;
$check(unique_staff_login_account([$unverifiedUser], '1234', 'test@test.com') === null, 'Reject unverified admin email');
$wrongRole = $baseAccount;
$wrongRole['user_type'] = 'parent';
$wrongRole['role_name'] = 'parent';
$check(unique_staff_login_account([$wrongRole], '1234', 'test@test.com') === null, 'Reject parent role through staff login');
$mismatchedRole = $baseAccount;
$mismatchedRole['role_name'] = 'staff';
$check(unique_staff_login_account([$mismatchedRole], '1234', 'test@test.com') === null, 'Reject role mapping mismatch');
$check(unique_staff_login_account([$baseAccount, $baseAccount], '1234', 'test@test.com') === null, 'Reject ambiguous staff account resolution');

$invalidInstitution = begin_staff_email_challenge('../1234', 'test@test.com');
$invalidEmail = begin_staff_email_challenge('1234', 'invalid-email');
$check(($invalidInstitution['reason'] ?? null) === 'invalid_input', 'Reject invalid institution code before database access');
$check(($invalidEmail['reason'] ?? null) === 'invalid_input', 'Reject invalid email before database access');

ob_start();
render_login_form('staff', route_url('/login/staff'), 'staff-test');
$staffFormHtml = (string) ob_get_clean();
$check(str_contains($staffFormHtml, 'name="institution_code"'), 'Render institution code for staff sign-in');
$check(str_contains($staffFormHtml, 'name="email"'), 'Render registered email for staff sign-in');
$check(!str_contains($staffFormHtml, 'name="mobile"'), 'Do not request a mobile number in the staff form');
$check(str_contains($staffFormHtml, 'type="email"'), 'Use an email input for staff sign-in');
$check(str_contains($staffFormHtml, 'name="csrf_token"'), 'Keep CSRF protection on the staff sign-in form');

$staffHandler = file_get_contents(__DIR__ . '/../auth/login.php');
$parentHandler = file_get_contents(__DIR__ . '/../auth/parent-login.php');
$check(is_string($staffHandler) && str_contains($staffHandler, 'begin_staff_email_challenge('), 'Dispatch staff form requests to the staff challenge service');
$check(is_string($staffHandler) && !str_contains($staffHandler, 'begin_parent_email_challenge('), 'Never invoke parent challenge creation from staff login');
$check(is_string($staffHandler) && str_contains($staffHandler, "'staff_auth'"), 'Scope staff failures to staff sign-in');
$check(is_string($parentHandler) && str_contains($parentHandler, 'begin_parent_email_challenge('), 'Keep parent login on its dedicated challenge service');
$check(is_string($parentHandler) && !str_contains($parentHandler, 'begin_staff_email_challenge('), 'Never invoke staff challenge creation from parent login');
$check(is_string($parentHandler) && str_contains($parentHandler, "'parent_auth'"), 'Scope parent failures to parent sign-in');

$legacyParentMessage = 'If the address matches one eligible parent account, a code may be sent to its registered email address.';
$_SESSION['flash'] = [
    ['type' => 'info', 'message' => $legacyParentMessage],
    [
        'type' => 'danger',
        'message' => 'Unable to send a sign-in code right now. Please try again later.',
        'scope' => 'staff_auth',
    ],
];
$loginModalState = [];
$_GET = ['login' => '1', 'account' => 'staff'];
ob_start();
require __DIR__ . '/../includes/components/login-modal.php';
$staffModalHtml = (string) ob_get_clean();
$check(str_contains($staffModalHtml, 'Staff / Admin</button>') && str_contains($staffModalHtml, 'aria-selected="true"'), 'Select the staff tab for a staff login response');
$check(!str_contains($staffModalHtml, $legacyParentMessage), 'Do not show a stale legacy parent message in the staff flow');
$check(str_contains($staffModalHtml, 'Unable to send a sign-in code right now.'), 'Show the current staff-specific failure');
$check(count($_SESSION['flash'] ?? []) === 1, 'Keep the parent message queued for the parent flow');

$_GET = ['login' => '1', 'account' => 'parent'];
ob_start();
require __DIR__ . '/../includes/components/login-modal.php';
$parentModalHtml = (string) ob_get_clean();
$check(str_contains($parentModalHtml, $legacyParentMessage), 'Keep the parent-specific message available in the parent flow');
$check(!str_contains($parentModalHtml, 'Unable to send a sign-in code right now.'), 'Do not show a staff failure in the parent flow');
$_SESSION['flash'] = [['type' => 'info', 'message' => $legacyParentMessage, 'scope' => 'parent_auth']];
$_SESSION['pending_auth'] = ['login_type' => 'staff_email', 'challenge_ready' => true];
$_GET = ['verify' => '1'];
ob_start();
require __DIR__ . '/../includes/components/login-modal.php';
$loginDuringOtpHtml = (string) ob_get_clean();
$check(!str_contains($loginDuringOtpHtml, $legacyParentMessage), 'Do not show a stale parent message over staff OTP verification');
$check(!str_contains($loginDuringOtpHtml, "const shouldOpen = true"), 'Do not reopen the login modal during OTP verification');
$check(isset($_SESSION['flash'][0]), 'Preserve the unrelated parent flash during OTP verification');
unset($_GET['verify'], $_SESSION['flash'], $_SESSION['pending_auth']);

$seedPath = __DIR__ . '/../database/seed-development.sql';
$seed = file_get_contents($seedPath);
$check(is_string($seed), 'Read development seed for static safety assertions');
if (is_string($seed)) {
    $check(str_contains($seed, "'1234'") && str_contains($seed, "'test@test.com'"), 'Seed the requested institution code and email');
    $check(str_contains($seed, "r.name = 'admin'") && str_contains($seed, "'admin'"), 'Seed the institution administrator role');
    $check(str_contains($seed, "i.name = 'FeesPortal Development Institution'") && str_contains($seed, "i.status = 'active'"), 'Avoid attaching the development account to a conflicting institution');
    $check(str_contains($seed, "'active'") && str_contains($seed, 'CURRENT_TIMESTAMP'), 'Seed active account with a verified email timestamp');
    $check(substr_count($seed, 'NOT EXISTS') >= 2, 'Make institution and account inserts repeatable without updates');
    $check(!str_contains($seed, 'ON DUPLICATE KEY UPDATE'), 'Do not overwrite existing development records');
    $check(str_contains($seed, '@feesportal_development_seed_allowed = 1'), 'Guard seed data to development/test database names');
    $check(!preg_match('/\b[0-9]{6}\b/', $seed), 'Do not include a fixed OTP');
    $check(!str_contains(strtolower($seed), 'password_hash') && !str_contains(strtolower($seed), 'password='), 'Do not seed a password or plaintext credential');
}

$pending = [];
store_pending_authentication($baseAccount, 'staff-otp-request', 'test@test.com', '1234', true);
$pending = $_SESSION['pending_auth'];
$check(($pending['login_type'] ?? null) === 'staff_email', 'Use a separate staff email challenge type');
$check(($pending['email'] ?? null) === 'test@test.com', 'Store registered email server-side for resend and verification');
$check(($pending['masked_email'] ?? null) === 't***@test.com', 'Store only a masked email for display');
$check(!isset($pending['mobile']), 'Do not retain a mobile number in the staff challenge');
$check(!isset($pending['otp']), 'Do not store a plaintext OTP in the staff session');
$_SESSION['user'] = [
    'id' => 71,
    'institution_id' => 12,
    'role_id' => 2,
    'user_type' => 'admin',
    'status' => 'active',
    'permissions' => ['dashboard.view'],
];
$check(authenticated_dashboard_route() === '/admin/dashboard', 'Use the role-authorized admin dashboard route');

unset($_SESSION['pending_auth'], $_SESSION['user']);
if ($failures !== []) {
    fwrite(STDERR, 'FAIL: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'PASS: ' . $checks . ' staff email authentication checks.' . PHP_EOL);
