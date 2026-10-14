<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/audit-service.php';

const STUDENT_MANAGEMENT_PAGE_SIZE = 15;
const STUDENT_MANAGEMENT_STATUSES = ['prospect', 'admitted', 'active', 'inactive', 'graduated', 'withdrawn'];
const STUDENT_MANAGEMENT_GENDERS = ['male', 'female', 'other', 'prefer_not_to_say'];

function student_management_bind_params(mysqli_stmt $statement, string $types, array &$values): void
{
    if ($types === '') {
        return;
    }

    $parameters = [$types];
    foreach ($values as &$value) {
        $parameters[] = &$value;
    }
    unset($value);
    if (!call_user_func_array([$statement, 'bind_param'], $parameters)) {
        throw new RuntimeException('The student management query could not bind its parameters.');
    }
}

function student_management_institution_id(): int
{
    $user = current_user();
    if ($user === null || !in_array($user['user_type'], ['super_admin', 'admin', 'staff'], true)) {
        throw new RuntimeException('An authorized institution user is required.');
    }

    return (int) $user['institution_id'];
}

function student_management_valid_date(string $date): bool
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1 || (int) substr($date, 0, 4) < 1000) {
        return false;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function student_management_validate_profile(array $input, bool $creating = true): array
{
    $errors = [];
    $fieldErrors = [];
    foreach (['full_name', 'student_code', 'admission_date', 'date_of_birth', 'gender'] as $field) {
        if (array_key_exists($field, $input) && !is_string($input[$field])) {
            $errors[] = 'One or more student details have an unsupported value.';
            $fieldErrors[$field] = 'Enter a valid value for this field.';
        }
    }
    $name = isset($input['full_name']) && is_string($input['full_name']) ? trim($input['full_name']) : '';
    $code = isset($input['student_code']) && is_string($input['student_code'])
        ? trim($input['student_code'])
        : '';
    $admissionDate = isset($input['admission_date']) && is_string($input['admission_date'])
        ? trim($input['admission_date'])
        : '';
    $dateOfBirthInput = isset($input['date_of_birth']) && is_string($input['date_of_birth'])
        ? trim($input['date_of_birth'])
        : '';
    $genderInput = isset($input['gender']) && is_string($input['gender'])
        ? trim($input['gender'])
        : '';
    $gender = $genderInput === '' ? null : $genderInput;

    if ($name === '' || mb_strlen($name, 'UTF-8') > 150 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
        $errors[] = 'Enter a student name using no more than 150 characters.';
        $fieldErrors['full_name'] = 'Enter a student name using no more than 150 characters.';
    }
    if ($code === '' || mb_strlen($code, 'UTF-8') > 50 || preg_match('/[\x00-\x1F\x7F]/', $code) === 1) {
        $errors[] = 'Enter a student ID using no more than 50 characters.';
        $fieldErrors['student_code'] = 'Enter a student ID using no more than 50 characters.';
    }
    if (!student_management_valid_date($admissionDate)) {
        $errors[] = 'Enter a valid admission date.';
        $fieldErrors['admission_date'] = 'Enter a valid admission date.';
    }
    if ($dateOfBirthInput !== '' && !student_management_valid_date($dateOfBirthInput)) {
        $errors[] = 'Enter a valid date of birth or leave it blank.';
        $fieldErrors['date_of_birth'] = 'Enter a valid date of birth or leave it blank.';
    }
    if ($gender !== null && !in_array($gender, STUDENT_MANAGEMENT_GENDERS, true)) {
        $errors[] = 'Choose a supported gender value.';
        $fieldErrors['gender'] = 'Choose a supported gender value.';
    }

    $ids = [];
    foreach (['academic_year_id', 'course_id', 'class_id', 'batch_id'] as $field) {
        $raw = $input[$field] ?? '';
        if (!is_string($raw) && !is_int($raw)) {
            $errors[] = 'One or more academic selections are invalid.';
            $fieldErrors[$field] = 'Choose a valid academic record.';
            $ids[$field] = null;
            continue;
        }
        $raw = trim((string) $raw);
        $id = $raw === '' ? null : filter_var($raw, FILTER_VALIDATE_INT);
        if ($id !== null && ($id === false || $id < 1)) {
            $errors[] = 'One or more academic selections are invalid.';
            $fieldErrors[$field] = 'Choose a valid academic record.';
            $ids[$field] = null;
            continue;
        }
        $ids[$field] = $id === false ? null : $id;
    }
    if ($ids['batch_id'] !== null && ($ids['course_id'] === null || $ids['class_id'] === null)) {
        $errors[] = 'Choose the course and class associated with the selected batch.';
        $fieldErrors['batch_id'] = 'Choose the course and class associated with this batch.';
    }

    $status = 'prospect';
    if ($creating) {
        if (array_key_exists('status', $input) && !is_string($input['status'])) {
            $errors[] = 'Choose a supported initial student status.';
            $fieldErrors['status'] = 'Choose a supported initial student status.';
        } else {
            $status = isset($input['status']) ? trim($input['status']) : 'prospect';
        }
        if (!in_array($status, ['prospect', 'admitted', 'active'], true)) {
            $errors[] = 'Choose a supported initial student status.';
            $fieldErrors['status'] = 'Choose a supported initial student status.';
        }
    }

    return [
        'data' => [
            'full_name' => $name,
            'student_code' => $code,
            'date_of_birth' => $dateOfBirthInput === '' ? null : $dateOfBirthInput,
            'gender' => $gender,
            'admission_date' => $admissionDate,
            'academic_year_id' => $ids['academic_year_id'],
            'course_id' => $ids['course_id'],
            'class_id' => $ids['class_id'],
            'batch_id' => $ids['batch_id'],
            'status' => $status,
        ],
        'errors' => array_values(array_unique($errors)),
        'field_errors' => $fieldErrors,
    ];
}

function student_management_parent_ids(mixed $rawIds): array
{
    if ($rawIds === null) {
        return [];
    }
    if (!is_array($rawIds)) {
        throw new InvalidArgumentException('The selected parent records are invalid.');
    }

    $ids = [];
    foreach ($rawIds as $rawId) {
        if (!is_string($rawId) && !is_int($rawId)) {
            throw new InvalidArgumentException('The selected parent records are invalid.');
        }
        $id = filter_var((string) $rawId, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            throw new InvalidArgumentException('The selected parent records are invalid.');
        }
        $ids[] = (int) $id;
    }
    if (count($ids) > 100) {
        throw new InvalidArgumentException('Select no more than 100 parents at a time.');
    }

    return array_values(array_unique($ids));
}

function student_management_old_form_values(array $input, string $form, ?int $studentId = null): array
{
    $values = ['form' => $form];
    if ($studentId !== null) {
        $values['student_id'] = $studentId;
    }
    foreach ([
        'full_name', 'student_code', 'date_of_birth', 'gender', 'admission_date',
        'academic_year_id', 'course_id', 'class_id', 'batch_id', 'status',
    ] as $field) {
        if (array_key_exists($field, $input)) {
            $values[$field] = is_string($input[$field]) || is_int($input[$field])
                ? trim((string) $input[$field])
                : '';
        }
    }
    try {
        $values['parent_ids'] = array_map('strval', student_management_parent_ids($input['parent_ids'] ?? []));
    } catch (InvalidArgumentException) {
        $values['parent_ids'] = [];
    }

    return $values;
}

function student_management_prepare_list_filters(
    int $institutionId,
    string $search,
    string $status,
    int $classId,
    int $batchId
): array {
    $where = ['s.institution_id = ?'];
    $values = [$institutionId];
    $types = 'i';
    if ($status !== '') {
        $where[] = 's.status = ?';
        $values[] = $status;
        $types .= 's';
    }
    if ($classId > 0) {
        $where[] = 's.class_id = ?';
        $values[] = $classId;
        $types .= 'i';
    }
    if ($batchId > 0) {
        $where[] = 's.batch_id = ?';
        $values[] = $batchId;
        $types .= 'i';
    }
    if ($search !== '') {
        $literalSearch = strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']);
        $pattern = '%' . $literalSearch . '%';
        $where[] = "(s.full_name LIKE ? ESCAPE '!' OR s.student_code LIKE ? ESCAPE '!'
            OR EXISTS (
                SELECT 1 FROM parent_student_relationships search_link
                JOIN parents search_parent
                  ON search_parent.institution_id = search_link.institution_id
                 AND search_parent.id = search_link.parent_id
                WHERE search_link.institution_id = s.institution_id
                  AND search_link.student_id = s.id AND search_link.status = 'active'
                  AND (search_parent.full_name LIKE ? ESCAPE '!'
                    OR search_parent.email LIKE ? ESCAPE '!'
                    OR search_parent.mobile LIKE ? ESCAPE '!')
            ))";
        array_push($values, $pattern, $pattern, $pattern, $pattern, $pattern);
        $types .= 'sssss';
    }

    return [implode(' AND ', $where), $types, $values];
}

