<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/institution-branding-service.php';

function receipt_service_issue_for_payment(
    mysqli $db,
    int $institutionId,
    int $paymentId,
    int $studentId,
    int $parentId,
    string $amount,
    string $orderNumber
): void {
    $paymentStatement = $db->prepare(
        'SELECT student_id, parent_id, amount, status, verified_at
         FROM payments
         WHERE institution_id = ? AND id = ?
         LIMIT 1 FOR UPDATE'
    );
    $paymentStatement->bind_param('ii', $institutionId, $paymentId);
    $paymentStatement->execute();
    $payment = $paymentStatement->get_result()->fetch_assoc();
    $paymentStatement->close();
    if (!is_array($payment)
        || (int) $payment['student_id'] !== $studentId
        || (int) $payment['parent_id'] !== $parentId
        || (string) $payment['amount'] !== $amount
        || $payment['status'] !== 'success'
        || !is_string($payment['verified_at'])
        || trim($payment['verified_at']) === '') {
        throw new DomainException('A verified successful payment is required to issue a receipt.');
    }

    $receiptNumber = 'R-' . $orderNumber;
    $status = 'issued';
    $statement = $db->prepare(
        'INSERT INTO receipts (
            institution_id, payment_id, student_id, parent_id, receipt_number,
            total_amount, status, issued_at
         ) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
    );
    $statement->bind_param(
        'iiiisss',
        $institutionId,
        $paymentId,
        $studentId,
        $parentId,
        $receiptNumber,
        $amount,
        $status
    );
    $statement->execute();
    $statement->close();
}

function receipt_service_pdf_format_date(?string $date, bool $includeTime = false): string
{
    if ($date === null || trim($date) === '') {
        return 'Not provided';
    }
    $timestamp = strtotime($date);
    return $timestamp === false
        ? 'Not provided'
        : date($includeTime ? 'j M Y, g:i a' : 'j M Y', $timestamp);
}

function receipt_service_pdf_logo_data_uri(?string $logoPath): ?string
{
    $logo = institution_branding_logo_file($logoPath);
    if ($logo === null) {
        return null;
    }
    $contents = file_get_contents($logo['path']);
    return is_string($contents)
        ? 'data:' . $logo['mime_type'] . ';base64,' . base64_encode($contents)
        : null;
}

