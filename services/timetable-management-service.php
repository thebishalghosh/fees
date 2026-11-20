<?php
declare(strict_types=1);

require_once __DIR__ . '/academic-management-service.php';
require_once __DIR__ . '/audit-service.php';

function timetable_management_actor_id(int $actorUserId): ?int
{
    return $actorUserId > 0 ? $actorUserId : null;
}

function timetable_management_audit(
    mysqli $db,
    int $institutionId,
    string $action,
    string $entityType,
    int $entityId,
    array $details
): void {
    $user = current_user();
    write_audit_log(
        $db,
        $institutionId,
        $user === null ? null : timetable_management_actor_id((int) $user['id']),
        is_array($user) ? (string) $user['user_type'] : null,
        $action,
        $entityType,
        (string) $entityId,
        null,
        $details
    );
}

function timetable_management_schema_ready(mysqli $db): bool
{
    $requiredTables = [
        'teacher_group_assignments',
        'teacher_subject_assignments',
        'classrooms',
        'timetable_period_schedules',
        'timetable_periods',
        'academic_timetables',
        'timetable_versions',
        'timetable_entries',
    ];
    $quoted = implode(',', array_fill(0, count($requiredTables), '?'));
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name IN (' . $quoted . ')'
    );
    $types = str_repeat('s', count($requiredTables));
    $parameters = [$types];
    foreach ($requiredTables as &$table) {
        $parameters[] = &$table;
    }
    unset($table);
    call_user_func_array([$statement, 'bind_param'], $parameters);
    $statement->execute();
    $ready = (int) $statement->get_result()->fetch_column() === count($requiredTables);
    $statement->close();
    return $ready;
}

function timetable_management_group(mysqli $db, int $institutionId, int $yearId, int $groupId): array
{
    $statement = $db->prepare(
        "SELECT g.id, g.group_type, g.class_id, g.section_id, c.name AS class_name,
                s.name AS section_name, ay.name AS academic_year_name
         FROM academic_groups g
         JOIN academic_years ay
           ON ay.institution_id = g.institution_id AND ay.id = g.academic_year_id
         JOIN classes c ON c.institution_id = g.institution_id AND c.id = g.class_id
         JOIN academic_year_classes yc
           ON yc.institution_id = g.institution_id AND yc.academic_year_id = g.academic_year_id
          AND yc.class_id = g.class_id AND yc.status <> 'closed'
         LEFT JOIN sections s
           ON s.institution_id = g.institution_id AND s.academic_year_id = g.academic_year_id
          AND s.class_id = g.class_id AND s.id = g.section_id
         WHERE g.institution_id = ? AND g.academic_year_id = ? AND g.id = ?
           AND g.group_type IN ('class','section') AND c.status = 'active'
         LIMIT 1"
    );
    $statement->bind_param('iii', $institutionId, $yearId, $groupId);
    $statement->execute();
    $group = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_array($group) || ($group['group_type'] === 'section' && $group['section_name'] === null)) {
        throw new DomainException('Choose an active class or section in this institution and academic year.');
    }
    return $group;
}

function timetable_management_subject_is_available(
    mysqli $db,
    int $institutionId,
    int $yearId,
    int $classId,
    ?int $sectionId,
    int $subjectId
): bool {
    $statement = $db->prepare(
        "SELECT 1
         FROM class_subjects cs
         JOIN subjects s ON s.institution_id = cs.institution_id AND s.id = cs.subject_id
         WHERE cs.institution_id = ? AND cs.academic_year_id = ? AND cs.class_id = ?
           AND cs.subject_id = ? AND cs.status = 'active' AND s.status = 'active'
           AND (
               ? IS NULL
               OR EXISTS (
                   SELECT 1 FROM section_subjects ss
                   WHERE ss.institution_id = cs.institution_id AND ss.academic_year_id = cs.academic_year_id
                     AND ss.class_id = cs.class_id AND ss.section_id = ?
                     AND ss.subject_id = cs.subject_id AND ss.status = 'active'
                     AND ss.assignment_mode = 'include'
               )
               OR NOT EXISTS (
                   SELECT 1 FROM section_subjects sx
                   WHERE sx.institution_id = cs.institution_id AND sx.academic_year_id = cs.academic_year_id
                     AND sx.class_id = cs.class_id AND sx.section_id = ?
                     AND sx.subject_id = cs.subject_id AND sx.status = 'active'
                     AND sx.assignment_mode = 'exclude'
               )
           )
         LIMIT 1"
    );
    $statement->bind_param(
        'iiiiiii',
        $institutionId,
        $yearId,
        $classId,
        $subjectId,
        $sectionId,
        $sectionId,
        $sectionId
    );
    $statement->execute();
    $available = $statement->get_result()->num_rows === 1;
    $statement->close();
    return $available;
}