function student_management_get_summary(mysqli $db, int $institutionId): array
{
    $statement = $db->prepare(
        "SELECT COUNT(*) AS total_count,
                COUNT(IF(s.status = 'active', 1, NULL)) AS active_count,
                COUNT(IF(s.status = 'inactive', 1, NULL)) AS inactive_count,
                COUNT(IF(NOT EXISTS (
                    SELECT 1 FROM parent_student_relationships psr
                    WHERE psr.institution_id = s.institution_id
                      AND psr.student_id = s.id AND psr.status = 'active'
                ), 1, NULL)) AS without_parent_count
         FROM students s WHERE s.institution_id = ?"
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $summary = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_array($summary)) {
        throw new RuntimeException('Student summary data could not be loaded.');
    }

    return array_map('intval', $summary);
}

function student_management_list(
    mysqli $db,
    int $institutionId,
    string $search,
    string $status,
    int $classId,
    int $batchId,
    int $page
): array {
    [$where, $filterTypes, $filterValues] = student_management_prepare_list_filters(
        $institutionId,
        $search,
        $status,
        $classId,
        $batchId
    );
    $countStatement = $db->prepare("SELECT COUNT(*) AS result_count FROM students s WHERE {$where}");
    student_management_bind_params($countStatement, $filterTypes, $filterValues);
    $countStatement->execute();
    $total = (int) $countStatement->get_result()->fetch_assoc()['result_count'];
    $countStatement->close();

    $pageSize = STUDENT_MANAGEMENT_PAGE_SIZE;
    $totalPages = max(1, (int) ceil($total / $pageSize));
    $page = min(max(1, $page), $totalPages);
    $queryValues = [...$filterValues, $pageSize, ($page - 1) * $pageSize];
    $queryTypes = $filterTypes . 'ii';
    $statement = $db->prepare(
        "SELECT s.id, s.student_code, s.full_name, s.admission_date, s.status,
                c.name AS class_name, co.name AS course_name, b.name AS batch_name,
                (SELECT GROUP_CONCAT(DISTINCT p.full_name ORDER BY p.full_name SEPARATOR ', ')
                 FROM parent_student_relationships psr
                 JOIN parents p ON p.institution_id = psr.institution_id AND p.id = psr.parent_id
                 WHERE psr.institution_id = s.institution_id AND psr.student_id = s.id
                   AND psr.status = 'active') AS parent_names
         FROM students s
         LEFT JOIN classes c ON c.institution_id = s.institution_id AND c.id = s.class_id
         LEFT JOIN courses co ON co.institution_id = s.institution_id AND co.id = s.course_id
         LEFT JOIN batches b ON b.institution_id = s.institution_id AND b.id = s.batch_id
         WHERE {$where}
         ORDER BY s.created_at DESC, s.id DESC
         LIMIT ? OFFSET ?"
    );
    student_management_bind_params($statement, $queryTypes, $queryValues);
    $statement->execute();
    $result = $statement->get_result();
    $students = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $students[] = $row;
    }
    $statement->close();

    return [
        'students' => $students,
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => $totalPages,
    ];
}

