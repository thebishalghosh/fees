<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/institution-branding-service.php';

require_http_methods(['GET', 'HEAD']);
$institutionCode = is_string($_GET['institution_code'] ?? null)
    ? $_GET['institution_code']
    : '';
$institution = institution_branding_get_for_login_code(db_connect(), $institutionCode);
if ($institution === null) {
    http_response_code(404);
    exit('Institution logo not found.');
}

institution_branding_send_logo(db_connect(), (int) $institution['id']);
