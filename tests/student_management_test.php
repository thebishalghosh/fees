<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/student-management-service.php';

$checks = 0;
$failures = [];
$check = static function (bool $condition, string $message) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
};

$validProfile = [
    'full_name' => 'Test Student',
    'student_code' => 'STU-1001',
    'date_of_birth' => '2012-04-18',
    'gender' => 'other',
    'admission_date' => '2026-10-13',
    'academic_year_id' => '2',
    'course_id' => '3',
    'class_id' => '4',
    'batch_id' => '5',
    'status' => 'admitted',
];
$validResult = student_management_validate_profile($validProfile);
$check($validResult['errors'] === [], 'Accept a valid student profile for creation');
$check($validResult['data']['student_code'] === 'STU-1001', 'Retain the institution-level student identifier');
$check($validResult['data']['batch_id'] === 5, 'Normalize valid academic selector IDs');

$missingRequired = student_management_validate_profile([
    'full_name' => '',
    'student_code' => '',
    'admission_date' => '',
]);
$check(count($missingRequired['errors']) >= 3, 'Reject missing required name, student ID, and admission date');
$check(isset($missingRequired['field_errors']['full_name'], $missingRequired['field_errors']['student_code'], $missingRequired['field_errors']['admission_date']), 'Return field-specific messages for required student details');

$badDateInput = $validProfile;
$badDateInput['date_of_birth'] = '2026-02-31';
$badDate = student_management_validate_profile($badDateInput);
$check(in_array('Enter a valid date of birth or leave it blank.', $badDate['errors'], true), 'Reject malformed dates of birth');
$check(!student_management_valid_date('0999-12-31'), 'Reject dates outside MySQL DATE range');

$malformedInput = $validProfile;
$malformedInput['gender'] = ['male'];
$check(in_array('One or more student details have an unsupported value.', student_management_validate_profile($malformedInput)['errors'], true), 'Reject non-scalar profile input');

$badGender = $validProfile;
$badGender['gender'] = 'unknown';
$check(in_array('Choose a supported gender value.', student_management_validate_profile($badGender)['errors'], true), 'Reject gender values outside the schema enum');

$badStatus = $validProfile;
$badStatus['status'] = 'suspended';
$check(in_array('Choose a supported initial student status.', student_management_validate_profile($badStatus)['errors'], true), 'Reject initial statuses outside the supported workflow');

$badBatch = $validProfile;
$badBatch['course_id'] = '';
$badBatch['class_id'] = '';
$badBatchResult = student_management_validate_profile($badBatch);
$check(in_array('Choose the course and class associated with the selected batch.', $badBatchResult['errors'], true), 'Require a course and class when a batch is selected');
$check(isset($badBatchResult['field_errors']['batch_id']), 'Associate cross-field batch validation with the batch field');

$updateResult = student_management_validate_profile($validProfile, false);
$check($updateResult['errors'] === [], 'Validate editable student fields without accepting an edit-time status overwrite');
$check($updateResult['data']['status'] === 'prospect', 'Leave status out of editable profile data');

$check(student_management_parent_ids(['4', 7, '4']) === [4, 7], 'Normalize and de-duplicate parent link IDs');
$invalidParentIdsRejected = false;
try {
    student_management_parent_ids('4');
} catch (InvalidArgumentException) {
    $invalidParentIdsRejected = true;
}
$check($invalidParentIdsRejected, 'Reject malformed parent relationship input');
$duplicateCodeFieldErrors = student_management_failure_field_errors(
    new InvalidArgumentException('That student ID is already in use in this institution.')
);
$check(isset($duplicateCodeFieldErrors['student_code']), 'Show duplicate student ID errors at the student ID field');

[$where, $types, $values] = student_management_prepare_list_filters(
    12,
    'A%_!B',
    'active',
    31,
    42
);
$check(str_contains($where, 's.institution_id = ?'), 'Scope student filters to an institution');
$check(str_contains($where, 's.class_id = ?') && str_contains($where, 's.batch_id = ?'), 'Support class and batch filters');
$check(str_contains($where, 'search_parent.email LIKE ?') && str_contains($where, 'search_parent.mobile LIKE ?'), 'Search linked parent email and mobile because students have no contact columns');
$check($types === 'isiisssss' && count($values) === 9, 'Bind institution, status, class, batch and all search terms');
$check(in_array('%A!%!_!!B%', $values, true), 'Escape SQL wildcard characters in the search text');