function student_management_get_options(mysqli $db, int $institutionId): array
{
    $options = [];
    $queries = [
        'academic_years' => "SELECT id, name, status FROM academic_years WHERE institution_id = ?
             ORDER BY (status = 'active') DESC, start_date DESC, name",
        'courses' => 'SELECT id, name, code, status FROM courses WHERE institution_id = ? ORDER BY name',
        'classes' => 'SELECT id, name, code, status FROM classes WHERE institution_id = ? ORDER BY name',
        'batches' =>
            'SELECT b.id, b.name, b.code, b.course_id, b.class_id, b.status,
                    co.name AS course_name, c.name AS class_name
             FROM batches b
             JOIN courses co ON co.institution_id = b.institution_id AND co.id = b.course_id
             JOIN classes c ON c.institution_id = b.institution_id AND c.id = b.class_id
             WHERE b.institution_id = ? ORDER BY co.name, c.name, b.name',
    ];
    foreach ($queries as $key => $query) {
        $statement = $db->prepare($query);
        $statement->bind_param('i', $institutionId);
        $statement->execute();
        $result = $statement->get_result();
        $options[$key] = [];
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int) $row['id'];
            $options[$key][] = $row;
        }
        $statement->close();
    }

    $parentStatement = $db->prepare(
        "SELECT p.id, p.full_name, p.mobile, COALESCE(p.email, u.email) AS email,
                p.relationship_to_student, p.status
         FROM parents p
         JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
         JOIN roles r ON r.id = u.role_id AND r.name = 'parent'
         WHERE p.institution_id = ? AND u.user_type = 'parent'
         ORDER BY p.full_name"
    );
    $parentStatement->bind_param('i', $institutionId);
    $parentStatement->execute();
    $result = $parentStatement->get_result();
    $options['parents'] = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $options['parents'][] = $row;
    }
    $parentStatement->close();

    return $options;
}

