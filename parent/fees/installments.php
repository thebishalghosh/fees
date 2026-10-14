<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$assignmentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (is_int($assignmentId) && $assignmentId > 0) {
    safe_redirect('/parent/fees/view?id=' . $assignmentId);
}
safe_redirect('/parent/fees/index');
