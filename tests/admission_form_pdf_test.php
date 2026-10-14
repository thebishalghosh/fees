<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/admission-form-pdf-service.php';

$checks = 0;
$failures = [];
$check = static function (bool $condition, string $message) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
};

$student = [
    'institution_name' => 'Example Learning Centre',
    'institution_email' => 'office@example.test',
    'institution_phone' => '+91 12345 67890',
    'institution_address_line1' => '10 School Road',
    'institution_address_line2' => null,
    'institution_city' => 'Pune',
    'institution_state' => 'Maharashtra',
    'institution_postal_code' => '411001',
    'institution_country' => 'India',
    'institution_logo_path' => null,
    'student_code' => 'STU-2042',
    'full_name' => 'Anaya Example',
    'date_of_birth' => '2012-04-18',
    'gender' => 'female',
    'course_name' => 'Science',
    'class_name' => 'Class 8',
    'batch_name' => 'Morning',
    'academic_year_name' => '2026-2027',
    'admission_date' => '2026-10-01',
    'status' => 'admitted',
    'created_at' => '2026-10-01 09:30:00',
    'parents' => [[
        'full_name' => 'Ravi Example',
        'relationship_type' => 'father',
        'mobile' => '+91 98765 43210',
        'email' => 'ravi@example.test',
        'address_line1' => '10 School Road',
        'address_line2' => null,
        'city' => 'Pune',
        'state' => 'Maharashtra',
        'postal_code' => '411001',
        'country' => 'India',
    ]],
];

$html = student_admission_pdf_html($student);
$check(str_contains($html, 'Student Registration / Admission Form'), 'Render the admission form title');
$check(str_contains($html, 'STU-2042') && str_contains($html, 'Anaya Example'), 'Render the saved student ID and name');
$check(str_contains($html, 'Ravi Example') && str_contains($html, 'ravi@example.test'), 'Render saved parent contact details');
$check(str_contains($html, 'Example Learning Centre') && str_contains($html, '10 School Road'), 'Render configured institution details');
$check(str_contains($html, 'Science') && str_contains($html, '2026-2027'), 'Render academic placement and year');
$check(str_contains($html, '1 Oct 2026') && str_contains($html, 'Admitted'), 'Format the admission date and current status');

$unsafeStudent = $student;
$unsafeStudent['full_name'] = '<script>alert("unsafe")</script>';
$unsafeHtml = student_admission_pdf_html($unsafeStudent);
$check(!str_contains($unsafeHtml, '<script>alert') && str_contains($unsafeHtml, '&lt;script&gt;'), 'Escape user-provided student text in the PDF HTML');

$pdf = student_admission_pdf_render($student);
$check(str_starts_with($pdf, '%PDF-'), 'Render a valid PDF document from a saved student record');
$check(student_admission_pdf_format_date(null) === 'Not provided', 'Render missing optional dates gracefully');
$document = student_admission_pdf_render_document($student);
$check($document['page_count'] === 1, 'Render a normal admission form on exactly one page');
$check(
    abs($document['width_points'] - 595.28) < 1
        && abs($document['height_points'] - 841.89) < 1,
    'Render the document on portrait A4 dimensions'
);

$longStudent = $student;
$longStudent['institution_name'] = str_repeat('Long Institution Name ', 10);
$longStudent['institution_address_line1'] = str_repeat('Long institution street address ', 8);
$longStudent['institution_email'] = 'office.' . str_repeat('long-address-segment-', 5) . 'example.test';
$longStudent['full_name'] = str_repeat('Long Student Name ', 8);
$longStudent['course_name'] = str_repeat('Advanced course title ', 8);
$longStudent['class_name'] = str_repeat('Class description ', 6);
$longStudent['batch_name'] = str_repeat('Batch description ', 5);
$longStudent['parents'][0]['full_name'] = str_repeat('Guardian Full Name ', 7);
$longStudent['parents'][0]['email'] = 'guardian.' . str_repeat('long-address-segment-', 5) . 'example.test';
$longStudent['parents'][0]['address_line1'] = str_repeat('Guardian residential address ', 8);
$longHtml = student_admission_pdf_html($longStudent);
$check(
    str_contains($longHtml, str_repeat('Long Student Name ', 8))
        && str_contains($longHtml, str_repeat('Guardian residential address ', 8))
        && str_contains($longHtml, 'word-break: break-word'),
    'Retain long field values and apply wrapping to prevent horizontal overflow'
);
$longDocument = student_admission_pdf_render_document($longStudent);
$check(
    $longDocument['page_count'] === 1
        && abs($longDocument['width_points'] - 595.28) < 1
        && abs($longDocument['height_points'] - 841.89) < 1,
    'Keep long-value documents wrapped within one portrait A4 page'
);

