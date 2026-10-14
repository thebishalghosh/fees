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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    try {
        $action = $_POST['action'] ?? null;
        if ($action === 'save') {
            $examId = filter_var($_POST['exam_id'] ?? null, FILTER_VALIDATE_INT);
            if ($examId === false || $examId < 0) {
                throw new InvalidArgumentException('The examination identifier is invalid.');
            }
            examination_results_save_exam(
                $db,
                $institutionId,
                (int) $user['id'],
                $_POST,
                $examId > 0 ? (int) $examId : null
            );
            set_flash('success', $examId > 0 ? 'Examination updated.' : 'Examination created.');
        } elseif ($action === 'publication') {
            $examId = filter_var($_POST['exam_id'] ?? null, FILTER_VALIDATE_INT);
            $published = $_POST['published'] ?? null;
            if ($examId === false || $examId < 1 || !in_array($published, ['0', '1'], true)) {
                throw new InvalidArgumentException('The result publication action is invalid.');
            }
            examination_results_set_publication(
                $db,
                $institutionId,
                (int) $user['id'],
                (int) $examId,
                $published === '1'
            );
            set_flash('success', $published === '1' ? 'Results published to authorized parents.' : 'Results unpublished.');
        } else {
            throw new InvalidArgumentException('Select a supported examination action.');
        }
        safe_redirect('/admin/academics/examinations', 303);
    } catch (InvalidArgumentException | DomainException | OutOfBoundsException $exception) {
        $errors[] = $exception->getMessage();
    } catch (Throwable $exception) {
        log_error('admin_examination_save_failed', [
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
        ]);
        $errors[] = 'The examination could not be saved. Please try again.';
    }
}

$editId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT);
$editExam = null;
if ($editId !== false && $editId !== null && $editId > 0) {
    $editExam = examination_results_get_exam($db, $institutionId, (int) $editId);
    if (!is_array($editExam)) {
        http_response_code(404);
        exit('Examination not found.');
    }
}
$years = examination_results_get_academic_years($db, $institutionId);
$batches = examination_results_get_batches($db, $institutionId);
$classes = examination_results_get_classes($db, $institutionId);
$filters = [
    'query' => is_string($_GET['query'] ?? null) ? mb_substr(trim($_GET['query']), 0, 150) : '',
    'class_id' => filter_var($_GET['class_id'] ?? 0, FILTER_VALIDATE_INT),
    'academic_year_id' => filter_var($_GET['academic_year_id'] ?? 0, FILTER_VALIDATE_INT),
];
if ($filters['class_id'] === false || $filters['class_id'] < 1) {
    $filters['class_id'] = 0;
}
if ($filters['academic_year_id'] === false || $filters['academic_year_id'] < 1) {
    $filters['academic_year_id'] = 0;
}
$exams = examination_results_list_exams($db, $institutionId, $filters);

render_app_page_start(
    'Examinations & Results',
    'Schedule examinations, enter student marks, and publish results to authorized families.',
    'admin',
    ['admin-dashboard.css', 'examination-results.css']
);
?>
<?php echo render_flash_messages(); ?>
<?php foreach ($errors as $error): ?><div class="alert alert-danger" role="alert"><?php echo e($error); ?></div><?php endforeach; ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item active" aria-current="page">Examinations</li></ol></nav>
<section class="dashboard-panel exam-admin-form-panel">
    <div class="exam-section-heading"><div><span class="exam-section-kicker">Academic management</span><h2><?php echo is_array($editExam) ? 'Update examination' : 'Schedule an examination'; ?></h2><p>Examinations are linked to an institution batch and academic session.</p></div></div>
    <?php if ($years === [] || $batches === []): ?>
        <div class="exam-empty-note"><i class="bi bi-info-circle" aria-hidden="true"></i><span>Add an active academic session and batch before scheduling an examination.</span></div>
    <?php else: ?>
        <?php
        $formValues = is_array($editExam) ? $editExam : [];
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? null) === 'save') {
            $formValues = $_POST;
        }
        ?>
        <form method="post" action="<?php echo e(route_url('/admin/academics/examinations')); ?>" class="exam-admin-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="exam_id" value="<?php echo e((string) ($formValues['id'] ?? ($_POST['exam_id'] ?? '0'))); ?>">
            <label class="exam-field"><span>Examination name</span><input class="form-control" name="name" maxlength="150" required value="<?php echo e(is_string($formValues['name'] ?? null) ? $formValues['name'] : ''); ?>" placeholder="e.g. Mid-term assessment"></label>
            <label class="exam-field"><span>Academic session</span><select class="form-select" name="academic_year_id" required><option value="">Choose session</option><?php foreach ($years as $year): ?><option value="<?php echo e((string) $year['id']); ?>"<?php echo (string) ($formValues['academic_year_id'] ?? '') === (string) $year['id'] ? ' selected' : ''; ?>><?php echo e($year['name']); ?></option><?php endforeach; ?></select></label>
            <label class="exam-field"><span>Batch (class and course)</span><select class="form-select" name="batch_id" required><option value="">Choose batch</option><?php foreach ($batches as $batch): ?><option value="<?php echo e((string) $batch['id']); ?>"<?php echo (string) ($formValues['batch_id'] ?? '') === (string) $batch['id'] ? ' selected' : ''; ?>><?php echo e($batch['class_name'] . ' · ' . $batch['course_name'] . ' · ' . $batch['batch_name']); ?></option><?php endforeach; ?></select></label>
            <label class="exam-field"><span>Examination date</span><input class="form-control" type="date" name="exam_date" required value="<?php echo e(is_string($formValues['exam_date'] ?? null) ? $formValues['exam_date'] : ''); ?>"></label>
            <label class="exam-field"><span>Maximum marks</span><input class="form-control" type="number" name="max_marks" min="0.01" max="999999.99" step="0.01" required value="<?php echo e(is_string($formValues['max_marks'] ?? null) ? $formValues['max_marks'] : ''); ?>"></label>
            <label class="exam-field exam-field-wide"><span>Description <small>Optional</small></span><textarea class="form-control" name="description" rows="3" maxlength="5000"><?php echo e(is_string($formValues['description'] ?? null) ? $formValues['description'] : ''); ?></textarea></label>
            <div class="exam-form-actions"><button class="btn btn-cobalt" type="submit"><i class="bi bi-check2-circle" aria-hidden="true"></i><?php echo is_array($editExam) ? 'Update examination' : 'Create examination'; ?></button><?php if (is_array($editExam)): ?><a class="btn btn-outline-secondary" href="<?php echo e(route_url('/admin/academics/examinations')); ?>">Cancel edit</a><?php endif; ?></div>
        </form>
    <?php endif; ?>
