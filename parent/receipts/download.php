<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/parent-portal-service.php';
require_once __DIR__ . '/../../services/receipt-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$receiptId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!is_int($receiptId) || $receiptId < 1) {
    http_response_code(404);
    exit('Receipt not found.');
}
$db = db_connect();
$context = parent_portal_require_current_parent($db);
$receipt = parent_portal_get_receipt(
    $db,
    (int) $context['parent']['parent_id'],
    (int) $context['user']['institution_id'],
    $receiptId
);
if ($receipt === null) {
    http_response_code(404);
    exit('Receipt not found.');
}

$pdf = receipt_service_pdf_render($receipt);
receipt_service_pdf_send_response($pdf, (string) $receipt['receipt_number'], true);
