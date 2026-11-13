<?php
declare(strict_types=1);

require_once __DIR__ . '/admission-conversion-service.php';
require_once __DIR__ . '/email-delivery.php';

const ADMISSION_WORKFLOW_STATUSES = [
    'draft',
    'submitted',
    'under_review',
    'information_requested',
    'approved',
    'rejected',
    'enrolled',
];
const ADMISSION_DOCUMENT_MAX_BYTES = 5242880;
const ADMISSION_NOTIFICATION_EVENTS = [
    'application_submitted' => 'Application submitted',
    'information_requested' => 'Additional information requested',
    'application_approved' => 'Application approved',
    'application_rejected' => 'Application rejected',
    'student_registered' => 'Student registration completed',
];
const ADMISSION_HISTORY_EVENTS = [
    'created',
    'draft_updated',
    'details_updated',
    'submitted',
    'under_review',
    'information_requested',
    'approved',
    'rejected',
    'student_registered',
    'status_changed',
];

function admission_workflow_schema_ready(mysqli $db): bool
{
    $requiredTables = [
        'admission_enquiries',
        'admission_status_history',
        'student_code_settings',
        'student_code_sequences',
        'admission_notification_settings',
    ];
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name IN (?, ?, ?, ?, ?)'
    );
    $values = $requiredTables;
    admission_workflow_bind($statement, 'sssss', $values);
    $statement->execute();
    $count = (int) $statement->get_result()->fetch_column();
    $statement->close();
    if ($count !== count($requiredTables) || !admission_conversion_schema_ready($db)) {
        return false;
    }
    $requiredColumns = [
        'admissions' => [
            'application_reference', 'requested_academic_group_id', 'first_name', 'middle_name',
            'last_name', 'gender', 'student_mobile', 'student_email', 'address_line1',
            'address_line2', 'city', 'state', 'postal_code', 'country', 'previous_school',
            'admission_category', 'father_name', 'father_mobile', 'father_email', 'mother_name',
            'mother_mobile', 'mother_email', 'guardian_name', 'guardian_mobile', 'guardian_email',
            'enquiry_source', 'submitted_at',
        ],
        'students' => [
            'mobile', 'email', 'address_line1', 'address_line2', 'city', 'state',
            'postal_code', 'country', 'previous_school',
        ],
        'admission_documents' => [
            'verification_status', 'verification_remarks', 'verified_by', 'verified_at',
            'storage_key', 'file_size', 'original_extension',
        ],
        'admission_status_history' => ['event_type'],
        'student_code_sequences' => ['start_number'],
    ];
    foreach ($requiredColumns as $table => $columns) {
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $statement = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name IN (' . $placeholders . ')'
        );
        $values = [$table, ...$columns];
        admission_workflow_bind($statement, str_repeat('s', count($values)), $values);
        $statement->execute();
        $foundColumns = (int) $statement->get_result()->fetch_column();
        $statement->close();
        if ($foundColumns !== count($columns)) {
            return false;
        }
    }
    return true;
}

function admission_workflow_bind(mysqli_stmt $statement, string $types, array &$values): void
{
    $parameters = [$types];
    foreach ($values as &$value) {
        $parameters[] = &$value;
    }
    unset($value);
    if (!call_user_func_array([$statement, 'bind_param'], $parameters)) {
        throw new RuntimeException('Admission data could not be bound to the database query.');
    }
}

function admission_workflow_text(mixed $value, string $label, int $maxLength, bool $required = false): ?string
{
    if (!is_string($value)) {
        throw new InvalidArgumentException('Enter a valid ' . $label . '.');
    }
    $value = trim($value);
    if (($required && $value === '')
        || mb_strlen($value, 'UTF-8') > $maxLength
        || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
        throw new InvalidArgumentException('Enter a valid ' . $label . '.');
    }
    return $value === '' ? null : $value;
}

function admission_workflow_optional_id(mixed $value, string $label): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    $id = is_string($value) || is_int($value) ? filter_var((string) $value, FILTER_VALIDATE_INT) : false;
    if ($id === false || $id < 1) {
        throw new InvalidArgumentException('Choose a valid ' . $label . '.');
    }
    return (int) $id;
}

function admission_workflow_validate_application(array $input, string $targetStatus): array
{
    if (!in_array($targetStatus, ['draft', 'submitted', 'save_changes'], true)) {
        throw new InvalidArgumentException('Choose whether to save the draft, submit, or save changes.');
    }
    $fields = [
        'applicant_name' => [150, true],
        'applicant_mobile' => [20, true],
        'applicant_email' => [255, false],
        'relationship_to_student' => [80, false],
        'first_name' => [80, true],
        'middle_name' => [80, false],
        'last_name' => [80, false],
        'admission_date' => [10, false],
        'student_mobile' => [20, false],
        'student_email' => [255, false],
        'address_line1' => [255, false],
        'address_line2' => [255, false],
        'city' => [120, false],
        'state' => [120, false],
        'postal_code' => [20, false],
        'country' => [120, false],
        'previous_school' => [200, false],
        'admission_category' => [100, false],
        'father_name' => [150, false],
        'father_mobile' => [20, false],
        'father_email' => [255, false],
        'mother_name' => [150, false],
        'mother_mobile' => [20, false],
        'mother_email' => [255, false],
        'guardian_name' => [150, false],
        'guardian_mobile' => [20, false],
        'guardian_email' => [255, false],
        'enquiry_source' => [80, false],
        'submission_notes' => [20000, false],
    ];
    $data = [];
    foreach ($fields as $field => [$maxLength, $required]) {
        $data[$field] = admission_workflow_text(
            $input[$field] ?? '',
            str_replace('_', ' ', $field),
            $maxLength,
            $required
        );
    }
    foreach (['applicant_email', 'student_email', 'father_email', 'mother_email', 'guardian_email'] as $field) {
        if ($data[$field] !== null && filter_var($data[$field], FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Enter a valid ' . str_replace('_', ' ', $field) . '.');
        }
    }
    foreach (['applicant_mobile', 'student_mobile', 'father_mobile', 'mother_mobile', 'guardian_mobile'] as $field) {
        if ($data[$field] !== null
            && preg_match('/^[0-9+(). -]{8,20}$/D', $data[$field]) !== 1) {
            throw new InvalidArgumentException('Enter a valid ' . str_replace('_', ' ', $field) . '.');
        }
    }
    $data['student_name'] = trim(implode(' ', array_filter([
        $data['first_name'],
        $data['middle_name'],
        $data['last_name'],
    ], static fn (?string $part): bool => $part !== null && $part !== '')));
    $dateOfBirth = admission_workflow_text($input['date_of_birth'] ?? '', 'date of birth', 10);
    if ($dateOfBirth !== null) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateOfBirth);
        if ($date === false || $date->format('Y-m-d') !== $dateOfBirth
            || $dateOfBirth > date('Y-m-d')) {
            throw new InvalidArgumentException('Enter a valid date of birth.');
        }
    }
    $admissionDate = admission_workflow_text($input['admission_date'] ?? '', 'admission date', 10);
    if ($admissionDate !== null) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $admissionDate);
        if ($date === false || $date->format('Y-m-d') !== $admissionDate) {
            throw new InvalidArgumentException('Enter a valid admission date.');
        }
    }
    $gender = admission_workflow_text($input['gender'] ?? '', 'gender', 30);
    if ($gender !== null && !in_array($gender, STUDENT_MANAGEMENT_GENDERS, true)) {
        throw new InvalidArgumentException('Choose a supported gender.');
    }
    $data['date_of_birth'] = $dateOfBirth;
    $data['admission_date'] = $admissionDate;
    $data['gender'] = $gender;
    $data['academic_year_id'] = admission_workflow_optional_id($input['academic_year_id'] ?? '', 'academic year');
    $data['course_id'] = admission_workflow_optional_id($input['course_id'] ?? '', 'course');
    $data['class_id'] = admission_workflow_optional_id($input['class_id'] ?? '', 'class');
    $data['batch_id'] = admission_workflow_optional_id($input['batch_id'] ?? '', 'batch');
    $data['requested_academic_group_id'] = admission_workflow_optional_id(
        $input['requested_academic_group_id'] ?? '',
        'section or academic placement'
    );
    if ($targetStatus === 'submitted'
        && ($data['academic_year_id'] === null
            || ($data['course_id'] === null && $data['class_id'] === null))) {
        throw new InvalidArgumentException('Select an academic year and a class or course before submission.');
    }
    return $data;
}

