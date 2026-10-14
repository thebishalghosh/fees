<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/attendance-management-service.php';

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

$service = file_get_contents(__DIR__ . '/../services/attendance-management-service.php');
$route = file_get_contents(__DIR__ . '/../admin/academics/attendance.php');
$parentRoute = file_get_contents(__DIR__ . '/../parent/attendance.php');
$schema = file_get_contents(__DIR__ . '/../database/schema.sql');
$migration = file_get_contents(__DIR__ . '/../database/migrations/20261014_add_attendance_records.sql');
$sidebar = file_get_contents(__DIR__ . '/../includes/layouts/sidebar.php');
$readable = is_string($service) && is_string($route) && is_string($parentRoute) && is_string($schema)
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
            && str_contains($parentRoute, 'attendance_parent_list_records('),
        'Scope parent attendance reads by authenticated parent, academic permission, student, and institution'
    );
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "{$checks} attendance management checks passed." . PHP_EOL);
