<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/admission-workflow-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$institutionId = authenticated_institution_id(['super_admin', 'admin', 'staff']);
$documentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($institutionId === null || !is_int($documentId) || $documentId < 1) {
    http_response_code(404);
    exit('Admission document not found.');
}
$document = admission_workflow_get_document(db_connect(), $institutionId, $documentId);
if ($document === null || !is_string($document['storage_key'])
    || preg_match('/^[a-f0-9]{64}$/D', $document['storage_key']) !== 1
    || !in_array($document['original_extension'], ['pdf', 'jpg', 'png', 'webp'], true)) {
    http_response_code(404);
    exit('Admission document not found.');
}
$directory = admission_workflow_document_storage_directory();
$filePath = realpath($directory . DIRECTORY_SEPARATOR . $document['storage_key'] . '.' . $document['original_extension']);
if ($filePath === false
    || !str_starts_with(strtolower($filePath), strtolower($directory . DIRECTORY_SEPARATOR))
    || !is_file($filePath)) {
    http_response_code(404);
    exit('Admission document file not found.');
}
$mimeTypes = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('Content-Type: ' . $mimeTypes[$document['original_extension']]);
header('Content-Length: ' . (string) filesize($filePath));
header('Content-Disposition: inline; filename="admission-document.' . $document['original_extension'] . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
    readfile($filePath);
}
exit;
