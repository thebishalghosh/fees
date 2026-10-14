<?php
declare(strict_types=1);

putenv('OTP_HASH_KEY=local-test-key-with-more-than-thirty-two-characters');

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layouts/auth.php';
require_once __DIR__ . '/../services/otp-service.php';

$checks = 0;
$failures = [];
$check = static function (bool $condition, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $label;
    }
};

$check(validate_email('parent@example.test'), 'Accept a valid parent email address');
$check(!validate_email('not-an-email'), 'Reject malformed parent email');
$check(!validate_max_length(str_repeat('a', 250) . '@test.com', 255), 'Reject an overlong parent email');

$account = [
    'id' => 41,
    'institution_id' => 7,
    'role_id' => 4,
    'user_type' => 'parent',
    'email' => 'parent@example.test',
];
$check(unique_parent_login_account([]) === null, 'Do not resolve an email with no eligible account');
$check(unique_parent_login_account([$account, $account]) === null, 'Do not resolve duplicate eligible accounts');
$check(unique_parent_login_account([$account]) === $account, 'Resolve one unambiguous eligible parent account');

$check(login_otp_is_usable('pending', 0, 5, true), 'Allow an unexpired parent OTP');
$check(!login_otp_is_usable('pending', 0, 5, false), 'Reject an expired parent OTP');
$check(!login_otp_is_usable('verified', 0, 5, true), 'Reject a previously used parent OTP');
$check(next_login_otp_attempt(4, 5) === ['attempts' => 5, 'status' => 'failed'], 'Enforce parent OTP attempt limit');
$check(login_otp_resend_cooldown_active(30, 60), 'Enforce resend cooldown before the interval expires');
$check(!login_otp_resend_cooldown_active(60, 60), 'Allow resend after cooldown expires');

$diagnosticLog = tempnam(sys_get_temp_dir(), 'fees-parent-otp-diagnostic-');
if ($diagnosticLog === false) {
    $failures[] = 'Create temporary parent OTP diagnostic log';
} else {
    $previousErrorLog = ini_get('error_log');
    ini_set('error_log', $diagnosticLog);
    $accountCorrelationId = create_auth_request_id();
    log_parent_otp_diagnostic($accountCorrelationId, 'account_ineligible');
    $deliveryCorrelationId = create_auth_request_id();
    deliver_login_otp(
        'private-parent@example.test',
        '593104',
        static fn(): bool => true,
        $deliveryCorrelationId
    );
    $failedDeliveryCorrelationId = create_auth_request_id();
    $failedDeliveryInvalidatedWithCorrelation = false;
    try {
        deliver_otp_with_failure_cleanup(
            static fn(): bool => deliver_login_otp(
                'private-parent@example.test',
                '593104',
                static fn(): bool => false,
                $failedDeliveryCorrelationId
            ),
            static function () use (&$failedDeliveryInvalidatedWithCorrelation): void {
                $failedDeliveryInvalidatedWithCorrelation = true;
            },
            $failedDeliveryCorrelationId
        );
        $check(false, 'Reject unconfirmed Parent email delivery with diagnostic correlation');
    } catch (OtpEmailDeliveryException $exception) {
        $check($failedDeliveryInvalidatedWithCorrelation, 'Invalidate the Parent challenge after correlated delivery failure');
    }
    ini_set('error_log', is_string($previousErrorLog) ? $previousErrorLog : '');
    $diagnosticOutput = file_get_contents($diagnosticLog);
    $check(
        is_string($diagnosticOutput)
            && str_contains($diagnosticOutput, $accountCorrelationId)
            && str_contains($diagnosticOutput, '"outcome":"account_ineligible"'),
        'Correlate the generic Parent eligibility failure without account data'
    );
    $check(
        is_string($diagnosticOutput)
            && str_contains($diagnosticOutput, $deliveryCorrelationId)
            && str_contains($diagnosticOutput, '"outcome":"delivery_accepted"'),
        'Record delivery accepted only after the mail transport confirms success'
    );
    $check(
        is_string($diagnosticOutput)
            && str_contains($diagnosticOutput, $failedDeliveryCorrelationId)
            && str_contains($diagnosticOutput, '"outcome":"delivery_failed"'),
        'Correlate a rejected delivery outcome'
    );
    $mailConfigurationStatus = mailpit_runtime_configuration_status();
    $check(
        array_key_exists('mail_config_match', $mailConfigurationStatus)
            && is_bool($mailConfigurationStatus['mail_config_match'])
            && array_key_exists('smtp_host_expected', $mailConfigurationStatus)
            && array_key_exists('smtp_port_expected', $mailConfigurationStatus),
        'Expose only boolean checks for Apache-loaded development SMTP configuration'
    );
    $check(
        is_string($diagnosticOutput)
            && !str_contains($diagnosticOutput, 'private-parent@example.test')
            && !str_contains($diagnosticOutput, '593104'),
        'Do not include email addresses or OTPs in correlated diagnostic events'
    );
    if (!unlink($diagnosticLog)) {
        $failures[] = 'Remove temporary parent OTP diagnostic log';
    }
}

