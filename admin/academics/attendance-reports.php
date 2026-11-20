<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/admin-panel-service.php';
require_once __DIR__ . '/../../services/attendance-reporting-service.php';

require_http_methods(['GET', 'POST']);
require_placeholder_route_access();
$db = db_connect();
$institutionId = admin_panel_institution_id();
if (!academic_management_schema_ready($db)) {
    academic_management_render_schema_required_page();
    exit;
}
$user = current_user();
$configuration = academic_management_configuration($db, $institutionId);
$sessionMode = is_string($configuration['academic_model']);
$errors = [];
$years = academic_management_list_years($db, $institutionId);
$activeYearId = $configuration['active_academic_year_id'];
$selectedYearId = attendance_parse_id(
    $_REQUEST['academic_year_id'] ?? (string) ($activeYearId ?? ($years[0]['id'] ?? 0))
);
$selectedYearId = $selectedYearId === null || $selectedYearId < 1 ? 0 : $selectedYearId;
$year = null;
foreach ($years as $yearOption) {
    if ((int) $yearOption['id'] === $selectedYearId) {
        $year = $yearOption;
        break;
    }
}
if ($year === null && $years !== []) {
    $year = $years[0];
    $selectedYearId = (int) $year['id'];
}
$today = date('Y-m-d');
$defaultFrom = is_array($year) ? (string) $year['start_date'] : date('Y-m-01');
$defaultTo = is_array($year)
    ? min($today, (string) $year['end_date'])
    : $today;
if ($defaultTo < $defaultFrom) {
    $defaultFrom = $defaultTo = (string) ($year['start_date'] ?? $today);
}
$reportType = is_string($_REQUEST['report'] ?? null) ? $_REQUEST['report'] : 'daily';
$defaultFrom = $reportType === 'daily' ? $defaultTo : $defaultFrom;
$defaultTo = $reportType === 'daily' ? $defaultFrom : $defaultTo;
$rawFilters = [
    'report' => $reportType,
    'academic_year_id' => (string) $selectedYearId,
    'academic_group_id' => $_REQUEST['academic_group_id'] ?? '0',
    'student_id' => $_REQUEST['student_id'] ?? '0',
    'date_from' => $_REQUEST['date_from'] ?? $defaultFrom,
    'date_to' => $_REQUEST['date_to'] ?? $defaultTo,
    'page' => $_REQUEST['page'] ?? '1',
];
try {
    $filters = attendance_reporting_validate_filters($rawFilters);
} catch (InvalidArgumentException $exception) {
    $errors[] = $exception->getMessage();
    $fallbackDate = (string) ($year['start_date'] ?? $today);
    $filters = attendance_reporting_validate_filters([
        'report' => 'daily',
        'academic_year_id' => (string) ($year['id'] ?? 0),
        'academic_group_id' => '0',
        'student_id' => '0',
        'date_from' => $fallbackDate,
        'date_to' => $fallbackDate,
        'page' => '1',
    ]);
}
$selectedYearId = $filters['academic_year_id'];
$year = null;
foreach ($years as $yearOption) {
    if ((int) $yearOption['id'] === $selectedYearId) {
        $year = $yearOption;
        break;
    }
}
if ($year === null) {
    $errors[] = 'Select an academic year belonging to this institution.';
}
if ($year !== null
    && ($filters['date_from'] < (string) $year['start_date']
        || $filters['date_to'] > (string) $year['end_date'])) {
    $errors[] = 'Report dates must fall within the selected academic year.';
}

$groups = $sessionMode
    ? attendance_reporting_group_options($db, $institutionId, $selectedYearId)
    : attendance_reporting_legacy_batch_options($db, $institutionId);
