<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/admin-panel-service.php';
require_once __DIR__ . '/../services/academic-management-service.php';
require_once __DIR__ . '/../services/admission-conversion-service.php';
require_once __DIR__ . '/../services/student-management-service.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$config = db_config();
$target = getenv('FEES_ACADEMIC_TEST_DB');
if (!is_string($target) || preg_match('/^fees_schema_verify_[0-9]{8}_[0-9]{6}$/D', $target) !== 1) {
    fwrite(STDOUT, "Academic integration checks skipped; set FEES_ACADEMIC_TEST_DB to a dedicated disposable fees_schema_verify_ database." . PHP_EOL);
    exit(0);
}
if (strcasecmp((string) $config['database'], 'fees') !== 0 || $target === (string) $config['database']) {
    throw new RuntimeException('Unexpected database target.');
}
$db = new mysqli(
    (string) $config['host'],
    (string) $config['username'],
    (string) $config['password'],
    $target,
    (int) $config['port']
);
$db->set_charset('utf8mb4');
if (!academic_management_schema_ready($db)) {
    throw new RuntimeException('Academic schema is not ready.');
}

$checks = 0;
$failures = [];
$suffix = bin2hex(random_bytes(5));

function academic_test_check(bool $condition, string $label): void
{
    global $checks, $failures;
    $checks++;
    if (!$condition) {
        $failures[] = $label;
    }
}

function academic_test_insert_id(mysqli $db, string $sql, string $types, mixed ...$values): int
{
    $statement = $db->prepare($sql);
    $parameters = [$types];
    foreach ($values as &$value) {
        $parameters[] = &$value;
    }
    unset($value);
    call_user_func_array([$statement, 'bind_param'], $parameters);
    $statement->execute();
    $id = (int) $statement->insert_id;
    $statement->close();
    return $id;
}

function academic_test_scalar(mysqli $db, string $sql, string $types, mixed ...$values): mixed
{
    $statement = $db->prepare($sql);
    $parameters = [$types];
    foreach ($values as &$value) {
        $parameters[] = &$value;
    }
    unset($value);
    call_user_func_array([$statement, 'bind_param'], $parameters);
    $statement->execute();
    $value = $statement->get_result()->fetch_column();
    $statement->close();
    return $value;
}

function academic_test_institution(mysqli $db, string $name, string $code): int
{
    return academic_test_insert_id($db, 'INSERT INTO institutions (name, code) VALUES (?, ?)', 'ss', $name, $code);
}

function academic_test_actor(mysqli $db, int $institutionId, int $roleId, string $name, string $username): int
{
    return academic_test_insert_id(
        $db,
        "INSERT INTO users (institution_id, role_id, user_type, full_name, username, status)
         VALUES (?, ?, 'admin', ?, ?, 'active')",
        'iiss',
        $institutionId,
        $roleId,
        $name,
        $username
    );
}

function academic_test_year(
    mysqli $db,
    int $institutionId,
    int $actorId,
    string $name,
    string $startDate,
    string $endDate
): int {
    $values = admin_panel_validate_academic_year([
        'name' => $name,
        'start_date' => $startDate,
        'end_date' => $endDate,
    ]);
    return admin_panel_create_academic_year($db, $institutionId, $actorId, $values);
}

function academic_test_activate(mysqli $db, int $institutionId, int $actorId, int $yearId): void
{
    admin_panel_set_academic_year_status($db, $institutionId, $actorId, $yearId, 'active');
}

function academic_test_class(mysqli $db, int $institutionId, int $actorId, string $name, string $code): int
{
    $values = admin_panel_validate_class([
        'name' => $name,
        'code' => $code,
        'description' => 'Disposable academic integration fixture',
        'status' => 'active',
    ]);
    return admin_panel_create_class($db, $institutionId, $actorId, $values);
}

