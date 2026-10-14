<?php
declare(strict_types=1);

require_once __DIR__ . '/audit-service.php';

function attendance_valid_date(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function attendance_percentage(int $attendedDays, int $recordedDays): ?float
{
    if ($attendedDays < 0 || $recordedDays < 0 || $attendedDays > $recordedDays) {
        throw new InvalidArgumentException('Attendance totals are invalid.');
    }
    return $recordedDays === 0 ? null : round(100 * $attendedDays / $recordedDays, 2);
}

function attendance_parse_id(mixed $value): ?int
{
    if (!is_string($value) && !is_int($value)) {
        return null;
    }
    $parsed = filter_var($value, FILTER_VALIDATE_INT);
    return $parsed === false ? null : $parsed;
}

function attendance_validate_mark_input(array $input): array
{
    $academicYearId = attendance_parse_id($input['academic_year_id'] ?? null);
    $batchId = attendance_parse_id($input['batch_id'] ?? null);
    $attendanceDate = $input['attendance_date'] ?? null;
    $statuses = $input['attendance'] ?? null;
    if ($academicYearId === null || $academicYearId < 1
        || $batchId === null || $batchId < 1
        || !is_string($attendanceDate) || !attendance_valid_date($attendanceDate)
        || !is_array($statuses) || $statuses === [] || count($statuses) > 1000) {
        throw new InvalidArgumentException('Select a valid academic session, batch, date, and student attendance.');
    }

    $validatedStatuses = [];
    foreach ($statuses as $studentId => $status) {
        if ((!is_string($studentId) && !is_int($studentId))
            || filter_var((string) $studentId, FILTER_VALIDATE_INT) === false
            || (int) $studentId < 1
            || !is_string($status)
            || !in_array($status, ['present', 'absent', 'late'], true)) {
            throw new InvalidArgumentException('One or more attendance entries are invalid.');
        }
        $validatedStatuses[(int) $studentId] = $status;
    }
    if (count($validatedStatuses) !== count($statuses)) {
        throw new InvalidArgumentException('Duplicate or invalid student attendance entries were submitted.');
    }
    ksort($validatedStatuses);

    return [
        'academic_year_id' => $academicYearId,
        'batch_id' => $batchId,
        'attendance_date' => $attendanceDate,
        'attendance' => $validatedStatuses,
    ];
}

function attendance_validate_history_filters(array $input): array
{
    $classId = attendance_parse_id($input['class_id'] ?? '0');
    $batchId = attendance_parse_id($input['batch_id'] ?? '0');
    $studentQuery = $input['student_query'] ?? '';
    $dateFrom = $input['date_from'] ?? '';
    $dateTo = $input['date_to'] ?? '';
    if ($classId === null || $classId < 0 || $batchId === null || $batchId < 0
        || !is_string($studentQuery) || mb_strlen($studentQuery, 'UTF-8') > 150
        || !is_string($dateFrom) || !is_string($dateTo)
        || ($dateFrom !== '' && !attendance_valid_date($dateFrom))
        || ($dateTo !== '' && !attendance_valid_date($dateTo))
        || ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo)) {
        throw new InvalidArgumentException('Enter valid attendance history filters and date range.');
    }
    return [
        'class_id' => $classId,
        'batch_id' => $batchId,
        'student_query' => trim($studentQuery),
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
    ];
}

function attendance_validate_parent_filters(array $input): array
{
    $dateFrom = $input['date_from'] ?? '';
    $dateTo = $input['date_to'] ?? '';
    $status = $input['status'] ?? '';
    $page = attendance_parse_id($input['page'] ?? '1');
    if (!is_string($dateFrom) || !is_string($dateTo) || !is_string($status)
        || ($dateFrom !== '' && !attendance_valid_date($dateFrom))
        || ($dateTo !== '' && !attendance_valid_date($dateTo))
        || ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo)
        || !in_array($status, ['', 'present', 'absent', 'late'], true)
        || $page === null || $page < 1) {
        throw new InvalidArgumentException('Choose a valid attendance status, date range, and page.');
    }
    return [
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'status' => $status,
        'page' => $page,
    ];
}

