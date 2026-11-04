<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/admin-panel-service.php';
require_once __DIR__ . '/../../services/attendance-management-service.php';

require_http_methods(['GET', 'POST']);
require_placeholder_route_access();
$db = db_connect();
$institutionId = admin_panel_institution_id();
if (!academic_management_schema_ready($db)) {
    academic_management_render_schema_required_page();
    exit;
}
$user = current_user();
$errors = [];
$academicConfiguration = academic_management_configuration($db, $institutionId);
if ($academicConfiguration['academic_model'] !== null) {
    $sessionView = is_string($_GET['view'] ?? null) ? $_GET['view'] : 'mark';
    if (!in_array($sessionView, ['mark', 'history'], true)) {
        $sessionView = 'mark';
    }
    $sessionGroupId = attendance_parse_id($_GET['academic_group_id'] ?? '0');
    $sessionGroupId = $sessionGroupId === null || $sessionGroupId < 0 ? 0 : $sessionGroupId;
    $sessionDate = is_string($_GET['attendance_date'] ?? null) ? $_GET['attendance_date'] : date('Y-m-d');
    if (!attendance_valid_date($sessionDate)) {
        $errors[] = 'Choose a valid attendance date.';
        $sessionDate = date('Y-m-d');
    }
    $postedStatuses = [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_valid_csrf_token();
        $posted = $_POST['attendance'] ?? [];
        $postedStatuses = is_array($posted) ? $posted : [];
        try {
            $savedCount = attendance_save_session($db, $institutionId, (int) $user['id'], $_POST);
            set_flash(
                'success',
                $savedCount === 0
                    ? 'Attendance was already up to date.'
                    : 'Attendance saved; ' . number_format($savedCount) . ' record(s) created or corrected.'
            );
            safe_redirect(
                '/admin/academics/attendance?' . http_build_query([
                    'view' => 'mark',
                    'academic_group_id' => (int) ($_POST['academic_group_id'] ?? 0),
                    'attendance_date' => is_string($_POST['attendance_date'] ?? null)
                        ? $_POST['attendance_date']
                        : date('Y-m-d'),
                ]),
                303
            );
        } catch (InvalidArgumentException | DomainException | OutOfBoundsException $exception) {
            $errors[] = $exception->getMessage();
            $postedGroupId = attendance_parse_id($_POST['academic_group_id'] ?? '0');
            $sessionGroupId = $postedGroupId === null || $postedGroupId < 0 ? 0 : $postedGroupId;
            if (is_string($_POST['attendance_date'] ?? null) && attendance_valid_date($_POST['attendance_date'])) {
                $sessionDate = $_POST['attendance_date'];
            }
        } catch (Throwable $exception) {
            log_error('academic_session_attendance_save_failed', [
                'error_type' => get_class($exception),
                'error_code' => $exception->getCode(),
            ]);
            $errors[] = 'Attendance could not be saved. Review the placement and try again.';
        }
    }

    $sessionGroups = attendance_get_session_group_options($db, $institutionId);
    $sessionRoster = [];
    if ($sessionGroupId > 0) {
        try {
            $sessionRoster = attendance_get_session_roster(
                $db,
                $institutionId,
                (int) ($academicConfiguration['active_academic_year_id'] ?? 0),
                $sessionGroupId,
                $sessionDate
            );
        } catch (Throwable $exception) {
            log_error('academic_session_attendance_roster_failed', [
                'error_type' => get_class($exception),
                'error_code' => $exception->getCode(),
            ]);
            $errors[] = 'The enrolment roster could not be loaded.';
        }
    }
    foreach ($sessionRoster as &$sessionStudent) {
        $studentId = (int) $sessionStudent['id'];
        if (is_string($postedStatuses[$studentId] ?? null)) {
            $sessionStudent['attendance_status'] = $postedStatuses[$studentId];
        }
    }
    unset($sessionStudent);
    $canCreateSessionAttendance = user_has_compatible_permission('attendance.create', 'academics.manage');
    $canEditSessionAttendance = user_has_compatible_permission('attendance.edit', 'academics.manage');
    $sessionHistory = $sessionView === 'history'
        ? attendance_get_session_history($db, $institutionId)
        : [];
    $activeYearId = $academicConfiguration['active_academic_year_id'];

    render_app_page_start(
        'Attendance Management',
        'Record attendance against year-specific enrolments and placement history.',
        'admin',
        ['admin-dashboard.css', 'attendance-management.css']
    );
    ?>
    <?php echo render_flash_messages(); ?>
    <?php foreach ($errors as $error): ?><div class="alert alert-danger attendance-alert" role="alert"><?php echo e($error); ?></div><?php endforeach; ?>
    <nav aria-label="Breadcrumb"><ol class="breadcrumb attendance-breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item active" aria-current="page">Attendance</li></ol></nav>
    <nav class="attendance-tabs" aria-label="Attendance sections">
        <a class="<?php echo $sessionView === 'mark' ? 'is-active' : ''; ?>" href="<?php echo e(route_url('/admin/academics/attendance')); ?>"><i class="bi bi-pencil-square" aria-hidden="true"></i>Mark attendance</a>
        <a class="<?php echo $sessionView === 'history' ? 'is-active' : ''; ?>" href="<?php echo e(route_url('/admin/academics/attendance') . '?view=history'); ?>"><i class="bi bi-clock-history" aria-hidden="true"></i>Session history</a>
    </nav>
    <?php if ($activeYearId === null): ?>
        <section class="dashboard-panel"><h2>Active academic year required</h2><p>Select the current academic year before taking attendance.</p><a class="btn btn-cobalt" href="<?php echo e(route_url('/admin/academics/years')); ?>">Select academic year</a></section>
    <?php elseif ($sessionView === 'mark'): ?>
        <section class="dashboard-panel attendance-panel">
            <div class="attendance-heading"><div><span class="attendance-kicker">Enrolment register</span><h2>Mark attendance</h2><p>Roster membership follows the student's placement history for the selected date.</p></div></div>
            <form method="get" action="<?php echo e(route_url('/admin/academics/attendance')); ?>" class="attendance-filter-grid">
                <input type="hidden" name="view" value="mark">
                <label>Academic placement<select class="form-select" name="academic_group_id" required><option value="0">Select a placement</option><?php foreach ($sessionGroups as $group): ?><option value="<?php echo e((string) $group['id']); ?>"<?php echo $sessionGroupId === (int) $group['id'] ? ' selected' : ''; ?>><?php echo e($group['label']); ?></option><?php endforeach; ?></select></label>
                <label>Date<input class="form-control" type="date" name="attendance_date" required value="<?php echo e($sessionDate); ?>"></label>
                <button class="btn btn-outline-primary attendance-load-button" type="submit"><i class="bi bi-people" aria-hidden="true"></i>Load students</button>
            </form>
        </section>
        <?php if ($sessionGroupId > 0): ?>
            <section class="dashboard-panel attendance-panel">
                <div class="attendance-heading"><div><h2>Student register</h2><p>Present, absent, late, and excused entries are saved in an auditable session.</p></div><span class="attendance-roster-count"><?php echo number_format(count($sessionRoster)); ?> students</span></div>
                <?php if ($sessionRoster === []): ?>
                    <div class="admin-panel-empty"><i class="bi bi-person-x" aria-hidden="true"></i><strong>No enrolled students found</strong><span>There are no active enrolments in this placement for the selected date.</span></div>
                <?php else: ?>
                    <?php
                    $editableSessionStudentIds = [];
                    foreach ($sessionRoster as $sessionStudent) {
                        $hasStatus = ($sessionStudent['attendance_record_id'] ?? null) !== null;
                        if ($hasStatus ? $canEditSessionAttendance : $canCreateSessionAttendance) {
                            $editableSessionStudentIds[] = (int) $sessionStudent['id'];
                        }
                    }
                    ?>
                    <?php if (count($editableSessionStudentIds) === count($sessionRoster)): ?>
                        <form method="post" action="<?php echo e(route_url('/admin/academics/attendance')); ?>" class="attendance-save-form">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="academic_year_id" value="<?php echo e((string) $activeYearId); ?>">
                            <input type="hidden" name="academic_group_id" value="<?php echo e((string) $sessionGroupId); ?>">
                            <input type="hidden" name="attendance_date" value="<?php echo e($sessionDate); ?>">
                            <div class="table-responsive"><table class="table attendance-table align-middle">
                                <thead><tr><th scope="col">Student</th><th scope="col">Student ID</th><th scope="col">Status</th></tr></thead>
                                <tbody><?php foreach ($sessionRoster as $sessionStudent): ?><tr>
                                    <td><strong><?php echo e($sessionStudent['full_name']); ?></strong></td>
                                    <td><?php echo e($sessionStudent['student_code']); ?></td>
                                    <td><?php if (in_array((int) $sessionStudent['id'], $editableSessionStudentIds, true)): ?><label class="visually-hidden" for="session-attendance-<?php echo (int) $sessionStudent['id']; ?>">Attendance status for <?php echo e($sessionStudent['full_name']); ?></label><select class="form-select attendance-status-select" id="session-attendance-<?php echo (int) $sessionStudent['id']; ?>" name="attendance[<?php echo (int) $sessionStudent['id']; ?>]" required><option value="">Choose status</option><?php foreach (['present' => 'Present', 'absent' => 'Absent', 'late' => 'Late', 'excused' => 'Excused'] as $status => $label): ?><option value="<?php echo e($status); ?>"<?php echo ($sessionStudent['attendance_status'] ?? '') === $status ? ' selected' : ''; ?>><?php echo e($label); ?></option><?php endforeach; ?></select><?php else: ?><?php echo e(ucfirst((string) ($sessionStudent['attendance_status'] ?? 'Not recorded'))); ?><?php endif; ?></td>
                                </tr><?php endforeach; ?></tbody>
                            </table></div>
                            <div class="attendance-form-footer"><span>Existing records require correction permission.</span><button class="btn btn-cobalt" type="submit"><i class="bi bi-check2-circle" aria-hidden="true"></i>Save attendance</button></div>
                        </form>
                    <?php else: ?>
                        <p>This register is read-only because you cannot update every attendance entry.</p>
                        <div class="table-responsive"><table class="table attendance-table align-middle"><thead><tr><th scope="col">Student</th><th scope="col">Student ID</th><th scope="col">Status</th></tr></thead><tbody><?php foreach ($sessionRoster as $sessionStudent): ?><tr><td><strong><?php echo e($sessionStudent['full_name']); ?></strong></td><td><?php echo e($sessionStudent['student_code']); ?></td><td><?php echo e(ucfirst((string) ($sessionStudent['attendance_status'] ?? 'Not recorded'))); ?></td></tr><?php endforeach; ?></tbody></table></div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    <?php else: ?>
        <section class="dashboard-panel attendance-panel">
            <div class="attendance-heading"><div><span class="attendance-kicker">Session archive</span><h2>Attendance session history</h2></div></div>
            <?php if ($sessionHistory === []): ?><p>No session records have been saved.</p><?php else: ?>
                <div class="table-responsive"><table class="table attendance-table align-middle">
                    <thead><tr><th>Date</th><th>Academic year</th><th>Placement</th><th>Recorded</th><th>Present</th><th>Absent</th><th>Late</th><th>Excused</th></tr></thead>
                    <tbody><?php foreach ($sessionHistory as $history): $parts = array_filter([$history['class_name'] ?? null, $history['section_name'] ?? null, $history['course_name'] ?? null, $history['term_name'] ?? null, $history['batch_name'] ?? null], static fn($part): bool => is_string($part) && $part !== ''); ?><tr>
                        <td><?php echo e(substr((string) $history['session_at'], 0, 10)); ?></td><td><?php echo e($history['academic_year_name']); ?></td><td><?php echo e($parts === [] ? (string) $history['group_type'] : implode(' / ', $parts)); ?></td><td><?php echo e((string) $history['recorded_count']); ?></td><td><?php echo e((string) $history['present_count']); ?></td><td><?php echo e((string) $history['absent_count']); ?></td><td><?php echo e((string) $history['late_count']); ?></td><td><?php echo e((string) $history['excused_count']); ?></td>
                    </tr><?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    <?php render_app_page_end('admin'); ?>
    <?php
    exit;
}
$view = is_string($_GET['view'] ?? null) ? $_GET['view'] : 'mark';
if (!in_array($view, ['mark', 'history', 'reports'], true)) {
    $view = 'mark';
}
$canCreateAttendance = user_has_compatible_permission('attendance.create', 'academics.manage');
$canEditAttendance = user_has_compatible_permission('attendance.edit', 'academics.manage');
$canManageAttendance = $canCreateAttendance || $canEditAttendance;
if (!$canManageAttendance && $view === 'mark') {
    $view = 'history';
}
$today = date('Y-m-d');
$selectedYear = attendance_parse_id($_GET['academic_year_id'] ?? '0');
$selectedClass = attendance_parse_id($_GET['class_id'] ?? '0');
$selectedBatch = attendance_parse_id($_GET['batch_id'] ?? '0');
$selectedDate = is_string($_GET['attendance_date'] ?? null) ? $_GET['attendance_date'] : $today;
$submittedStatuses = [];
if ($selectedYear === null || $selectedYear < 0) {
    $errors[] = 'Select a valid academic session.';
    $selectedYear = 0;
}
if ($selectedClass === null || $selectedClass < 0) {
    $errors[] = 'Select a valid class.';
    $selectedClass = 0;
}
if ($selectedBatch === null || $selectedBatch < 0) {
    $errors[] = 'Select a valid batch.';
    $selectedBatch = 0;
}
if (!attendance_valid_date($selectedDate)) {
    $errors[] = 'Choose a valid attendance date.';
    $selectedDate = $today;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    $view = 'mark';
    $postedAttendance = $_POST['attendance'] ?? [];
    $submittedStatuses = is_array($postedAttendance) ? $postedAttendance : [];
    try {
        $savedCount = attendance_save_batch($db, $institutionId, (int) $user['id'], $_POST);
        $message = $savedCount === 0
            ? 'Attendance was already up to date.'
            : 'Attendance saved; ' . number_format($savedCount) . ' record(s) created or corrected.';
        set_flash('success', $message);
        $yearId = (int) ($_POST['academic_year_id'] ?? 0);
        $batchId = (int) ($_POST['batch_id'] ?? 0);
        $date = is_string($_POST['attendance_date'] ?? null) ? $_POST['attendance_date'] : $today;
        safe_redirect(
            '/admin/academics/attendance?' . http_build_query([
                'view' => 'mark',
                'academic_year_id' => $yearId,
                'class_id' => (int) ($_POST['class_id'] ?? 0),
                'batch_id' => $batchId,
                'attendance_date' => $date,
            ]),
            303
        );
    } catch (InvalidArgumentException $exception) {
        $errors[] = $exception->getMessage();
        $selectedYear = attendance_parse_id($_POST['academic_year_id'] ?? '0');
        $selectedYear = $selectedYear === null || $selectedYear < 0 ? 0 : $selectedYear;
        $selectedClass = attendance_parse_id($_POST['class_id'] ?? '0');
        $selectedClass = $selectedClass === null || $selectedClass < 0 ? 0 : $selectedClass;
        $selectedBatch = attendance_parse_id($_POST['batch_id'] ?? '0');
        $selectedBatch = $selectedBatch === null || $selectedBatch < 0 ? 0 : $selectedBatch;
        if (is_string($_POST['attendance_date'] ?? null) && attendance_valid_date($_POST['attendance_date'])) {
            $selectedDate = $_POST['attendance_date'];
        }
    } catch (Throwable $exception) {
        log_error('admin_attendance_save_failed', [
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
        ]);
        $errors[] = 'Attendance could not be saved. Please review the selection and try again.';
    }
}

