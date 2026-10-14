<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/parent-portal-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$db = db_connect();
$context = parent_portal_require_current_parent($db);
$parentId = (int) $context['parent']['parent_id'];
$institutionId = (int) $context['user']['institution_id'];
$children = parent_portal_get_children($db, $parentId, $institutionId);
$status = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : '';
$allowedStatuses = ['pending', 'success', 'failed', 'refunded', 'partial_refund'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}
$studentId = filter_input(INPUT_GET, 'student_id', FILTER_VALIDATE_INT);
$studentId = is_int($studentId) && in_array($studentId, array_column($children, 'id'), true) ? $studentId : 0;
$validDate = static function (mixed $value): bool {
    if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};
$from = $validDate($_GET['from'] ?? null) ? (string) $_GET['from'] : '';
$to = $validDate($_GET['to'] ?? null) ? (string) $_GET['to'] : '';
$requestedPage = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$page = is_int($requestedPage) && $requestedPage > 0 ? $requestedPage : 1;
$result = parent_portal_list_payments(
    $db,
    $parentId,
    $institutionId,
    ['student_id' => $studentId, 'status' => $status, 'from' => $from, 'to' => $to],
    $page,
    15
);

render_app_page_start('Payment History', 'Review payment activity for your linked children.', 'parent', ['parent-children.css']);
?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb parent-children-breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/parent/dashboard')); ?>">Overview</a></li><li class="breadcrumb-item active" aria-current="page">Payments</li></ol></nav>
<section class="parent-children-panel">
    <div class="parent-children-panel-heading"><div><h2>Payments</h2><p>Pending and failed attempts are shown as their recorded status; only successful payments count toward paid fees.</p></div><a class="parent-dashboard-text-link" href="<?php echo e(route_url('/parent/receipts/index')); ?>">Receipts</a></div>
    <form class="parent-payment-filters" method="get" action="<?php echo e(route_url('/parent/payments/index')); ?>">
        <label>Child<select name="student_id" class="form-select"><option value="0">All linked children</option><?php foreach ($children as $child): if (!$child['can_view_fees']) continue; ?><option value="<?php echo e((string) $child['id']); ?>"<?php echo $studentId === $child['id'] ? ' selected' : ''; ?>><?php echo e($child['full_name']); ?></option><?php endforeach; ?></select></label>
        <label>Status<select name="status" class="form-select"><option value="">All statuses</option><?php foreach ($allowedStatuses as $option): ?><option value="<?php echo e($option); ?>"<?php echo $status === $option ? ' selected' : ''; ?>><?php echo e(ucwords(str_replace('_', ' ', $option))); ?></option><?php endforeach; ?></select></label>
        <label>From date<input class="form-control" type="date" name="from" value="<?php echo e($from); ?>"></label>
        <label>To date<input class="form-control" type="date" name="to" value="<?php echo e($to); ?>"></label>
        <button class="btn btn-cobalt" type="submit">Filter</button>
        <a class="btn btn-outline-secondary" href="<?php echo e(route_url('/parent/payments/index')); ?>">Reset</a>
    </form>
    <?php if ($result['payments'] === []): ?>
        <div class="parent-children-empty"><span><i class="bi bi-credit-card" aria-hidden="true"></i></span><h3>No payment records found</h3><p>There are no payment records matching these filters for your linked children.</p></div>
    <?php else: ?>
        <div class="table-responsive"><table class="table parent-payments-table align-middle mt-3"><thead><tr><th>Reference</th><th>Student</th><th>Date</th><th>Method</th><th>Status</th><th class="text-end">Amount</th><th></th></tr></thead><tbody>
            <?php foreach ($result['payments'] as $payment): ?>
                <tr>
                    <td><?php echo e($payment['receipt_number'] ?? $payment['gateway_reference'] ?? 'Payment #' . $payment['id']); ?></td>
                    <td><?php echo e($payment['student_name']); ?><br><small><?php echo e($payment['student_code']); ?></small></td>
                    <td><?php echo e(date('j M Y', strtotime((string) ($payment['verified_at'] ?? $payment['captured_at'] ?? $payment['created_at'])))); ?></td>
                    <td><?php echo e(ucfirst((string) $payment['gateway'])); ?></td>
                    <td><?php echo e(ucwords(str_replace('_', ' ', (string) $payment['status']))); ?></td>
                    <td class="text-end"><?php echo e((string) $payment['currency']); ?> <?php echo e(number_format((float) $payment['amount'], 2)); ?><?php if ((float) $payment['refunded_amount'] > 0 || $payment['status'] === 'refunded'): ?><br><small>Refunded <?php echo e((string) $payment['currency']); ?> <?php echo e(number_format((float) $payment['refunded_amount'], 2)); ?> · net <?php echo e(number_format((float) $payment['net_amount'], 2)); ?></small><?php endif; ?></td>
                    <td><a href="<?php echo e(route_url('/parent/payments/view') . '?id=' . (int) $payment['id']); ?>">View</a><?php if (isset($payment['receipt_id'])): ?><br><a href="<?php echo e(route_url('/parent/receipts/download') . '?id=' . (int) $payment['receipt_id']); ?>">Download receipt</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody></table></div>
        <?php if ($result['total_pages'] > 1): ?>
            <nav class="parent-payment-pagination" aria-label="Payment pages"><span>Page <?php echo e((string) $result['page']); ?> of <?php echo e((string) $result['total_pages']); ?> · <?php echo e(number_format($result['total'])); ?> payments</span><div><?php for ($pageNumber = 1; $pageNumber <= $result['total_pages']; $pageNumber++): ?><a class="<?php echo $pageNumber === $result['page'] ? 'is-current' : ''; ?>" href="<?php echo e(route_url('/parent/payments/index') . '?' . http_build_query(['student_id' => $studentId, 'status' => $status, 'from' => $from, 'to' => $to, 'page' => $pageNumber])); ?>"><?php echo e((string) $pageNumber); ?></a><?php endfor; ?></div></nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php render_app_page_end('parent'); ?>
