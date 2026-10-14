<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/student-management-service.php';

require_post();
require_placeholder_route_access();
require_valid_csrf_token();
$user = current_user();
$institutionId = student_management_institution_id();
$studentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!is_int($studentId) || $studentId < 1) {
    set_flash('danger', 'The student record was not found.');
    safe_redirect(route_url('/admin/students/index'));
}
$db = db_connect();
$existingStudent = student_management_get_student($db, $institutionId, $studentId);
if ($existingStudent === null) {
    set_flash('danger', 'The student record was not found in your institution.');
    safe_redirect(route_url('/admin/students/index'));
}
$profileResult = student_management_validate_profile($_POST, false);
$errors = $profileResult['errors'];
$fieldErrors = $profileResult['field_errors'];
foreach ([
    'date_of_birth',
    'gender',
    'academic_year_id',
    'course_id',
    'class_id',
    'batch_id',
] as $optionalField) {
    if (!array_key_exists($optionalField, $_POST)) {
        $profileResult['data'][$optionalField] = $existingStudent[$optionalField] ?? null;
    }
}
if (($_POST['manage_parent_links'] ?? null) === '1') {
    try {
        $parentIds = student_management_parent_ids($_POST['parent_ids'] ?? []);
    } catch (InvalidArgumentException $exception) {
        $parentIds = $existingStudent['parent_ids'];
        $errors[] = $exception->getMessage();
        $fieldErrors['parent_ids'] = $exception->getMessage();
    }
} else {
    $parentIds = $existingStudent['parent_ids'];
}
if ($errors !== []) {
    $_SESSION['student_management_old'] = student_management_old_form_values($_POST, 'edit', $studentId);
    $_SESSION['student_management_old']['field_errors'] = $fieldErrors;
    if (($_POST['manage_parent_links'] ?? null) !== '1') {
        $_SESSION['student_management_old']['parent_ids'] = array_map('strval', $existingStudent['parent_ids']);
    }
    set_flash('danger', implode(' ', $errors));
    safe_redirect(route_url('/admin/students/edit') . '?id=' . $studentId);
}

try {
    student_management_update(
        $db,
        $institutionId,
        $studentId,
        (int) $user['id'],
        (string) $user['user_type'],
        $profileResult['data'],
        $parentIds
    );
    set_flash('success', 'Student record updated. Existing fee and relationship history was retained.');
    safe_redirect(route_url('/admin/students/view') . '?id=' . $studentId);
} catch (Throwable $exception) {
    $_SESSION['student_management_old'] = student_management_old_form_values($_POST, 'edit', $studentId);
    $_SESSION['student_management_old']['field_errors'] = student_management_failure_field_errors($exception);
    if (($_POST['manage_parent_links'] ?? null) !== '1') {
        $_SESSION['student_management_old']['parent_ids'] = array_map('strval', $existingStudent['parent_ids']);
    }
    student_management_handle_failure($exception, 'update');
    safe_redirect(route_url('/admin/students/edit') . '?id=' . $studentId);
}
