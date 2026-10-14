<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../services/parent-portal-service.php';
require_once __DIR__ . '/../../services/payment-service.php';
require_once __DIR__ . '/../../includes/flash.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$orderId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!is_int($orderId) || $orderId < 1) {
    http_response_code(404);
    exit('Payment order not found.');
}
$db = db_connect();
$context = parent_portal_require_current_parent($db);
$order = payment_service_get_parent_order(
    $db,
    (int) $context['parent']['parent_id'],
    (int) $context['user']['institution_id'],
    $orderId,
    true
);
if ($order === null || !is_string($order['gateway_order_id'])
    || $order['gateway_order_id'] === '' || $order['status'] === 'paid') {
    http_response_code(404);
    exit('Payment order not found.');
}
if (!payment_razorpay_is_ready()) {
    set_flash('warning', 'Razorpay checkout is unavailable until valid API credentials and PHP cURL are configured.', 'parent_payment');
    safe_redirect('/parent/fees/view?id=' . (int) $order['assignment_id']);
}
$checkout = [
    'key' => (string) app_config()['payments']['razorpay_key_id'],
    'amount' => payment_money_to_paise((string) $order['amount']),
    'currency' => (string) $order['currency'],
    'order_id' => (string) $order['gateway_order_id'],
    'name' => (string) app_config()['app_name'],
    'description' => 'Installment ' . (string) $order['installment_number'],
    'callback_url' => route_url('/parent/payments/verify'),
    'local_order_id' => (int) $order['id'],
    'attempt_id' => (int) $order['attempt_id'],
    'csrf_token' => csrf_token(),
    'status_url' => route_url('/parent/payments/status'),
];
$checkoutJson = json_encode(
    $checkout,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
);

render_app_page_start('Secure Checkout', 'Confirm your installment payment through Razorpay Checkout.', 'parent', ['parent-children.css']);
?>
<?php echo render_flash_messages('parent_payment'); ?>
<section class="parent-children-panel parent-student-detail">
    <div class="parent-student-detail-heading">
        <div><span class="parent-child-code"><?php echo e($order['student_name']); ?></span><h2>Installment <?php echo e((string) $order['installment_number']); ?></h2><p class="mb-0 text-secondary">Due <?php echo e(date('j M Y', strtotime((string) $order['due_date']))); ?></p></div>
        <strong><?php echo e((string) $order['currency']); ?> <?php echo e(number_format((float) $order['amount'], 2)); ?></strong>
    </div>
    <p class="text-secondary">Your installment balance will update only after Razorpay confirms a captured payment with the matching order, amount, and currency.</p>
    <button class="btn btn-cobalt" id="razorpay-pay-button" type="button">Continue to secure payment</button>
    <a class="btn btn-outline-secondary ms-2" href="<?php echo e(route_url('/parent/fees/view') . '?id=' . (int) $order['assignment_id']); ?>">Back to Fee Details</a>
    <p class="small text-secondary mt-3 mb-0">If checkout is closed or interrupted, your payment will remain pending until verified.</p>
</section>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
(() => {
    const config = <?php echo $checkoutJson; ?>;
    const button = document.getElementById('razorpay-pay-button');
    const statusUrl = `${config.status_url}?id=${config.local_order_id}`;
    button.addEventListener('click', () => {
        button.disabled = true;
        const checkout = new Razorpay({
            key: config.key,
            amount: config.amount,
            currency: config.currency,
            name: config.name,
            description: config.description,
            order_id: config.order_id,
            handler: async (response) => {
                const values = new URLSearchParams({
                    csrf_token: config.csrf_token,
                    order_id: String(config.local_order_id),
                    razorpay_order_id: response.razorpay_order_id || '',
                    razorpay_payment_id: response.razorpay_payment_id || '',
                    razorpay_signature: response.razorpay_signature || ''
                });
                let state = 'verification';
                try {
                    const verification = await fetch(config.callback_url, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: values.toString()
                    });
                    const result = await verification.json();
                    if (!verification.ok || !['success', 'pending'].includes(result.status)) {
                        state = 'verification_error';
                    }
                } catch {
                    state = 'verification_error';
                }
                window.location.assign(`${statusUrl}&state=${state}`);
            },
            modal: {ondismiss: () => window.location.assign(`${statusUrl}&state=cancelled`)}
        });
        checkout.on('payment.failed', () => window.location.assign(`${statusUrl}&state=failed`));
        checkout.open();
        button.disabled = false;
    });
})();
</script>
<?php render_app_page_end('parent'); ?>
