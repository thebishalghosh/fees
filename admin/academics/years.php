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
            $values = admin_panel_validate_academic_year($_POST);
            admin_panel_create_academic_year($db, $institutionId, (int) $user['id'], $values);
            set_flash('success', 'Academic year created as a draft.');
        } elseif (($_POST['action'] ?? '') === 'status') {
            $yearId = filter_var($_POST['year_id'] ?? null, FILTER_VALIDATE_INT);
            $status = $_POST['status'] ?? null;
            if ($yearId === false || !is_string($status)) {
                throw new InvalidArgumentException('The academic-year action is invalid.');
            }
            admin_panel_set_academic_year_status($db, $institutionId, (int) $user['id'], (int) $yearId, $status);
            set_flash('success', 'Academic-year status updated.');
        } else {
            throw new InvalidArgumentException('Select a supported academic-year action.');
        }
        safe_redirect('/admin/academics/years', 303);
    } catch (InvalidArgumentException | DomainException | OutOfBoundsException $exception) {
        $errors[] = $exception->getMessage();
    } catch (Throwable $exception) {
        log_error('admin_academic_year_save_failed', [
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
        ]);
        $errors[] = 'The academic year could not be saved. Check for a duplicate name and try again.';
    }
}
$years = admin_panel_simple_list($db, $institutionId, 'academic_years');
render_app_page_start('Academic Years', 'Create draft academic years and manage their lifecycle without deleting historical references.', 'admin', ['admin-dashboard.css']);
?>
<?php echo render_flash_messages(); ?>
<?php foreach ($errors as $error): ?><div class="alert alert-danger" role="alert"><?php echo e($error); ?></div><?php endforeach; ?>
<section class="dashboard-panel admin-form-panel">
    <h2 class="h5">Add academic year</h2>
    <form method="post" action="<?php echo e(route_url('/admin/academics/years')); ?>" class="admin-form-grid">
        <?php echo csrf_field(); ?><input type="hidden" name="action" value="create">
        <label class="admin-form-field">Name<input class="form-control" name="name" maxlength="120" required value="<?php echo e(is_string($_POST['name'] ?? null) ? $_POST['name'] : ''); ?>"></label>
        <label class="admin-form-field">Start date<input class="form-control" type="date" name="start_date" required value="<?php echo e(is_string($_POST['start_date'] ?? null) ? $_POST['start_date'] : ''); ?>"></label>
        <label class="admin-form-field">End date<input class="form-control" type="date" name="end_date" required value="<?php echo e(is_string($_POST['end_date'] ?? null) ? $_POST['end_date'] : ''); ?>"></label>
        <div class="admin-form-actions"><button class="btn btn-cobalt" type="submit">Create draft year</button></div>
    </form>
</section>
<section class="dashboard-panel">
    <?php if ($years === []): ?><div class="admin-panel-empty"><strong>No academic years</strong><span>Create a draft year to configure course fee structures.</span></div><?php else: ?>
        <div class="table-responsive"><table class="table admin-panel-table"><thead><tr><th>Name</th><th>Starts</th><th>Ends</th><th>Status</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($years as $year): ?><tr><td><?php echo e((string) $year['name']); ?></td><td><?php echo e((string) $year['start_date']); ?></td><td><?php echo e((string) $year['end_date']); ?></td><td><?php echo e(ucfirst((string) $year['status'])); ?></td><td><?php if ($year['status'] === 'draft'): ?><form method="post" action="<?php echo e(route_url('/admin/academics/years')); ?>"><?php echo csrf_field(); ?><input type="hidden" name="action" value="status"><input type="hidden" name="year_id" value="<?php echo e((string) $year['id']); ?>"><input type="hidden" name="status" value="active"><button class="btn btn-sm btn-outline-primary" type="submit">Activate</button></form><?php elseif ($year['status'] === 'active'): ?><form method="post" action="<?php echo e(route_url('/admin/academics/years')); ?>"><?php echo csrf_field(); ?><input type="hidden" name="action" value="status"><input type="hidden" name="year_id" value="<?php echo e((string) $year['id']); ?>"><input type="hidden" name="status" value="closed"><button class="btn btn-sm btn-outline-secondary" type="submit">Close</button></form><?php else: ?><span class="text-muted">Locked</span><?php endif; ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>
<?php render_app_page_end('admin'); ?>