function attendance_parent_bind_params(mysqli_stmt $statement, string $types, array $values): void
{
    $parameters = [$types];
    foreach ($values as &$value) {
        $parameters[] = &$value;
    }
    unset($value);
    if (!call_user_func_array([$statement, 'bind_param'], $parameters)) {
        throw new RuntimeException('The parent attendance query could not bind its parameters.');
    }
}

function attendance_parent_date_filter(array $filters): array
{
    $conditions = [];
    $types = '';
    $values = [];
    if ($filters['date_from'] !== '') {
        $conditions[] = 'ar.attendance_date >= ?';
        $types .= 's';
        $values[] = $filters['date_from'];
    }
    if ($filters['date_to'] !== '') {
        $conditions[] = 'ar.attendance_date <= ?';
        $types .= 's';
        $values[] = $filters['date_to'];
    }
    return [$conditions, $types, $values];
}

function attendance_parent_get_children(mysqli $db, int $parentId, int $institutionId): array
{
    $statement = $db->prepare(
        "SELECT s.id, s.student_code, s.full_name,
                c.name AS class_name, b.name AS batch_name
         FROM parent_student_relationships psr
         JOIN parents p ON p.institution_id = psr.institution_id AND p.id = psr.parent_id
         JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
         JOIN institutions i ON i.id = psr.institution_id AND i.status = 'active'
         JOIN students s ON s.institution_id = psr.institution_id AND s.id = psr.student_id
         LEFT JOIN classes c ON c.institution_id = s.institution_id AND c.id = s.class_id
         LEFT JOIN batches b ON b.institution_id = s.institution_id AND b.id = s.batch_id
         WHERE psr.institution_id = ? AND psr.parent_id = ?
           AND psr.status = 'active' AND psr.can_view_academics = 1
           AND p.status = 'active' AND u.user_type = 'parent' AND u.status = 'active'
           AND u.email_verified_at IS NOT NULL
         ORDER BY s.full_name, s.student_code"
    );
    $statement->bind_param('ii', $institutionId, $parentId);
    $statement->execute();
    $children = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    foreach ($children as &$child) {
        $child['id'] = (int) $child['id'];
    }
    unset($child);
    return $children;
}

function attendance_parent_get_summary(
    mysqli $db,
    int $parentId,
    int $institutionId,
    int $studentId,
    array $filters
): ?array {
    if ($parentId < 1 || $institutionId < 1 || $studentId < 1) {
        throw new InvalidArgumentException('A valid parent, institution, and student are required.');
    }
    $filters = attendance_validate_parent_filters($filters);
    [$dateConditions, $dateTypes, $dateValues] = attendance_parent_date_filter($filters);
    $query = "SELECT COUNT(*) AS total_days,
                     COALESCE(SUM(ar.status = 'present'), 0) AS present_days,
                     COALESCE(SUM(ar.status = 'absent'), 0) AS absent_days,
                     COALESCE(SUM(ar.status = 'late'), 0) AS late_days,
                     CASE WHEN COUNT(*) = 0 THEN NULL
                          ELSE ROUND(100.0 * SUM(ar.status IN ('present','late')) / COUNT(*), 2)
                     END AS attendance_percentage
              FROM attendance_records ar
              JOIN students s ON s.institution_id = ar.institution_id AND s.id = ar.student_id
              JOIN parent_student_relationships psr
                ON psr.institution_id = s.institution_id AND psr.student_id = s.id
              JOIN parents p ON p.institution_id = psr.institution_id AND p.id = psr.parent_id
              JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
              JOIN institutions i ON i.id = ar.institution_id AND i.status = 'active'
              WHERE ar.institution_id = ? AND ar.student_id = ? AND psr.parent_id = ?
                AND psr.status = 'active' AND psr.can_view_academics = 1
                AND p.status = 'active' AND u.user_type = 'parent' AND u.status = 'active'
                AND u.email_verified_at IS NOT NULL"
              . ($dateConditions === [] ? '' : ' AND ' . implode(' AND ', $dateConditions));
    $statement = $db->prepare($query);
    attendance_parent_bind_params(
        $statement,
        'iii' . $dateTypes,
        [$institutionId, $studentId, $parentId, ...$dateValues]
    );
    $statement->execute();
    $summary = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_array($summary)) {
        return null;
    }
    $summary['total_days'] = (int) $summary['total_days'];
    $summary['present_days'] = (int) $summary['present_days'];
    $summary['absent_days'] = (int) $summary['absent_days'];
    $summary['late_days'] = (int) $summary['late_days'];
    $summary['attendance_percentage'] = $summary['attendance_percentage'] === null
        ? null
        : (float) $summary['attendance_percentage'];
    return $summary;
}