function student_management_get_student(mysqli $db, int $institutionId, int $studentId): ?array
{
    $statement = $db->prepare(
        'SELECT s.id, s.institution_id, s.academic_year_id, s.course_id, s.class_id,
                s.batch_id, s.student_code, s.full_name, s.date_of_birth, s.gender,
                s.admission_date, s.status, s.created_at, s.updated_at,
                i.name AS institution_name, i.email AS institution_email,
                i.phone AS institution_phone, i.address_line1 AS institution_address_line1,
                i.address_line2 AS institution_address_line2, i.city AS institution_city,
                i.state AS institution_state, i.postal_code AS institution_postal_code,
                i.country AS institution_country, i.logo_path AS institution_logo_path,
                ay.name AS academic_year_name, co.name AS course_name,
                c.name AS class_name, b.name AS batch_name
         FROM students s
         JOIN institutions i ON i.id = s.institution_id
         LEFT JOIN academic_years ay ON ay.institution_id = s.institution_id AND ay.id = s.academic_year_id
         LEFT JOIN courses co ON co.institution_id = s.institution_id AND co.id = s.course_id
         LEFT JOIN classes c ON c.institution_id = s.institution_id AND c.id = s.class_id
         LEFT JOIN batches b ON b.institution_id = s.institution_id AND b.id = s.batch_id
         WHERE s.institution_id = ? AND s.id = ? LIMIT 1'
    );
    $statement->bind_param('ii', $institutionId, $studentId);
    $statement->execute();
    $student = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_array($student)) {
        return null;
    }
    $student['id'] = (int) $student['id'];

    $statement = $db->prepare(
        "SELECT p.id, p.full_name, COALESCE(p.email, u.email) AS email,
                p.mobile, p.address_line1, p.address_line2, p.city, p.state,
                p.postal_code, p.country, p.status AS parent_status,
                psr.relationship_type, psr.is_primary,
                psr.can_view_academics, psr.can_view_fees, psr.can_make_payments
         FROM parent_student_relationships psr
         JOIN parents p ON p.institution_id = psr.institution_id AND p.id = psr.parent_id
         JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
         WHERE psr.institution_id = ? AND psr.student_id = ? AND psr.status = 'active'
         ORDER BY psr.is_primary DESC, p.full_name"
    );
    $statement->bind_param('ii', $institutionId, $studentId);
    $statement->execute();
    $result = $statement->get_result();
    $student['parents'] = [];
    $student['parent_ids'] = [];
    while ($parent = $result->fetch_assoc()) {
        $parent['id'] = (int) $parent['id'];
        $student['parent_ids'][] = $parent['id'];
        $student['parents'][] = $parent;
    }
    $statement->close();

    $statement = $db->prepare(
        'SELECT COUNT(*) AS assignment_count,
                COALESCE(SUM(total_paid), 0) AS total_paid,
                COALESCE(SUM(outstanding_amount), 0) AS outstanding_amount
         FROM student_fee_assignments
         WHERE institution_id = ? AND student_id = ?'
    );
    $statement->bind_param('ii', $institutionId, $studentId);
    $statement->execute();
    $student['fee_summary'] = $statement->get_result()->fetch_assoc();
    $statement->close();

    return $student;
}

