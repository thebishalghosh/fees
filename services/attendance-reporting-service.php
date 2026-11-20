<?php
declare(strict_types=1);

require_once __DIR__ . '/attendance-management-service.php';

function attendance_reporting_default_thresholds(): array
{
    return ['warning' => 60, 'good' => 75];
}

function attendance_reporting_validate_thresholds(array $input): array
{
    $warning = attendance_parse_id($input['warning_threshold'] ?? null);
    $good = attendance_parse_id($input['good_threshold'] ?? null);
    if ($warning === null || $good === null || $warning < 0 || $good > 100
        || $warning >= $good || $good > 100) {
        throw new InvalidArgumentException('Set valid attendance thresholds from 0 to 100, with warning below good.');
    }

    return ['warning' => $warning, 'good' => $good];
}

function attendance_reporting_threshold_status(float $percentage, array $thresholds): string
{
    if ($percentage >= $thresholds['good']) {
        return 'good';
    }
    return $percentage >= $thresholds['warning'] ? 'warning' : 'critical';
}

function attendance_reporting_percentage(
    int $present,
    int $late,
    int $recorded,
    int $excused
): ?float {
    if ($present < 0 || $late < 0 || $recorded < 0 || $excused < 0
        || $present + $late + $excused > $recorded) {
        throw new InvalidArgumentException('Attendance report totals are invalid.');
    }
    $denominator = $recorded - $excused;
    return $denominator === 0 ? null : round(100 * ($present + $late) / $denominator, 2);
}

function attendance_reporting_get_thresholds(mysqli $db, int $institutionId): array
{
    if ($institutionId < 1) {
        throw new InvalidArgumentException('A valid institution is required.');
    }
    $thresholds = attendance_reporting_default_thresholds();
    $keys = ['attendance_warning_threshold', 'attendance_good_threshold'];
    $statement = $db->prepare(
        'SELECT setting_key, setting_value
         FROM system_settings
         WHERE institution_id = ? AND setting_key IN (?, ?)'
    );
    $statement->bind_param('iss', $institutionId, $keys[0], $keys[1]);
    $statement->execute();
    $result = $statement->get_result();
    while ($row = $result->fetch_assoc()) {
        $key = (string) $row['setting_key'];
        $value = filter_var($row['setting_value'], FILTER_VALIDATE_INT);
        if ($value === false || $value < 0 || $value > 100) {
            $statement->close();
            throw new RuntimeException('Saved attendance shortage thresholds are invalid.');
        }
        if ($key === $keys[0]) {
            $thresholds['warning'] = $value;
        } elseif ($key === $keys[1]) {
            $thresholds['good'] = $value;
        }
    }
    $statement->close();
    if ($thresholds['warning'] >= $thresholds['good']) {
        throw new RuntimeException('Saved attendance shortage thresholds are inconsistent.');
    }
    return $thresholds;
}

function attendance_reporting_save_thresholds(mysqli $db, int $institutionId, int $actorUserId, array $input): array
{
    $thresholds = attendance_reporting_validate_thresholds($input);
    if ($institutionId < 1 || $actorUserId < 1) {
        throw new InvalidArgumentException('A valid institution and user are required.');
    }
    if (!user_has_compatible_permission('institution_settings.edit', 'settings.manage')) {
        throw new DomainException('You do not have permission to change institution attendance settings.');
    }
    $warningKey = 'attendance_warning_threshold';
    $goodKey = 'attendance_good_threshold';
    $warningValue = (string) $thresholds['warning'];
    $goodValue = (string) $thresholds['good'];
    $statement = $db->prepare(
        'INSERT INTO system_settings (institution_id, setting_key, setting_value, updated_by)
         VALUES (?, ?, ?, ?), (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)'
    );
    $statement->bind_param(
        'issiissi',
        $institutionId,
        $warningKey,
        $warningValue,
        $actorUserId,
        $institutionId,
        $goodKey,
        $goodValue,
        $actorUserId
    );
    $statement->execute();
    $statement->close();
    return $thresholds;
}

function attendance_reporting_validate_filters(array $input): array
{
    $yearId = attendance_parse_id($input['academic_year_id'] ?? '0');
    $groupId = attendance_parse_id($input['academic_group_id'] ?? '0');
    $studentId = attendance_parse_id($input['student_id'] ?? '0');
    $dateFrom = $input['date_from'] ?? '';
    $dateTo = $input['date_to'] ?? '';
    $page = attendance_parse_id($input['page'] ?? '1');
    $report = $input['report'] ?? 'daily';
    if ($yearId === null || $yearId < 0 || $groupId === null || $groupId < 0
        || $studentId === null || $studentId < 0 || $page === null || $page < 1
        || !is_string($dateFrom) || !is_string($dateTo)
        || ($dateFrom !== '' && !attendance_valid_date($dateFrom))
        || ($dateTo !== '' && !attendance_valid_date($dateTo))
        || ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo)
        || !is_string($report) || !in_array($report, ['daily', 'student', 'shortage', 'monthly'], true)) {
        throw new InvalidArgumentException('Choose valid attendance report filters and dates.');
    }
    return [
        'report' => $report,
        'academic_year_id' => $yearId,
        'academic_group_id' => $groupId,
        'student_id' => $studentId,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'page' => $page,
    ];
}

function attendance_reporting_group_options(mysqli $db, int $institutionId, int $yearId): array
{
    if ($institutionId < 1 || $yearId < 1) {
        return [];
    }
    $statement = $db->prepare(
        "SELECT g.id, g.group_type, g.class_id, g.section_id,
                CONCAT_WS(' / ', ay.name,
                    CASE WHEN g.group_type IN ('class','section') THEN c.name ELSE co.name END,
                    CASE WHEN g.group_type = 'section' THEN section.name
                         WHEN g.group_type = 'term' THEN term.name
                         WHEN g.group_type = 'batch' THEN batch.name END) AS label
         FROM academic_groups g
         JOIN academic_years ay
           ON ay.institution_id = g.institution_id AND ay.id = g.academic_year_id
         LEFT JOIN classes c ON c.institution_id = g.institution_id AND c.id = g.class_id
         LEFT JOIN sections section
           ON section.institution_id = g.institution_id
          AND section.academic_year_id = g.academic_year_id
          AND section.class_id = g.class_id AND section.id = g.section_id
         LEFT JOIN courses co ON co.institution_id = g.institution_id AND co.id = g.course_id
         LEFT JOIN academic_terms term
           ON term.institution_id = g.institution_id AND term.academic_year_id = g.academic_year_id
          AND term.course_id = g.course_id AND term.id = g.academic_term_id
         LEFT JOIN batches batch
           ON batch.institution_id = g.institution_id AND batch.course_id = g.course_id
          AND batch.id = g.batch_id
         WHERE g.institution_id = ? AND g.academic_year_id = ?
         ORDER BY c.name, section.name, co.name, term.sequence_number, batch.name, g.id"
    );
    $statement->bind_param('ii', $institutionId, $yearId);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}

function attendance_reporting_student_options(
    mysqli $db,
    int $institutionId,
    int $yearId,
    int $groupId = 0
): array {
    if ($institutionId < 1 || $yearId < 1) {
        return [];
    }
    $groupCondition = $groupId > 0 ? 'AND placement.academic_group_id = ?' : '';
    $statement = $db->prepare(
        "SELECT DISTINCT s.id, s.student_code, s.full_name
         FROM student_enrolments enrolment
         JOIN academic_years ay
           ON ay.institution_id = enrolment.institution_id AND ay.id = enrolment.academic_year_id
         JOIN students s
           ON s.institution_id = enrolment.institution_id AND s.id = enrolment.student_id
         JOIN student_enrolment_placements placement
           ON placement.institution_id = enrolment.institution_id
          AND placement.student_enrolment_id = enrolment.id
         WHERE enrolment.institution_id = ? AND enrolment.academic_year_id = ?
           AND enrolment.enrolment_status IN ('active','completed','promoted')
           $groupCondition
         ORDER BY s.full_name, s.student_code
         LIMIT 2000"
    );
    if ($groupId > 0) {
        $statement->bind_param('iii', $institutionId, $yearId, $groupId);
    } else {
        $statement->bind_param('ii', $institutionId, $yearId);
    }
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}

