<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';
require_once __DIR__ . '/../../services/parent-portal-service.php';
require_once __DIR__ . '/../../services/payment-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$assignmentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!is_int($assignmentId) || $assignmentId < 1) {
    http_response_code(404);
    exit('Fee assignment not found.');
}
$db = db_connect();
$context = parent_portal_require_current_parent($db);
$assignment = parent_portal_get_fee_assignment(
    $db,
    (int) $context['parent']['parent_id'],
    (int) $context['user']['institution_id'],
    $assignmentId
);
if ($assignment === null) {
    http_response_code(404);
    exit('Fee assignment not found.');
}
$components = parent_portal_get_assignment_components(
    $db,
    (int) $context['user']['institution_id'],
    $assignmentId
);
$payments = parent_portal_list_payments(
    $db,
    (int) $context['parent']['parent_id'],
    (int) $context['user']['institution_id'],
    ['assignment_id' => $assignmentId],
    1,
    50
);

render_app_page_start('Fee Details', 'Fee assignment details and installment schedule.', 'parent', ['parent-children.css']);
?>
<?php echo render_flash_messages('parent_payment'); ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb parent-children-breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/parent/dashboard')); ?>">Overview</a></li><li class="breadcrumb-item"><a href="<?php echo e(route_url('/parent/fees/index')); ?>">Fees</a></li><li class="breadcrumb-item active" aria-current="page"><?php echo e($assignment['fee_structure_name']); ?></li></ol></nav>
<section class="parent-children-panel parent-student-detail">
    <div class="parent-student-detail-heading"><div><span class="parent-child-code"><?php echo e($assignment['student_code']); ?> · <?php echo e($assignment['academic_year_name']); ?></span><h2><?php echo e($assignment['fee_structure_name']); ?></h2><p class="mb-0 text-secondary"><?php echo e($assignment['student_name']); ?></p></div><a class="parent-child-back-link" href="<?php echo e(route_url('/parent/fees/index')); ?>">Back to Fees</a></div>
    <div class="parent-student-info-grid">
        <article class="parent-student-info-card"><h3><i class="bi bi-cash-stack" aria-hidden="true"></i>Assignment balance</h3>
            <dl class="parent-fee-summary-list">
                <div><dt>Assigned fees</dt><dd>₹<?php echo e(number_format((float) $assignment['total_fee'], 2)); ?></dd></div>
                <div><dt>Discounts</dt><dd>₹<?php echo e(number_format((float) $assignment['total_discount'], 2)); ?></dd></div>
                <div><dt>Adjustments</dt><dd>₹<?php echo e(number_format((float) $assignment['total_adjustments'], 2)); ?></dd></div>
                <div><dt>Paid</dt><dd>₹<?php echo e(number_format((float) $assignment['total_paid'], 2)); ?></dd></div>
                <div class="is-outstanding"><dt>Outstanding</dt><dd>₹<?php echo e(number_format((float) $assignment['outstanding_amount'], 2)); ?></dd></div>
            </dl>
        </article>
        <article class="parent-student-info-card"><h3><i class="bi bi-list-check" aria-hidden="true"></i>Fee structure items</h3>
            <?php if ($assignment['items'] === []): ?><p class="text-secondary mb-0">No itemized fee components are recorded.</p><?php else: ?>
                <div class="table-responsive"><table class="table parent-payments-table"><thead><tr><th>Category</th><th>Type</th><th class="text-end">Amount</th></tr></thead><tbody><?php foreach ($assignment['items'] as $item): ?><tr><td><?php echo e($item['category_name']); ?></td><td><?php echo (int) $item['is_optional'] === 1 ? 'Optional' : 'Required'; ?></td><td class="text-end">₹<?php echo e(number_format((float) $item['amount'], 2)); ?></td></tr><?php endforeach; ?></tbody></table></div>
            <?php endif; ?>
        </article>
    </div>
    <?php if ($components['discounts'] !== [] || $components['adjustments'] !== []): ?>
        <section class="parent-payments-panel"><h3>Discounts and adjustments</h3><div class="table-responsive"><table class="table parent-payments-table"><thead><tr><th>Type</th><th>Reason</th><th>Date</th><th class="text-end">Amount</th></tr></thead><tbody>
        <?php foreach ($components['discounts'] as $row): ?><tr><td><?php echo e($row['discount_type']); ?> discount</td><td><?php echo e($row['reason'] ?? '—'); ?></td><td><?php echo e(date('j M Y', strtotime((string) $row['applied_at']))); ?></td><td class="text-end">₹<?php echo e(number_format((float) $row['amount'], 2)); ?></td></tr><?php endforeach; ?>
        <?php foreach ($components['adjustments'] as $row): ?><tr><td><?php echo e(ucfirst((string) $row['adjustment_type'])); ?></td><td><?php echo e($row['reason']); ?></td><td><?php echo e(date('j M Y', strtotime((string) $row['created_at']))); ?></td><td class="text-end">₹<?php echo e(number_format((float) $row['amount'], 2)); ?></td></tr><?php endforeach; ?>
        </tbody></table></div></section>
    <?php endif; ?>
    <section class="parent-payments-panel"><h3>Installments</h3>
        <?php if (!payment_razorpay_is_ready()): ?><div class="alert alert-info" role="status">Online payment is temporarily unavailable. Please contact the institution for payment options.</div><?php endif; ?>
        <?php if ($assignment['installments'] === []): ?><p class="text-secondary">No installment schedule is recorded.</p><?php else: ?>
            <div class="table-responsive"><table class="table parent-payments-table"><thead><tr><th>#</th><th>Due date</th><th>Base amount</th><th>Paid</th><th>Penalty</th><th>Discount</th><th>Adjustment</th><th>Status</th><th>Payment</th></tr></thead><tbody><?php foreach ($assignment['installments'] as $installment):
                $installmentBalancePaise = payment_calculate_installment_balance_paise(
                    (string) $installment['due_amount'],
                    (string) $installment['penalty_amount'],
                    (string) $installment['adjustment_amount'],
                    (string) $installment['discount_amount'],
                    (string) $installment['amount_paid'],
                    (string) $assignment['outstanding_amount']
                );
                $payable = $assignment['status'] === 'active'
                    && $assignment['can_make_payments']
                    && in_array($installment['status'], ['pending', 'partial', 'overdue'], true)
                    && $installmentBalancePaise > 0
                    && payment_razorpay_is_ready();
                ?><tr><td><?php echo e((string) $installment['installment_number']); ?></td><td><?php echo e(date('j M Y', strtotime((string) $installment['due_date']))); ?></td><td>₹<?php echo e(number_format((float) $installment['due_amount'], 2)); ?></td><td>₹<?php echo e(number_format((float) $installment['amount_paid'], 2)); ?></td><td>₹<?php echo e(number_format((float) $installment['penalty_amount'], 2)); ?></td><td>₹<?php echo e(number_format((float) $installment['discount_amount'], 2)); ?></td><td>₹<?php echo e(number_format((float) $installment['adjustment_amount'], 2)); ?></td><td><?php echo e(ucfirst((string) $installment['status'])); ?></td><td><?php if ($payable): ?><form method="post" action="<?php echo e(route_url('/parent/payments/create-order')); ?>"><?php echo csrf_field(); ?><input type="hidden" name="installment_id" value="<?php echo e((string) $installment['id']); ?>"><input type="hidden" name="assignment_id" value="<?php echo e((string) $assignmentId); ?>"><button class="btn btn-sm btn-cobalt" type="submit">Pay ₹<?php echo e(number_format($installmentBalancePaise / 100, 2)); ?></button></form><?php elseif ($installmentBalancePaise > 0 && in_array($installment['status'], ['pending', 'partial', 'overdue'], true)): ?><span class="text-secondary">Online payment unavailable</span><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </section>
    <section class="parent-payments-panel"><h3>Payments for <?php echo e($assignment['student_name']); ?></h3>
        <?php if ($payments['payments'] === []): ?><p class="text-secondary">No payment records are available for this student.</p><?php else: ?>
            <div class="table-responsive"><table class="table parent-payments-table"><thead><tr><th>Date</th><th>Reference</th><th>Status</th><th>Amount</th><th></th></tr></thead><tbody><?php foreach ($payments['payments'] as $payment): ?><tr><td><?php echo e(date('j M Y', strtotime((string) ($payment['verified_at'] ?? $payment['created_at'])))); ?></td><td><?php echo e($payment['receipt_number'] ?? $payment['gateway_reference'] ?? '—'); ?></td><td><?php echo e(ucwords(str_replace('_', ' ', (string) $payment['status']))); ?></td><td><?php echo e((string) $payment['currency']); ?> <?php echo e(number_format((float) $payment['amount'], 2)); ?></td><td><a href="<?php echo e(route_url('/parent/payments/view') . '?id=' . (int) $payment['id']); ?>">View</a></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </section>
</section>
<?php render_app_page_end('parent'); ?>
