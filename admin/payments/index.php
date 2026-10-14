<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/admin-panel-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$institutionId = admin_panel_institution_id();
$search = isset($_GET['search']) && is_string($_GET['search']) ? mb_substr(trim($_GET['search']), 0, 120, 'UTF-8') : '';
$status = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : '';
$statuses = ['', 'pending', 'success', 'failed', 'refunded', 'partial_refund'];
if (!in_array($status, $statuses, true)) {
    $status = '';
}
$requestedPage = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$page = $requestedPage === false ? 1 : max(1, $requestedPage);
$result = admin_panel_payment_list(db_connect(), $institutionId, $search, $status, $page);

render_app_page_start('Payment Transactions', 'Search payment records and review verified status without changing gateway outcomes.', 'admin', ['admin-dashboard.css']);
?>
<?php echo render_flash_messages(); ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item active" aria-current="page">Payments</li></ol></nav>
<section class="dashboard-panel admin-recent-payments-panel">
    <div class="dashboard-panel-heading"><div><h2>Transactions</h2><p><?php echo e(number_format($result['total'])); ?> records in this institution.</p></div><?php if (user_has_permission('payments.refund')): ?><a href="<?php echo e(route_url('/admin/payments/refunds')); ?>">Review refunds</a><?php endif; ?></div>
    <form class="admin-list-filters" method="get" action="<?php echo e(route_url('/admin/payments/index')); ?>">
        <label>Search<input type="search" class="form-control" name="search" value="<?php echo e($search); ?>" placeholder="Student, parent or transaction reference"></label>
        <label>Status<select class="form-select" name="status"><?php foreach ($statuses as $option): ?><option value="<?php echo e($option); ?>"<?php echo $option === $status ? ' selected' : ''; ?>><?php echo e($option === '' ? 'All statuses' : ucwords(str_replace('_', ' ', $option))); ?></option><?php endforeach; ?></select></label>
        <button class="btn btn-cobalt" type="submit">Filter</button><a class="btn btn-outline-secondary" href="<?php echo e(route_url('/admin/payments/index')); ?>">Reset</a>
    </form>
    <?php if ($result['payments'] === []): ?>
        <div class="admin-panel-empty"><i class="bi bi-credit-card" aria-hidden="true"></i><strong>No payments match these filters</strong><span>Try another search or status.</span></div>
    <?php else: ?>
        <div class="table-responsive"><table class="table admin-panel-table align-middle"><thead><tr><th>Payment</th><th>Student</th><th>Parent</th><th>Recorded</th><th>Gateway</th><th>Status</th><th class="text-end">Amount</th><th></th></tr></thead><tbody>
            <?php foreach ($result['payments'] as $payment): ?><tr>
                <td><?php echo e($payment['gateway_reference'] ?? $payment['gateway_transaction_id'] ?? $payment['receipt_number'] ?? 'Payment #' . $payment['id']); ?></td>
                <td><?php echo e($payment['student_name']); ?><small><?php echo e($payment['student_code']); ?></small></td><td><?php echo e($payment['parent_name']); ?></td>
                <td><?php echo e(date('j M Y, g:i a', strtotime((string) ($payment['verified_at'] ?? $payment['captured_at'] ?? $payment['created_at'])))); ?></td>
                <td><?php echo e(ucfirst((string) $payment['gateway'])); ?></td><td><span class="admin-status-badge is-<?php echo e((string) $payment['status']); ?>"><?php echo e(ucwords(str_replace('_', ' ', (string) $payment['status']))); ?></span></td>
                <td class="text-end"><?php echo e((string) $payment['currency']); ?> <?php echo e(number_format((float) $payment['amount'], 2)); ?></td><td><a href="<?php echo e(route_url('/admin/payments/details') . '?id=' . (int) $payment['id']); ?>">Details</a></td>
            </tr><?php endforeach; ?>
        </tbody></table></div>
        <?php if ($result['total_pages'] > 1): ?><nav class="admin-pagination" aria-label="Payment pages"><span>Page <?php echo e((string) $result['page']); ?> of <?php echo e((string) $result['total_pages']); ?></span><div><?php for ($n = 1; $n <= $result['total_pages']; $n++): ?><a class="<?php echo $n === $result['page'] ? 'is-current' : ''; ?>" href="<?php echo e(route_url('/admin/payments/index') . '?' . http_build_query(['search' => $search, 'status' => $status, 'page' => $n])); ?>"><?php echo e((string) $n); ?></a><?php endfor; ?></div></nav><?php endif; ?>
    <?php endif; ?>
</section>
<?php render_app_page_end('admin'); ?>