function attendance_reporting_holiday_condition(
    string $dateExpression,
    string $institutionExpression = 'session.institution_id'
): string
{
    return "NOT EXISTS (
        SELECT 1 FROM holidays holiday
        WHERE holiday.institution_id = $institutionExpression AND holiday.status = 'active'
          AND (
            (holiday.is_recurring = 0
             AND $dateExpression BETWEEN holiday.start_date AND COALESCE(holiday.end_date, holiday.start_date))
            OR (holiday.is_recurring = 1 AND
                CASE
                    WHEN DATE_FORMAT(holiday.start_date, '%m-%d')
                         <= DATE_FORMAT(COALESCE(holiday.end_date, holiday.start_date), '%m-%d')
                    THEN DATE_FORMAT($dateExpression, '%m-%d')
                         BETWEEN DATE_FORMAT(holiday.start_date, '%m-%d')
                             AND DATE_FORMAT(COALESCE(holiday.end_date, holiday.start_date), '%m-%d')
                    ELSE DATE_FORMAT($dateExpression, '%m-%d') >= DATE_FORMAT(holiday.start_date, '%m-%d')
                      OR DATE_FORMAT($dateExpression, '%m-%d')
                         <= DATE_FORMAT(COALESCE(holiday.end_date, holiday.start_date), '%m-%d')
                END)
          )
    )";
}

function attendance_reporting_date_is_holiday(mysqli $db, int $institutionId, string $date): bool
{
    if ($institutionId < 1 || !attendance_valid_date($date)) {
        throw new InvalidArgumentException('Choose a valid institution and date.');
    }
    $statement = $db->prepare(
        "SELECT EXISTS(
            SELECT 1 FROM holidays holiday
            WHERE holiday.institution_id = ? AND holiday.status = 'active'
              AND (
                (holiday.is_recurring = 0
                 AND ? BETWEEN holiday.start_date AND COALESCE(holiday.end_date, holiday.start_date))
                OR (holiday.is_recurring = 1 AND
                    CASE
                        WHEN DATE_FORMAT(holiday.start_date, '%m-%d')
                             <= DATE_FORMAT(COALESCE(holiday.end_date, holiday.start_date), '%m-%d')
                        THEN DATE_FORMAT(?, '%m-%d')
                             BETWEEN DATE_FORMAT(holiday.start_date, '%m-%d')
                                 AND DATE_FORMAT(COALESCE(holiday.end_date, holiday.start_date), '%m-%d')
                        ELSE DATE_FORMAT(?, '%m-%d') >= DATE_FORMAT(holiday.start_date, '%m-%d')
                          OR DATE_FORMAT(?, '%m-%d')
                             <= DATE_FORMAT(COALESCE(holiday.end_date, holiday.start_date), '%m-%d')
                    END)
              )
        )"
    );
    $statement->bind_param('issss', $institutionId, $date, $date, $date, $date);
    $statement->execute();
    $isHoliday = (bool) $statement->get_result()->fetch_column();
    $statement->close();
    return $isHoliday;
}

function attendance_reporting_require_year_group(
    mysqli $db,
    int $institutionId,
    int $yearId,
    int $groupId,
    ?string $date = null
): array {
    if ($institutionId < 1 || $yearId < 1 || $groupId < 1) {
        throw new InvalidArgumentException('Select a valid academic year and placement.');
    }
    $statement = $db->prepare(
        'SELECT ay.start_date, ay.end_date, g.id AS group_id
         FROM academic_years ay
         JOIN academic_groups g
           ON g.institution_id = ay.institution_id AND g.academic_year_id = ay.id
         WHERE ay.institution_id = ? AND ay.id = ? AND g.id = ?'
    );
    $statement->bind_param('iii', $institutionId, $yearId, $groupId);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_array($row)) {
        throw new OutOfBoundsException('The selected placement is not available in this institution and academic year.');
    }
    if ($date !== null && (!attendance_valid_date($date)
        || $date < (string) $row['start_date'] || $date > (string) $row['end_date'])) {
        throw new InvalidArgumentException('The report date must fall within the selected academic year.');
    }
    return $row;
}

function attendance_reporting_roster_count(
    mysqli $db,
    int $institutionId,
    int $yearId,
    int $groupId,
    string $date
): int {
    attendance_reporting_require_year_group($db, $institutionId, $yearId, $groupId, $date);
    $statement = $db->prepare(
        "SELECT COUNT(DISTINCT enrolment.student_id)
         FROM student_enrolments enrolment
         JOIN student_enrolment_placements placement
           ON placement.institution_id = enrolment.institution_id
          AND placement.student_enrolment_id = enrolment.id
          AND placement.id = (
              SELECT placement2.id FROM student_enrolment_placements placement2
              WHERE placement2.institution_id = enrolment.institution_id
                AND placement2.student_enrolment_id = enrolment.id
                AND placement2.effective_at < DATE_ADD(?, INTERVAL 1 DAY)
              ORDER BY placement2.effective_at DESC, placement2.id DESC LIMIT 1
          )
         WHERE enrolment.institution_id = ? AND enrolment.academic_year_id = ?
           AND enrolment.enrolment_status IN ('active','completed','promoted')
           AND placement.academic_group_id = ?"
    );
    $statement->bind_param('siii', $date, $institutionId, $yearId, $groupId);
    $statement->execute();
    $count = (int) $statement->get_result()->fetch_column();
    $statement->close();
    return $count;
}

function attendance_reporting_get_daily(
    mysqli $db,
    int $institutionId,
    int $yearId,
    int $groupId,
    string $date
): array {
    attendance_reporting_require_year_group($db, $institutionId, $yearId, $groupId, $date);
    $enrolled = attendance_reporting_roster_count($db, $institutionId, $yearId, $groupId, $date);
    $sessionAt = $date . ' 00:00:00.000000';
    $statement = $db->prepare(
        "SELECT session.id, session.status,
                COUNT(record.id) AS recorded_count,
                COALESCE(SUM(record.status = 'present'), 0) AS present_count,
                COALESCE(SUM(record.status = 'absent'), 0) AS absent_count,
                COALESCE(SUM(record.status = 'late'), 0) AS late_count,
                COALESCE(SUM(record.status = 'excused'), 0) AS excused_count
         FROM attendance_sessions session
         LEFT JOIN attendance_session_records record
           ON record.institution_id = session.institution_id
          AND record.academic_year_id = session.academic_year_id
          AND record.academic_group_id = session.academic_group_id
          AND record.attendance_session_id = session.id
         WHERE session.institution_id = ? AND session.academic_year_id = ?
           AND session.academic_group_id = ? AND session.session_at = ?
           AND session.session_code = 'daily'
         GROUP BY session.id, session.status"
    );
    $statement->bind_param('iiis', $institutionId, $yearId, $groupId, $sessionAt);
    $statement->execute();
    $session = $statement->get_result()->fetch_assoc();
    $statement->close();
    $holiday = attendance_reporting_date_is_holiday($db, $institutionId, $date);
    $recorded = is_array($session) ? (int) $session['recorded_count'] : 0;
    $sessionStatus = is_array($session) ? (string) $session['status'] : 'not_submitted';
    $finalized = $sessionStatus === 'closed' && !$holiday;
    $percentage = $finalized
        ? attendance_reporting_percentage(
            (int) $session['present_count'],
            (int) $session['late_count'],
            $recorded,
            (int) $session['excused_count']
        )
        : null;

    return [
        'date' => $date,
        'enrolled_count' => $enrolled,
        'recorded_count' => $recorded,
        'present_count' => (int) ($session['present_count'] ?? 0),
        'absent_count' => (int) ($session['absent_count'] ?? 0),
        'late_count' => (int) ($session['late_count'] ?? 0),
        'excused_count' => (int) ($session['excused_count'] ?? 0),
        'attendance_percentage' => $percentage,
        'session_status' => $sessionStatus,
        'is_holiday' => $holiday,
        'submission_status' => $holiday
            ? 'Holiday'
            : match ($sessionStatus) {
                'closed' => 'Finalized',
                'open' => $enrolled > 0 && $recorded >= $enrolled ? 'Ready to finalize' : 'In progress',
                'cancelled' => 'Cancelled',
                default => 'Not submitted',
            },
    ];
}

