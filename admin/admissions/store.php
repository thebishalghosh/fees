<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/admission-workflow-service.php';

require_post();
require_placeholder_route_access();
require_valid_csrf_token();
$institutionId = authenticated_institution_id(['super_admin', 'admin', 'staff']);
$actor = current_user();
if ($institutionId === null || $actor === null) {
    http_response_code(403);
    exit('Institution access is unavailable for this account.');
}
$admissionId = null;
if (isset($_POST['admission_id']) && $_POST['admission_id'] !== '') {
    $parsedId = filter_var($_POST['admission_id'], FILTER_VALIDATE_INT);
    if ($parsedId === false || $parsedId < 1) {
        http_response_code(400);
        exit('Invalid admission application.');
    }
    $admissionId = (int) $parsedId;
}
$requiredPermission = $admissionId === null ? 'admissions.create' : 'admissions.edit';
if (!user_has_compatible_permission($requiredPermission, 'admissions.manage')) {
    http_response_code(403);
    exit('Forbidden.');
}
$action = $_POST['action'] ?? null;
$targetStatus = match ($action) {
    'save_draft' => 'draft',
    'submit_application' => 'submitted',
    default => null,
};
if ($targetStatus === null) {
    http_response_code(400);
    exit('Choose save draft or submit application.');
}
try {
    $db = db_connect();
    $savedId = admission_workflow_save_application(
        $db,
        $institutionId,
        (int) $actor['id'],
        (string) $actor['user_type'],
        $_POST,
        $targetStatus,
        $admissionId
    );
    $notificationResult = $targetStatus === 'submitted'
        ? admission_workflow_notify_after_commit($db, $institutionId, $savedId, 'application_submitted')
        : null;
    set_flash($notificationResult === false ? 'warning' : 'success', $notificationResult === false
        ? 'Application submitted, but its email notification could not be delivered.'
        : ($targetStatus === 'submitted'
            ? 'Application submitted for review.'
            : 'Admission application draft saved.'));
    safe_redirect('/admin/admissions/view?id=' . $savedId, 303);
} catch (InvalidArgumentException | DomainException | OutOfBoundsException $exception) {
    $oldValues = array_intersect_key($_POST, array_flip([
        'applicant_name', 'applicant_mobile', 'applicant_email', 'relationship_to_student',
        'first_name', 'middle_name', 'last_name', 'date_of_birth', 'admission_date', 'gender',
        'student_mobile', 'student_email', 'address_line1', 'address_line2', 'city', 'state',
        'postal_code', 'country', 'previous_school', 'admission_category', 'father_name',
        'father_mobile', 'father_email', 'mother_name', 'mother_mobile', 'mother_email',
        'guardian_name', 'guardian_mobile', 'guardian_email', 'enquiry_source',
        'academic_year_id', 'course_id', 'class_id', 'batch_id',
        'requested_academic_group_id', 'submission_notes',
    ]));
    $_SESSION['admission_workflow_old'] = array_filter(
        $oldValues,
        static fn (mixed $value): bool => is_string($value)
    );
    set_flash('danger', $exception->getMessage());
    safe_redirect($admissionId === null
        ? '/admin/admissions/create'
        : '/admin/admissions/edit?id=' . $admissionId, 303);
} catch (Throwable $exception) {
    log_error('admission_application_save_failed', [
        'operation' => $targetStatus,
        'error_type' => get_class($exception),
        'error_code' => $exception->getCode(),
    ]);
    set_flash('danger', 'The admission application could not be saved.');
    safe_redirect($admissionId === null
        ? '/admin/admissions/create'
        : '/admin/admissions/edit?id=' . $admissionId, 303);
}