$years = attendance_get_academic_years($db, $institutionId);
$classes = attendance_get_classes($db, $institutionId);
$allBatches = attendance_get_batches($db, $institutionId);
$markingBatches = attendance_get_batches($db, $institutionId, true);
$visibleMarkingBatches = $selectedClass > 0
    ? array_values(array_filter($markingBatches, static fn(array $batch): bool => (int) $batch['class_id'] === $selectedClass))
    : $markingBatches;
$roster = [];
if ($selectedYear > 0 && $selectedBatch > 0 && attendance_valid_date($selectedDate)) {
    try {
        $roster = attendance_get_students_for_marking(
            $db,
            $institutionId,
            $selectedYear,
            $selectedBatch,
            $selectedDate
        );
    } catch (Throwable $exception) {
        log_error('admin_attendance_roster_failed', [
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
        ]);
        $errors[] = 'The student roster could not be loaded.';
    }
}
if ($submittedStatuses !== []) {
    foreach ($roster as &$student) {
        $studentId = (int) $student['id'];
        if (is_string($submittedStatuses[$studentId] ?? null)) {
            $student['attendance_status'] = $submittedStatuses[$studentId];
        }
    }
    unset($student);
}
$editableAttendanceStudentIds = [];
foreach ($roster as $student) {
    $hasExistingStatus = ($student['attendance_record_id'] ?? null) !== null;
    if ($hasExistingStatus ? $canEditAttendance : $canCreateAttendance) {
        $editableAttendanceStudentIds[] = (int) $student['id'];
    }
}