function attendance_reporting_close_session(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    int $yearId,
    int $groupId,
    string $date
): void {
    if ($institutionId < 1 || $actorUserId < 1) {
        throw new InvalidArgumentException('A valid institution and user are required.');
    }
    if (!user_has_compatible_permission('attendance.edit', 'academics.manage')) {
        throw new DomainException('You do not have permission to finalize attendance.');
    }
    attendance_reporting_require_year_group($db, $institutionId, $yearId, $groupId, $date);
    if (attendance_reporting_date_is_holiday($db, $institutionId, $date)) {
        throw new DomainException('Attendance cannot be finalized on an institutional holiday.');
    }
    $db->begin_transaction();
    try {
        $sessionAt = $date . ' 00:00:00.000000';
        $statement = $db->prepare(
            "SELECT id, status FROM attendance_sessions
             WHERE institution_id = ? AND academic_year_id = ? AND academic_group_id = ?
               AND session_at = ? AND session_code = 'daily'
             FOR UPDATE"
        );
        $statement->bind_param('iiis', $institutionId, $yearId, $groupId, $sessionAt);
        $statement->execute();
        $session = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($session) || $session['status'] !== 'open') {
            throw new DomainException('Only an open attendance session can be finalized.');
        }
        $sessionId = (int) $session['id'];
        $roster = attendance_get_session_roster($db, $institutionId, $yearId, $groupId, $date);
        $statement = $db->prepare(
            'SELECT COUNT(DISTINCT student_id) FROM attendance_session_records
             WHERE institution_id = ? AND academic_year_id = ? AND academic_group_id = ?
               AND attendance_session_id = ?'
        );
        $statement->bind_param('iiii', $institutionId, $yearId, $groupId, $sessionId);
        $statement->execute();
        $recorded = (int) $statement->get_result()->fetch_column();
        $statement->close();
        $rosterIsComplete = $roster !== [];
        foreach ($roster as $student) {
            if (($student['attendance_record_id'] ?? null) === null) {
                $rosterIsComplete = false;
                break;
            }
        }
        if (!$rosterIsComplete || $recorded !== count($roster)) {
            throw new DomainException('Finalize attendance only after every enrolled student has a status.');
        }
        $statement = $db->prepare(
            "UPDATE attendance_sessions SET status = 'closed'
             WHERE institution_id = ? AND id = ? AND status = 'open'"
        );
        $statement->bind_param('ii', $institutionId, $sessionId);
        $statement->execute();
        $statement->close();
        $actor = current_user();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            is_array($actor) ? (string) $actor['user_type'] : 'admin',
            'attendance_session.finalized',
            'attendance_session',
            (string) $sessionId,
            ['status' => 'open'],
            ['status' => 'closed', 'recorded_count' => $recorded]
        );
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function attendance_reporting_student_summary(
    mysqli $db,
    int $institutionId,
    int $yearId,
    int $studentId,
    string $dateFrom,
    string $dateTo,
    int $groupId = 0
): array {
    if ($institutionId < 1 || $yearId < 1 || $studentId < 1 || $groupId < 0
        || !attendance_valid_date($dateFrom) || !attendance_valid_date($dateTo) || $dateFrom > $dateTo) {
        throw new InvalidArgumentException('Choose a student, academic year, and valid date range.');
    }
    $holidayCondition = attendance_reporting_holiday_condition('DATE(session.session_at)');
    $groupCondition = $groupId > 0 ? 'AND session.academic_group_id = ?' : '';
    $statement = $db->prepare(
        "SELECT COUNT(DISTINCT session.id) AS total_working_days,
                COALESCE(SUM(record.status = 'present'), 0) AS present_count,
                COALESCE(SUM(record.status = 'absent'), 0) AS absent_count,
                COALESCE(SUM(record.status = 'late'), 0) AS late_count,
                COALESCE(SUM(record.status = 'excused'), 0) AS excused_count,
                CASE WHEN COUNT(record.id) = COALESCE(SUM(record.status = 'excused'), 0) THEN NULL
                     ELSE ROUND(100.0 * SUM(record.status IN ('present','late'))
                          / (COUNT(record.id) - COALESCE(SUM(record.status = 'excused'), 0)), 2)
                END AS attendance_percentage
         FROM attendance_sessions session
         JOIN attendance_session_records record
           ON record.institution_id = session.institution_id
          AND record.academic_year_id = session.academic_year_id
          AND record.academic_group_id = session.academic_group_id
          AND record.attendance_session_id = session.id
         WHERE session.institution_id = ? AND session.academic_year_id = ?
           AND record.student_id = ? AND session.status = 'closed'
           AND session.session_code = 'daily'
           AND DATE(session.session_at) BETWEEN ? AND ?
           AND $holidayCondition $groupCondition"
    );
    if ($groupId > 0) {
        $statement->bind_param('iiissi', $institutionId, $yearId, $studentId, $dateFrom, $dateTo, $groupId);
    } else {
        $statement->bind_param('iiiss', $institutionId, $yearId, $studentId, $dateFrom, $dateTo);
    }
    $statement->execute();
    $summary = $statement->get_result()->fetch_assoc() ?: [];
    $statement->close();
    foreach (['total_working_days', 'present_count', 'absent_count', 'late_count', 'excused_count'] as $key) {
        $summary[$key] = (int) ($summary[$key] ?? 0);
    }
    $summary['attendance_percentage'] = $summary['attendance_percentage'] === null
        ? null
        : (float) $summary['attendance_percentage'];
    return $summary;
}

