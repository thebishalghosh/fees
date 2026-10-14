<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
safe_redirect('/admin/settings/general', 302);