$historyFilters = ['class_id' => 0, 'batch_id' => 0, 'student_query' => '', 'date_from' => '', 'date_to' => ''];
$historyRows = [];
if ($view === 'history') {
    try {
        $historyFilters = attendance_validate_history_filters($_GET);
        $historyRows = attendance_get_history($db, $institutionId, $historyFilters);
    } catch (InvalidArgumentException $exception) {
        $errors[] = $exception->getMessage();
    }
}

$reportRows = [];
$reportFilters = [
    'class_id' => $selectedClass,
    'batch_id' => 0,
    'date_from' => date('Y-m-01'),
    'date_to' => $today,
];
if ($view === 'reports') {
    $reportClass = attendance_parse_id($_GET['class_id'] ?? '0');
    $reportBatch = attendance_parse_id($_GET['batch_id'] ?? '0');
    $reportFrom = is_string($_GET['date_from'] ?? null) ? $_GET['date_from'] : date('Y-m-01');
    $reportTo = is_string($_GET['date_to'] ?? null) ? $_GET['date_to'] : $today;
    if ($reportClass !== null && $reportClass > 0) {
        $reportFilters['class_id'] = $reportClass;
    }
    if ($reportBatch !== null && $reportBatch >= 0) {
        $reportFilters['batch_id'] = $reportBatch;
    }
    $reportFilters['date_from'] = $reportFrom;
    $reportFilters['date_to'] = $reportTo;
    if ($reportFilters['class_id'] > 0) {
        try {
            $reportRows = attendance_get_report(
                $db,
                $institutionId,
                $reportFilters['class_id'],
                $reportFilters['batch_id'],
                $reportFrom,
                $reportTo
            );
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        }
    }
}