$failedDeliveryInvalidated = false;
try {
    deliver_otp_with_failure_cleanup(
        static fn(): bool => deliver_login_otp(
            'parent@example.test',
            '593104',
            static fn(): bool => false
        ),
        static function () use (&$failedDeliveryInvalidated): void {
            $failedDeliveryInvalidated = true;
        }
    );
    $check(false, 'Reject failed parent email delivery');
} catch (OtpEmailDeliveryException $exception) {
    $check($failedDeliveryInvalidated, 'Invalidate a parent challenge after delivery failure');
}

$csrfToken = csrf_token();
$check(verify_csrf_token($csrfToken), 'Accept the current CSRF token');
$check(!verify_csrf_token(str_repeat('0', 64)), 'Reject an invalid CSRF token');

$_SESSION['pending_auth'] = ['unrelated' => 'old'];
store_pending_parent_authentication($account, 'request-parent-test', true);
$pending = $_SESSION['pending_auth'];
$check(($pending['login_type'] ?? null) === 'parent_email', 'Mark parent email challenges in server-side pending state');
$check(($pending['user_id'] ?? null) === 41, 'Keep resolved account identity server-side');
$check(($pending['institution_id'] ?? null) === 7, 'Keep resolved institution scope server-side');
$check(($pending['email'] ?? null) === 'parent@example.test', 'Keep the registered lookup email server-side for resend');
$check(($pending['masked_email'] ?? null) === 'p*****@example.test', 'Store only the masked email for display');
$check(($pending['challenge_ready'] ?? false) === true, 'Mark a successfully delivered parent challenge as ready');
$check(!array_key_exists('otp', $pending) && !array_key_exists('code', $pending), 'Do not store the plaintext OTP in the session');

ob_start();
render_auth_page_start('Parent login test', 'Render parent login surface.');
render_auth_page_end();
ob_end_clean();

$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST = [];
ob_start();
require __DIR__ . '/../auth/parent-login.php';
$parentLoginHtml = (string) ob_get_clean();
$check(str_contains($parentLoginHtml, 'name="email"'), 'Render email input on parent login');
$check(!str_contains($parentLoginHtml, 'name="mobile"'), 'Do not ask parents for a mobile number');
$check(!str_contains($parentLoginHtml, 'name="institution_code"'), 'Do not ask parents for an institution code');
$check(str_contains($parentLoginHtml, 'name="csrf_token"'), 'Protect parent login with CSRF');
$check(str_contains($parentLoginHtml, '/login/staff'), 'Provide a link back to clean shared staff login');

$_SESSION['pending_auth'] = [
    'login_type' => 'parent_email',
    'user_id' => null,
    'institution_id' => null,
    'request_id' => 'unresolved-parent-account',
    'email' => 'parent@example.test',
    'masked_email' => 'p*****@example.test',
    'challenge_ready' => true,
    'created_at' => time(),
];
ob_start();
require __DIR__ . '/../auth/verify-otp.php';
$parentVerificationHtml = (string) ob_get_clean();
$check(str_contains($parentVerificationHtml, 'Verify your email address'), 'Render parent email verification heading');
$check(str_contains($parentVerificationHtml, 'name="otp"'), 'Render parent OTP entry field');
$check(str_contains($parentVerificationHtml, '/resend-otp'), 'Render clean resend action');
$check(str_contains($parentVerificationHtml, '/login/parent'), 'Link parent verification to clean email-change restart');
$check(!preg_match('/\b[0-9]{6}\b/', strip_tags($parentVerificationHtml)), 'Do not render OTP in parent verification response');

unset($_SESSION['user'], $_SESSION['auth_session']);
ob_start();
render_site_header('public');
$publicHeaderHtml = (string) ob_get_clean();
$check(str_contains($publicHeaderHtml, '>Sign in</a>'), 'Show sign-in on the public header when logged out');
$check(str_contains($publicHeaderHtml, 'data-bs-toggle="modal"'), 'Make landing-page sign-in open a Bootstrap modal');
$check(str_contains($publicHeaderHtml, 'data-bs-target="#login-modal"'), 'Target the shared sign-in modal');
$check(!str_contains($publicHeaderHtml, 'Request a demo'), 'Remove the demo CTA from the public header');

