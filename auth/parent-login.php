<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layouts/auth.php';
require_once __DIR__ . '/../includes/components/login-form.php';
require_once __DIR__ . '/../services/otp-service.php';

require_http_methods(['GET', 'POST']);

if (is_authenticated()) {
    safe_redirect(authenticated_dashboard_route());
}

$emailInput = '';
$emailError = '';

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    require_post();
    require_valid_csrf_token();

    if (($_POST['start_over'] ?? null) === '1') {
        unset($_SESSION['pending_auth']);
        safe_redirect('/login/parent');
    }

    $correlationId = create_auth_request_id();
    unset($_SESSION['pending_auth'], $_SESSION['otp_modal_message']);
    try {
        $emailInput = get_post_value('email');
    } catch (InvalidArgumentException $exception) {
        $emailInput = '';
        $emailError = 'Enter a valid email address.';
    }

    if ($emailError === '' && (!validate_email($emailInput) || !validate_max_length($emailInput, 255))) {
        $emailError = 'Enter a valid email address.';
    }

    if ($emailError !== '') {
        log_parent_otp_diagnostic($correlationId, 'input_rejected');
    }

    if ($emailError === '') {
        $challenge = null;
        $deliveryFailure = false;
        try {
            $challenge = begin_parent_email_challenge($emailInput, $correlationId);
        } catch (OtpEmailDeliveryException) {
            $deliveryFailure = true;
        } catch (OtpGenerationException) {
            $challenge = ['available' => false, 'request_id' => null, 'reason' => 'otp_generation_failed'];
        } catch (Throwable) {
            log_parent_otp_diagnostic($correlationId, 'challenge_unavailable');
        }

        if ($deliveryFailure
            || (is_array($challenge)
                && in_array($challenge['reason'] ?? null, ['hash_key_missing', 'delivery_unavailable'], true))) {
            set_flash('warning', 'Unable to send a sign-in code right now. Please try again later.', 'parent_auth');
            safe_redirect('/?login=1');
        }

        $usableChallenge = is_array($challenge)
            && ($challenge['available'] ?? false)
            && is_string($challenge['request_id'] ?? null)
            && is_array($challenge['user'] ?? null);
        if ($usableChallenge) {
            store_pending_parent_authentication(
                $challenge['user'],
                $challenge['request_id'],
                true
            );
            safe_redirect('/?verify=1');
        }

        set_flash(
            'info',
            'If the address matches one eligible parent account, a code may be sent to its registered email address.',
            'parent_auth'
        );
        safe_redirect('/?login=1');
    }

    $_SESSION['login_modal_state'] = [
        'parent' => [
            'values' => ['email' => $emailInput],
            'errors' => ['email' => $emailError],
        ],
    ];
    safe_redirect('/?login=1');
}

render_auth_page_start(
    'Parent sign in',
    'Enter the verified email address registered with your institution.'
);
?>
<?php render_login_form(
    'parent',
    route_url('/login/parent'),
    'direct-parent',
    ['email' => $emailInput],
    ['email' => $emailError]
); ?>
<p class="mt-4 mb-0 text-center">
    <a href="<?php echo e(route_url('/login/staff')); ?>">Sign in as staff or administrator</a>
</p>
<p class="mt-2 mb-0 text-center">
    <a href="<?php echo e(route_url('/request-parent-verification')); ?>">Resend email verification</a>
</p>
<?php
render_auth_page_end();
