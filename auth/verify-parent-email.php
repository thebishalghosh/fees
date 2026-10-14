<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layouts/auth.php';
require_once __DIR__ . '/../services/parent-email-verification-service.php';

require_http_methods(['GET', 'POST']);

$requestId = isset($_POST['request_id']) && is_string($_POST['request_id'])
    ? $_POST['request_id']
    : (isset($_GET['request_id']) && is_string($_GET['request_id']) ? $_GET['request_id'] : '');
if (preg_match('/^[a-f0-9-]{36}$/D', $requestId) !== 1) {
    $requestId = '';
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    require_post();
    require_valid_csrf_token();
    $otp = isset($_POST['otp']) && is_string($_POST['otp']) ? trim($_POST['otp']) : '';
    try {
        if (!verification_rate_limit_allows()) {
            set_flash('warning', 'Too many verification attempts. Please wait and try again.');
            safe_redirect('/verify-parent-email' . ($requestId !== '' ? '?request_id=' . rawurlencode($requestId) : ''));
        }
        $result = $requestId === ''
            ? 'invalid'
            : parent_email_verification_verify(db_connect(), $requestId, $otp);
        if ($result === 'verified') {
            set_flash('success', 'Your email address is verified. You can now sign in when your parent account is active.');
            safe_redirect('/login/parent');
        }
    } catch (Throwable $exception) {
        log_error('parent_email_verification_failed', [
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
        ]);
    }
    set_flash('danger', 'The verification code is invalid or expired. Request a new verification email and try again.');
    safe_redirect('/verify-parent-email' . ($requestId !== '' ? '?request_id=' . rawurlencode($requestId) : ''));
}

render_auth_page_start(
    'Verify your parent email',
    'Enter the six-digit verification code from the email sent to your address.'
);
?>
<?php if ($requestId === ''): ?>
    <div class="alert alert-warning" role="status">This verification link is invalid. Request a new verification email.</div>
    <a class="btn btn-cobalt w-100" href="<?php echo e(route_url('/request-parent-verification')); ?>">Request a new verification email</a>
<?php else: ?>
    <form method="post" action="<?php echo e(route_url('/verify-parent-email')); ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="request_id" value="<?php echo e($requestId); ?>">
        <div class="mb-4">
            <label class="form-label" for="otp">Six-digit verification code</label>
            <input class="form-control" id="otp" name="otp" type="text" required
                inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6">
            <div class="form-text">The code expires after five minutes and can only be used once.</div>
        </div>
        <button class="btn btn-cobalt w-100" type="submit">Verify email</button>
    </form>
<?php endif; ?>
<p class="mt-4 mb-0 text-center"><a href="<?php echo e(route_url('/login/parent')); ?>">Return to parent sign in</a></p>
<?php render_auth_page_end(); ?>
