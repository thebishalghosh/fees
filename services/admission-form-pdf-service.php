<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/student-management-service.php';
require_once __DIR__ . '/institution-branding-service.php';

function student_admission_pdf_logo_data_uri(?string $logoPath): ?string
{
    $logoFile = institution_branding_logo_file($logoPath);
    if ($logoFile === null) {
        return null;
    }

    $contents = file_get_contents($logoFile['path']);
    if (!is_string($contents)) {
        return null;
    }

    return 'data:' . $logoFile['mime_type'] . ';base64,' . base64_encode($contents);
}

function student_admission_pdf_format_date(?string $date, bool $includeTime = false): string
{
    if ($date === null || trim($date) === '') {
        return 'Not provided';
    }

    $timestamp = strtotime($date);
    return $timestamp === false
        ? 'Not provided'
        : date($includeTime ? 'j M Y, g:i a' : 'j M Y', $timestamp);
}

function student_admission_pdf_html(array $student): string
{
    $institutionAddress = array_filter([
        $student['institution_address_line1'] ?? null,
        $student['institution_address_line2'] ?? null,
        $student['institution_city'] ?? null,
        $student['institution_state'] ?? null,
        $student['institution_postal_code'] ?? null,
        $student['institution_country'] ?? null,
    ], static fn (mixed $value): bool => is_string($value) && trim($value) !== '');
    $institutionContacts = array_filter([
        $student['institution_phone'] ?? null,
        $student['institution_email'] ?? null,
    ], static fn (mixed $value): bool => is_string($value) && trim($value) !== '');
    $logoDataUri = student_admission_pdf_logo_data_uri(
        is_string($student['institution_logo_path'] ?? null) ? $student['institution_logo_path'] : null
    );
    $status = ucwords(str_replace('_', ' ', (string) ($student['status'] ?? '')));
    $gender = ($student['gender'] ?? null) === null
        ? 'Not specified'
        : ucwords(str_replace('_', ' ', (string) $student['gender']));
    $parents = is_array($student['parents'] ?? null) ? $student['parents'] : [];

    ob_start();
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page { size: A4 portrait; margin: 11mm 13mm 12mm; }
        body { color: #243247; font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; line-height: 1.25; }
        .institution { border-bottom: 1.5px solid #254b7c; margin-bottom: 9px; padding-bottom: 6px; }
        .brand { display: table; table-layout: fixed; width: 100%; }
        .logo, .brand-copy { display: table-cell; vertical-align: middle; }
        .logo { width: 54px; }
        .logo img { max-height: 42px; max-width: 48px; }
        .institution-name { color: #17365d; font-size: 12pt; font-weight: bold; margin: 0; }
        .institution-detail { color: #66758b; font-size: 7.2pt; margin: 1px 0 0; overflow-wrap: break-word; word-wrap: break-word; }
        .document-title { color: #17365d; font-size: 12pt; font-weight: bold; margin: 7px 0 3px; text-align: center; }
        .document-subtitle { color: #66758b; font-size: 7.5pt; margin: 0 0 7px; text-align: center; }
        .reference { background: #f1f5fa; border: 1px solid #dce5f0; color: #17365d; font-size: 8.5pt; font-weight: bold; margin-bottom: 7px; padding: 5px 7px; }
        h2 { border-bottom: 1px solid #dce5f0; color: #254b7c; font-size: 9pt; margin: 8px 0 4px; padding-bottom: 2px; }
        table { border-collapse: collapse; table-layout: fixed; width: 100%; }
        td { padding: 3px 5px; vertical-align: top; }
        .details td { border-bottom: 1px solid #edf0f4; }
        .label { color: #68778d; font-size: 7.2pt; width: 27%; }
        .value { color: #243247; font-weight: bold; }
        .details td:not(.label), .guardian td:not(.label) { overflow-wrap: break-word; word-wrap: break-word; word-break: break-word; }
        .columns { border-spacing: 7px 0; margin: 0 -7px; width: calc(100% + 14px); }
        .columns > tbody > tr > td { padding: 0; vertical-align: top; width: 50%; }
        .guardian { border: 1px solid #e0e6ee; margin-bottom: 5px; }
        .guardian td { padding: 3px 5px; }
        .muted { color: #68778d; font-size: 7.5pt; }
        .signatures { margin-top: 16px; page-break-inside: avoid; }
        .signatures td { padding: 0 14px 0 0; width: 50%; }
        .signature-line { border-top: 1px solid #77849a; margin-top: 21px; padding-top: 4px; }
        .generated { border-top: 1px solid #e2e7ee; color: #8591a3; font-size: 7pt; margin-top: 8px; padding-top: 4px; }
    </style>
</head>
<body>
    <header class="institution">
        <div class="brand">
            <?php if ($logoDataUri !== null): ?>
                <div class="logo"><img src="<?php echo e($logoDataUri); ?>" alt=""></div>
            <?php endif; ?>
            <div class="brand-copy">
                <p class="institution-name"><?php echo e($student['institution_name'] ?? ''); ?></p>
                <?php if ($institutionAddress !== []): ?><p class="institution-detail"><?php echo e(implode(', ', $institutionAddress)); ?></p><?php endif; ?>
                <?php if ($institutionContacts !== []): ?><p class="institution-detail"><?php echo e(implode(' · ', $institutionContacts)); ?></p><?php endif; ?>
            </div>
        </div>
    </header>
    <h1 class="document-title">Student Registration / Admission Form</h1>
    <p class="document-subtitle">Official student registration record</p>
    <div class="reference">Student / Admission ID: <?php echo e($student['student_code'] ?? ''); ?></div>

    <table class="columns">
        <tr>
            <td>
                <h2>Student details</h2>
                <table class="details">
                    <tr><td class="label">Full name</td><td class="value"><?php echo e($student['full_name'] ?? ''); ?></td></tr>
                    <tr><td class="label">Date of birth</td><td><?php echo e(student_admission_pdf_format_date(is_string($student['date_of_birth'] ?? null) ? $student['date_of_birth'] : null)); ?></td></tr>
                    <tr><td class="label">Gender</td><td><?php echo e($gender); ?></td></tr>
                </table>
                <h2>Admission details</h2>
                <table class="details">
                    <tr><td class="label">Course</td><td><?php echo e($student['course_name'] ?? 'Not assigned'); ?></td></tr>
                    <tr><td class="label">Class / batch</td><td><?php echo e(($student['class_name'] ?? 'Not assigned') . ' / ' . ($student['batch_name'] ?? 'Not assigned')); ?></td></tr>
                    <tr><td class="label">Academic year</td><td><?php echo e($student['academic_year_name'] ?? 'Not assigned'); ?></td></tr>
                    <tr><td class="label">Admission date</td><td><?php echo e(student_admission_pdf_format_date(is_string($student['admission_date'] ?? null) ? $student['admission_date'] : null)); ?></td></tr>
                    <tr><td class="label">Current status</td><td><?php echo e($status); ?></td></tr>
                    <tr><td class="label">Registration date</td><td><?php echo e(student_admission_pdf_format_date(is_string($student['created_at'] ?? null) ? $student['created_at'] : null, true)); ?></td></tr>
                </table>
            </td>
            <td>
                <h2>Parent / guardian</h2>
                <?php if ($parents === []): ?>
                    <p class="muted">No parent or guardian profile is linked to this student.</p>
                <?php else: ?>
                    <?php foreach ($parents as $parent): ?>
            <?php
            $parentAddress = array_filter([
                $parent['address_line1'] ?? null,
                $parent['address_line2'] ?? null,
                $parent['city'] ?? null,
                $parent['state'] ?? null,
                $parent['postal_code'] ?? null,
                $parent['country'] ?? null,
            ], static fn (mixed $value): bool => is_string($value) && trim($value) !== '');
            ?>
            <table class="guardian">
                <tr><td colspan="2" class="value"><?php echo e($parent['full_name'] ?? ''); ?></td></tr>
                <tr><td class="label">Relationship</td><td><?php echo e(ucfirst((string) ($parent['relationship_type'] ?? 'guardian'))); ?></td></tr>
                <tr><td class="label">Mobile</td><td><?php echo e($parent['mobile'] ?? 'Not provided'); ?></td></tr>
                <tr><td class="label">Email</td><td><?php echo e($parent['email'] ?? 'Not provided'); ?></td></tr>
                <tr><td class="label">Address</td><td><?php echo e($parentAddress === [] ? 'Not provided' : implode(', ', $parentAddress)); ?></td></tr>
            </table>
                    <?php endforeach; ?>
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <table class="signatures">
        <tr>
            <td><div class="signature-line">Parent / Guardian Signature</div></td>
            <td><div class="signature-line">Authorized Institution Representative</div></td>
        </tr>
    </table>
    <p class="generated">Generated from the saved institution student record.</p>
</body>
</html>
    <?php
    $html = ob_get_clean();
    if (!is_string($html)) {
        throw new RuntimeException('The admission form could not be prepared.');
    }

    return $html;
}

function student_admission_pdf_render_document(array $student): array
{
    $options = new Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->setChroot(APP_ROOT);

    $pdf = new Dompdf\Dompdf($options);
    $pdf->loadHtml(student_admission_pdf_html($student), 'UTF-8');
    $pdf->setPaper('A4', 'portrait');
    $pdf->render();
    $contents = $pdf->output();
    if (!str_starts_with($contents, '%PDF-')) {
        throw new RuntimeException('The admission form PDF could not be rendered.');
    }

    $canvas = $pdf->getCanvas();
    return [
        'contents' => $contents,
        'page_count' => $canvas->get_page_count(),
        'width_points' => $canvas->get_width(),
        'height_points' => $canvas->get_height(),
    ];
}

function student_admission_pdf_render(array $student): string
{
    return student_admission_pdf_render_document($student)['contents'];
}

function student_admission_pdf_send_response(string $pdf, string $studentCode, bool $download): never
{
    $safeStudentCode = preg_replace('/[^A-Za-z0-9._-]+/', '-', $studentCode);
    $safeStudentCode = trim((string) $safeStudentCode, '.-_');
    if ($safeStudentCode === '') {
        $safeStudentCode = 'student';
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline')
        . '; filename="admission-form-' . $safeStudentCode . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
        echo $pdf;
    }
    exit;
}