$groupId = $filters['academic_group_id'];
if ($sessionMode) {
    $groups = array_values(array_filter(
        $groups,
        static fn(array $group): bool => (int) $group['id'] > 0
    ));
} else {
    $groups = array_values(array_filter(
        $groups,
        static fn(array $group): bool => (int) $group['id'] > 0
    ));
}
$groupFound = $groupId === 0;
foreach ($groups as $group) {
    if ((int) $group['id'] === $groupId) {
        $groupFound = true;
        break;
    }
}
if (!$groupFound) {
    $errors[] = 'The selected class, section, or batch is not available in this institution.';
    $groupId = 0;
    $filters['academic_group_id'] = 0;
}
$students = $sessionMode
    ? attendance_reporting_student_options($db, $institutionId, $selectedYearId, $groupId)
    : attendance_reporting_legacy_student_options($db, $institutionId, $selectedYearId, $groupId);
$studentFound = $filters['student_id'] === 0;
$selectedStudentName = '';
foreach ($students as $studentOption) {
    if ((int) $studentOption['id'] === $filters['student_id']) {
        $studentFound = true;
        $selectedStudentName = (string) $studentOption['full_name'];
    }
}
if (!$studentFound) {
    $errors[] = 'The selected student is not enrolled in this institution and academic year.';
    $filters['student_id'] = 0;
}
$thresholds = attendance_reporting_get_thresholds($db, $institutionId);
$canConfigureThresholds = user_has_compatible_permission('institution_settings.edit', 'settings.manage');
$canEditAttendance = user_has_compatible_permission('attendance.edit', 'academics.manage');
$canExportAttendance = user_has_compatible_permission('attendance.export', 'academics.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    try {
        if (($_POST['action'] ?? null) === 'save_thresholds') {
            if (!$canConfigureThresholds) {
                http_response_code(403);
                exit('Forbidden.');
            }
            $thresholds = attendance_reporting_save_thresholds(
                $db,
                $institutionId,
                (int) $user['id'],
                $_POST
            );
            set_flash('success', 'Attendance shortage thresholds updated.');
            safe_redirect('/admin/academics/attendance-reports?report=shortage', 303);
        }
        if (($_POST['action'] ?? null) === 'finalize_session') {
            if (!$canEditAttendance) {
                http_response_code(403);
                exit('Forbidden.');
            }
            if (!$sessionMode) {
                throw new DomainException('Legacy batch attendance is finalized automatically when every enrolled student has a record.');
            }
            attendance_reporting_close_session(
                $db,
                $institutionId,
                (int) $user['id'],
                (int) ($_POST['academic_year_id'] ?? 0),
                (int) ($_POST['academic_group_id'] ?? 0),
                (string) ($_POST['attendance_date'] ?? '')
            );
            set_flash('success', 'Attendance finalized. Its records are now included in attendance analytics.');
            safe_redirect(
                '/admin/academics/attendance-reports?' . http_build_query([
                    'report' => 'daily',
                    'academic_year_id' => (int) ($_POST['academic_year_id'] ?? 0),
                    'academic_group_id' => (int) ($_POST['academic_group_id'] ?? 0),
                    'date_from' => (string) ($_POST['attendance_date'] ?? ''),
                    'date_to' => (string) ($_POST['attendance_date'] ?? ''),
                ]),
                303
            );
        }
        throw new InvalidArgumentException('Choose a valid attendance report action.');
    } catch (InvalidArgumentException | DomainException | OutOfBoundsException $exception) {
        $errors[] = $exception->getMessage();
    } catch (Throwable $exception) {
        log_error('attendance_report_action_failed', [
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
        ]);
        $errors[] = 'The attendance report action could not be completed.';
    }
}

