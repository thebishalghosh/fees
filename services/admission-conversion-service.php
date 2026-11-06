<?php
declare(strict_types=1);

require_once __DIR__ . '/academic-management-service.php';
require_once __DIR__ . '/student-management-service.php';

function admission_conversion_schema_ready(mysqli $db): bool
{
    $statement = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'admissions'
           AND column_name IN (
               'student_enrolment_id', 'academic_group_id',
               'student_enrolment_placement_id', 'converted_at', 'converted_by'
           )"
    );
    $statement->execute();
    $columnCount = (int) $statement->get_result()->fetch_column();
    $statement->close();
    return $columnCount === 5;
}

function admission_conversion_list(mysqli $db, int $institutionId): array
{
    $statement = $db->prepare(
        "SELECT a.id, a.status, a.applicant_name, a.applicant_mobile, a.student_name,
                a.created_at, a.student_id, a.student_enrolment_id, a.converted_at,
                s.student_code, e.academic_year_id, ay.name AS academic_year_name
         FROM admissions a
         LEFT JOIN students s ON s.institution_id = a.institution_id AND s.id = a.student_id
         LEFT JOIN student_enrolments e
           ON e.institution_id = a.institution_id AND e.id = a.student_enrolment_id
         LEFT JOIN academic_years ay
           ON ay.institution_id = e.institution_id AND ay.id = e.academic_year_id
         WHERE a.institution_id = ?
         ORDER BY FIELD(a.status, 'submitted', 'under_review', 'approved', 'draft', 'rejected', 'enrolled'),
                  a.created_at DESC, a.id DESC
         LIMIT 500"
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}

function admission_conversion_get(mysqli $db, int $institutionId, int $admissionId): ?array
{
    if ($institutionId < 1 || $admissionId < 1) {
        return null;
    }
    $statement = $db->prepare(
        'SELECT a.*, p.full_name AS linked_parent_name,
                s.student_code AS linked_student_code, s.full_name AS linked_student_name,
                e.enrolment_status, e.admission_number AS enrolment_admission_number,
                ay.name AS converted_year_name,
                g.group_type, c.name AS class_name, section.name AS section_name,
                course.name AS course_name, term.name AS term_name, b.name AS batch_name
         FROM admissions a
         LEFT JOIN parents p ON p.institution_id = a.institution_id AND p.id = a.parent_id
         LEFT JOIN students s ON s.institution_id = a.institution_id AND s.id = a.student_id
         LEFT JOIN student_enrolments e
           ON e.institution_id = a.institution_id AND e.id = a.student_enrolment_id
         LEFT JOIN academic_years ay
           ON ay.institution_id = e.institution_id AND ay.id = e.academic_year_id
         LEFT JOIN academic_groups g
           ON g.institution_id = a.institution_id AND g.academic_year_id = a.academic_year_id
          AND g.id = a.academic_group_id
         LEFT JOIN classes c ON c.institution_id = g.institution_id AND c.id = g.class_id
         LEFT JOIN sections section
           ON section.institution_id = g.institution_id AND section.academic_year_id = g.academic_year_id
          AND section.class_id = g.class_id AND section.id = g.section_id
         LEFT JOIN courses course ON course.institution_id = g.institution_id AND course.id = g.course_id
         LEFT JOIN academic_terms term
           ON term.institution_id = g.institution_id AND term.academic_year_id = g.academic_year_id
          AND term.course_id = g.course_id AND term.id = g.academic_term_id
         LEFT JOIN batches b
           ON b.institution_id = g.institution_id AND b.course_id = g.course_id AND b.id = g.batch_id
         WHERE a.institution_id = ? AND a.id = ?
         LIMIT 1'
    );
    $statement->bind_param('ii', $institutionId, $admissionId);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc();
    $statement->close();
    return is_array($row) ? $row : null;
}

function admission_conversion_placement_options(mysqli $db, int $institutionId): array
{
    $configuration = academic_management_configuration($db, $institutionId);
    $yearId = $configuration['active_academic_year_id'];
    $years = [];
    if ($yearId !== null) {
        foreach (academic_management_list_years($db, $institutionId) as $year) {
            if ((int) $year['id'] === $yearId && $year['status'] === 'active') {
                $years[] = $year;
                break;
            }
        }
    }
    return [
        'academic_model' => $configuration['academic_model'],
        'academic_year_id' => $yearId,
        'years' => $years,
        'placements' => $yearId === null ? [] : academic_management_list_placement_options($db, $institutionId),
    ];
}

function admission_conversion_set_status(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    string $actorType,
    int $admissionId,
    string $status,
    string $reviewNotes
): void {
    if (!in_array($status, ['under_review', 'approved', 'rejected'], true)) {
        throw new InvalidArgumentException('Choose a supported admission review status.');
    }
    if (mb_strlen($reviewNotes, 'UTF-8') > 20000 || preg_match('/[\x00-\x1F\x7F]/', $reviewNotes) === 1) {
        throw new InvalidArgumentException('Review notes contain unsupported characters or are too long.');
    }
    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            'SELECT status, student_enrolment_id, review_notes
             FROM admissions WHERE institution_id = ? AND id = ? FOR UPDATE'
        );
        $statement->bind_param('ii', $institutionId, $admissionId);
        $statement->execute();
        $before = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($before)) {
            throw new OutOfBoundsException('Admission application not found in this institution.');
        }
        if ($before['student_enrolment_id'] !== null || $before['status'] === 'enrolled') {
            throw new DomainException('A converted admission application cannot be reviewed again.');
        }
        if (!in_array($before['status'], ['submitted', 'under_review'], true)) {
            throw new DomainException('Only submitted or under-review applications can be reviewed.');
        }
        $statement = $db->prepare(
            'UPDATE admissions SET status = ?, review_notes = ?, approved_by = ?, approved_at = ?
             WHERE institution_id = ? AND id = ?'
        );
        $approvedAt = $status === 'approved' ? date('Y-m-d H:i:s') : null;
        $approvedBy = $status === 'approved'
            ? institution_audit_actor_user_id($actorUserId)
            : null;
        $statement->bind_param('ssisii', $status, $reviewNotes, $approvedBy, $approvedAt, $institutionId, $admissionId);
        $statement->execute();
        $statement->close();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            $actorType,
            'admission.' . $status,
            'admission',
            (string) $admissionId,
            ['status' => (string) $before['status']],
            ['status' => $status, 'review_notes' => $reviewNotes]
        );
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function admission_conversion_convert(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    string $actorType,
    int $admissionId,
    int $yearId,
    int $groupId,
    string $studentCode,
    ?string $admissionNumber
): array {
    if ($institutionId < 1
        || ($actorUserId < 1 && current_platform_admin() === null)
        || $admissionId < 1
        || $yearId < 1
        || $groupId < 1) {
        throw new InvalidArgumentException('Select a valid admission, active academic year, and placement.');
    }
    $admissionNumber = $admissionNumber === null || trim($admissionNumber) === ''
        ? null
        : academic_management_text($admissionNumber, 'admission number', 50);
    $studentCode = trim($studentCode);
    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            'SELECT id, student_id, parent_id, student_name, date_of_birth, admission_date,
                    status, student_enrolment_id
             FROM admissions WHERE institution_id = ? AND id = ? FOR UPDATE'
        );
        $statement->bind_param('ii', $institutionId, $admissionId);
        $statement->execute();
        $application = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($application)) {
            throw new OutOfBoundsException('Admission application not found in this institution.');
        }
        if ($application['status'] !== 'approved') {
            throw new DomainException('Only approved, incomplete admission applications can be converted.');
        }
        if ($application['student_enrolment_id'] !== null) {
            throw new DomainException('This admission application has already been converted.');
        }

        $statement = $db->prepare(
            'SELECT academic_model, active_academic_year_id
             FROM institutions WHERE id = ? FOR UPDATE'
        );
        $statement->bind_param('i', $institutionId);
        $statement->execute();
        $configuration = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($configuration)) {
            throw new OutOfBoundsException('Institution not found.');
        }
        if ($configuration['active_academic_year_id'] === null
            || (int) $configuration['active_academic_year_id'] !== $yearId) {
            throw new DomainException('Select the institution’s active academic year before conversion.');
        }
        $year = academic_management_require_active_year($db, $institutionId, $yearId);
        $group = academic_management_lock_group($db, $institutionId, $yearId, $groupId);
        academic_management_validate_group_for_model($group, $year['academic_model']);

        if ($application['student_id'] === null) {
            $studentCode = academic_management_text($studentCode, 'student ID', 50);
            student_management_assert_unique_code($db, $institutionId, $studentCode);
            $studentName = academic_management_text($application['student_name'], 'student name', 150);
            $studentStatus = 'admitted';
            $admissionDate = is_string($application['admission_date']) && $application['admission_date'] !== ''
                ? (string) $application['admission_date']
                : date('Y-m-d');
            $dateOfBirth = $application['date_of_birth'] === null
                ? null
                : (string) $application['date_of_birth'];
            $statement = $db->prepare(
                'INSERT INTO students
                    (institution_id, student_code, full_name, date_of_birth, admission_date, status)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $statement->bind_param(
                'isssss',
                $institutionId,
                $studentCode,
                $studentName,
                $dateOfBirth,
                $admissionDate,
                $studentStatus
            );
            $statement->execute();
            $studentId = (int) $statement->insert_id;
            $statement->close();
            write_audit_log(
                $db,
                $institutionId,
                $actorUserId,
                $actorType,
                'student.created_from_admission',
                'student',
                (string) $studentId,
                null,
                ['student_code' => $studentCode, 'full_name' => $studentName, 'admission_id' => $admissionId]
            );
        } else {
            $studentId = (int) $application['student_id'];
            $statement = $db->prepare(
                'SELECT student_code, full_name, status
                 FROM students WHERE institution_id = ? AND id = ? FOR UPDATE'
            );
            $statement->bind_param('ii', $institutionId, $studentId);
            $statement->execute();
            $student = $statement->get_result()->fetch_assoc();
            $statement->close();
            if (!is_array($student) || !in_array($student['status'], ['prospect', 'admitted', 'active'], true)) {
                throw new DomainException('The linked student is unavailable or cannot be enrolled; no profile changes were made.');
            }
            if ($student['status'] === 'prospect') {
                $statement = $db->prepare(
                    "UPDATE students SET status = 'admitted'
                     WHERE institution_id = ? AND id = ? AND status = 'prospect'"
                );
                $statement->bind_param('ii', $institutionId, $studentId);
                $statement->execute();
                $statement->close();
            }
            $studentCode = (string) $student['student_code'];
        }

        if ($application['parent_id'] !== null) {
            $parentIds = [];
            $statement = $db->prepare(
                "SELECT parent_id FROM parent_student_relationships
                 WHERE institution_id = ? AND student_id = ? AND status = 'active' FOR UPDATE"
            );
            $statement->bind_param('ii', $institutionId, $studentId);
            $statement->execute();
            $parentResult = $statement->get_result();
            while ($parentRow = $parentResult->fetch_assoc()) {
                $parentIds[] = (int) $parentRow['parent_id'];
            }
            $statement->close();
            $parentIds[] = (int) $application['parent_id'];
            student_management_sync_parents(
                $db,
                $institutionId,
                $studentId,
                array_values(array_unique($parentIds))
            );
        }

        $statement = $db->prepare(
            'SELECT id FROM student_enrolments
             WHERE institution_id = ? AND academic_year_id = ? AND student_id = ? FOR UPDATE'
        );
        $statement->bind_param('iii', $institutionId, $yearId, $studentId);
        $statement->execute();
        $existingEnrolment = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (is_array($existingEnrolment)) {
            throw new DomainException(
                'This student already has an enrolment in the selected academic year (enrolment '
                . (int) $existingEnrolment['id'] . '). Review that enrolment before converting this application.'
            );
        }

        $enrolmentStatus = 'active';
        $statement = $db->prepare(
            'INSERT INTO student_enrolments
                (institution_id, student_id, academic_year_id, admission_number, enrolment_status)
             VALUES (?, ?, ?, ?, ?)'
        );
        $statement->bind_param(
            'iiiss',
            $institutionId,
            $studentId,
            $yearId,
            $admissionNumber,
            $enrolmentStatus
        );
        $statement->execute();
        $enrolmentId = (int) $statement->insert_id;
        $statement->close();

        $reason = 'Converted from admission application #' . $admissionId . '.';
        academic_management_append_status_event(
            $db,
            $institutionId,
            $actorUserId,
            $enrolmentId,
            null,
            $enrolmentStatus,
            $reason
        );
        $placementId = academic_management_append_placement(
            $db,
            $institutionId,
            $actorUserId,
            $studentId,
            $enrolmentId,
            $yearId,
            $groupId,
            $reason
        );
        academic_management_sync_legacy_placement($db, $institutionId, $studentId, $yearId, $group);

        $courseId = $group['course_id'] === null ? null : (int) $group['course_id'];
        $classId = $group['class_id'] === null ? null : (int) $group['class_id'];
        $batchId = $group['batch_id'] === null ? null : (int) $group['batch_id'];
        $newStatus = 'enrolled';
        $statement = $db->prepare(
            'UPDATE admissions
             SET student_id = ?, academic_year_id = ?, course_id = ?, class_id = ?, batch_id = ?,
                 academic_group_id = ?, student_enrolment_id = ?, student_enrolment_placement_id = ?,
                 converted_at = CURRENT_TIMESTAMP, converted_by = ?, status = ?
             WHERE institution_id = ? AND id = ? AND status = \'approved\'
               AND student_enrolment_id IS NULL'
        );
        $convertedBy = institution_audit_actor_user_id($actorUserId);
        $statement->bind_param(
            'iiiiiiiiisii',
            $studentId,
            $yearId,
            $courseId,
            $classId,
            $batchId,
            $groupId,
            $enrolmentId,
            $placementId,
            $convertedBy,
            $newStatus,
            $institutionId,
            $admissionId
        );
        $statement->execute();
        if ($statement->affected_rows !== 1) {
            $statement->close();
            throw new DomainException('This admission application changed during conversion; reload and review it.');
        }
        $statement->close();

        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            $actorType,
            'admission.converted',
            'admission',
            (string) $admissionId,
            ['status' => 'approved'],
            [
                'status' => $newStatus,
                'student_id' => $studentId,
                'student_enrolment_id' => $enrolmentId,
                'academic_year_id' => $yearId,
                'academic_group_id' => $groupId,
                'placement_id' => $placementId,
            ]
        );
        $db->commit();
        return [
            'student_id' => $studentId,
            'student_enrolment_id' => $enrolmentId,
            'placement_id' => $placementId,
        ];
    } catch (mysqli_sql_exception $exception) {
        $db->rollback();
        if ((int) $exception->getCode() === 1062) {
            throw new DomainException(
                'A student or admission already exists for this institution and academic year. Review the existing record before retrying.',
                0,
                $exception
            );
        }
        throw $exception;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}