render_app_page_start(
    'Attendance Management',
    'Record daily attendance and review institution-scoped attendance history and class reports.',
    'admin',
    ['admin-dashboard.css', 'attendance-management.css']
);
?>
<?php echo render_flash_messages(); ?>
<?php foreach ($errors as $error): ?><div class="alert alert-danger attendance-alert" role="alert"><?php echo e($error); ?></div><?php endforeach; ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb attendance-breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item active" aria-current="page">Attendance</li></ol></nav>
<nav class="attendance-tabs" aria-label="Attendance sections">
    <?php if ($canManageAttendance): ?><a class="<?php echo $view === 'mark' ? 'is-active' : ''; ?>" href="<?php echo e(route_url('/admin/academics/attendance')); ?>"><i class="bi bi-pencil-square" aria-hidden="true"></i>Mark attendance</a><?php endif; ?>
    <a class="<?php echo $view === 'history' ? 'is-active' : ''; ?>" href="<?php echo e(route_url('/admin/academics/attendance') . '?view=history'); ?>"><i class="bi bi-clock-history" aria-hidden="true"></i>History</a>
    <a class="<?php echo $view === 'reports' ? 'is-active' : ''; ?>" href="<?php echo e(route_url('/admin/academics/attendance') . '?view=reports'); ?>"><i class="bi bi-bar-chart-line" aria-hidden="true"></i>Reports</a>
