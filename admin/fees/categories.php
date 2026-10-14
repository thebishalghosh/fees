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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    try {
        if (($_POST['action'] ?? '') === 'create') {
            $values = admin_panel_validate_fee_category($_POST);
            admin_panel_create_fee_category($db, $institutionId, (int) $user['id'], $values);
            set_flash('success', 'Fee category created.');
        } elseif (($_POST['action'] ?? '') === 'status') {
            $categoryId = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT);
            $status = $_POST['status'] ?? null;
            if ($categoryId === false || !is_string($status)) {
                throw new InvalidArgumentException('The fee category action is invalid.');
            }
            admin_panel_set_fee_category_status($db, $institutionId, (int) $user['id'], (int) $categoryId, $status);
            set_flash('success', 'Fee category status updated.');
        } else {
            throw new InvalidArgumentException('Select a supported fee category action.');
        }
        safe_redirect('/admin/fees/categories', 303);
    } catch (InvalidArgumentException | OutOfBoundsException $exception) {
        $errors[] = $exception->getMessage();
    } catch (Throwable $exception) {
        log_error('admin_fee_category_save_failed', [
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
        ]);
        $errors[] = 'The fee category could not be saved. Check its code and try again.';
    }
}
$categories = admin_panel_simple_list($db, $institutionId, 'fee_categories');
$activeCategoryCount = count(array_filter(
    $categories,
    static fn(array $category): bool => ($category['status'] ?? null) === 'active'
));
$mandatoryCategoryCount = count(array_filter(
    $categories,
    static fn(array $category): bool => (int) ($category['is_mandatory'] ?? 0) === 1
));
render_app_page_start('Fee Categories', 'Create and deactivate categories without deleting fee history.', 'admin', ['admin-dashboard.css', 'admin-fee-categories.css']);
?>
<?php echo render_flash_messages(); ?>
<?php foreach ($errors as $error): ?><div class="alert alert-danger" role="alert"><?php echo e($error); ?></div><?php endforeach; ?>
<section class="fee-category-summary" aria-label="Fee category summary">
    <article class="fee-category-stat">
        <span class="fee-category-stat-icon"><i class="bi bi-tags" aria-hidden="true"></i></span>
        <span><small>Total categories</small><strong><?php echo count($categories); ?></strong></span>
    </article>
    <article class="fee-category-stat">
        <span class="fee-category-stat-icon is-active"><i class="bi bi-check-circle" aria-hidden="true"></i></span>
        <span><small>Active categories</small><strong><?php echo $activeCategoryCount; ?></strong></span>
    </article>
    <article class="fee-category-stat">
        <span class="fee-category-stat-icon is-mandatory"><i class="bi bi-asterisk" aria-hidden="true"></i></span>
        <span><small>Mandatory categories</small><strong><?php echo $mandatoryCategoryCount; ?></strong></span>
    </article>