$logoFixture = tempnam(sys_get_temp_dir(), 'admission-pdf-logo-');
if ($logoFixture === false) {
    throw new RuntimeException('Could not create a temporary admission PDF logo fixture.');
}
$logoImage = imagecreatetruecolor(36, 20);
imagefilledrectangle($logoImage, 0, 0, 35, 19, imagecolorallocate($logoImage, 36, 75, 124));
imagepng($logoImage, $logoFixture);
imagedestroy($logoImage);
$logoPath = institution_branding_store_logo([
    'error' => UPLOAD_ERR_OK,
    'tmp_name' => $logoFixture,
    'name' => 'logo.png',
    'type' => 'image/png',
    'size' => filesize($logoFixture),
], false);
$brandedStudent = $student;
$brandedStudent['institution_logo_path'] = $logoPath;
$brandedHtml = student_admission_pdf_html($brandedStudent);
$brandedDocument = student_admission_pdf_render_document($brandedStudent);
$check(
    str_contains($brandedHtml, 'data:image/png;base64,')
        && $brandedDocument['page_count'] === 1
        && abs($brandedDocument['width_points'] - 595.28) < 1
        && abs($brandedDocument['height_points'] - 841.89) < 1,
    'Render the saved institution logo while preserving a single portrait A4 page'
);
institution_branding_delete_logo_file($logoPath);
unlink($logoFixture);

$parentRoute = file_get_contents(__DIR__ . '/../parent/admissions/download-pdf.php');
$adminRoute = file_get_contents(__DIR__ . '/../admin/students/admission-form.php');
$authSource = file_get_contents(__DIR__ . '/../includes/auth.php');
$parentUi = file_get_contents(__DIR__ . '/../parent/child-details.php');
$portalService = file_get_contents(__DIR__ . '/../services/parent-portal-service.php');
$check(
    is_string($parentRoute)
        && str_contains($parentRoute, 'parent_portal_require_current_parent($db)')
        && str_contains($parentRoute, 'parent_portal_get_student($db, $parentId, $institutionId, $studentId)')
        && str_contains($parentRoute, 'student_admission_pdf_send_response($pdf'),
    'Require authenticated parent context and authorized child access before returning a PDF'
);
$check(
    is_string($parentRoute)
        && str_contains($parentRoute, "\$parent['parent_id']")
        && !str_contains($parentRoute, "\$_GET['parent_id']")
        && str_contains($parentRoute, "['id'] === \$parentId"),
    'Derive parent identity from session context and limit guardian information to that parent'
);
$check(
    is_string($portalService)
        && str_contains($portalService, "WHERE p.id = ? AND p.institution_id = ?")
        && str_contains($portalService, "\$statement->bind_param('iii', \$parentId, \$institutionId, \$studentId)")
        && str_contains($portalService, "psr.status = 'active'")
        && str_contains($portalService, 'AND psr.can_view_academics = 1 AND s.id = ?'),
    'Authorize only an active same-institution relationship that allows academic details'
);
$check(
    is_string($authSource)
        && str_contains($authSource, "\$scriptPath === '/parent/admissions/download-pdf.php'")
        && str_contains($authSource, "\$permission = 'students.view'")
        && is_string($adminRoute)
        && str_contains($adminRoute, 'require_placeholder_route_access()')
        && str_contains($adminRoute, 'student_management_get_student'),
    'Protect the parent endpoint with students.view and retain institution-scoped admin authorization'
);
$check(
    is_string($adminRoute)
        && str_contains($adminRoute, '$pdf = student_admission_pdf_render($student);')
        && is_string($parentRoute)
        && str_contains($parentRoute, '$pdf = student_admission_pdf_render($student);')
        && str_contains($adminRoute, 'student_admission_pdf_send_response($pdf')
        && str_contains($parentRoute, 'student_admission_pdf_send_response($pdf')
        && str_contains(file_get_contents(__DIR__ . '/../services/admission-form-pdf-service.php'), 'echo $pdf;'),
    'Use the same rendered document bytes for inline display and download responses'
);
$check(
    is_string($parentUi)
        && str_contains($parentUi, 'View Registration Form')
        && str_contains($parentUi, 'Download PDF')
        && str_contains($parentUi, "route_url('/parent/admissions/download-pdf')"),
    'Provide view and download actions from the authorized child details page'
);

if ($failures !== []) {
    fwrite(STDERR, 'FAIL: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'PASS: ' . $checks . ' admission form PDF checks.' . PHP_EOL);
