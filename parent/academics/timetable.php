<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/parent-portal-service.php';
require_once __DIR__ . '/../../services/timetable-management-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$db = db_connect();
$institutionId = authenticated_institution_id(['parent']);
if ($institutionId === null) {
    http_response_code(403);
    exit('Parent access is unavailable for this account.');
}
if (!academic_management_schema_ready($db)) {
    academic_management_render_schema_required_page();
    exit;
}
if (!timetable_management_schema_ready($db)) {
    http_response_code(503);
    exit('Timetable infrastructure is unavailable. Apply the additive timetable migration first.');
}
$context = parent_portal_require_current_parent($db);
$parentId = (int) $context['parent']['id'];
$children = parent_portal_get_children($db, $parentId, $institutionId);
$eligibleChildren = array_values(array_filter(
    $children,
    static fn(array $child): bool => $child['can_view_academics']
));
$studentId = filter_var($_GET['student_id'] ?? null, FILTER_VALIDATE_INT);
$studentId = $studentId !== false && $studentId !== null && $studentId > 0 ? (int) $studentId : 0;
if ($studentId < 1 && $eligibleChildren !== []) {
    $studentId = (int) $eligibleChildren[0]['id'];
}
$selectedChild = null;
foreach ($eligibleChildren as $child) {
    if ((int) $child['id'] === $studentId) {
        $selectedChild = $child;
        break;
    }
}
if ($studentId > 0 && ($selectedChild === null || !user_can_access_student($studentId, 'academics'))) {
    http_response_code(403);
    exit('You do not have academic timetable access for this student.');
}
$timetable = $studentId > 0
    ? timetable_management_student_timetable($db, $institutionId, $studentId)
    : ['group' => null, 'entries' => []];
$day = is_string($_GET['day'] ?? null) ? strtolower($_GET['day']) : '';
$days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
if ($day !== '' && !in_array($day, $days, true)) {
    $day = '';
}
$entries = array_values(array_filter(
    $timetable['entries'],
    static fn(array $entry): bool => $day === '' || $entry['day_of_week'] === $day
));

render_app_page_start(
    'Student timetable',
    'Published timetable for a linked child based on the child’s current academic placement.',
    'parent'
);
?>
<?php echo render_flash_messages(); ?>
<section class="card-panel mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
        <div>
            <span class="eyebrow">Academic schedule</span>
            <h2 class="h4 mb-1"><?php echo e($selectedChild['full_name'] ?? 'No child selected'); ?></h2>
            <?php if ($timetable['group'] !== null): ?>
                <p class="text-muted mb-0">
                    <?php echo e($timetable['group']['class_name']); ?>
                    <?php if ($timetable['group']['section_name'] !== null): ?> / <?php echo e($timetable['group']['section_name']); ?><?php endif; ?>
                    · <?php echo e($timetable['group']['academic_year_name']); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php if (count($eligibleChildren) > 1): ?>
        <form method="get" class="d-flex flex-wrap align-items-end gap-2">
            <div><label class="form-label" for="student_id">Child</label>
                <select class="form-select" name="student_id" id="student_id">
                    <?php foreach ($eligibleChildren as $child): ?>
                        <option value="<?php echo (int) $child['id']; ?>" <?php echo (int) $child['id'] === $studentId ? 'selected' : ''; ?>><?php echo e($child['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label class="form-label" for="day">View</label>
                <select class="form-select" name="day" id="day">
                    <option value="">Full week</option>
                    <?php foreach ($days as $weekday): ?><option value="<?php echo $weekday; ?>" <?php echo $day === $weekday ? 'selected' : ''; ?>><?php echo ucfirst($weekday); ?></option><?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn-outline-primary" type="submit">Show</button>
        </form>
        <?php else: ?>
        <form method="get" class="d-flex align-items-end gap-2">
            <input type="hidden" name="student_id" value="<?php echo $studentId; ?>">
            <div><label class="form-label" for="day">View</label>
                <select class="form-select" name="day" id="day">
                    <option value="">Full week</option>
                    <?php foreach ($days as $weekday): ?><option value="<?php echo $weekday; ?>" <?php echo $day === $weekday ? 'selected' : ''; ?>><?php echo ucfirst($weekday); ?></option><?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn-outline-primary" type="submit">Show</button>
        </form>
        <?php endif; ?>
    </div>
</section>
<section class="card-panel">
    <?php if ($timetable['group'] === null): ?>
        <p class="text-muted mb-0">No active academic placement is available for this child.</p>
    <?php elseif ($entries === []): ?>
        <p class="text-muted mb-0">No published timetable is available for the child’s current placement.</p>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>Day</th><th>Time</th><th>Subject</th><th>Teacher</th><th>Room</th></tr></thead>
            <tbody><?php foreach ($entries as $entry): ?>
                <tr>
                    <td><?php echo e(ucfirst($entry['day_of_week'])); ?></td>
                    <td><?php echo e(substr($entry['start_time'], 0, 5) . '–' . substr($entry['end_time'], 0, 5)); ?><br><small class="text-muted"><?php echo e($entry['period_label']); ?></small></td>
                    <td><?php echo e($entry['subject_name']); ?></td>
                    <td><?php echo e($entry['teacher_name']); ?></td>
                    <td><?php echo e(trim((string) $entry['room_code'] . ' ' . (string) $entry['room_name']) ?: '—'); ?></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php
render_app_page_end('parent');