$daily = null;
$studentSummary = null;
$studentMonthly = [];
$studentRecords = ['records' => [], 'total' => 0, 'page' => 1, 'per_page' => 20, 'total_pages' => 1];
$reportRows = [];
$consistentlyPoorStudentIds = [];
if ($errors === [] && $year !== null) {
    try {
        if ($filters['report'] === 'daily' && $groupId > 0) {
            $daily = $sessionMode
                ? attendance_reporting_get_daily(
                    $db,
                    $institutionId,
                    $selectedYearId,
                    $groupId,
                    $filters['date_from']
                )
                : attendance_reporting_legacy_daily(
                    $db,
                    $institutionId,
                    $selectedYearId,
                    $groupId,
                    $filters['date_from']
                );
        } elseif ($filters['report'] === 'student' && $filters['student_id'] > 0) {
            $studentSummary = $sessionMode
                ? attendance_reporting_student_summary(
                    $db,
                    $institutionId,
                    $selectedYearId,
                    $filters['student_id'],
                    $filters['date_from'],
                    $filters['date_to'],
                    $groupId
                )
                : attendance_reporting_legacy_student_summary(
                    $db,
                    $institutionId,
                    $selectedYearId,
                    $filters['student_id'],
                    $filters['date_from'],
                    $filters['date_to'],
                    $groupId
                );
            $studentMonthly = $sessionMode
                ? attendance_reporting_student_monthly(
                    $db,
                    $institutionId,
                    $selectedYearId,
                    $filters['student_id'],
                    $filters['date_from'],
                    $filters['date_to']
                )
                : attendance_reporting_legacy_student_monthly(
                    $db,
                    $institutionId,
                    $selectedYearId,
                    $filters['student_id'],
                    $filters['date_from'],
                    $filters['date_to']
                );
            $studentRecords = $sessionMode
                ? attendance_reporting_student_records(
                    $db,
                    $institutionId,
                    $selectedYearId,
                    $filters['student_id'],
                    $filters['date_from'],
                    $filters['date_to'],
                    $filters['page'],
                    20,
                    $groupId
                )
                : attendance_reporting_legacy_student_records(
                    $db,
                    $institutionId,
                    $selectedYearId,
                    $filters['student_id'],
                    $filters['date_from'],
                    $filters['date_to'],
                    $filters['page'],
                    20,
                    $groupId
                );
        } elseif ($filters['report'] === 'shortage') {
            $reportRows = $sessionMode
                ? attendance_reporting_shortage(
                    $db,
                    $institutionId,
                    $selectedYearId,
                    $groupId,
                    $filters['date_from'],
                    $filters['date_to'],
                    $thresholds
                )
                : attendance_reporting_legacy_shortage(
                    $db,
                    $institutionId,
                    $selectedYearId,
                    $groupId,
                    $filters['date_from'],
                    $filters['date_to'],
                    $thresholds
                );
            $consistentlyPoorStudentIds = attendance_reporting_consistently_poor_students(
                $db,
                $institutionId,
                $selectedYearId,
                $groupId,
                $filters['date_from'],
                $filters['date_to'],
                $thresholds['warning'],
                $sessionMode
            );
        } elseif ($filters['report'] === 'monthly') {
            $reportRows = $sessionMode
                ? attendance_reporting_monthly(
                    $db,
                    $institutionId,
                    $selectedYearId,
                    $groupId,
                    $filters['date_from'],
                    $filters['date_to']
                )
                : attendance_reporting_legacy_monthly(
                    $db,
                    $institutionId,
                    $selectedYearId,
                    $groupId,
                    $filters['date_from'],
                    $filters['date_to']
                );
        }
    } catch (InvalidArgumentException | DomainException | OutOfBoundsException $exception) {
        $errors[] = $exception->getMessage();
    } catch (Throwable $exception) {
        log_error('attendance_report_load_failed', [
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
        ]);
        $errors[] = 'Attendance report data could not be loaded.';
    }
}

