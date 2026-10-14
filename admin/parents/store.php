<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/parent-management-service.php';
require_once __DIR__ . '/../../services/parent-email-verification-service.php';

require_post();
require_placeholder_route_access();
require_valid_csrf_token();

$user = current_user();
$institutionId = parent_management_institution_id();
$profileResult = parent_management_validate_profile($_POST);
$initialStatus = !array_key_exists('initial_status', $_POST)
    ? 'pending_verification'
    : (is_string($_POST['initial_status']) ? $_POST['initial_status'] : '');
$studentIds = [];
$errors = $profileResult['errors'];
try {
    $studentIds = parent_management_student_ids($_POST['student_ids'] ?? null);
} catch (InvalidArgumentException $exception) {
    $errors[] = $exception->getMessage();
}
if (!in_array($initialStatus, ['pending_verification', 'inactive'], true)) {
    $errors[] = 'Choose a supported initial parent status.';
}

if ($errors !== []) {
    $_SESSION['parent_management_old'] = parent_management_old_form_values($_POST, 'create');
    set_flash('danger', implode(' ', $errors));
    safe_redirect(route_url('/admin/parents/create'));
}

try {
    $db = db_connect();
    $parentId = parent_management_create(
        $db,
        $institutionId,
        (int) $user['id'],
        (string) $user['user_type'],
        $profileResult['data'],
        $initialStatus,
        $studentIds
    );
} catch (Throwable $exception) {
    $_SESSION['parent_management_old'] = parent_management_old_form_values($_POST, 'create');
    parent_management_handle_failure($exception, 'create');
    safe_redirect(route_url('/admin/parents/create'));
}

try {
    $createdParent = parent_management_get_parent($db, $institutionId, $parentId);
    if ($createdParent === null) {
        throw new RuntimeException('The created parent profile could not be reloaded.');
    }
    $verification = parent_email_verification_issue(
        $db,
        $institutionId,
        (int) $createdParent['user_id'],
        (string) $profileResult['data']['email'],
        (string) $profileResult['data']['full_name']
    );
    set_flash(
        $verification['sent'] ? 'success' : 'warning',
        $verification['sent']
            ? 'Parent profile created. A verification code and link were sent to the parent email address.'
            : 'Parent profile created, but the verification email was not sent. The parent can request it again from the sign-in page.'
    );
} catch (Throwable $exception) {
    log_error('parent_email_verification_delivery_failed', [
        'error_type' => get_class($exception),
        'error_code' => $exception->getCode(),
    ]);
    set_flash(
        'warning',
        'Parent profile created, but the verification email could not be sent. The parent can request it again from the sign-in page.'
    );
}
safe_redirect(route_url('/admin/parents/view') . '?id=' . $parentId);
