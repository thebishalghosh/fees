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
$institutionCodeInput = '';
$emailError = '';
$institutionError = '';

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    require_post();
    require_valid_csrf_token();
    if (($_POST['start_over'] ?? null) === '1') {
        unset($_SESSION['pending_auth']);
        safe_redirect('/?login=1&account=staff');
    }

    unset($_SESSION['pending_auth'], $_SESSION['otp_modal_message']);
    try {
        $emailInput = get_post_value('email');
        $institutionCodeInput = strtoupper(get_post_value('institution_code'));
    } catch (InvalidArgumentException $exception) {
        $emailInput = '';
        $institutionCodeInput = '';
        $emailError = 'Enter a valid email address.';
        $institutionError = 'Enter the institution code provided by your institution.';
    }

    if ($emailError === '' && $institutionError === '') {
        if (!validate_email($emailInput) || !validate_max_length($emailInput, 255)) {
            $emailError = 'Enter a valid email address.';
        }
        $validInstitutionCode = validate_institution_code($institutionCodeInput);
        if (!$validInstitutionCode) {
            $institutionError = 'Enter the institution code provided by your institution.';
        }
    }

    if ($emailError === '' && $institutionError === '') {
        $challenge = null;
        $deliveryFailure = false;
        try {
            $challenge = begin_staff_email_challenge($institutionCodeInput, $emailInput);
        } catch (OtpEmailDeliveryException $exception) {
            $deliveryFailure = true;
            log_error('authentication_email_delivery_failed', [
                'error_type' => get_class($exception),
                'error_code' => $exception->getCode(),
            ]);
        } catch (Throwable $exception) {
            log_error('authentication_otp_request_failed', [
                'error_type' => get_class($exception),
                'error_code' => $exception->getCode(),
            ]);
        }

        if ($deliveryFailure) {
            set_flash('danger', 'Unable to send a sign-in code right now. Please try again later.', 'staff_auth');
            safe_redirect('/?login=1&account=staff');
        }

        if (is_array($challenge)
            && in_array($challenge['reason'] ?? null, ['hash_key_missing', 'delivery_unavailable'], true)) {
            log_otp_authentication_unavailable((string) $challenge['reason']);
            set_flash('warning', 'Email OTP sign-in is temporarily unavailable. Please try again later.', 'staff_auth');
            safe_redirect('/?login=1&account=staff');
        }

        $usableChallenge = is_array($challenge)
            && ($challenge['available'] ?? false)
            && is_string($challenge['request_id'] ?? null)
            && is_array($challenge['user'] ?? null);
        if (!$usableChallenge) {
            if (is_array($challenge) && is_string($challenge['reason'] ?? null)) {
                log_otp_authentication_unavailable($challenge['reason']);
            }
            set_flash(
                'info',
                'If the details match an active account, a code may be sent to its registered email address.',
                'staff_auth'
            );
            safe_redirect('/?login=1&account=staff');
        }

        store_pending_authentication(
            $challenge['user'],
            $challenge['request_id'],
            $emailInput,
            $institutionCodeInput,
            true
        );
        safe_redirect('/?verify=1');
    }

    $_SESSION['login_modal_state'] = [
        'staff' => [
            'values' => [
                'email' => $emailInput,
                'institution_code' => $institutionCodeInput,
            ],
            'errors' => [
                'email' => $emailError,
                'institution_code' => $institutionError,
            ],
        ],
    ];
    safe_redirect('/?login=1&account=staff');
}

render_auth_page_start(
    'Sign in to FeesPortal',
    'Enter your institution code and verified email address to continue.'
);
?>
<?php render_login_form(
    'staff',
    route_url('/login/staff'),
    'direct-staff',
    ['email' => $emailInput, 'institution_code' => $institutionCodeInput],
    ['email' => $emailError, 'institution_code' => $institutionError]
); ?>
<p class="mt-4 mb-0 text-center"><a href="<?php echo e(route_url('/login/parent')); ?>">Sign in as a parent with email</a></p>
<?php
render_auth_page_end();
