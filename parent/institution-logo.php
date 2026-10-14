<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/page-placeholder.php';
require_once __DIR__ . '/../services/institution-branding-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$user = current_user();
if ($user === null || $user['user_type'] !== 'parent') {
    http_response_code(403);
    exit('Forbidden.');
}
institution_branding_send_logo(db_connect(), (int) $user['institution_id']);
