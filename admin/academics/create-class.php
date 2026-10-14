<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/admin-panel-service.php';

require_http_methods(['GET', 'POST']);
require_placeholder_route_access();
$db = db_connect();
$institutionId = admin_panel_institution_id();
$user = current_user();
$errors = [];
$formValues = [
    'name' => '',
    'code' => '',
    'description' => '',
    'status' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    foreach (array_keys($formValues) as $field) {
        $formValues[$field] = is_string($_POST[$field] ?? null) ? $_POST[$field] : '';
    }
    try {
        $values = admin_panel_validate_class($_POST);
        admin_panel_create_class($db, $institutionId, (int) $user['id'], $values);
        set_flash('success', 'Class created.');
        safe_redirect('/admin/academics/classes', 303);
    } catch (InvalidArgumentException $exception) {
        $errors[] = $exception->getMessage();
    } catch (Throwable $exception) {
        log_error('admin_class_create_failed', [
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
        ]);
        $errors[] = 'The class could not be saved. Check that its name or code is not already in use.';
    }
}

render_app_page_start('Create Class', 'Add a class for this institution to use when organizing batches and examinations.', 'admin', ['admin-dashboard.css', 'admin-class-form.css']);
?>
<?php echo render_flash_messages(); ?>
<nav class="class-form-breadcrumb" aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/academics/classes')); ?>">Classes</a></li><li class="breadcrumb-item active" aria-current="page">Create Class</li></ol></nav>
<?php foreach ($errors as $error): ?><div class="alert alert-danger class-form-alert" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span><?php echo e($error); ?></span></div><?php endforeach; ?>
<section class="class-form-card" aria-labelledby="class-form-title">
    <header class="class-form-header">
        <span class="class-form-icon" aria-hidden="true"><i class="bi bi-building"></i></span>
        <div>
            <span class="class-form-eyebrow">Academic setup</span>
            <h2 id="class-form-title">Class details</h2>
            <p>Define an institution class to organize batches, student records, and examinations.</p>
        </div>
    </header>
    <form method="post" action="<?php echo e(route_url('/admin/academics/classes/create')); ?>" class="class-form">
        <?php echo csrf_field(); ?>
        <div class="class-form-section-title"><span>01</span><div><h3>Class information</h3><p>Set a clear name and optional reference details for this institution.</p></div></div>
        <label class="class-form-field class-form-field-wide" for="class-name">
            <span>Class name <b>Required</b></span>
            <input class="form-control" id="class-name" name="name" maxlength="120" required placeholder="e.g. Grade 8" value="<?php echo e($formValues['name']); ?>">
            <small>Class names must be unique within your institution.</small>
        </label>
        <label class="class-form-field" for="class-code">
            <span>Class code <small>Optional</small></span>
            <input class="form-control" id="class-code" name="code" maxlength="50" placeholder="e.g. G8" value="<?php echo e($formValues['code']); ?>">
            <small>An optional internal reference, unique within this institution.</small>
        </label>
        <label class="class-form-field" for="class-status">
            <span>Status <b>Required</b></span>
            <select class="form-select" id="class-status" name="status" required>
                <option value="">Choose status</option>
                <option value="active"<?php echo $formValues['status'] === 'active' ? ' selected' : ''; ?>>Active</option>
                <option value="inactive"<?php echo $formValues['status'] === 'inactive' ? ' selected' : ''; ?>>Inactive</option>
            </select>
            <small>Choose Active for use in new batches and examination schedules.</small>
        </label>
        <label class="class-form-field class-form-field-wide" for="class-description">
            <span>Description <small>Optional</small></span>
            <textarea class="form-control" id="class-description" name="description" rows="4" maxlength="20000" placeholder="Add a short description or useful class details..."><?php echo e($formValues['description']); ?></textarea>
        </label>
        <footer class="class-form-footer">
            <a class="class-form-cancel" href="<?php echo e(route_url('/admin/academics/classes')); ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Cancel</a>
            <button class="btn btn-cobalt class-form-submit" type="submit"><i class="bi bi-check2-circle" aria-hidden="true"></i>Create class</button>
        </footer>
    </form>
</section>
<?php render_app_page_end('admin'); ?>
