<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/admin-panel-service.php';
require_once __DIR__ . '/../../services/academic-management-service.php';

require_http_methods(['GET', 'POST']);
require_placeholder_route_access();
$db = db_connect();
$user = current_user();
$isSuperAdmin = $user['user_type'] === 'super_admin';
$errors = [];
$requestedInstitutionId = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['institution_id'] ?? null)
    : ($_GET['institution_id'] ?? null);
try {
    $institutionId = academic_management_resolve_institution_id($db, $user, $requestedInstitutionId);
} catch (InvalidArgumentException | OutOfBoundsException $exception) {
    $institutionId = null;
    $errors[] = $exception->getMessage();
}
$returnYearId = filter_var(
    $_SERVER['REQUEST_METHOD'] === 'POST'
        ? ($_POST['return_year_id'] ?? null)
        : ($_GET['year_id'] ?? null),
    FILTER_VALIDATE_INT
);
if ($returnYearId === false || $returnYearId === null || $returnYearId < 1) {
    $returnYearId = null;
}
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
        if ($institutionId === null) {
            throw new InvalidArgumentException('Choose an institution before creating a class.');
        }
        $values = admin_panel_validate_class($_POST);
        admin_panel_create_class($db, $institutionId, (int) $user['id'], $values);
        set_flash('success', 'Class created.');
        $returnQuery = array_filter([
            $isSuperAdmin ? 'institution_id=' . $institutionId : '',
            $returnYearId === null ? '' : 'year_id=' . $returnYearId,
        ]);
        safe_redirect(
            '/admin/academics/classes' . ($returnQuery === [] ? '' : '?' . implode('&', $returnQuery)),
            303
        );
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

render_app_page_start('Create Class', 'Add a class to your school. You can then offer it for an academic year and add sections.', 'admin', ['admin-dashboard.css', 'admin-class-form.css']);
?>
<?php echo render_flash_messages(); ?>
<nav class="class-form-breadcrumb" aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/academics/classes')); ?>">Classes</a></li><li class="breadcrumb-item active" aria-current="page">Create Class</li></ol></nav>
<?php foreach ($errors as $error): ?><div class="alert alert-danger class-form-alert" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span><?php echo e($error); ?></span></div><?php endforeach; ?>
<?php if (!$isSuperAdmin || $institutionId !== null): ?>
<section class="class-form-card" aria-labelledby="class-form-title">
    <header class="class-form-header">
        <span class="class-form-icon" aria-hidden="true"><i class="bi bi-building"></i></span>
        <div>
            <span class="class-form-eyebrow">Academic setup</span>
            <h2 id="class-form-title">Class details</h2>
            <p>Create a class such as Grade 8. Next, add it to an academic year and create its sections.</p>
        </div>
    </header>
    <form method="post" action="<?php echo e(route_url('/admin/academics/classes/create') . ($returnYearId === null ? '' : '?year_id=' . $returnYearId)); ?>" class="class-form">
        <?php echo csrf_field(); ?>
        <?php if ($isSuperAdmin): ?><input type="hidden" name="institution_id" value="<?php echo e((string) $institutionId); ?>"><?php endif; ?>
        <?php if ($returnYearId !== null): ?><input type="hidden" name="return_year_id" value="<?php echo e((string) $returnYearId); ?>"><?php endif; ?>
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
            <small>Choose Active to offer this class in academic years and use it for student placement.</small>
        </label>
        <label class="class-form-field class-form-field-wide" for="class-description">
            <span>Description <small>Optional</small></span>
            <textarea class="form-control" id="class-description" name="description" rows="4" maxlength="20000" placeholder="Add a short description or useful class details..."><?php echo e($formValues['description']); ?></textarea>
        </label>
        <footer class="class-form-footer">
            <a class="class-form-cancel" href="<?php echo e(route_url('/admin/academics/classes') . ($returnYearId === null ? '' : '?year_id=' . $returnYearId)); ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Cancel</a>
            <button class="btn btn-cobalt class-form-submit" type="submit"><i class="bi bi-check2-circle" aria-hidden="true"></i>Create class</button>
        </footer>
    </form>
</section>
<?php endif; ?>
<?php render_app_page_end('admin'); ?>