if (($_GET['export'] ?? null) === 'csv') {
    if (!user_has_compatible_permission('attendance.export', 'academics.manage')) {
        http_response_code(403);
        exit('Forbidden.');
    }
    if ($errors !== []) {
        http_response_code(400);
        exit(e(implode(' ', $errors)));
    }
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('The attendance CSV export could not be opened.');
    }
    $writeRow = static function (array $row) use ($output): void {
        $safe = array_map('attendance_reporting_csv_value', $row);
        if (fputcsv($output, $safe, ',', '"', '') === false) {
            throw new RuntimeException('An attendance CSV row could not be written.');
        }
    };
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="attendance-' . $filters['report'] . '-' . date('Ymd') . '.csv"');
    $writeRow(['Report', 'Academic year', 'Placement', 'Date / month', 'Student', 'Student ID', 'Enrolled / working days', 'Present', 'Absent', 'Late', 'Excused', 'Attendance %', 'Status']);
    $yearName = (string) ($year['name'] ?? '');
    $placementLabel = '';
    foreach ($groups as $group) {
        if ((int) $group['id'] === $groupId) {
            $placementLabel = (string) ($group['label'] ?? ($group['class_name'] . ' / ' . $group['batch_name']));
            break;
        }
    }
    if ($filters['report'] === 'daily' && is_array($daily)) {
        $writeRow([
            'Daily', $yearName, $placementLabel, $daily['date'], '', '',
            $daily['enrolled_count'], $daily['present_count'], $daily['absent_count'],
            $daily['late_count'], $daily['excused_count'],
            $daily['attendance_percentage'] ?? '', $daily['submission_status'],
        ]);
    } elseif ($filters['report'] === 'student' && is_array($studentSummary)) {
        $page = 1;
        do {
            $recordsPage = $sessionMode
                ? attendance_reporting_student_records(
                    $db, $institutionId, $selectedYearId, $filters['student_id'],
                    $filters['date_from'], $filters['date_to'], $page, 100, $groupId
                )
                : attendance_reporting_legacy_student_records(
                    $db, $institutionId, $selectedYearId, $filters['student_id'],
                    $filters['date_from'], $filters['date_to'], $page, 100, $groupId
                );
            foreach ($recordsPage['records'] as $record) {
                $writeRow([
                    'Student', $yearName, $record['placement_name'], $record['attendance_date'],
                    $selectedStudentName, $filters['student_id'], '', $record['status'] === 'present' ? 1 : 0,
                    $record['status'] === 'absent' ? 1 : 0, $record['status'] === 'late' ? 1 : 0,
                    $record['status'] === 'excused' ? 1 : 0, '', $record['status'],
                ]);
            }
            $page++;
        } while ($page <= $recordsPage['total_pages']);
    } else {
        foreach ($reportRows as $row) {
            if ($filters['report'] === 'shortage'
                && !in_array($row['status'], ['warning', 'critical'], true)) {
                continue;
            }
            $writeRow([
                ucfirst($filters['report']), $yearName, $row['placement_name'] ?? $placementLabel,
                $row['month'] ?? ($filters['date_from'] . ' to ' . $filters['date_to']),
                $row['full_name'] ?? '', $row['student_code'] ?? '',
                $row['total_working_days'], $row['present_count'], $row['absent_count'],
                $row['late_count'], $row['excused_count'], $row['attendance_percentage'],
                ($row['status'] ?? '')
                    . (in_array((int) ($row['student_id'] ?? 0), $consistentlyPoorStudentIds, true)
                        ? ' (consistently low for 3+ months)'
                        : ''),
            ]);
        }
    }
    fclose($output);
    exit;
}

$queryFilters = $filters;
$queryFilters['page'] = $studentRecords['page'];
$exportUrl = route_url('/admin/academics/attendance-reports') . '?' . http_build_query([
    ...$queryFilters,
    'export' => 'csv',
]);
$tabUrl = static function (string $report) use ($filters): string {
    return route_url('/admin/academics/attendance-reports') . '?' . http_build_query([
        ...$filters,
        'report' => $report,
        'page' => 1,
    ]);
};

render_app_page_start(
    'Attendance Reports & Analytics',
    'Institution-scoped attendance insights based on finalized attendance days.',
    'admin',
    ['admin-dashboard.css', 'attendance-management.css']
);
?>
<?php echo render_flash_messages(); ?>
<?php foreach ($errors as $error): ?><div class="alert alert-danger attendance-alert" role="alert"><?php echo e($error); ?></div><?php endforeach; ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb attendance-breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/academics/attendance')); ?>">Attendance</a></li><li class="breadcrumb-item active" aria-current="page">Reports & analytics</li></ol></nav>
<nav class="attendance-tabs" aria-label="Attendance sections">
    <a href="<?php echo e(route_url('/admin/academics/attendance')); ?>"><i class="bi bi-pencil-square" aria-hidden="true"></i>Attendance register</a>
    <a class="is-active" href="<?php echo e(route_url('/admin/academics/attendance-reports')); ?>"><i class="bi bi-bar-chart-line" aria-hidden="true"></i>Reports & analytics</a>
