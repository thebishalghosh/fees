<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layouts/page.php';
require_once __DIR__ . '/../../services/fee-assignment-service.php';
require_once __DIR__ . '/../../services/admin-panel-service.php';

require_http_methods(['GET', 'HEAD', 'POST']);
require_permission('fees.view');
$user = current_user();
if ($user === null) {
    throw new RuntimeException('An authenticated institution account is required.');
}
$institutionId = (int) $user['institution_id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('fees.manage');
    require_valid_csrf_token();
    try {
        $values = fee_workflow_validate_plan($_POST);
        fee_workflow_create_plan(db_connect(), $institutionId, (int) $user['id'], $values);
        set_flash('success', 'Payment plan created.');
        safe_redirect('/admin/fees/payment-plans', 303);
    } catch (InvalidArgumentException $exception) {
        $errors[] = $exception->getMessage();
    } catch (mysqli_sql_exception $exception) {
        if ($exception->getCode() === 1062) {
            $errors[] = 'That payment plan code is already in use for this institution.';
        } else {
            log_error('payment_plan_create_failed', [
                'error_type' => get_class($exception),
                'error_code' => $exception->getCode(),
            ]);
            $errors[] = 'The payment plan could not be saved. Try again.';
        }
    } catch (Throwable $exception) {
        log_error('payment_plan_create_failed', [
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
        ]);
        $errors[] = 'The payment plan could not be saved. Try again.';
    }
}

$plans = admin_panel_simple_list(db_connect(), $institutionId, 'payment_plans');
render_app_page_start(
    'Payment Plans',
    'Configure monthly or quarterly schedule intervals and the number of installments for each institution plan.',
    'admin',
    ['admin-dashboard.css']
);
?>
<?php echo render_flash_messages(); ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item active" aria-current="page">Payment plans</li></ol></nav>
<?php foreach ($errors as $error): ?><div class="alert alert-danger" role="alert"><?php echo e($error); ?></div><?php endforeach; ?>
<?php if (user_has_permission('fees.manage')): ?>
<section class="dashboard-panel admin-form-panel mb-4">
    <h2 class="h5">Create a payment plan</h2>
    <p class="text-muted">Installment amounts are derived equally from the assigned fee total; remainder cents are added to the earliest installments.</p>
    <form method="post" action="<?php echo e(route_url('/admin/fees/payment-plans')); ?>" class="admin-form-grid">
        <?php echo csrf_field(); ?>
        <label class="admin-form-field">Plan name<input class="form-control" name="name" maxlength="120" required value="<?php echo e(is_string($_POST['name'] ?? null) ? $_POST['name'] : ''); ?>"></label>
        <label class="admin-form-field">Institution plan code<input class="form-control" name="code" maxlength="50" pattern="[A-Za-z0-9][A-Za-z0-9_-]{0,49}" required value="<?php echo e(is_string($_POST['code'] ?? null) ? $_POST['code'] : ''); ?>"></label>
        <label class="admin-form-field">Interval<select class="form-select" name="plan_type" required>
            <option value="">Select interval</option>
            <option value="monthly"<?php echo ($_POST['plan_type'] ?? null) === 'monthly' ? ' selected' : ''; ?>>Monthly</option>
            <option value="quarterly"<?php echo ($_POST['plan_type'] ?? null) === 'quarterly' ? ' selected' : ''; ?>>Quarterly</option>
        </select></label>
        <label class="admin-form-field">Number of installments<input class="form-control" type="number" name="installment_count" min="1" max="120" step="1" required value="<?php echo e(is_string($_POST['installment_count'] ?? null) ? $_POST['installment_count'] : ''); ?>"></label>
        <div class="admin-form-actions mt-3"><button class="btn btn-cobalt" type="submit">Create plan</button></div>
    </form>
</section>
<?php endif; ?>
<section class="dashboard-panel">
    <?php if ($plans === []): ?>
        <div class="admin-panel-empty"><i class="bi bi-inbox" aria-hidden="true"></i><strong>No payment plans</strong><span>Create a monthly or quarterly plan before assigning fees.</span></div>
    <?php else: ?>
        <div class="table-responsive"><table class="table admin-panel-table align-middle">
            <thead><tr><?php foreach (array_keys($plans[0]) as $column): ?><th scope="col"><?php echo e(ucwords(str_replace('_', ' ', $column))); ?></th><?php endforeach; ?></tr></thead>
            <tbody><?php foreach ($plans as $plan): ?><tr><?php foreach ($plan as $value): ?><td><?php echo e($value === null || $value === '' ? '—' : (string) $value); ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php render_app_page_end('admin'); ?>