$_SESSION['user'] = [
    'id' => 41,
    'institution_id' => 7,
    'user_type' => 'parent',
    'status' => 'active',
    'permissions' => [],
];
$_SESSION['auth_session'] = [
    'identifier' => str_repeat('a', 32),
    'token_hash' => str_repeat('b', 64),
];
ob_start();
render_site_header('public');
$authenticatedParentHeaderHtml = (string) ob_get_clean();
$check(str_contains($authenticatedParentHeaderHtml, 'href="' . e(route_url('/parent/dashboard')) . '">Go to Dashboard</a>'), 'Route authenticated parents to the parent dashboard');
$check(!str_contains($authenticatedParentHeaderHtml, '>Sign in</a>'), 'Hide sign-in in the public header for authenticated users');
$check(!str_contains($authenticatedParentHeaderHtml, 'Request a demo'), 'Keep the demo CTA removed for authenticated users');

$_SESSION['user']['user_type'] = 'admin';
ob_start();
render_site_header('public');
$authenticatedAdminHeaderHtml = (string) ob_get_clean();
$check(str_contains($authenticatedAdminHeaderHtml, 'href="' . e(route_url('/admin/dashboard')) . '">Go to Dashboard</a>'), 'Route authenticated administrators to the admin dashboard');

$_SESSION['user']['user_type'] = 'staff';
ob_start();
render_site_header('public');
$authenticatedStaffHeaderHtml = (string) ob_get_clean();
$check(str_contains($authenticatedStaffHeaderHtml, 'href="' . e(route_url('/admin/dashboard')) . '">Go to Dashboard</a>'), 'Route authenticated staff to the admin dashboard');
unset($_SESSION['user'], $_SESSION['auth_session']);

$loginModalState = [];
$_GET['login'] = '1';
ob_start();
require __DIR__ . '/../includes/components/login-modal.php';
$loginModalHtml = (string) ob_get_clean();
unset($_GET['login']);
$check(str_contains($loginModalHtml, 'id="login-modal"'), 'Render accessible login modal');
$check(str_contains($loginModalHtml, 'role="tablist"'), 'Render keyboard-operable account type tabs');
$check(str_contains($loginModalHtml, 'id="parent-login-panel"'), 'Render parent email form in modal');
$check(str_contains($loginModalHtml, 'id="staff-login-panel"'), 'Render staff/admin form in modal');
$check(str_contains($loginModalHtml, 'data-bs-dismiss="modal"'), 'Render modal close button');
$check(str_contains($loginModalHtml, "Modal.getOrCreateInstance(modalElement).show()"), 'Reopen modal after validation redirect');
$check(str_contains($loginModalHtml, '/login/parent'), 'Post parent modal form to the clean parent authentication route');
$check(str_contains($loginModalHtml, '/login/staff'), 'Post staff modal form to the clean staff authentication route');
$check(str_contains($loginModalHtml, 'verified email address'), 'Describe staff/admin email sign-in in the modal');

ob_start();
require __DIR__ . '/../auth/login.php';
$sharedLoginHtml = (string) ob_get_clean();
$check(str_contains($sharedLoginHtml, 'name="email"'), 'Render the staff/admin email login field');
$check(!str_contains($sharedLoginHtml, 'name="mobile"'), 'Remove the mobile number requirement from staff/admin login');
$check(str_contains($sharedLoginHtml, 'name="institution_code"'), 'Preserve the shared institution field');
$check(str_contains($sharedLoginHtml, '/login/parent'), 'Link shared login to parent email login');
$check(!preg_match('/\b[0-9]{6}\b/', strip_tags($parentLoginHtml . $sharedLoginHtml)), 'Do not render OTPs in login responses');

$beforeSessionId = session_id();
regenerate_session_id();
$check($beforeSessionId !== '' && session_id() !== '' && $beforeSessionId !== session_id(), 'Regenerate session ID at authentication boundary');
$check(isset($_SESSION['csrf_token']), 'Rotate CSRF state with session ID');
clear_app_session();
$check(!is_authenticated(), 'Clear local authentication state on logout cleanup');
start_app_session();
ob_start();
render_site_header('public');
$loggedOutHeaderHtml = (string) ob_get_clean();
$check(str_contains($loggedOutHeaderHtml, '>Sign in</a>'), 'Restore sign-in on the public header after logout');
$check(!str_contains($loggedOutHeaderHtml, 'Go to Dashboard'), 'Hide the dashboard action on the public header after logout');

if ($failures !== []) {
    fwrite(STDERR, 'FAIL: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'PASS: ' . $checks . ' parent email authentication checks.' . PHP_EOL);