function admission_workflow_options(mysqli $db, int $institutionId): array
{
    $options = [];
    foreach ([
        'academic_years' => 'SELECT id, name, status FROM academic_years WHERE institution_id = ? ORDER BY (status = \'active\') DESC, start_date DESC',
        'courses' => "SELECT id, name, code, status FROM courses WHERE institution_id = ? AND status = 'active' ORDER BY name",
        'classes' => "SELECT id, name, code, status FROM classes WHERE institution_id = ? AND status = 'active' ORDER BY name",
        'batches' => "SELECT id, course_id, class_id, name, status FROM batches WHERE institution_id = ? AND status = 'active' ORDER BY name",
    ] as $key => $query) {
        $statement = $db->prepare($query);
        $statement->bind_param('i', $institutionId);
        $statement->execute();
        $options[$key] = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
        $statement->close();
    }
    return $options;
}

function admission_workflow_group_options(mysqli $db, int $institutionId): array
{
    $statement = $db->prepare(
        'SELECT g.id, g.academic_year_id, g.group_type, ay.name AS year_name,
                course.name AS course_name, class.name AS class_name,
                section.name AS section_name, term.name AS term_name, batch.name AS batch_name
         FROM academic_groups g
         JOIN academic_years ay
           ON ay.institution_id = g.institution_id AND ay.id = g.academic_year_id
         LEFT JOIN courses course ON course.institution_id = g.institution_id AND course.id = g.course_id
         LEFT JOIN classes class ON class.institution_id = g.institution_id AND class.id = g.class_id
         LEFT JOIN sections section
           ON section.institution_id = g.institution_id AND section.academic_year_id = g.academic_year_id
          AND section.class_id = g.class_id AND section.id = g.section_id
         LEFT JOIN academic_terms term
           ON term.institution_id = g.institution_id AND term.academic_year_id = g.academic_year_id
          AND term.course_id = g.course_id AND term.id = g.academic_term_id
         LEFT JOIN batches batch
           ON batch.institution_id = g.institution_id AND batch.course_id = g.course_id
          AND batch.id = g.batch_id
         WHERE g.institution_id = ?
         ORDER BY ay.start_date DESC, course.name, class.name, section.name, term.sequence_number, batch.name'
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    foreach ($rows as &$row) {
        $parts = array_filter([
            $row['year_name'],
            $row['course_name'],
            $row['class_name'],
            $row['section_name'] ?? $row['term_name'] ?? $row['batch_name'],
        ], static fn (?string $value): bool => $value !== null && $value !== '');
        $row['label'] = implode(' / ', $parts);
    }
    unset($row);
    return $rows;
}

function admission_workflow_get_application(mysqli $db, int $institutionId, int $admissionId): ?array
{
    $statement = $db->prepare(
        'SELECT * FROM admissions WHERE institution_id = ? AND id = ? LIMIT 1'
    );
    $statement->bind_param('ii', $institutionId, $admissionId);
    $statement->execute();
    $application = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_array($application)) {
        return null;
    }
    $application['history'] = admission_workflow_status_history($db, $institutionId, $admissionId);
    return $application;
}