</nav>

<?php if ($view === 'mark'): ?>
<section class="dashboard-panel attendance-panel">
    <div class="attendance-heading"><div><span class="attendance-kicker">Daily register</span><h2>Mark attendance</h2><p>Choose the academic session and batch to load students assigned to that cohort.</p></div></div>
    <form method="get" action="<?php echo e(route_url('/admin/academics/attendance')); ?>" class="attendance-filter-grid">
        <input type="hidden" name="view" value="mark">
        <label>Academic session<select class="form-select" name="academic_year_id" required><option value="0">Select session</option><?php foreach ($years as $year): ?><option value="<?php echo e((string) $year['id']); ?>"<?php echo $selectedYear === (int) $year['id'] ? ' selected' : ''; ?>><?php echo e($year['name']); ?><?php echo $year['status'] === 'closed' ? ' (Closed)' : ''; ?></option><?php endforeach; ?></select></label>
        <label>Class<select class="form-select" name="class_id"><option value="0">All classes</option><?php foreach ($classes as $class): ?><option value="<?php echo e((string) $class['id']); ?>"<?php echo $selectedClass === (int) $class['id'] ? ' selected' : ''; ?>><?php echo e($class['name']); ?></option><?php endforeach; ?></select></label>
        <label>Batch<select class="form-select" name="batch_id" required><option value="0">Select batch</option><?php foreach ($visibleMarkingBatches as $batch): ?><option value="<?php echo e((string) $batch['id']); ?>"<?php echo $selectedBatch === (int) $batch['id'] ? ' selected' : ''; ?>><?php echo e($batch['class_name'] . ' · ' . $batch['course_name'] . ' · ' . $batch['batch_name']); ?></option><?php endforeach; ?></select></label>
        <label>Date<input class="form-control" type="date" name="attendance_date" required value="<?php echo e($selectedDate); ?>"></label>
        <button class="btn btn-outline-primary attendance-load-button" type="submit"><i class="bi bi-people" aria-hidden="true"></i>Load students</button>
    </form>
