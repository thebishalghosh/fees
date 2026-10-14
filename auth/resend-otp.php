<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/otp-service.php';

require_http_methods(['POST']);
require_post();
require_valid_csrf_token();

$pending = $_SESSION['pending_auth'] ?? null;
if (!is_array($pending)) {
    set_flash('warning', 'Start sign-in again to request a new code.');
    safe_redirect('/login');
}
if (($pending['challenge_ready'] ?? false) !== true) {
    unset($_SESSION['pending_auth']);
    set_flash('warning', 'Start sign-in again to request a new code.');
    safe_redirect('/login');
}

try {
    $result = resend_login_challenge($pending);
    if (in_array($result['reason'] ?? null, ['hash_key_missing', 'delivery_unavailable'], true)) {
        log_otp_authentication_unavailable($result['reason']);
        $_SESSION['otp_modal_message'] = [
            'type' => 'danger',
            'message' => 'Unable to send a sign-in code right now. Please try again later.',
        ];
    } elseif (($result['sent'] ?? false) === true) {
        $_SESSION['otp_modal_message'] = [
            'type' => 'success',
            'message' => 'A new sign-in code was sent to the registered email address.',
        ];
    } elseif (($result['cooldown'] ?? false) === true) {
        $_SESSION['otp_modal_message'] = [
            'type' => 'warning',
            'message' => 'Please wait for the resend cooldown to finish before requesting another code.',
        ];
    } else {
        $_SESSION['otp_modal_message'] = [
            'type' => 'warning',
            'message' => 'A new code is not available right now. Please try again later.',
        ];
    }
} catch (OtpEmailDeliveryException $exception) {
    log_error('authentication_email_delivery_failed', [
        'error_type' => get_class($exception),
        'error_code' => $exception->getCode(),
    ]);
    $_SESSION['otp_modal_message'] = [
        'type' => 'danger',
        'message' => 'Unable to send a sign-in code right now. Please try again later.',
    ];
} catch (Throwable $exception) {
    log_error('authentication_otp_resend_failed', [
        'error_type' => get_class($exception),
        'error_code' => $exception->getCode(),
    ]);
    $_SESSION['otp_modal_message'] = [
        'type' => 'danger',
        'message' => 'Unable to send a sign-in code right now. Please try again later.',
    ];
}

safe_redirect('/?verify=1');