function admission_workflow_status_history(mysqli $db, int $institutionId, int $admissionId): array
{
    $statement = $db->prepare(
        'SELECT h.event_type, h.from_status, h.to_status, h.remarks, h.changed_at,
                COALESCE(u.full_name, \'Platform administrator / system\') AS actor_name
         FROM admission_status_history h
         LEFT JOIN users u ON u.institution_id = h.institution_id AND u.id = h.changed_by
         WHERE h.institution_id = ? AND h.admission_id = ?
         ORDER BY h.changed_at, h.id'
    );
    $statement->bind_param('ii', $institutionId, $admissionId);
    $statement->execute();
    $history = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $history;
}

function admission_workflow_record_status(
    mysqli $db,
    int $institutionId,
    int $admissionId,
    ?string $fromStatus,
    string $toStatus,
    ?string $remarks,
    ?int $actorUserId,
    string $eventType = 'status_changed'
): void {
    if (!in_array($eventType, ADMISSION_HISTORY_EVENTS, true)) {
        throw new InvalidArgumentException('Choose a supported admission timeline event.');
    }
    $statement = $db->prepare(
        'INSERT INTO admission_status_history
            (institution_id, admission_id, from_status, to_status, event_type, remarks, changed_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $statement->bind_param(
        'iissssi',
        $institutionId,
        $admissionId,
        $fromStatus,
        $toStatus,
        $eventType,
        $remarks,
        $actorUserId
    );
    $statement->execute();
    $statement->close();
}

function admission_workflow_save_application(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    string $actorType,
    array $input,
    string $targetStatus,
    ?int $admissionId = null
): int {
    $data = admission_workflow_validate_application($input, $targetStatus);
    if ($admissionId === null && $targetStatus === 'save_changes') {
        throw new InvalidArgumentException('Choose an existing application to update.');
    }
    if ($targetStatus === 'submitted') {
        $data['submitted_at'] = date('Y-m-d H:i:s');
    }
    $db->begin_transaction();
    try {
        if ($data['academic_year_id'] !== null) {
            $statement = $db->prepare(
                "SELECT id FROM academic_years
                 WHERE institution_id = ? AND id = ? AND status <> 'closed' LIMIT 1"
            );
            $statement->bind_param('ii', $institutionId, $data['academic_year_id']);
            $statement->execute();
            $found = $statement->get_result()->fetch_column();
            $statement->close();
            if ($found === false) {
                throw new InvalidArgumentException('Choose an academic year belonging to this institution.');
            }
        }
        foreach (['course_id' => 'courses', 'class_id' => 'classes', 'batch_id' => 'batches'] as $field => $table) {
            if ($data[$field] === null) {
                continue;
            }
            $statement = $db->prepare("SELECT id FROM {$table} WHERE institution_id = ? AND id = ? LIMIT 1");
            $statement->bind_param('ii', $institutionId, $data[$field]);
            $statement->execute();
            $found = $statement->get_result()->fetch_column();
            $statement->close();
            if ($found === false) {
                throw new InvalidArgumentException('One of the selected academic records is unavailable in this institution.');
            }
        }
        if ($data['requested_academic_group_id'] !== null) {
            if ($data['academic_year_id'] === null) {
                throw new InvalidArgumentException('Choose an academic year before selecting a placement.');
            }
            $statement = $db->prepare(
                'SELECT id FROM academic_groups
                 WHERE institution_id = ? AND academic_year_id = ? AND id = ? LIMIT 1'
            );
            $statement->bind_param('iii', $institutionId, $data['academic_year_id'], $data['requested_academic_group_id']);
            $statement->execute();
            $found = $statement->get_result()->fetch_column();
            $statement->close();
            if ($found === false) {
                throw new InvalidArgumentException('Choose a placement in the selected institution and academic year.');
            }
        }
        if ($admissionId === null) {
            $columns = [
                'institution_id', 'applicant_name', 'applicant_mobile', 'applicant_email',
                'relationship_to_student', 'student_name', 'first_name', 'middle_name', 'last_name',
                'date_of_birth', 'admission_date', 'gender', 'student_mobile', 'student_email', 'address_line1',
                'address_line2', 'city', 'state', 'postal_code', 'country', 'previous_school',
                'admission_category', 'father_name', 'father_mobile', 'father_email', 'mother_name',
                'mother_mobile', 'mother_email', 'guardian_name', 'guardian_mobile', 'guardian_email',
                'enquiry_source', 'academic_year_id', 'requested_academic_group_id', 'course_id',
                'class_id', 'batch_id', 'status', 'submission_notes', 'submitted_at',
            ];
            $all = ['institution_id' => $institutionId] + $data + ['status' => $targetStatus];
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $types = '';
            $values = [];
            foreach ($columns as $column) {
                $value = $all[$column] ?? null;
                $values[] = $value;
                $types .= in_array($column, [
                    'institution_id', 'academic_year_id', 'requested_academic_group_id',
                    'course_id', 'class_id', 'batch_id',
                ], true) ? 'i' : 's';
            }
            $statement = $db->prepare(
                'INSERT INTO admissions (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')'
            );
            admission_workflow_bind($statement, $types, $values);
            $statement->execute();
            $admissionId = (int) $statement->insert_id;
            $statement->close();
            $reference = 'ADM-' . date('Y') . '-' . str_pad((string) $admissionId, 8, '0', STR_PAD_LEFT);
            $statement = $db->prepare(
                'UPDATE admissions SET application_reference = ? WHERE institution_id = ? AND id = ?'
            );
            $statement->bind_param('sii', $reference, $institutionId, $admissionId);
            $statement->execute();
            $statement->close();
            $fromStatus = null;
        } else {
            $statement = $db->prepare(
                'SELECT status FROM admissions
                 WHERE institution_id = ? AND id = ? FOR UPDATE'
            );
            $statement->bind_param('ii', $institutionId, $admissionId);
            $statement->execute();
            $current = $statement->get_result()->fetch_assoc();
            $statement->close();
            if (!is_array($current)) {
                throw new OutOfBoundsException('Admission application not found in this institution.');
            }
            if ($targetStatus === 'save_changes'
                && (!in_array($current['status'], ADMISSION_WORKFLOW_STATUSES, true)
                    || $current['status'] === 'enrolled')) {
                throw new DomainException('Enrolled or invalid applications cannot be edited.');
            }
            $canSaveChanges = $targetStatus === 'save_changes'
                && in_array($current['status'], ADMISSION_WORKFLOW_STATUSES, true);
            if (!$canSaveChanges && !in_array($current['status'], ['draft', 'information_requested'], true)) {
                throw new DomainException('Only draft or information-requested applications can be edited.');
            }
            $fromStatus = (string) $current['status'];
            $columns = [
                'applicant_name', 'applicant_mobile', 'applicant_email', 'relationship_to_student',
                'student_name', 'first_name', 'middle_name', 'last_name', 'date_of_birth', 'admission_date', 'gender',
                'student_mobile', 'student_email', 'address_line1', 'address_line2', 'city', 'state',
                'postal_code', 'country', 'previous_school', 'admission_category', 'father_name',
                'father_mobile', 'father_email', 'mother_name', 'mother_mobile', 'mother_email',
                'guardian_name', 'guardian_mobile', 'guardian_email', 'enquiry_source',
                'academic_year_id', 'requested_academic_group_id', 'course_id', 'class_id', 'batch_id',
                'status', 'submission_notes', 'submitted_at',
            ];
            if ($targetStatus === 'save_changes') {
                $columns = array_values(array_diff($columns, ['status', 'submitted_at']));
            }
            $assignments = implode(', ', array_map(static fn (string $column): string => $column . ' = ?', $columns));
            $types = '';
            $values = [];
            foreach ($columns as $column) {
                $values[] = $column === 'status' ? $targetStatus : ($data[$column] ?? null);
                $types .= in_array($column, [
                    'academic_year_id', 'requested_academic_group_id', 'course_id', 'class_id', 'batch_id',
                ], true) ? 'i' : 's';
            }
            $values[] = $institutionId;
            $values[] = $admissionId;
            $types .= 'ii';
            $statement = $db->prepare(
                'UPDATE admissions SET ' . $assignments . ' WHERE institution_id = ? AND id = ?'
            );
            admission_workflow_bind($statement, $types, $values);
            $statement->execute();
            $statement->close();
        }
        $databaseActorId = institution_audit_actor_user_id($actorUserId);
        if ($fromStatus === null) {
            admission_workflow_record_status(
                $db,
                $institutionId,
                $admissionId,
                null,
                'draft',
                'Application created.',
                $databaseActorId,
                'created'
            );
            if ($targetStatus === 'submitted') {
                admission_workflow_record_status(
                    $db,
                    $institutionId,
                    $admissionId,
                    'draft',
                    'submitted',
                    'Application submitted.',
                    $databaseActorId,
                    'submitted'
                );
            }
        } else {
            $eventType = match ($targetStatus) {
                'submitted' => 'submitted',
                'save_changes' => 'details_updated',
                default => 'draft_updated',
            };
            $remarks = match ($targetStatus) {
                'submitted' => 'Application submitted.',
                'save_changes' => 'Application details updated.',
                default => 'Application draft updated.',
            };
            admission_workflow_record_status(
                $db,
                $institutionId,
                $admissionId,
                $fromStatus,
                $targetStatus === 'save_changes' ? $fromStatus : $targetStatus,
                $remarks,
                $databaseActorId,
                $eventType
            );
        }
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            $actorType,
            match ($targetStatus) {
                'submitted' => 'admission.submitted',
                'save_changes' => 'admission.updated',
                default => 'admission.draft_saved',
            },
            'admission',
            (string) $admissionId,
            $fromStatus === null ? null : ['status' => $fromStatus],
            ['status' => $targetStatus === 'save_changes' ? $fromStatus : $targetStatus]
        );
        $db->commit();
        return $admissionId;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function admission_workflow_list_enquiries(
    mysqli $db,
    int $institutionId,
    string $search = '',
    string $status = '',
    ?string $dateFrom = null,
    ?string $dateTo = null,
    int $limit = 26,
    int $offset = 0
): array {
    $where = ['e.institution_id = ?'];
    $values = [$institutionId];
    $types = 'i';
    if (in_array($status, ['new', 'contacted', 'follow_up', 'converted', 'closed'], true)) {
        $where[] = 'e.status = ?';
        $values[] = $status;
        $types .= 's';
    }
    if ($search !== '') {
        $like = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $where[] = "(e.reference_number LIKE ? ESCAPE '!' OR e.applicant_name LIKE ? ESCAPE '!'
            OR e.student_name LIKE ? ESCAPE '!' OR e.mobile LIKE ? ESCAPE '!')";
        array_push($values, $like, $like, $like, $like);
        $types .= 'ssss';
    }
    if ($dateFrom !== null) {
        $where[] = 'e.created_at >= CONCAT(?, \' 00:00:00\')';
        $values[] = $dateFrom;
        $types .= 's';
    }
    if ($dateTo !== null) {
        $where[] = 'e.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
        $values[] = $dateTo;
        $types .= 's';
    }
    $limit = max(1, min(101, $limit));
    $offset = max(0, $offset);
    $values[] = $limit;
    $values[] = $offset;
    $types .= 'ii';
    $statement = $db->prepare(
        'SELECT e.*, ay.name AS academic_year_name, c.name AS class_name, co.name AS course_name
         FROM admission_enquiries e
         LEFT JOIN academic_years ay ON ay.institution_id = e.institution_id AND ay.id = e.academic_year_id
         LEFT JOIN classes c ON c.institution_id = e.institution_id AND c.id = e.class_id
         LEFT JOIN courses co ON co.institution_id = e.institution_id AND co.id = e.course_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY FIELD(e.status, \'new\', \'follow_up\', \'contacted\', \'converted\', \'closed\'),
                  e.follow_up_date, e.created_at DESC LIMIT ? OFFSET ?'
    );
    admission_workflow_bind($statement, $types, $values);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}

function admission_workflow_dashboard_stats(
    mysqli $db,
    int $institutionId,
    ?string $dateFrom = null,
    ?string $dateTo = null
): array {
    $stats = [
        'total_enquiries' => 0,
        'draft' => 0,
        'submitted' => 0,
        'under_review' => 0,
        'information_requested' => 0,
        'approved' => 0,
        'rejected' => 0,
        'enrolled' => 0,
    ];
    $dateClauses = '';
    $dateValues = [];
    $dateTypes = '';
    if ($dateFrom !== null) {
        $dateClauses .= ' AND created_at >= CONCAT(?, \' 00:00:00\')';
        $dateValues[] = $dateFrom;
        $dateTypes .= 's';
    }
    if ($dateTo !== null) {
        $dateClauses .= ' AND created_at < DATE_ADD(?, INTERVAL 1 DAY)';
        $dateValues[] = $dateTo;
        $dateTypes .= 's';
    }

    $statement = $db->prepare(
        'SELECT status, COUNT(*) AS total FROM admissions
         WHERE institution_id = ?' . $dateClauses . ' GROUP BY status'
    );
    $values = [$institutionId, ...$dateValues];
    admission_workflow_bind($statement, 'i' . $dateTypes, $values);
    $statement->execute();
    foreach ($statement->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $status = (string) $row['status'];
        if (array_key_exists($status, $stats)) {
            $stats[$status] = (int) $row['total'];
        }
    }
    $statement->close();

    $statement = $db->prepare(
        'SELECT COUNT(*) FROM admission_enquiries
         WHERE institution_id = ?' . $dateClauses
    );
    $values = [$institutionId, ...$dateValues];
    admission_workflow_bind($statement, 'i' . $dateTypes, $values);
    $statement->execute();
    $stats['total_enquiries'] = (int) $statement->get_result()->fetch_column();
    $statement->close();
    return $stats;
}

function admission_workflow_get_enquiry(mysqli $db, int $institutionId, int $enquiryId): ?array
{
    $statement = $db->prepare(
        'SELECT * FROM admission_enquiries
         WHERE institution_id = ? AND id = ? LIMIT 1'
    );
    $statement->bind_param('ii', $institutionId, $enquiryId);
    $statement->execute();
    $enquiry = $statement->get_result()->fetch_assoc();
    $statement->close();
    return is_array($enquiry) ? $enquiry : null;
}

function admission_workflow_update_enquiry(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    string $actorType,
    int $enquiryId,
    array $input
): void {
    $data = [
        'applicant_name' => admission_workflow_text($input['applicant_name'] ?? '', 'applicant name', 150, true),
        'student_name' => admission_workflow_text($input['student_name'] ?? '', 'student name', 150, true),
        'mobile' => admission_workflow_text($input['mobile'] ?? '', 'contact number', 20, true),
        'email' => admission_workflow_text($input['email'] ?? '', 'email', 255),
        'relationship_to_student' => admission_workflow_text($input['relationship_to_student'] ?? '', 'relationship', 80),
        'academic_year_id' => admission_workflow_optional_id($input['academic_year_id'] ?? '', 'academic year'),
        'course_id' => admission_workflow_optional_id($input['course_id'] ?? '', 'course'),
        'class_id' => admission_workflow_optional_id($input['class_id'] ?? '', 'class'),
        'source' => admission_workflow_text($input['source'] ?? '', 'enquiry source', 80),
        'status' => admission_workflow_text($input['status'] ?? 'new', 'enquiry status', 20, true),
        'follow_up_date' => admission_workflow_text($input['follow_up_date'] ?? '', 'follow-up date', 10),
        'notes' => admission_workflow_text($input['notes'] ?? '', 'enquiry notes', 20000),
    ];
    if (!in_array($data['status'], ['new', 'contacted', 'follow_up', 'closed'], true)) {
        throw new InvalidArgumentException('Choose a supported enquiry status.');
    }
    if ($data['email'] !== null && filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('Enter a valid enquiry email address.');
    }
    if ($data['follow_up_date'] !== null) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $data['follow_up_date']);
        if ($date === false || $date->format('Y-m-d') !== $data['follow_up_date']) {
            throw new InvalidArgumentException('Enter a valid follow-up date.');
        }
    }
    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            'SELECT status FROM admission_enquiries
             WHERE institution_id = ? AND id = ? FOR UPDATE'
        );
        $statement->bind_param('ii', $institutionId, $enquiryId);
        $statement->execute();
        $before = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($before)) {
            throw new OutOfBoundsException('Enquiry not found in this institution.');
        }
        if ($before['status'] === 'converted') {
            throw new DomainException('A converted enquiry cannot be edited.');
        }
        foreach (['academic_year_id' => 'academic_years', 'course_id' => 'courses', 'class_id' => 'classes'] as $field => $table) {
            if ($data[$field] === null) {
                continue;
            }
            $statement = $db->prepare("SELECT id FROM {$table} WHERE institution_id = ? AND id = ? LIMIT 1");
            $statement->bind_param('ii', $institutionId, $data[$field]);
            $statement->execute();
            $found = $statement->get_result()->fetch_column();
            $statement->close();
            if ($found === false) {
                throw new InvalidArgumentException('One of the selected academic records is unavailable in this institution.');
            }
        }
        $statement = $db->prepare(
            'UPDATE admission_enquiries
             SET applicant_name = ?, student_name = ?, mobile = ?, email = ?,
                 relationship_to_student = ?, academic_year_id = ?, course_id = ?, class_id = ?,
                 source = ?, status = ?, follow_up_date = ?, notes = ?, updated_by = ?
             WHERE institution_id = ? AND id = ?'
        );
        $databaseActorId = institution_audit_actor_user_id($actorUserId);
        $statement->bind_param(
            'sssssiiissssiii',
            $data['applicant_name'],
            $data['student_name'],
            $data['mobile'],
            $data['email'],
            $data['relationship_to_student'],
            $data['academic_year_id'],
            $data['course_id'],
            $data['class_id'],
            $data['source'],
            $data['status'],
            $data['follow_up_date'],
            $data['notes'],
            $databaseActorId,
            $institutionId,
            $enquiryId
        );
        $statement->execute();
        $statement->close();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            $actorType,
            'admission.enquiry_updated',
            'admission_enquiry',
            (string) $enquiryId,
            ['status' => $before['status']],
            ['status' => $data['status']]
        );
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function admission_workflow_save_enquiry(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    string $actorType,
    array $input
): int {
    $data = [
        'applicant_name' => admission_workflow_text($input['applicant_name'] ?? '', 'applicant name', 150, true),
        'student_name' => admission_workflow_text($input['student_name'] ?? '', 'student name', 150, true),
        'mobile' => admission_workflow_text($input['mobile'] ?? '', 'contact number', 20, true),
        'email' => admission_workflow_text($input['email'] ?? '', 'email', 255),
        'relationship_to_student' => admission_workflow_text($input['relationship_to_student'] ?? '', 'relationship', 80),
        'academic_year_id' => admission_workflow_optional_id($input['academic_year_id'] ?? '', 'academic year'),
        'course_id' => admission_workflow_optional_id($input['course_id'] ?? '', 'course'),
        'class_id' => admission_workflow_optional_id($input['class_id'] ?? '', 'class'),
        'source' => admission_workflow_text($input['source'] ?? '', 'enquiry source', 80),
        'status' => admission_workflow_text($input['status'] ?? 'new', 'enquiry status', 20, true),
        'follow_up_date' => admission_workflow_text($input['follow_up_date'] ?? '', 'follow-up date', 10),
        'notes' => admission_workflow_text($input['notes'] ?? '', 'enquiry notes', 20000),
    ];
    if (!in_array($data['status'], ['new', 'contacted', 'follow_up', 'closed'], true)) {
        throw new InvalidArgumentException('Choose a supported enquiry status.');
    }
    if ($data['email'] !== null && filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('Enter a valid enquiry email address.');
    }
    if ($data['follow_up_date'] !== null) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $data['follow_up_date']);
        if ($date === false || $date->format('Y-m-d') !== $data['follow_up_date']) {
            throw new InvalidArgumentException('Enter a valid follow-up date.');
        }
    }
    $db->begin_transaction();
    try {
        foreach (['academic_year_id' => 'academic_years', 'course_id' => 'courses', 'class_id' => 'classes'] as $field => $table) {
            if ($data[$field] === null) {
                continue;
            }
            $statement = $db->prepare("SELECT id FROM {$table} WHERE institution_id = ? AND id = ? LIMIT 1");
            $statement->bind_param('ii', $institutionId, $data[$field]);
            $statement->execute();
            $found = $statement->get_result()->fetch_column();
            $statement->close();
            if ($found === false) {
                throw new InvalidArgumentException('One of the selected academic records is unavailable in this institution.');
            }
        }
        $databaseActorId = institution_audit_actor_user_id($actorUserId);
        $statement = $db->prepare(
            "INSERT INTO admission_enquiries
                (institution_id, reference_number, applicant_name, student_name, mobile, email,
                 relationship_to_student, academic_year_id, course_id, class_id, source, status,
                 follow_up_date, notes, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $pendingReference = 'ENQ-PENDING-' . bin2hex(random_bytes(6));
        $statement->bind_param(
            'issssssiiissssii',
            $institutionId,
            $pendingReference,
            $data['applicant_name'],
            $data['student_name'],
            $data['mobile'],
            $data['email'],
            $data['relationship_to_student'],
            $data['academic_year_id'],
            $data['course_id'],
            $data['class_id'],
            $data['source'],
            $data['status'],
            $data['follow_up_date'],
            $data['notes'],
            $databaseActorId,
            $databaseActorId
        );
        $statement->execute();
        $enquiryId = (int) $statement->insert_id;
        $statement->close();
        $reference = 'ENQ-' . date('Y') . '-' . str_pad((string) $enquiryId, 8, '0', STR_PAD_LEFT);
        $statement = $db->prepare(
            'UPDATE admission_enquiries SET reference_number = ? WHERE institution_id = ? AND id = ?'
        );
        $statement->bind_param('sii', $reference, $institutionId, $enquiryId);
        $statement->execute();
        $statement->close();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            $actorType,
            'admission.enquiry_created',
            'admission_enquiry',
            (string) $enquiryId,
            null,
            ['reference_number' => $reference, 'status' => $data['status']]
        );
        $db->commit();
        return $enquiryId;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function admission_workflow_convert_enquiry(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    string $actorType,
    int $enquiryId
): int {
    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            'SELECT * FROM admission_enquiries
             WHERE institution_id = ? AND id = ? FOR UPDATE'
        );
        $statement->bind_param('ii', $institutionId, $enquiryId);
        $statement->execute();
        $enquiry = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($enquiry)) {
            throw new OutOfBoundsException('Enquiry not found in this institution.');
        }
        if ($enquiry['admission_id'] !== null || $enquiry['status'] === 'converted') {
            throw new DomainException('This enquiry has already been converted.');
        }
        $statement = $db->prepare(
            "INSERT INTO admissions
                (institution_id, application_reference, applicant_name, applicant_mobile,
                 applicant_email, relationship_to_student, student_name, academic_year_id,
                 course_id, class_id, enquiry_source, status, submission_notes)
             VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)"
        );
        $statement->bind_param(
            'isssssiiiss',
            $institutionId,
            $enquiry['applicant_name'],
            $enquiry['mobile'],
            $enquiry['email'],
            $enquiry['relationship_to_student'],
            $enquiry['student_name'],
            $enquiry['academic_year_id'],
            $enquiry['course_id'],
            $enquiry['class_id'],
            $enquiry['source'],
            $enquiry['notes']
        );
        $statement->execute();
        $admissionId = (int) $statement->insert_id;
        $statement->close();
        $reference = 'ADM-' . date('Y') . '-' . str_pad((string) $admissionId, 8, '0', STR_PAD_LEFT);
        $statement = $db->prepare(
            'UPDATE admissions SET application_reference = ? WHERE institution_id = ? AND id = ?'
        );
        $statement->bind_param('sii', $reference, $institutionId, $admissionId);
        $statement->execute();
        $statement->close();
        $statement = $db->prepare(
            "UPDATE admission_enquiries SET admission_id = ?, status = 'converted',
                updated_by = ? WHERE institution_id = ? AND id = ?"
        );
        $databaseActorId = institution_audit_actor_user_id($actorUserId);
        $statement->bind_param('iiii', $admissionId, $databaseActorId, $institutionId, $enquiryId);
        $statement->execute();
        $statement->close();
        admission_workflow_record_status(
            $db,
            $institutionId,
            $admissionId,
            null,
            'draft',
            'Converted from enquiry ' . $enquiry['reference_number'],
            institution_audit_actor_user_id($actorUserId),
            'created'
        );
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            $actorType,
            'admission.enquiry_converted',
            'admission',
            (string) $admissionId,
            ['enquiry_id' => $enquiryId],
            ['status' => 'draft']
        );
        $db->commit();
        return $admissionId;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function admission_workflow_set_student_code_prefix(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    string $actorType,
    string $prefix
): void {
    $prefix = strtoupper(trim($prefix));
    if ($prefix === '' || strlen($prefix) > 20 || preg_match('/^[A-Z0-9-]+$/D', $prefix) !== 1) {
        throw new InvalidArgumentException('Use a prefix of up to 20 letters, digits, or hyphens.');
    }
    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            'INSERT INTO student_code_settings (institution_id, prefix, updated_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE prefix = VALUES(prefix), updated_by = VALUES(updated_by)'
        );
        $databaseActorId = institution_audit_actor_user_id($actorUserId);
        $statement->bind_param('isi', $institutionId, $prefix, $databaseActorId);
        $statement->execute();
        $statement->close();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            $actorType,
            'admission.student_code_prefix_updated',
            'student_code_settings',
            (string) $institutionId,
            null,
            ['prefix' => $prefix]
        );
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function admission_workflow_student_code_years(mysqli $db, int $institutionId): array
{
    $statement = $db->prepare(
        'SELECT ay.id, ay.name, ay.status,
                COALESCE(sequence.start_number, 1) AS start_number,
                sequence.next_number
         FROM academic_years ay
         LEFT JOIN student_code_sequences sequence
           ON sequence.institution_id = ay.institution_id
          AND sequence.academic_year_id = ay.id
         WHERE ay.institution_id = ?
         ORDER BY ay.start_date DESC, ay.id DESC'
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $years = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $years;
}

function admission_workflow_set_student_code_start(
    mysqli $db,
    int $institutionId,
    int $academicYearId,
    int $actorUserId,
    string $actorType,
    int $startNumber
): void {
    if ($academicYearId < 1 || $startNumber < 1 || $startNumber > 999999999) {
        throw new InvalidArgumentException('Choose an academic year and a starting number from 1 to 999,999,999.');
    }
    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            'SELECT id FROM academic_years
             WHERE institution_id = ? AND id = ? FOR UPDATE'
        );
        $statement->bind_param('ii', $institutionId, $academicYearId);
        $statement->execute();
        $yearExists = $statement->get_result()->fetch_column();
        $statement->close();
        if ($yearExists === false) {
            throw new OutOfBoundsException('Academic year not found in this institution.');
        }
        $statement = $db->prepare(
            'SELECT start_number, next_number FROM student_code_sequences
             WHERE institution_id = ? AND academic_year_id = ? FOR UPDATE'
        );
        $statement->bind_param('ii', $institutionId, $academicYearId);
        $statement->execute();
        $before = $statement->get_result()->fetch_assoc();
        $statement->close();
        $statement = $db->prepare(
            'INSERT INTO student_code_sequences
                (institution_id, academic_year_id, start_number, next_number)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                start_number = VALUES(start_number),
                next_number = GREATEST(next_number, VALUES(start_number))'
        );
        $nextNumber = $startNumber;
        $statement->bind_param('iiii', $institutionId, $academicYearId, $startNumber, $nextNumber);
        $statement->execute();
        $statement->close();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            $actorType,
            'admission.student_code_sequence_updated',
            'student_code_sequence',
            (string) $academicYearId,
            is_array($before) ? [
                'start_number' => (int) $before['start_number'],
                'next_number' => (int) $before['next_number'],
            ] : null,
            ['start_number' => $startNumber]
        );
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function admission_workflow_notification_settings(mysqli $db, int $institutionId): array
{
    $settings = array_fill_keys(array_keys(ADMISSION_NOTIFICATION_EVENTS), false);
    $statement = $db->prepare(
        'SELECT event_type, enabled FROM admission_notification_settings
         WHERE institution_id = ?'
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $result = $statement->get_result();
    while ($row = $result->fetch_assoc()) {
        if (array_key_exists($row['event_type'], $settings)) {
            $settings[$row['event_type']] = (bool) $row['enabled'];
        }
    }
    $statement->close();
    return $settings;
}

function admission_workflow_notify(
    mysqli $db,
    int $institutionId,
    int $admissionId,
    string $eventType
): ?bool {
    if (!array_key_exists($eventType, ADMISSION_NOTIFICATION_EVENTS)) {
        throw new InvalidArgumentException('Choose a supported admission notification event.');
    }
    $statement = $db->prepare(
        'SELECT a.application_reference, a.student_name, a.applicant_email,
                a.father_email, a.mother_email, a.guardian_email, a.review_notes,
                settings.enabled
         FROM admissions a
         LEFT JOIN admission_notification_settings settings
           ON settings.institution_id = a.institution_id AND settings.event_type = ?
         WHERE a.institution_id = ? AND a.id = ? LIMIT 1'
    );
    $statement->bind_param('sii', $eventType, $institutionId, $admissionId);
    $statement->execute();
    $application = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_array($application)) {
        throw new OutOfBoundsException('Admission application not found in this institution.');
    }
    if ((int) ($application['enabled'] ?? 0) !== 1) {
        return null;
    }

    $recipient = null;
    foreach (['applicant_email', 'father_email', 'mother_email', 'guardian_email'] as $field) {
        if (is_string($application[$field]) && filter_var($application[$field], FILTER_VALIDATE_EMAIL) !== false) {
            $recipient = $application[$field];
            break;
        }
    }
    if ($recipient === null) {
        log_error('admission_notification_skipped', [
            'event' => $eventType,
            'admission_id' => $admissionId,
            'reason' => 'no_valid_recipient',
        ]);
        return false;
    }

    $reference = is_string($application['application_reference']) && $application['application_reference'] !== ''
        ? $application['application_reference']
        : 'Application #' . $admissionId;
    $label = ADMISSION_NOTIFICATION_EVENTS[$eventType];
    $message = $label . ' for ' . $application['student_name'] . '. Reference: ' . $reference . '.';
    if (in_array($eventType, ['information_requested', 'application_rejected'], true)
        && is_string($application['review_notes']) && trim($application['review_notes']) !== '') {
        $message .= "\n\nReview notes: " . trim($application['review_notes']);
    }
    try {
        send_otp_email($recipient, 'Admission update: ' . $label, $message, null, null, $message);
        return true;
    } catch (OtpEmailDeliveryException $exception) {
        log_error('admission_notification_delivery_failed', [
            'event' => $eventType,
            'admission_id' => $admissionId,
            'error_type' => get_class($exception),
        ]);
        return false;
    }
}

