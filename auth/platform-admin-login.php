<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_http_methods(['POST']);

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verify_csrf_token($csrfToken)) {
    http_response_code(419);
    exit('CSRF verification failed.');
}

$email = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
$password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
$ipAddress = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : null;
$db = db_connect();
try {
    $login = platform_admin_login(
        $db,
        $email,
        $password,
        is_string($_SERVER['HTTP_USER_AGENT'] ?? null) ? $_SERVER['HTTP_USER_AGENT'] : null,
        $ipAddress
    );
} catch (PlatformAdminLoginRateLimited $exception) {
    http_response_code(429);
    header('Retry-After: 900');
    exit('Too many login attempts. Try again later.');
}
if ($login === null) {
    set_flash('error', 'Invalid platform administrator credentials.');
    safe_redirect('/');
}

safe_redirect('/');
