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
render_app_page_start($title, 'Maintain batch details within this institution.', 'admin', ['admin-dashboard.css']);
?>
<?php echo render_flash_messages(); ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/batches/index')); ?>">Batches</a></li><li class="breadcrumb-item active" aria-current="page"><?php echo e($title); ?></li></ol></nav>
<?php foreach ($errors as $error): ?><div class="alert alert-danger" role="alert"><?php echo e($error); ?></div><?php endforeach; ?>
<section class="dashboard-panel admin-form-panel">
    <form method="post" action="<?php echo e(route_url($adminBatchId === null ? '/admin/batches/create' : '/admin/batches/edit') . ($adminBatchId === null ? '' : '?id=' . $adminBatchId)); ?>" class="admin-form-grid">
        <?php echo csrf_field(); ?>
        <label class="admin-form-field">Batch name<input class="form-control" name="name" maxlength="120" required value="<?php echo e($formValues['name']); ?>"></label>
        <label class="admin-form-field">Batch code<input class="form-control" name="code" maxlength="50" value="<?php echo e($formValues['code']); ?>"></label>
        <label class="admin-form-field">Course<select class="form-select" name="course_id" required><option value="">Select course</option><?php foreach ($courses as $courseOption): ?><option value="<?php echo e((string) $courseOption['id']); ?>"<?php echo $formValues['course_id'] === (string) $courseOption['id'] ? ' selected' : ''; ?>><?php echo e((string) $courseOption['name']); ?></option><?php endforeach; ?></select></label>
        <label class="admin-form-field">Class<select class="form-select" name="class_id" required><option value="">Select class</option><?php foreach ($classes as $classOption): ?><option value="<?php echo e((string) $classOption['id']); ?>"<?php echo $formValues['class_id'] === (string) $classOption['id'] ? ' selected' : ''; ?>><?php echo e((string) $classOption['name']); ?></option><?php endforeach; ?></select></label>
        <label class="admin-form-field">Starts at<input class="form-control" type="time" name="start_time" value="<?php echo e(substr($formValues['start_time'], 0, 5)); ?>"></label>
        <label class="admin-form-field">Ends at<input class="form-control" type="time" name="end_time" value="<?php echo e(substr($formValues['end_time'], 0, 5)); ?>"></label>
        <label class="admin-form-field">Capacity<input class="form-control" type="number" min="1" max="1000000" name="capacity" value="<?php echo e($formValues['capacity']); ?>"></label>
        <label class="admin-form-field">Status<select class="form-select" name="status"><?php foreach (['active', 'inactive', 'full'] as $status): ?><option value="<?php echo e($status); ?>"<?php echo $formValues['status'] === $status ? ' selected' : ''; ?>><?php echo e(ucfirst($status)); ?></option><?php endforeach; ?></select></label>
        <div class="admin-form-actions"><button class="btn btn-cobalt" type="submit"><?php echo e($adminBatchId === null ? 'Create batch' : 'Save batch'); ?></button><a class="btn btn-outline-secondary" href="<?php echo e(route_url('/admin/batches/index')); ?>">Cancel</a></div>
    </form>
</section>
<?php render_app_page_end('admin'); ?>
