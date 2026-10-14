<?php
declare(strict_types=1);

require_once __DIR__ . '/../../services/otp-service.php';

$pendingOtpAuth = $_SESSION['pending_auth'] ?? null;
if (!is_array($pendingOtpAuth) || ($pendingOtpAuth['challenge_ready'] ?? false) !== true) {
    return;
}

$parentOtpFlow = ($pendingOtpAuth['login_type'] ?? null) === 'parent_email';
$otpModalMessage = $_SESSION['otp_modal_message'] ?? null;
unset($_SESSION['otp_modal_message']);
if (!is_array($otpModalMessage)) {
    $otpModalMessage = null;
}
$otpMessageType = in_array($otpModalMessage['type'] ?? null, ['success', 'danger', 'warning', 'info'], true)
    ? $otpModalMessage['type']
    : 'info';
$otpMessageText = is_string($otpModalMessage['message'] ?? null) ? $otpModalMessage['message'] : '';
$otpSettings = otp_settings();
$otpCreatedAt = (int) ($pendingOtpAuth['created_at'] ?? time());
$resendWait = max(
    0,
    (int) $otpSettings['otp_resend_cooldown_seconds'] - (time() - $otpCreatedAt)
);
$showOtpModal = isset($_GET['verify']) && $_GET['verify'] === '1';
?>
<div class="modal fade login-modal" id="otp-verification-modal" tabindex="-1"
    aria-labelledby="otp-verification-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <div>
                    <span class="eyebrow">Secure verification</span>
                    <h2 class="modal-title fs-4" id="otp-verification-title">
                        <?php echo $parentOtpFlow ? 'Verify your email address' : 'Verify your sign-in'; ?>
                    </h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close verification dialog"></button>
            </div>
            <div class="modal-body pt-3">
                <p class="login-modal-help">
                    Enter the one-time code sent to
                    <strong><?php echo e($pendingOtpAuth['masked_email'] ?? 'your registered email address'); ?></strong>.
                    The code expires after <?php echo e((string) ceil((int) $otpSettings['otp_lifetime_seconds'] / 60)); ?> minutes.
                </p>
                <?php if ($otpMessageText !== ''): ?>
                    <div class="alert alert-<?php echo e($otpMessageType); ?>" role="<?php echo $otpMessageType === 'success' ? 'status' : 'alert'; ?>" aria-live="polite">
                        <?php echo e($otpMessageText); ?>
                    </div>
                <?php endif; ?>
                <form method="post" action="<?php echo e(route_url('/verify-otp')); ?>" id="otp-verification-form">
                    <?php echo csrf_field(); ?>
                    <div class="mb-4">
                        <label class="form-label" for="otp-modal-code">Six-digit verification code</label>
                        <input class="form-control" id="otp-modal-code" name="otp" type="text" required
                            inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6"
                            aria-describedby="otp-modal-help">
                        <div class="form-text" id="otp-modal-help">
                            Codes can be used once. Do not share your code with anyone.
                        </div>
                    </div>
                    <button class="btn btn-cobalt w-100" type="submit">Verify and sign in</button>
                </form>
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mt-4">
                    <span id="otp-modal-resend-status" role="status" aria-live="polite"></span>
                    <form method="post" action="<?php echo e(route_url('/resend-otp')); ?>" id="otp-resend-form">
                        <?php echo csrf_field(); ?>
                        <button class="btn btn-outline-primary" id="otp-modal-resend" type="submit"
                            <?php echo $resendWait > 0 ? 'disabled' : ''; ?>>Resend code</button>
                    </form>
                </div>
                <form method="post" action="<?php echo e(route_url($parentOtpFlow ? '/login/parent' : '/login/staff')); ?>" class="mt-3">
                    <?php echo csrf_field(); ?>
                    <button class="btn btn-link px-0" type="submit" name="start_over" value="1">
                        <?php echo $parentOtpFlow ? 'Use a different email address' : 'Use a different institution or email'; ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const modalElement = document.getElementById('otp-verification-modal');
    const shouldOpen = <?php echo $showOtpModal ? 'true' : 'false'; ?>;
    if (!modalElement || !window.bootstrap) {
        return;
    }

    if (shouldOpen) {
        window.bootstrap.Modal.getOrCreateInstance(modalElement).show();
    }

    for (const formId of ['otp-verification-form', 'otp-resend-form']) {
        const form = document.getElementById(formId);
        if (!form) {
            continue;
        }
        form.addEventListener('submit', () => {
            const button = form.querySelector('button[type="submit"]');
            if (button) {
                button.disabled = true;
                button.setAttribute('aria-busy', 'true');
                button.textContent = formId === 'otp-resend-form' ? 'Sending…' : 'Verifying…';
            }
        });
    }

    let remaining = <?php echo e((string) $resendWait); ?>;
    const status = document.getElementById('otp-modal-resend-status');
    const button = document.getElementById('otp-modal-resend');
    const updateCooldown = () => {
        if (remaining <= 0) {
            status.textContent = 'You can request another code.';
            button.disabled = false;
            return;
        }
        status.textContent = `You can request another code in ${remaining} seconds.`;
        button.disabled = true;
        remaining -= 1;
        window.setTimeout(updateCooldown, 1000);
    };
    updateCooldown();
});
</script>
