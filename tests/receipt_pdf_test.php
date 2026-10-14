<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/receipt-service.php';
require_once __DIR__ . '/../services/parent-portal-service.php';

$checks = 0;
$failures = [];
$check = static function (bool $condition, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $label;
    }
};

$receipt = [
    'receipt_number' => 'R-TEST-1405',
    'total_amount' => '1000.00',
    'currency' => 'INR',
    'payment_status' => 'success',
    'verified_at' => '2026-10-14 12:00:00',
    'captured_at' => '2026-10-14 12:00:00',
    'gateway' => 'razorpay',
    'gateway_transaction_id' => 'pay_test1405',
    'gateway_reference' => 'order_test1405',
    'issued_at' => '2026-10-14 12:00:05',
    'student_name' => 'Anaya Example',
    'student_code' => 'STU-1405',
    'parent_name' => 'Ravi Example',
    'institution_name' => 'Example Academy',
    'institution_address_line1' => '10 School Road',
    'institution_city' => 'Pune',
    'institution_country' => 'India',
    'institution_phone' => '+91 12345 67890',
    'institution_email' => 'office@example.test',
    'course_name' => 'Science',
    'class_name' => 'Class 8',
    'batch_name' => 'Morning',
    'academic_year_name' => '2026-2027',
    'installment_number' => 2,
    'installment_due_date' => '2026-10-01',
    'remaining_balance' => '2500.00',
];

$html = receipt_service_pdf_html($receipt);
$check(
    str_contains($html, 'FEE PAYMENT RECEIPT')
        && str_contains($html, 'R-TEST-1405')
        && str_contains($html, 'Anaya Example')
        && str_contains($html, 'Ravi Example')
        && str_contains($html, 'INR 1,000.00')
        && str_contains($html, 'pay_test1405')
        && str_contains($html, '2 · 2026-2027'),
    'Render institution, receipt, student, payer, payment and installment details safely'
);
$document = receipt_service_pdf_render_document($receipt);
$check(
    str_starts_with($document['contents'], '%PDF-')
        && $document['page_count'] === 1
        && abs($document['width_points'] - 595.28) < 1
        && abs($document['height_points'] - 841.89) < 1,
    'Render a valid one-page portrait A4 receipt'
);

$unsafeReceipt = $receipt;
$unsafeReceipt['student_name'] = '<script>alert("x")</script>';
$unsafeHtml = receipt_service_pdf_html($unsafeReceipt);
$check(
    !str_contains($unsafeHtml, '<script>alert')
        && str_contains($unsafeHtml, '&lt;script&gt;'),
    'Escape untrusted receipt and student text in the PDF'
);

$invalidStatusesRejected = true;
foreach (['pending', 'failed'] as $status) {
    $invalidReceipt = $receipt;
    $invalidReceipt['payment_status'] = $status;
    $invalidReceipt['verified_at'] = null;
    $rejected = false;
    try {
        receipt_service_pdf_render($invalidReceipt);
    } catch (DomainException) {
        $rejected = true;
    }
    $invalidStatusesRejected = $invalidStatusesRejected && $rejected;
}
$check($invalidStatusesRejected, 'Reject pending and failed payments from successful receipt generation');

$logoFixture = tempnam(sys_get_temp_dir(), 'receipt-logo-');
if ($logoFixture === false) {
    throw new RuntimeException('Could not create a temporary receipt-logo fixture.');
}
$logoImage = imagecreatetruecolor(40, 24);
imagefilledrectangle($logoImage, 0, 0, 39, 23, imagecolorallocate($logoImage, 36, 75, 124));
imagepng($logoImage, $logoFixture);
imagedestroy($logoImage);
$logoPath = institution_branding_store_logo([
    'error' => UPLOAD_ERR_OK,
    'tmp_name' => $logoFixture,
    'name' => 'receipt-logo.png',
    'type' => 'image/png',
    'size' => filesize($logoFixture),
], false);
$brandedReceipt = $receipt;
$brandedReceipt['institution_logo_path'] = $logoPath;
$brandedHtml = receipt_service_pdf_html($brandedReceipt);
$brandedDocument = receipt_service_pdf_render_document($brandedReceipt);
$check(
    str_contains($brandedHtml, 'data:image/png;base64,')
        && $brandedDocument['page_count'] === 1,
    'Render the institution logo without changing the one-page receipt layout'
);
institution_branding_delete_logo_file($logoPath);
$receipt['institution_logo_path'] = null;
$noLogoDocument = receipt_service_pdf_render_document($receipt);
$check($noLogoDocument['page_count'] === 1, 'Render a text-only receipt when no institution logo exists');
unlink($logoFixture);