function attendance_parent_list_records(
    mysqli $db,
    int $parentId,
    int $institutionId,
    int $studentId,
    array $filters,
    int $perPage = 15
): array {
    if ($parentId < 1 || $institutionId < 1 || $studentId < 1) {
        throw new InvalidArgumentException('A valid parent, institution, and student are required.');
    }
    $filters = attendance_validate_parent_filters($filters);
    $perPage = min(max($perPage, 1), 50);
    [$dateConditions, $dateTypes, $dateValues] = attendance_parent_date_filter($filters);
    $conditions = [
        'ar.institution_id = ?',
        'ar.student_id = ?',
        'psr.parent_id = ?',
        "psr.status = 'active'",
        'psr.can_view_academics = 1',
        "p.status = 'active'",
        "u.user_type = 'parent'",
        "u.status = 'active'",
        'u.email_verified_at IS NOT NULL',
        "i.status = 'active'",
    ];
    if ($dateConditions !== []) {
        array_push($conditions, ...$dateConditions);
    }
    $types = 'iii' . $dateTypes;
    $values = [$institutionId, $studentId, $parentId, ...$dateValues];
    if ($filters['status'] !== '') {
        $conditions[] = 'ar.status = ?';
        $types .= 's';
        $values[] = $filters['status'];
    }
    $from = implode(' AND ', $conditions);
    $base = " FROM attendance_records ar
              JOIN students s ON s.institution_id = ar.institution_id AND s.id = ar.student_id
              JOIN parent_student_relationships psr
                ON psr.institution_id = s.institution_id AND psr.student_id = s.id
              JOIN parents p ON p.institution_id = psr.institution_id AND p.id = psr.parent_id
              JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
              JOIN institutions i ON i.id = ar.institution_id";
    $countStatement = $db->prepare('SELECT COUNT(*)' . $base . ' WHERE ' . $from);
    attendance_parent_bind_params($countStatement, $types, $values);
    $countStatement->execute();
    $total = (int) $countStatement->get_result()->fetch_row()[0];
    $countStatement->close();

    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = min($filters['page'], $totalPages);
    $offset = ($page - 1) * $perPage;
    $rowsStatement = $db->prepare(
        'SELECT ar.id, ar.attendance_date, ar.status, ar.academic_year_id,
                ay.name AS academic_year_name, b.name AS batch_name, c.name AS class_name
        ' . $base . '
         JOIN academic_years ay
           ON ay.institution_id = ar.institution_id AND ay.id = ar.academic_year_id
         JOIN batches b ON b.institution_id = ar.institution_id AND b.id = ar.batch_id
         JOIN classes c ON c.institution_id = b.institution_id AND c.id = b.class_id
         WHERE ' . $from . '
         ORDER BY ar.attendance_date DESC, ar.id DESC LIMIT ? OFFSET ?'
    );
    $rowTypes = $types . 'ii';
    $rowValues = [...$values, $perPage, $offset];
    attendance_parent_bind_params($rowsStatement, $rowTypes, $rowValues);
    $rowsStatement->execute();
    $records = $rowsStatement->get_result()->fetch_all(MYSQLI_ASSOC);
    $rowsStatement->close();
    return [
        'records' => $records,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => $totalPages,
    ];
}

