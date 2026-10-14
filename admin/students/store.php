<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/student-management-service.php';

require_post();
require_placeholder_route_access();
require_valid_csrf_token();
$user = current_user();
$institutionId = student_management_institution_id();
$profileResult = student_management_validate_profile($_POST);
$errors = $profileResult['errors'];
$fieldErrors = $profileResult['field_errors'];
try {
    $parentIds = student_management_parent_ids($_POST['parent_ids'] ?? []);
} catch (InvalidArgumentException $exception) {
    $parentIds = [];
    $errors[] = $exception->getMessage();
    $fieldErrors['parent_ids'] = $exception->getMessage();
}
if ($errors !== []) {
    $_SESSION['student_management_old'] = student_management_old_form_values($_POST, 'create');
    $_SESSION['student_management_old']['field_errors'] = $fieldErrors;
    set_flash('danger', implode(' ', $errors));
    safe_redirect(route_url('/admin/students/create'));
}

try {
    $studentId = student_management_create(
        db_connect(),
        $institutionId,
        (int) $user['id'],
        (string) $user['user_type'],
        $profileResult['data'],
        $parentIds
    );
    set_flash('success', 'Student record created.');
    safe_redirect(route_url('/admin/students/view') . '?id=' . $studentId);
} catch (Throwable $exception) {
    $_SESSION['student_management_old'] = student_management_old_form_values($_POST, 'create');
    $_SESSION['student_management_old']['field_errors'] = student_management_failure_field_errors($exception);
    student_management_handle_failure($exception, 'create');
    safe_redirect(route_url('/admin/students/create'));
}
