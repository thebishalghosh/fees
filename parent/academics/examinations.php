<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/parent-portal-service.php';
require_once __DIR__ . '/../../services/examination-results-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$db = db_connect();
$context = parent_portal_require_current_parent($db);
$parentId = (int) $context['parent']['parent_id'];
$institutionId = (int) $context['user']['institution_id'];
$children = examination_results_get_parent_students($db, $parentId, $institutionId);
$requestedStudentId = filter_var($_GET['student_id'] ?? null, FILTER_VALIDATE_INT);
$selectedStudentId = $requestedStudentId === false || $requestedStudentId === null || $requestedStudentId < 1
    ? (int) ($children[0]['id'] ?? 0)
    : (int) $requestedStudentId;
$studentIsLinked = false;
foreach ($children as $child) {
    if ((int) $child['id'] === $selectedStudentId) {
        $studentIsLinked = true;
        break;
    }
}
if ($selectedStudentId > 0 && !$studentIsLinked) {
    http_response_code(404);
    exit('Student results not found.');
}
$studentResults = $selectedStudentId > 0
    ? examination_results_get_published_for_parent(
        $db,
        $parentId,
        $institutionId,
        $selectedStudentId
    )
    : null;
if ($selectedStudentId > 0 && !is_array($studentResults)) {
    http_response_code(404);
    exit('Student results not found.');
}

render_app_page_start(
    'Examination Results',
    'View published examination results for your children.',
    'parent',
    ['parent-children.css', 'examination-results.css']
);
?>
<?php echo render_flash_messages(); ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb parent-children-breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/parent/dashboard')); ?>">Overview</a></li><li class="breadcrumb-item active" aria-current="page">Examination results</li></ol></nav>
<?php if ($children === []): ?>
    <section class="parent-children-panel">
        <div class="parent-children-empty"><span><i class="bi bi-journal-check" aria-hidden="true"></i></span><h2>No examination results available</h2><p>No linked child currently has academic access to examination results.</p></div>
    </section>
<?php else: ?>
    <section class="parent-children-panel exam-parent-panel">
        <div class="exam-parent-toolbar">
            <div><span class="exam-section-kicker">Academic record</span><h2><?php echo e($studentResults['full_name']); ?></h2><p><?php echo e(implode(' · ', array_filter([$studentResults['student_code'], $studentResults['class_name'], $studentResults['batch_name']]))); ?></p></div>
            <?php if (count($children) > 1): ?>
                <form method="get" action="<?php echo e(route_url('/parent/academics/examinations')); ?>" class="exam-child-switcher">
                    <label for="exam-child">Select child</label>
                    <select class="form-select" id="exam-child" name="student_id">
                        <?php foreach ($children as $child): ?><option value="<?php echo e((string) $child['id']); ?>"<?php echo (int) $child['id'] === $selectedStudentId ? ' selected' : ''; ?>><?php echo e($child['full_name'] . ' · ' . $child['student_code']); ?></option><?php endforeach; ?>
                    </select>
                    <button class="btn btn-outline-primary btn-sm mt-2" type="submit">View results</button>
                </form>
            <?php endif; ?>
        </div>
        <?php if ($studentResults['results'] === []): ?>
            <div class="parent-children-empty exam-no-published-results"><span><i class="bi bi-hourglass-split" aria-hidden="true"></i></span><h3>No published results yet</h3><p>Published examination results for this child will appear here when the institution makes them available.</p></div>
        <?php else: ?>
            <div class="table-responsive"><table class="table parent-payments-table align-middle exam-parent-table">
                <thead><tr><th>Examination</th><th>Session</th><th>Date</th><th>Course</th><th>Marks</th><th>Percentage</th><th>Status</th></tr></thead>
                <tbody><?php foreach ($studentResults['results'] as $result): ?>
                    <tr><td><strong><?php echo e($result['examination_name']); ?></strong></td><td><?php echo e($result['academic_year_name'] ?? '—'); ?></td><td><?php echo e(date('j M Y', strtotime((string) $result['exam_date']))); ?></td><td><?php echo e($result['course_name'] ?? '—'); ?></td><td><?php echo e(number_format((float) $result['marks_obtained'], 2)); ?> / <?php echo e(number_format((float) $result['max_marks'], 2)); ?></td><td><strong><?php echo e($result['percentage']); ?>%</strong></td><td><span class="exam-publication-badge is-published">Published</span></td></tr>
                <?php endforeach; ?></tbody>
            </table></div>
            <p class="exam-grading-note"><i class="bi bi-info-circle" aria-hidden="true"></i> Percentages show marks earned out of the examination maximum. No pass/fail threshold is configured.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php render_app_page_end('parent'); ?>