</section>
<div class="fee-category-layout">
    <section class="dashboard-panel fee-category-form-panel" aria-labelledby="fee-category-form-title">
        <div class="fee-category-panel-heading">
            <span class="fee-category-panel-icon"><i class="bi bi-plus-lg" aria-hidden="true"></i></span>
            <div>
                <span class="fee-category-kicker">Category setup</span>
                <h2 id="fee-category-form-title">Add a category</h2>
                <p>Define a fee type for your institution.</p>
            </div>
        </div>
        <form method="post" action="<?php echo e(route_url('/admin/fees/categories')); ?>" class="fee-category-form">
            <?php echo csrf_field(); ?><input type="hidden" name="action" value="create">
            <label class="fee-category-field"><span class="fee-category-label">Category name <span aria-hidden="true">*</span></span>
                <input class="form-control" name="name" maxlength="150" required placeholder="e.g. Tuition fee" value="<?php echo e(is_string($_POST['name'] ?? null) ? $_POST['name'] : ''); ?>">
            </label>
            <label class="fee-category-field"><span class="fee-category-label">Category code <small>Optional</small></span>
                <input class="form-control" name="code" maxlength="50" placeholder="e.g. TUIT" value="<?php echo e(is_string($_POST['code'] ?? null) ? $_POST['code'] : ''); ?>">
            </label>
            <label class="fee-category-field"><span class="fee-category-label">Mandatory</span>
                <select class="form-select" name="is_mandatory">
                    <option value="1"<?php echo ($_POST['is_mandatory'] ?? '1') === '1' ? ' selected' : ''; ?>>Yes, required</option>
                    <option value="0"<?php echo ($_POST['is_mandatory'] ?? '') === '0' ? ' selected' : ''; ?>>No, optional</option>
                </select>
            </label>
            <label class="fee-category-field"><span class="fee-category-label">Description <small>Optional</small></span>
                <textarea class="form-control" name="description" rows="4" maxlength="20000" placeholder="Add a short note about this fee type"><?php echo e(is_string($_POST['description'] ?? null) ? $_POST['description'] : ''); ?></textarea>
            </label>
            <button class="btn btn-cobalt fee-category-submit" type="submit"><i class="bi bi-plus-lg" aria-hidden="true"></i>Create category</button>
        </form>
    </section>
    <section class="dashboard-panel fee-category-list-panel" aria-labelledby="fee-category-list-title">
        <div class="fee-category-list-heading">
            <div>
                <span class="fee-category-kicker">Fee configuration</span>
                <h2 id="fee-category-list-title">Category catalogue</h2>
            </div>
            <span class="fee-category-count"><?php echo count($categories); ?> total</span>
        </div>
        <?php if ($categories === []): ?>
            <div class="fee-category-empty">
                <span><i class="bi bi-tags" aria-hidden="true"></i></span>
                <strong>No fee categories yet</strong>
                <p>Create your first category to use it in fee structures.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive fee-category-table-wrap">
                <table class="table admin-panel-table fee-category-table">
                    <thead><tr><th scope="col">Category</th><th scope="col">Type</th><th scope="col">Status</th><th scope="col">Created</th><th scope="col"><span class="visually-hidden">Action</span></th></tr></thead>
                    <tbody>
                        <?php foreach ($categories as $category): ?>
                            <?php $categoryIsActive = $category['status'] === 'active'; ?>
                            <tr>
                                <td>
                                    <span class="fee-category-name"><?php echo e((string) $category['name']); ?></span>
                                    <small class="fee-category-code"><?php echo e((string) ($category['code'] ?? '—')); ?></small>
                                </td>
                                <td><span class="fee-category-type<?php echo (int) $category['is_mandatory'] === 1 ? ' is-required' : ''; ?>"><?php echo (int) $category['is_mandatory'] === 1 ? 'Mandatory' : 'Optional'; ?></span></td>
                                <td><span class="fee-category-status<?php echo $categoryIsActive ? ' is-active' : ' is-inactive'; ?>"><?php echo e(ucfirst((string) $category['status'])); ?></span></td>
                                <td><?php echo e((string) $category['created_at']); ?></td>
                                <td>
                                    <form method="post" action="<?php echo e(route_url('/admin/fees/categories')); ?>">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="status">
                                        <input type="hidden" name="category_id" value="<?php echo e((string) $category['id']); ?>">
                                        <input type="hidden" name="status" value="<?php echo $categoryIsActive ? 'inactive' : 'active'; ?>">
                                        <button class="btn btn-sm fee-category-action<?php echo $categoryIsActive ? ' is-deactivate' : ' is-activate'; ?>" type="submit" aria-label="<?php echo e(($categoryIsActive ? 'Deactivate ' : 'Activate ') . (string) $category['name']); ?>">
                                            <?php echo $categoryIsActive ? 'Deactivate' : 'Activate'; ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
<?php render_app_page_end('admin'); ?>
