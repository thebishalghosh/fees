<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_http_methods(['POST']);
require_post();
require_valid_csrf_token();

$revoked = logout_user();
start_app_session();
if ($revoked) {
    set_flash('success', 'You have been signed out.');
} else {
    set_flash('warning', 'You have been signed out locally, but server-side session revocation could not be confirmed.');
}
safe_redirect('/login');
