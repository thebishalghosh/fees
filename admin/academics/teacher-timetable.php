<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/timetable-management-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$user = current_user();
$institutionId = authenticated_institution_id(['super_admin', 'admin', 'staff']);
if ($user === null || $institutionId === null) {
    http_response_code(403);
    exit('Institution access is unavailable for this account.');
}
$db = db_connect();
if (!academic_management_schema_ready($db)) {
    academic_management_render_schema_required_page();
    exit;
}
if (!timetable_management_schema_ready($db)) {
    http_response_code(503);
    exit('Timetable infrastructure is unavailable. Apply the additive timetable migration first.');
}
$configuration = academic_management_configuration($db, $institutionId);
$years = academic_management_list_years($db, $institutionId);
$requestedYearId = filter_var($_GET['academic_year_id'] ?? null, FILTER_VALIDATE_INT);
$requestedYearId = $requestedYearId !== false && $requestedYearId !== null && $requestedYearId > 0
    ? (int) $requestedYearId
    : null;
$availableYearIds = array_map(static fn(array $year): int => (int) $year['id'], $years);
$yearId = $requestedYearId !== null && in_array($requestedYearId, $availableYearIds, true)
    ? $requestedYearId
    : ($configuration['active_academic_year_id'] !== null
        && in_array((int) $configuration['active_academic_year_id'], $availableYearIds, true)
            ? (int) $configuration['active_academic_year_id']
            : ($availableYearIds[0] ?? null));
$errors = [];
$timetable = ['teacher' => null, 'entries' => [], 'workload_minutes' => 0];
try {
    $timetable = timetable_management_teacher_timetable(
        $db,
        $institutionId,
        (int) $user['id'],
        $yearId
    );
} catch (DomainException $exception) {
    $errors[] = $exception->getMessage();
}
$selectedDay = is_string($_GET['day'] ?? null) ? strtolower($_GET['day']) : '';
$weekDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
foreach ($timetable['entries'] as $entry) {
    if ($entry['day_of_week'] === 'sunday' && !in_array('sunday', $weekDays, true)) {
        $weekDays[] = 'sunday';
    }
}
if (!in_array($selectedDay, $weekDays, true)) {
    $selectedDay = '';
}
$entriesByDay = array_fill_keys($weekDays, []);
foreach ($timetable['entries'] as $entry) {
    $entriesByDay[$entry['day_of_week']][] = $entry;
}
$visibleDays = $selectedDay === '' ? $weekDays : [$selectedDay];
$minutes = (int) $timetable['workload_minutes'];
$cohorts = array_unique(array_column($timetable['entries'], 'cohort_name'));
$subjects = array_unique(array_column($timetable['entries'], 'subject_name'));
$teachingDays = count(array_filter(
    $entriesByDay,
    static fn(array $dayEntries): bool => $dayEntries !== []
));
$selectedYearName = '';
foreach ($years as $year) {
    if ((int) $year['id'] === $yearId) {
        $selectedYearName = (string) $year['name'];
        break;
    }
}
$today = strtolower(date('l'));

render_app_page_start(
    'My timetable',
    'Your published classes, subjects, rooms, and weekly teaching workload.',
    'admin',
    ['admin-dashboard.css', 'admin-teacher-timetable.css']
);
?>
<div class="teacher-timetable-page">
<?php echo render_flash_messages(); ?>
<?php if ($errors !== []): ?>
    <div class="alert alert-warning teacher-timetable-alert" role="status"><?php foreach ($errors as $error): ?><p class="mb-1"><?php echo e($error); ?></p><?php endforeach; ?></div>
