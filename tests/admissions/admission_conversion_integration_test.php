<?php
declare(strict_types=1);

$testDatabase = getenv('FEES_ADMISSION_TEST_DB');
if (!is_string($testDatabase) || $testDatabase === '') {
    fwrite(STDOUT, "Admission integration checks skipped; load database/schema.sql and database/seed.sql into a dedicated disposable fees_admission_verify_ database and set FEES_ADMISSION_TEST_DB." . PHP_EOL);
    exit(0);
}
if (preg_match('/^fees_admission_verify_[a-z0-9_]+$/D', $testDatabase) !== 1) {
    fwrite(STDERR, 'Use only a dedicated disposable fees_admission_verify_ database.' . PHP_EOL);
    exit(1);
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../services/admission-conversion-service.php';
require_once __DIR__ . '/../../services/admin-panel-service.php';

$configuration = db_config();
$db = new mysqli(
    (string) $configuration['host'],
    (string) $configuration['username'],
    (string) $configuration['password'],
    $testDatabase,
    (int) $configuration['port']
);
$db->set_charset('utf8mb4');
if (!academic_management_schema_ready($db) || !admission_conversion_schema_ready($db)) {
    fwrite(STDERR, 'Load database/schema.sql and database/seed.sql into the disposable database before running admission integration checks.' . PHP_EOL);
    exit(1);
}

$checks = 0;
$failures = [];
$check = static function (bool $condition, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $label;
    }
};
$insertId = static function (string $sql, string $types, mixed ...$values) use ($db): int {
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
};
$fetchValue = static function (string $sql, string $types, mixed ...$values) use ($db): mixed {
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
};
$suffix = bin2hex(random_bytes(6));
$institutionCodeA = 'ADM-A-' . $suffix;
$institutionCodeB = 'ADM-B-' . $suffix;
$applicantMobileA = '7' . substr((string) hexdec(substr($suffix, 0, 7)), 0, 9);
$applicantMobileB = '8' . substr((string) hexdec(substr($suffix, 2, 7)), 0, 9);

