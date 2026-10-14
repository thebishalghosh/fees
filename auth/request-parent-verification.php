<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layouts/auth.php';
require_once __DIR__ . '/../services/parent-email-verification-service.php';

require_http_methods(['GET', 'POST']);

$institutionCode = '';
$email = '';
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    require_post();
    require_valid_csrf_token();
    $institutionCode = isset($_POST['institution_code']) && is_string($_POST['institution_code'])
        ? trim($_POST['institution_code'])
        : '';
    $email = isset($_POST['email']) && is_string($_POST['email'])
        ? strtolower(trim($_POST['email']))
        : '';
    try {
        parent_email_verification_request_resend(db_connect(), $institutionCode, $email);
    } catch (Throwable $exception) {
        log_error('parent_email_verification_resend_failed', [
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
        ]);
    }
    set_flash(
        'info',
        'If the details match an eligible unverified parent account, a verification email may be sent.'
    );
    safe_redirect('/request-parent-verification');
}

render_auth_page_start(
    'Resend parent verification',
    'Enter your institution code and parent email address to request a new verification code.'
);
?>
<form method="post" action="<?php echo e(route_url('/request-parent-verification')); ?>">
    <?php echo csrf_field(); ?>
    <div class="mb-3">
        <label class="form-label" for="institution_code">Institution code</label>
        <input class="form-control" id="institution_code" name="institution_code" type="text"
            maxlength="50" autocomplete="organization" required value="<?php echo e($institutionCode); ?>">
    </div>
    <div class="mb-4">
        <label class="form-label" for="email">Parent email address</label>
        <input class="form-control" id="email" name="email" type="email" maxlength="255"
            autocomplete="email" required value="<?php echo e($email); ?>">
    </div>
    <button class="btn btn-cobalt w-100" type="submit">Request verification email</button>
</form>
<p class="mt-4 mb-0 text-center"><a href="<?php echo e(route_url('/login/parent')); ?>">Return to parent sign in</a></p>
<?php render_auth_page_end(); ?>
