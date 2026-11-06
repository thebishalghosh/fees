<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../services/admin-password-auth-service.php';

require_http_methods(['GET', 'POST']);

if (is_authenticated()) {
    safe_redirect(authenticated_dashboard_route());
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    safe_redirect('/?login=1&account=admin');
}

$identifier = '';
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

$loginModalState = is_array($_SESSION['login_modal_state'] ?? null)
    ? $_SESSION['login_modal_state']
    : [];
$loginModalState['admin'] = [
    'values' => ['identifier' => $identifier],
];
$_SESSION['login_modal_state'] = $loginModalState;
set_flash('danger', $loginError, 'admin_auth');
safe_redirect('/?login=1&account=admin', 303);
