<?php
declare(strict_types=1);

putenv('OTP_HASH_KEY=local-test-key-with-more-than-thirty-two-characters');

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../services/otp-service.php';
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
$check(user_can_access_institution(9), 'Allow the session institution');
$check(!user_can_access_institution(10), 'Deny a different institution');
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

if ($failures !== []) {
    fwrite(STDERR, "FAIL: " . implode('; ', $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'PASS: ' . $checks . ' authentication helper checks.' . PHP_EOL);
