<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/page-placeholder.php';
require_once __DIR__ . '/../services/admin-panel-service.php';
require_once __DIR__ . '/../services/institution-branding-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
institution_branding_send_logo(db_connect(), admin_panel_institution_id());
