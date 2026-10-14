<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/parent-portal-service.php';

require_http_methods(['POST']);
require_placeholder_route_access();
require_valid_csrf_token();
$db = db_connect();
$context = parent_portal_require_current_parent($db);
$validation = parent_portal_validate_profile_update($_POST);
if ($validation['errors'] !== []) {
    set_flash('danger', implode(' ', $validation['errors']));
    safe_redirect('/parent/profile/index', 303);
}

try {
    parent_portal_update_profile(
        $db,
        (int) $context['parent']['parent_id'],
        (int) $context['user']['institution_id'],
        (int) $context['user']['id'],
        $validation['data']
    );
} catch (Throwable $exception) {
    log_error('parent_profile_update_failed', [
        'error_type' => get_class($exception),
        'error_code' => $exception->getCode(),
    ]);
    set_flash('danger', 'Your profile could not be saved. Please try again or contact the institution administrator.');
    safe_redirect('/parent/profile/index', 303);
}

set_flash('success', 'Your profile has been updated.');
safe_redirect('/parent/profile/index', 303);