function attendance_reporting_student_monthly(
    mysqli $db,
    int $institutionId,
    int $yearId,
    int $studentId,
    string $dateFrom,
    string $dateTo,
    int $groupId = 0
): array {
    if ($institutionId < 1 || $yearId < 1 || $studentId < 1 || $groupId < 0
        || !attendance_valid_date($dateFrom) || !attendance_valid_date($dateTo) || $dateFrom > $dateTo) {
        throw new InvalidArgumentException('Choose a student, academic year, and valid date range.');
    }
    $holidayCondition = attendance_reporting_holiday_condition('DATE(session.session_at)');
    $groupCondition = $groupId > 0 ? 'AND session.academic_group_id = ?' : '';
    $statement = $db->prepare(
        "SELECT DATE_FORMAT(session.session_at, '%Y-%m') AS month,
                COUNT(DISTINCT session.id) AS total_working_days,
                COALESCE(SUM(record.status = 'present'), 0) AS present_count,
                COALESCE(SUM(record.status = 'absent'), 0) AS absent_count,
                COALESCE(SUM(record.status = 'late'), 0) AS late_count,
                COALESCE(SUM(record.status = 'excused'), 0) AS excused_count,
                CASE WHEN COUNT(record.id) = COALESCE(SUM(record.status = 'excused'), 0) THEN NULL
                     ELSE ROUND(100.0 * SUM(record.status IN ('present','late'))
                          / (COUNT(record.id) - COALESCE(SUM(record.status = 'excused'), 0)), 2)
                END AS attendance_percentage
         FROM attendance_sessions session
         JOIN attendance_session_records record
           ON record.institution_id = session.institution_id
          AND record.academic_year_id = session.academic_year_id
          AND record.academic_group_id = session.academic_group_id
          AND record.attendance_session_id = session.id
         WHERE session.institution_id = ? AND session.academic_year_id = ?
           AND record.student_id = ? AND session.status = 'closed'
           AND session.session_code = 'daily'
           AND DATE(session.session_at) BETWEEN ? AND ?
           AND $holidayCondition $groupCondition
         GROUP BY DATE_FORMAT(session.session_at, '%Y-%m')
         ORDER BY month"
    );
    if ($groupId > 0) {
        $statement->bind_param('iiissi', $institutionId, $yearId, $studentId, $dateFrom, $dateTo, $groupId);
    } else {
        $statement->bind_param('iiiss', $institutionId, $yearId, $studentId, $dateFrom, $dateTo);
    }
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}

function attendance_reporting_student_records(
    mysqli $db,
    int $institutionId,
    int $yearId,
    int $studentId,
    string $dateFrom,
    string $dateTo,
    int $page = 1,
    int $perPage = 20,
    int $groupId = 0
): array {
    if ($institutionId < 1 || $yearId < 1 || $studentId < 1 || $groupId < 0
        || !attendance_valid_date($dateFrom) || !attendance_valid_date($dateTo) || $dateFrom > $dateTo
        || $page < 1 || $perPage < 1 || $perPage > 100) {
        throw new InvalidArgumentException('Choose a valid attendance history page.');
    }
    $holidayCondition = attendance_reporting_holiday_condition('DATE(session.session_at)');
    $groupCondition = $groupId > 0 ? 'AND session.academic_group_id = ?' : '';
    $base = " FROM attendance_sessions session
              JOIN attendance_session_records record
                ON record.institution_id = session.institution_id
               AND record.academic_year_id = session.academic_year_id
               AND record.academic_group_id = session.academic_group_id
               AND record.attendance_session_id = session.id
              JOIN academic_groups g
                ON g.institution_id = session.institution_id
               AND g.academic_year_id = session.academic_year_id AND g.id = session.academic_group_id
              LEFT JOIN classes c ON c.institution_id = g.institution_id AND c.id = g.class_id
              LEFT JOIN sections section
                ON section.institution_id = g.institution_id
               AND section.academic_year_id = g.academic_year_id
               AND section.class_id = g.class_id AND section.id = g.section_id
              LEFT JOIN courses co ON co.institution_id = g.institution_id AND co.id = g.course_id
              LEFT JOIN academic_terms term
                ON term.institution_id = g.institution_id AND term.academic_year_id = g.academic_year_id
               AND term.course_id = g.course_id AND term.id = g.academic_term_id
              LEFT JOIN batches batch
                ON batch.institution_id = g.institution_id AND batch.course_id = g.course_id
               AND batch.id = g.batch_id
              WHERE session.institution_id = ? AND session.academic_year_id = ?
                AND record.student_id = ? AND session.status = 'closed'
                AND session.session_code = 'daily'
                AND DATE(session.session_at) BETWEEN ? AND ?
                AND $holidayCondition $groupCondition";
    $countStatement = $db->prepare('SELECT COUNT(*)' . $base);
    if ($groupId > 0) {
        $countStatement->bind_param('iiissi', $institutionId, $yearId, $studentId, $dateFrom, $dateTo, $groupId);
    } else {
        $countStatement->bind_param('iiiss', $institutionId, $yearId, $studentId, $dateFrom, $dateTo);
    }
    $countStatement->execute();
    $total = (int) $countStatement->get_result()->fetch_column();
    $countStatement->close();
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    $statement = $db->prepare(
        "SELECT DATE(session.session_at) AS attendance_date, record.status,
                CONCAT_WS(' / ',
                    CASE WHEN g.group_type IN ('class','section') THEN c.name ELSE co.name END,
                    CASE WHEN g.group_type = 'section' THEN section.name
                         WHEN g.group_type = 'term' THEN term.name
                         WHEN g.group_type = 'batch' THEN batch.name END) AS placement_name
         $base
         ORDER BY session.session_at DESC, session.id DESC
         LIMIT ? OFFSET ?"
    );
    if ($groupId > 0) {
        $statement->bind_param('iiissiii', $institutionId, $yearId, $studentId, $dateFrom, $dateTo, $groupId, $perPage, $offset);
    } else {
        $statement->bind_param('iiissii', $institutionId, $yearId, $studentId, $dateFrom, $dateTo, $perPage, $offset);
    }
    $statement->execute();
    $records = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return [
        'records' => $records,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => $totalPages,
    ];
}

function attendance_reporting_shortage(
    mysqli $db,
    int $institutionId,
    int $yearId,
    int $groupId,
    string $dateFrom,
    string $dateTo,
    array $thresholds
): array {
    if ($institutionId < 1 || $yearId < 1 || $groupId < 0
        || !attendance_valid_date($dateFrom) || !attendance_valid_date($dateTo) || $dateFrom > $dateTo) {
        throw new InvalidArgumentException('Choose a valid academic year, placement, and date range.');
    }
    $groupCondition = $groupId > 0 ? 'AND session.academic_group_id = ?' : '';
    $holidayCondition = attendance_reporting_holiday_condition('DATE(session.session_at)');
    $statement = $db->prepare(
        "SELECT s.id AS student_id, s.student_code, s.full_name,
                g.id AS academic_group_id,
                CONCAT_WS(' / ',
                    CASE WHEN g.group_type IN ('class','section') THEN c.name ELSE co.name END,
                    CASE WHEN g.group_type = 'section' THEN section.name
                         WHEN g.group_type = 'term' THEN term.name
                         WHEN g.group_type = 'batch' THEN batch.name END) AS placement_name,
                COUNT(DISTINCT session.id) AS total_working_days,
                COALESCE(SUM(record.status = 'present'), 0) AS present_count,
                COALESCE(SUM(record.status = 'absent'), 0) AS absent_count,
                COALESCE(SUM(record.status = 'late'), 0) AS late_count,
                COALESCE(SUM(record.status = 'excused'), 0) AS excused_count,
                CASE WHEN COUNT(record.id) = COALESCE(SUM(record.status = 'excused'), 0) THEN NULL
                     ELSE ROUND(100.0 * SUM(record.status IN ('present','late'))
                          / (COUNT(record.id) - COALESCE(SUM(record.status = 'excused'), 0)), 2)
                END AS attendance_percentage
         FROM attendance_sessions session
         JOIN attendance_session_records record
           ON record.institution_id = session.institution_id
          AND record.academic_year_id = session.academic_year_id
          AND record.academic_group_id = session.academic_group_id
          AND record.attendance_session_id = session.id
         JOIN students s ON s.institution_id = record.institution_id AND s.id = record.student_id
         JOIN academic_groups g
           ON g.institution_id = session.institution_id
          AND g.academic_year_id = session.academic_year_id AND g.id = session.academic_group_id
         LEFT JOIN classes c ON c.institution_id = g.institution_id AND c.id = g.class_id
         LEFT JOIN sections section
           ON section.institution_id = g.institution_id
          AND section.academic_year_id = g.academic_year_id
          AND section.class_id = g.class_id AND section.id = g.section_id
         LEFT JOIN courses co ON co.institution_id = g.institution_id AND co.id = g.course_id
         LEFT JOIN academic_terms term
           ON term.institution_id = g.institution_id AND term.academic_year_id = g.academic_year_id
          AND term.course_id = g.course_id AND term.id = g.academic_term_id
         LEFT JOIN batches batch
           ON batch.institution_id = g.institution_id AND batch.course_id = g.course_id AND batch.id = g.batch_id
         WHERE session.institution_id = ? AND session.academic_year_id = ?
           AND session.status = 'closed' AND session.session_code = 'daily'
           AND DATE(session.session_at) BETWEEN ? AND ?
           AND $holidayCondition $groupCondition
         GROUP BY s.id, s.student_code, s.full_name, g.id, g.group_type, c.name, section.name,
                  co.name, term.name, batch.name
         ORDER BY attendance_percentage, s.full_name, s.student_code"
    );
    if ($groupId > 0) {
        $statement->bind_param('iissi', $institutionId, $yearId, $dateFrom, $dateTo, $groupId);
    } else {
        $statement->bind_param('iiss', $institutionId, $yearId, $dateFrom, $dateTo);
    }
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    foreach ($rows as &$row) {
        $row['total_working_days'] = (int) $row['total_working_days'];
        $row['present_count'] = (int) $row['present_count'];
        $row['absent_count'] = (int) $row['absent_count'];
        $row['late_count'] = (int) $row['late_count'];
        $row['excused_count'] = (int) $row['excused_count'];
        $row['attendance_percentage'] = $row['attendance_percentage'] === null
            ? null
            : (float) $row['attendance_percentage'];
        $row['status'] = $row['attendance_percentage'] === null
            ? 'no-data'
            : attendance_reporting_threshold_status($row['attendance_percentage'], $thresholds);
    }
    unset($row);
    return $rows;
}

