<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layouts/auth.php';
require_once __DIR__ . '/../services/otp-service.php';

require_http_methods(['GET', 'POST']);

if (is_authenticated()) {
    safe_redirect(authenticated_dashboard_route());
}

$pending = $_SESSION['pending_auth'] ?? null;
if (!is_array($pending) || ($pending['challenge_ready'] ?? false) !== true) {
    set_flash('warning', 'Start sign-in again to request a new code.');
    safe_redirect('/login');
}
$parentEmailFlow = ($pending['login_type'] ?? null) === 'parent_email';

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    require_post();
    require_valid_csrf_token();

    try {
        if (!verification_rate_limit_allows()) {
            $_SESSION['otp_modal_message'] = [
                'type' => 'warning',
                'message' => 'Too many verification attempts. Wait a little while, then try again.',
            ];
            safe_redirect('/?verify=1');
        } else {
            $result = verify_login_challenge($pending, get_post_value('otp'));
            if ($result === 'authenticated') {
                safe_redirect(authenticated_dashboard_route());
            }
            $_SESSION['otp_modal_message'] = [
                'type' => 'danger',
                'message' => 'That code is invalid or expired. You can try again or request another code.',
            ];
            safe_redirect('/?verify=1');
        }
    } catch (Throwable $exception) {
        log_error('authentication_otp_verification_failed', [
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
        ]);
        $_SESSION['otp_modal_message'] = [
            'type' => 'danger',
            'message' => 'Verification is temporarily unavailable. Please try again.',
        ];
        safe_redirect('/?verify=1');
    }
}

$settings = otp_settings();
$createdAt = (int) ($pending['created_at'] ?? time());
$remainingSeconds = max(
    0,
    (int) $settings['otp_resend_cooldown_seconds'] - (time() - $createdAt)
);

render_auth_page_start(
    'Verify your email address',
    'Enter the six-digit code sent to your registered email address.'
);
?>
<p class="text-secondary mb-3">A code was sent to <?php echo e($pending['masked_email'] ?? 'your registered email address'); ?>.</p>
<form method="post" action="<?php echo e(route_url('/verify-otp')); ?>">
    <?php echo csrf_field(); ?>
    <div class="mb-4">
        <label class="form-label" for="otp">Six-digit code</label>
        <input class="form-control" id="otp" name="otp" type="text" required
            inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6"
            aria-describedby="otp-help">
        <div class="form-text" id="otp-help">Codes are sent to the verified email address on your account, expire after five minutes, and can be used once.</div>
    </div>
    <button class="btn btn-cobalt w-100" type="submit">Verify and sign in</button>
</form>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mt-4">
    <span id="resend-countdown" role="status" aria-live="polite"></span>
    <form method="post" action="<?php echo e(route_url('/resend-otp')); ?>">
        <?php echo csrf_field(); ?>
        <button class="btn btn-outline-primary" id="resend-button" type="submit"
            <?php echo $remainingSeconds > 0 ? 'disabled' : ''; ?>>Resend code</button>
    </form>
</div>
<form method="post" action="<?php echo e(route_url($parentEmailFlow ? '/login/parent' : '/login/staff')); ?>" class="mt-3">
    <?php echo csrf_field(); ?>
    <button class="btn btn-link px-0" type="submit" name="start_over" value="1"><?php echo $parentEmailFlow ? 'Use a different email address' : 'Use a different institution or email'; ?></button>
</form>
<script>
(() => {
    let remaining = <?php echo e((string) $remainingSeconds); ?>;
    const label = document.getElementById('resend-countdown');
    const button = document.getElementById('resend-button');
    const update = () => {
        if (remaining <= 0) {
            label.textContent = 'You can request another code.';
            button.disabled = false;
            return;
        }
        label.textContent = `You can request another code in ${remaining} seconds.`;
        button.disabled = true;
        remaining -= 1;
        window.setTimeout(update, 1000);
    };
    update();
})();
</script>
<?php
render_auth_page_end();