$serviceSource = file_get_contents(__DIR__ . '/../services/student-management-service.php');
$auditSource = file_get_contents(__DIR__ . '/../services/audit-service.php');
$routeFiles = [
    'index.php', 'create.php', 'store.php', 'edit.php', 'update.php', 'view.php', 'toggle-status.php',
    'admission-form.php',
];
$routeSources = [];
foreach ($routeFiles as $file) {
    $routeSources[$file] = file_get_contents(__DIR__ . '/../admin/students/' . $file);
}
$allSourcesReadable = is_string($serviceSource) && is_string($auditSource);
foreach ($routeSources as $source) {
    $allSourcesReadable = $allSourcesReadable && is_string($source);
}
$check($allSourcesReadable, 'Read student service, route, and audit sources for static safety checks');
if ($allSourcesReadable) {
    $check(str_contains($serviceSource, 'WHERE institution_id = ? AND student_code = ?'), 'Check duplicate student IDs within the institution');
    $check(str_contains($serviceSource, 'AND course_id = ? AND class_id = ?'), 'Reject batches that do not match the selected course and class');
    $check(str_contains($serviceSource, 'p.institution_id = ? AND p.id IN'), 'Scope parent relationship validation to the institution');
    $check(str_contains($serviceSource, 'WHERE s.institution_id = ? AND s.id = ? LIMIT 1'), 'Reject view and edit lookups outside the current institution');
    $check(str_contains($serviceSource, "JOIN roles r ON r.id = u.role_id AND r.name = 'parent'"), 'Only accept linked accounts with the schema-defined parent role');
    $check(str_contains($serviceSource, 'Graduated or withdrawn students cannot be toggled'), 'Prevent status toggles for terminal student states');
    $check(str_contains($serviceSource, "SET status = 'revoked'") && !str_contains($serviceSource, 'DELETE FROM parent_student_relationships'), 'Revoke parent links instead of deleting relationship history');
    $check(str_contains($serviceSource, "'student.created'") && str_contains($serviceSource, "'student.updated'") && str_contains($serviceSource, "'student.status_changed'"), 'Audit student create, update, and status changes');
    $check(str_contains($auditSource, 'INSERT INTO audit_logs'), 'Reuse the existing audit log table');
    foreach (['store.php', 'update.php', 'toggle-status.php'] as $file) {
        $check(str_contains($routeSources[$file], 'require_valid_csrf_token()'), 'Require CSRF checks on ' . $file);
        $check(str_contains($routeSources[$file], 'require_placeholder_route_access()'), 'Require existing permission checks on ' . $file);
    }
    foreach (['index.php', 'create.php', 'edit.php', 'view.php'] as $file) {
        $check(str_contains($routeSources[$file], 'require_placeholder_route_access()'), 'Require existing authorization on ' . $file);
    }
    $check(str_contains($routeSources['admission-form.php'], 'require_placeholder_route_access()'), 'Protect PDF endpoints with the existing permission system');
    $check(str_contains($routeSources['admission-form.php'], 'student_management_get_student(db_connect(), $institutionId, $studentId)'), 'Build the PDF from the saved institution-scoped student record');
    $check(str_contains($routeSources['admission-form.php'], "http_response_code(404)"), 'Return not found for invalid or inaccessible student IDs');
    $pdfServiceSource = file_get_contents(__DIR__ . '/../services/admission-form-pdf-service.php');
    $check(is_string($pdfServiceSource)
        && str_contains($pdfServiceSource, "'Content-Disposition: '")
        && str_contains($pdfServiceSource, '$safeStudentCode'), 'Use a sanitized saved student ID in the PDF filename');
    $check(is_string($pdfServiceSource)
        && str_contains($pdfServiceSource, "header('Content-Type: application/pdf')"), 'Set the PDF response content type');

    $authSource = file_get_contents(__DIR__ . '/../includes/auth.php');
    $schemaSource = file_get_contents(__DIR__ . '/../database/schema.sql');
    $updateStart = strpos($serviceSource, 'function student_management_update(');
    $updateEnd = strpos($serviceSource, 'function student_management_toggle_status(', $updateStart === false ? 0 : $updateStart);
    $updateSource = $updateStart !== false && $updateEnd !== false
        ? substr($serviceSource, $updateStart, $updateEnd - $updateStart)
        : '';
    $check(is_string($authSource) && str_contains($authSource, "'index', 'view', 'admission-form'"), 'Authorize student PDF access through students.view');
    $check(is_string($schemaSource) && str_contains($schemaSource, 'UNIQUE KEY uq_students_institution_code (institution_id, student_code)'), 'Enforce student ID uniqueness in the database');
    $check($updateSource !== '' && !preg_match('/(?:INSERT|UPDATE|DELETE)\s+(?:INTO\s+)?(?:student_fee_assignments|installments|payments|receipts)/i', $updateSource), 'Keep student profile edits from creating or changing fee or payment records');
}

if ($failures !== []) {
    fwrite(STDERR, 'FAIL: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'PASS: ' . $checks . ' student management checks.' . PHP_EOL);