</section>

<?php if ($selectedYear > 0 && $selectedBatch > 0): ?>
<section class="dashboard-panel attendance-panel">
    <div class="attendance-heading"><div><h2>Student register</h2><p>Every listed student must have a status selected before saving. Existing records can be corrected.</p></div><span class="attendance-roster-count"><?php echo number_format(count($roster)); ?> students</span></div>
    <?php if ($roster === []): ?>
        <div class="admin-panel-empty"><i class="bi bi-person-x" aria-hidden="true"></i><strong>No enrolled students found</strong><span>There are no admitted or active students assigned to this batch and academic session for the selected date.</span></div>
    <?php else: ?>
        <?php if ($editableAttendanceStudentIds !== []): ?><form method="post" action="<?php echo e(route_url('/admin/academics/attendance')); ?>" class="attendance-save-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="academic_year_id" value="<?php echo e((string) $selectedYear); ?>">
            <input type="hidden" name="class_id" value="<?php echo e((string) $selectedClass); ?>">
            <input type="hidden" name="batch_id" value="<?php echo e((string) $selectedBatch); ?>">
            <input type="hidden" name="attendance_date" value="<?php echo e($selectedDate); ?>">
            <div class="table-responsive"><table class="table attendance-table align-middle">
                <thead><tr><th scope="col">Student</th><th scope="col">Student ID</th><th scope="col">Status</th></tr></thead>
                <tbody><?php foreach ($roster as $student): ?><tr>
                    <td><strong><?php echo e($student['full_name']); ?></strong></td>
                    <td><?php echo e($student['student_code']); ?></td>
                    <td><?php if (in_array((int) $student['id'], $editableAttendanceStudentIds, true)): ?><label class="visually-hidden" for="attendance-<?php echo (int) $student['id']; ?>">Attendance status for <?php echo e($student['full_name']); ?></label><select class="form-select attendance-status-select" id="attendance-<?php echo (int) $student['id']; ?>" name="attendance[<?php echo (int) $student['id']; ?>]" required><option value="">Choose status</option><?php foreach (['present' => 'Present', 'absent' => 'Absent', 'late' => 'Late'] as $status => $label): ?><option value="<?php echo e($status); ?>"<?php echo ($student['attendance_status'] ?? '') === $status ? ' selected' : ''; ?>><?php echo e($label); ?></option><?php endforeach; ?></select><?php else: ?><?php echo e(ucfirst((string) ($student['attendance_status'] ?? 'Not recorded'))); ?><?php endif; ?></td>
                </tr><?php endforeach; ?></tbody>
            </table></div>
            <div class="attendance-form-footer"><span>Saving records or changing an existing status is recorded in the audit log.</span><button class="btn btn-cobalt" type="submit"><i class="bi bi-check2-circle" aria-hidden="true"></i>Save attendance</button></div>
        </form>
        <?php else: ?><div class="table-responsive"><table class="table attendance-table align-middle"><thead><tr><th scope="col">Student</th><th scope="col">Student ID</th><th scope="col">Status</th></tr></thead><tbody><?php foreach ($roster as $student): ?><tr><td><strong><?php echo e($student['full_name']); ?></strong></td><td><?php echo e($student['student_code']); ?></td><td><?php echo e(ucfirst((string) ($student['attendance_status'] ?? 'Not recorded'))); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php elseif ($view === 'history'): ?>