function attendance_reporting_monthly(
    mysqli $db,
    int $institutionId,
    int $yearId,
    int $groupId,
    string $dateFrom,
    string $dateTo
): array {
    if ($institutionId < 1 || $yearId < 1 || $groupId < 0
        || !attendance_valid_date($dateFrom) || !attendance_valid_date($dateTo) || $dateFrom > $dateTo) {
        throw new InvalidArgumentException('Choose a valid academic year, placement, and date range.');
    }
    $groupCondition = $groupId > 0 ? 'AND session.academic_group_id = ?' : '';
    $holidayCondition = attendance_reporting_holiday_condition('DATE(session.session_at)');
    $statement = $db->prepare(
        "SELECT DATE_FORMAT(session.session_at, '%Y-%m') AS month,
                g.id AS academic_group_id,
                CONCAT_WS(' / ',
                    CASE WHEN g.group_type IN ('class','section') THEN c.name ELSE co.name END,
                    CASE WHEN g.group_type = 'section' THEN section.name
                         WHEN g.group_type = 'term' THEN term.name
                         WHEN g.group_type = 'batch' THEN batch.name END) AS placement_name,
                COUNT(DISTINCT session.id) AS total_working_days,
                COUNT(record.id) AS recorded_count,
                COALESCE(SUM(record.status = 'present'), 0) AS present_count,
                COALESCE(SUM(record.status = 'absent'), 0) AS absent_count,
                COALESCE(SUM(record.status = 'late'), 0) AS late_count,
                COALESCE(SUM(record.status = 'excused'), 0) AS excused_count,
                CASE WHEN COUNT(record.id) = COALESCE(SUM(record.status = 'excused'), 0) THEN NULL
                     ELSE ROUND(100.0 * SUM(record.status IN ('present','late'))
                          / (COUNT(record.id) - COALESCE(SUM(record.status = 'excused'), 0)), 2)
                END AS attendance_percentage
         FROM attendance_sessions session
         JOIN attendance_session_records record
           ON record.institution_id = session.institution_id
          AND record.academic_year_id = session.academic_year_id
          AND record.academic_group_id = session.academic_group_id
          AND record.attendance_session_id = session.id
         JOIN academic_groups g
           ON g.institution_id = session.institution_id
          AND g.academic_year_id = session.academic_year_id AND g.id = session.academic_group_id
         LEFT JOIN classes c ON c.institution_id = g.institution_id AND c.id = g.class_id
         LEFT JOIN sections section
           ON section.institution_id = g.institution_id
          AND section.academic_year_id = g.academic_year_id
          AND section.class_id = g.class_id AND section.id = g.section_id
         LEFT JOIN courses co ON co.institution_id = g.institution_id AND co.id = g.course_id
         LEFT JOIN academic_terms term
           ON term.institution_id = g.institution_id AND term.academic_year_id = g.academic_year_id
          AND term.course_id = g.course_id AND term.id = g.academic_term_id
         LEFT JOIN batches batch
           ON batch.institution_id = g.institution_id AND batch.course_id = g.course_id AND batch.id = g.batch_id
         WHERE session.institution_id = ? AND session.academic_year_id = ?
           AND session.status = 'closed' AND session.session_code = 'daily'
           AND DATE(session.session_at) BETWEEN ? AND ?
           AND $holidayCondition $groupCondition
         GROUP BY DATE_FORMAT(session.session_at, '%Y-%m'), g.id, g.group_type,
                  c.name, section.name, co.name, term.name, batch.name
         ORDER BY month, placement_name"
    );
    if ($groupId > 0) {
        $statement->bind_param('iissi', $institutionId, $yearId, $dateFrom, $dateTo, $groupId);
    } else {
        $statement->bind_param('iiss', $institutionId, $yearId, $dateFrom, $dateTo);
    }
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}