function admission_workflow_notify_after_commit(
    mysqli $db,
    int $institutionId,
    int $admissionId,
    string $eventType
): ?bool {
    try {
        return admission_workflow_notify($db, $institutionId, $admissionId, $eventType);
    } catch (Throwable $exception) {
        log_error('admission_notification_failed', [
            'event' => $eventType,
            'admission_id' => $admissionId,
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
        ]);
        return false;
    }
}

function admission_workflow_save_notification_settings(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    string $actorType,
    mixed $enabledEvents
): void {
    if (!is_array($enabledEvents)) {
        throw new InvalidArgumentException('Choose valid admission notification preferences.');
    }
    $enabled = [];
    foreach ($enabledEvents as $event) {
        if (!is_string($event) || !array_key_exists($event, ADMISSION_NOTIFICATION_EVENTS)) {
            throw new InvalidArgumentException('Choose valid admission notification preferences.');
        }
        $enabled[$event] = true;
    }
    $before = admission_workflow_notification_settings($db, $institutionId);
    $databaseActorId = institution_audit_actor_user_id($actorUserId);
    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            'INSERT INTO admission_notification_settings
                (institution_id, event_type, enabled, updated_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_by = VALUES(updated_by)'
        );
        foreach (array_keys(ADMISSION_NOTIFICATION_EVENTS) as $eventType) {
            $isEnabled = isset($enabled[$eventType]) ? 1 : 0;
            $statement->bind_param('isii', $institutionId, $eventType, $isEnabled, $databaseActorId);
            $statement->execute();
        }
        $statement->close();
        $after = [];
        foreach (array_keys(ADMISSION_NOTIFICATION_EVENTS) as $eventType) {
            $after[$eventType] = isset($enabled[$eventType]);
        }
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            $actorType,
            'admission.notification_settings_updated',
            'admission_notification_settings',
            (string) $institutionId,
            $before,
            $after
        );
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function admission_workflow_document_storage_directory(): string
{
    $directory = APP_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'admission-documents';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Private admission document storage is unavailable.');
    }
    $realDirectory = realpath($directory);
    $storageRoot = realpath(APP_ROOT . DIRECTORY_SEPARATOR . 'storage');
    if ($realDirectory === false || $storageRoot === false
        || !str_starts_with(
            strtolower($realDirectory . DIRECTORY_SEPARATOR),
            strtolower($storageRoot . DIRECTORY_SEPARATOR)
        )) {
        throw new RuntimeException('Private admission document storage is unavailable.');
    }
    return $realDirectory;
}

