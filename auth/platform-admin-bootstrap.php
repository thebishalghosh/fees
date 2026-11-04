<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_http_methods(['POST']);

$bootstrapToken = is_string($_POST['bootstrap_token'] ?? null) ? $_POST['bootstrap_token'] : '';
if ($bootstrapToken === '' || !hash_equals((string) platform_admin_bootstrap_token(), $bootstrapToken)) {
    platform_admin_log_bootstrap_failure('invalid_token');
    http_response_code(403);
    exit('Bootstrap request rejected.');
}

$name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
$email = is_string($_POST['email'] ?? null) ? platform_admin_normalize_email($_POST['email']) : '';
$password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

try {
    $adminId = platform_admin_bootstrap_initialize(db_connect(), $name, $email, $password, $bootstrapToken);
} catch (Throwable $exception) {
    platform_admin_log_bootstrap_failure('request_failed', $exception);
    http_response_code(409);
    exit('Bootstrap is unavailable.');
}
if ($adminId < 1) {
    platform_admin_log_bootstrap_failure('request_failed');
    http_response_code(409);
    exit('Bootstrap is unavailable.');
}

safe_redirect('/');