function student_management_assert_references(mysqli $db, int $institutionId, array $profile): void
{
    foreach ([
        'academic_year_id' => 'academic_years',
        'course_id' => 'courses',
        'class_id' => 'classes',
    ] as $field => $table) {
        if ($profile[$field] === null) {
            continue;
        }
        $statement = $db->prepare("SELECT id FROM {$table} WHERE institution_id = ? AND id = ? LIMIT 1");
        $statement->bind_param('ii', $institutionId, $profile[$field]);
        $statement->execute();
        $found = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($found)) {
            throw new InvalidArgumentException('One or more selected academic records are unavailable in this institution.');
        }
    }
    if ($profile['batch_id'] !== null) {
        $statement = $db->prepare(
            'SELECT id FROM batches
             WHERE institution_id = ? AND id = ? AND course_id = ? AND class_id = ? LIMIT 1'
        );
        $statement->bind_param(
            'iiii',
            $institutionId,
            $profile['batch_id'],
            $profile['course_id'],
            $profile['class_id']
        );
        $statement->execute();
        $found = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($found)) {
            throw new InvalidArgumentException('The selected batch does not belong to the selected course and class.');
        }
    }
}

function student_management_assert_unique_code(
    mysqli $db,
    int $institutionId,
    string $studentCode,
    ?int $excludeStudentId = null
): void {
    $query = 'SELECT id FROM students WHERE institution_id = ? AND student_code = ?';
    if ($excludeStudentId !== null) {
        $query .= ' AND id <> ?';
    }
    $query .= ' LIMIT 1';
    $statement = $db->prepare($query);
    if ($excludeStudentId === null) {
        $statement->bind_param('is', $institutionId, $studentCode);
    } else {
        $statement->bind_param('isi', $institutionId, $studentCode, $excludeStudentId);
    }
    $statement->execute();
    $found = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (is_array($found)) {
        throw new InvalidArgumentException('That student ID is already in use in this institution.');
    }
}

function student_management_valid_parent_ids(
    mysqli $db,
    int $institutionId,
    array $parentIds
): void {
    if ($parentIds === []) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($parentIds), '?'));
    $query = "SELECT p.id
              FROM parents p
              JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
              JOIN roles r ON r.id = u.role_id AND r.name = 'parent'
              WHERE p.institution_id = ? AND p.id IN ({$placeholders})
                AND u.user_type = 'parent'";
    $values = [$institutionId, ...$parentIds];
    $statement = $db->prepare($query);
    student_management_bind_params($statement, 'i' . str_repeat('i', count($parentIds)), $values);
    $statement->execute();
    $validIds = [];
    $result = $statement->get_result();
    while ($row = $result->fetch_assoc()) {
        $validIds[] = (int) $row['id'];
    }
    $statement->close();
    sort($validIds);
    $expectedIds = $parentIds;
    sort($expectedIds);
    if ($validIds !== $expectedIds) {
        throw new InvalidArgumentException('One or more selected parents are unavailable in this institution.');
    }
}

