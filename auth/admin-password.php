<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layouts/auth.php';
require_once __DIR__ . '/../services/admin-password-auth-service.php';

require_http_methods(['GET', 'POST']);
require_login();

$user = current_user();
if ($user === null || !in_array($user['user_type'], ['super_admin', 'admin'], true)) {
    http_response_code(403);
    exit('Forbidden.');
}

$message = '';
$messageType = 'danger';
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    require_post();
    require_valid_csrf_token();
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirmation = is_string($_POST['password_confirmation'] ?? null)
        ? $_POST['password_confirmation']
        : '';

    if (!admin_auth_password_is_valid($password)) {
        $message = 'Use a password of at least 12 characters, with no control characters, and no more than 72 bytes.';
    } elseif (!hash_equals($password, $confirmation)) {
        $message = 'The password confirmation does not match.';
    } else {
        try {
            $updated = admin_auth_update_own_password(
                db_connect(),
                (int) $user['id'],
                (int) $user['institution_id'],
                $password
            );
            if ($updated) {
                $message = 'Your administrator password has been updated.';
                $messageType = 'success';
            } else {
                $message = 'The password could not be updated for this account.';
            }
        } catch (Throwable $exception) {
            log_error('admin_password_update_failed', [
                'error_type' => get_class($exception),
                'error_code' => $exception->getCode(),
            ]);
            $message = 'The password could not be updated. Please try again.';
        }
    }
}

render_auth_page_start(
    'Set or reset administrator password',
    'Choose a strong password for your administrator account.'
);
?>
<?php if ($message !== ''): ?>
    <div class="alert alert-<?php echo e($messageType); ?>" role="alert"><?php echo e($message); ?></div>
<?php endif; ?>
<form method="post" action="<?php echo e(route_url('/admin-password')); ?>">
    <?php echo csrf_field(); ?>
    <div class="mb-3">
        <label class="form-label" for="admin-password">New password</label>
        <input class="form-control" id="admin-password" name="password" type="password"
            required minlength="12" maxlength="72" autocomplete="new-password">
    </div>
    <div class="mb-4">
        <label class="form-label" for="admin-password-confirmation">Confirm new password</label>
        <input class="form-control" id="admin-password-confirmation" name="password_confirmation"
            type="password" required minlength="12" maxlength="72" autocomplete="new-password">
    </div>
    <p class="form-text">Use at least 12 characters. Password values are never displayed or logged.</p>
    <button class="btn btn-cobalt w-100" type="submit">Save password</button>
</form>
<p class="mt-3 mb-0 text-center">
    <a href="<?php echo e(route_url('/admin/dashboard')); ?>">Return to administrator dashboard</a>
</p>
<?php
render_auth_page_end();