function admission_workflow_upload_document(
    mysqli $db,
    int $institutionId,
    int $admissionId,
    int $actorUserId,
    string $actorType,
    string $documentType,
    array $upload
): int {
    $documentType = admission_workflow_text($documentType, 'document category', 80, true);
    $allowedCategories = ['birth_certificate', 'identity', 'address', 'previous_school', 'photo', 'other'];
    if (!in_array($documentType, $allowedCategories, true)) {
        throw new InvalidArgumentException('Choose a supported document category.');
    }
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || !is_string($upload['tmp_name'] ?? null)
        || !is_uploaded_file($upload['tmp_name'])
        || !is_int($upload['size'] ?? null)
        || $upload['size'] < 1
        || $upload['size'] > ADMISSION_DOCUMENT_MAX_BYTES) {
        throw new InvalidArgumentException('Choose a document no larger than 5 MB.');
    }
    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $fileInfo->file($upload['tmp_name']);
    $allowedTypes = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!is_string($mime) || !isset($allowedTypes[$mime])) {
        throw new InvalidArgumentException('Upload a PDF, JPEG, PNG, or WebP document.');
    }
    $originalName = basename((string) ($upload['name'] ?? 'document'));
    $originalName = admission_workflow_text($originalName, 'file name', 255, true);
    $storageKey = bin2hex(random_bytes(32));
    $fileSize = (int) $upload['size'];
    $uploadedBy = institution_audit_actor_user_id($actorUserId);
    $extension = $allowedTypes[$mime];
    $directory = admission_workflow_document_storage_directory();
    $destination = $directory . DIRECTORY_SEPARATOR . $storageKey . '.' . $extension;
    $statement = $db->prepare(
        'SELECT id FROM admissions WHERE institution_id = ? AND id = ? LIMIT 1'
    );
    $statement->bind_param('ii', $institutionId, $admissionId);
    $statement->execute();
    $found = $statement->get_result()->fetch_column();
    $statement->close();
    if ($found === false) {
        throw new OutOfBoundsException('Admission application not found in this institution.');
    }
    $db->begin_transaction();
    try {
        if (!move_uploaded_file($upload['tmp_name'], $destination)) {
            throw new RuntimeException('The admission document could not be stored.');
        }
        chmod($destination, 0600);
        $statement = $db->prepare(
            "INSERT INTO admission_documents
                (institution_id, admission_id, document_type, display_name, file_path,
                 mime_type, uploaded_by, verification_status, storage_key, file_size, original_extension)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)"
        );
        $relativePath = 'storage/admission-documents/' . $storageKey . '.' . $extension;
        $statement->bind_param(
            'iissssisis',
            $institutionId,
            $admissionId,
            $documentType,
            $originalName,
            $relativePath,
            $mime,
            $uploadedBy,
            $storageKey,
            $fileSize,
            $extension
        );
        $statement->execute();
        $documentId = (int) $statement->insert_id;
        $statement->close();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            $actorType,
            'admission.document_uploaded',
            'admission_document',
            (string) $documentId,
            null,
            ['admission_id' => $admissionId, 'document_type' => $documentType]
        );
        $db->commit();
        return $documentId;
    } catch (Throwable $exception) {
        $db->rollback();
        if (is_file($destination)) {
            unlink($destination);
        }
        throw $exception;
    }
}

