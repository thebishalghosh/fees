<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/student-management-service.php';

require_post();
require_placeholder_route_access();
require_valid_csrf_token();
$user = current_user();
$studentId = isset($_POST['student_id']) && is_string($_POST['student_id'])
    ? filter_var($_POST['student_id'], FILTER_VALIDATE_INT)
    : false;
if ($studentId === false || $studentId < 1) {
    set_flash('danger', 'The student record was not found.');
    safe_redirect(route_url('/admin/students/index'));
}

try {
    $newStatus = student_management_toggle_status(
        db_connect(),
        student_management_institution_id(),
        $studentId,
        (int) $user['id'],
        (string) $user['user_type']
    );
    set_flash('success', $newStatus === 'active'
        ? 'Student activated. Existing academic, parent and fee history was preserved.'
        : 'Student deactivated. Existing academic, parent and fee history was preserved.');
} catch (Throwable $exception) {
    student_management_handle_failure($exception, 'toggle_status');
}
safe_redirect(route_url('/admin/students/view') . '?id=' . $studentId);
