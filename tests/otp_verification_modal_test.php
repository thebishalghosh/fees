<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/otp-service.php';

$checks = 0;
$failures = [];
$check = static function (bool $condition, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $label;
    }
};

$check(mask_email_address('parent@example.test') === 'p*****@example.test', 'Mask the recipient local part');
$check(mask_email_address('x@example.test') === '*@example.test', 'Mask a one-character local part');
$check(mask_email_address('invalid-address') === 'your registered email address', 'Use a safe fallback for malformed email');

$_SESSION['pending_auth'] = [
    'login_type' => 'parent_email',
    'user_id' => 41,
    'institution_id' => 7,
    'request_id' => 'modal-challenge',
    'masked_email' => 'p*****@example.test',
    'challenge_ready' => true,
    'created_at' => time(),
];
$_GET['verify'] = '1';
ob_start();
require __DIR__ . '/../includes/components/otp-verification-modal.php';
$modalHtml = (string) ob_get_clean();
unset($_GET['verify']);

$check(str_contains($modalHtml, 'id="otp-verification-modal"'), 'Render the OTP verification modal');
$check(str_contains($modalHtml, 'p*****@example.test'), 'Show only the masked destination address');
$check(!str_contains($modalHtml, 'parent@example.test'), 'Do not render the full destination address');
$check(str_contains($modalHtml, 'name="otp"'), 'Post OTP verification to the server');
$check(str_contains($modalHtml, 'action="' . route_url('/verify-otp') . '"'), 'Use the existing clean verification endpoint');
$check(str_contains($modalHtml, 'action="' . route_url('/resend-otp') . '"'), 'Use the existing clean resend endpoint');
$check(str_contains($modalHtml, 'name="csrf_token"'), 'Keep CSRF tokens on OTP and resend forms');
$check(str_contains($modalHtml, 'disabled>Resend code'), 'Enforce resend cooldown in the initial modal state');
$check(str_contains($modalHtml, 'one-time-code'), 'Provide the browser OTP autofill hint');
$check(str_contains($modalHtml, 'getOrCreateInstance(modalElement).show()'), 'Open the modal for the verification route');
$check(!preg_match('/\b[0-9]{6}\b/', strip_tags($modalHtml)), 'Do not render a six-digit OTP');

$_SESSION['pending_auth']['login_type'] = 'staff_email';
$_SESSION['pending_auth']['masked_email'] = 's*****@example.test';
$_SESSION['otp_modal_message'] = ['type' => 'danger', 'message' => 'That code is invalid or expired.'];
ob_start();
require __DIR__ . '/../includes/components/otp-verification-modal.php';
$staffModalHtml = (string) ob_get_clean();
$check(str_contains($staffModalHtml, 'Verify your sign-in'), 'Keep the staff verification experience distinct');
$check(str_contains($staffModalHtml, 'That code is invalid or expired.'), 'Render a safe verification error');
$check(!isset($_SESSION['otp_modal_message']), 'Consume verification status once');

unset($_SESSION['pending_auth']);
ob_start();
require __DIR__ . '/../includes/components/otp-verification-modal.php';
$unreadyModalHtml = (string) ob_get_clean();
$check($unreadyModalHtml === '', 'Do not render verification before a challenge is ready');

if ($failures !== []) {
    fwrite(STDERR, 'FAIL: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'PASS: ' . $checks . ' OTP verification modal checks.' . PHP_EOL);