function timetable_management_create_teacher_group_assignment(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    int $yearId,
    int $groupId,
    int $instructorId
): int {
    $db->begin_transaction();
    try {
        academic_management_require_open_year($db, $institutionId, $yearId);
        $group = timetable_management_group($db, $institutionId, $yearId, $groupId);
        $classId = (int) $group['class_id'];
        $statement = $db->prepare(
            "SELECT i.id FROM instructors i
             LEFT JOIN users u ON u.institution_id = i.institution_id AND u.id = i.user_id
             WHERE i.institution_id = ? AND i.id = ? AND i.status = 'active'
               AND (i.user_id IS NULL OR u.status = 'active')
             LIMIT 1"
        );
        $statement->bind_param('ii', $institutionId, $instructorId);
        $statement->execute();
        $teacherExists = $statement->get_result()->num_rows === 1;
        $statement->close();
        if (!$teacherExists) {
            throw new DomainException('Only active instructors with active portal accounts may be assigned.');
        }

        $statement = $db->prepare(
            'SELECT id FROM teacher_group_assignments
             WHERE institution_id = ? AND academic_year_id = ? AND academic_group_id = ?
               AND instructor_id = ? FOR UPDATE'
        );
        $statement->bind_param('iiii', $institutionId, $yearId, $groupId, $instructorId);
        $statement->execute();
        $existing = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (is_array($existing)) {
            $assignmentId = (int) $existing['id'];
            $statement = $db->prepare(
                "UPDATE teacher_group_assignments SET class_id = ?, status = 'active', assigned_by = ?
                 WHERE institution_id = ? AND id = ?"
            );
            $assignedBy = timetable_management_actor_id($actorUserId);
            $statement->bind_param('iiii', $classId, $assignedBy, $institutionId, $assignmentId);
            $statement->execute();
            $statement->close();
        } else {
            $statement = $db->prepare(
                'INSERT INTO teacher_group_assignments
                    (institution_id, academic_year_id, class_id, academic_group_id, instructor_id, assigned_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $assignedBy = timetable_management_actor_id($actorUserId);
            $statement->bind_param('iiiiii', $institutionId, $yearId, $classId, $groupId, $instructorId, $assignedBy);
            $statement->execute();
            $assignmentId = (int) $statement->insert_id;
            $statement->close();
        }
        timetable_management_audit($db, $institutionId, 'teacher_group_assignment.saved',
            'teacher_group_assignment', $assignmentId, [
                'academic_year_id' => $yearId,
                'academic_group_id' => $groupId,
                'instructor_id' => $instructorId,
            ]);
        $db->commit();
        return $assignmentId;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function timetable_management_assign_teacher_subject(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    int $yearId,
    int $groupId,
    int $teacherGroupAssignmentId,
    int $subjectId
): int {
    $db->begin_transaction();
    try {
        academic_management_require_open_year($db, $institutionId, $yearId);
        $group = timetable_management_group($db, $institutionId, $yearId, $groupId);
        $classId = (int) $group['class_id'];
        if (!timetable_management_subject_is_available(
            $db,
            $institutionId,
            $yearId,
            $classId,
            $group['section_id'] === null ? null : (int) $group['section_id'],
            $subjectId
        )) {
            throw new DomainException('The subject is not mapped to this class or section.');
        }
        $statement = $db->prepare(
            "SELECT tga.id
             FROM teacher_group_assignments tga
             JOIN instructors i ON i.institution_id = tga.institution_id AND i.id = tga.instructor_id
             LEFT JOIN users u ON u.institution_id = i.institution_id AND u.id = i.user_id
             WHERE tga.institution_id = ? AND tga.academic_year_id = ? AND tga.academic_group_id = ?
               AND tga.class_id = ? AND tga.id = ? AND tga.status = 'active'
               AND i.status = 'active' AND (i.user_id IS NULL OR u.status = 'active')
             LIMIT 1 FOR UPDATE"
        );
        $statement->bind_param('iiiii', $institutionId, $yearId, $groupId, $classId, $teacherGroupAssignmentId);
        $statement->execute();
        $eligible = $statement->get_result()->num_rows === 1;
        $statement->close();
        if (!$eligible) {
            throw new DomainException('Choose an active teacher assignment for this class or section.');
        }

        $statement = $db->prepare(
            'SELECT id FROM teacher_subject_assignments
             WHERE institution_id = ? AND academic_year_id = ? AND academic_group_id = ?
               AND teacher_group_assignment_id = ? AND subject_id = ? FOR UPDATE'
        );
        $statement->bind_param('iiiii', $institutionId, $yearId, $groupId, $teacherGroupAssignmentId, $subjectId);
        $statement->execute();
        $existing = $statement->get_result()->fetch_assoc();
        $statement->close();
        $assignedBy = timetable_management_actor_id($actorUserId);
        if (is_array($existing)) {
            $assignmentId = (int) $existing['id'];
            $statement = $db->prepare(
                "UPDATE teacher_subject_assignments SET class_id = ?, status = 'active', assigned_by = ?
                 WHERE institution_id = ? AND id = ?"
            );
            $statement->bind_param('iiii', $classId, $assignedBy, $institutionId, $assignmentId);
        } else {
            $statement = $db->prepare(
                'INSERT INTO teacher_subject_assignments
                    (institution_id, academic_year_id, class_id, academic_group_id,
                     teacher_group_assignment_id, subject_id, assigned_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->bind_param(
                'iiiiiii',
                $institutionId,
                $yearId,
                $classId,
                $groupId,
                $teacherGroupAssignmentId,
                $subjectId,
                $assignedBy
            );
        }
        $statement->execute();
        if (!is_array($existing)) {
            $assignmentId = (int) $statement->insert_id;
        }
        $statement->close();
        timetable_management_audit($db, $institutionId, 'teacher_subject_assignment.saved',
            'teacher_subject_assignment', $assignmentId, [
                'academic_year_id' => $yearId,
                'academic_group_id' => $groupId,
                'subject_id' => $subjectId,
            ]);
        $db->commit();
        return $assignmentId;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function timetable_management_set_assignment_status(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    int $assignmentId,
    string $assignmentType,
    string $status
): void {
    if (!in_array($assignmentType, ['teacher_group_assignments', 'teacher_subject_assignments'], true)
        || !in_array($status, ['active', 'inactive'], true)) {
        throw new InvalidArgumentException('Choose a valid assignment status.');
    }
    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            "SELECT academic_year_id FROM {$assignmentType}
             WHERE institution_id = ? AND id = ?"
        );
        $statement->bind_param('ii', $institutionId, $assignmentId);
        $statement->execute();
        $assignment = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($assignment)) {
            throw new DomainException('The assignment was not found.');
        }
        $yearId = (int) $assignment['academic_year_id'];
        academic_management_require_open_year($db, $institutionId, $yearId);
        $statement = $db->prepare(
            "SELECT id FROM {$assignmentType}
             WHERE institution_id = ? AND id = ? FOR UPDATE"
        );
        $statement->bind_param('ii', $institutionId, $assignmentId);
        $statement->execute();
        $statement->get_result()->fetch_assoc();
        $statement->close();
        $statement = $db->prepare(
            "UPDATE {$assignmentType} SET status = ? WHERE institution_id = ? AND id = ?"
        );
        $statement->bind_param('sii', $status, $institutionId, $assignmentId);
        $statement->execute();
        $statement->close();
        timetable_management_audit($db, $institutionId, 'timetable_assignment.status_changed',
            $assignmentType, $assignmentId, [
                'academic_year_id' => $yearId,
                'status' => $status,
            ]);
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function timetable_management_save_classroom(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    int $classroomId,
    string $roomCode,
    string $name,
    ?string $building,
    ?int $capacity,
    string $status
): int {
    $roomCode = trim($roomCode);
    $name = trim($name);
    $building = trim((string) $building);
    if ($roomCode === '' || strlen($roomCode) > 50 || $name === '' || strlen($name) > 120
        || strlen($building) > 120 || ($capacity !== null && $capacity < 1)
        || !in_array($status, ['active', 'inactive'], true)) {
        throw new InvalidArgumentException('Enter a valid classroom code, name, capacity, and status.');
    }
    $buildingValue = $building === '' ? null : $building;
    $db->begin_transaction();
    try {
    if ($classroomId > 0) {
        $statement = $db->prepare(
            'SELECT id FROM classrooms WHERE institution_id = ? AND id = ? FOR UPDATE'
        );
        $statement->bind_param('ii', $institutionId, $classroomId);
        $statement->execute();
        $roomExists = $statement->get_result()->num_rows === 1;
        $statement->close();
        if (!$roomExists) {
            throw new DomainException('The classroom was not found in this institution.');
        }
        if ($status === 'inactive') {
            $statement = $db->prepare(
                "SELECT 1 FROM timetable_entries e
                 JOIN timetable_versions v
                   ON v.institution_id = e.institution_id AND v.academic_year_id = e.academic_year_id
                  AND v.id = e.timetable_version_id AND v.status = 'published'
                 WHERE e.institution_id = ? AND e.classroom_id = ? LIMIT 1"
            );
            $statement->bind_param('ii', $institutionId, $classroomId);
            $statement->execute();
            $inUse = $statement->get_result()->num_rows > 0;
            $statement->close();
            if ($inUse) {
                throw new DomainException('A classroom used by a currently published timetable cannot be deactivated.');
            }
        }
        $statement = $db->prepare(
            'UPDATE classrooms SET room_code = ?, name = ?, building = ?, capacity = ?, status = ?
             WHERE institution_id = ? AND id = ?'
        );
        $statement->bind_param(
            'sssisii',
            $roomCode,
            $name,
            $buildingValue,
            $capacity,
            $status,
            $institutionId,
            $classroomId
        );
    } else {
        $statement = $db->prepare(
            'INSERT INTO classrooms (institution_id, room_code, name, building, capacity, status)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->bind_param('isssis', $institutionId, $roomCode, $name, $buildingValue, $capacity, $status);
    }
    $statement->execute();
    if ($classroomId < 1) {
        $classroomId = (int) $statement->insert_id;
    }
    $statement->close();
    timetable_management_audit($db, $institutionId, 'classroom.saved',
    'classroom', $classroomId, [
            'room_code' => $roomCode,
            'status' => $status,
        ]);
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
    return $classroomId;
}

function timetable_management_list_setup_options(mysqli $db, int $institutionId, int $yearId): array
{
    $years = [];
    $result = $db->query(
        'SELECT id, name, status FROM academic_years
         WHERE institution_id = ' . (int) $institutionId . ' ORDER BY start_date DESC, id DESC'
    );
    while ($row = $result->fetch_assoc()) {
        $years[] = $row;
    }

    $groups = [];
    $statement = $db->prepare(
        "SELECT g.id, g.group_type, g.class_id, g.section_id, c.name AS class_name,
                s.name AS section_name
         FROM academic_groups g
         JOIN classes c ON c.institution_id = g.institution_id AND c.id = g.class_id
         LEFT JOIN sections s
           ON s.institution_id = g.institution_id AND s.academic_year_id = g.academic_year_id
          AND s.class_id = g.class_id AND s.id = g.section_id AND s.status = 'active'
         WHERE g.institution_id = ? AND g.academic_year_id = ?
           AND g.group_type IN ('class','section') AND c.status = 'active'
           AND (g.group_type = 'section' OR NOT EXISTS (
               SELECT 1 FROM sections active_section
               WHERE active_section.institution_id = g.institution_id
                 AND active_section.academic_year_id = g.academic_year_id
                 AND active_section.class_id = g.class_id AND active_section.status = 'active'
           ))
         ORDER BY c.display_order, c.name, s.name"
    );
    $statement->bind_param('ii', $institutionId, $yearId);
    $statement->execute();
    $result = $statement->get_result();
    while ($row = $result->fetch_assoc()) {
        if ($row['group_type'] === 'section' && $row['section_name'] === null) {
            continue;
        }
        $row['label'] = (string) $row['class_name']
            . ($row['section_name'] !== null ? ' / ' . (string) $row['section_name'] : ' / Whole class');
        $groups[] = $row;
    }
    $statement->close();

    $instructors = [];
    $statement = $db->prepare(
        "SELECT i.id, i.full_name, i.email
         FROM instructors i
         LEFT JOIN users u ON u.institution_id = i.institution_id AND u.id = i.user_id
         WHERE i.institution_id = ? AND i.status = 'active'
           AND (i.user_id IS NULL OR u.status = 'active')
         ORDER BY i.full_name, i.id"
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $result = $statement->get_result();
    while ($row = $result->fetch_assoc()) {
        $instructors[] = $row;
    }
    $statement->close();

    $classrooms = [];
    $statement = $db->prepare(
        'SELECT id, room_code, name, building, capacity, status
         FROM classrooms WHERE institution_id = ? ORDER BY room_code'
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $result = $statement->get_result();
    while ($row = $result->fetch_assoc()) {
        $classrooms[] = $row;
    }
    $statement->close();

    $periodSchedules = [];
    $statement = $db->prepare(
        'SELECT id, version_number, status FROM timetable_period_schedules
         WHERE institution_id = ? AND academic_year_id = ? ORDER BY version_number DESC'
    );
    $statement->bind_param('ii', $institutionId, $yearId);
    $statement->execute();
    $result = $statement->get_result();
    while ($row = $result->fetch_assoc()) {
        $periodSchedules[] = $row;
    }
    $statement->close();
    return [
        'years' => $years,
        'groups' => $groups,
        'instructors' => $instructors,
        'classrooms' => $classrooms,
        'period_schedules' => $periodSchedules,
    ];
}

function timetable_management_list_group_assignments(
    mysqli $db,
    int $institutionId,
    int $yearId,
    int $groupId
): array {
    $statement = $db->prepare(
        'SELECT tga.id, tga.status, i.full_name, i.email, i.user_id,
                i.status AS instructor_status, u.status AS account_status
         FROM teacher_group_assignments tga
         JOIN instructors i ON i.institution_id = tga.institution_id AND i.id = tga.instructor_id
         LEFT JOIN users u ON u.institution_id = i.institution_id AND u.id = i.user_id
         WHERE tga.institution_id = ? AND tga.academic_year_id = ? AND tga.academic_group_id = ?
         ORDER BY i.full_name'
    );
    $statement->bind_param('iii', $institutionId, $yearId, $groupId);
    $statement->execute();
    $result = $statement->get_result();
    $teachers = [];
    while ($row = $result->fetch_assoc()) {
        $teachers[] = $row;
    }
    $statement->close();

    $group = timetable_management_group($db, $institutionId, $yearId, $groupId);
    $sectionId = $group['section_id'] === null ? null : (int) $group['section_id'];
    $statement = $db->prepare(
        'SELECT cs.subject_id, s.name, s.code
         FROM class_subjects cs
         JOIN subjects s ON s.institution_id = cs.institution_id AND s.id = cs.subject_id
         WHERE cs.institution_id = ? AND cs.academic_year_id = ? AND cs.class_id = ?
           AND cs.status = \'active\' AND s.status = \'active\'
         ORDER BY s.name'
    );
    $classId = (int) $group['class_id'];
    $statement->bind_param('iii', $institutionId, $yearId, $classId);
    $statement->execute();
    $result = $statement->get_result();
    $subjects = [];
    while ($row = $result->fetch_assoc()) {
        $subjectId = (int) $row['subject_id'];
        if (timetable_management_subject_is_available(
            $db,
            $institutionId,
            $yearId,
            $classId,
            $sectionId,
            $subjectId
        )) {
            $subjects[] = $row;
        }
    }
    $statement->close();

    $statement = $db->prepare(
        "SELECT tsa.id, tsa.teacher_group_assignment_id, tsa.subject_id, tsa.status,
                i.full_name, s.name AS subject_name
         FROM teacher_subject_assignments tsa
         JOIN teacher_group_assignments tga
           ON tga.institution_id = tsa.institution_id AND tga.academic_year_id = tsa.academic_year_id
          AND tga.academic_group_id = tsa.academic_group_id AND tga.id = tsa.teacher_group_assignment_id
         JOIN instructors i ON i.institution_id = tga.institution_id AND i.id = tga.instructor_id
         JOIN subjects s ON s.institution_id = tsa.institution_id AND s.id = tsa.subject_id
         WHERE tsa.institution_id = ? AND tsa.academic_year_id = ? AND tsa.academic_group_id = ?
         ORDER BY i.full_name, s.name"
    );
    $statement->bind_param('iii', $institutionId, $yearId, $groupId);
    $statement->execute();
    $result = $statement->get_result();
    $subjectAssignments = [];
    while ($row = $result->fetch_assoc()) {
        $subjectAssignments[] = $row;
    }
    $statement->close();
    return ['teachers' => $teachers, 'subjects' => $subjects, 'subject_assignments' => $subjectAssignments];
}

function timetable_management_list_periods(
    mysqli $db,
    int $institutionId,
    int $yearId,
    int $scheduleId
): array {
    $statement = $db->prepare(
        'SELECT id, day_of_week, period_number, label, start_time, end_time, period_type
         FROM timetable_periods
         WHERE institution_id = ? AND academic_year_id = ? AND period_schedule_id = ?
         ORDER BY FIELD(day_of_week,\'monday\',\'tuesday\',\'wednesday\',\'thursday\',\'friday\',\'saturday\',\'sunday\'),
                  start_time, period_number'
    );
    $statement->bind_param('iii', $institutionId, $yearId, $scheduleId);
    $statement->execute();
    $result = $statement->get_result();
    $periods = [];
    while ($row = $result->fetch_assoc()) {
        $periods[] = $row;
    }
    $statement->close();
    return $periods;
}

function timetable_management_create_period_schedule(
    mysqli $db,
    int $institutionId,
    int $yearId,
    int $actorUserId
): int {
    $db->begin_transaction();
    try {
        academic_management_require_open_year($db, $institutionId, $yearId);
        $statement = $db->prepare(
            'SELECT COALESCE(MAX(version_number), 0) + 1 AS next_version
             FROM timetable_period_schedules WHERE institution_id = ? AND academic_year_id = ?'
        );
        $statement->bind_param('ii', $institutionId, $yearId);
        $statement->execute();
        $version = (int) $statement->get_result()->fetch_assoc()['next_version'];
        $statement->close();
        $creatorId = timetable_management_actor_id($actorUserId);
        $statement = $db->prepare(
            'INSERT INTO timetable_period_schedules
                (institution_id, academic_year_id, version_number, created_by)
             VALUES (?, ?, ?, ?)'
        );
        $statement->bind_param('iiii', $institutionId, $yearId, $version, $creatorId);
        $statement->execute();
        $scheduleId = (int) $statement->insert_id;
        $statement->close();
        timetable_management_audit($db, $institutionId, 'timetable.period_schedule_created',
            'timetable_period_schedule', $scheduleId, ['academic_year_id' => $yearId, 'version_number' => $version]);
        $db->commit();
        return $scheduleId;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function timetable_management_add_period(
    mysqli $db,
    int $institutionId,
    int $yearId,
    int $scheduleId,
    string $day,
    int $periodNumber,
    string $label,
    string $startTime,
    string $endTime,
    string $periodType
): int {
    $periodIds = timetable_management_add_periods_for_days(
        $db,
        $institutionId,
        $yearId,
        $scheduleId,
        [$day],
        $periodNumber,
        $label,
        $startTime,
        $endTime,
        $periodType
    );
    return $periodIds[0];
}

function timetable_management_add_periods_for_days(
    mysqli $db,
    int $institutionId,
    int $yearId,
    int $scheduleId,
    array $selectedDays,
    int $periodNumber,
    string $label,
    string $startTime,
    string $endTime,
    string $periodType
): array {
    $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    $validSelectedDays = array_filter(
        $selectedDays,
        static fn(mixed $day): bool => is_string($day) && in_array($day, $days, true)
    );
    if ($selectedDays === []
        || count($validSelectedDays) !== count($selectedDays)
        || count(array_unique($validSelectedDays)) !== count($validSelectedDays)
        || $periodNumber < 1
        || !in_array($periodType, ['teaching', 'break'], true)
        || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $startTime) !== 1
        || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $endTime) !== 1
        || $startTime >= $endTime) {
        throw new InvalidArgumentException('Choose valid weekdays and enter a period number, type, and increasing time range.');
    }
    $label = trim($label);
    if ($label === '' || strlen($label) > 80) {
        throw new InvalidArgumentException('Enter a period label of 1 to 80 characters.');
    }
    $db->begin_transaction();
    try {
        academic_management_require_open_year($db, $institutionId, $yearId);
        $statement = $db->prepare(
            "SELECT id FROM timetable_period_schedules
             WHERE institution_id = ? AND academic_year_id = ? AND id = ? AND status = 'draft'
             FOR UPDATE"
        );
        $statement->bind_param('iii', $institutionId, $yearId, $scheduleId);
        $statement->execute();
        $found = $statement->get_result()->num_rows === 1;
        $statement->close();
        if (!$found) {
            throw new DomainException('Periods can only be added to a draft schedule.');
        }
        $periodIds = [];
        foreach ($selectedDays as $day) {
            $statement = $db->prepare(
                'SELECT 1 FROM timetable_periods
                 WHERE institution_id = ? AND academic_year_id = ? AND period_schedule_id = ?
                   AND day_of_week = ? AND start_time < ? AND end_time > ? LIMIT 1'
            );
            $statement->bind_param('iiisss', $institutionId, $yearId, $scheduleId, $day, $endTime, $startTime);
            $statement->execute();
            $overlap = $statement->get_result()->num_rows > 0;
            $statement->close();
            if ($overlap) {
                throw new DomainException('This period overlaps another period on ' . ucfirst($day) . '.');
            }
            $statement = $db->prepare(
                'INSERT INTO timetable_periods
                    (institution_id, academic_year_id, period_schedule_id, day_of_week,
                     period_number, label, start_time, end_time, period_type)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->bind_param(
                'iiisissss',
                $institutionId,
                $yearId,
                $scheduleId,
                $day,
                $periodNumber,
                $label,
                $startTime,
                $endTime,
                $periodType
            );
            $statement->execute();
            $periodId = (int) $statement->insert_id;
            $statement->close();
            timetable_management_audit($db, $institutionId, 'timetable.period_created',
                'timetable_period', $periodId, [
                    'academic_year_id' => $yearId,
                    'period_schedule_id' => $scheduleId,
                    'day_of_week' => $day,
                ]);
            $periodIds[] = $periodId;
        }
        $db->commit();
        return $periodIds;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function timetable_management_update_period(
    mysqli $db,
    int $institutionId,
    int $yearId,
    int $scheduleId,
    int $periodId,
    string $day,
    int $periodNumber,
    string $label,
    string $startTime,
    string $endTime,
    string $periodType
): void {
    $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    $label = trim($label);
    if (!in_array($day, $days, true) || $periodNumber < 1
        || !in_array($periodType, ['teaching', 'break'], true)
        || $label === '' || strlen($label) > 80
        || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $startTime) !== 1
        || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $endTime) !== 1
        || $startTime >= $endTime) {
        throw new InvalidArgumentException('Enter a valid day, period number, label, type, and time range.');
    }
    $db->begin_transaction();
    try {
        academic_management_require_open_year($db, $institutionId, $yearId);
        $statement = $db->prepare(
            "SELECT id FROM timetable_period_schedules
             WHERE institution_id = ? AND academic_year_id = ? AND id = ? AND status = 'draft'
             FOR UPDATE"
        );
        $statement->bind_param('iii', $institutionId, $yearId, $scheduleId);
        $statement->execute();
        $draftSchedule = $statement->get_result()->num_rows === 1;
        $statement->close();
        if (!$draftSchedule) {
            throw new DomainException('Only a draft period schedule can be edited.');
        }
        $statement = $db->prepare(
            'SELECT id FROM timetable_periods
             WHERE institution_id = ? AND academic_year_id = ? AND period_schedule_id = ? AND id = ?
             FOR UPDATE'
        );
        $statement->bind_param('iiii', $institutionId, $yearId, $scheduleId, $periodId);
        $statement->execute();
        $periodExists = $statement->get_result()->num_rows === 1;
        $statement->close();
        if (!$periodExists) {
            throw new DomainException('The period was not found in this draft schedule.');
        }
        $statement = $db->prepare(
            'SELECT 1 FROM timetable_periods
             WHERE institution_id = ? AND academic_year_id = ? AND period_schedule_id = ?
               AND id <> ? AND day_of_week = ? AND start_time < ? AND end_time > ? LIMIT 1'
        );
        $statement->bind_param(
            'iiiisss',
            $institutionId,
            $yearId,
            $scheduleId,
            $periodId,
            $day,
            $endTime,
            $startTime
        );
        $statement->execute();
        $overlap = $statement->get_result()->num_rows > 0;
        $statement->close();
        if ($overlap) {
            throw new DomainException('This period overlaps another period on the selected weekday.');
        }
        $statement = $db->prepare(
            'UPDATE timetable_periods
             SET day_of_week = ?, period_number = ?, label = ?, start_time = ?, end_time = ?, period_type = ?
             WHERE institution_id = ? AND academic_year_id = ? AND period_schedule_id = ? AND id = ?'
        );
        $statement->bind_param(
            'sissssiiii',
            $day,
            $periodNumber,
            $label,
            $startTime,
            $endTime,
            $periodType,
            $institutionId,
            $yearId,
            $scheduleId,
            $periodId
        );
        $statement->execute();
        $statement->close();
        timetable_management_audit($db, $institutionId, 'timetable.period_updated',
            'timetable_period', $periodId, ['period_schedule_id' => $scheduleId]);
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function timetable_management_delete_period(
    mysqli $db,
    int $institutionId,
    int $yearId,
    int $scheduleId,
    int $periodId
): void {
    $db->begin_transaction();
    try {
        academic_management_require_open_year($db, $institutionId, $yearId);
        $statement = $db->prepare(
            "DELETE p FROM timetable_periods p
             JOIN timetable_period_schedules s
               ON s.institution_id = p.institution_id AND s.academic_year_id = p.academic_year_id
              AND s.id = p.period_schedule_id AND s.status = 'draft'
             WHERE p.institution_id = ? AND p.academic_year_id = ?
               AND p.period_schedule_id = ? AND p.id = ?"
        );
        $statement->bind_param('iiii', $institutionId, $yearId, $scheduleId, $periodId);
        $statement->execute();
        $deleted = $statement->affected_rows;
        $statement->close();
        if ($deleted !== 1) {
            throw new DomainException('Only an unused period in a draft schedule can be deleted.');
        }
        timetable_management_audit($db, $institutionId, 'timetable.period_deleted',
            'timetable_period', $periodId, ['period_schedule_id' => $scheduleId]);
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function timetable_management_activate_period_schedule(
    mysqli $db,
    int $institutionId,
    int $yearId,
    int $scheduleId
): void {
    $db->begin_transaction();
    try {
        academic_management_require_open_year($db, $institutionId, $yearId);
        $statement = $db->prepare(
            "SELECT id FROM timetable_period_schedules
             WHERE institution_id = ? AND academic_year_id = ? AND id = ? AND status = 'draft'
             FOR UPDATE"
        );
        $statement->bind_param('iii', $institutionId, $yearId, $scheduleId);
        $statement->execute();
        $found = $statement->get_result()->num_rows === 1;
        $statement->close();
        if (!$found) {
            throw new DomainException('Choose a draft period schedule to activate.');
        }
        $statement = $db->prepare(
            "SELECT COUNT(*) AS period_count FROM timetable_periods
             WHERE institution_id = ? AND academic_year_id = ? AND period_schedule_id = ?
               AND period_type = 'teaching'"
        );
        $statement->bind_param('iii', $institutionId, $yearId, $scheduleId);
        $statement->execute();
        $periodCount = (int) $statement->get_result()->fetch_assoc()['period_count'];
        $statement->close();
        if ($periodCount < 1) {
            throw new DomainException('Add at least one teaching period before activating this schedule.');
        }
        $statement = $db->prepare(
            "UPDATE timetable_period_schedules SET status = 'retired'
             WHERE institution_id = ? AND academic_year_id = ? AND status = 'active'"
        );
        $statement->bind_param('ii', $institutionId, $yearId);
        $statement->execute();
        $statement->close();
        $statement = $db->prepare(
            "UPDATE timetable_period_schedules SET status = 'active'
             WHERE institution_id = ? AND academic_year_id = ? AND id = ? AND status = 'draft'"
        );
        $statement->bind_param('iii', $institutionId, $yearId, $scheduleId);
        $statement->execute();
        if ($statement->affected_rows !== 1) {
            throw new RuntimeException('The period schedule could not be activated.');
        }
        $statement->close();
        timetable_management_audit($db, $institutionId, 'timetable.period_schedule_activated',
            'timetable_period_schedule', $scheduleId, ['academic_year_id' => $yearId]);
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function timetable_management_create_timetable_draft(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    int $yearId,
    int $groupId
): int {
    $db->begin_transaction();
    try {
        academic_management_require_open_year($db, $institutionId, $yearId);
        $lock = $db->prepare(
            'SELECT id FROM academic_years WHERE institution_id = ? AND id = ? FOR UPDATE'
        );
        $lock->bind_param('ii', $institutionId, $yearId);
        $lock->execute();
        $lock->get_result()->fetch_assoc();
        $lock->close();
        $group = timetable_management_group($db, $institutionId, $yearId, $groupId);
        $classId = (int) $group['class_id'];
        $scopeMode = $group['group_type'] === 'section' ? 'sections' : 'class';
        $sectionStatement = $db->prepare(
            "SELECT COUNT(*) AS section_count FROM sections
             WHERE institution_id = ? AND academic_year_id = ? AND class_id = ? AND status = 'active'"
        );
        $sectionStatement->bind_param('iii', $institutionId, $yearId, $classId);
        $sectionStatement->execute();
        $sectionCount = (int) $sectionStatement->get_result()->fetch_assoc()['section_count'];
        $sectionStatement->close();
        if (($scopeMode === 'class' && $sectionCount > 0) || ($scopeMode === 'sections' && $sectionCount === 0)) {
            throw new DomainException('Use a whole-class timetable only when the class has no active sections.');
        }

        $statement = $db->prepare(
            "SELECT id FROM timetable_period_schedules
             WHERE institution_id = ? AND academic_year_id = ? AND status = 'active'
             LIMIT 1 FOR UPDATE"
        );
        $statement->bind_param('ii', $institutionId, $yearId);
        $statement->execute();
        $schedule = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($schedule)) {
            throw new DomainException('Activate a period schedule before creating a timetable.');
        }
        $periodScheduleId = (int) $schedule['id'];

        $creatorId = timetable_management_actor_id($actorUserId);
        $statement = $db->prepare(
            'INSERT INTO academic_timetables
                (institution_id, academic_year_id, class_id, scope_mode, created_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );
        $statement->bind_param('iiisi', $institutionId, $yearId, $classId, $scopeMode, $creatorId);
        $statement->execute();
        $timetableId = (int) $statement->insert_id;
        $statement->close();
        $statement = $db->prepare(
            'SELECT id, scope_mode, status FROM academic_timetables
             WHERE institution_id = ? AND academic_year_id = ? AND class_id = ? FOR UPDATE'
        );
        $statement->bind_param('iii', $institutionId, $yearId, $classId);
        $statement->execute();
        $header = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($header) || $header['status'] !== 'active' || $header['scope_mode'] !== $scopeMode) {
            throw new DomainException('This class already has a timetable in a different scheduling mode.');
        }
        $timetableId = (int) $header['id'];

        $statement = $db->prepare(
            'SELECT COALESCE(MAX(version_number), 0) + 1 AS next_version
             FROM timetable_versions WHERE institution_id = ? AND academic_timetable_id = ?
               AND academic_group_id = ?'
        );
        $statement->bind_param('iii', $institutionId, $timetableId, $groupId);
        $statement->execute();
        $versionNumber = (int) $statement->get_result()->fetch_assoc()['next_version'];
        $statement->close();
        $statement = $db->prepare(
            "INSERT INTO timetable_versions
                (institution_id, academic_year_id, class_id, academic_timetable_id,
                 academic_group_id, period_schedule_id, version_number, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $statement->bind_param(
            'iiiiiiii',
            $institutionId,
            $yearId,
            $classId,
            $timetableId,
            $groupId,
            $periodScheduleId,
            $versionNumber,
            $creatorId
        );
        $statement->execute();
        $versionId = (int) $statement->insert_id;
        $statement->close();
        timetable_management_audit($db, $institutionId, 'timetable.draft_created',
            'timetable_version', $versionId, [
                'academic_year_id' => $yearId,
                'academic_group_id' => $groupId,
                'version_number' => $versionNumber,
            ]);
        $db->commit();
        return $versionId;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function timetable_management_entry_conflicts(
    mysqli $db,
    int $institutionId,
    int $yearId,
    int $groupId,
    int $instructorId,
    ?int $classroomId,
    string $day,
    string $startTime,
    string $endTime
): array {
    $statement = $db->prepare(
        "SELECT 1
         FROM timetable_entries e
         JOIN timetable_versions v
           ON v.institution_id = e.institution_id AND v.academic_year_id = e.academic_year_id
          AND v.id = e.timetable_version_id AND v.status = 'published'
         JOIN timetable_periods p
           ON p.institution_id = e.institution_id AND p.academic_year_id = e.academic_year_id
          AND p.period_schedule_id = e.period_schedule_id AND p.id = e.period_id
         JOIN teacher_subject_assignments tsa
           ON tsa.institution_id = e.institution_id AND tsa.academic_year_id = e.academic_year_id
          AND tsa.academic_group_id = e.academic_group_id AND tsa.id = e.teacher_subject_assignment_id
         JOIN teacher_group_assignments tga
           ON tga.institution_id = tsa.institution_id AND tga.academic_year_id = tsa.academic_year_id
          AND tga.academic_group_id = tsa.academic_group_id AND tga.id = tsa.teacher_group_assignment_id
         WHERE e.institution_id = ? AND e.academic_year_id = ? AND v.academic_group_id <> ?
           AND tga.instructor_id = ? AND p.day_of_week = ?
           AND p.start_time < ? AND p.end_time > ?
         LIMIT 1"
    );
    $statement->bind_param('iiiisss', $institutionId, $yearId, $groupId, $instructorId, $day, $endTime, $startTime);
    $statement->execute();
    $teacherConflict = $statement->get_result()->num_rows > 0;
    $statement->close();
    $conflicts = [];
    if ($teacherConflict) {
        $conflicts[] = 'The teacher is already scheduled in an overlapping published class.';
    }
    if ($classroomId !== null) {
        $statement = $db->prepare(
            "SELECT 1
             FROM timetable_entries e
             JOIN timetable_versions v
               ON v.institution_id = e.institution_id AND v.academic_year_id = e.academic_year_id
              AND v.id = e.timetable_version_id AND v.status = 'published'
             JOIN timetable_periods p
               ON p.institution_id = e.institution_id AND p.academic_year_id = e.academic_year_id
              AND p.period_schedule_id = e.period_schedule_id AND p.id = e.period_id
             WHERE e.institution_id = ? AND e.academic_year_id = ? AND v.academic_group_id <> ?
               AND e.classroom_id = ? AND p.day_of_week = ?
               AND p.start_time < ? AND p.end_time > ?
             LIMIT 1"
        );
        $statement->bind_param('iiiisss', $institutionId, $yearId, $groupId, $classroomId, $day, $endTime, $startTime);
        $statement->execute();
        $roomConflict = $statement->get_result()->num_rows > 0;
        $statement->close();
        if ($roomConflict) {
            $conflicts[] = 'The classroom is already occupied during this time.';
        }
    }
    return $conflicts;
}

function timetable_management_add_entry(
    mysqli $db,
    int $institutionId,
    int $versionId,
    int $periodId,
    int $teacherSubjectAssignmentId,
    ?int $classroomId,
    ?string $notes = null
): int {
    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            'SELECT academic_year_id FROM timetable_versions
             WHERE institution_id = ? AND id = ?'
        );
        $statement->bind_param('ii', $institutionId, $versionId);
        $statement->execute();
        $version = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($version)) {
            throw new DomainException('The timetable version was not found.');
        }
        $yearId = (int) $version['academic_year_id'];
        academic_management_require_open_year($db, $institutionId, $yearId);
        $statement = $db->prepare(
            "SELECT v.class_id, v.academic_timetable_id, v.academic_group_id, v.period_schedule_id
             FROM timetable_versions v WHERE v.institution_id = ? AND v.id = ? AND v.status = 'draft'
             FOR UPDATE"
        );
        $statement->bind_param('ii', $institutionId, $versionId);
        $statement->execute();
        $version = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($version)) {
            throw new DomainException('Only a draft timetable can be edited.');
        }
        $groupId = (int) $version['academic_group_id'];
        $classId = (int) $version['class_id'];
        $scheduleId = (int) $version['period_schedule_id'];
        $statement = $db->prepare(
            "SELECT id, day_of_week, start_time, end_time, period_type
             FROM timetable_periods
             WHERE institution_id = ? AND academic_year_id = ? AND period_schedule_id = ?
               AND id = ?"
        );
        $statement->bind_param('iiii', $institutionId, $yearId, $scheduleId, $periodId);
        $statement->execute();
        $period = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($period) || $period['period_type'] !== 'teaching') {
            throw new DomainException('Choose a teaching period from this timetable schedule.');
        }
        $statement = $db->prepare(
            "SELECT tsa.class_id, tsa.subject_id, tga.instructor_id
             FROM teacher_subject_assignments tsa
             JOIN teacher_group_assignments tga
               ON tga.institution_id = tsa.institution_id AND tga.academic_year_id = tsa.academic_year_id
              AND tga.academic_group_id = tsa.academic_group_id AND tga.id = tsa.teacher_group_assignment_id
             JOIN instructors i ON i.institution_id = tga.institution_id AND i.id = tga.instructor_id
             LEFT JOIN users u ON u.institution_id = i.institution_id AND u.id = i.user_id
             WHERE tsa.institution_id = ? AND tsa.academic_year_id = ? AND tsa.academic_group_id = ?
               AND tsa.id = ? AND tsa.status = 'active' AND tga.status = 'active'
               AND i.status = 'active' AND (i.user_id IS NULL OR u.status = 'active')
             LIMIT 1"
        );
        $statement->bind_param('iiii', $institutionId, $yearId, $groupId, $teacherSubjectAssignmentId);
        $statement->execute();
        $assignment = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($assignment) || (int) $assignment['class_id'] !== $classId) {
            throw new DomainException('Choose an active teacher and subject assignment for this cohort.');
        }
        $group = timetable_management_group($db, $institutionId, $yearId, $groupId);
        $sectionId = $group['section_id'] === null ? null : (int) $group['section_id'];
        if (!timetable_management_subject_is_available(
            $db,
            $institutionId,
            $yearId,
            $classId,
            $sectionId,
            (int) $assignment['subject_id']
        )) {
            throw new DomainException('The subject is no longer mapped to this class or section.');
        }
        if ($classroomId !== null) {
            $statement = $db->prepare(
                "SELECT id FROM classrooms
                 WHERE institution_id = ? AND id = ? AND status = 'active' LIMIT 1"
            );
            $statement->bind_param('ii', $institutionId, $classroomId);
            $statement->execute();
            $roomExists = $statement->get_result()->num_rows === 1;
            $statement->close();
            if (!$roomExists) {
                throw new DomainException('Choose an active classroom in this institution.');
            }
        }
        $conflicts = timetable_management_entry_conflicts(
            $db,
            $institutionId,
            $yearId,
            $groupId,
            (int) $assignment['instructor_id'],
            $classroomId,
            (string) $period['day_of_week'],
            (string) $period['start_time'],
            (string) $period['end_time']
        );
        if ($conflicts !== []) {
            throw new DomainException(implode(' ', $conflicts));
        }
        $notes = trim((string) $notes);
        if ($notes !== '' && strlen($notes) > 500) {
            throw new InvalidArgumentException('Entry notes may not exceed 500 characters.');
        }
        $notesValue = $notes === '' ? null : $notes;
        $statement = $db->prepare(
            'INSERT INTO timetable_entries
                (institution_id, academic_year_id, class_id, academic_timetable_id, academic_group_id,
                 timetable_version_id, period_schedule_id, period_id, teacher_subject_assignment_id,
                 classroom_id, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $timetableId = (int) $version['academic_timetable_id'];
        $statement->bind_param(
            'iiiiiiiiiis',
            $institutionId,
            $yearId,
            $classId,
            $timetableId,
            $groupId,
            $versionId,
            $scheduleId,
            $periodId,
            $teacherSubjectAssignmentId,
            $classroomId,
            $notesValue
        );
        $statement->execute();
        $entryId = (int) $statement->insert_id;
        $statement->close();
        timetable_management_audit($db, $institutionId, 'timetable.entry_created',
            'timetable_entry', $entryId, [
                'academic_year_id' => $yearId,
                'academic_group_id' => $groupId,
                'timetable_version_id' => $versionId,
            ]);
        $db->commit();
        return $entryId;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function timetable_management_validate_version(
    mysqli $db,
    int $institutionId,
    int $versionId
): array {
            $statement = $db->prepare(
                'SELECT academic_year_id, academic_group_id, period_schedule_id, status
                 FROM timetable_versions WHERE institution_id = ? AND id = ? LIMIT 1'
            );
            $statement->bind_param('ii', $institutionId, $versionId);
            $statement->execute();
            $version = $statement->get_result()->fetch_assoc();
            $statement->close();
            if (!is_array($version)) {
                throw new DomainException('The timetable version was not found.');
            }
            if ($version['status'] !== 'draft') {
                throw new DomainException('Only a draft timetable can be validated or published.');
            }
            $yearId = (int) $version['academic_year_id'];
            $groupId = (int) $version['academic_group_id'];
            $scheduleId = (int) $version['period_schedule_id'];
            $errors = [];
            $statement = $db->prepare(
                'SELECT v.class_id, t.scope_mode, g.group_type, g.class_id AS group_class_id
                 FROM timetable_versions v
                 JOIN academic_timetables t
                   ON t.institution_id = v.institution_id AND t.academic_year_id = v.academic_year_id
                  AND t.class_id = v.class_id AND t.id = v.academic_timetable_id
                 JOIN academic_groups g
                   ON g.institution_id = v.institution_id AND g.academic_year_id = v.academic_year_id
                  AND g.id = v.academic_group_id
                 WHERE v.institution_id = ? AND v.id = ?'
            );
            $statement->bind_param('ii', $institutionId, $versionId);
            $statement->execute();
            $cohort = $statement->get_result()->fetch_assoc();
            $statement->close();
            if (!is_array($cohort) || (int) $cohort['class_id'] !== (int) $cohort['group_class_id']
                || ($cohort['scope_mode'] === 'class' && $cohort['group_type'] !== 'class')
                || ($cohort['scope_mode'] === 'sections' && $cohort['group_type'] !== 'section')) {
                $errors[] = 'The timetable cohort no longer matches its class or section scope.';
            } elseif (is_array($cohort)) {
                $statement = $db->prepare(
                    "SELECT COUNT(*) AS section_count FROM sections
                     WHERE institution_id = ? AND academic_year_id = ? AND class_id = ? AND status = 'active'"
                );
                $classId = (int) $cohort['class_id'];
                $statement->bind_param('iii', $institutionId, $yearId, $classId);
                $statement->execute();
                $sectionCount = (int) $statement->get_result()->fetch_assoc()['section_count'];
                $statement->close();
                if (($cohort['scope_mode'] === 'class' && $sectionCount > 0)
                    || ($cohort['scope_mode'] === 'sections' && $sectionCount === 0)) {
                    $errors[] = 'The class section structure changed after this timetable was created.';
                }
            }

            $statement = $db->prepare(
                "SELECT status FROM timetable_period_schedules
                 WHERE institution_id = ? AND academic_year_id = ? AND id = ?"
            );
            $statement->bind_param('iii', $institutionId, $yearId, $scheduleId);
            $statement->execute();
            $schedule = $statement->get_result()->fetch_assoc();
            $statement->close();
            if (!is_array($schedule) || $schedule['status'] === 'draft') {
                $errors[] = 'The draft does not use a finalized period schedule.';
            }

            $statement = $db->prepare(
                "SELECT COUNT(*) AS entry_count
                 FROM timetable_entries WHERE institution_id = ? AND academic_year_id = ?
                   AND timetable_version_id = ?"
            );
            $statement->bind_param('iii', $institutionId, $yearId, $versionId);
            $statement->execute();
            $entryCount = (int) $statement->get_result()->fetch_assoc()['entry_count'];
            $statement->close();
            if ($entryCount < 1) {
                $errors[] = 'Add at least one teaching period before publishing.';
            }

            $statement = $db->prepare(
                "SELECT p.period_type, tsa.status AS subject_assignment_status,
                        tga.status AS group_assignment_status, i.status AS instructor_status,
                        i.user_id, u.status AS account_status, e.classroom_id, r.status AS classroom_status
                 FROM timetable_entries e
                 JOIN timetable_periods p
                   ON p.institution_id = e.institution_id AND p.academic_year_id = e.academic_year_id
                  AND p.period_schedule_id = e.period_schedule_id AND p.id = e.period_id
                 LEFT JOIN teacher_subject_assignments tsa
                   ON tsa.institution_id = e.institution_id AND tsa.academic_year_id = e.academic_year_id
                  AND tsa.academic_group_id = e.academic_group_id AND tsa.id = e.teacher_subject_assignment_id
                 LEFT JOIN teacher_group_assignments tga
                   ON tga.institution_id = tsa.institution_id AND tga.academic_year_id = tsa.academic_year_id
                  AND tga.academic_group_id = tsa.academic_group_id AND tga.id = tsa.teacher_group_assignment_id
                 LEFT JOIN instructors i ON i.institution_id = tga.institution_id AND i.id = tga.instructor_id
                 LEFT JOIN users u ON u.institution_id = i.institution_id AND u.id = i.user_id
                 LEFT JOIN classrooms r ON r.institution_id = e.institution_id AND r.id = e.classroom_id
                 WHERE e.institution_id = ? AND e.academic_year_id = ? AND e.timetable_version_id = ?
                 FOR UPDATE"
            );
            $statement->bind_param('iii', $institutionId, $yearId, $versionId);
            $statement->execute();
            $result = $statement->get_result();
            $invalidAssignment = false;
            while ($row = $result->fetch_assoc()) {
                if ($row['period_type'] !== 'teaching'
                    || $row['subject_assignment_status'] !== 'active'
                    || $row['group_assignment_status'] !== 'active'
                    || $row['instructor_status'] !== 'active'
                    || ($row['user_id'] !== null && $row['account_status'] !== 'active')
                    || ($row['classroom_id'] !== null && $row['classroom_status'] !== 'active')) {
                    $invalidAssignment = true;
                }
            }
            $statement->close();
            if ($invalidAssignment) {
                $errors[] = 'One or more periods, teacher assignments, instructors, or classrooms are no longer active.';
            }

            $statement = $db->prepare(
                "SELECT 1
                 FROM timetable_entries a
                 JOIN timetable_entries b
                   ON b.institution_id = a.institution_id AND b.timetable_version_id = a.timetable_version_id
                  AND b.id > a.id
                 JOIN timetable_periods pa
                   ON pa.institution_id = a.institution_id AND pa.academic_year_id = a.academic_year_id
                  AND pa.period_schedule_id = a.period_schedule_id AND pa.id = a.period_id
                 JOIN timetable_periods pb
                   ON pb.institution_id = b.institution_id AND pb.academic_year_id = b.academic_year_id
                  AND pb.period_schedule_id = b.period_schedule_id AND pb.id = b.period_id
                 JOIN teacher_subject_assignments tsa
                   ON tsa.institution_id = a.institution_id AND tsa.academic_year_id = a.academic_year_id
                  AND tsa.academic_group_id = a.academic_group_id AND tsa.id = a.teacher_subject_assignment_id
                 JOIN teacher_group_assignments tga
                   ON tga.institution_id = tsa.institution_id AND tga.academic_year_id = tsa.academic_year_id
                  AND tga.academic_group_id = tsa.academic_group_id AND tga.id = tsa.teacher_group_assignment_id
                 JOIN teacher_subject_assignments tsb
                   ON tsb.institution_id = b.institution_id AND tsb.academic_year_id = b.academic_year_id
                  AND tsb.academic_group_id = b.academic_group_id AND tsb.id = b.teacher_subject_assignment_id
                 JOIN teacher_group_assignments tgb
                   ON tgb.institution_id = tsb.institution_id AND tgb.academic_year_id = tsb.academic_year_id
                  AND tgb.academic_group_id = tsb.academic_group_id AND tgb.id = tsb.teacher_group_assignment_id
                 WHERE a.institution_id = ? AND a.timetable_version_id = ?
                   AND pa.day_of_week = pb.day_of_week AND pa.start_time < pb.end_time AND pa.end_time > pb.start_time
                   AND (tga.instructor_id = tgb.instructor_id
                     OR (a.classroom_id IS NOT NULL AND a.classroom_id = b.classroom_id))
                 LIMIT 1"
            );
            $statement->bind_param('ii', $institutionId, $versionId);
            $statement->execute();
            $internalConflict = $statement->get_result()->num_rows > 0;
            $statement->close();
            if ($internalConflict) {
                $errors[] = 'Two entries in this timetable overlap for a teacher or classroom.';
            }

            $statement = $db->prepare(
                "SELECT e.period_id, e.classroom_id, tsa.subject_id, tga.instructor_id,
                        p.day_of_week, p.start_time, p.end_time
                 FROM timetable_entries e
                 JOIN timetable_periods p
                   ON p.institution_id = e.institution_id AND p.academic_year_id = e.academic_year_id
                  AND p.period_schedule_id = e.period_schedule_id AND p.id = e.period_id
                 JOIN teacher_subject_assignments tsa
                   ON tsa.institution_id = e.institution_id AND tsa.academic_year_id = e.academic_year_id
                  AND tsa.academic_group_id = e.academic_group_id AND tsa.id = e.teacher_subject_assignment_id
                 JOIN teacher_group_assignments tga
                   ON tga.institution_id = tsa.institution_id AND tga.academic_year_id = tsa.academic_year_id
                  AND tga.academic_group_id = tsa.academic_group_id AND tga.id = tsa.teacher_group_assignment_id
                 WHERE e.institution_id = ? AND e.academic_year_id = ? AND e.timetable_version_id = ?"
            );
            $statement->bind_param('iii', $institutionId, $yearId, $versionId);
            $statement->execute();
            $result = $statement->get_result();
            $group = timetable_management_group($db, $institutionId, $yearId, $groupId);
            $classId = (int) $group['class_id'];
            $sectionId = $group['section_id'] === null ? null : (int) $group['section_id'];
            while ($entry = $result->fetch_assoc()) {
                if (!timetable_management_subject_is_available(
                    $db,
                    $institutionId,
                    $yearId,
                    $classId,
                    $sectionId,
                    (int) $entry['subject_id']
                )) {
                    $errors[] = 'A subject is no longer mapped to this class or section.';
                }
                $conflicts = timetable_management_entry_conflicts(
                    $db,
                    $institutionId,
                    $yearId,
                    $groupId,
                    (int) $entry['instructor_id'],
                    $entry['classroom_id'] === null ? null : (int) $entry['classroom_id'],
                    (string) $entry['day_of_week'],
                    (string) $entry['start_time'],
                    (string) $entry['end_time']
                );
                foreach ($conflicts as $conflict) {
                    $errors[] = $conflict;
                }
            }
            $statement->close();
            return array_values(array_unique($errors));
        }

        function timetable_management_validate_draft(mysqli $db, int $institutionId, int $versionId): array
        {
            $db->begin_transaction();
            try {
                $statement = $db->prepare(
                    'SELECT academic_year_id FROM timetable_versions
                     WHERE institution_id = ? AND id = ?'
                );
                $statement->bind_param('ii', $institutionId, $versionId);
                $statement->execute();
                $version = $statement->get_result()->fetch_assoc();
                $statement->close();
                if (!is_array($version)) {
                    throw new DomainException('The timetable version was not found.');
                }
                $yearId = (int) $version['academic_year_id'];
                academic_management_require_open_year($db, $institutionId, $yearId);
                $statement = $db->prepare(
                    'SELECT id FROM timetable_versions
                     WHERE institution_id = ? AND id = ? AND status = \'draft\' FOR UPDATE'
                );
                $statement->bind_param('ii', $institutionId, $versionId);
                $statement->execute();
                $draftExists = $statement->get_result()->num_rows === 1;
                $statement->close();
                if (!$draftExists) {
                    throw new DomainException('Only a draft timetable can be validated.');
                }
                $errors = timetable_management_validate_version($db, $institutionId, $versionId);
                $json = json_encode($errors, JSON_THROW_ON_ERROR);
                $statement = $db->prepare(
                    'UPDATE timetable_versions SET validation_errors = ?, validated_at = CURRENT_TIMESTAMP
                     WHERE institution_id = ? AND id = ? AND status = \'draft\''
                );
                $statement->bind_param('sii', $json, $institutionId, $versionId);
                $statement->execute();
                $statement->close();
                timetable_management_audit($db, $institutionId, 'timetable.validated',
                    'timetable_version', $versionId, ['error_count' => count($errors)]);
                $db->commit();
                return $errors;
            } catch (Throwable $exception) {
                $db->rollback();
                throw $exception;
            }
        }

        function timetable_management_publish_draft(
            mysqli $db,
            int $institutionId,
            int $actorUserId,
            int $versionId
        ): array {
            $db->begin_transaction();
            try {
                $statement = $db->prepare(
                    'SELECT academic_year_id, academic_timetable_id, academic_group_id
                     FROM timetable_versions WHERE institution_id = ? AND id = ?'
                );
                $statement->bind_param('ii', $institutionId, $versionId);
                $statement->execute();
                $version = $statement->get_result()->fetch_assoc();
                $statement->close();
                if (!is_array($version)) {
                    throw new DomainException('The timetable version was not found.');
                }
                $yearId = (int) $version['academic_year_id'];
                academic_management_require_open_year($db, $institutionId, $yearId);
                $statement = $db->prepare(
                    'SELECT academic_timetable_id, academic_group_id FROM timetable_versions
                     WHERE institution_id = ? AND id = ? AND status = \'draft\' FOR UPDATE'
                );
                $statement->bind_param('ii', $institutionId, $versionId);
                $statement->execute();
                $version = $statement->get_result()->fetch_assoc();
                $statement->close();
                if (!is_array($version)) {
                    throw new DomainException('Only a draft timetable can be published.');
                }
                $errors = timetable_management_validate_version($db, $institutionId, $versionId);
                $json = json_encode($errors, JSON_THROW_ON_ERROR);
                $statement = $db->prepare(
                    'UPDATE timetable_versions SET validation_errors = ?, validated_at = CURRENT_TIMESTAMP
                     WHERE institution_id = ? AND id = ? AND status = \'draft\''
                );
                $statement->bind_param('sii', $json, $institutionId, $versionId);
                $statement->execute();
                $statement->close();
                if ($errors !== []) {
                    $db->commit();
                    return $errors;
                }
                $statement = $db->prepare(
                    "UPDATE timetable_versions
                     SET status = 'superseded'
                     WHERE institution_id = ? AND academic_timetable_id = ? AND academic_group_id = ?
                       AND status = 'published'"
                );
                $timetableId = (int) $version['academic_timetable_id'];
                $groupId = (int) $version['academic_group_id'];
                $statement->bind_param('iii', $institutionId, $timetableId, $groupId);
                $statement->execute();
                $statement->close();
                $publisherId = timetable_management_actor_id($actorUserId);
                $statement = $db->prepare(
                    "UPDATE timetable_versions
                     SET status = 'published', published_by = ?, published_at = CURRENT_TIMESTAMP,
                         validation_errors = JSON_ARRAY()
                     WHERE institution_id = ? AND id = ? AND status = 'draft'"
                );
                $statement->bind_param('iii', $publisherId, $institutionId, $versionId);
                $statement->execute();
                if ($statement->affected_rows !== 1) {
                    throw new RuntimeException('The timetable draft could not be published.');
                }
                $statement->close();
                timetable_management_audit($db, $institutionId, 'timetable.published',
                    'timetable_version', $versionId, [
                        'academic_year_id' => $yearId,
                        'academic_group_id' => $groupId,
                    ]);
                $db->commit();
                return [];
            } catch (Throwable $exception) {
                $db->rollback();
                throw $exception;
            }
        }

        function timetable_management_clone_published(
            mysqli $db,
            int $institutionId,
            int $actorUserId,
            int $sourceVersionId
        ): int {
            $db->begin_transaction();
            try {
                $statement = $db->prepare(
                    "SELECT academic_year_id, class_id, academic_timetable_id, academic_group_id, period_schedule_id
                     FROM timetable_versions
                     WHERE institution_id = ? AND id = ? AND status = 'published'"
                );
                $statement->bind_param('ii', $institutionId, $sourceVersionId);
                $statement->execute();
                $source = $statement->get_result()->fetch_assoc();
                $statement->close();
                if (!is_array($source)) {
                    throw new DomainException('Only a currently published timetable can be cloned.');
                }
                $yearId = (int) $source['academic_year_id'];
                academic_management_require_open_year($db, $institutionId, $yearId);
                $statement = $db->prepare(
                    "SELECT academic_year_id, class_id, academic_timetable_id, academic_group_id, period_schedule_id
                     FROM timetable_versions
                     WHERE institution_id = ? AND id = ? AND status = 'published' FOR UPDATE"
                );
                $statement->bind_param('ii', $institutionId, $sourceVersionId);
                $statement->execute();
                $source = $statement->get_result()->fetch_assoc();
                $statement->close();
                if (!is_array($source)) {
                    throw new DomainException('The published timetable changed before it could be cloned.');
                }
                $groupId = (int) $source['academic_group_id'];
                $statement = $db->prepare(
                    'SELECT COALESCE(MAX(version_number), 0) + 1 AS next_version
                     FROM timetable_versions WHERE institution_id = ? AND academic_timetable_id = ?
                       AND academic_group_id = ?'
                );
                $timetableId = (int) $source['academic_timetable_id'];
                $statement->bind_param('iii', $institutionId, $timetableId, $groupId);
                $statement->execute();
                $versionNumber = (int) $statement->get_result()->fetch_assoc()['next_version'];
                $statement->close();
                $creatorId = timetable_management_actor_id($actorUserId);
                $statement = $db->prepare(
                    "INSERT INTO timetable_versions
                        (institution_id, academic_year_id, class_id, academic_timetable_id, academic_group_id,
                         period_schedule_id, version_number, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $classId = (int) $source['class_id'];
                $periodScheduleId = (int) $source['period_schedule_id'];
                $statement->bind_param(
                    'iiiiiiii',
                    $institutionId,
                    $yearId,
                    $classId,
                    $timetableId,
                    $groupId,
                    $periodScheduleId,
                    $versionNumber,
                    $creatorId
                );
                $statement->execute();
                $newVersionId = (int) $statement->insert_id;
                $statement->close();
                $statement = $db->prepare(
                    'INSERT INTO timetable_entries
                        (institution_id, academic_year_id, class_id, academic_timetable_id, academic_group_id,
                         timetable_version_id, period_schedule_id, period_id, teacher_subject_assignment_id,
                         classroom_id, notes)
                     SELECT institution_id, academic_year_id, class_id, academic_timetable_id, academic_group_id,
                            ?, period_schedule_id, period_id, teacher_subject_assignment_id, classroom_id, notes
                     FROM timetable_entries
                     WHERE institution_id = ? AND academic_year_id = ? AND timetable_version_id = ?'
                );
                $statement->bind_param('iiii', $newVersionId, $institutionId, $yearId, $sourceVersionId);
                $statement->execute();
                $statement->close();
                timetable_management_audit($db, $institutionId, 'timetable.draft_cloned',
                    'timetable_version', $newVersionId, [
                        'source_version_id' => $sourceVersionId,
                        'academic_group_id' => $groupId,
                    ]);
                $db->commit();
                return $newVersionId;
            } catch (Throwable $exception) {
                $db->rollback();
                throw $exception;
            }
        }

        function timetable_management_delete_draft(mysqli $db, int $institutionId, int $versionId): void
        {
            $db->begin_transaction();
            try {
                $statement = $db->prepare(
                    "DELETE FROM timetable_versions
                     WHERE institution_id = ? AND id = ? AND status = 'draft'"
                );
                $statement->bind_param('ii', $institutionId, $versionId);
                $statement->execute();
                $deleted = $statement->affected_rows;
                $statement->close();
                if ($deleted !== 1) {
                    throw new DomainException('Only an existing draft timetable can be deleted.');
                }
                timetable_management_audit($db, $institutionId, 'timetable.draft_deleted',
                    'timetable_version', $versionId, []);
                $db->commit();
            } catch (Throwable $exception) {
                $db->rollback();
                throw $exception;
            }
        }

        function timetable_management_delete_entry(
            mysqli $db,
            int $institutionId,
            int $versionId,
            int $entryId
        ): void {
            $db->begin_transaction();
            try {
                $statement = $db->prepare(
                    "DELETE e FROM timetable_entries e
                     JOIN timetable_versions v
                       ON v.institution_id = e.institution_id AND v.academic_year_id = e.academic_year_id
                      AND v.id = e.timetable_version_id AND v.status = 'draft'
                     WHERE e.institution_id = ? AND e.timetable_version_id = ? AND e.id = ?"
                );
                $statement->bind_param('iii', $institutionId, $versionId, $entryId);
                $statement->execute();
                $deleted = $statement->affected_rows;
                $statement->close();
                if ($deleted !== 1) {
                    throw new DomainException('The entry was not found in an editable draft.');
                }
                timetable_management_audit($db, $institutionId, 'timetable.entry_deleted',
                    'timetable_entry', $entryId, ['timetable_version_id' => $versionId]);
                $db->commit();
            } catch (Throwable $exception) {
                $db->rollback();
                throw $exception;
            }
        }

        function timetable_management_get_version_entries(
            mysqli $db,
            int $institutionId,
            int $versionId
        ): array {
            $statement = $db->prepare(
                'SELECT v.academic_year_id, v.period_schedule_id
                 FROM timetable_versions v WHERE v.institution_id = ? AND v.id = ?'
            );
            $statement->bind_param('ii', $institutionId, $versionId);
            $statement->execute();
            $version = $statement->get_result()->fetch_assoc();
            $statement->close();
            if (!is_array($version)) {
                return [];
            }
            $statement = $db->prepare(
                "SELECT e.id, e.period_id, e.teacher_subject_assignment_id, e.classroom_id, e.notes,
                        p.day_of_week, p.period_number, p.label AS period_label, p.start_time, p.end_time,
                        s.name AS subject_name, i.full_name AS teacher_name,
                        r.room_code, r.name AS room_name
                 FROM timetable_entries e
                 JOIN timetable_periods p
                   ON p.institution_id = e.institution_id AND p.academic_year_id = e.academic_year_id
                  AND p.period_schedule_id = e.period_schedule_id AND p.id = e.period_id
                 JOIN teacher_subject_assignments tsa
                   ON tsa.institution_id = e.institution_id AND tsa.academic_year_id = e.academic_year_id
                  AND tsa.academic_group_id = e.academic_group_id AND tsa.id = e.teacher_subject_assignment_id
                 JOIN teacher_group_assignments tga
                   ON tga.institution_id = tsa.institution_id AND tga.academic_year_id = tsa.academic_year_id
                  AND tga.academic_group_id = tsa.academic_group_id AND tga.id = tsa.teacher_group_assignment_id
                 JOIN instructors i ON i.institution_id = tga.institution_id AND i.id = tga.instructor_id
                 JOIN subjects s ON s.institution_id = tsa.institution_id AND s.id = tsa.subject_id
                 LEFT JOIN classrooms r ON r.institution_id = e.institution_id AND r.id = e.classroom_id
                 WHERE e.institution_id = ? AND e.academic_year_id = ? AND e.timetable_version_id = ?
                 ORDER BY FIELD(p.day_of_week,'monday','tuesday','wednesday','thursday','friday','saturday','sunday'),
                          p.start_time, p.period_number"
            );
            $yearId = (int) $version['academic_year_id'];
            $statement->bind_param('iii', $institutionId, $yearId, $versionId);
            $statement->execute();
            $result = $statement->get_result();
            $entries = [];
            while ($row = $result->fetch_assoc()) {
                $entries[] = $row;
            }
            $statement->close();
            return $entries;
        }

        function timetable_management_get_builder_data(
            mysqli $db,
            int $institutionId,
            int $yearId,
            int $groupId
        ): array {
            $group = timetable_management_group($db, $institutionId, $yearId, $groupId);
            $classId = (int) $group['class_id'];
            $statement = $db->prepare(
                'SELECT id FROM academic_timetables
                 WHERE institution_id = ? AND academic_year_id = ? AND class_id = ? AND status = \'active\''
            );
            $statement->bind_param('iii', $institutionId, $yearId, $classId);
            $statement->execute();
            $header = $statement->get_result()->fetch_assoc();
            $statement->close();
            $timetableId = is_array($header) ? (int) $header['id'] : null;
            $versions = [];
            if ($timetableId !== null) {
                $statement = $db->prepare(
                    'SELECT id, version_number, status, created_at, published_at, validated_at
                     FROM timetable_versions
                     WHERE institution_id = ? AND academic_year_id = ? AND academic_timetable_id = ?
                       AND academic_group_id = ?
                     ORDER BY version_number DESC'
                );
                $statement->bind_param('iiii', $institutionId, $yearId, $timetableId, $groupId);
                $statement->execute();
                $result = $statement->get_result();
                while ($row = $result->fetch_assoc()) {
                    $versions[] = $row;
                }
                $statement->close();
            }
            $publishedVersionId = null;
            foreach ($versions as $version) {
                if ($version['status'] === 'published') {
                    $publishedVersionId = (int) $version['id'];
                    break;
                }
            }
            return [
                'group' => $group,
                'timetable_id' => $timetableId,
                'versions' => $versions,
                'published_version_id' => $publishedVersionId,
            ];
        }

        function timetable_management_get_published_group_entries(
            mysqli $db,
            int $institutionId,
            int $yearId,
            int $groupId
        ): array {
            $statement = $db->prepare(
                "SELECT id FROM timetable_versions
                 WHERE institution_id = ? AND academic_year_id = ? AND academic_group_id = ?
                   AND status = 'published' LIMIT 1"
            );
            $statement->bind_param('iii', $institutionId, $yearId, $groupId);
            $statement->execute();
            $version = $statement->get_result()->fetch_assoc();
            $statement->close();
            if (!is_array($version)) {
                return [];
            }
            return timetable_management_get_version_entries($db, $institutionId, (int) $version['id']);
        }

        function timetable_management_teacher_timetable(
            mysqli $db,
            int $institutionId,
            int $userId,
            ?int $yearId = null
        ): array {
            if ($yearId === null || $yearId < 1) {
                $statement = $db->prepare(
                    'SELECT active_academic_year_id FROM institutions WHERE id = ?'
                );
                $statement->bind_param('i', $institutionId);
                $statement->execute();
                $row = $statement->get_result()->fetch_assoc();
                $statement->close();
                $yearId = is_array($row) && $row['active_academic_year_id'] !== null
                    ? (int) $row['active_academic_year_id']
                    : 0;
            }
            $statement = $db->prepare(
                "SELECT i.id, i.full_name FROM instructors i
                 JOIN users u ON u.institution_id = i.institution_id AND u.id = i.user_id
                 WHERE i.institution_id = ? AND i.user_id = ? AND i.status = 'active'
                   AND u.status = 'active' AND u.user_type IN ('staff','admin','super_admin')
                 LIMIT 1"
            );
            $statement->bind_param('ii', $institutionId, $userId);
            $statement->execute();
            $teacher = $statement->get_result()->fetch_assoc();
            $statement->close();
            if (!is_array($teacher)) {
                throw new DomainException('No active instructor profile is linked to this account.');
            }
            $instructorId = (int) $teacher['id'];
            $statement = $db->prepare(
                "SELECT p.day_of_week, p.period_number, p.label AS period_label,
                        p.start_time, p.end_time, s.name AS subject_name,
                        c.name AS class_name, sec.name AS section_name,
                        r.room_code, r.name AS room_name
                 FROM timetable_entries e
                 JOIN timetable_versions v
                   ON v.institution_id = e.institution_id AND v.academic_year_id = e.academic_year_id
                  AND v.id = e.timetable_version_id AND v.status = 'published'
                 JOIN timetable_periods p
                   ON p.institution_id = e.institution_id AND p.academic_year_id = e.academic_year_id
                  AND p.period_schedule_id = e.period_schedule_id AND p.id = e.period_id
                 JOIN teacher_subject_assignments tsa
                   ON tsa.institution_id = e.institution_id AND tsa.academic_year_id = e.academic_year_id
                  AND tsa.academic_group_id = e.academic_group_id AND tsa.id = e.teacher_subject_assignment_id
                 JOIN teacher_group_assignments tga
                   ON tga.institution_id = tsa.institution_id AND tga.academic_year_id = tsa.academic_year_id
                  AND tga.academic_group_id = tsa.academic_group_id AND tga.id = tsa.teacher_group_assignment_id
                 JOIN subjects s ON s.institution_id = tsa.institution_id AND s.id = tsa.subject_id
                 JOIN classes c ON c.institution_id = e.institution_id AND c.id = e.class_id
                 JOIN academic_groups g
                   ON g.institution_id = e.institution_id AND g.academic_year_id = e.academic_year_id
                  AND g.id = e.academic_group_id
                 LEFT JOIN sections sec
                   ON sec.institution_id = g.institution_id AND sec.academic_year_id = g.academic_year_id
                  AND sec.id = g.section_id
                 LEFT JOIN classrooms r ON r.institution_id = e.institution_id AND r.id = e.classroom_id
                 WHERE e.institution_id = ? AND e.academic_year_id = ?
                   AND tga.instructor_id = ?
                 ORDER BY FIELD(p.day_of_week,'monday','tuesday','wednesday','thursday','friday','saturday','sunday'),
                          p.start_time, c.name, sec.name"
            );
            $statement->bind_param('iii', $institutionId, $yearId, $instructorId);
            $statement->execute();
            $result = $statement->get_result();
            $entries = [];
            $workloadMinutes = 0;
            while ($row = $result->fetch_assoc()) {
                $row['cohort_name'] = (string) $row['class_name']
                    . ($row['section_name'] !== null ? ' / ' . (string) $row['section_name'] : '');
                $workloadMinutes += max(
                    0,
                    (int) ((strtotime((string) $row['end_time']) - strtotime((string) $row['start_time'])) / 60)
                );
                $entries[] = $row;
            }
            $statement->close();
            return [
                'teacher' => $teacher,
                'academic_year_id' => $yearId,
                'entries' => $entries,
                'workload_minutes' => $workloadMinutes,
            ];
        }

        function timetable_management_student_group(
            mysqli $db,
            int $institutionId,
            int $studentId
        ): ?array {
            $statement = $db->prepare(
                "SELECT e.academic_year_id, p.academic_group_id
                 FROM student_enrolments e
                 JOIN student_enrolment_placements p
                   ON p.institution_id = e.institution_id AND p.student_id = e.student_id
                  AND p.student_enrolment_id = e.id AND p.academic_year_id = e.academic_year_id
                 JOIN students s ON s.institution_id = e.institution_id AND s.id = e.student_id
                 JOIN academic_groups g
                   ON g.institution_id = p.institution_id AND g.academic_year_id = p.academic_year_id
                  AND g.id = p.academic_group_id AND g.group_type IN ('class','section')
                 WHERE e.institution_id = ? AND e.student_id = ? AND e.enrolment_status IN ('enrolled','active')
                   AND s.status IN ('admitted','active') AND p.effective_at <= CURRENT_TIMESTAMP
                   AND p.id = (
                       SELECT p2.id FROM student_enrolment_placements p2
                       WHERE p2.institution_id = e.institution_id AND p2.student_id = e.student_id
                         AND p2.student_enrolment_id = e.id AND p2.effective_at <= CURRENT_TIMESTAMP
                       ORDER BY p2.effective_at DESC, p2.id DESC LIMIT 1
                   )
                 ORDER BY p.effective_at DESC, p.id DESC LIMIT 1"
            );
            $statement->bind_param('ii', $institutionId, $studentId);
            $statement->execute();
            $group = $statement->get_result()->fetch_assoc();
            $statement->close();
            return is_array($group) ? $group : null;
        }

        function timetable_management_student_timetable(
            mysqli $db,
            int $institutionId,
            int $studentId
        ): array {
            $group = timetable_management_student_group($db, $institutionId, $studentId);
            if ($group === null) {
                return ['group' => null, 'entries' => []];
            }
            $groupId = (int) $group['academic_group_id'];
            $yearId = (int) $group['academic_year_id'];
            $entries = timetable_management_get_published_group_entries($db, $institutionId, $yearId, $groupId);
            $groupDetails = timetable_management_group($db, $institutionId, $yearId, $groupId);
            $groupDetails['academic_year_id'] = $yearId;
            if ($entries === []) {
                return ['group' => $groupDetails, 'entries' => []];
            }
            return ['group' => $groupDetails, 'entries' => $entries];
        }
