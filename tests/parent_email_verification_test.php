<?php
declare(strict_types=1);

putenv('OTP_HASH_KEY=local-test-key-with-more-than-thirty-two-characters');

require_once __DIR__ . '/../services/parent-email-verification-service.php';

$checks = 0;
$failures = [];
$check = static function (bool $condition, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $label;
    }
};

$otp = '381047';
$requestId = '7c8e2c6f-267a-4e7e-9921-a1680d8c53de';
$content = parent_email_verification_content($otp, 300, $requestId, 'Ava <Parent>');
$check(str_contains($content['html'], 'Verify your email address'), 'Generate parent email-verification content');
$check(str_contains($content['html'], 'Ava &lt;Parent&gt;'), 'Escape the parent name in HTML');
$check(!str_contains($content['html'], 'Ava <Parent>'), 'Do not insert an unescaped parent name');
$check(str_contains($content['html'], $otp), 'Include the OTP in the verification email');
$check(str_contains($content['text'], $otp), 'Include the OTP in the plain-text alternative');
$check(str_contains($content['url'], '/verify-parent-email?request_id=' . $requestId), 'Generate the verification page link from the challenge ID');
$check(str_contains($content['html'], 'expires in 5 minutes'), 'State the configured challenge expiry');

$captured = null;
$delivered = deliver_parent_email_verification(
    'parent@example.test',
    $otp,
    $requestId,
    'Ava Parent',
    static function (string $recipient, string $subject, string $body, ?string $altBody) use (&$captured): bool {
        $captured = [$recipient, $subject, $body, $altBody];
        return true;
    }
);
$check($delivered, 'Accept mocked verification email delivery');
$check(is_array($captured) && $captured[0] === 'parent@example.test', 'Send the message to the intended parent email');
$check(is_array($captured) && str_contains($captured[1], 'Verify your ' . app_config()['app_name'] . ' parent account'), 'Use a parent-verification subject');
$check(is_array($captured) && str_contains($captured[2], $requestId), 'Deliver the challenge-specific verification link');

$invalidatedOnDeliveryFailure = false;
try {
    deliver_otp_with_failure_cleanup(
        static fn (): bool => deliver_parent_email_verification(
            'parent@example.test',
            $otp,
            $requestId,
            null,
            static fn (): bool => false
        ),
        static function () use (&$invalidatedOnDeliveryFailure): void {
            $invalidatedOnDeliveryFailure = true;
        }
    );
    $check(false, 'Reject unconfirmed parent verification email delivery');
} catch (OtpEmailDeliveryException) {
    $check($invalidatedOnDeliveryFailure, 'Invalidate the verification challenge on delivery failure');
}

$otpHash = hash_login_otp($otp);
$check(
    parent_email_verification_decision($otp, $otpHash, 'pending', 0, 5, true) === 'verified',
    'Accept a matching unexpired pending verification code'
);
$check(
    parent_email_verification_decision('000000', $otpHash, 'pending', 0, 5, true) === 'invalid',
    'Reject a wrong verification code'
);
$check(
    parent_email_verification_decision($otp, $otpHash, 'pending', 0, 5, false) === 'expired',
    'Reject an expired verification challenge'
);
$check(
    parent_email_verification_decision($otp, $otpHash, 'expired', 0, 5, true) === 'invalid',
    'Reject an expired or otherwise inactive challenge status'
);
$check(
    parent_email_verification_decision($otp, $otpHash, 'pending', 5, 5, true) === 'failed',
    'Reject a challenge after its attempt limit'
);
$check(
    parent_email_verification_decision($otp, $otpHash, 'verified', 0, 5, true) === 'invalid',
    'Reject an already-used verification challenge'
);
$check(
    parent_email_verification_next_statuses('pending_verification', 'pending')
        === ['parent_status' => 'active', 'user_status' => 'active'],
    'Activate a pending parent account only after successful email verification'
);
$check(
    parent_email_verification_next_statuses('inactive', 'inactive')
        === ['parent_status' => 'inactive', 'user_status' => 'inactive'],
    'Do not activate an account that was explicitly created inactive'
);

