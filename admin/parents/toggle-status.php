<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/parent-management-service.php';

require_post();
require_placeholder_route_access();
require_valid_csrf_token();

$parentId = isset($_POST['parent_id']) && is_string($_POST['parent_id'])
    ? filter_var($_POST['parent_id'], FILTER_VALIDATE_INT)
    : false;
if ($parentId === false || $parentId < 1) {
    set_flash('danger', 'The parent record was not found.');
    safe_redirect(route_url('/admin/parents/index'));
}

$user = current_user();
try {
    $newStatus = parent_management_toggle_status(
        db_connect(),
        parent_management_institution_id(),
        $parentId,
        (int) $user['id'],
        (string) $user['user_type']
    );
    set_flash(
        'success',
        $newStatus === 'active'
            ? 'Parent profile activated. Email verification is still required before email OTP sign-in.'
            : 'Parent access deactivated. Existing sessions have been revoked.'
    );
} catch (Throwable $exception) {
    parent_management_handle_failure($exception, 'toggle_status');
}
safe_redirect(route_url('/admin/parents/view') . '?id=' . $parentId);
