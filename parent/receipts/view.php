<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/parent-portal-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$receiptId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!is_int($receiptId) || $receiptId < 1) {
    http_response_code(404);
    exit('Receipt not found.');
}
$db = db_connect();
$context = parent_portal_require_current_parent($db);
$receipt = parent_portal_get_receipt(
    $db,
    (int) $context['parent']['parent_id'],
    (int) $context['user']['institution_id'],
    $receiptId
);
if ($receipt === null) {
    http_response_code(404);
    exit('Receipt not found.');
}

render_app_page_start('Receipt Details', 'Issued receipt information.', 'parent', ['parent-children.css']);
?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb parent-children-breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/parent/dashboard')); ?>">Overview</a></li><li class="breadcrumb-item"><a href="<?php echo e(route_url('/parent/receipts/index')); ?>">Receipts</a></li><li class="breadcrumb-item active" aria-current="page"><?php echo e($receipt['receipt_number']); ?></li></ol></nav>
<section class="parent-children-panel parent-student-detail">
    <div class="parent-student-detail-heading"><div><span class="parent-child-code">Issued receipt</span><h2><?php echo e($receipt['receipt_number']); ?></h2><p class="mb-0 text-secondary"><?php echo e($receipt['student_name']); ?> · <?php echo e($receipt['student_code']); ?></p></div><a class="parent-child-back-link" href="<?php echo e(route_url('/parent/receipts/index')); ?>">Back to Receipts</a></div>
    <dl class="parent-fee-summary-list parent-payment-detail-facts">
        <div><dt>Receipt number</dt><dd><?php echo e($receipt['receipt_number']); ?></dd></div>
        <div><dt>Amount</dt><dd><?php echo e((string) $receipt['currency']); ?> <?php echo e(number_format((float) $receipt['total_amount'], 2)); ?></dd></div>
        <div><dt>Payment status</dt><dd><?php echo e(ucwords(str_replace('_', ' ', (string) $receipt['payment_status']))); ?></dd></div>
        <div><dt>Payment method</dt><dd><?php echo e(ucfirst((string) $receipt['gateway'])); ?></dd></div>
        <div><dt>Issued date</dt><dd><?php echo e(date('j M Y, g:i a', strtotime((string) ($receipt['issued_at'] ?? $receipt['created_at'])))); ?></dd></div>
    </dl>
    <div class="parent-receipt-actions mt-4">
        <a class="btn btn-cobalt" href="<?php echo e(route_url('/parent/receipts/download') . '?id=' . $receiptId); ?>"><i class="bi bi-download me-1" aria-hidden="true"></i>Download PDF receipt</a>
    </div>
</section>
<?php render_app_page_end('parent'); ?>
