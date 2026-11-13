<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/page-placeholder.php';
require_once __DIR__ . '/../../../services/admission-workflow-service.php';

require_post();
require_placeholder_route_access();
require_valid_csrf_token();
if (!user_has_compatible_permission('admissions.create', 'admissions.manage')) {
    http_response_code(403);
    exit('Forbidden.');
}
$institutionId = authenticated_institution_id(['super_admin', 'admin', 'staff']);
$actor = current_user();
if ($institutionId === null || $actor === null) {
    http_response_code(403);
    exit('Institution access is unavailable for this account.');
}
try {
    $enquiryId = admission_workflow_save_enquiry(
        db_connect(),
        $institutionId,
        (int) $actor['id'],
        (string) $actor['user_type'],
        $_POST
    );
    set_flash('success', 'Admission enquiry recorded.');
    safe_redirect('/admin/admissions/enquiries/view?id=' . $enquiryId, 303);
} catch (InvalidArgumentException $exception) {
    $oldValues = array_intersect_key($_POST, array_flip([
        'applicant_name','student_name','mobile','email','relationship_to_student',
        'academic_year_id','course_id','class_id','source','status','follow_up_date','notes',
    ]));
    $_SESSION['admission_enquiry_old'] = array_filter(
        $oldValues,
        static fn (mixed $value): bool => is_string($value)
    );
    set_flash('danger', $exception->getMessage());
    safe_redirect('/admin/admissions/enquiries/create', 303);
} catch (Throwable $exception) {
    log_error('admission_enquiry_save_failed', [
        'operation' => 'create',
        'error_type' => get_class($exception),
        'error_code' => $exception->getCode(),
    ]);
    set_flash('danger', 'The admission enquiry could not be saved.');
    safe_redirect('/admin/admissions/enquiries/create', 303);
}
