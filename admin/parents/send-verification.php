<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/parent-management-service.php';
require_once __DIR__ . '/../../services/parent-email-verification-service.php';

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
    $outcome = parent_email_verification_send_for_admin(
        db_connect(),
        parent_management_institution_id(),
        $parentId,
        (int) $user['id'],
        (string) $user['user_type']
    );
    [$messageType, $message] = match ($outcome) {
        'sent' => ['success', 'Verification email sent to the parent.'],
        'already_verified' => ['info', 'This parent email address is already verified.'],
        'rate_limited' => ['warning', 'Please wait before requesting another verification email.'],
        'delivery_failed' => ['warning', 'The verification email could not be sent. Please try again later.'],
        default => ['warning', 'The verification email could not be sent. Please check the parent account and try again later.'],
    };
    set_flash($messageType, $message);
} catch (OutOfBoundsException) {
    set_flash('danger', 'The parent record was not found.');
} catch (Throwable $exception) {
    log_error('admin_parent_verification_send_failed', [
        'error_type' => get_class($exception),
        'error_code' => $exception->getCode(),
    ]);
    set_flash('warning', 'The verification email could not be sent. Please try again later.');
}

safe_redirect(route_url('/admin/parents/view') . '?id=' . $parentId);