function attendance_reporting_consistently_poor_students(
    mysqli $db,
    int $institutionId,
    int $yearId,
    int $groupId,
    string $dateFrom,
    string $dateTo,
    int $warningThreshold,
    bool $sessionMode
): array {
    if ($institutionId < 1 || $yearId < 1 || $groupId < 0
        || $warningThreshold < 0 || $warningThreshold > 100
        || !attendance_valid_date($dateFrom) || !attendance_valid_date($dateTo) || $dateFrom > $dateTo) {
        throw new InvalidArgumentException('Choose valid attendance analytics filters.');
    }
    if ($sessionMode) {
        $groupCondition = $groupId > 0 ? 'AND session.academic_group_id = ?' : '';
        $holidayCondition = attendance_reporting_holiday_condition('DATE(session.session_at)');
        $query = "SELECT record.student_id, DATE_FORMAT(session.session_at, '%Y-%m') AS month,
                         ROUND(100.0 * SUM(record.status IN ('present','late'))
                               / NULLIF(COUNT(record.id) - SUM(record.status = 'excused'), 0), 2) AS percentage
                  FROM attendance_sessions session
                  JOIN attendance_session_records record
                    ON record.institution_id = session.institution_id
                   AND record.academic_year_id = session.academic_year_id
                   AND record.academic_group_id = session.academic_group_id
                   AND record.attendance_session_id = session.id
                  WHERE session.institution_id = ? AND session.academic_year_id = ?
                    AND session.status = 'closed' AND session.session_code = 'daily'
                    AND DATE(session.session_at) BETWEEN ? AND ?
                    AND $holidayCondition $groupCondition
                  GROUP BY record.student_id, DATE_FORMAT(session.session_at, '%Y-%m')
                  ORDER BY record.student_id, month";
    } else {
        $batchCondition = $groupId > 0 ? 'AND record.batch_id = ?' : '';
        $holidayCondition = attendance_reporting_holiday_condition(
            'record.attendance_date',
            'record.institution_id'
        );
        $query = "SELECT record.student_id, DATE_FORMAT(record.attendance_date, '%Y-%m') AS month,
                         ROUND(100.0 * SUM(record.status IN ('present','late')) / COUNT(*), 2) AS percentage
                  FROM attendance_records record
                  JOIN " . attendance_reporting_legacy_finalized_days_subquery() . "
                    ON finalized.institution_id = record.institution_id
                   AND finalized.academic_year_id = record.academic_year_id
                   AND finalized.batch_id = record.batch_id
                   AND finalized.attendance_date = record.attendance_date
                  WHERE record.institution_id = ? AND record.academic_year_id = ?
                    AND record.attendance_date BETWEEN ? AND ?
                    AND $holidayCondition $batchCondition
                  GROUP BY record.student_id, DATE_FORMAT(record.attendance_date, '%Y-%m')
                  ORDER BY record.student_id, month";
    }
    $statement = $db->prepare($query);
    if ($sessionMode && $groupId > 0) {
        $statement->bind_param('iissi', $institutionId, $yearId, $dateFrom, $dateTo, $groupId);
    } elseif ($sessionMode) {
        $statement->bind_param('iiss', $institutionId, $yearId, $dateFrom, $dateTo);
    } elseif ($groupId > 0) {
        $statement->bind_param(
            'iiiissi',
            $institutionId,
            $yearId,
            $institutionId,
            $yearId,
            $dateFrom,
            $dateTo,
            $groupId
        );
    } else {
        $statement->bind_param(
            'iiiiss',
            $institutionId,
            $yearId,
            $institutionId,
            $yearId,
            $dateFrom,
            $dateTo
        );
    }
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return attendance_reporting_detect_consistently_poor($rows, $warningThreshold);
}

function attendance_reporting_detect_consistently_poor(array $monthlyRates, int $warningThreshold): array
{
    if ($warningThreshold < 0 || $warningThreshold > 100) {
        throw new InvalidArgumentException('Choose a valid attendance warning threshold.');
    }
    $consecutiveMonths = [];
    $lastMonths = [];
    $consistentlyPoor = [];
    foreach ($monthlyRates as $row) {
        if (!isset($row['student_id'], $row['month']) || !array_key_exists('percentage', $row)
            || !is_string($row['month'])
            || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $row['month']) !== 1
            || ($row['percentage'] !== null && !is_numeric($row['percentage']))) {
            throw new InvalidArgumentException('Monthly attendance trend data is invalid.');
        }
        $studentId = (int) $row['student_id'];
        $month = (string) $row['month'];
        if ($row['percentage'] === null) {
            $consecutiveMonths[$studentId] = 0;
            $lastMonths[$studentId] = $month;
            continue;
        }
        $previousMonth = $lastMonths[$studentId] ?? null;
        $expectedNextMonth = $previousMonth === null
            ? null
            : (new DateTimeImmutable($previousMonth . '-01'))->modify('+1 month')->format('Y-m');
        $consecutiveMonths[$studentId] = (float) $row['percentage'] < $warningThreshold
            ? (($expectedNextMonth === $month ? ($consecutiveMonths[$studentId] ?? 0) + 1 : 1))
            : 0;
        if ($consecutiveMonths[$studentId] >= 3) {
            $consistentlyPoor[$studentId] = true;
        }
        $lastMonths[$studentId] = $month;
    }
    return array_map('intval', array_keys($consistentlyPoor));
}

function attendance_reporting_csv_value(mixed $value): string
{
    $text = (string) $value;
    if (!is_numeric($value)
        && preg_match('/^[\s\x00-\x1F]*[=+\-@]/', $text) === 1) {
        return "'" . $text;
    }
    return $text;
}

function attendance_reporting_legacy_batch_options(mysqli $db, int $institutionId): array
{
    return attendance_get_batches($db, $institutionId);
}

function attendance_reporting_legacy_student_options(
    mysqli $db,
    int $institutionId,
    int $yearId,
    int $batchId = 0
): array {
    if ($institutionId < 1 || $yearId < 1) {
        return [];
    }
    $batchCondition = $batchId > 0 ? 'AND s.batch_id = ?' : '';
    $statement = $db->prepare(
        "SELECT s.id, s.student_code, s.full_name
         FROM students s
         WHERE s.institution_id = ? AND s.academic_year_id = ?
           AND s.status IN ('admitted','active') $batchCondition
         ORDER BY s.full_name, s.student_code
         LIMIT 2000"
    );
    if ($batchId > 0) {
        $statement->bind_param('iii', $institutionId, $yearId, $batchId);
    } else {
        $statement->bind_param('ii', $institutionId, $yearId);
    }
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}

function attendance_reporting_legacy_require_cohort(
    mysqli $db,
    int $institutionId,
    int $yearId,
    int $batchId,
    string $dateFrom,
    string $dateTo
): array {
    if ($institutionId < 1 || $yearId < 1 || $batchId < 1
        || !attendance_valid_date($dateFrom) || !attendance_valid_date($dateTo) || $dateFrom > $dateTo) {
        throw new InvalidArgumentException('Choose a valid academic year, batch, and date range.');
    }
    $statement = $db->prepare(
        'SELECT ay.start_date, ay.end_date, b.id AS batch_id, b.class_id
         FROM academic_years ay
         JOIN batches b ON b.institution_id = ay.institution_id
         WHERE ay.institution_id = ? AND ay.id = ? AND b.id = ?'
    );
    $statement->bind_param('iii', $institutionId, $yearId, $batchId);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_array($row)) {
        throw new OutOfBoundsException('The selected batch is not available in this institution and academic year.');
    }
    if ($dateFrom < (string) $row['start_date'] || $dateTo > (string) $row['end_date']) {
        throw new InvalidArgumentException('The report dates must fall within the selected academic year.');
    }
    return $row;
}

function attendance_reporting_legacy_daily(
    mysqli $db,
    int $institutionId,
    int $yearId,
    int $batchId,
    string $date
): array {
    $cohort = attendance_reporting_legacy_require_cohort(
        $db,
        $institutionId,
        $yearId,
        $batchId,
        $date,
        $date
    );
    $statement = $db->prepare(
        "SELECT COUNT(*) FROM students
         WHERE institution_id = ? AND academic_year_id = ? AND batch_id = ?
           AND class_id = ? AND status IN ('admitted','active')"
    );
    $statement->bind_param('iiii', $institutionId, $yearId, $batchId, $cohort['class_id']);
    $statement->execute();
    $enrolled = (int) $statement->get_result()->fetch_column();
    $statement->close();
    $statement = $db->prepare(
        "SELECT COUNT(*) AS recorded_count,
                COALESCE(SUM(status = 'present'), 0) AS present_count,
                COALESCE(SUM(status = 'absent'), 0) AS absent_count,
                COALESCE(SUM(status = 'late'), 0) AS late_count
         FROM attendance_records
         WHERE institution_id = ? AND academic_year_id = ? AND batch_id = ? AND attendance_date = ?"
    );
    $statement->bind_param('iiis', $institutionId, $yearId, $batchId, $date);
    $statement->execute();
    $counts = $statement->get_result()->fetch_assoc() ?: [];
    $statement->close();
    $holiday = attendance_reporting_date_is_holiday($db, $institutionId, $date);
    $recorded = (int) ($counts['recorded_count'] ?? 0);
    $finalized = !$holiday && $enrolled > 0 && $recorded === $enrolled;
    return [
        'date' => $date,
        'enrolled_count' => $enrolled,
        'recorded_count' => $recorded,
        'present_count' => (int) ($counts['present_count'] ?? 0),
        'absent_count' => (int) ($counts['absent_count'] ?? 0),
        'late_count' => (int) ($counts['late_count'] ?? 0),
        'excused_count' => 0,
        'attendance_percentage' => $finalized
            ? attendance_reporting_percentage(
                (int) $counts['present_count'],
                (int) $counts['late_count'],
                $enrolled,
                0
            )
            : null,
        'session_status' => $finalized ? 'closed' : ($recorded > 0 ? 'open' : 'not_submitted'),
        'is_holiday' => $holiday,
        'submission_status' => $holiday
            ? 'Holiday'
            : ($finalized ? 'Finalized' : ($recorded > 0 ? 'In progress' : 'Not submitted')),
    ];
}

