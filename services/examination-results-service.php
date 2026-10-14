<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/audit-service.php';

function examination_results_decimal_hundredths(string $value): int
{
    $value = trim($value);
    if (preg_match('/^[0-9]{1,6}(?:\.[0-9]{1,2})?$/D', $value) !== 1) {
        throw new InvalidArgumentException('Enter a non-negative value with up to two decimal places.');
    }
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

    return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
}

function examination_results_normalize_decimal(string $value): string
{
    $hundredths = examination_results_decimal_hundredths($value);

    return intdiv($hundredths, 100) . '.' . str_pad((string) ($hundredths % 100), 2, '0', STR_PAD_LEFT);
}

function examination_results_validate_marks(string $marks, string $maximum): string
{
    $marksHundredths = examination_results_decimal_hundredths($marks);
    if ($marksHundredths > examination_results_decimal_hundredths($maximum)) {
        throw new InvalidArgumentException('Marks cannot exceed the examination maximum.');
    }

    return examination_results_normalize_decimal($marks);
}

function examination_results_percentage(string $marks, string $maximum): string
{
    $marksHundredths = examination_results_decimal_hundredths($marks);
    $maximumHundredths = examination_results_decimal_hundredths($maximum);
    if ($maximumHundredths < 1 || $marksHundredths > $maximumHundredths) {
        throw new InvalidArgumentException('The result values are invalid.');
    }

    return number_format(($marksHundredths / $maximumHundredths) * 100, 2, '.', '');
}

function examination_results_validate_exam_input(array $input): array
{
    $name = $input['name'] ?? null;
    $date = $input['exam_date'] ?? null;
    $batchId = filter_var($input['batch_id'] ?? null, FILTER_VALIDATE_INT);
    $academicYearId = filter_var($input['academic_year_id'] ?? null, FILTER_VALIDATE_INT);
    $maximum = $input['max_marks'] ?? null;
    if (!is_string($name) || trim($name) === '' || mb_strlen(trim($name)) > 150
        || !is_string($date) || !is_string($maximum)
        || $batchId === false || $batchId < 1
        || $academicYearId === false || $academicYearId < 1) {
        throw new InvalidArgumentException('Provide an examination name, date, session, batch, and maximum marks.');
    }
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Enter a valid examination date.');
    }
    $maximum = examination_results_normalize_decimal($maximum);
    if (examination_results_decimal_hundredths($maximum) < 1) {
        throw new InvalidArgumentException('Maximum marks must be greater than zero.');
    }
    $description = $input['description'] ?? '';
    if (!is_string($description) || mb_strlen($description) > 5000) {
        throw new InvalidArgumentException('The examination description is too long.');
    }

    return [
        'name' => trim($name),
        'exam_date' => $date,
        'batch_id' => (int) $batchId,
        'academic_year_id' => (int) $academicYearId,
        'max_marks' => $maximum,
        'description' => trim($description) === '' ? null : trim($description),
    ];
}

