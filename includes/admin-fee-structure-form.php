<?php
declare(strict_types=1);

require_once __DIR__ . '/page-placeholder.php';
require_once __DIR__ . '/../services/admin-panel-service.php';

if (!array_key_exists('adminFeeStructureId', $GLOBALS)
    || ($adminFeeStructureId !== null && !is_int($adminFeeStructureId))) {
    throw new LogicException('The fee structure form is not configured.');
}
require_http_methods(['GET', 'POST']);
require_placeholder_route_access();
$db = db_connect();
$institutionId = admin_panel_institution_id();
$user = current_user();
$structure = $adminFeeStructureId === null ? null : admin_panel_fee_structure($db, $institutionId, $adminFeeStructureId);
if ($adminFeeStructureId !== null && $structure === null) {
    http_response_code(404);
    exit('Fee structure not found.');
}
$years = admin_panel_simple_list($db, $institutionId, 'academic_years');
$courses = admin_panel_simple_list($db, $institutionId, 'courses');
$classes = admin_panel_simple_list($db, $institutionId, 'classes');
$categories = admin_panel_simple_list($db, $institutionId, 'fee_categories');
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    try {
        $values = admin_panel_validate_fee_structure($_POST);
        admin_panel_save_fee_structure($db, $institutionId, (int) $user['id'], $values, $adminFeeStructureId);
        set_flash('success', $adminFeeStructureId === null ? 'Fee structure created.' : 'Fee structure updated.');
        safe_redirect('/admin/fees/structures', 303);
    } catch (InvalidArgumentException | DomainException | OutOfBoundsException $exception) {
        $errors[] = $exception->getMessage();
    } catch (Throwable $exception) {
        log_error('admin_fee_structure_save_failed', [
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
        ]);
        $errors[] = 'The fee structure could not be saved. Check the selected records and try again.';
    }
}
$formValues = [];
foreach (['name', 'description', 'academic_year_id', 'course_id', 'class_id', 'status'] as $field) {
    $formValues[$field] = is_string($_POST[$field] ?? null)
        ? $_POST[$field]
        : (string) ($structure[$field] ?? ($field === 'status' ? 'draft' : ''));
}
$selectedCategoryValues = is_array($_POST['category_ids'] ?? null)
    ? array_values(array_filter($_POST['category_ids'], 'is_string'))
    : array_map(static fn(array $item): string => (string) $item['category_id'], $structure['items'] ?? []);
$amountValues = is_array($_POST['items'] ?? null) ? $_POST['items'] : [];
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $structure !== null) {
    foreach ($structure['items'] as $item) {
        $amountValues[(string) $item['category_id']] = (string) $item['amount'];
    }
}
$title = $adminFeeStructureId === null ? 'Create Fee Structure' : 'Edit Fee Structure';
render_app_page_start($title, 'Fee totals are calculated from the selected category amounts. Structures already assigned to students are locked to preserve fee history.', 'admin', ['admin-dashboard.css']);
?>
<?php echo render_flash_messages(); ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/fees/structures')); ?>">Fee Structures</a></li><li class="breadcrumb-item active" aria-current="page"><?php echo e($title); ?></li></ol></nav>
<?php foreach ($errors as $error): ?><div class="alert alert-danger" role="alert"><?php echo e($error); ?></div><?php endforeach; ?>
<section class="dashboard-panel admin-form-panel">
    <form method="post" action="<?php echo e(route_url($adminFeeStructureId === null ? '/admin/fees/create-structure' : '/admin/fees/edit-structure') . ($adminFeeStructureId === null ? '' : '?id=' . $adminFeeStructureId)); ?>" class="admin-form-grid">
        <?php echo csrf_field(); ?>
        <label class="admin-form-field">Structure name<input class="form-control" name="name" maxlength="150" required value="<?php echo e($formValues['name']); ?>"></label>
        <label class="admin-form-field">Academic year<select class="form-select" name="academic_year_id" required><option value="">Select academic year</option><?php foreach ($years as $year): ?><option value="<?php echo e((string) $year['id']); ?>"<?php echo $formValues['academic_year_id'] === (string) $year['id'] ? ' selected' : ''; ?>><?php echo e((string) $year['name']); ?></option><?php endforeach; ?></select></label>
        <label class="admin-form-field">Course (optional)<select class="form-select" name="course_id"><option value="">All courses</option><?php foreach ($courses as $course): ?><option value="<?php echo e((string) $course['id']); ?>"<?php echo $formValues['course_id'] === (string) $course['id'] ? ' selected' : ''; ?>><?php echo e((string) $course['name']); ?></option><?php endforeach; ?></select></label>
        <label class="admin-form-field">Class (optional)<select class="form-select" name="class_id"><option value="">All classes</option><?php foreach ($classes as $class): ?><option value="<?php echo e((string) $class['id']); ?>"<?php echo $formValues['class_id'] === (string) $class['id'] ? ' selected' : ''; ?>><?php echo e((string) $class['name']); ?></option><?php endforeach; ?></select></label>
        <label class="admin-form-field">Status<select class="form-select" name="status"><?php foreach (['draft', 'active', 'inactive'] as $status): ?><option value="<?php echo e($status); ?>"<?php echo $formValues['status'] === $status ? ' selected' : ''; ?>><?php echo e(ucfirst($status)); ?></option><?php endforeach; ?></select></label>
        <label class="admin-form-field is-wide">Description<textarea class="form-control" name="description" rows="4" maxlength="20000"><?php echo e($formValues['description']); ?></textarea></label>
        <fieldset class="admin-form-field is-wide"><legend>Fee items</legend>
            <?php if ($categories === []): ?><p class="text-muted">Create fee categories before configuring a structure.</p><?php else: ?>
                <div class="table-responsive"><table class="table"><thead><tr><th>Include</th><th>Category</th><th>Amount</th></tr></thead><tbody>
                    <?php foreach ($categories as $category): $categoryId = (string) $category['id']; $checked = in_array($categoryId, $selectedCategoryValues, true); $disabled = $category['status'] !== 'active' && !$checked; ?>
                        <tr><td><input type="checkbox" name="category_ids[]" value="<?php echo e($categoryId); ?>"<?php echo $checked ? ' checked' : ''; ?><?php echo $disabled ? ' disabled' : ''; ?> aria-label="<?php echo e('Include ' . $category['name']); ?>"></td><td><?php echo e((string) $category['name']); ?><?php if ($category['status'] !== 'active'): ?> <span class="text-muted">(inactive; existing structures only)</span><?php endif; ?></td><td><input class="form-control" type="number" name="items[<?php echo e($categoryId); ?>]" min="0.01" max="9999999999.99" step="0.01" value="<?php echo e(is_string($amountValues[$categoryId] ?? null) ? $amountValues[$categoryId] : ''); ?>" aria-label="<?php echo e($category['name'] . ' amount'); ?>"></td></tr>
                    <?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </fieldset>
        <div class="admin-form-actions"><button class="btn btn-cobalt" type="submit"><?php echo e($adminFeeStructureId === null ? 'Create fee structure' : 'Save fee structure'); ?></button><a class="btn btn-outline-secondary" href="<?php echo e(route_url('/admin/fees/structures')); ?>">Cancel</a></div>
    </form>
</section>
<?php render_app_page_end('admin'); ?>