<section class="dashboard-panel attendance-panel">
    <div class="attendance-heading"><div><span class="attendance-kicker">Register archive</span><h2>Attendance history</h2><p>Filter saved records by student, class, batch, or date range.</p></div></div>
    <form method="get" action="<?php echo e(route_url('/admin/academics/attendance')); ?>" class="attendance-filter-grid attendance-history-filters">
        <input type="hidden" name="view" value="history">
        <label>Class<select class="form-select" name="class_id"><option value="0">All classes</option><?php foreach ($classes as $class): ?><option value="<?php echo e((string) $class['id']); ?>"<?php echo $historyFilters['class_id'] === (int) $class['id'] ? ' selected' : ''; ?>><?php echo e($class['name']); ?></option><?php endforeach; ?></select></label>
        <label>Batch<select class="form-select" name="batch_id"><option value="0">All batches</option><?php foreach ($allBatches as $batch): ?><option value="<?php echo e((string) $batch['id']); ?>"<?php echo $historyFilters['batch_id'] === (int) $batch['id'] ? ' selected' : ''; ?>><?php echo e($batch['class_name'] . ' · ' . $batch['batch_name']); ?></option><?php endforeach; ?></select></label>
        <label>Student<input class="form-control" type="search" name="student_query" maxlength="150" value="<?php echo e($historyFilters['student_query']); ?>" placeholder="Name or student ID"></label>
        <label>From<input class="form-control" type="date" name="date_from" value="<?php echo e($historyFilters['date_from']); ?>"></label>
        <label>To<input class="form-control" type="date" name="date_to" value="<?php echo e($historyFilters['date_to']); ?>"></label>
        <button class="btn btn-outline-primary" type="submit"><i class="bi bi-search" aria-hidden="true"></i>Filter</button>
    </form>
    <?php if ($historyRows === []): ?><div class="admin-panel-empty"><i class="bi bi-calendar-x" aria-hidden="true"></i><strong>No attendance records found</strong><span>Adjust filters or mark attendance for a batch.</span></div>
    <?php else: ?><div class="table-responsive"><table class="table attendance-table align-middle"><thead><tr><th>Date</th><th>Student</th><th>Class / batch</th><th>Status</th></tr></thead><tbody><?php foreach ($historyRows as $row): ?><tr><td><?php echo e(date('j M Y', strtotime((string) $row['attendance_date']))); ?></td><td><strong><?php echo e($row['full_name']); ?></strong><small><?php echo e($row['student_code']); ?></small></td><td><?php echo e($row['class_name'] . ' · ' . $row['batch_name']); ?></td><td><span class="attendance-badge is-<?php echo e($row['status']); ?>"><?php echo e(ucfirst($row['status'])); ?></span></td></tr><?php endforeach; ?></tbody></table></div><p class="attendance-limit-note">Showing up to 1,000 matching records.</p><?php endif; ?>
