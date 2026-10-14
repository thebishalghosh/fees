<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/admin-panel-service.php';
require_once __DIR__ . '/../../services/examination-results-service.php';

require_http_methods(['GET', 'POST']);
require_placeholder_route_access();
$db = db_connect();
$institutionId = admin_panel_institution_id();
$user = current_user();
$errors = [];
$examId = filter_var(
    $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['exam_id'] ?? null) : ($_GET['id'] ?? null),
    FILTER_VALIDATE_INT
);
if ($examId === false || $examId < 1) {
    http_response_code(404);
    exit('Examination not found.');
}
$exam = examination_results_get_exam($db, $institutionId, (int) $examId);
if (!is_array($exam)) {
    http_response_code(404);
    exit('Examination not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    if (($_POST['action'] ?? null) !== 'save_marks' || !is_array($_POST['marks'] ?? null)) {
        $errors[] = 'The result form contains invalid values.';
    } else {
        try {
            $saved = examination_results_save_marks(
                $db,
                $institutionId,
                (int) $user['id'],
                (int) $examId,
                $_POST['marks']
            );
            set_flash('success', $saved === 1 ? 'Student result saved.' : number_format($saved) . ' student results saved.');
            safe_redirect('/admin/academics/results?id=' . (int) $examId, 303);
        } catch (InvalidArgumentException | DomainException | OutOfBoundsException $exception) {
            $errors[] = $exception->getMessage();
        } catch (Throwable $exception) {
            log_error('admin_examination_results_save_failed', [
                'institution_id' => $institutionId,
                'examination_id' => (int) $examId,
                'error_type' => get_class($exception),
                'error_code' => $exception->getCode(),
            ]);
            $errors[] = 'Student results could not be saved. Please try again.';
        }
    }
}

$search = is_string($_GET['student'] ?? null) ? trim($_GET['student']) : '';
$roster = examination_results_get_roster($db, $institutionId, (int) $examId, $search);
render_app_page_start(
    'Enter Examination Results',
    'Record or update marks for students in this examination batch.',
    'admin',
    ['admin-dashboard.css', 'examination-results.css']
);
?>
<?php echo render_flash_messages(); ?>
<?php foreach ($errors as $error): ?><div class="alert alert-danger" role="alert"><?php echo e($error); ?></div><?php endforeach; ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/academics/examinations')); ?>">Examinations</a></li><li class="breadcrumb-item active" aria-current="page">Enter results</li></ol></nav>
<section class="dashboard-panel exam-detail-card">
    <div><span class="exam-section-kicker">Results entry</span><h2><?php echo e($exam['name']); ?></h2><p><?php echo e($exam['class_name'] . ' · ' . $exam['course_name'] . ' · ' . $exam['batch_name'] . ' · ' . ($exam['academic_year_name'] ?? 'Session not set')); ?></p></div>
    <dl><div><dt>Exam date</dt><dd><?php echo e(date('j M Y', strtotime((string) $exam['exam_date']))); ?></dd></div><div><dt>Maximum marks</dt><dd><?php echo $exam['max_marks'] === null ? 'Not configured' : e(number_format((float) $exam['max_marks'], 2)); ?></dd></div><div><dt>Parent visibility</dt><dd><?php echo $exam['results_published_at'] !== null ? 'Published' : 'Not published'; ?></dd></div></dl>
</section>
<?php if ($exam['max_marks'] === null): ?>
    <div class="alert alert-warning" role="alert">Set maximum marks for this examination before entering results. <a href="<?php echo e(route_url('/admin/academics/examinations') . '?edit=' . (int) $exam['id']); ?>">Edit examination</a></div>
<?php else: ?>
    <section class="dashboard-panel exam-results-panel">
        <div class="exam-list-heading"><div><h2>Student marks</h2><p>Leave a mark blank to leave that student unchanged. Maximum: <?php echo e(number_format((float) $exam['max_marks'], 2)); ?>.</p></div></div>
        <form method="get" action="<?php echo e(route_url('/admin/academics/results')); ?>" class="exam-student-search"><?php echo '<input type="hidden" name="id" value="' . e((string) $examId) . '">'; ?><label><span>Find student</span><input class="form-control" type="search" name="student" value="<?php echo e($search); ?>" placeholder="Name or student ID"></label><button class="btn btn-outline-primary" type="submit"><i class="bi bi-search" aria-hidden="true"></i> Search</button><?php if ($search !== ''): ?><a class="btn btn-link" href="<?php echo e(route_url('/admin/academics/results') . '?id=' . (int) $examId); ?>">Clear</a><?php endif; ?></form>
        <?php if ($roster === []): ?>
            <div class="admin-panel-empty"><i class="bi bi-people" aria-hidden="true"></i><strong>No students found</strong><span>There are no students for this batch and academic session matching your search.</span></div>
        <?php else: ?>
            <form method="post" action="<?php echo e(route_url('/admin/academics/results')); ?>" class="exam-marks-form">
                <?php echo csrf_field(); ?><input type="hidden" name="action" value="save_marks"><input type="hidden" name="exam_id" value="<?php echo e((string) $examId); ?>">
                <div class="table-responsive"><table class="table admin-panel-table align-middle">
                    <thead><tr><th>Student</th><th>Student ID</th><th>Marks / <?php echo e(number_format((float) $exam['max_marks'], 2)); ?></th><th>Percentage</th><th>Result</th></tr></thead>
                    <tbody><?php foreach ($roster as $student): ?>
                        <?php $hasMark = $student['marks_obtained'] !== null; ?>
                        <tr>
                            <td><?php echo e($student['full_name']); ?></td><td><?php echo e($student['student_code']); ?></td>
                            <td><input class="form-control exam-mark-input" type="number" min="0" max="<?php echo e((string) $exam['max_marks']); ?>" step="0.01" name="marks[<?php echo (int) $student['student_id']; ?>]" value="<?php echo $hasMark ? e((string) $student['marks_obtained']) : ''; ?>" aria-label="Marks for <?php echo e($student['full_name']); ?>"></td>
                            <td><?php echo $hasMark ? e(examination_results_percentage((string) $student['marks_obtained'], (string) $exam['max_marks'])) . '%' : '—'; ?></td>
                            <td><?php echo $hasMark ? 'Recorded' : 'Not entered'; ?></td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table></div>
                <div class="exam-form-actions"><button class="btn btn-cobalt" type="submit"><i class="bi bi-save" aria-hidden="true"></i> Save marks</button><a class="btn btn-outline-secondary" href="<?php echo e(route_url('/admin/academics/examinations')); ?>">Back to examinations</a></div>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php render_app_page_end('admin'); ?>