function attendance_reporting_legacy_finalized_days_subquery(): string
{
    return "(SELECT day_record.institution_id, day_record.academic_year_id,
                   day_record.batch_id, day_record.attendance_date
            FROM attendance_records day_record
            WHERE day_record.institution_id = ? AND day_record.academic_year_id = ?
            GROUP BY day_record.institution_id, day_record.academic_year_id,
                     day_record.batch_id, day_record.attendance_date
            HAVING COUNT(DISTINCT day_record.student_id) = (
                SELECT COUNT(*) FROM students roster
                WHERE roster.institution_id = day_record.institution_id
                  AND roster.academic_year_id = day_record.academic_year_id
                  AND roster.batch_id = day_record.batch_id
                  AND roster.status IN ('admitted','active')
            )) finalized"
    ;
}

function attendance_reporting_legacy_student_summary(
    mysqli $db,
    int $institutionId,
    int $yearId,
    int $studentId,
    string $dateFrom,
    string $dateTo,
    int $batchId = 0
): array {
    if ($institutionId < 1 || $yearId < 1 || $studentId < 1 || $batchId < 0
        || !attendance_valid_date($dateFrom) || !attendance_valid_date($dateTo) || $dateFrom > $dateTo) {
        throw new InvalidArgumentException('Choose a student, academic year, and valid date range.');
    }
    $holidayCondition = attendance_reporting_holiday_condition('record.attendance_date', 'record.institution_id');
    $batchCondition = $batchId > 0 ? 'AND record.batch_id = ?' : '';
    $statement = $db->prepare(
        "SELECT COUNT(*) AS total_working_days,
                COALESCE(SUM(record.status = 'present'), 0) AS present_count,
                COALESCE(SUM(record.status = 'absent'), 0) AS absent_count,
                COALESCE(SUM(record.status = 'late'), 0) AS late_count,
                0 AS excused_count,
                CASE WHEN COUNT(*) = 0 THEN NULL
                     ELSE ROUND(100.0 * SUM(record.status IN ('present','late')) / COUNT(*), 2)
                END AS attendance_percentage
         FROM attendance_records record
         JOIN " . attendance_reporting_legacy_finalized_days_subquery() . "
           ON finalized.institution_id = record.institution_id
          AND finalized.academic_year_id = record.academic_year_id
          AND finalized.batch_id = record.batch_id
          AND finalized.attendance_date = record.attendance_date
         WHERE record.institution_id = ? AND record.academic_year_id = ?
           AND record.student_id = ? AND record.attendance_date BETWEEN ? AND ?
           AND $holidayCondition $batchCondition"
    );
    if ($batchId > 0) {
        $statement->bind_param('iiiiissi', $institutionId, $yearId, $institutionId, $yearId, $studentId, $dateFrom, $dateTo, $batchId);
    } else {
        $statement->bind_param('iiiiiss', $institutionId, $yearId, $institutionId, $yearId, $studentId, $dateFrom, $dateTo);
    }
    $statement->execute();
    $summary = $statement->get_result()->fetch_assoc() ?: [];
    $statement->close();
    foreach (['total_working_days', 'present_count', 'absent_count', 'late_count', 'excused_count'] as $key) {
        $summary[$key] = (int) ($summary[$key] ?? 0);
    }
    $summary['attendance_percentage'] = $summary['attendance_percentage'] === null
        ? null
        : (float) $summary['attendance_percentage'];
    return $summary;
}

function attendance_reporting_legacy_student_monthly(
    mysqli $db,
    int $institutionId,
    int $yearId,
    int $studentId,
    string $dateFrom,
    string $dateTo,
    int $batchId = 0
): array {
    if ($institutionId < 1 || $yearId < 1 || $studentId < 1 || $batchId < 0
        || !attendance_valid_date($dateFrom) || !attendance_valid_date($dateTo) || $dateFrom > $dateTo) {
        throw new InvalidArgumentException('Choose a student, academic year, and valid date range.');
    }
    $holidayCondition = attendance_reporting_holiday_condition('record.attendance_date', 'record.institution_id');
    $batchCondition = $batchId > 0 ? 'AND record.batch_id = ?' : '';
    $statement = $db->prepare(
        "SELECT DATE_FORMAT(record.attendance_date, '%Y-%m') AS month,
                COUNT(*) AS total_working_days,
                COALESCE(SUM(record.status = 'present'), 0) AS present_count,
                COALESCE(SUM(record.status = 'absent'), 0) AS absent_count,
                COALESCE(SUM(record.status = 'late'), 0) AS late_count,
                0 AS excused_count,
                ROUND(100.0 * SUM(record.status IN ('present','late')) / COUNT(*), 2) AS attendance_percentage
         FROM attendance_records record
         JOIN " . attendance_reporting_legacy_finalized_days_subquery() . "
           ON finalized.institution_id = record.institution_id
          AND finalized.academic_year_id = record.academic_year_id
          AND finalized.batch_id = record.batch_id
          AND finalized.attendance_date = record.attendance_date
         WHERE record.institution_id = ? AND record.academic_year_id = ?
           AND record.student_id = ? AND record.attendance_date BETWEEN ? AND ?
           AND $holidayCondition $batchCondition
         GROUP BY DATE_FORMAT(record.attendance_date, '%Y-%m')
         ORDER BY month"
    );
    if ($batchId > 0) {
        $statement->bind_param('iiiiissi', $institutionId, $yearId, $institutionId, $yearId, $studentId, $dateFrom, $dateTo, $batchId);
    } else {
        $statement->bind_param('iiiiiss', $institutionId, $yearId, $institutionId, $yearId, $studentId, $dateFrom, $dateTo);
    }
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}