</nav>
<nav class="attendance-tabs" aria-label="Attendance report types">
    <?php foreach (['daily' => 'Daily', 'student' => 'Student history', 'shortage' => 'Shortage', 'monthly' => 'Monthly analytics'] as $reportKey => $reportLabel): ?>
        <a class="<?php echo $filters['report'] === $reportKey ? 'is-active' : ''; ?>" href="<?php echo e($tabUrl($reportKey)); ?>"><?php echo e($reportLabel); ?></a>
    <?php endforeach; ?>
</nav>
<section class="dashboard-panel attendance-panel">
    <div class="attendance-heading"><div><span class="attendance-kicker">Reporting period</span><h2><?php echo e(ucfirst($filters['report'])); ?> attendance</h2><p><?php echo $sessionMode ? 'Only closed attendance sessions contribute to trends and shortage calculations.' : 'Legacy attendance days count as finalized only when every admitted or active student in the batch has a record.'; ?> Institutional holidays are excluded.</p></div></div>
    <form method="get" action="<?php echo e(route_url('/admin/academics/attendance-reports')); ?>" class="attendance-filter-grid">
        <input type="hidden" name="report" value="<?php echo e($filters['report']); ?>">
        <label>Academic year<select class="form-select" name="academic_year_id" required><?php foreach ($years as $yearOption): ?><option value="<?php echo e((string) $yearOption['id']); ?>"<?php echo $selectedYearId === (int) $yearOption['id'] ? ' selected' : ''; ?>><?php echo e($yearOption['name']); ?><?php echo $yearOption['status'] === 'closed' ? ' (Closed)' : ''; ?></option><?php endforeach; ?></select></label>
        <label><?php echo $sessionMode ? 'Class / section / placement' : 'Class / batch'; ?><select class="form-select" name="academic_group_id"<?php echo $filters['report'] === 'daily' ? ' required' : ''; ?>><option value="0"><?php echo in_array($filters['report'], ['monthly', 'shortage', 'student'], true) ? 'All placements' : 'Select placement'; ?></option><?php foreach ($groups as $group): ?>
            <?php $groupLabel = $sessionMode ? (string) $group['label'] : ($group['class_name'] . ' / ' . $group['course_name'] . ' / ' . $group['batch_name']); ?>
            <option value="<?php echo e((string) $group['id']); ?>"<?php echo $groupId === (int) $group['id'] ? ' selected' : ''; ?>><?php echo e($groupLabel); ?></option>
        <?php endforeach; ?></select></label>
        <?php if ($filters['report'] === 'student'): ?>
            <label>Student<select class="form-select" name="student_id" required><option value="0">Select student</option><?php foreach ($students as $studentOption): ?><option value="<?php echo e((string) $studentOption['id']); ?>"<?php echo $filters['student_id'] === (int) $studentOption['id'] ? ' selected' : ''; ?>><?php echo e($studentOption['full_name'] . ' · ' . $studentOption['student_code']); ?></option><?php endforeach; ?></select></label>
        <?php endif; ?>
        <?php if ($filters['report'] === 'daily'): ?>
            <label>Date<input class="form-control" type="date" name="date_from" required value="<?php echo e($filters['date_from']); ?>"></label>
            <input type="hidden" name="date_to" value="<?php echo e($filters['date_from']); ?>">
        <?php else: ?>
            <label>From<input class="form-control" type="date" name="date_from" required value="<?php echo e($filters['date_from']); ?>"></label>
            <label>To<input class="form-control" type="date" name="date_to" required value="<?php echo e($filters['date_to']); ?>"></label>
        <?php endif; ?>
        <button class="btn btn-outline-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i>Run report</button>
    </form>
</section>

