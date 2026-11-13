<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/admission-workflow-service.php';

require_post();
require_placeholder_route_access();
require_valid_csrf_token();
if (!user_has_compatible_permission('admissions.edit', 'admissions.manage')) {
    http_response_code(403);
    exit('Forbidden.');
}
$institutionId = authenticated_institution_id(['super_admin', 'admin', 'staff']);
$actor = current_user();
$admissionId = filter_var($_POST['admission_id'] ?? null, FILTER_VALIDATE_INT);
if ($institutionId === null || $actor === null || $admissionId === false || $admissionId < 1) {
    http_response_code(400);
    exit('Invalid admission application.');
}
try {
    admission_workflow_upload_document(
        db_connect(),
        $institutionId,
        (int) $admissionId,
        (int) $actor['id'],
        (string) $actor['user_type'],
        is_string($_POST['document_type'] ?? null) ? $_POST['document_type'] : '',
        is_array($_FILES['document'] ?? null) ? $_FILES['document'] : []
    );
    set_flash('success', 'Document uploaded for verification.');
} catch (InvalidArgumentException | OutOfBoundsException $exception) {
    set_flash('danger', $exception->getMessage());
} catch (Throwable $exception) {
    log_error('admission_document_upload_failed', [
        'operation' => 'upload',
        'error_type' => get_class($exception),
        'error_code' => $exception->getCode(),
    ]);
    set_flash('danger', 'The document could not be uploaded.');
}
safe_redirect('/admin/admissions/view?id=' . (int) $admissionId, 303);
