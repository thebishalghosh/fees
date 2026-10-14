<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/page-placeholder.php';
require_once __DIR__ . '/../services/parent-portal-service.php';
require_once __DIR__ . '/../services/attendance-management-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();

$db = db_connect();
$context = parent_portal_require_current_parent($db);
$parentId = (int) $context['parent']['parent_id'];
$institutionId = (int) $context['user']['institution_id'];
$children = attendance_parent_get_children($db, $parentId, $institutionId);
$requestedStudent = $_GET['student_id'] ?? null;
if ($requestedStudent !== null) {
    $requestedStudentId = attendance_parse_id($requestedStudent);
    $selectedStudentId = $requestedStudentId ?? 0;
    $studentIsLinked = false;
    foreach ($children as $child) {
        if ((int) $child['id'] === $selectedStudentId) {
            $studentIsLinked = true;
            break;
        }
    }
    if (!$studentIsLinked) {
        http_response_code(404);
        exit('Attendance records not found.');
    }
} else {
    $selectedStudentId = (int) ($children[0]['id'] ?? 0);
}

$filterError = '';
try {
    $filters = attendance_validate_parent_filters([
        'date_from' => is_string($_GET['from'] ?? null) ? $_GET['from'] : '',
        'date_to' => is_string($_GET['to'] ?? null) ? $_GET['to'] : '',
        'status' => is_string($_GET['status'] ?? null) ? $_GET['status'] : '',
        'page' => is_string($_GET['page'] ?? null) ? $_GET['page'] : '1',
    ]);
} catch (InvalidArgumentException $exception) {
    $filterError = $exception->getMessage();
    $filters = attendance_validate_parent_filters([]);
}

$student = null;
$summary = null;
$history = ['records' => [], 'total' => 0, 'page' => 1, 'per_page' => 15, 'total_pages' => 1];
if ($selectedStudentId > 0) {
    foreach ($children as $child) {
        if ((int) $child['id'] === $selectedStudentId) {
            $student = $child;
            break;
        }
    }
    $summary = attendance_parent_get_summary(
        $db,
        $parentId,
        $institutionId,
        $selectedStudentId,
        $filters
    );
    if ($summary === null) {
        http_response_code(404);
        exit('Attendance records not found.');
    }
    $history = attendance_parent_list_records(
        $db,
        $parentId,
        $institutionId,
        $selectedStudentId,
        $filters
    );
}