function student_management_sync_parents(
    mysqli $db,
    int $institutionId,
    int $studentId,
    array $parentIds
): void {
    $statement = $db->prepare(
        "SELECT parent_id FROM parent_student_relationships
         WHERE institution_id = ? AND student_id = ? AND status = 'active'
         FOR UPDATE"
    );
    $statement->bind_param('ii', $institutionId, $studentId);
    $statement->execute();
    $result = $statement->get_result();
    $existingIds = [];
    while ($row = $result->fetch_assoc()) {
        $existingIds[] = (int) $row['parent_id'];
    }
    $statement->close();
    foreach (array_diff($existingIds, $parentIds) as $removedId) {
        $removedId = (int) $removedId;
        $statement = $db->prepare(
            "UPDATE parent_student_relationships SET status = 'revoked'
             WHERE institution_id = ? AND student_id = ? AND parent_id = ? AND status = 'active'"
        );
        $statement->bind_param('iii', $institutionId, $studentId, $removedId);
        $statement->execute();
        $statement->close();
    }
    foreach ($parentIds as $parentId) {
        $statement = $db->prepare(
            'SELECT id, status FROM parent_student_relationships
             WHERE institution_id = ? AND student_id = ? AND parent_id = ? LIMIT 1'
        );
        $statement->bind_param('iii', $institutionId, $studentId, $parentId);
        $statement->execute();
        $existing = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (is_array($existing)) {
            if ($existing['status'] === 'active') {
                continue;
            }
            $relationshipId = (int) $existing['id'];
            $statement = $db->prepare(
                "UPDATE parent_student_relationships SET status = 'active'
                 WHERE id = ? AND institution_id = ? AND student_id = ? AND parent_id = ?"
            );
            $statement->bind_param('iiii', $relationshipId, $institutionId, $studentId, $parentId);
            $statement->execute();
            $statement->close();
            continue;
        }

        $statement = $db->prepare(
            'SELECT relationship_to_student FROM parents WHERE institution_id = ? AND id = ? LIMIT 1'
        );
        $statement->bind_param('ii', $institutionId, $parentId);
        $statement->execute();
        $parent = $statement->get_result()->fetch_assoc();
        $statement->close();
        $relationship = strtolower(trim((string) ($parent['relationship_to_student'] ?? 'guardian')));
        if (!in_array($relationship, ['father', 'mother', 'guardian', 'other'], true)) {
            $relationship = 'guardian';
        }
        $statement = $db->prepare(
            "INSERT INTO parent_student_relationships
                (institution_id, parent_id, student_id, relationship_type, status)
             VALUES (?, ?, ?, ?, 'active')"
        );
        $statement->bind_param('iiis', $institutionId, $parentId, $studentId, $relationship);
        $statement->execute();
        $statement->close();
    }
}

function student_management_audit_values(array $student, array $parentIds): array
{
    return [
        'student_code' => (string) $student['student_code'],
        'full_name' => (string) $student['full_name'],
        'date_of_birth' => $student['date_of_birth'],
        'gender' => $student['gender'],
        'admission_date' => (string) $student['admission_date'],
        'academic_year_id' => $student['academic_year_id'] === null ? null : (int) $student['academic_year_id'],
        'course_id' => $student['course_id'] === null ? null : (int) $student['course_id'],
        'class_id' => $student['class_id'] === null ? null : (int) $student['class_id'],
        'batch_id' => $student['batch_id'] === null ? null : (int) $student['batch_id'],
        'status' => (string) $student['status'],
        'parent_ids' => array_values(array_map('intval', $parentIds)),
    ];
}

