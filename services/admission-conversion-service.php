<?php
declare(strict_types=1);

require_once __DIR__ . '/academic-management-service.php';
require_once __DIR__ . '/student-management-service.php';
require_once __DIR__ . '/otp-service.php';

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

function admission_conversion_list(
    mysqli $db,
    int $institutionId,
    string $search = '',
    string $status = '',
    ?string $dateFrom = null,
    ?string $dateTo = null,
    int $limit = 26,
    int $offset = 0
): array
{
    $where = ['a.institution_id = ?'];
    $values = [$institutionId];
    $types = 'i';
    if (in_array($status, ['draft', 'submitted', 'under_review', 'information_requested', 'approved', 'rejected', 'enrolled'], true)) {
        $where[] = 'a.status = ?';
        $values[] = $status;
        $types .= 's';
    }
    if ($search !== '') {
        $like = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $where[] = "(a.application_reference LIKE ? ESCAPE '!' OR a.applicant_name LIKE ? ESCAPE '!'
            OR a.applicant_mobile LIKE ? ESCAPE '!' OR a.student_name LIKE ? ESCAPE '!')";
        array_push($values, $like, $like, $like, $like);
        $types .= 'ssss';
    }
    if ($dateFrom !== null) {
        $where[] = 'a.created_at >= CONCAT(?, \' 00:00:00\')';
        $values[] = $dateFrom;
        $types .= 's';
    }
    if ($dateTo !== null) {
        $where[] = 'a.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
        $values[] = $dateTo;
        $types .= 's';
    }
    $limit = max(1, min(101, $limit));
    $offset = max(0, $offset);
    $values[] = $limit;
    $values[] = $offset;
    $types .= 'ii';
    $statement = $db->prepare(
        "SELECT a.id, a.status, a.application_reference, a.applicant_name, a.applicant_mobile, a.student_name,
                a.created_at, a.submitted_at, a.student_id, a.student_enrolment_id, a.converted_at,
                s.student_code, e.academic_year_id, ay.name AS academic_year_name
         FROM admissions a
         LEFT JOIN students s ON s.institution_id = a.institution_id AND s.id = a.student_id
         LEFT JOIN student_enrolments e
           ON e.institution_id = a.institution_id AND e.id = a.student_enrolment_id
         LEFT JOIN academic_years ay
           ON ay.institution_id = e.institution_id AND ay.id = e.academic_year_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY FIELD(a.status, 'submitted', 'under_review', 'information_requested',
                        'approved', 'draft', 'rejected', 'enrolled'),
                  a.created_at DESC, a.id DESC
         LIMIT ? OFFSET ?"
    );
    admission_conversion_bind($statement, $types, $values);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}

function admission_conversion_bind(mysqli_stmt $statement, string $types, array &$values): void
{
    $params = [$types];
    foreach ($values as &$value) {
        $params[] = &$value;
    }
    unset($value);
    if (!call_user_func_array([$statement, 'bind_param'], $params)) {
        throw new RuntimeException('Admission records could not be queried.');
    }
}