<?php endif; ?>
<section class="teacher-timetable-hero" aria-labelledby="teacher-timetable-title">
    <div class="teacher-timetable-hero-copy">
        <span class="teacher-timetable-kicker"><i class="bi bi-calendar2-week" aria-hidden="true"></i> Personal schedule</span>
        <h2 id="teacher-timetable-title"><?php echo e($timetable['teacher']['full_name'] ?? ($user['full_name'] ?? 'Teacher')); ?></h2>
        <p><?php echo $selectedYearName !== '' ? e($selectedYearName) . ' · ' : ''; ?>Published teaching timetable</p>
    </div>
    <form method="get" class="teacher-timetable-filters">
        <div>
            <label class="form-label" for="academic_year_id">Academic year</label>
            <select class="form-select" name="academic_year_id" id="academic_year_id">
                <?php foreach ($years as $year): ?>
                    <option value="<?php echo (int) $year['id']; ?>" <?php echo (int) $year['id'] === $yearId ? 'selected' : ''; ?>>
                        <?php echo e($year['name'] . ' · ' . ucfirst((string) $year['status'])); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="day">Day</label>
            <select class="form-select" name="day" id="day">
                <option value="">Full week</option>
                <?php foreach ($weekDays as $weekday): ?>
                    <option value="<?php echo e($weekday); ?>" <?php echo $selectedDay === $weekday ? 'selected' : ''; ?>><?php echo e(ucfirst($weekday)); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Apply</button>
    </form>
</section>
<section class="teacher-timetable-stats" aria-label="Weekly workload summary">
    <article class="teacher-timetable-stat">
        <span class="teacher-timetable-stat-icon"><i class="bi bi-clock-history" aria-hidden="true"></i></span>
        <div><small>Weekly workload</small><strong><?php echo intdiv($minutes, 60); ?>h <?php echo $minutes % 60; ?>m</strong></div>
    </article>
    <article class="teacher-timetable-stat">
        <span class="teacher-timetable-stat-icon"><i class="bi bi-journal-check" aria-hidden="true"></i></span>
        <div><small>Teaching periods</small><strong><?php echo count($timetable['entries']); ?></strong></div>
    </article>
    <article class="teacher-timetable-stat">
        <span class="teacher-timetable-stat-icon"><i class="bi bi-mortarboard" aria-hidden="true"></i></span>
        <div><small>Classes / sections</small><strong><?php echo count($cohorts); ?></strong></div>
    </article>
    <article class="teacher-timetable-stat">
        <span class="teacher-timetable-stat-icon"><i class="bi bi-calendar-week" aria-hidden="true"></i></span>
        <div><small>Teaching days</small><strong><?php echo $teachingDays; ?></strong></div>
    </article>
</section>
<section class="teacher-timetable-week" aria-labelledby="teacher-week-title">
    <div class="teacher-timetable-week-heading">
        <div>
            <span class="teacher-timetable-kicker">Weekly overview</span>
            <h2 id="teacher-week-title">Your teaching week</h2>
        </div>
        <span class="teacher-timetable-subject-count"><?php echo count($subjects); ?> subject<?php echo count($subjects) === 1 ? '' : 's'; ?></span>
    </div>
    <div class="teacher-timetable-day-grid">
        <?php foreach ($visibleDays as $weekday): ?>
            <?php $dayEntries = $entriesByDay[$weekday] ?? []; ?>
            <article class="teacher-day-card<?php echo $weekday === $today ? ' is-today' : ''; ?>">
                <header class="teacher-day-heading">
                    <h3><?php echo e(ucfirst($weekday)); ?><?php if ($weekday === $today): ?><span>Today</span><?php endif; ?></h3>
                    <small><?php echo count($dayEntries); ?> period<?php echo count($dayEntries) === 1 ? '' : 's'; ?></small>
                </header>
                <?php if ($dayEntries === []): ?>
                    <div class="teacher-day-empty"><i class="bi bi-calendar2-check" aria-hidden="true"></i><span>No classes scheduled</span></div>
                <?php else: ?>
                    <ol class="teacher-day-lessons">
                        <?php foreach ($dayEntries as $entry): ?>
                            <li class="teacher-lesson">
                                <span class="teacher-lesson-time">
                                    <strong><?php echo e(substr($entry['start_time'], 0, 5)); ?></strong>
                                    <small><?php echo e(substr($entry['end_time'], 0, 5)); ?></small>
                                </span>
                                <div class="teacher-lesson-content">
                                    <span class="teacher-lesson-period"><?php echo e($entry['period_label']); ?></span>
                                    <strong><?php echo e($entry['subject_name']); ?></strong>
                                    <span><?php echo e($entry['cohort_name']); ?></span>
                                    <small><i class="bi bi-geo-alt" aria-hidden="true"></i>
                                        <?php echo e(trim((string) $entry['room_code'] . ' ' . (string) $entry['room_name']) ?: 'Room not assigned'); ?>
                                    </small>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</section>
</div>
<?php
render_app_page_end('admin');
