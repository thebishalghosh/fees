<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/admin-panel-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$receiptId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!is_int($receiptId) || $receiptId < 1) {
    http_response_code(404);
    exit('Receipt not found.');
}
$receipt = admin_panel_receipt(db_connect(), admin_panel_institution_id(), $receiptId);
if ($receipt === null || !is_string($receipt['file_path']) || trim($receipt['file_path']) === '') {
    http_response_code(404);
    exit('Receipt file not found.');
}
$projectRoot = dirname(__DIR__, 2);
$storageRoot = realpath($projectRoot . DIRECTORY_SEPARATOR . 'storage');
$storedPath = (string) $receipt['file_path'];
if (preg_match('/^[A-Za-z]:/', $storedPath) === 1
    || str_starts_with($storedPath, '/')
    || str_starts_with($storedPath, '\\')) {
    http_response_code(404);
    exit('Receipt file not found.');
}
$relativePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $storedPath);
$filePath = realpath($projectRoot . DIRECTORY_SEPARATOR . $relativePath);
if ($storageRoot === false || $filePath === false
    || !str_starts_with($filePath, $storageRoot . DIRECTORY_SEPARATOR)
    || !is_file($filePath) || !is_readable($filePath)) {
    http_response_code(404);
    exit('Receipt file not found.');
}
$fileName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($filePath));
header('Content-Type: application/octet-stream');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . (string) filesize($filePath));
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
    readfile($filePath);
}