function receipt_service_pdf_html(array $receipt): string
{
    $address = array_filter([
        $receipt['institution_address_line1'] ?? null,
        $receipt['institution_address_line2'] ?? null,
        $receipt['institution_city'] ?? null,
        $receipt['institution_state'] ?? null,
        $receipt['institution_postal_code'] ?? null,
        $receipt['institution_country'] ?? null,
    ], static fn (mixed $value): bool => is_string($value) && trim($value) !== '');
    $contacts = array_filter([
        $receipt['institution_phone'] ?? null,
        $receipt['institution_email'] ?? null,
    ], static fn (mixed $value): bool => is_string($value) && trim($value) !== '');
    $logo = receipt_service_pdf_logo_data_uri(
        is_string($receipt['institution_logo_path'] ?? null) ? $receipt['institution_logo_path'] : null
    );
    $currency = strtoupper((string) ($receipt['currency'] ?? 'INR'));
    $amount = number_format((float) ($receipt['total_amount'] ?? 0), 2, '.', ',');
    $status = ucwords(str_replace('_', ' ', (string) ($receipt['payment_status'] ?? '')));
    $period = is_string($receipt['academic_year_name'] ?? null) ? $receipt['academic_year_name'] : '';
    $installmentNumber = $receipt['installment_number'] ?? null;
    if (is_numeric($installmentNumber)) {
        $period = trim('Installment ' . $installmentNumber . ($period !== '' ? ' · ' . $period : ''));
    }
    if ($period === '' && is_string($receipt['installment_due_date'] ?? null)) {
        $period = 'Due ' . receipt_service_pdf_format_date($receipt['installment_due_date']);
    }
    if ($period === '') {
        $period = 'Not assigned';
    }
    $reference = $receipt['gateway_transaction_id'] ?? null;
    if (!is_string($reference) || trim($reference) === '') {
        $reference = $receipt['gateway_reference'] ?? null;
    }
    $remainingBalance = is_string($receipt['remaining_balance'] ?? null)
        ? number_format((float) $receipt['remaining_balance'], 2, '.', ',')
        : 'Not available';

    ob_start();
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page { size: A4 portrait; margin: 13mm 15mm; }
        body { color: #243247; font-family: "DejaVu Sans", sans-serif; font-size: 9pt; line-height: 1.35; }
        .header { display: table; width: 100%; border-bottom: 2px solid #254b7c; padding-bottom: 9px; }
        .logo, .institution { display: table-cell; vertical-align: middle; }
        .logo { width: 72px; }
        .logo img { max-width: 62px; max-height: 52px; }
        .institution h2 { color: #17365d; font-size: 14pt; margin: 0 0 3px; overflow-wrap: break-word; }
        .institution p { color: #66758b; font-size: 8pt; margin: 1px 0; overflow-wrap: break-word; word-break: break-word; }
        h1 { color: #17365d; font-size: 17pt; letter-spacing: .04em; margin: 17px 0 4px; text-align: center; }
        .subtitle { color: #66758b; font-size: 8pt; margin: 0 0 14px; text-align: center; }
        .receipt-number { background: #f1f5fa; border: 1px solid #dce5f0; color: #17365d; font-size: 11pt; font-weight: bold; margin-bottom: 12px; padding: 9px 11px; text-align: center; overflow-wrap: break-word; }
        .section-title { border-bottom: 1px solid #dce5f0; color: #254b7c; font-size: 10pt; margin: 13px 0 5px; padding-bottom: 3px; }
        table { border-collapse: collapse; table-layout: fixed; width: 100%; }
        td { border-bottom: 1px solid #edf0f4; padding: 6px 8px; vertical-align: top; overflow-wrap: break-word; word-wrap: break-word; word-break: break-word; }
        td.label { color: #68778d; width: 32%; }
        td.value { color: #243247; font-weight: bold; }
        .amount-box { background: #f7f9fc; border: 1px solid #dce5f0; margin-top: 12px; padding: 10px 12px; }
        .amount-box td { border: 0; padding: 3px 4px; }
        .amount { color: #17365d; font-size: 15pt; font-weight: bold; text-align: right; }
        .signatures { margin-top: 34px; }
        .signatures td { border: 0; padding: 0 18px 0 0; width: 50%; }
        .signature-line { border-top: 1px solid #77849a; padding-top: 5px; }
        .footer { border-top: 1px solid #e2e7ee; color: #8591a3; font-size: 7.5pt; margin-top: 17px; padding-top: 6px; text-align: center; }
    </style>
</head>
<body>
    <header class="header">
        <?php if ($logo !== null): ?><div class="logo"><img src="<?php echo e($logo); ?>" alt=""></div><?php endif; ?>
        <div class="institution">
            <h2><?php echo e($receipt['institution_name'] ?? ''); ?></h2>
            <?php if ($address !== []): ?><p><?php echo e(implode(', ', $address)); ?></p><?php endif; ?>
            <?php if ($contacts !== []): ?><p><?php echo e(implode(' · ', $contacts)); ?></p><?php endif; ?>
        </div>
    </header>
    <h1>FEE PAYMENT RECEIPT</h1>
    <p class="subtitle">Official confirmation of payment received</p>
    <div class="receipt-number">Receipt No. <?php echo e($receipt['receipt_number'] ?? ''); ?></div>

    <h2 class="section-title">Student and payer</h2>
    <table>
        <tr><td class="label">Student</td><td class="value"><?php echo e($receipt['student_name'] ?? ''); ?></td></tr>
        <tr><td class="label">Student ID</td><td><?php echo e($receipt['student_code'] ?? ''); ?></td></tr>
        <tr><td class="label">Parent / guardian</td><td><?php echo e($receipt['parent_name'] ?? ''); ?></td></tr>
        <tr><td class="label">Course / class / batch</td><td><?php echo e(implode(' / ', array_filter([
            $receipt['course_name'] ?? null,
            $receipt['class_name'] ?? null,
            $receipt['batch_name'] ?? null,
        ], static fn (mixed $value): bool => is_string($value) && trim($value) !== '')) ?: 'Not assigned'); ?></td></tr>
        <tr><td class="label">Fee installment / period</td><td><?php echo e($period); ?></td></tr>
    </table>

    <h2 class="section-title">Payment details</h2>
    <table>
        <tr><td class="label">Payment date</td><td><?php echo e(receipt_service_pdf_format_date(
            is_string($receipt['verified_at'] ?? null)
                ? $receipt['verified_at']
                : (is_string($receipt['captured_at'] ?? null) ? $receipt['captured_at'] : null),
            true
        )); ?></td></tr>
        <tr><td class="label">Payment method</td><td><?php echo e(ucfirst((string) ($receipt['gateway'] ?? ''))); ?></td></tr>
        <tr><td class="label">Gateway transaction / reference</td><td><?php echo e(is_string($reference) && trim($reference) !== '' ? $reference : 'Not provided'); ?></td></tr>
        <tr><td class="label">Payment status</td><td><?php echo e($status); ?></td></tr>
        <tr><td class="label">Issued date</td><td><?php echo e(receipt_service_pdf_format_date(
            is_string($receipt['issued_at'] ?? null)
                ? $receipt['issued_at']
                : (is_string($receipt['created_at'] ?? null) ? $receipt['created_at'] : null),
            true
        )); ?></td></tr>
    </table>
    <table class="amount-box">
        <tr><td class="label">Amount paid</td><td class="amount"><?php echo e($currency); ?> <?php echo e($amount); ?></td></tr>
        <tr><td class="label">Remaining fee balance</td><td class="value" style="text-align:right"><?php echo e($currency); ?> <?php echo e($remainingBalance); ?></td></tr>
    </table>

    <table class="signatures">
        <tr>
            <td><div class="signature-line">Parent / guardian</div></td>
            <td><div class="signature-line">Authorized signatory</div></td>
        </tr>
    </table>
    <div class="footer">This receipt was generated from the institution's verified payment records.</div>
</body>
</html>
    <?php
    $html = ob_get_clean();
    if (!is_string($html)) {
        throw new RuntimeException('The fee receipt could not be prepared.');
    }
    return $html;
}

function receipt_service_pdf_render_document(array $receipt): array
{
    if (!in_array($receipt['payment_status'] ?? null, ['success', 'partial_refund', 'refunded'], true)
        || !is_string($receipt['verified_at'] ?? null)
        || trim($receipt['verified_at']) === '') {
        throw new DomainException('A verified successful payment is required to generate a receipt.');
    }

    $options = new Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->setChroot(APP_ROOT);
    $pdf = new Dompdf\Dompdf($options);
    $pdf->loadHtml(receipt_service_pdf_html($receipt), 'UTF-8');
    $pdf->setPaper('A4', 'portrait');
    $pdf->render();
    $contents = $pdf->output();
    if (!str_starts_with($contents, '%PDF-')) {
        throw new RuntimeException('The fee receipt PDF could not be rendered.');
    }
    $canvas = $pdf->getCanvas();
    return [
        'contents' => $contents,
        'page_count' => $canvas->get_page_count(),
        'width_points' => $canvas->get_width(),
        'height_points' => $canvas->get_height(),
    ];
}

function receipt_service_pdf_render(array $receipt): string
{
    return receipt_service_pdf_render_document($receipt)['contents'];
}

function receipt_service_pdf_send_response(string $pdf, string $receiptNumber, bool $download): never
{
    $safeNumber = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $receiptNumber), '.-_');
    if ($safeNumber === '') {
        $safeNumber = 'receipt';
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline')
        . '; filename="fee-receipt-' . $safeNumber . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
        echo $pdf;
    }
    exit;
}