function attendance_reporting_legacy_student_records(
    mysqli $db,
    int $institutionId,
    int $yearId,
    int $studentId,
    string $dateFrom,
    string $dateTo,
    int $page = 1,
    int $perPage = 20,
    int $batchId = 0
): array {
    if ($institutionId < 1 || $yearId < 1 || $studentId < 1 || $batchId < 0
        || !attendance_valid_date($dateFrom) || !attendance_valid_date($dateTo) || $dateFrom > $dateTo
        || $page < 1 || $perPage < 1 || $perPage > 100) {
        throw new InvalidArgumentException('Choose a valid attendance history page.');
    }
    $holidayCondition = attendance_reporting_holiday_condition('record.attendance_date', 'record.institution_id');
    $batchCondition = $batchId > 0 ? 'AND record.batch_id = ?' : '';
    $base = ' FROM attendance_records record
              JOIN ' . attendance_reporting_legacy_finalized_days_subquery() . '
                ON finalized.institution_id = record.institution_id
               AND finalized.academic_year_id = record.academic_year_id
               AND finalized.batch_id = record.batch_id
               AND finalized.attendance_date = record.attendance_date
              JOIN batches batch ON batch.institution_id = record.institution_id AND batch.id = record.batch_id
              JOIN classes c ON c.institution_id = batch.institution_id AND c.id = batch.class_id
              WHERE record.institution_id = ? AND record.academic_year_id = ?
                AND record.student_id = ? AND record.attendance_date BETWEEN ? AND ?
                AND ' . $holidayCondition . ' ' . $batchCondition;
    $count = $db->prepare('SELECT COUNT(*)' . $base);
    if ($batchId > 0) {
        $count->bind_param('iiiiissi', $institutionId, $yearId, $institutionId, $yearId, $studentId, $dateFrom, $dateTo, $batchId);
    } else {
        $count->bind_param('iiiiiss', $institutionId, $yearId, $institutionId, $yearId, $studentId, $dateFrom, $dateTo);
    }
    $count->execute();
    $total = (int) $count->get_result()->fetch_column();
    $count->close();
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    $statement = $db->prepare(
        'SELECT record.attendance_date, record.status,
                CONCAT(c.name, " / ", batch.name) AS placement_name
         ' . $base . '
         ORDER BY record.attendance_date DESC, record.id DESC
         LIMIT ? OFFSET ?'
    );
    if ($batchId > 0) {
        $statement->bind_param(
            'iiiiissiii',
            $institutionId,
            $yearId,
            $institutionId,
            $yearId,
            $studentId,
            $dateFrom,
            $dateTo,
            $batchId,
            $perPage,
            $offset
        );
    } else {
        $statement->bind_param(
            'iiiiissii',
            $institutionId,
            $yearId,
            $institutionId,
            $yearId,
            $studentId,
            $dateFrom,
            $dateTo,
            $perPage,
            $offset
        );
    }
    $statement->execute();
    $records = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return [
        'records' => $records,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => $totalPages,
    ];
}

function attendance_reporting_legacy_shortage(
    mysqli $db,
    int $institutionId,
    int $yearId,
    int $batchId,
    string $dateFrom,
    string $dateTo,
    array $thresholds
): array {
    if ($institutionId < 1 || $yearId < 1 || $batchId < 0
        || !attendance_valid_date($dateFrom) || !attendance_valid_date($dateTo) || $dateFrom > $dateTo) {
        throw new InvalidArgumentException('Choose a valid academic year, batch, and date range.');
    }
    $batchCondition = $batchId > 0 ? 'AND record.batch_id = ?' : '';
    $holidayCondition = attendance_reporting_holiday_condition('record.attendance_date', 'record.institution_id');
    $statement = $db->prepare(
        "SELECT s.id AS student_id, s.student_code, s.full_name,
                record.batch_id AS academic_group_id,
                CONCAT(c.name, ' / ', batch.name) AS placement_name,
                COUNT(*) AS total_working_days,
                COALESCE(SUM(record.status = 'present'), 0) AS present_count,
                COALESCE(SUM(record.status = 'absent'), 0) AS absent_count,
                COALESCE(SUM(record.status = 'late'), 0) AS late_count,
                0 AS excused_count,
                ROUND(100.0 * SUM(record.status IN ('present','late')) / COUNT(*), 2) AS attendance_percentage
         FROM attendance_records record
         JOIN " . attendance_reporting_legacy_finalized_days_subquery() . "
           ON finalized.institution_id = record.institution_id
          AND finalized.academic_year_id = record.academic_year_id
          AND finalized.batch_id = record.batch_id
          AND finalized.attendance_date = record.attendance_date
         JOIN students s ON s.institution_id = record.institution_id AND s.id = record.student_id
         JOIN batches batch ON batch.institution_id = record.institution_id AND batch.id = record.batch_id
         JOIN classes c ON c.institution_id = batch.institution_id AND c.id = batch.class_id
         WHERE record.institution_id = ? AND record.academic_year_id = ?
           AND record.attendance_date BETWEEN ? AND ? AND $holidayCondition $batchCondition
         GROUP BY s.id, s.student_code, s.full_name, record.batch_id, c.name, batch.name
         ORDER BY attendance_percentage, s.full_name, s.student_code"
    );
    if ($batchId > 0) {
        $statement->bind_param('iiiissi', $institutionId, $yearId, $institutionId, $yearId, $dateFrom, $dateTo, $batchId);
    } else {
        $statement->bind_param('iiiiss', $institutionId, $yearId, $institutionId, $yearId, $dateFrom, $dateTo);
    }
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    foreach ($rows as &$row) {
        $row['total_working_days'] = (int) $row['total_working_days'];
        $row['present_count'] = (int) $row['present_count'];
        $row['absent_count'] = (int) $row['absent_count'];
        $row['late_count'] = (int) $row['late_count'];
        $row['excused_count'] = 0;
        $row['attendance_percentage'] = (float) $row['attendance_percentage'];
        $row['status'] = attendance_reporting_threshold_status($row['attendance_percentage'], $thresholds);
    }
    unset($row);
    return $rows;
}

function attendance_reporting_legacy_monthly(
    mysqli $db,
    int $institutionId,
    int $yearId,
    int $batchId,
    string $dateFrom,
    string $dateTo
): array {
    if ($institutionId < 1 || $yearId < 1 || $batchId < 0
        || !attendance_valid_date($dateFrom) || !attendance_valid_date($dateTo) || $dateFrom > $dateTo) {
        throw new InvalidArgumentException('Choose a valid academic year, batch, and date range.');
    }
    $batchCondition = $batchId > 0 ? 'AND record.batch_id = ?' : '';
    $holidayCondition = attendance_reporting_holiday_condition('record.attendance_date', 'record.institution_id');
    $statement = $db->prepare(
        "SELECT DATE_FORMAT(record.attendance_date, '%Y-%m') AS month,
                record.batch_id AS academic_group_id,
                CONCAT(c.name, ' / ', batch.name) AS placement_name,
                COUNT(DISTINCT record.attendance_date) AS total_working_days,
                COUNT(*) AS recorded_count,
                COALESCE(SUM(record.status = 'present'), 0) AS present_count,
                COALESCE(SUM(record.status = 'absent'), 0) AS absent_count,
                COALESCE(SUM(record.status = 'late'), 0) AS late_count,
                0 AS excused_count,
                ROUND(100.0 * SUM(record.status IN ('present','late')) / COUNT(*), 2) AS attendance_percentage
         FROM attendance_records record
         JOIN " . attendance_reporting_legacy_finalized_days_subquery() . "
           ON finalized.institution_id = record.institution_id
          AND finalized.academic_year_id = record.academic_year_id
          AND finalized.batch_id = record.batch_id
          AND finalized.attendance_date = record.attendance_date
         JOIN batches batch ON batch.institution_id = record.institution_id AND batch.id = record.batch_id
         JOIN classes c ON c.institution_id = batch.institution_id AND c.id = batch.class_id
         WHERE record.institution_id = ? AND record.academic_year_id = ?
           AND record.attendance_date BETWEEN ? AND ? AND $holidayCondition $batchCondition
         GROUP BY DATE_FORMAT(record.attendance_date, '%Y-%m'), record.batch_id, c.name, batch.name
         ORDER BY month, placement_name"
    );
    if ($batchId > 0) {
        $statement->bind_param('iiiissi', $institutionId, $yearId, $institutionId, $yearId, $dateFrom, $dateTo, $batchId);
    } else {
        $statement->bind_param('iiiiss', $institutionId, $yearId, $institutionId, $yearId, $dateFrom, $dateTo);
    }
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}
