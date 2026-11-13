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
$documentId = filter_var($_POST['document_id'] ?? null, FILTER_VALIDATE_INT);
if ($institutionId === null || $actor === null || $documentId === false || $documentId < 1) {
    http_response_code(400);
    exit('Invalid admission document.');
}
$document = admission_workflow_get_document(db_connect(), $institutionId, (int) $documentId);
if ($document === null) {
    http_response_code(404);
    exit('Admission document not found.');
}
try {
    admission_workflow_verify_document(
        db_connect(),
        $institutionId,
        (int) $documentId,
        (int) $actor['id'],
        (string) $actor['user_type'],
        is_string($_POST['status'] ?? null) ? $_POST['status'] : '',
        is_string($_POST['remarks'] ?? null) ? $_POST['remarks'] : ''
    );
    set_flash('success', 'Document verification status updated.');
} catch (InvalidArgumentException | OutOfBoundsException $exception) {
    set_flash('danger', $exception->getMessage());
} catch (Throwable $exception) {
    log_error('admission_document_verification_failed', [
        'operation' => 'verify',
        'error_type' => get_class($exception),
        'error_code' => $exception->getCode(),
    ]);
    set_flash('danger', 'The document status could not be updated.');
}
safe_redirect('/admin/admissions/view?id=' . (int) $document['admission_id'], 303);