</section>

<section class="dashboard-panel exam-list-panel">
    <div class="exam-list-heading"><div><h2>Scheduled examinations</h2><p>Search by examination or student; narrow the list by class and academic session.</p></div></div>
    <form method="get" action="<?php echo e(route_url('/admin/academics/examinations')); ?>" class="exam-filter-form">
        <label><span>Examination or student</span><input class="form-control" type="search" name="query" value="<?php echo e($filters['query']); ?>" placeholder="Search name"></label>
        <label><span>Class</span><select class="form-select" name="class_id"><option value="0">All classes</option><?php foreach ($classes as $class): ?><option value="<?php echo e((string) $class['id']); ?>"<?php echo (string) $filters['class_id'] === (string) $class['id'] ? ' selected' : ''; ?>><?php echo e($class['name']); ?></option><?php endforeach; ?></select></label>
        <label><span>Academic session</span><select class="form-select" name="academic_year_id"><option value="0">All sessions</option><?php foreach ($years as $year): ?><option value="<?php echo e((string) $year['id']); ?>"<?php echo (string) $filters['academic_year_id'] === (string) $year['id'] ? ' selected' : ''; ?>><?php echo e($year['name']); ?></option><?php endforeach; ?></select></label>
        <button class="btn btn-outline-primary" type="submit"><i class="bi bi-search" aria-hidden="true"></i> Filter</button>
    </form>
    <?php if ($exams === []): ?>
        <div class="admin-panel-empty"><i class="bi bi-journal-x" aria-hidden="true"></i><strong>No examinations found</strong><span>Schedule an examination or adjust your search filters.</span></div>
    <?php else: ?>
        <div class="table-responsive"><table class="table admin-panel-table align-middle">
            <thead><tr><th>Examination</th><th>Class / course / batch</th><th>Session</th><th>Date</th><th>Max marks</th><th>Results</th><th>Actions</th></tr></thead>
            <tbody><?php foreach ($exams as $exam): ?>
                <tr>
                    <td><strong><?php echo e($exam['name']); ?></strong></td>
                    <td><?php echo e($exam['class_name'] . ' · ' . $exam['course_name'] . ' · ' . $exam['batch_name']); ?></td>
                    <td><?php echo e($exam['academic_year_name'] ?? 'Session not set'); ?></td>
                    <td><?php echo e(date('j M Y', strtotime((string) $exam['exam_date']))); ?></td>
                    <td><?php echo $exam['max_marks'] === null ? '—' : e(number_format((float) $exam['max_marks'], 2)); ?></td>
                    <td><span class="exam-publication-badge<?php echo $exam['results_published_at'] !== null ? ' is-published' : ''; ?>"><?php echo $exam['results_published_at'] !== null ? 'Published' : 'Unpublished'; ?></span><small class="exam-result-count"><?php echo e((string) $exam['result_count']); ?> entered</small></td>
                    <td><div class="exam-row-actions"><a class="btn btn-sm btn-outline-primary" href="<?php echo e(route_url('/admin/academics/results') . '?id=' . (int) $exam['id']); ?>">Enter marks</a><a class="btn btn-sm btn-outline-secondary" href="<?php echo e(route_url('/admin/academics/examinations') . '?edit=' . (int) $exam['id']); ?>">Edit</a><form method="post" action="<?php echo e(route_url('/admin/academics/examinations')); ?>"><?php echo csrf_field(); ?><input type="hidden" name="action" value="publication"><input type="hidden" name="exam_id" value="<?php echo e((string) $exam['id']); ?>"><input type="hidden" name="published" value="<?php echo $exam['results_published_at'] !== null ? '0' : '1'; ?>"><button class="btn btn-sm <?php echo $exam['results_published_at'] !== null ? 'btn-outline-danger' : 'btn-outline-success'; ?>" type="submit"><?php echo $exam['results_published_at'] !== null ? 'Unpublish' : 'Publish'; ?></button></form></div></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php render_app_page_end('admin'); ?>