<?php if ($filters['report'] === 'daily'): ?>
    <?php if (is_array($daily)): ?>
        <section class="dashboard-panel attendance-panel">
            <div class="attendance-heading"><div><span class="attendance-kicker"><?php echo e($daily['submission_status']); ?></span><h2>Daily attendance summary</h2><p><?php echo e(date('j M Y', strtotime($daily['date']))); ?> · <?php echo number_format($daily['enrolled_count']); ?> enrolled</p></div><?php if ($canExportAttendance): ?><a class="btn btn-outline-primary" href="<?php echo e($exportUrl); ?>"><i class="bi bi-download" aria-hidden="true"></i>Export CSV</a><?php endif; ?></div>
            <div class="attendance-report-summary">
                <div><span>Present</span><strong><?php echo number_format($daily['present_count']); ?></strong></div>
                <div><span>Absent</span><strong><?php echo number_format($daily['absent_count']); ?></strong></div>
                <div><span>Late</span><strong><?php echo number_format($daily['late_count']); ?></strong></div>
                <div><span>Excused</span><strong><?php echo number_format($daily['excused_count']); ?></strong></div>
                <div><span>Attendance</span><strong><?php echo $daily['attendance_percentage'] === null ? '—' : e(number_format($daily['attendance_percentage'], 2) . '%'); ?></strong></div>
            </div>
            <?php if ($sessionMode && $daily['session_status'] === 'open' && $daily['recorded_count'] === $daily['enrolled_count'] && $daily['enrolled_count'] > 0 && !$daily['is_holiday'] && $canEditAttendance): ?>
                <form method="post" action="<?php echo e(route_url('/admin/academics/attendance-reports')); ?>" class="mt-3">
                    <?php echo csrf_field(); ?><input type="hidden" name="action" value="finalize_session"><input type="hidden" name="academic_year_id" value="<?php echo e((string) $selectedYearId); ?>"><input type="hidden" name="academic_group_id" value="<?php echo e((string) $groupId); ?>"><input type="hidden" name="attendance_date" value="<?php echo e($daily['date']); ?>">
                    <button class="btn btn-cobalt" type="submit"><i class="bi bi-lock" aria-hidden="true"></i>Finalize attendance</button>
                </form>
            <?php elseif ($sessionMode && $daily['session_status'] === 'open'): ?><p class="mt-3 mb-0">Finalize after a status is recorded for every enrolled student. Open sessions are not included in shortage or monthly analytics.</p><?php endif; ?>
        </section>
    <?php elseif ($groupId === 0): ?><section class="dashboard-panel"><p>Select a class, section, or batch to view its daily report.</p></section><?php endif; ?>

