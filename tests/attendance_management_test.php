<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/attendance-reporting-service.php';

$checks = 0;
$failures = [];
$check = static function (bool $condition, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $label;
    }
};

$validMarks = attendance_validate_mark_input([
    'academic_year_id' => '4',
    'batch_id' => '9',
    'attendance_date' => '2026-10-14',
    'attendance' => ['12' => 'present', '18' => 'late'],
]);
$check(
    $validMarks['academic_year_id'] === 4
        && $validMarks['batch_id'] === 9
        && $validMarks['attendance'] === [12 => 'present', 18 => 'late'],
    'Validate selected cohort and normalize student attendance statuses'
);
$sessionMarks = attendance_validate_session_mark_input([
    'academic_year_id' => '4',
    'academic_group_id' => '12',
    'attendance_date' => '2026-10-14',
    'attendance' => ['18' => 'excused', '12' => 'present'],
]);
$check(
    $sessionMarks['academic_group_id'] === 12
        && $sessionMarks['attendance'] === [12 => 'present', 18 => 'excused'],
    'Validate section/term/batch session attendance and excused statuses'
);
$invalidSessionMarksRejected = false;
try {
    attendance_validate_session_mark_input([
        'academic_year_id' => '4',
        'academic_group_id' => '12',
        'attendance_date' => '2026-10-14',
        'attendance' => ['18' => 'unknown'],
    ]);
} catch (InvalidArgumentException) {
    $invalidSessionMarksRejected = true;
}
$check($invalidSessionMarksRejected, 'Reject unsupported session attendance statuses');
$check(attendance_percentage(2, 3) === 66.67, 'Calculate student attendance percentage with two-decimal precision');
$check(attendance_percentage(0, 0) === null, 'Leave attendance percentage unavailable with no recorded sessions');