</section>

<?php else: ?>
<section class="dashboard-panel attendance-panel">
    <div class="attendance-heading"><div><span class="attendance-kicker">Attendance insights</span><h2>Class attendance report</h2><p>Compare attendance rates for enrolled students across a selected class and period. Late counts as attended.</p></div></div>
    <form method="get" action="<?php echo e(route_url('/admin/academics/attendance')); ?>" class="attendance-filter-grid attendance-report-filters">
        <input type="hidden" name="view" value="reports">
        <label>Class<select class="form-select" name="class_id" required><option value="0">Select class</option><?php foreach ($classes as $class): ?><option value="<?php echo e((string) $class['id']); ?>"<?php echo $reportFilters['class_id'] === (int) $class['id'] ? ' selected' : ''; ?>><?php echo e($class['name']); ?></option><?php endforeach; ?></select></label>
        <label>Batch<select class="form-select" name="batch_id"><option value="0">All batches in class</option><?php foreach ($allBatches as $batch): ?><option value="<?php echo e((string) $batch['id']); ?>"<?php echo $reportFilters['batch_id'] === (int) $batch['id'] ? ' selected' : ''; ?>><?php echo e($batch['class_name'] . ' · ' . $batch['batch_name']); ?></option><?php endforeach; ?></select></label>
        <label>From<input class="form-control" type="date" name="date_from" required value="<?php echo e($reportFilters['date_from']); ?>"></label>
        <label>To<input class="form-control" type="date" name="date_to" required value="<?php echo e($reportFilters['date_to']); ?>"></label>
        <button class="btn btn-outline-primary" type="submit"><i class="bi bi-bar-chart" aria-hidden="true"></i>Run report</button>
    </form>
    <?php if ($reportFilters['class_id'] > 0 && $reportRows !== []):
        $totalRecorded = 0;
        $totalAttended = 0;
        $totalAbsent = 0;
        foreach ($reportRows as $reportRow) {
            $totalRecorded += (int) $reportRow['recorded_days'];
            $totalAttended += (int) $reportRow['attended_days'];
            $totalAbsent += (int) $reportRow['absent_days'];
        }
        $classPercentage = attendance_percentage($totalAttended, $totalRecorded);
    ?>
        <div class="attendance-report-summary">
            <div><span>Students</span><strong><?php echo number_format(count($reportRows)); ?></strong></div>
            <div><span>Recorded student-days</span><strong><?php echo number_format($totalRecorded); ?></strong></div>
            <div><span>Present or late</span><strong><?php echo number_format($totalAttended); ?></strong></div>
            <div><span>Absent</span><strong><?php echo number_format($totalAbsent); ?></strong></div>
            <div><span>Class attendance</span><strong><?php echo $classPercentage === null ? '—' : e(number_format($classPercentage, 2) . '%'); ?></strong></div>
        </div>
        <div class="table-responsive"><table class="table attendance-table align-middle"><thead><tr><th>Student</th><th>Batch</th><th>Recorded</th><th>Present / late</th><th>Absent</th><th>Late</th><th>Attendance</th></tr></thead><tbody><?php foreach ($reportRows as $row): ?><tr><td><strong><?php echo e($row['full_name']); ?></strong><small><?php echo e($row['student_code']); ?></small></td><td><?php echo e($row['batch_name']); ?></td><td><?php echo number_format((int) $row['recorded_days']); ?></td><td><?php echo number_format((int) $row['attended_days']); ?></td><td><?php echo number_format((int) $row['absent_days']); ?></td><td><?php echo number_format((int) $row['late_days']); ?></td><td><?php echo $row['attendance_percentage'] === null ? '—' : e(number_format((float) $row['attendance_percentage'], 2) . '%'); ?></td></tr><?php endforeach; ?></tbody></table></div>
    <?php elseif ($reportFilters['class_id'] > 0): ?><div class="admin-panel-empty"><i class="bi bi-bar-chart" aria-hidden="true"></i><strong>No report data found</strong><span>No active or admitted students or attendance records match this class and period.</span></div><?php endif; ?>
</section>
<?php endif; ?>
<?php render_app_page_end('admin'); ?>
