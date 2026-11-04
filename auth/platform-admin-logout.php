<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_http_methods(['POST']);
require_valid_csrf_token();
require_platform_admin();

if (!platform_admin_logout()) {
	http_response_code(401);
	exit('Platform admin authentication required.');
}
safe_redirect('/');
