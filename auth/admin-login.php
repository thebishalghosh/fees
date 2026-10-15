<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layouts/auth.php';
require_once __DIR__ . '/../includes/components/login-form.php';
require_once __DIR__ . '/../services/admin-password-auth-service.php';

require_http_methods(['GET', 'POST']);

if (is_authenticated()) {
    safe_redirect(authenticated_dashboard_route());
}

$identifier = '';
$loginError = '';
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    require_post();
    require_valid_csrf_token();
    $identifier = is_string($_POST['identifier'] ?? null) ? trim($_POST['identifier']) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

    try {
        $result = admin_auth_password_login(db_connect(), $identifier, $password);
        if ($result === 'authenticated') {
            safe_redirect('/admin/dashboard', 303);
        }
        $loginError = $result === 'rate_limited'
            ? 'Too many sign-in attempts. Wait a little while, then try again.'
            : 'Invalid username/email or password.';
    } catch (Throwable $exception) {
        log_error('admin_password_login_failed', [
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
        ]);
        $loginError = 'Sign-in is temporarily unavailable. Please try again.';
    }
}

render_auth_page_start(
    'Administrator sign in',
    'Sign in with your administrator username or email and password.'
);
?>
<?php if ($loginError !== ''): ?>
    <div class="alert alert-danger" role="alert"><?php echo e($loginError); ?></div>
<?php endif; ?>
<?php render_login_form(
    'admin',
    route_url('/login/admin'),
    'admin-login',
    ['identifier' => $identifier]
); ?>
<p class="mt-3 mb-0 text-center">
    <a href="<?php echo e(route_url('/login/staff')); ?>">Use staff email OTP sign in or recover your administrator password</a>
</p>
<p class="mt-2 mb-0 text-center">
    <a href="<?php echo e(route_url('/login/parent')); ?>">Sign in as a parent</a>
</p>
<?php
render_auth_page_end();
