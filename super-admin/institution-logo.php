<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/page-placeholder.php';
require_once __DIR__ . '/../services/institution-onboarding-service.php';
require_once __DIR__ . '/../services/institution-branding-service.php';

require_http_methods(['GET', 'HEAD']);
institution_onboarding_assert_super_admin();

$institutionId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($institutionId === false || $institutionId === null || $institutionId < 1) {
    http_response_code(404);
    exit('Institution logo not found.');
}

institution_branding_send_logo(db_connect(), (int) $institutionId);
