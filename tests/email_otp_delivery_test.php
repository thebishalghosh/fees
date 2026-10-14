<?php
declare(strict_types=1);

putenv('OTP_HASH_KEY=local-test-key-with-more-than-thirty-two-characters');

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../services/otp-service.php';

$checks = 0;
$failures = [];
$check = static function (bool $condition, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $label;
    }
};

$received = null;
$otp = '041927';
$recipientName = 'Ava <Parent> & Family';
$sent = deliver_login_otp(
    'registered@example.test',
    $otp,
    static function (
        string $recipient,
        string $subject,
        string $body,
        ?string $altBody
    ) use (&$received): bool {
        $received = [$recipient, $subject, $body, $altBody];
        return true;
    },
    null,
    $recipientName
);
$check($sent, 'Confirm mocked email delivery');
$check(is_array($received) && $received[0] === 'registered@example.test', 'Deliver to supplied registered email');
$check(
    is_array($received)
        && str_contains($received[1], 'Fees Management System verification code')
        && str_contains($received[2], 'Verify your email address')
        && str_contains($received[2], $otp),
    'Send branded HTML containing the dynamic OTP'
);
$check(
    is_array($received)
        && str_contains($received[2], 'This code expires in <strong')
        && str_contains($received[2], otp_settings()['otp_lifetime_seconds'] / 60 . ' minutes'),
    'Display the configured OTP lifetime in the HTML email'
);
$check(
    is_array($received)
        && str_contains($received[2], 'Never share it with anyone, including staff or support personnel.')
        && str_contains($received[2], 'If you did not request this code'),
    'Include the security and unrequested-login notices'
);
$check(
    is_array($received)
        && str_contains($received[2], 'Ava &lt;Parent&gt; &amp; Family')
        && !str_contains($received[2], 'Ava <Parent>'),
    'Escape the dynamic recipient name in HTML'
);
$check(
    is_array($received)
        && is_string($received[3])
        && str_contains($received[3], $otp)
        && str_contains($received[3], 'This code expires in 5 minutes.')
        && !str_contains($received[3], '<table'),
    'Provide a readable plain-text alternative'
);

$emailContent = login_otp_email_content('123456', 301, null, 'Fees & <Management>');
$check(
    str_contains($emailContent['html'], 'Fees &amp; &lt;Management&gt;')
        && str_contains($emailContent['html'], 'Hello,</p>')
        && str_contains($emailContent['html'], '5 minutes and 1 second')
        && str_contains($emailContent['text'], '5 minutes and 1 second'),
    'Escape branding, use generic greeting when name is absent, and state exact expiry duration'
);

$invalidated = false;
try {
    deliver_otp_with_failure_cleanup(
        static fn(): bool => deliver_login_otp(
            'registered@example.test',
            $otp,
            static fn(): bool => false
        ),
        static function () use (&$invalidated): void {
            $invalidated = true;
        }
    );
    $check(false, 'Reject unconfirmed email delivery');
} catch (OtpEmailDeliveryException $exception) {
    $check($invalidated, 'Invalidate challenge on delivery failure');
}

$invalidatedAfterTransportError = false;
try {
    deliver_otp_with_failure_cleanup(
        static function (): bool {
            throw new RuntimeException('simulated SMTP unavailability');
        },
        static function () use (&$invalidatedAfterTransportError): void {
            $invalidatedAfterTransportError = true;
        }
    );
    $check(false, 'Reject failed SMTP transport');
} catch (OtpEmailDeliveryException $exception) {
    $check($invalidatedAfterTransportError, 'Invalidate challenge when SMTP transport throws');
}

$invalidatedAfterSuccess = false;
deliver_otp_with_failure_cleanup(
    static fn(): bool => true,
    static function () use (&$invalidatedAfterSuccess): void {
        $invalidatedAfterSuccess = true;
    }
);
$check(!$invalidatedAfterSuccess, 'Keep successful delivery challenge valid');

$invalidRecipientRejected = false;
try {
    send_otp_email('not-an-email', 'Test', 'Test body');
} catch (OtpEmailDeliveryException $exception) {
    $invalidRecipientRejected = true;
}
$check($invalidRecipientRejected, 'Reject malformed recipient email addresses');

$diagnosticLog = tempnam(sys_get_temp_dir(), 'fees-mailpit-diagnostic-');
if ($diagnosticLog === false) {
    $failures[] = 'Create temporary diagnostic log';
} else {
    $previousErrorLog = ini_get('error_log');
    ini_set('error_log', $diagnosticLog);
    log_mailpit_delivery_failure(
        'smtp_send_failed',
        new RuntimeException('SMTP rejected code 654321 for registered@example.test'),
        'registered@example.test'
    );
    ini_set('error_log', is_string($previousErrorLog) ? $previousErrorLog : '');
    $diagnosticOutput = file_get_contents($diagnosticLog);
    $check(is_string($diagnosticOutput) && str_contains($diagnosticOutput, 'smtp_send_failed'), 'Log sanitized SMTP failure diagnostics in development');
    $check(is_string($diagnosticOutput) && !str_contains($diagnosticOutput, '654321'), 'Do not log OTP-shaped values in SMTP diagnostics');
    $check(is_string($diagnosticOutput) && !str_contains($diagnosticOutput, 'registered@example.test'), 'Do not log the recipient address');
    if (!unlink($diagnosticLog)) {
        $failures[] = 'Remove temporary diagnostic log';
    }
}

$check(login_otp_is_usable('pending', 4, 5, true), 'Allow final OTP attempt before limit');
$check(!login_otp_is_usable('pending', 0, 5, false), 'Reject expired OTP challenge');
$check(next_login_otp_attempt(4, 5) === ['attempts' => 5, 'status' => 'failed'], 'Enforce OTP retry limit');

if ($failures !== []) {
    fwrite(STDERR, 'FAIL: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'PASS: ' . $checks . ' mocked email/OTP checks.' . PHP_EOL);