function examination_results_get_academic_years(mysqli $db, int $institutionId): array
{
    $statement = $db->prepare(
        "SELECT id, name, start_date, end_date, status
         FROM academic_years
         WHERE institution_id = ? AND status IN ('active', 'closed')
         ORDER BY start_date DESC, name"
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $years = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();

    return $years;
}

function examination_results_get_batches(mysqli $db, int $institutionId): array
{
    $statement = $db->prepare(
        "SELECT b.id, b.name AS batch_name, c.id AS class_id,
                c.name AS class_name, co.name AS course_name
         FROM batches b
         JOIN classes c ON c.institution_id = b.institution_id AND c.id = b.class_id
         JOIN courses co ON co.institution_id = b.institution_id AND co.id = b.course_id
         WHERE b.institution_id = ? AND b.status IN ('active', 'full')
         ORDER BY c.name, co.name, b.name"
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $batches = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();

    return $batches;
}

function examination_results_get_classes(mysqli $db, int $institutionId): array
{
    $statement = $db->prepare(
        'SELECT id, name FROM classes WHERE institution_id = ? ORDER BY name'
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $classes = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();

    return $classes;
}

function examination_results_get_exam(mysqli $db, int $institutionId, int $examId): ?array
{
    $statement = $db->prepare(
        'SELECT e.id, e.institution_id, e.batch_id, e.course_id, e.academic_year_id,
                e.name, e.exam_date, e.max_marks, e.results_published_at, e.description,
                ay.name AS academic_year_name, b.name AS batch_name,
                c.name AS class_name, co.name AS course_name
         FROM examinations e
         JOIN batches b ON b.institution_id = e.institution_id AND b.id = e.batch_id
         JOIN classes c ON c.institution_id = b.institution_id AND c.id = b.class_id
         JOIN courses co ON co.institution_id = b.institution_id AND co.id = b.course_id
         LEFT JOIN academic_years ay
           ON ay.institution_id = e.institution_id AND ay.id = e.academic_year_id
         WHERE e.institution_id = ? AND e.id = ?
         LIMIT 1'
    );
    $statement->bind_param('ii', $institutionId, $examId);
    $statement->execute();
    $exam = $statement->get_result()->fetch_assoc();
    $statement->close();

    return is_array($exam) ? $exam : null;
}

function examination_results_save_exam(
    mysqli $db,
    int $institutionId,
    int $userId,
    array $input,
    ?int $examId = null
): int {
    $exam = examination_results_validate_exam_input($input);
    $batchStatement = $db->prepare(
        "SELECT b.course_id, ay.start_date, ay.end_date
         FROM batches b
         JOIN classes c ON c.institution_id = b.institution_id AND c.id = b.class_id
         JOIN courses co ON co.institution_id = b.institution_id AND co.id = b.course_id
         JOIN academic_years ay
           ON ay.institution_id = b.institution_id AND ay.id = ?
         WHERE b.institution_id = ? AND b.id = ? AND b.status IN ('active', 'full')
           AND ay.status IN ('active', 'closed')
         LIMIT 1"
    );
    $batchStatement->bind_param('iii', $exam['academic_year_id'], $institutionId, $exam['batch_id']);
    $batchStatement->execute();
    $batch = $batchStatement->get_result()->fetch_assoc();
    $batchStatement->close();
    if (!is_array($batch)) {
        throw new DomainException('Select a batch and academic session from this institution.');
    }
    if ($exam['exam_date'] < $batch['start_date'] || $exam['exam_date'] > $batch['end_date']) {
        throw new DomainException('The examination date must fall within the selected academic session.');
    }

    if ($examId !== null) {
        $db->begin_transaction();
        try {
            $currentStatement = $db->prepare(
                'SELECT batch_id, academic_year_id FROM examinations
                 WHERE institution_id = ? AND id = ? FOR UPDATE'
            );
            $currentStatement->bind_param('ii', $institutionId, $examId);
            $currentStatement->execute();
            $current = $currentStatement->get_result()->fetch_assoc();
            $currentStatement->close();
            if (!is_array($current)) {
                throw new OutOfBoundsException('The examination was not found for this institution.');
            }
            if ((int) $current['batch_id'] !== $exam['batch_id']
                || (int) $current['academic_year_id'] !== $exam['academic_year_id']) {
                $countStatement = $db->prepare(
                    'SELECT COUNT(*) FROM examination_results WHERE institution_id = ? AND examination_id = ?'
                );
                $countStatement->bind_param('ii', $institutionId, $examId);
                $countStatement->execute();
                $hasResults = (int) $countStatement->get_result()->fetch_row()[0] > 0;
                $countStatement->close();
                if ($hasResults) {
                    throw new DomainException('The batch and session cannot be changed after results have been entered.');
                }
            }
            $highestStatement = $db->prepare(
                'SELECT MAX(marks_obtained) FROM examination_results WHERE institution_id = ? AND examination_id = ?'
            );
            $highestStatement->bind_param('ii', $institutionId, $examId);
            $highestStatement->execute();
            $highestMarks = $highestStatement->get_result()->fetch_row()[0];
            $highestStatement->close();
            if ($highestMarks !== null
                && examination_results_decimal_hundredths($exam['max_marks'])
                    < examination_results_decimal_hundredths((string) $highestMarks)) {
                throw new DomainException('Maximum marks cannot be lower than marks already recorded.');
            }

            $update = $db->prepare(
                'UPDATE examinations
                 SET batch_id = ?, course_id = ?, academic_year_id = ?, name = ?, exam_date = ?,
                     max_marks = ?, description = ?
                 WHERE institution_id = ? AND id = ?'
            );
            $courseId = (int) $batch['course_id'];
            $update->bind_param(
                'iiissssii',
                $exam['batch_id'],
                $courseId,
                $exam['academic_year_id'],
                $exam['name'],
                $exam['exam_date'],
                $exam['max_marks'],
                $exam['description'],
                $institutionId,
                $examId
            );
            $update->execute();
            $update->close();
            $db->commit();
        } catch (Throwable $exception) {
            $db->rollback();
            throw $exception;
        }
        $savedId = $examId;
        $action = 'examination.updated';
    } else {
        $insert = $db->prepare(
            'INSERT INTO examinations (
                institution_id, batch_id, course_id, academic_year_id, name, exam_date, max_marks, description
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $courseId = (int) $batch['course_id'];
        $insert->bind_param(
            'iiiissss',
            $institutionId,
            $exam['batch_id'],
            $courseId,
            $exam['academic_year_id'],
            $exam['name'],
            $exam['exam_date'],
            $exam['max_marks'],
            $exam['description']
        );
        $insert->execute();
        $savedId = (int) $db->insert_id;
        $insert->close();
        $action = 'examination.created';
    }

    write_audit_log(
        $db,
        $institutionId,
        $userId,
        'admin',
        $action,
        'examination',
        (string) $savedId,
        null,
        ['batch_id' => $exam['batch_id'], 'academic_year_id' => $exam['academic_year_id']]
    );

    return $savedId;
}

function examination_results_list_exams(mysqli $db, int $institutionId, array $filters = []): array
{
    $query = mb_substr(trim(is_string($filters['query'] ?? null) ? $filters['query'] : ''), 0, 150);
    $classId = filter_var($filters['class_id'] ?? 0, FILTER_VALIDATE_INT);
    $yearId = filter_var($filters['academic_year_id'] ?? 0, FILTER_VALIDATE_INT);
    $classId = $classId === false || $classId < 1 ? 0 : (int) $classId;
    $yearId = $yearId === false || $yearId < 1 ? 0 : (int) $yearId;
    $search = '%' . $query . '%';
    $statement = $db->prepare(
        'SELECT e.id, e.name, e.exam_date, e.max_marks, e.results_published_at,
                ay.name AS academic_year_name, b.name AS batch_name,
                c.id AS class_id, c.name AS class_name, co.name AS course_name,
                (SELECT COUNT(*) FROM examination_results er
                 WHERE er.institution_id = e.institution_id AND er.examination_id = e.id) AS result_count
         FROM examinations e
         JOIN batches b ON b.institution_id = e.institution_id AND b.id = e.batch_id
         JOIN classes c ON c.institution_id = b.institution_id AND c.id = b.class_id
         JOIN courses co ON co.institution_id = b.institution_id AND co.id = b.course_id
         LEFT JOIN academic_years ay
           ON ay.institution_id = e.institution_id AND ay.id = e.academic_year_id
         WHERE e.institution_id = ?
           AND (? = 0 OR c.id = ?) AND (? = 0 OR e.academic_year_id = ?)
           AND (? = \'%%\' OR e.name LIKE ? OR EXISTS (
               SELECT 1 FROM students s
               WHERE s.institution_id = e.institution_id AND s.batch_id = e.batch_id
                 AND s.academic_year_id = e.academic_year_id
                 AND (s.full_name LIKE ? OR s.student_code LIKE ?)
           ))
         ORDER BY e.exam_date DESC, e.id DESC'
    );
    $statement->bind_param(
        'iiiiissss',
        $institutionId,
        $classId,
        $classId,
        $yearId,
        $yearId,
        $search,
        $search,
        $search,
        $search
    );
    $statement->execute();
    $exams = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();

    return $exams;
}

function examination_results_set_publication(
    mysqli $db,
    int $institutionId,
    int $userId,
    int $examId,
    bool $published
): void {
    $exam = examination_results_get_exam($db, $institutionId, $examId);
    if (!is_array($exam)) {
        throw new OutOfBoundsException('The examination was not found for this institution.');
    }
    if ($published) {
        if ($exam['max_marks'] === null) {
            throw new DomainException('Set maximum marks before publishing results.');
        }
        $count = $db->prepare(
            'SELECT COUNT(*) FROM examination_results WHERE institution_id = ? AND examination_id = ?'
        );
        $count->bind_param('ii', $institutionId, $examId);
        $count->execute();
        $hasResults = (int) $count->get_result()->fetch_row()[0] > 0;
        $count->close();
        if (!$hasResults) {
            throw new DomainException('Enter at least one student result before publishing.');
        }
    }
    $statement = $db->prepare(
        'UPDATE examinations
         SET results_published_at = ' . ($published ? 'CURRENT_TIMESTAMP' : 'NULL') . '
         WHERE institution_id = ? AND id = ?'
    );
    $statement->bind_param('ii', $institutionId, $examId);
    $statement->execute();
    $statement->close();
    write_audit_log(
        $db,
        $institutionId,
        $userId,
        'admin',
        $published ? 'examination.results_published' : 'examination.results_unpublished',
        'examination',
        (string) $examId,
        null,
        null
    );
}

function examination_results_get_roster(
    mysqli $db,
    int $institutionId,
    int $examId,
    string $query = ''
): array {
    $exam = examination_results_get_exam($db, $institutionId, $examId);
    if (!is_array($exam)) {
        throw new OutOfBoundsException('The examination was not found for this institution.');
    }
    $search = '%' . mb_substr(trim($query), 0, 150) . '%';
    $statement = $db->prepare(
        'SELECT s.id AS student_id, s.student_code, s.full_name, s.academic_year_id,
                er.marks_obtained
         FROM students s
         LEFT JOIN examination_results er
           ON er.institution_id = s.institution_id AND er.student_id = s.id
          AND er.examination_id = ?
         WHERE s.institution_id = ? AND s.batch_id = ?
           AND s.academic_year_id = ?
           AND (? = \'%%\' OR s.full_name LIKE ? OR s.student_code LIKE ?)
         ORDER BY s.full_name, s.student_code'
    );
    $statement->bind_param(
        'iiiisss',
        $examId,
        $institutionId,
        $exam['batch_id'],
        $exam['academic_year_id'],
        $search,
        $search,
        $search
    );
    $statement->execute();
    $students = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();

    return $students;
}

function examination_results_save_marks(
    mysqli $db,
    int $institutionId,
    int $userId,
    int $examId,
    array $marksByStudent
): int {
    if (count($marksByStudent) > 1000) {
        throw new InvalidArgumentException('Submit no more than 1000 student results at once.');
    }
    $exam = examination_results_get_exam($db, $institutionId, $examId);
    if (!is_array($exam) || $exam['max_marks'] === null) {
        throw new OutOfBoundsException('The examination was not found or has no maximum marks configured.');
    }
    $normalized = [];
    foreach ($marksByStudent as $rawStudentId => $rawMarks) {
        if (!is_string($rawStudentId) && !is_int($rawStudentId)) {
            throw new InvalidArgumentException('The student result list is invalid.');
        }
        $studentId = filter_var((string) $rawStudentId, FILTER_VALIDATE_INT);
        if ($studentId === false || $studentId < 1) {
            throw new InvalidArgumentException('The student result list is invalid.');
        }
        if (!is_string($rawMarks)) {
            throw new InvalidArgumentException('Enter marks as a valid decimal value.');
        }
        if (trim($rawMarks) === '') {
            continue;
        }
        $normalized[(int) $studentId] = examination_results_validate_marks(
            $rawMarks,
            (string) $exam['max_marks']
        );
    }
    if ($normalized === []) {
        return 0;
    }

    $db->begin_transaction();
    try {
        $lockedExam = $db->prepare(
            'SELECT batch_id, academic_year_id, max_marks
             FROM examinations WHERE institution_id = ? AND id = ? FOR UPDATE'
        );
        $lockedExam->bind_param('ii', $institutionId, $examId);
        $lockedExam->execute();
        $currentExam = $lockedExam->get_result()->fetch_assoc();
        $lockedExam->close();
        if (!is_array($currentExam) || $currentExam['max_marks'] === null) {
            throw new OutOfBoundsException('The examination is no longer available.');
        }
        foreach ($normalized as $marks) {
            examination_results_validate_marks($marks, (string) $currentExam['max_marks']);
        }

        $studentIds = array_keys($normalized);
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $types = 'iii' . str_repeat('i', count($studentIds));
        $scopeValues = [
            $institutionId,
            (int) $currentExam['batch_id'],
            (int) $currentExam['academic_year_id'],
            ...$studentIds,
        ];
        $scope = $db->prepare(
            "SELECT id FROM students
             WHERE institution_id = ? AND batch_id = ?
               AND academic_year_id = ?
               AND id IN ($placeholders) FOR UPDATE"
        );
        examination_results_bind_params($scope, $types, $scopeValues);
        $scope->execute();
        $allowedIds = array_map(
            static fn(array $row): int => (int) $row['id'],
            $scope->get_result()->fetch_all(MYSQLI_ASSOC)
        );
        $scope->close();
        if (count($allowedIds) !== count($normalized)) {
            throw new DomainException('One or more students do not belong to this examination batch and session.');
        }

        $upsert = $db->prepare(
            'INSERT INTO examination_results (
                institution_id, examination_id, student_id, marks_obtained, recorded_by
             ) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE marks_obtained = VALUES(marks_obtained),
                recorded_by = VALUES(recorded_by), updated_at = CURRENT_TIMESTAMP'
        );
        foreach ($normalized as $studentId => $marks) {
            $upsert->bind_param('iiisi', $institutionId, $examId, $studentId, $marks, $userId);
            $upsert->execute();
        }
        $upsert->close();
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }

    write_audit_log(
        $db,
        $institutionId,
        $userId,
        'admin',
        'examination.results_saved',
        'examination',
        (string) $examId,
        null,
        ['result_count' => count($normalized)]
    );

    return count($normalized);
}

function examination_results_bind_params(mysqli_stmt $statement, string $types, array &$values): void
{
    $parameters = [$types];
    foreach ($values as &$value) {
        $parameters[] = &$value;
    }
    unset($value);
    if (!call_user_func_array([$statement, 'bind_param'], $parameters)) {
        throw new RuntimeException('The examination result query could not bind its parameters.');
    }
}

function examination_results_get_parent_students(
    mysqli $db,
    int $parentId,
    int $institutionId
): array {
    $statement = $db->prepare(
        "SELECT s.id, s.full_name, s.student_code, c.name AS class_name, b.name AS batch_name
         FROM parent_student_relationships psr
         JOIN students s ON s.institution_id = psr.institution_id AND s.id = psr.student_id
         LEFT JOIN classes c ON c.institution_id = s.institution_id AND c.id = s.class_id
         LEFT JOIN batches b ON b.institution_id = s.institution_id AND b.id = s.batch_id
         JOIN parents p ON p.institution_id = psr.institution_id AND p.id = psr.parent_id
         WHERE psr.institution_id = ? AND psr.parent_id = ? AND psr.status = 'active'
           AND psr.can_view_academics = 1 AND p.status = 'active'
         ORDER BY s.full_name, s.student_code"
    );
    $statement->bind_param('ii', $institutionId, $parentId);
    $statement->execute();
    $students = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();

    return $students;
}

function examination_results_get_published_for_parent(
    mysqli $db,
    int $parentId,
    int $institutionId,
    int $studentId
): ?array {
    $studentStatement = $db->prepare(
        "SELECT s.id, s.full_name, s.student_code, c.name AS class_name, b.name AS batch_name
         FROM parent_student_relationships psr
         JOIN parents p ON p.institution_id = psr.institution_id AND p.id = psr.parent_id
         JOIN students s ON s.institution_id = psr.institution_id AND s.id = psr.student_id
         LEFT JOIN classes c ON c.institution_id = s.institution_id AND c.id = s.class_id
         LEFT JOIN batches b ON b.institution_id = s.institution_id AND b.id = s.batch_id
         WHERE psr.institution_id = ? AND psr.parent_id = ? AND psr.student_id = ?
           AND psr.status = 'active' AND psr.can_view_academics = 1 AND p.status = 'active'
         LIMIT 1"
    );
    $studentStatement->bind_param('iii', $institutionId, $parentId, $studentId);
    $studentStatement->execute();
    $student = $studentStatement->get_result()->fetch_assoc();
    $studentStatement->close();
    if (!is_array($student)) {
        return null;
    }
    $student['results'] = [];

    $resultsStatement = $db->prepare(
        "SELECT e.id AS examination_id, e.name AS examination_name, e.exam_date,
                e.max_marks, ay.name AS academic_year_name, co.name AS course_name,
                er.marks_obtained, e.results_published_at
         FROM examination_results er
         JOIN examinations e
           ON e.institution_id = er.institution_id AND e.id = er.examination_id
         JOIN students s ON s.institution_id = er.institution_id AND s.id = er.student_id
         JOIN parent_student_relationships psr
           ON psr.institution_id = s.institution_id AND psr.student_id = s.id
         LEFT JOIN academic_years ay
           ON ay.institution_id = e.institution_id AND ay.id = e.academic_year_id
         LEFT JOIN courses co ON co.institution_id = e.institution_id AND co.id = e.course_id
         WHERE er.institution_id = ? AND er.student_id = ? AND psr.parent_id = ?
           AND psr.status = 'active' AND psr.can_view_academics = 1
           AND e.results_published_at IS NOT NULL
           AND e.batch_id = s.batch_id AND e.academic_year_id = s.academic_year_id
         ORDER BY e.exam_date DESC, e.name"
    );
    $resultsStatement->bind_param('iii', $institutionId, $studentId, $parentId);
    $resultsStatement->execute();
    $results = $resultsStatement->get_result()->fetch_all(MYSQLI_ASSOC);
    $resultsStatement->close();
    foreach ($results as &$result) {
        $result['percentage'] = examination_results_percentage(
            (string) $result['marks_obtained'],
            (string) $result['max_marks']
        );
    }
    unset($result);
    $student['results'] = $results;

    return $student;
}
