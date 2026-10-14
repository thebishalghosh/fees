<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/parent-portal-service.php';
require_once __DIR__ . '/../../services/admission-form-pdf-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();

$studentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!is_int($studentId) || $studentId < 1) {
    http_response_code(404);
    exit('Student not found.');
}

$db = db_connect();
$context = parent_portal_require_current_parent($db);
$user = $context['user'];
$parent = $context['parent'];
$institutionId = (int) $user['institution_id'];
$parentId = (int) $parent['parent_id'];
$isDownload = isset($_GET['download']) && is_string($_GET['download']) && $_GET['download'] === '1';

try {
    $authorizedStudent = parent_portal_get_student($db, $parentId, $institutionId, $studentId);
    if ($authorizedStudent === null) {
        http_response_code(404);
        exit('Student not found.');
    }
    $student = student_management_get_student($db, $institutionId, $studentId);
    if ($student === null) {
        http_response_code(404);
        exit('Student not found.');
    }
    $student['parents'] = array_values(array_filter(
        $student['parents'],
        static fn (array $linkedParent): bool => (int) $linkedParent['id'] === $parentId
    ));
    $pdf = student_admission_pdf_render($student);
} catch (Throwable $exception) {
    log_error('parent_admission_pdf_failed', [
        'operation' => $isDownload ? 'download' : 'view',
        'error_type' => get_class($exception),
        'error_code' => $exception->getCode(),
    ]);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('The registration form PDF could not be generated. Please try again.');
}

student_admission_pdf_send_response($pdf, (string) $student['student_code'], $isDownload);