<?php elseif ($filters['report'] === 'student'): ?>
    <?php if (is_array($studentSummary)): ?>
        <section class="dashboard-panel attendance-panel">
            <div class="attendance-heading"><div><span class="attendance-kicker">Student trend</span><h2><?php echo e($selectedStudentName !== '' ? $selectedStudentName : 'Attendance history'); ?></h2><p>Only finalized, non-holiday attendance days are counted.</p></div><?php if ($canExportAttendance): ?><a class="btn btn-outline-primary" href="<?php echo e($exportUrl); ?>"><i class="bi bi-download" aria-hidden="true"></i>Export CSV</a><?php endif; ?></div>
            <div class="attendance-report-summary">
                <div><span>Working days</span><strong><?php echo number_format($studentSummary['total_working_days']); ?></strong></div>
                <div><span>Present</span><strong><?php echo number_format($studentSummary['present_count']); ?></strong></div>
                <div><span>Absent</span><strong><?php echo number_format($studentSummary['absent_count']); ?></strong></div>
                <div><span>Late / excused</span><strong><?php echo number_format($studentSummary['late_count']); ?> / <?php echo number_format($studentSummary['excused_count']); ?></strong></div>
                <div><span>Attendance</span><strong><?php echo $studentSummary['attendance_percentage'] === null ? '—' : e(number_format($studentSummary['attendance_percentage'], 2) . '%'); ?></strong></div>
            </div>
            <h3 class="h5 mt-4">Monthly breakdown</h3>
            <?php if ($studentMonthly === []): ?><p>No finalized attendance days were found for this student and date range.</p><?php else: ?><div class="table-responsive"><table class="table attendance-table align-middle"><thead><tr><th>Month</th><th>Working days</th><th>Present</th><th>Absent</th><th>Late</th><th>Excused</th><th>Attendance</th></tr></thead><tbody><?php foreach ($studentMonthly as $month): ?><tr><td><?php echo e(date('F Y', strtotime($month['month'] . '-01'))); ?></td><td><?php echo number_format((int) $month['total_working_days']); ?></td><td><?php echo number_format((int) $month['present_count']); ?></td><td><?php echo number_format((int) $month['absent_count']); ?></td><td><?php echo number_format((int) $month['late_count']); ?></td><td><?php echo number_format((int) $month['excused_count']); ?></td><td><?php echo $month['attendance_percentage'] === null ? '—' : e(number_format((float) $month['attendance_percentage'], 2) . '%'); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
            <h3 class="h5 mt-4">Attendance history</h3>
            <?php if ($studentRecords['records'] === []): ?><p>No matching finalized records.</p><?php else: ?><div class="table-responsive"><table class="table attendance-table align-middle"><thead><tr><th>Date</th><th>Placement</th><th>Status</th></tr></thead><tbody><?php foreach ($studentRecords['records'] as $record): ?><tr><td><?php echo e(date('j M Y', strtotime($record['attendance_date']))); ?></td><td><?php echo e($record['placement_name'] ?? ''); ?></td><td><?php echo e(ucfirst($record['status'])); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
            <?php if ($studentRecords['total_pages'] > 1): ?><nav aria-label="Student attendance pages"><ul class="pagination"><?php for ($page = 1; $page <= $studentRecords['total_pages']; $page++): ?><li class="page-item<?php echo $page === $studentRecords['page'] ? ' active' : ''; ?>"><a class="page-link" href="<?php echo e(route_url('/admin/academics/attendance-reports') . '?' . http_build_query([...$filters, 'page' => $page])); ?>"><?php echo $page; ?></a></li><?php endfor; ?></ul></nav><?php endif; ?>
        </section>
    <?php endif; ?>