render_app_page_start(
    'Attendance',
    'Review attendance recorded for your linked children.',
    'parent',
    ['parent-children.css', 'parent-attendance.css']
);
?>
<?php echo render_flash_messages(); ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb parent-children-breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/parent/dashboard')); ?>">Overview</a></li><li class="breadcrumb-item active" aria-current="page">Attendance</li></ol></nav>
<section class="parent-children-panel parent-attendance-panel">
    <div class="parent-children-panel-heading">
        <div><h2>Attendance</h2><p>Attendance records shared with your parent account.</p></div>
    </div>
    <?php if ($children === []): ?>
        <div class="parent-children-empty"><span><i class="bi bi-calendar2-x" aria-hidden="true"></i></span><h3>No linked students available</h3><p>No linked child currently has academic access to attendance records.</p></div>
    <?php else: ?>
        <div class="parent-attendance-student">
            <div><span class="parent-attendance-eyebrow">Selected child</span><h3><?php echo e($student['full_name']); ?></h3><p><?php echo e(implode(' · ', array_filter([$student['student_code'], $student['class_name'] ?? null, $student['batch_name'] ?? null]))); ?></p></div>
            <?php if (count($children) > 1): ?>
                <form method="get" action="<?php echo e(route_url('/parent/attendance')); ?>" class="parent-attendance-child-switcher">
                    <label for="attendance-child">Switch child</label>
                    <select id="attendance-child" name="student_id" class="form-select">
                        <?php foreach ($children as $child): ?>
                            <option value="<?php echo e((string) $child['id']); ?>"<?php echo (int) $child['id'] === $selectedStudentId ? ' selected' : ''; ?>><?php echo e($child['full_name'] . ' · ' . $child['student_code']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-outline-primary btn-sm" type="submit">View attendance</button>
                </form>
            <?php endif; ?>
        </div>
        <?php if ($filterError !== ''): ?><div class="alert alert-warning" role="alert"><?php echo e($filterError); ?> Showing unfiltered attendance.</div><?php endif; ?>
        <form class="parent-attendance-filters" method="get" action="<?php echo e(route_url('/parent/attendance')); ?>">
            <input type="hidden" name="student_id" value="<?php echo e((string) $selectedStudentId); ?>">
            <label>Status<select name="status" class="form-select"><option value="">All statuses</option><?php foreach (['present', 'absent', 'late'] as $option): ?><option value="<?php echo e($option); ?>"<?php echo $filters['status'] === $option ? ' selected' : ''; ?>><?php echo e(ucfirst($option)); ?></option><?php endforeach; ?></select></label>
            <label>From date<input class="form-control" type="date" name="from" value="<?php echo e($filters['date_from']); ?>"></label>
            <label>To date<input class="form-control" type="date" name="to" value="<?php echo e($filters['date_to']); ?>"></label>
            <button class="btn btn-cobalt" type="submit">Filter</button>
            <a class="btn btn-outline-secondary" href="<?php echo e(route_url('/parent/attendance') . '?student_id=' . $selectedStudentId); ?>">Reset</a>
        </form>
        <p class="parent-attendance-filter-note">The date range applies to the summary and history; status filters the history list.</p>
        <section class="parent-attendance-summary" aria-label="Attendance summary">
            <?php foreach ([
                ['Recorded days', $summary['total_days'], 'bi-calendar-check'],
                ['Present', $summary['present_days'], 'bi-check-circle'],
                ['Absent', $summary['absent_days'], 'bi-x-circle'],
                ['Late', $summary['late_days'], 'bi-clock-history'],
            ] as [$label, $value, $icon]): ?>
                <article><span aria-hidden="true"><i class="bi <?php echo e($icon); ?>"></i></span><div><small><?php echo e($label); ?></small><strong><?php echo e(number_format((int) $value)); ?></strong></div></article>
            <?php endforeach; ?>
            <article class="is-percentage"><span aria-hidden="true"><i class="bi bi-graph-up-arrow"></i></span><div><small>Attendance</small><strong><?php echo $summary['attendance_percentage'] === null ? '—' : e(number_format($summary['attendance_percentage'], 2) . '%'); ?></strong></div></article>
        </section>
        <div class="parent-attendance-history-heading"><div><h3>Attendance history</h3><p><?php echo e(number_format($history['total'])); ?> matching records</p></div></div>
        <?php if ($history['records'] === []): ?>
            <div class="parent-children-empty"><span><i class="bi bi-calendar2-week" aria-hidden="true"></i></span><h3>No attendance records found</h3><p>No attendance has been recorded for this child and date range, or no records match the selected status.</p></div>
        <?php else: ?>
            <div class="table-responsive"><table class="table parent-payments-table align-middle parent-attendance-table">
                <thead><tr><th>Date</th><th>Academic session</th><th>Class / batch</th><th>Status</th></tr></thead>
                <tbody><?php foreach ($history['records'] as $record): ?>
                    <tr><td><?php echo e(date('j M Y', strtotime((string) $record['attendance_date']))); ?></td><td><?php echo e($record['academic_year_name']); ?></td><td><?php echo e($record['class_name']); ?> / <?php echo e($record['batch_name']); ?></td><td><span class="parent-attendance-status is-<?php echo e($record['status']); ?>"><?php echo e(ucfirst($record['status'])); ?></span></td></tr>
                <?php endforeach; ?></tbody>
            </table></div>
            <?php if ($history['total_pages'] > 1): ?>
                <nav class="parent-payment-pagination" aria-label="Attendance pages"><span>Page <?php echo e((string) $history['page']); ?> of <?php echo e((string) $history['total_pages']); ?></span><div><?php for ($pageNumber = 1; $pageNumber <= $history['total_pages']; $pageNumber++): ?><a class="<?php echo $pageNumber === $history['page'] ? 'is-current' : ''; ?>" href="<?php echo e(route_url('/parent/attendance') . '?' . http_build_query(['student_id' => $selectedStudentId, 'status' => $filters['status'], 'from' => $filters['date_from'], 'to' => $filters['date_to'], 'page' => $pageNumber])); ?>"><?php echo e((string) $pageNumber); ?></a><?php endfor; ?></div></nav>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php render_app_page_end('parent'); ?>