foreach ([
    ['academic_year_id' => '4', 'batch_id' => '9', 'attendance_date' => '2026-02-30', 'attendance' => ['12' => 'present']],
    ['academic_year_id' => '0', 'batch_id' => '9', 'attendance_date' => '2026-10-14', 'attendance' => ['12' => 'present']],
    ['academic_year_id' => '4', 'batch_id' => '9', 'attendance_date' => '2026-10-14', 'attendance' => []],
    ['academic_year_id' => '4', 'batch_id' => '9', 'attendance_date' => '2026-10-14', 'attendance' => ['0' => 'present']],
    ['academic_year_id' => '4', 'batch_id' => '9', 'attendance_date' => '2026-10-14', 'attendance' => ['12' => 'excused']],
    ['academic_year_id' => '4', 'batch_id' => '9', 'attendance_date' => '2026-10-14', 'attendance' => ['12' => ['present']]],
    ['academic_year_id' => ['4'], 'batch_id' => '9', 'attendance_date' => '2026-10-14', 'attendance' => ['12' => 'present']],
] as $input) {
    $rejected = false;
    try {
        attendance_validate_mark_input($input);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    $check($rejected, 'Reject invalid attendance selection or status');
}

$history = attendance_validate_history_filters([
    'class_id' => '3',
    'batch_id' => '9',
    'student_query' => 'STU-12',
    'date_from' => '2026-10-01',
    'date_to' => '2026-10-14',
]);
$check($history['class_id'] === 3 && $history['student_query'] === 'STU-12', 'Normalize institution-filtered history selectors');

$invalidRangeRejected = false;
try {
    attendance_validate_history_filters([
        'class_id' => '0',
        'batch_id' => '0',
        'student_id' => '0',
        'date_from' => '2026-10-15',
        'date_to' => '2026-10-14',
    ]);
} catch (InvalidArgumentException) {
    $invalidRangeRejected = true;
}
$check($invalidRangeRejected, 'Reject reversed history date ranges');

$parentFilters = attendance_validate_parent_filters([
    'date_from' => '2026-10-01',
    'date_to' => '2026-10-14',
    'status' => 'late',
    'page' => '2',
]);
$check(
    $parentFilters === [
        'date_from' => '2026-10-01',
        'date_to' => '2026-10-14',
        'status' => 'late',
        'page' => 2,
    ],
    'Validate parent date, status, and pagination filters'
);
$invalidParentFiltersRejected = false;
try {
    attendance_validate_parent_filters([
        'date_from' => '2026-10-15',
        'date_to' => '2026-10-14',
        'status' => 'excused',
        'page' => '0',
    ]);
} catch (InvalidArgumentException) {
    $invalidParentFiltersRejected = true;
}
$check($invalidParentFiltersRejected, 'Reject invalid parent status and reversed date filters');
$excusedParentFilter = attendance_validate_parent_filters(['status' => 'excused']);
$check($excusedParentFilter['status'] === 'excused', 'Allow parents to filter excused session attendance');

$reportFilters = attendance_reporting_validate_filters([
    'report' => 'shortage',
    'academic_year_id' => '8',
    'academic_group_id' => '14',
    'student_id' => '0',
    'date_from' => '2026-09-01',
    'date_to' => '2026-10-14',
    'page' => '2',
]);
$check(
    $reportFilters['academic_year_id'] === 8
        && $reportFilters['academic_group_id'] === 14
        && $reportFilters['page'] === 2,
    'Validate and normalize report cohort, academic year, and pagination filters'
);
$invalidReportRejected = false;
try {
    attendance_reporting_validate_filters([
        'report' => 'all_students',
        'academic_year_id' => '8',
        'date_from' => '2026-10-15',
        'date_to' => '2026-10-14',
    ]);
} catch (InvalidArgumentException) {
    $invalidReportRejected = true;
}
$check($invalidReportRejected, 'Reject unsupported report types and reversed date ranges');
$thresholds = attendance_reporting_validate_thresholds([
    'warning_threshold' => '60',
    'good_threshold' => '75',
]);
$check($thresholds === ['warning' => 60, 'good' => 75], 'Validate configurable attendance shortage thresholds');
$invalidThresholdsRejected = false;
try {
    attendance_reporting_validate_thresholds([
        'warning_threshold' => '80',
        'good_threshold' => '75',
    ]);
} catch (InvalidArgumentException) {
    $invalidThresholdsRejected = true;
}
$check($invalidThresholdsRejected, 'Reject inconsistent shortage thresholds');
$check(
    attendance_reporting_percentage(5, 2, 10, 1) === 77.78
        && attendance_reporting_percentage(0, 0, 1, 1) === null,
    'Exclude excused records from attendance percentages and handle no assessable days'
);
$invalidReportTotalsRejected = false;
try {
    attendance_reporting_percentage(2, 2, 3, 0);
} catch (InvalidArgumentException) {
    $invalidReportTotalsRejected = true;
}
$check($invalidReportTotalsRejected, 'Reject attendance summaries with impossible recorded totals');
$check(
    attendance_reporting_threshold_status(75.0, $thresholds) === 'good'
        && attendance_reporting_threshold_status(60.0, $thresholds) === 'warning'
        && attendance_reporting_threshold_status(59.99, $thresholds) === 'critical',
    'Classify attendance exactly at configured threshold boundaries'
);
$consistentlyPoor = attendance_reporting_detect_consistently_poor([
    ['student_id' => 3, 'month' => '2026-01', 'percentage' => 59.0],
    ['student_id' => 3, 'month' => '2026-02', 'percentage' => 59.0],
    ['student_id' => 3, 'month' => '2026-03', 'percentage' => 59.0],
    ['student_id' => 3, 'month' => '2026-04', 'percentage' => 80.0],
    ['student_id' => 4, 'month' => '2026-01', 'percentage' => 59.0],
    ['student_id' => 4, 'month' => '2026-03', 'percentage' => 59.0],
    ['student_id' => 4, 'month' => '2026-04', 'percentage' => 59.0],
], 60);
$check($consistentlyPoor === [3], 'Identify three consecutive low-attendance months without bridging missing months');
$check(
    attendance_reporting_csv_value('=1+1') === "'=1+1"
        && attendance_reporting_csv_value(" \t@SUM(A1)") === "' \t@SUM(A1)"
        && attendance_reporting_csv_value('-42') === '-42'
        && attendance_reporting_csv_value('Student, One') === 'Student, One',
    'Neutralize spreadsheet formula injection without altering numeric values'
);
$csvStream = fopen('php://temp', 'w+');
if ($csvStream === false) {
    throw new RuntimeException('CSV test stream could not be opened.');
}
fputcsv(
    $csvStream,
    [attendance_reporting_csv_value('=1+1'), attendance_reporting_csv_value('Student, One')],
    ',',
    '"',
    ''
);
rewind($csvStream);
$csvLine = fgets($csvStream);
fclose($csvStream);
$check(
    is_string($csvLine) && str_contains($csvLine, "'=1+1") && str_contains($csvLine, '"Student, One"'),
    'Quote exported CSV fields and retain spreadsheet-safe cell values'
);

$service = file_get_contents(__DIR__ . '/../services/attendance-management-service.php');
$reportingService = file_get_contents(__DIR__ . '/../services/attendance-reporting-service.php');
$route = file_get_contents(__DIR__ . '/../admin/academics/attendance.php');
$reportRoute = file_get_contents(__DIR__ . '/../admin/academics/attendance-reports.php');
$parentRoute = file_get_contents(__DIR__ . '/../parent/attendance.php');
$schema = file_get_contents(__DIR__ . '/../database/schema.sql');
$migration = file_get_contents(__DIR__ . '/../database/migrations/20261014_add_attendance_records.sql');
$sidebar = file_get_contents(__DIR__ . '/../includes/layouts/sidebar.php');
$auth = file_get_contents(__DIR__ . '/../includes/auth.php');
$readable = is_string($service) && is_string($reportingService) && is_string($route)
    && is_string($reportRoute) && is_string($auth) && is_string($parentRoute) && is_string($schema)
    && is_string($migration) && is_string($sidebar);
$check($readable, 'Read attendance service, route, schema, migration, and navigation');

if ($readable) {
    $check(
        str_contains($route, 'require_placeholder_route_access()')
            && str_contains($route, 'require_valid_csrf_token()')
            && str_contains($route, 'admin_panel_institution_id()'),
        'Require existing admin authorization, CSRF, and current institution context'
    );
    $check(
        str_contains($schema, 'UNIQUE KEY uq_attendance_student_batch_date')
            && str_contains($migration, 'UNIQUE KEY uq_attendance_student_batch_date')
            && str_contains($service, 'SELECT id, student_id, status')
            && str_contains($service, "'attendance.created'")
            && str_contains($service, "'attendance.corrected'"),
        'Prevent duplicate student/batch/date records and audit creates and corrections'
    );
    $check(
        str_contains($service, 's.institution_id = ? AND s.academic_year_id = ? AND s.batch_id = ?')
            && str_contains($service, 's.class_id = b.class_id')
            && str_contains($service, "s.status IN ('admitted','active')")
            && str_contains($service, 'FOR UPDATE'),
        'Validate enrolled students and serialize attendance writes within the selected institution and batch'
    );
    $check(
        str_contains($service, 'ar.institution_id = ?')
            && str_contains($service, 'b.institution_id = ?')
            && str_contains($service, 'ROUND(100.0')
            && str_contains($route, 'Class attendance report'),
        'Scope history and reports to the institution and calculate per-student/class attendance percentages'
    );
    $check(
        str_contains($schema, 'CREATE TABLE IF NOT EXISTS attendance_records')
            && str_contains($migration, 'CREATE TABLE attendance_records')
            && str_contains($schema, 'UNIQUE KEY uq_attendance_student_batch_date')
            && str_contains($migration, 'UNIQUE KEY uq_attendance_student_batch_date'),
        'Keep schema and safe additive migration aligned with database uniqueness'
    );
    $check(
        str_contains($sidebar, "'Attendance', '/admin/academics/attendance'")
            && !str_contains($service, 'payments')
            && !str_contains($route, 'Razorpay'),
        'Add admin navigation without coupling attendance to payment processing'
    );
    $check(
        str_contains($parentRoute, 'parent_portal_require_current_parent($db)')
            && str_contains($service, 'psr.parent_id = ?')
            && str_contains($service, 'psr.can_view_academics = 1')
            && str_contains($service, 'ar.institution_id = ?')
            && str_contains($service, 'attendance_session_records')
            && str_contains($service, 'academic_group_name')
            && str_contains($parentRoute, "'excused'")
            && str_contains($parentRoute, 'attendance_parent_list_records('),
        'Show legacy and academic-session attendance only to the authenticated parent with academic access'
    );
    $check(
        str_contains($reportingService, "session.status = 'closed'")
            && str_contains($reportingService, 'attendance_reporting_holiday_condition')
            && str_contains($reportingService, 'WHERE holiday.institution_id = $institutionExpression')
            && str_contains($reportRoute, 'admin_panel_institution_id()'),
        'Count only institution-scoped finalized attendance and exclude active holidays'
    );
    $check(
        str_contains($reportRoute, 'attendance_reporting_close_session(')
            && str_contains($service, 'Finalized or cancelled attendance cannot be changed.')
            && str_contains($reportingService, 'attendance_reporting_legacy_finalized_days_subquery'),
        'Finalize complete session registers and preserve finalized legacy attendance history'
    );
    $check(
        str_contains($auth, "'attendance.export', 'academics.manage'")
            && str_contains($auth, "'institution_settings.edit', 'settings.manage'")
            && str_contains($reportRoute, 'attendance.export')
            && str_contains($reportRoute, 'require_valid_csrf_token()'),
        'Protect report export and threshold/finalization actions with existing permissions and CSRF'
    );
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "{$checks} attendance management checks passed." . PHP_EOL);