$downloadEndpoint = file_get_contents(__DIR__ . '/../parent/receipts/download.php');
$receiptView = file_get_contents(__DIR__ . '/../parent/receipts/view.php');
$receiptList = file_get_contents(__DIR__ . '/../parent/receipts/index.php');
$paymentList = file_get_contents(__DIR__ . '/../parent/payments/index.php');
$parentService = file_get_contents(__DIR__ . '/../services/parent-portal-service.php');
$receiptService = file_get_contents(__DIR__ . '/../services/receipt-service.php');
$check(
    is_string($downloadEndpoint)
        && str_contains($downloadEndpoint, 'parent_portal_require_current_parent($db)')
        && str_contains($downloadEndpoint, 'parent_portal_get_receipt(')
        && str_contains($downloadEndpoint, 'receipt_service_pdf_render($receipt)')
        && str_contains($downloadEndpoint, 'receipt_service_pdf_send_response('),
    'Authorize receipt PDF downloads through the authenticated parent receipt lookup'
);
$check(
    is_string($receiptView)
        && is_string($receiptList)
        && is_string($paymentList)
        && str_contains($receiptView, 'Download PDF receipt')
        && str_contains($receiptList, 'Download PDF')
        && str_contains($paymentList, 'Download receipt'),
    'Expose on-demand PDF downloads from receipt details, receipt list and payment history'
);
$check(
    is_string($parentService)
        && str_contains($parentService, 'rec.parent_id = ?')
        && str_contains($parentService, 'pay.parent_id = rec.parent_id')
        && str_contains($parentService, 'psr.parent_id = ?')
        && str_contains($parentService, 'rec.institution_id = ?')
        && str_contains($parentService, "pay.status IN ('success', 'partial_refund', 'refunded')")
        && str_contains($parentService, 'pay.verified_at IS NOT NULL'),
    'Enforce parent, active relationship, institution and verified-payment receipt scope'
);
$check(
    is_string($receiptService)
        && str_contains($receiptService, "payment['status'] !== 'success'")
        && str_contains($receiptService, "payment['verified_at']"),
    'Require verified successful payment records before issuing new receipts'
);

$db = db_connect();
$recordStatement = $db->prepare(
    'SELECT institution_id, parent_id FROM receipts WHERE id = ? LIMIT 1'
);
$existingReceiptId = 1405;
$recordStatement->bind_param('i', $existingReceiptId);
$recordStatement->execute();
$record = $recordStatement->get_result()->fetch_assoc();
$recordStatement->close();
$check(is_array($record), 'Find existing issued receipt 1405 in the approved test database');
if (is_array($record)) {
    $countStatement = $db->prepare('SELECT COUNT(*) FROM receipts');
    $countStatement->execute();
    $receiptCountBefore = (int) $countStatement->get_result()->fetch_row()[0];
    $countStatement->close();
    $countStatement = $db->prepare('SELECT COUNT(*) FROM payments');
    $countStatement->execute();
    $paymentCountBefore = (int) $countStatement->get_result()->fetch_row()[0];
    $countStatement->close();

    $savedReceipt = parent_portal_get_receipt(
        $db,
        (int) $record['parent_id'],
        (int) $record['institution_id'],
        $existingReceiptId
    );
    $otherParentReceipt = parent_portal_get_receipt(
        $db,
        (int) $record['parent_id'] + 1,
        (int) $record['institution_id'],
        $existingReceiptId
    );
    $crossInstitutionReceipt = parent_portal_get_receipt(
        $db,
        (int) $record['parent_id'],
        (int) $record['institution_id'] + 1,
        $existingReceiptId
    );
    $listedReceipts = parent_portal_list_receipts(
        $db,
        (int) $record['parent_id'],
        (int) $record['institution_id']
    );
    $check(
        is_array($savedReceipt)
            && $savedReceipt['receipt_number'] === 'R-RZP-130D3B5EFFE0B402E73008C0'
            && $savedReceipt['payment_status'] === 'success'
            && $savedReceipt['verified_at'] !== null
            && in_array($existingReceiptId, array_map('intval', array_column($listedReceipts, 'id')), true),
        'Load existing receipt 1405 from its verified payment and authorized relationship'
    );
    $check(
        $otherParentReceipt === null && $crossInstitutionReceipt === null,
        'Deny another parent and another institution access to the existing receipt'
    );
    if (is_array($savedReceipt)) {
        $savedHtml = receipt_service_pdf_html($savedReceipt);
        $savedDocument = receipt_service_pdf_render_document($savedReceipt);
        $repeatedDocument = receipt_service_pdf_render_document($savedReceipt);
        $check(
            str_contains($savedHtml, 'R-RZP-130D3B5EFFE0B402E73008C0')
                && str_contains($savedHtml, number_format((float) $savedReceipt['total_amount'], 2, '.', ','))
                && is_string($savedReceipt['gateway_transaction_id'])
                && str_contains($savedHtml, $savedReceipt['gateway_transaction_id'])
                && str_starts_with($savedDocument['contents'], '%PDF-')
                && str_starts_with($repeatedDocument['contents'], '%PDF-')
                && $savedDocument['page_count'] === 1
                && abs($savedDocument['width_points'] - 595.28) < 1
                && abs($savedDocument['height_points'] - 841.89) < 1,
            'Render and repeatedly download the existing receipt number, amount and verified gateway transaction'
        );
    }

    $countStatement = $db->prepare('SELECT COUNT(*) FROM receipts');
    $countStatement->execute();
    $receiptCountAfter = (int) $countStatement->get_result()->fetch_row()[0];
    $countStatement->close();
    $countStatement = $db->prepare('SELECT COUNT(*) FROM payments');
    $countStatement->execute();
    $paymentCountAfter = (int) $countStatement->get_result()->fetch_row()[0];
    $countStatement->close();
    $check(
        $receiptCountBefore === $receiptCountAfter && $paymentCountBefore === $paymentCountAfter,
        'Generate/download an existing receipt without inserting receipts or payments'
    );
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, 'Passed ' . $checks . ' receipt PDF checks.' . PHP_EOL);