function academic_test_section(
    mysqli $db,
    int $institutionId,
    int $actorId,
    int $yearId,
    int $classId,
    string $label,
    string $suffix
): array {
    $sectionId = academic_management_create_section(
        $db,
        $institutionId,
        $actorId,
        $yearId,
        $classId,
        $label,
        'SEC-' . $suffix . '-' . $label,
        30
    );
    $statement = $db->prepare(
        "SELECT id FROM academic_groups
         WHERE institution_id = ? AND academic_year_id = ?
           AND group_type = 'section' AND section_id = ?"
    );
    $statement->bind_param('iii', $institutionId, $yearId, $sectionId);
    $statement->execute();
    $groupId = (int) $statement->get_result()->fetch_column();
    $statement->close();
    return [$sectionId, $groupId];
}

function academic_test_student(mysqli $db, int $institutionId, string $name, string $code): int
{
    return academic_test_insert_id(
        $db,
        "INSERT INTO students (institution_id, student_code, full_name, admission_date, status)
         VALUES (?, ?, ?, '2026-04-01', 'admitted')",
        'iss',
        $institutionId,
        $code,
        $name
    );
}

try {
    global $db, $suffix;
    $adminRole = $db->query(
        "SELECT id FROM roles
         WHERE institution_id IS NULL AND is_system = 1 AND BINARY name = BINARY 'admin' LIMIT 1"
    )->fetch_assoc();
    $parentRole = $db->query(
        "SELECT id FROM roles
         WHERE institution_id IS NULL AND is_system = 1 AND BINARY name = BINARY 'parent' LIMIT 1"
    )->fetch_assoc();
    if (!is_array($adminRole) || !is_array($parentRole)) {
        throw new RuntimeException('Seeded system roles are missing.');
    }
    $adminRoleId = (int) $adminRole['id'];
    $parentRoleId = (int) $parentRole['id'];

    $institutionA = academic_test_institution($db, 'Academic A ' . $suffix, 'ACA-' . $suffix);
    $actorA = academic_test_actor($db, $institutionA, $adminRoleId, 'Academic Admin A ' . $suffix, 'acad-a-' . $suffix);
    $_SESSION = ['user' => [
        'id' => $actorA,
        'institution_id' => $institutionA,
        'user_type' => 'admin',
        'status' => 'active',
        'permissions' => [],
    ]];
    academic_management_configure_model($db, $institutionA, $actorA, 'school');

    $year1 = academic_test_year($db, $institutionA, $actorA, 'Academic Year 1 ' . $suffix, '2026-04-01', '2027-03-31');
    academic_test_activate($db, $institutionA, $actorA, $year1);
    academic_management_set_active_year($db, $institutionA, $year1, $actorA);
    $classA = academic_test_class($db, $institutionA, $actorA, 'Academic Class ' . $suffix, 'CLS-' . $suffix);
    academic_management_create_class_offering($db, $institutionA, $actorA, $year1, $classA);
    [$section1, $group1] = academic_test_section($db, $institutionA, $actorA, $year1, $classA, 'A', $suffix);
    $subjectId = academic_management_create_subject($db, $institutionA, $actorA, 'Subject ' . $suffix, 'SUB-' . $suffix, 'core');
    academic_management_map_subject($db, $institutionA, $actorA, $year1, $classA, $subjectId);
    academic_management_map_subject($db, $institutionA, $actorA, $year1, $classA, $subjectId, $section1, 'include');
    academic_test_check(
        (int) academic_test_scalar($db, 'SELECT COUNT(*) FROM academic_year_classes WHERE institution_id=? AND academic_year_id=? AND class_id=?', 'iii', $institutionA, $year1, $classA) === 1
            && (int) academic_test_scalar($db, 'SELECT COUNT(*) FROM sections WHERE institution_id=? AND id=?', 'ii', $institutionA, $section1) === 1
            && (int) academic_test_scalar($db, 'SELECT COUNT(*) FROM section_subjects WHERE institution_id=? AND academic_year_id=? AND section_id=? AND subject_id=?', 'iiii', $institutionA, $year1, $section1, $subjectId) === 1,
        'Create and persist year, class, section, subject, and mapping'
    );
    academic_management_complete_configuration($db, $institutionA, $actorA);
    academic_test_check(
        academic_test_scalar($db, 'SELECT academic_configuration_status FROM institutions WHERE id=?', 'i', $institutionA) === 'configured',
        'Complete school academic configuration'
    );

    $draftYear = academic_test_year($db, $institutionA, $actorA, 'Draft Year ' . $suffix, '2030-04-01', '2031-03-31');
    $draftRejected = false;
    try {
        academic_management_set_active_year($db, $institutionA, $draftYear, $actorA);
    } catch (DomainException) {
        $draftRejected = true;
    }
    academic_test_check(
        $draftRejected && (int) academic_test_scalar($db, 'SELECT active_academic_year_id FROM institutions WHERE id=?', 'i', $institutionA) === $year1,
        'Reject selecting a draft year and preserve active-year selection'
    );

    $year2 = academic_test_year($db, $institutionA, $actorA, 'Academic Year 2 ' . $suffix, '2027-04-01', '2028-03-31');
    academic_test_activate($db, $institutionA, $actorA, $year2);
    academic_management_create_class_offering($db, $institutionA, $actorA, $year2, $classA);
    [, $group2] = academic_test_section($db, $institutionA, $actorA, $year2, $classA, 'A', $suffix);
    $year3 = academic_test_year($db, $institutionA, $actorA, 'Academic Year 3 ' . $suffix, '2028-04-01', '2029-03-31');
    academic_test_activate($db, $institutionA, $actorA, $year3);
    academic_management_create_class_offering($db, $institutionA, $actorA, $year3, $classA);
    [, $group3] = academic_test_section($db, $institutionA, $actorA, $year3, $classA, 'A', $suffix);

    $student1 = academic_test_student($db, $institutionA, 'Student One ' . $suffix, 'ST1-' . $suffix);
    $student2 = academic_test_student($db, $institutionA, 'Student Two ' . $suffix, 'ST2-' . $suffix);
    $student3 = academic_test_student($db, $institutionA, 'Student Three ' . $suffix, 'ST3-' . $suffix);
    academic_management_set_active_year($db, $institutionA, $year1, $actorA);
    $enrolment1 = academic_management_create_enrolment($db, $institutionA, $actorA, $student1, $year1, $group1, 'Initial enrolment', 'EN1-' . $suffix);
    $enrolment2 = academic_management_create_enrolment($db, $institutionA, $actorA, $student2, $year1, $group1, 'Initial enrolment', 'EN2-' . $suffix);
    $enrolment3 = academic_management_create_enrolment($db, $institutionA, $actorA, $student3, $year1, $group1, 'Initial enrolment', 'EN3-' . $suffix);
    academic_test_check(
        (int) academic_test_scalar($db, 'SELECT COUNT(*) FROM student_enrolment_placements WHERE institution_id=? AND student_enrolment_id IN (?, ?, ?)', 'iiii', $institutionA, $enrolment1, $enrolment2, $enrolment3) === 3,
        'Create initial enrolment placements'
    );

    academic_management_set_active_year($db, $institutionA, $year2, $actorA);
    $promoted1 = academic_management_promote_enrolment($db, $institutionA, $actorA, $enrolment1, $year2, $group2, 'Immediate-year promotion');
    academic_test_check(
        academic_test_scalar($db, 'SELECT enrolment_status FROM student_enrolments WHERE institution_id=? AND id=?', 'ii', $institutionA, $enrolment1) === 'promoted'
            && (int) academic_test_scalar($db, 'SELECT promoted_from_enrolment_id FROM student_enrolments WHERE institution_id=? AND id=?', 'ii', $institutionA, $promoted1) === $enrolment1
            && (int) academic_test_scalar($db, 'SELECT COUNT(*) FROM student_enrolment_placements WHERE institution_id=? AND student_enrolment_id=? AND academic_year_id=? AND academic_group_id=?', 'iiii', $institutionA, $enrolment1, $year1, $group1) === 1
            && (int) academic_test_scalar($db, 'SELECT COUNT(*) FROM student_enrolment_placements WHERE institution_id=? AND student_enrolment_id=? AND academic_year_id=? AND academic_group_id=?', 'iiii', $institutionA, $promoted1, $year2, $group2) === 1
            && (int) academic_test_scalar($db, 'SELECT academic_year_id FROM students WHERE institution_id=? AND id=?', 'ii', $institutionA, $student1) === $year2,
        'Promote to immediate next year and preserve source/target placement history'
    );

    academic_management_set_active_year($db, $institutionA, $year3, $actorA);
    $skipRejected = false;
    try {
        academic_management_promote_enrolment($db, $institutionA, $actorA, $enrolment2, $year3, $group3, 'Invalid skip-year attempt');
    } catch (DomainException) {
        $skipRejected = true;
    }
    academic_test_check(
        $skipRejected
            && academic_test_scalar($db, 'SELECT enrolment_status FROM student_enrolments WHERE institution_id=? AND id=?', 'ii', $institutionA, $enrolment2) === 'active'
            && (int) academic_test_scalar($db, 'SELECT COUNT(*) FROM student_enrolments WHERE institution_id=? AND student_id=? AND academic_year_id=?', 'iii', $institutionA, $student2, $year3) === 0,
        'Reject skipping an academic year without modifying source enrolment'
    );

    academic_management_set_active_year($db, $institutionA, $year2, $actorA);
    $rollbackCaught = false;
    try {
        academic_management_promote_enrolment($db, $institutionA, 999999999, $enrolment3, $year2, $group2, 'Forced actor foreign-key rollback');
    } catch (mysqli_sql_exception) {
        $rollbackCaught = true;
    }
    academic_test_check(
        $rollbackCaught
            && (int) academic_test_scalar($db, 'SELECT COUNT(*) FROM student_enrolments WHERE institution_id=? AND student_id=? AND academic_year_id=?', 'iii', $institutionA, $student3, $year2) === 0
            && academic_test_scalar($db, 'SELECT enrolment_status FROM student_enrolments WHERE institution_id=? AND id=?', 'ii', $institutionA, $enrolment3) === 'active'
            && (int) academic_test_scalar($db, 'SELECT COUNT(*) FROM student_enrolment_placements WHERE institution_id=? AND student_enrolment_id=?', 'ii', $institutionA, $enrolment3) === 1,
        'Roll back partial promotion writes after a later foreign-key error'
    );

    $duplicateYear = academic_test_year($db, $institutionA, $actorA, 'Ambiguous Next Year ' . $suffix, '2027-04-01', '2028-03-31');
    academic_test_activate($db, $institutionA, $actorA, $duplicateYear);
    academic_management_set_active_year($db, $institutionA, $duplicateYear, $actorA);
    $ambiguousRejected = false;
    try {
        academic_management_promote_enrolment($db, $institutionA, $actorA, $enrolment2, $year2, $group2, 'Ambiguous next year attempt');
    } catch (DomainException) {
        $ambiguousRejected = true;
    }
    academic_test_check(
        $ambiguousRejected
            && academic_test_scalar($db, 'SELECT enrolment_status FROM student_enrolments WHERE institution_id=? AND id=?', 'ii', $institutionA, $enrolment2) === 'active',
        'Reject promotion when multiple next years are ambiguous'
    );

    $institutionB = academic_test_institution($db, 'Academic B ' . $suffix, 'ACB-' . $suffix);
    $actorB = academic_test_actor($db, $institutionB, $adminRoleId, 'Academic Admin B ' . $suffix, 'acad-b-' . $suffix);
    $_SESSION = ['user' => [
        'id' => $actorB,
        'institution_id' => $institutionB,
        'user_type' => 'admin',
        'status' => 'active',
        'permissions' => [],
    ]];
    academic_management_configure_model($db, $institutionB, $actorB, 'school');
    $yearB = academic_test_year($db, $institutionB, $actorB, 'Academic B Year ' . $suffix, '2026-04-01', '2027-03-31');
    academic_test_activate($db, $institutionB, $actorB, $yearB);
    academic_management_set_active_year($db, $institutionB, $yearB, $actorB);
    $classB = academic_test_class($db, $institutionB, $actorB, 'Academic B Class ' . $suffix, 'BCL-' . $suffix);
    academic_management_create_class_offering($db, $institutionB, $actorB, $yearB, $classB);
    [, $groupB] = academic_test_section($db, $institutionB, $actorB, $yearB, $classB, 'B', $suffix);
    $studentB = academic_test_student($db, $institutionB, 'Student B ' . $suffix, 'STB-' . $suffix);
    $parentUserB = academic_test_insert_id(
        $db,
        "INSERT INTO users (institution_id, role_id, user_type, full_name, username, status)
         VALUES (?, ?, 'parent', ?, ?, 'active')",
        'iiss',
        $institutionB,
        $parentRoleId,
        'Parent B ' . $suffix,
        'parent-b-' . $suffix
    );
    $parentB = academic_test_insert_id(
        $db,
        "INSERT INTO parents (institution_id, user_id, full_name, mobile, relationship_to_student, status)
         VALUES (?, ?, ?, ?, 'guardian', 'active')",
        'iiss',
        $institutionB,
        $parentUserB,
        'Parent B ' . $suffix,
        '8' . substr((string) hexdec(substr($suffix, 0, 7)), 0, 9)
    );

    $_SESSION = ['user' => [
        'id' => $actorA,
        'institution_id' => $institutionA,
        'user_type' => 'admin',
        'status' => 'active',
        'permissions' => [],
    ]];
    academic_management_set_active_year($db, $institutionA, $year1, $actorA);
    $crossEnrolmentRejected = false;
    try {
        academic_management_create_enrolment($db, $institutionA, $actorA, $studentB, $year1, $group1, 'Cross-tenant attempt');
    } catch (OutOfBoundsException) {
        $crossEnrolmentRejected = true;
    }
    academic_test_check(
        $crossEnrolmentRejected
            && student_management_get_student($db, $institutionA, $studentB) === null
            && (int) academic_test_scalar($db, 'SELECT COUNT(*) FROM student_enrolments WHERE institution_id=? AND student_id=?', 'ii', $institutionA, $studentB) === 0,
        'Deny cross-tenant student lookup and enrolment'
    );

    $placementsBefore = (int) academic_test_scalar($db, 'SELECT COUNT(*) FROM student_enrolment_placements WHERE institution_id=? AND student_enrolment_id=?', 'ii', $institutionA, $enrolment2);
    $_SESSION = ['user' => [
        'id' => $actorB,
        'institution_id' => $institutionB,
        'user_type' => 'admin',
        'status' => 'active',
        'permissions' => [],
    ]];
    $crossSectionRejected = false;
    try {
        academic_management_change_section($db, $institutionB, $actorB, $enrolment2, $groupB, 'Cross-tenant modification attempt');
    } catch (OutOfBoundsException) {
        $crossSectionRejected = true;
    }
    $crossPromotionRejected = false;
    try {
        academic_management_promote_enrolment($db, $institutionB, $actorB, $enrolment2, $yearB, $groupB, 'Cross-tenant promotion attempt');
    } catch (DomainException) {
        $crossPromotionRejected = true;
    }
    academic_test_check(
        $crossSectionRejected && $crossPromotionRejected
            && (int) academic_test_scalar($db, 'SELECT COUNT(*) FROM student_enrolment_placements WHERE institution_id=? AND student_enrolment_id=?', 'ii', $institutionA, $enrolment2) === $placementsBefore
            && (int) academic_test_scalar($db, 'SELECT COUNT(*) FROM student_enrolments WHERE institution_id=? AND student_id=?', 'ii', $institutionB, $student2) === 0,
        'Deny cross-tenant enrolment modification and promotion'
    );

    $parentLinkRejected = false;
    try {
        academic_test_insert_id(
            $db,
            "INSERT INTO parent_student_relationships (institution_id, parent_id, student_id, relationship_type, status)
             VALUES (?, ?, ?, 'guardian', 'active')",
            'iii',
            $institutionA,
            $parentB,
            $student1
        );
    } catch (mysqli_sql_exception) {
        $parentLinkRejected = true;
    }
    academic_test_check(
        $parentLinkRejected
            && (int) academic_test_scalar($db, 'SELECT COUNT(*) FROM parent_student_relationships WHERE institution_id=? AND parent_id=? AND student_id=?', 'iii', $institutionA, $parentB, $student1) === 0,
        'Reject a parent/student association that crosses institutions'
    );

    $admissionId = academic_test_insert_id(
        $db,
        "INSERT INTO admissions (institution_id, applicant_name, applicant_mobile, student_name, status)
         VALUES (?, ?, ?, ?, 'submitted')",
        'isss',
        $institutionA,
        'Applicant ' . $suffix,
        '7' . substr((string) hexdec(substr($suffix, 2, 7)), 0, 9),
        'Applicant Student ' . $suffix
    );
    $admissionReadDenied = admission_conversion_get($db, $institutionB, $admissionId) === null;
    $admissionReviewDenied = false;
    try {
        admission_conversion_set_status($db, $institutionB, $actorB, 'admin', $admissionId, 'rejected', 'Cross-tenant review attempt');
    } catch (OutOfBoundsException) {
        $admissionReviewDenied = true;
    }
    academic_test_check(
        $admissionReadDenied && $admissionReviewDenied
            && academic_test_scalar($db, 'SELECT status FROM admissions WHERE institution_id=? AND id=?', 'ii', $institutionA, $admissionId) === 'submitted',
        'Deny cross-tenant admission read and modification'
    );

    academic_test_check(
        (int) academic_test_scalar($db, "SELECT COUNT(*) FROM audit_logs WHERE institution_id=? AND actor_user_id=? AND action='academic_year.created'", 'ii', $institutionA, $actorA) > 0,
        'Preserve institution context in academic audit records'
    );

    $_SESSION = ['user' => [
        'id' => $actorA,
        'institution_id' => $institutionA,
        'user_type' => 'admin',
        'status' => 'active',
        'permissions' => [],
    ]];
    academic_management_set_active_year($db, $institutionA, $year1, $actorA);
    $assignedAdmissionId = academic_test_insert_id(
        $db,
        "INSERT INTO admissions (institution_id, applicant_name, applicant_mobile, student_name, status)
         VALUES (?, ?, ?, ?, 'approved')",
        'isss',
        $institutionA,
        'Assigned applicant ' . $suffix,
        '3' . substr((string) hexdec(substr($suffix, 2, 7)), 0, 9),
        'Assigned student ' . $suffix
    );
    $assignedConversion = admission_conversion_convert(
        $db,
        $institutionA,
        $actorA,
        'admin',
        $assignedAdmissionId,
        $year1,
        $group1,
        'ASSIGNED-STUDENT-' . $suffix,
        'ASSIGNED-ADM-' . $suffix
    );
    academic_test_check(
        (int) academic_test_scalar($db, 'SELECT institution_id FROM admissions WHERE id=? AND student_id=? AND academic_year_id=? AND academic_group_id=? AND student_enrolment_id=?', 'iiiii', $assignedAdmissionId, $assignedConversion['student_id'], $year1, $group1, $assignedConversion['student_enrolment_id']) === $institutionA
            && (int) academic_test_scalar($db, 'SELECT COUNT(*) FROM student_enrolments WHERE institution_id=? AND id=? AND student_id=? AND academic_year_id=?', 'iiii', $institutionA, $assignedConversion['student_enrolment_id'], $assignedConversion['student_id'], $year1) === 1
            && (int) academic_test_scalar($db, 'SELECT COUNT(*) FROM student_enrolment_placements WHERE institution_id=? AND id=? AND student_id=? AND academic_year_id=? AND academic_group_id=?', 'iiiii', $institutionA, $assignedConversion['placement_id'], $assignedConversion['student_id'], $year1, $group1) === 1,
        'Persist admission conversion with consistent institution, year, group, enrolment, and placement ownership'
    );

    $duplicateCodeAdmissionId = academic_test_insert_id(
        $db,
        "INSERT INTO admissions (institution_id, applicant_name, applicant_mobile, student_name, status)
         VALUES (?, ?, ?, ?, 'approved')",
        'isss',
        $institutionA,
        'Duplicate-code applicant ' . $suffix,
        '2' . substr((string) hexdec(substr($suffix, 3, 7)), 0, 9),
        'Duplicate-code student ' . $suffix
    );
    $duplicateStudentCodeRejected = false;
    try {
        admission_conversion_convert(
            $db,
            $institutionA,
            $actorA,
            'admin',
            $duplicateCodeAdmissionId,
            $year1,
            $group1,
            'ST1-' . $suffix,
            null
        );
    } catch (InvalidArgumentException) {
        $duplicateStudentCodeRejected = true;
    }
    academic_test_check(
        $duplicateStudentCodeRejected
            && (int) academic_test_scalar($db, 'SELECT COUNT(*) FROM students WHERE institution_id=? AND student_code=?', 'is', $institutionA, 'ST1-' . $suffix) === 1
            && (int) academic_test_scalar($db, 'SELECT COUNT(*) FROM admissions WHERE institution_id=? AND id=? AND status=\'approved\' AND student_enrolment_id IS NULL', 'ii', $institutionA, $duplicateCodeAdmissionId) === 1,
        'Reject duplicate student code without creating a second student or converting the application'
    );

    $blockerStudent = academic_test_student($db, $institutionA, 'Rollback blocker ' . $suffix, 'RBLOCK-' . $suffix);
    academic_management_create_enrolment(
        $db,
        $institutionA,
        $actorA,
        $blockerStudent,
        $year1,
        $group1,
        'Reserve admission number for rollback assertion',
        'ADN-' . $suffix
    );
    $rollbackAdmissionId = academic_test_insert_id(
        $db,
        "INSERT INTO admissions (institution_id, applicant_name, applicant_mobile, student_name, status)
         VALUES (?, ?, ?, ?, 'approved')",
        'isss',
        $institutionA,
        'Rollback applicant ' . $suffix,
        '4' . substr((string) hexdec(substr($suffix, 1, 7)), 0, 9),
        'Rollback student ' . $suffix
    );
    $createdFromAdmissionAuditBefore = (int) academic_test_scalar(
        $db,
        "SELECT COUNT(*) FROM audit_logs WHERE institution_id=? AND actor_user_id=? AND action='student.created_from_admission'",
        'ii',
        $institutionA,
        $actorA
    );
    $conversionRollbackCaught = false;
    try {
        admission_conversion_convert(
            $db,
            $institutionA,
            $actorA,
            'admin',
            $rollbackAdmissionId,
            $year1,
            $group1,
            'ROLLBACK-STUDENT-' . $suffix,
            'ADN-' . $suffix
        );
    } catch (DomainException) {
        $conversionRollbackCaught = true;
    }
    academic_test_check(
        $conversionRollbackCaught
            && (int) academic_test_scalar($db, 'SELECT COUNT(*) FROM students WHERE institution_id=? AND student_code=?', 'is', $institutionA, 'ROLLBACK-STUDENT-' . $suffix) === 0
            && (int) academic_test_scalar($db, 'SELECT COUNT(*) FROM student_enrolments WHERE institution_id=? AND admission_number=?', 'is', $institutionA, 'ADN-' . $suffix) === 1
            && (int) academic_test_scalar($db, 'SELECT COUNT(*) FROM admissions WHERE institution_id=? AND id=? AND status=\'approved\' AND student_enrolment_id IS NULL', 'ii', $institutionA, $rollbackAdmissionId) === 1
            && (int) academic_test_scalar(
                $db,
                "SELECT COUNT(*) FROM audit_logs WHERE institution_id=? AND actor_user_id=? AND action='student.created_from_admission'",
                'ii',
                $institutionA,
                $actorA
            ) === $createdFromAdmissionAuditBefore,
        'Roll back admission student and audit writes after duplicate enrolment number failure'
    );
} catch (Throwable $exception) {
    fwrite(STDERR, 'ACADEMIC_SETUP_OR_ASSERTION_ERROR=' . get_class($exception)
        . ' code=' . $exception->getCode() . ' message=' . $exception->getMessage() . PHP_EOL);
    exit(1);
} finally {
    $db->close();
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, 'Passed ' . $checks . ' academic and tenant integration checks.' . PHP_EOL);