try {
    $institutionA = $insertId(
        'INSERT INTO institutions (name, code, academic_model, academic_configuration_status)
         VALUES (?, ?, \'school\', \'configured\')',
        'ss',
        'Admission Integration A ' . $suffix,
        $institutionCodeA
    );
    $institutionB = $insertId(
        'INSERT INTO institutions (name, code, academic_model, academic_configuration_status)
         VALUES (?, ?, \'school\', \'configured\')',
        'ss',
        'Admission Integration B ' . $suffix,
        $institutionCodeB
    );

    $actorId = $insertId(
        'INSERT INTO users (institution_id, role_id, user_type, full_name, username, status)
         VALUES (?, 2, \'admin\', ?, ?, \'active\')',
        'iss',
        $institutionA,
        'Admission Test Admin',
        'admit-admin-' . $suffix
    );
    $parentUserIds = [];
    $parentIds = [];
    foreach ([1, 2] as $number) {
        $parentUserIds[] = $insertId(
            'INSERT INTO users (institution_id, role_id, user_type, full_name, username, status)
             VALUES (?, 4, \'parent\', ?, ?, \'active\')',
            'iss',
            $institutionA,
            'Admission Test Parent ' . $number,
            'admit-parent-' . $number . '-' . $suffix
        );
        $parentIds[] = $insertId(
            'INSERT INTO parents (institution_id, user_id, full_name, mobile, relationship_to_student, status)
             VALUES (?, ?, ?, ?, \'guardian\', \'active\')',
            'iiss',
            $institutionA,
            $parentUserIds[$number - 1],
            'Admission Test Parent ' . $number,
            ($number === 1 ? $applicantMobileA : $applicantMobileB)
        );
    }

    $yearA = $insertId(
        'INSERT INTO academic_years (institution_id, name, start_date, end_date, status)
         VALUES (?, ?, \'2026-04-01\', \'2027-03-31\', \'active\')',
        'is',
        $institutionA,
        'Admission Year A ' . $suffix
    );
    $yearB = $insertId(
        'INSERT INTO academic_years (institution_id, name, start_date, end_date, status)
         VALUES (?, ?, \'2026-04-01\', \'2027-03-31\', \'active\')',
        'is',
        $institutionB,
        'Admission Year B ' . $suffix
    );
    $statement = $db->prepare('UPDATE institutions SET active_academic_year_id = ? WHERE id = ?');
    $statement->bind_param('ii', $yearA, $institutionA);
    $statement->execute();
    $statement->close();
    $statement = $db->prepare('UPDATE institutions SET active_academic_year_id = ? WHERE id = ?');
    $statement->bind_param('ii', $yearB, $institutionB);
    $statement->execute();
    $statement->close();

    $classA = $insertId(
        'INSERT INTO classes (institution_id, name, code, status) VALUES (?, ?, ?, \'active\')',
        'iss',
        $institutionA,
        'Admission Class A ' . $suffix,
        'AA-' . $suffix
    );
    $classB = $insertId(
        'INSERT INTO classes (institution_id, name, code, status) VALUES (?, ?, ?, \'active\')',
        'iss',
        $institutionB,
        'Admission Class B ' . $suffix,
        'AB-' . $suffix
    );
    foreach ([[$institutionA, $yearA, $classA], [$institutionB, $yearB, $classB]] as [$institutionId, $yearId, $classId]) {
        $insertId(
            'INSERT INTO academic_year_classes (institution_id, academic_year_id, class_id, status)
             VALUES (?, ?, ?, \'active\')',
            'iii',
            $institutionId,
            $yearId,
            $classId
        );
    }
    $sectionA = $insertId(
        'INSERT INTO sections (institution_id, academic_year_id, class_id, name, code, status)
         VALUES (?, ?, ?, \'A\', ?, \'active\')',
        'iiis',
        $institutionA,
        $yearA,
        $classA,
        'SEC-A-' . $suffix
    );
    $sectionB = $insertId(
        'INSERT INTO sections (institution_id, academic_year_id, class_id, name, code, status)
         VALUES (?, ?, ?, \'B\', ?, \'active\')',
        'iiis',
        $institutionB,
        $yearB,
        $classB,
        'SEC-B-' . $suffix
    );
    $groupA = $insertId(
        'INSERT INTO academic_groups (institution_id, academic_year_id, group_type, class_id, section_id)
         VALUES (?, ?, \'section\', ?, ?)',
        'iiii',
        $institutionA,
        $yearA,
        $classA,
        $sectionA
    );
    $groupB = $insertId(
        'INSERT INTO academic_groups (institution_id, academic_year_id, group_type, class_id, section_id)
         VALUES (?, ?, \'section\', ?, ?)',
        'iiii',
        $institutionB,
        $yearB,
        $classB,
        $sectionB
    );
    $check(
        academic_management_resolve_institution_id(
            $db,
            ['user_type' => 'super_admin'],
            (string) $institutionB
        ) === $institutionB
            && academic_management_resolve_institution_id(
                $db,
                ['user_type' => 'super_admin'],
                null
            ) === null,
        'Allow super administrators to explicitly select an institution without defaulting to their account tenant'
    );
    admin_panel_update_class(
        $db,
        $institutionA,
        $actorId,
        $classA,
        [
            'name' => 'Updated Class A ' . $suffix,
            'code' => 'UCA-' . $suffix,
            'description' => 'Updated by a super administrator',
            'status' => 'active',
        ]
    );
    academic_management_update_section(
        $db,
        $institutionA,
        $actorId,
        $yearA,
        $classA,
        $sectionA,
        'Updated Section A',
        'USA-' . $suffix,
        35,
        'active'
    );
    $check(
        $fetchValue('SELECT name FROM classes WHERE institution_id = ? AND id = ?', 'ii', $institutionA, $classA) === 'Updated Class A ' . $suffix
            && $fetchValue('SELECT name FROM sections WHERE institution_id = ? AND id = ?', 'ii', $institutionA, $sectionA) === 'Updated Section A'
            && count(academic_management_list_placement_options($db, $institutionA)) === 1,
        'Update institution-scoped class and section details while keeping active placements available'
    );

    $admissionId = $insertId(
        'INSERT INTO admissions
            (institution_id, parent_id, applicant_name, applicant_mobile, relationship_to_student,
             student_name, status, submission_notes)
         VALUES (?, ?, \'Applicant One\', ?, \'guardian\', \'Student One\', \'submitted\', \'Intake notes\')',
        'iis',
        $institutionA,
        $parentIds[0],
        $applicantMobileA
    );
    admission_conversion_set_status(
        $db,
        $institutionA,
        $actorId,
        'admin',
        $admissionId,
        'approved',
        'Documents checked'
    );
    $check(
        $fetchValue('SELECT status FROM admissions WHERE institution_id = ? AND id = ?', 'ii', $institutionA, $admissionId) === 'approved',
        'Approve a submitted admission with an audit record'
    );

    $converted = admission_conversion_convert(
        $db,
        $institutionA,
        $actorId,
        'admin',
        $admissionId,
        $yearA,
        $groupA,
        'STU-' . $suffix,
        'ADM-' . $suffix
    );
    $check(
        $fetchValue('SELECT status FROM admissions WHERE institution_id = ? AND id = ?', 'ii', $institutionA, $admissionId) === 'enrolled'
            && (int) $fetchValue('SELECT COUNT(*) FROM student_enrolments WHERE institution_id = ? AND id = ?', 'ii', $institutionA, $converted['student_enrolment_id']) === 1
            && (int) $fetchValue('SELECT COUNT(*) FROM parent_student_relationships WHERE institution_id = ? AND student_id = ? AND parent_id = ? AND status = \'active\'', 'iii', $institutionA, $converted['student_id'], $parentIds[0]) === 1,
        'Create a student, enrolment, placement, and parent link in one conversion'
    );
    academic_management_update_section(
        $db,
        $institutionA,
        $actorId,
        $yearA,
        $classA,
        $sectionA,
        'Updated Section A',
        'USA-' . $suffix,
        35,
        'inactive'
    );
    $check(
        academic_management_list_placement_options($db, $institutionA) === []
            && (int) $fetchValue(
                'SELECT COUNT(*) FROM student_enrolment_placements
                 WHERE institution_id = ? AND id = ?',
                'ii',
                $institutionA,
                $converted['placement_id']
            ) === 1,
        'Archive a section from new placements without deleting historical enrolment placement'
    );
    academic_management_update_section(
        $db,
        $institutionA,
        $actorId,
        $yearA,
        $classA,
        $sectionA,
        'Updated Section A',
        'USA-' . $suffix,
        35,
        'active'
    );
    $duplicateConversionRejected = false;
    try {
        admission_conversion_convert(
            $db,
            $institutionA,
            $actorId,
            'admin',
            $admissionId,
            $yearA,
            $groupA,
            '',
            null
        );
    } catch (DomainException) {
        $duplicateConversionRejected = true;
    }
    $check(
        $duplicateConversionRejected
            && (int) $fetchValue('SELECT COUNT(*) FROM student_enrolments WHERE institution_id = ? AND student_id = ?', 'ii', $institutionA, $converted['student_id']) === 1,
        'Reject duplicate conversion of an already-enrolled admission'
    );

    $studentId = (int) $converted['student_id'];
    $statement = $db->prepare(
        'INSERT INTO parent_student_relationships
            (institution_id, parent_id, student_id, relationship_type, status)
         VALUES (?, ?, ?, \'guardian\', \'active\')'
    );
    $statement->bind_param('iii', $institutionA, $parentIds[1], $studentId);
    $statement->execute();
    $statement->close();
    $duplicateApplicationId = $insertId(
        'INSERT INTO admissions
            (institution_id, student_id, parent_id, applicant_name, applicant_mobile,
             relationship_to_student, student_name, status)
         VALUES (?, ?, ?, \'Applicant Two\', ?, \'guardian\', \'Student One\', \'approved\')',
        'iiis',
        $institutionA,
        $studentId,
        $parentIds[0],
        $applicantMobileB
    );
    $duplicateRejected = false;
    try {
        admission_conversion_convert(
            $db,
            $institutionA,
            $actorId,
            'admin',
            $duplicateApplicationId,
            $yearA,
            $groupA,
            '',
            null
        );
    } catch (DomainException | OutOfBoundsException) {
        $duplicateRejected = true;
    }
    $check(
        $duplicateRejected
            && (int) $fetchValue('SELECT COUNT(*) FROM student_enrolments WHERE institution_id = ? AND student_id = ? AND academic_year_id = ?', 'iii', $institutionA, $studentId, $yearA) === 1
            && (int) $fetchValue('SELECT COUNT(*) FROM parent_student_relationships WHERE institution_id = ? AND student_id = ? AND status = \'active\'', 'ii', $institutionA, $studentId) === 2,
        'Reject duplicate student/year enrolment while preserving all existing parent links'
    );

    $invalidPlacementApplicationId = $insertId(
        'INSERT INTO admissions
            (institution_id, applicant_name, applicant_mobile, student_name, status)
         VALUES (?, \'Applicant Three\', ?, \'Student Three\', \'approved\')',
        'is',
        $institutionA,
        '9' . substr((string) hexdec(substr($suffix, 4, 7)), 0, 9)
    );
    $invalidPlacementRejected = false;
    try {
        admission_conversion_convert(
            $db,
            $institutionA,
            $actorId,
            'admin',
            $invalidPlacementApplicationId,
            $yearA,
            $groupB,
            'ROLLBACK-' . $suffix,
            null
        );
    } catch (DomainException | OutOfBoundsException) {
        $invalidPlacementRejected = true;
    }
    $check(
        $invalidPlacementRejected
            && (int) $fetchValue('SELECT COUNT(*) FROM students WHERE institution_id = ? AND student_code = ?', 'is', $institutionA, 'ROLLBACK-' . $suffix) === 0
            && (int) $fetchValue('SELECT COUNT(*) FROM admissions WHERE institution_id = ? AND id = ? AND student_enrolment_id IS NULL', 'ii', $institutionA, $invalidPlacementApplicationId) === 1,
        'Reject a cross-tenant placement and roll back all partially created records'
    );

    $classOnlyGroupId = $insertId(
        'INSERT INTO academic_groups (institution_id, academic_year_id, group_type, class_id)
         VALUES (?, ?, \'class\', ?)',
        'iii',
        $institutionA,
        $yearA,
        $classA
    );
    $invalidModelApplicationId = $insertId(
        'INSERT INTO admissions
            (institution_id, applicant_name, applicant_mobile, student_name, status)
         VALUES (?, \'Applicant Four\', ?, \'Student Four\', \'approved\')',
        'is',
        $institutionA,
        '6' . substr((string) hexdec(substr($suffix, 1, 7)), 0, 9)
    );
    $invalidModelRejected = false;
    try {
        admission_conversion_convert(
            $db,
            $institutionA,
            $actorId,
            'admin',
            $invalidModelApplicationId,
            $yearA,
            $classOnlyGroupId,
            'MODEL-' . $suffix,
            null
        );
    } catch (DomainException) {
        $invalidModelRejected = true;
    }
    $check(
        $invalidModelRejected
            && (int) $fetchValue('SELECT COUNT(*) FROM students WHERE institution_id = ? AND student_code = ?', 'is', $institutionA, 'MODEL-' . $suffix) === 0,
        'Reject a same-tenant placement that does not match the school academic model'
    );

    $rejectedApplicationId = $insertId(
        'INSERT INTO admissions
            (institution_id, applicant_name, applicant_mobile, student_name, status)
         VALUES (?, \'Applicant Five\', ?, \'Student Five\', \'submitted\')',
        'is',
        $institutionA,
        '5' . substr((string) hexdec(substr($suffix, 3, 7)), 0, 9)
    );
    admission_conversion_set_status(
        $db,
        $institutionA,
        $actorId,
        'admin',
        $rejectedApplicationId,
        'rejected',
        'Eligibility criteria not met'
    );
    $check(
        $fetchValue('SELECT status FROM admissions WHERE institution_id = ? AND id = ?', 'ii', $institutionA, $rejectedApplicationId) === 'rejected',
        'Reject an application and persist the review decision'
    );

    $detail = admission_conversion_get($db, $institutionA, $admissionId);
    $check(
        is_array($detail)
            && (int) $detail['student_id'] === $studentId
            && (int) $detail['student_enrolment_id'] === (int) $converted['student_enrolment_id']
            && (int) $detail['student_enrolment_placement_id'] === (int) $converted['placement_id'],
        'Load converted student and enrolment details only within their institution'
    );
} catch (Throwable $exception) {
    $failures[] = 'Integration setup or operation failed: ' . get_class($exception)
        . ' (' . $exception->getCode() . '): ' . $exception->getMessage();
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "{$checks} admission integration checks passed." . PHP_EOL);