function attendance_get_academic_years(mysqli $db, int $institutionId): array
{
    $statement = $db->prepare(
        "SELECT id, name, start_date, end_date, status
         FROM academic_years
         WHERE institution_id = ? AND status IN ('active', 'closed')
         ORDER BY start_date DESC, name"
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}

function attendance_get_classes(mysqli $db, int $institutionId): array
{
    $statement = $db->prepare(
        'SELECT id, name FROM classes WHERE institution_id = ? ORDER BY name'
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}

function attendance_get_batches(mysqli $db, int $institutionId, bool $eligibleForMarking = false): array
{
    $statusClause = $eligibleForMarking ? "AND b.status IN ('active','full')" : '';
    $statement = $db->prepare(
        "SELECT b.id, b.name AS batch_name, b.class_id, c.name AS class_name, co.name AS course_name
         FROM batches b
         JOIN classes c ON c.institution_id = b.institution_id AND c.id = b.class_id
         JOIN courses co ON co.institution_id = b.institution_id AND co.id = b.course_id
         WHERE b.institution_id = ? $statusClause
         ORDER BY c.name, co.name, b.name"
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}

function attendance_get_students_for_marking(
    mysqli $db,
    int $institutionId,
    int $academicYearId,
    int $batchId,
    string $date
): array {
    if (!attendance_valid_date($date)) {
        throw new InvalidArgumentException('Enter a valid attendance date.');
    }
    $statement = $db->prepare(
        "SELECT s.id, s.student_code, s.full_name, ar.status AS attendance_status
         FROM students s
         JOIN batches b ON b.institution_id = s.institution_id AND b.id = s.batch_id
         JOIN classes c ON c.institution_id = b.institution_id AND c.id = b.class_id
         JOIN academic_years ay ON ay.institution_id = s.institution_id AND ay.id = s.academic_year_id
         LEFT JOIN attendance_records ar
           ON ar.institution_id = s.institution_id AND ar.student_id = s.id
          AND ar.batch_id = b.id AND ar.academic_year_id = ay.id AND ar.attendance_date = ?
         WHERE s.institution_id = ? AND s.academic_year_id = ? AND s.batch_id = ?
           AND s.class_id = b.class_id AND s.status IN ('admitted','active')
           AND b.status IN ('active','full') AND c.status = 'active'
           AND ay.status IN ('active','closed') AND ? BETWEEN ay.start_date AND ay.end_date
         ORDER BY s.full_name, s.student_code"
    );
    $statement->bind_param('siiis', $date, $institutionId, $academicYearId, $batchId, $date);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}

function attendance_save_batch(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    array $input
): int {
    $values = attendance_validate_mark_input($input);
    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            "SELECT b.id, b.class_id, ay.start_date, ay.end_date
             FROM batches b
             JOIN classes c ON c.institution_id = b.institution_id AND c.id = b.class_id
             JOIN academic_years ay ON ay.institution_id = b.institution_id AND ay.id = ?
             WHERE b.institution_id = ? AND b.id = ? AND b.status IN ('active','full')
               AND c.status = 'active' AND ay.status IN ('active','closed')
               AND ? BETWEEN ay.start_date AND ay.end_date
             LIMIT 1 FOR UPDATE"
        );
        $statement->bind_param(
            'iiis',
            $values['academic_year_id'],
            $institutionId,
            $values['batch_id'],
            $values['attendance_date']
        );
        $statement->execute();
        $batch = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($batch)) {
            throw new InvalidArgumentException('Select an eligible batch and academic session belonging to this institution.');
        }

        $studentIds = array_keys($values['attendance']);
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $types = 'iiii' . str_repeat('i', count($studentIds));
        $parameters = [
            $institutionId,
            $values['academic_year_id'],
            $values['batch_id'],
            (int) $batch['class_id'],
            ...$studentIds,
        ];
        $query = "SELECT id FROM students
                  WHERE institution_id = ? AND academic_year_id = ? AND batch_id = ?
                    AND class_id = ? AND status IN ('admitted','active')
                    AND id IN ($placeholders) ORDER BY id FOR UPDATE";
        $statement = $db->prepare($query);
        $statement->bind_param($types, ...$parameters);
        $statement->execute();
        $enrolledIds = array_map(
            'intval',
            array_column($statement->get_result()->fetch_all(MYSQLI_ASSOC), 'id')
        );
        $statement->close();
        sort($studentIds);
        if ($enrolledIds !== $studentIds) {
            throw new InvalidArgumentException('Attendance can only be saved for enrolled students in the selected batch and session.');
        }

        $statement = $db->prepare(
            'SELECT id, student_id, status
             FROM attendance_records
             WHERE institution_id = ? AND batch_id = ? AND attendance_date = ?
             FOR UPDATE'
        );
        $statement->bind_param('iis', $institutionId, $values['batch_id'], $values['attendance_date']);
        $statement->execute();
        $existingRows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
        $statement->close();
        $existingByStudent = [];
        foreach ($existingRows as $row) {
            $existingByStudent[(int) $row['student_id']] = $row;
        }

        $savedCount = 0;
        foreach ($values['attendance'] as $studentId => $status) {
            $old = $existingByStudent[$studentId] ?? null;
            if (is_array($old) && $old['status'] === $status) {
                continue;
            }
            if (is_array($old)) {
                $statement = $db->prepare(
                    'UPDATE attendance_records SET status = ?, updated_by = ?
                     WHERE institution_id = ? AND id = ?'
                );
                $statement->bind_param('siii', $status, $actorUserId, $institutionId, $old['id']);
                $statement->execute();
                $recordId = (int) $old['id'];
                $statement->close();
                $action = 'attendance.corrected';
                $oldValues = ['status' => (string) $old['status']];
            } else {
                $statement = $db->prepare(
                    'INSERT INTO attendance_records
                        (institution_id, academic_year_id, batch_id, student_id, attendance_date, status, recorded_by, updated_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $statement->bind_param(
                    'iiiissii',
                    $institutionId,
                    $values['academic_year_id'],
                    $values['batch_id'],
                    $studentId,
                    $values['attendance_date'],
                    $status,
                    $actorUserId,
                    $actorUserId
                );
                $statement->execute();
                $recordId = (int) $statement->insert_id;
                $statement->close();
                $action = 'attendance.created';
                $oldValues = null;
            }
            $actor = current_user();
            write_audit_log(
                $db,
                $institutionId,
                $actorUserId,
                is_array($actor) ? (string) $actor['user_type'] : 'admin',
                $action,
                'attendance',
                (string) $recordId,
                $oldValues,
                [
                    'student_id' => $studentId,
                    'academic_year_id' => $values['academic_year_id'],
                    'batch_id' => $values['batch_id'],
                    'attendance_date' => $values['attendance_date'],
                    'status' => $status,
                ]
            );
            $savedCount++;
        }
        $db->commit();
        return $savedCount;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function attendance_get_history(mysqli $db, int $institutionId, array $filters): array
{
    $conditions = ['ar.institution_id = ?'];
    $types = 'i';
    $parameters = [$institutionId];
    if ($filters['class_id'] > 0) {
        $conditions[] = 'b.class_id = ?';
        $types .= 'i';
        $parameters[] = $filters['class_id'];
    }
    if ($filters['batch_id'] > 0) {
        $conditions[] = 'ar.batch_id = ?';
        $types .= 'i';
        $parameters[] = $filters['batch_id'];
    }
    if ($filters['student_query'] !== '') {
        $studentPattern = '%' . strtr($filters['student_query'], ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $conditions[] = "(s.student_code LIKE ? ESCAPE '!' OR s.full_name LIKE ? ESCAPE '!')";
        $types .= 'ss';
        $parameters[] = $studentPattern;
        $parameters[] = $studentPattern;
    }
    if ($filters['date_from'] !== '') {
        $conditions[] = 'ar.attendance_date >= ?';
        $types .= 's';
        $parameters[] = $filters['date_from'];
    }
    if ($filters['date_to'] !== '') {
        $conditions[] = 'ar.attendance_date <= ?';
        $types .= 's';
        $parameters[] = $filters['date_to'];
    }
    $query = 'SELECT ar.id, ar.attendance_date, ar.status, ar.academic_year_id,
                     s.id AS student_id, s.student_code, s.full_name,
                     b.id AS batch_id, b.name AS batch_name, c.id AS class_id, c.name AS class_name
              FROM attendance_records ar
              JOIN students s ON s.institution_id = ar.institution_id AND s.id = ar.student_id
              JOIN batches b ON b.institution_id = ar.institution_id AND b.id = ar.batch_id
              JOIN classes c ON c.institution_id = b.institution_id AND c.id = b.class_id
              WHERE ' . implode(' AND ', $conditions) . '
              ORDER BY ar.attendance_date DESC, c.name, b.name, s.full_name
              LIMIT 1000';
    $statement = $db->prepare($query);
    $statement->bind_param($types, ...$parameters);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}

function attendance_get_report(
    mysqli $db,
    int $institutionId,
    int $classId,
    int $batchId,
    string $dateFrom,
    string $dateTo
): array
{
    if ($classId < 1 || $batchId < 0 || !attendance_valid_date($dateFrom) || !attendance_valid_date($dateTo)
        || $dateFrom > $dateTo) {
        throw new InvalidArgumentException('Select a class and valid report date range.');
    }
    $batchCondition = $batchId > 0 ? 'AND b.id = ?' : '';
    $statement = $db->prepare(
        "SELECT b.id AS batch_id, b.name AS batch_name, c.id AS class_id, c.name AS class_name,
                s.id AS student_id, s.student_code, s.full_name,
                COUNT(ar.id) AS recorded_days,
                SUM(CASE WHEN ar.status IN ('present','late') THEN 1 ELSE 0 END) AS attended_days,
                SUM(CASE WHEN ar.status = 'absent' THEN 1 ELSE 0 END) AS absent_days,
                SUM(CASE WHEN ar.status = 'late' THEN 1 ELSE 0 END) AS late_days,
                CASE WHEN COUNT(ar.id) = 0 THEN NULL
                     ELSE ROUND(100.0 * SUM(CASE WHEN ar.status IN ('present','late') THEN 1 ELSE 0 END) / COUNT(ar.id), 2)
                END AS attendance_percentage
         FROM batches b
         JOIN classes c ON c.institution_id = b.institution_id AND c.id = b.class_id
         JOIN students s ON s.institution_id = b.institution_id AND s.batch_id = b.id
             AND s.class_id = b.class_id AND s.status IN ('admitted','active')
         LEFT JOIN attendance_records ar ON ar.institution_id = s.institution_id
             AND ar.batch_id = b.id AND ar.student_id = s.id
             AND ar.attendance_date BETWEEN ? AND ?
         WHERE b.institution_id = ? AND c.id = ? $batchCondition
         GROUP BY b.id, b.name, c.id, c.name, s.id, s.student_code, s.full_name
         ORDER BY s.full_name, s.student_code"
    );
    if ($batchId > 0) {
        $statement->bind_param('ssiii', $dateFrom, $dateTo, $institutionId, $classId, $batchId);
    } else {
        $statement->bind_param('ssii', $dateFrom, $dateTo, $institutionId, $classId);
    }
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}
