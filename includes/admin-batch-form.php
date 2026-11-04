<?php
declare(strict_types=1);

require_once __DIR__ . '/page-placeholder.php';
require_once __DIR__ . '/../services/admin-panel-service.php';

if (!array_key_exists('adminBatchId', $GLOBALS)
    || ($adminBatchId !== null && !is_int($adminBatchId))) {
    throw new LogicException('The batch form is not configured.');
}
require_http_methods(['GET', 'POST']);
require_placeholder_route_access();
$db = db_connect();
$institutionId = admin_panel_institution_id();
$user = current_user();
$batch = $adminBatchId === null ? null : admin_panel_batch($db, $institutionId, $adminBatchId);
if ($adminBatchId !== null && $batch === null) {
    http_response_code(404);
    exit('Batch not found.');
}
$courses = admin_panel_simple_list($db, $institutionId, 'courses');
$classes = admin_panel_simple_list($db, $institutionId, 'classes');
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    try {
        $values = admin_panel_validate_batch($_POST);
        admin_panel_save_batch($db, $institutionId, (int) $user['id'], $values, $adminBatchId);
        set_flash('success', $adminBatchId === null ? 'Batch created.' : 'Batch updated.');
        safe_redirect('/admin/batches/index', 303);
    } catch (InvalidArgumentException $exception) {
        $errors[] = $exception->getMessage();
    } catch (Throwable $exception) {
        log_error('admin_batch_save_failed', [
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
        ]);
        $errors[] = 'The batch could not be saved. Check that its code is not already in use.';
    }
}
$formValues = [];
foreach (['name', 'code', 'course_id', 'class_id', 'start_time', 'end_time', 'capacity', 'status'] as $field) {
    $formValues[$field] = is_string($_POST[$field] ?? null)
        ? $_POST[$field]
        : (string) ($batch[$field] ?? ($field === 'status' ? 'active' : ''));
}
$title = $adminBatchId === null ? 'Create Batch' : 'Edit Batch';
render_app_page_start(
    $title,
    $adminBatchId === null
        ? 'Set up a batch and connect it with its course and class.'
        : 'Update the batch details and scheduling for this institution.',
    'admin',
    ['admin-dashboard.css', 'admin-batch-form.css']
);
?>
<?php echo render_flash_messages(); ?>
<nav class="batch-form-breadcrumb" aria-label="Breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/batches/index')); ?>">Batches</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?php echo e($title); ?></li>
    </ol>
</nav>
<?php foreach ($errors as $error): ?><div class="alert alert-danger batch-form-error" role="alert"><?php echo e($error); ?></div><?php endforeach; ?>
<section class="batch-form-card">
    <header class="batch-form-header">
        <span class="batch-form-icon" aria-hidden="true"><i class="bi bi-people"></i></span>
        <div>
            <span class="batch-form-eyebrow"><?php echo $adminBatchId === null ? 'Batch setup' : 'Batch details'; ?></span>
            <h2><?php echo $adminBatchId === null ? 'Create a new batch' : 'Edit batch details'; ?></h2>
            <p>Organize learners into a course and class, then add the schedule and capacity.</p>
        </div>
    </header>
    <form method="post" action="<?php echo e(route_url($adminBatchId === null ? '/admin/batches/create' : '/admin/batches/edit') . ($adminBatchId === null ? '' : '?id=' . $adminBatchId)); ?>" class="batch-form">
        <?php echo csrf_field(); ?>
        <div class="batch-form-section-heading">
            <span aria-hidden="true"><i class="bi bi-journal-bookmark"></i></span>
            <div>
                <h3>Batch information</h3>
                <p>Choose a name and assign the batch to an existing course and class.</p>
            </div>
        </div>
        <label class="batch-form-field">
            <span>Batch name <b aria-hidden="true">*</b></span>
            <input class="form-control" name="name" maxlength="120" required value="<?php echo e($formValues['name']); ?>" placeholder="e.g. Morning batch">
        </label>
        <label class="batch-form-field">
            <span>Batch code <small>Optional</small></span>
            <input class="form-control" name="code" maxlength="50" value="<?php echo e($formValues['code']); ?>" placeholder="e.g. MTH-01">
        </label>
        <label class="batch-form-field">
            <span>Course <b aria-hidden="true">*</b></span>
            <select class="form-select" name="course_id" required>
                <option value="">Select a course</option>
                <?php foreach ($courses as $courseOption): ?>
                    <option value="<?php echo e((string) $courseOption['id']); ?>"<?php echo $formValues['course_id'] === (string) $courseOption['id'] ? ' selected' : ''; ?>><?php echo e((string) $courseOption['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="batch-form-field">
            <span>Class <b aria-hidden="true">*</b></span>
            <select class="form-select" name="class_id" required>
                <option value="">Select a class</option>
                <?php foreach ($classes as $classOption): ?>
                    <option value="<?php echo e((string) $classOption['id']); ?>"<?php echo $formValues['class_id'] === (string) $classOption['id'] ? ' selected' : ''; ?>><?php echo e((string) $classOption['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="batch-form-section-heading batch-form-section-spaced">
            <span aria-hidden="true"><i class="bi bi-clock-history"></i></span>
            <div>
                <h3>Schedule and availability</h3>
                <p>Set the usual meeting times, maximum capacity, and current status.</p>
            </div>
        </div>
        <label class="batch-form-field">
            <span>Starts at <small>Optional</small></span>
            <input class="form-control" type="time" name="start_time" value="<?php echo e(substr($formValues['start_time'], 0, 5)); ?>">
        </label>
        <label class="batch-form-field">
            <span>Ends at <small>Optional</small></span>
            <input class="form-control" type="time" name="end_time" value="<?php echo e(substr($formValues['end_time'], 0, 5)); ?>">
        </label>
        <label class="batch-form-field">
            <span>Capacity <small>Optional</small></span>
            <input class="form-control" type="number" min="1" max="1000000" name="capacity" value="<?php echo e($formValues['capacity']); ?>" placeholder="Set a learner limit">
        </label>
        <label class="batch-form-field">
            <span>Status</span>
            <select class="form-select" name="status">
                <?php foreach (['active', 'inactive', 'full'] as $status): ?>
                    <option value="<?php echo e($status); ?>"<?php echo $formValues['status'] === $status ? ' selected' : ''; ?>><?php echo e(ucfirst($status)); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <footer class="batch-form-footer">
            <a class="batch-form-cancel" href="<?php echo e(route_url('/admin/batches/index')); ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to batches</a>
            <button class="btn btn-cobalt batch-form-submit" type="submit">
                <i class="bi bi-check2" aria-hidden="true"></i>
                <?php echo e($adminBatchId === null ? 'Create batch' : 'Save changes'); ?>
            </button>
        </footer>
    </form>
</section>
<?php render_app_page_end('admin'); ?>