function student_management_create(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    string $actorType,
    array $profile,
    array $parentIds
): int {
    $db->begin_transaction();
    try {
        student_management_assert_unique_code($db, $institutionId, $profile['student_code']);
        student_management_assert_references($db, $institutionId, $profile);
        student_management_valid_parent_ids($db, $institutionId, $parentIds);
        $statement = $db->prepare(
            'INSERT INTO students (
                institution_id, academic_year_id, course_id, class_id, batch_id,
                student_code, full_name, date_of_birth, gender, admission_date, status
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->bind_param(
            'iiiiissssss',
            $institutionId,
            $profile['academic_year_id'],
            $profile['course_id'],
            $profile['class_id'],
            $profile['batch_id'],
            $profile['student_code'],
            $profile['full_name'],
            $profile['date_of_birth'],
            $profile['gender'],
            $profile['admission_date'],
            $profile['status']
        );
        $statement->execute();
        $studentId = (int) $db->insert_id;
        $statement->close();
        student_management_sync_parents($db, $institutionId, $studentId, $parentIds);
        $student = $profile;
        $student['status'] = $profile['status'];
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            $actorType,
            'student.created',
            'student',
            (string) $studentId,
            null,
            student_management_audit_values($student, $parentIds)
        );
        $db->commit();

        return $studentId;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function student_management_update(
    mysqli $db,
    int $institutionId,
    int $studentId,
    int $actorUserId,
    string $actorType,
    array $profile,
    array $parentIds
): void {
    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            'SELECT id FROM students WHERE institution_id = ? AND id = ? FOR UPDATE'
        );
        $statement->bind_param('ii', $institutionId, $studentId);
        $statement->execute();
        $found = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($found)) {
            throw new OutOfBoundsException('The student record was not found in this institution.');
        }
        student_management_assert_unique_code($db, $institutionId, $profile['student_code'], $studentId);
        student_management_assert_references($db, $institutionId, $profile);
        student_management_valid_parent_ids($db, $institutionId, $parentIds);
        $old = student_management_get_student($db, $institutionId, $studentId);
        if ($old === null) {
            throw new OutOfBoundsException('The student record was not found in this institution.');
        }
        $statement = $db->prepare(
            'UPDATE students
             SET academic_year_id = ?, course_id = ?, class_id = ?, batch_id = ?,
                 student_code = ?, full_name = ?, date_of_birth = ?, gender = ?,
                 admission_date = ?
             WHERE institution_id = ? AND id = ?'
        );
        $statement->bind_param(
            'iiiisssssii',
            $profile['academic_year_id'],
            $profile['course_id'],
            $profile['class_id'],
            $profile['batch_id'],
            $profile['student_code'],
            $profile['full_name'],
            $profile['date_of_birth'],
            $profile['gender'],
            $profile['admission_date'],
            $institutionId,
            $studentId
        );
        $statement->execute();
        $statement->close();
        student_management_sync_parents($db, $institutionId, $studentId, $parentIds);
        $updated = $profile;
        $updated['status'] = $old['status'];
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            $actorType,
            'student.updated',
            'student',
            (string) $studentId,
            student_management_audit_values($old, $old['parent_ids']),
            student_management_audit_values($updated, $parentIds)
        );
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function student_management_toggle_status(
    mysqli $db,
    int $institutionId,
    int $studentId,
    int $actorUserId,
    string $actorType
): string {
    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            'SELECT id, institution_id, student_code, full_name, date_of_birth, gender,
                    admission_date, academic_year_id, course_id, class_id, batch_id, status
             FROM students WHERE institution_id = ? AND id = ? FOR UPDATE'
        );
        $statement->bind_param('ii', $institutionId, $studentId);
        $statement->execute();
        $student = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($student)) {
            throw new OutOfBoundsException('The student record was not found in this institution.');
        }
        if (!in_array($student['status'], ['prospect', 'admitted', 'active', 'inactive'], true)) {
            throw new InvalidArgumentException('Graduated or withdrawn students cannot be toggled from this page.');
        }
        $oldStatus = (string) $student['status'];
        $newStatus = $oldStatus === 'active' ? 'inactive' : 'active';
        $statement = $db->prepare(
            'UPDATE students SET status = ? WHERE institution_id = ? AND id = ?'
        );
        $statement->bind_param('sii', $newStatus, $institutionId, $studentId);
        $statement->execute();
        $statement->close();
        $student['status'] = $newStatus;
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            $actorType,
            'student.status_changed',
            'student',
            (string) $studentId,
            ['status' => $oldStatus],
            ['status' => $newStatus]
        );
        $db->commit();

        return $newStatus;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function student_management_handle_failure(Throwable $exception, string $operation): void
{
    if ($exception instanceof InvalidArgumentException || $exception instanceof OutOfBoundsException) {
        set_flash('danger', $exception->getMessage());
        return;
    }
    if ($exception instanceof mysqli_sql_exception && $exception->getCode() === 1062) {
        set_flash('danger', 'That student ID or parent-student relationship is already registered.');
        return;
    }
    log_error('student_management_operation_failed', [
        'operation' => $operation,
        'error_type' => get_class($exception),
        'error_code' => $exception->getCode(),
    ]);
    set_flash('danger', 'The student record could not be saved. Please try again or contact an administrator.');
}

function student_management_failure_field_errors(Throwable $exception): array
{
    $duplicateCode = $exception instanceof InvalidArgumentException
        && $exception->getMessage() === 'That student ID is already in use in this institution.';
    $concurrentDuplicateCode = $exception instanceof mysqli_sql_exception
        && $exception->getCode() === 1062
        && str_contains($exception->getMessage(), 'uq_students_institution_code');
    if ($duplicateCode || $concurrentDuplicateCode) {
        return ['student_code' => 'That student ID is already in use in this institution.'];
    }

    return [];
}