function admission_conversion_get(mysqli $db, int $institutionId, int $admissionId): ?array
{
    if ($institutionId < 1 || $admissionId < 1) {
        return null;
    }
    $statement = $db->prepare(
        'SELECT a.*, p.id AS linked_parent_id, p.full_name AS linked_parent_name,
                reviewer.full_name AS reviewer_name,
                s.student_code AS linked_student_code, s.full_name AS linked_student_name,
                e.enrolment_status, e.admission_number AS enrolment_admission_number,
                ay.name AS converted_year_name,
                g.group_type, c.name AS class_name, section.name AS section_name,
                course.name AS course_name, term.name AS term_name, b.name AS batch_name
         FROM admissions a
         LEFT JOIN parents p ON p.institution_id = a.institution_id AND p.id = a.parent_id
         LEFT JOIN users reviewer
           ON reviewer.institution_id = a.institution_id AND reviewer.id = a.approved_by
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

function admission_conversion_record_history(
    mysqli $db,
    int $institutionId,
    int $admissionId,
    ?string $fromStatus,
    string $toStatus,
    ?string $remarks,
    ?int $actorUserId,
    string $eventType = 'status_changed'
): void {
    $statement = $db->prepare(
        'INSERT INTO admission_status_history
            (institution_id, admission_id, from_status, to_status, remarks, changed_by, event_type)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $statement->bind_param(
        'iisssis',
        $institutionId,
        $admissionId,
        $fromStatus,
        $toStatus,
        $remarks,
        $actorUserId,
        $eventType
    );
    $statement->execute();
    $statement->close();
}

function admission_conversion_generate_student_code(
    mysqli $db,
    int $institutionId,
    int $academicYearId
): string {
    $statement = $db->prepare(
        'SELECT prefix FROM student_code_settings WHERE institution_id = ? LIMIT 1'
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $prefix = $statement->get_result()->fetch_column();
    $statement->close();
    if (!is_string($prefix) || $prefix === '') {
        $prefix = 'STU';
    }

    $statement = $db->prepare(
        'INSERT INTO student_code_sequences
            (institution_id, academic_year_id, start_number, next_number)
         VALUES (?, ?, 1, 1)
         ON DUPLICATE KEY UPDATE next_number = next_number'
    );
    $statement->bind_param('ii', $institutionId, $academicYearId);
    $statement->execute();
    $statement->close();
    $statement = $db->prepare(
        'SELECT GREATEST(next_number, start_number) FROM student_code_sequences
         WHERE institution_id = ? AND academic_year_id = ? FOR UPDATE'
    );
    $statement->bind_param('ii', $institutionId, $academicYearId);
    $statement->execute();
    $number = (int) $statement->get_result()->fetch_column();
    $statement->close();

    $statement = $db->prepare(
        'SELECT name, start_date FROM academic_years
         WHERE institution_id = ? AND id = ? LIMIT 1'
    );
    $statement->bind_param('ii', $institutionId, $academicYearId);
    $statement->execute();
    $academicYear = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_array($academicYear)) {
        throw new OutOfBoundsException('Academic year not found in this institution.');
    }
    preg_match('/\b(19|20)\d{2}\b/', (string) $academicYear['name'], $yearMatch);
    $year = $yearMatch[0] ?? substr((string) $academicYear['start_date'], 0, 4);
    while (true) {
        $code = $prefix . '-' . $year . '-' . str_pad((string) $number, 5, '0', STR_PAD_LEFT);
        $statement = $db->prepare(
            'UPDATE student_code_sequences SET next_number = ?
             WHERE institution_id = ? AND academic_year_id = ?'
        );
        $nextNumber = $number + 1;
        $statement->bind_param('iii', $nextNumber, $institutionId, $academicYearId);
        $statement->execute();
        $statement->close();
        $statement = $db->prepare(
            'SELECT id FROM students WHERE institution_id = ? AND student_code = ? LIMIT 1'
        );
        $statement->bind_param('is', $institutionId, $code);
        $statement->execute();
        $exists = $statement->get_result()->fetch_column();
        $statement->close();
        if ($exists === false) {
            return $code;
        }
        $number++;
    }
}

function admission_conversion_find_or_create_parent(
    mysqli $db,
    int $institutionId,
    string $name,
    string $mobile,
    ?string $email,
    string $relationship,
    array $application
): ?int {
    $mobile = trim($mobile);
    $email = $email === null ? '' : mb_strtolower(trim($email), 'UTF-8');
    $normalizedMobile = $mobile === '' ? '' : normalize_mobile_number($mobile);
    if ($mobile !== '' && $normalizedMobile === null) {
        throw new DomainException('A parent mobile number could not be matched. Correct the contact details before conversion.');
    }
    $mobile = $normalizedMobile ?? '';
    if (trim($name) === '') {
        return null;
    }
    $statement = $db->prepare(
        'SELECT p.id, p.user_id, u.user_type
         FROM parents p
         JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
         JOIN roles r ON r.id = u.role_id AND r.name = \'parent\'
         WHERE p.institution_id = ?
           AND ((? <> \'\' AND (p.mobile = ? OR u.mobile = ?))
             OR (? <> \'\' AND (p.email = ? OR u.email = ?)))
         FOR UPDATE'
    );
    $statement->bind_param(
        'issssss',
        $institutionId,
        $mobile,
        $mobile,
        $mobile,
        $email,
        $email,
        $email
    );
    $statement->execute();
    $matchedParents = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();

    $statement = $db->prepare(
        'SELECT id, user_type FROM users
         WHERE institution_id = ?
           AND ((? <> \'\' AND mobile = ?) OR (? <> \'\' AND email = ?))
         FOR UPDATE'
    );
    $statement->bind_param('issss', $institutionId, $mobile, $mobile, $email, $email);
    $statement->execute();
    $matchingUsers = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();

    $parentIdsByUser = [];
    foreach ($matchedParents as $matchedParent) {
        if ($matchedParent['user_type'] !== 'parent') {
            throw new DomainException('A matching contact belongs to a non-parent account. Resolve it before conversion.');
        }
        $parentIdsByUser[(int) $matchedParent['user_id']] = (int) $matchedParent['id'];
    }
    if (count($parentIdsByUser) > 1) {
        throw new DomainException('The supplied parent mobile and email match different parent accounts. Resolve the contact conflict before conversion.');
    }
    foreach ($matchingUsers as $matchingUser) {
        $userId = (int) $matchingUser['id'];
        if ($matchingUser['user_type'] !== 'parent' || !isset($parentIdsByUser[$userId])) {
            throw new DomainException('A matching contact belongs to another or incomplete account. Resolve it before conversion.');
        }
    }
    if ($parentIdsByUser !== []) {
        return (int) reset($parentIdsByUser);
    }
    if ($mobile === '') {
        return null;
    }
    $email = $email === '' ? null : $email;
    $statement = $db->prepare(
        "SELECT id FROM roles WHERE institution_id IS NULL AND is_system = 1
           AND BINARY name = BINARY 'parent' AND is_active = 1 LIMIT 1"
    );
    $statement->execute();
    $roleId = $statement->get_result()->fetch_column();
    $statement->close();
    if ($roleId === false) {
        throw new RuntimeException('The institution parent role is unavailable.');
    }
    $userStatus = 'pending';
    $statement = $db->prepare(
        "INSERT INTO users
            (institution_id, role_id, user_type, full_name, email, mobile, status)
         VALUES (?, ?, 'parent', ?, ?, ?, ?)"
    );
    $roleId = (int) $roleId;
    $statement->bind_param('iissss', $institutionId, $roleId, $name, $email, $mobile, $userStatus);
    $statement->execute();
    $userId = (int) $statement->insert_id;
    $statement->close();

    $parentStatus = 'pending_verification';
    $country = is_string($application['country'] ?? null) && $application['country'] !== ''
        ? $application['country']
        : 'India';
    $statement = $db->prepare(
        'INSERT INTO parents
            (institution_id, user_id, full_name, mobile, email, relationship_to_student,
             address_line1, address_line2, city, state, postal_code, country, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $statement->bind_param(
        'iisssssssssss',
        $institutionId,
        $userId,
        $name,
        $mobile,
        $email,
        $relationship,
        $application['address_line1'],
        $application['address_line2'],
        $application['city'],
        $application['state'],
        $application['postal_code'],
        $country,
        $parentStatus
    );
    $statement->execute();
    $parentId = (int) $statement->insert_id;
    $statement->close();
    return $parentId;
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
    if (!in_array($status, ['under_review', 'information_requested', 'approved', 'rejected'], true)) {
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
        if (!in_array($before['status'], ['submitted', 'under_review', 'information_requested'], true)) {
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
        admission_conversion_record_history(
            $db,
            $institutionId,
            $admissionId,
            (string) $before['status'],
            $status,
            $reviewNotes,
            institution_audit_actor_user_id($actorUserId),
            $status
        );
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
            'SELECT id, student_id, parent_id, applicant_name, applicant_mobile, applicant_email,
                    relationship_to_student, student_name, date_of_birth, admission_date, gender,
                    student_mobile, student_email, address_line1, address_line2, city, state,
                    postal_code, country, previous_school, father_name, father_mobile, father_email,
                    mother_name, mother_mobile, mother_email, guardian_name, guardian_mobile,
                    guardian_email, academic_year_id, requested_academic_group_id, course_id,
                    class_id, batch_id, status, student_enrolment_id
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
        if ($application['academic_year_id'] !== null
            && (int) $application['academic_year_id'] !== $yearId) {
            throw new DomainException('Choose a placement in the academic year selected on the application.');
        }
        foreach (['course_id', 'class_id', 'batch_id'] as $placementField) {
            if ($application[$placementField] !== null
                && (int) $application[$placementField] !== (int) ($group[$placementField] ?? 0)) {
                throw new DomainException('Choose a placement matching the academic selection on the application.');
            }
        }
        if ($application['requested_academic_group_id'] !== null
            && (int) $application['requested_academic_group_id'] !== $groupId) {
            throw new DomainException('Choose the preferred academic placement recorded on the application.');
        }

        if ($application['student_id'] === null) {
            $studentCode = trim($studentCode) === ''
                ? admission_conversion_generate_student_code($db, $institutionId, $yearId)
                : academic_management_text($studentCode, 'student ID', 50);
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
                    (institution_id, student_code, full_name, date_of_birth, gender, mobile, email,
                     address_line1, address_line2, city, state, postal_code, country, previous_school,
                     admission_date, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->bind_param(
                'isssssssssssssss',
                $institutionId,
                $studentCode,
                $studentName,
                $dateOfBirth,
                $application['gender'],
                $application['student_mobile'],
                $application['student_email'],
                $application['address_line1'],
                $application['address_line2'],
                $application['city'],
                $application['state'],
                $application['postal_code'],
                $application['country'],
                $application['previous_school'],
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
        if ($admissionNumber === null) {
            $admissionNumber = $studentCode;
        }

        $parentIds = [];
        if ($application['parent_id'] !== null) {
            $parentIds[] = (int) $application['parent_id'];
        }
        $parentCandidates = [
            [
                'name' => $application['father_name'],
                'mobile' => $application['father_mobile'],
                'email' => $application['father_email'],
                'relationship' => 'father',
            ],
            [
                'name' => $application['mother_name'],
                'mobile' => $application['mother_mobile'],
                'email' => $application['mother_email'],
                'relationship' => 'mother',
            ],
            [
                'name' => $application['guardian_name'],
                'mobile' => $application['guardian_mobile'],
                'email' => $application['guardian_email'],
                'relationship' => 'guardian',
            ],
        ];
        $hasNamedParent = false;
        foreach ($parentCandidates as $candidate) {
            if (is_string($candidate['name']) && trim($candidate['name']) !== '') {
                $hasNamedParent = true;
                $parentId = admission_conversion_find_or_create_parent(
                    $db,
                    $institutionId,
                    (string) $candidate['name'],
                    (string) ($candidate['mobile'] ?? ''),
                    is_string($candidate['email']) ? $candidate['email'] : null,
                    $candidate['relationship'],
                    $application
                );
                if ($parentId !== null) {
                    $parentIds[] = $parentId;
                }
            }
        }
        if (!$hasNamedParent && $application['parent_id'] === null) {
            $parentId = admission_conversion_find_or_create_parent(
                $db,
                $institutionId,
                (string) $application['applicant_name'],
                (string) $application['applicant_mobile'],
                is_string($application['applicant_email']) ? $application['applicant_email'] : null,
                in_array($application['relationship_to_student'], ['father', 'mother', 'guardian', 'other'], true)
                    ? (string) $application['relationship_to_student']
                    : 'guardian',
                $application
            );
            if ($parentId !== null) {
                $parentIds[] = $parentId;
            }
        }
        $parentIds = array_values(array_unique($parentIds));
        if ($parentIds !== []) {
            student_management_sync_parents(
                $db,
                $institutionId,
                $studentId,
                $parentIds
            );
        }

        $primaryParentId = $parentIds[0] ?? null;

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
             SET student_id = ?, parent_id = ?, academic_year_id = ?, course_id = ?, class_id = ?, batch_id = ?,
                 academic_group_id = ?, student_enrolment_id = ?, student_enrolment_placement_id = ?,
                 converted_at = CURRENT_TIMESTAMP, converted_by = ?, status = ?
             WHERE institution_id = ? AND id = ? AND status = \'approved\'
               AND student_enrolment_id IS NULL'
        );
        $convertedBy = institution_audit_actor_user_id($actorUserId);
        $statement->bind_param(
            'iiiiiiiiiisii',
            $studentId,
            $primaryParentId,
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
        admission_conversion_record_history(
            $db,
            $institutionId,
            $admissionId,
            'approved',
            $newStatus,
            'Converted to student enrolment #' . $enrolmentId . '.',
            institution_audit_actor_user_id($actorUserId),
            'student_registered'
        );

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
            'student_code' => $studentCode,
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