$serviceSource = file_get_contents(__DIR__ . '/../services/parent-email-verification-service.php');
$parentService = file_get_contents(__DIR__ . '/../services/parent-management-service.php');
$storeSource = file_get_contents(__DIR__ . '/../admin/parents/store.php');
$verifyRoute = file_get_contents(__DIR__ . '/../auth/verify-parent-email.php');
$resendRoute = file_get_contents(__DIR__ . '/../auth/request-parent-verification.php');
$adminSendRoute = file_get_contents(__DIR__ . '/../admin/parents/send-verification.php');
$parentView = file_get_contents(__DIR__ . '/../admin/parents/view.php');
$rewriteRules = file_get_contents(__DIR__ . '/../.htaccess');
$check(is_string($serviceSource) && is_string($parentService) && is_string($storeSource), 'Read verification workflow sources');
if (is_string($serviceSource) && is_string($parentService) && is_string($storeSource)) {
    $check(str_contains($storeSource, 'parent_email_verification_issue('), 'Trigger verification after creating the parent');
    $check(str_contains($serviceSource, "purpose = 'email_verification'"), 'Use a dedicated email-verification challenge purpose');
    $check(str_contains($serviceSource, 'LIMIT 1 FOR UPDATE'), 'Lock the challenge row during verification');
    $check(str_contains($serviceSource, 'attempts_count = ?, status = ?'), 'Record failed verification attempts');
    $check(str_contains($parentService, 'status IN (\'pending\', \'failed\')'), 'Invalidate old-email challenges when a parent email changes');
    $check(str_contains($serviceSource, 'consume_auth_rate_limit($accountScope, 5, 900)'), 'Rate-limit verification resends per account');
    $check(str_contains($serviceSource, 'request_ip_rate_limit_allows()'), 'Apply the existing IP rate limit to verification resends');
    $check(str_contains($parentService, 'must be verified before the profile can be activated'), 'Block manual activation until the parent email is verified');
    $check(str_contains($storeSource, 'Parent profile created, but the verification email could not be sent'), 'Report delivery failure without undoing successful parent creation');
}
$check(is_string($verifyRoute) && str_contains($verifyRoute, 'require_valid_csrf_token()'), 'Protect email verification with CSRF');
$check(is_string($resendRoute) && str_contains($resendRoute, 'require_valid_csrf_token()'), 'Protect verification resend with CSRF');
$check(
    is_string($resendRoute)
        && str_contains($resendRoute, 'If the details match an eligible unverified parent account'),
    'Use a generic resend response that avoids account enumeration'
);
$check(
    parent_email_verification_admin_result('sent') === 'sent'
        && parent_email_verification_admin_result('already_verified') === 'already_verified'
        && parent_email_verification_admin_result('cooldown') === 'rate_limited'
        && parent_email_verification_admin_result('rate_limited') === 'rate_limited'
        && parent_email_verification_admin_result('delivery_failed') === 'delivery_failed'
        && parent_email_verification_admin_result('ineligible') === 'failed',
    'Map admin send outcomes to safe, explicit states'
);
$check(
    is_string($adminSendRoute)
        && str_contains($adminSendRoute, 'require_post()')
        && str_contains($adminSendRoute, 'require_placeholder_route_access()')
        && str_contains($adminSendRoute, 'require_valid_csrf_token()'),
    'Require POST, existing admin authorization, and CSRF validation for administrative sends'
);
$check(
    is_string($adminSendRoute)
        && str_contains($adminSendRoute, 'filter_var($_POST[\'parent_id\'], FILTER_VALIDATE_INT)')
        && !str_contains($adminSendRoute, '$_POST[\'email\']'),
    'Take only the parent identifier from the browser, never an email address'
);
$check(
    is_string($parentView)
        && str_contains($parentView, 'user_has_permission(\'parents.manage\')')
        && str_contains($parentView, '!$parent[\'email_verified\']')
        && str_contains($parentView, 'method="post"')
        && str_contains($parentView, 'csrf_field()'),
    'Show the CSRF-protected send button only to managers for unverified parents'
);
$check(
    is_string($serviceSource)
        && str_contains($serviceSource, 'WHERE p.institution_id = ? AND p.id = ?')
        && str_contains($serviceSource, 'auth_rate_limit_scope(')
        && str_contains($serviceSource, 'request_ip_rate_limit_allows()'),
    'Scope admin sends by institution and apply account and IP rate limits'
);
$check(
    is_string($serviceSource)
        && str_contains($serviceSource, 'parent.email_verification_requested')
        && str_contains($serviceSource, 'write_audit_log(')
        && str_contains($serviceSource, 'catch (OtpEmailDeliveryException)'),
    'Audit administrative attempts and handle email-delivery failure without exposing secrets'
);
$check(
    is_string($rewriteRules)
        && str_contains($rewriteRules, 'verify-parent-email/?$ auth/verify-parent-email.php')
        && str_contains($rewriteRules, 'request-parent-verification/?$ auth/request-parent-verification.php'),
    'Expose clean verification and resend routes through the existing rewrite file'
);

if ($failures !== []) {
    fwrite(STDERR, 'FAIL: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'PASS: ' . $checks . ' parent email verification checks.' . PHP_EOL);