function admission_workflow_get_document(
    mysqli $db,
    int $institutionId,
    int $documentId
): ?array {
    $statement = $db->prepare(
        'SELECT id, admission_id, document_type, display_name, file_path, mime_type,
                verification_status, verification_remarks, storage_key, original_extension
         FROM admission_documents WHERE institution_id = ? AND id = ? LIMIT 1'
    );
    $statement->bind_param('ii', $institutionId, $documentId);
    $statement->execute();
    $document = $statement->get_result()->fetch_assoc();
    $statement->close();
    return is_array($document) ? $document : null;
}

function admission_workflow_list_documents(mysqli $db, int $institutionId, int $admissionId): array
{
    $statement = $db->prepare(
        'SELECT id, document_type, display_name, mime_type, file_size, verification_status,
                verification_remarks, verified_at, created_at
         FROM admission_documents
         WHERE institution_id = ? AND admission_id = ?
         ORDER BY created_at DESC, id DESC'
    );
    $statement->bind_param('ii', $institutionId, $admissionId);
    $statement->execute();
    $documents = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $documents;
}

function admission_workflow_verify_document(
    mysqli $db,
    int $institutionId,
    int $documentId,
    int $actorUserId,
    string $actorType,
    string $status,
    string $remarks
): void {
    if (!in_array($status, ['verified', 'rejected'], true)) {
        throw new InvalidArgumentException('Choose verified or rejected for the document.');
    }
    $remarks = admission_workflow_text($remarks, 'verification remarks', 1000) ?? '';
    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            'SELECT verification_status FROM admission_documents
             WHERE institution_id = ? AND id = ? FOR UPDATE'
        );
        $statement->bind_param('ii', $institutionId, $documentId);
        $statement->execute();
        $beforeStatus = $statement->get_result()->fetch_column();
        $statement->close();
        if ($beforeStatus === false) {
            throw new OutOfBoundsException('Admission document not found in this institution.');
        }
        $databaseActorId = institution_audit_actor_user_id($actorUserId);
        $statement = $db->prepare(
            "UPDATE admission_documents
             SET verification_status = ?, verification_remarks = ?, verified_by = ?,
                 verified_at = CURRENT_TIMESTAMP
             WHERE institution_id = ? AND id = ?"
        );
        $statement->bind_param('ssiii', $status, $remarks, $databaseActorId, $institutionId, $documentId);
        $statement->execute();
        $statement->close();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            $actorType,
            'admission.document_verified',
            'admission_document',
            (string) $documentId,
            ['status' => (string) $beforeStatus],
            ['status' => $status, 'remarks' => $remarks]
        );
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}