<?php elseif ($filters['report'] === 'shortage'): ?>
    <section class="dashboard-panel attendance-panel">
        <div class="attendance-heading"><div><span class="attendance-kicker">Attendance thresholds</span><h2>Attendance shortage</h2><p>Good ≥ <?php echo $thresholds['good']; ?>%; warning ≥ <?php echo $thresholds['warning']; ?>% and &lt; <?php echo $thresholds['good']; ?>%; critical &lt; <?php echo $thresholds['warning']; ?>%. Consistently low means below the warning threshold for three consecutive months.</p></div><?php if ($canExportAttendance && $reportRows !== []): ?><a class="btn btn-outline-primary" href="<?php echo e($exportUrl); ?>"><i class="bi bi-download" aria-hidden="true"></i>Export CSV</a><?php endif; ?></div>
        <?php if ($canConfigureThresholds): ?><form method="post" action="<?php echo e(route_url('/admin/academics/attendance-reports')); ?>" class="attendance-filter-grid mb-4"><?php echo csrf_field(); ?><input type="hidden" name="action" value="save_thresholds"><label>Warning threshold (%)<input class="form-control" type="number" min="0" max="99" name="warning_threshold" required value="<?php echo e((string) $thresholds['warning']); ?>"></label><label>Good threshold (%)<input class="form-control" type="number" min="1" max="100" name="good_threshold" required value="<?php echo e((string) $thresholds['good']); ?>"></label><button class="btn btn-outline-primary align-self-end" type="submit">Save thresholds</button></form><?php endif; ?>
        <?php
        $shortageRowsAll = array_values(array_filter(
            $reportRows,
            static fn(array $row): bool => in_array($row['status'], ['warning', 'critical'], true)
        ));
        $shortagePageSize = 50;
        $shortageTotalPages = max(1, (int) ceil(count($shortageRowsAll) / $shortagePageSize));
        $shortagePage = min($filters['page'], $shortageTotalPages);
        $shortageRows = array_slice($shortageRowsAll, ($shortagePage - 1) * $shortagePageSize, $shortagePageSize);
        ?>
        <?php if ($shortageRows === []): ?><div class="admin-panel-empty"><i class="bi bi-check2-circle" aria-hidden="true"></i><strong>No students below the configured thresholds</strong><span>Students with no assessable finalized days are not classified as absent.</span></div><?php else: ?><div class="table-responsive"><table class="table attendance-table align-middle"><thead><tr><th>Student</th><th>Class / section</th><th>Working days</th><th>Present / late</th><th>Absent</th><th>Excused</th><th>Attendance</th><th>Status</th><th>Trend</th></tr></thead><tbody><?php foreach ($shortageRows as $row): ?><tr><td><strong><?php echo e($row['full_name']); ?></strong><small><?php echo e($row['student_code']); ?></small></td><td><?php echo e($row['placement_name']); ?></td><td><?php echo number_format($row['total_working_days']); ?></td><td><?php echo number_format($row['present_count'] + $row['late_count']); ?></td><td><?php echo number_format($row['absent_count']); ?></td><td><?php echo number_format($row['excused_count']); ?></td><td><?php echo e(number_format($row['attendance_percentage'], 2) . '%'); ?></td><td><span class="attendance-badge is-<?php echo e($row['status']); ?>"><?php echo e(ucfirst($row['status'])); ?></span></td><td><?php echo in_array((int) $row['student_id'], $consistentlyPoorStudentIds, true) ? 'Consistently low' : '—'; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        <?php if ($shortageTotalPages > 1): ?><nav aria-label="Attendance shortage pages"><ul class="pagination"><?php for ($page = 1; $page <= $shortageTotalPages; $page++): ?><li class="page-item<?php echo $page === $shortagePage ? ' active' : ''; ?>"><a class="page-link" href="<?php echo e(route_url('/admin/academics/attendance-reports') . '?' . http_build_query([...$filters, 'page' => $page])); ?>"><?php echo $page; ?></a></li><?php endfor; ?></ul></nav><?php endif; ?>
    </section>

<?php else: ?>
    <section class="dashboard-panel attendance-panel">
        <div class="attendance-heading"><div><span class="attendance-kicker">Finalized records only</span><h2>Monthly attendance trends</h2><p>Compare class, section, or batch attendance by month. Months without finalized attendance are not fabricated.</p></div><?php if ($canExportAttendance && $reportRows !== []): ?><a class="btn btn-outline-primary" href="<?php echo e($exportUrl); ?>"><i class="bi bi-download" aria-hidden="true"></i>Export CSV</a><?php endif; ?></div>
        <?php if ($reportRows === []): ?><div class="admin-panel-empty"><i class="bi bi-bar-chart" aria-hidden="true"></i><strong>No finalized monthly records</strong><span>Close completed attendance sessions to include them in analytics.</span></div><?php else: ?><div class="table-responsive"><table class="table attendance-table align-middle"><thead><tr><th>Month</th><th>Class / section / batch</th><th>Working days</th><th>Present</th><th>Absent</th><th>Late</th><th>Excused</th><th>Attendance</th></tr></thead><tbody><?php foreach ($reportRows as $row): ?><tr><td><?php echo e(date('F Y', strtotime($row['month'] . '-01'))); ?></td><td><?php echo e($row['placement_name']); ?></td><td><?php echo number_format((int) $row['total_working_days']); ?></td><td><?php echo number_format((int) $row['present_count']); ?></td><td><?php echo number_format((int) $row['absent_count']); ?></td><td><?php echo number_format((int) $row['late_count']); ?></td><td><?php echo number_format((int) $row['excused_count']); ?></td><td><?php echo $row['attendance_percentage'] === null ? '—' : e(number_format((float) $row['attendance_percentage'], 2) . '%'); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section>
<?php endif; ?>
<?php render_app_page_end('admin'); ?>
