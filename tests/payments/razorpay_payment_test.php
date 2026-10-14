<?php
declare(strict_types=1);

require_once __DIR__ . '/../../services/payment-service.php';
require_once __DIR__ . '/../../services/payment-verification-service.php';

$checks = 0;
$failures = [];
$check = static function (bool $condition, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $label;
    }
};

$check(payment_money_to_paise('5000.00') === 500000, 'Convert rupee amounts to integer paise');
$check(payment_money_to_paise('0.05') === 5, 'Preserve small decimal amounts without float rounding');
$check(payment_paise_to_money(200001) === '2000.01', 'Convert paise back to a two-decimal amount');
$check(
    payment_calculate_installment_balance_paise('5000.00', '0.00', '0.00', '0.00', '1500.00', '3500.00') === 350000,
    'Calculate only the outstanding remainder for an installment with a previous partial payment'
);
$check(
    payment_calculate_installment_balance_paise('5000.00', '0.00', '0.00', '0.00', '0.00', '2000.00') === 200000,
    'Never order more than the authoritative assignment balance'
);
$check(
    payment_calculate_installment_balance_paise('5000.00', '0.00', '0.00', '0.00', '3500.00', '3500.00') === 150000,
    'Keep installment and assignment balances consistent after a successful partial settlement'
);
$check(
    !payment_verification_installment_is_fully_paid('1000.00', '5000.00', '0.00', '0.00', '0.00')
        && payment_verification_installment_is_fully_paid('5000.00', '5000.00', '0.00', '0.00', '0.00')
        && payment_verification_installment_is_fully_paid('900.00', '1000.00', '0.00', '0.00', '100.00'),
    'Compare installment payment totals and adjusted due amounts in integer paise'
);
$validTestCredentials = [
    'razorpay_key_id' => 'rzp_test_fixture',
    'razorpay_key_secret' => 'fixture_secret',
    'razorpay_webhook_secret' => '',
];
$check(
    payment_razorpay_credentials_are_valid($validTestCredentials, 'development', true),
    'Allow test checkout with valid API credentials and no webhook secret'
);
$check(
    !payment_razorpay_credentials_are_valid(
        ['razorpay_key_id' => '', 'razorpay_key_secret' => '', 'razorpay_webhook_secret' => ''],
        'development',
        true
    ),
    'Disable checkout when API credentials are missing'
);
$check(
    !payment_razorpay_credentials_are_valid($validTestCredentials, 'development', false),
    'Disable checkout when the PHP cURL extension is unavailable'
);
$invalidKeyId = $validTestCredentials;
$invalidKeyId['razorpay_key_id'] = 'invalid_key_id';
$check(
    !payment_razorpay_credentials_are_valid($invalidKeyId, 'development', true),
    'Disable checkout when the API key ID has an invalid format'
);
foreach (['-1.00', '1.001', '01.00', '10000000000.00', 'invalid'] as $invalidAmount) {
    $rejected = false;
    try {
        payment_money_to_paise($invalidAmount);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    $check($rejected, 'Reject invalid or out-of-range payment amounts');
}

$secret = 'fixture_only_secret';
$orderId = 'order_fixture123';
$paymentId = 'pay_fixture456';
$checkoutSignature = hash_hmac('sha256', $orderId . '|' . $paymentId, $secret);
$webhookBody = '{"event":"payment.captured","fixture":true}';
$webhookSignature = hash_hmac('sha256', $webhookBody, $secret);
$check(
    razorpay_verify_checkout_signature($orderId, $paymentId, $checkoutSignature, $secret),
    'Accept a valid Razorpay checkout signature fixture'
);
$check(
    !razorpay_verify_checkout_signature($orderId, $paymentId, str_repeat('0', 64), $secret),
    'Reject an invalid checkout signature'
);
$check(
    razorpay_verify_webhook_signature($webhookBody, $webhookSignature, $secret),
    'Accept a valid webhook signature fixture'
);
$check(
    !razorpay_verify_webhook_signature($webhookBody . ' ', $webhookSignature, $secret),
    'Reject a webhook signature when the raw body changes'
);
$capturedFixture = [
    'id' => 'pay_fixture456',
    'order_id' => 'order_fixture123',
    'amount' => 250000,
    'currency' => 'INR',
    'status' => 'captured',
];
$check(
    payment_verification_gateway_payment_is_captured($capturedFixture, 'order_fixture123', 250000),
    'Accept server-verified captured payment details matching the expected order, amount, and currency'
);
$wrongOrder = $capturedFixture;
$wrongOrder['order_id'] = 'order_other123';
$check(
    !payment_verification_gateway_payment_is_captured($wrongOrder, 'order_fixture123', 250000),
    'Reject a payment associated with a different Razorpay order'
);
$wrongAmount = $capturedFixture;
$wrongAmount['amount'] = 249999;
$check(
    !payment_verification_gateway_payment_is_captured($wrongAmount, 'order_fixture123', 250000),
    'Reject a payment whose amount differs by one paise'
);
$wrongCurrency = $capturedFixture;
$wrongCurrency['currency'] = 'USD';
$check(
    !payment_verification_gateway_payment_is_captured($wrongCurrency, 'order_fixture123', 250000),
    'Reject a payment with an unexpected currency'
);
$uncaptured = $capturedFixture;
$uncaptured['status'] = 'authorized';
$check(
    !payment_verification_gateway_payment_is_captured($uncaptured, 'order_fixture123', 250000),
    'Do not confirm an authorized but uncaptured payment'
);
$check(
    payment_verification_recovery_state(['failed', 'captured']) === 'captured',
    'Prioritize a delayed captured payment over earlier failed attempts'
);
$check(
    payment_verification_recovery_state(['failed', 'authorized']) === 'pending'
        && payment_verification_recovery_state(['created']) === 'pending',
    'Keep recovery pending while any gateway payment is not terminal'
);
$check(
    payment_verification_recovery_state([]) === 'no_payment'
        && payment_verification_recovery_state(['failed', 'failed']) === 'failed',
    'Distinguish an unused order from an order with only verified failed payments'
);
$mockGatewayPayments = [
    'pay_fixturefailed' => [
        'id' => 'pay_fixturefailed',
        'order_id' => $orderId,
        'amount' => 250000,
        'currency' => 'INR',
        'status' => 'failed',
    ],
    $paymentId => $capturedFixture,
];
$mockFetchPayment = static function (string $id) use ($mockGatewayPayments): array {
    if (!isset($mockGatewayPayments[$id])) {
        throw new RuntimeException('Unexpected mock payment ID.');
    }
    return $mockGatewayPayments[$id];
};
$delayedCaptureInspection = payment_verification_inspect_gateway_payments(
    [['id' => 'pay_fixturefailed'], ['id' => $paymentId]],
    $mockFetchPayment,
    $orderId,
    250000,
    'INR'
);
$repeatedCaptureInspection = payment_verification_inspect_gateway_payments(
    [['id' => 'pay_fixturefailed'], ['id' => $paymentId]],
    $mockFetchPayment,
    $orderId,
    250000,
    'INR'
);
$check(
    $delayedCaptureInspection['state'] === 'captured'
        && ($delayedCaptureInspection['payment']['id'] ?? null) === $paymentId
        && $repeatedCaptureInspection['state'] === 'captured'
        && ($repeatedCaptureInspection['payment']['id'] ?? null) === $paymentId,
    'Recover the same delayed capture on repeated reconciliation without changing its identity'
);
$pendingInspection = payment_verification_inspect_gateway_payments(
    [['id' => $paymentId]],
    static fn(string $id): array => [...$capturedFixture, 'status' => 'authorized'],
    $orderId,
    250000,
    'INR'
);
$failedInspection = payment_verification_inspect_gateway_payments(
    [['id' => 'pay_fixturefailed']],
    $mockFetchPayment,
    $orderId,
    250000,
    'INR'
);
$check(
    $pendingInspection['state'] === 'pending' && $failedInspection['state'] === 'failed',
    'Keep authorized payments pending and recognize only authoritative failed gateway payments'
);
$gatewayTimeoutSurfaced = false;
try {
    payment_verification_inspect_gateway_payments(
        [['id' => $paymentId]],
        static function (string $id): array {
            throw new RuntimeException('simulated gateway timeout');
        },
        $orderId,
        250000,
        'INR'
    );
} catch (RuntimeException $exception) {
    $gatewayTimeoutSurfaced = $exception->getMessage() === 'simulated gateway timeout';
}
$check($gatewayTimeoutSurfaced, 'Surface gateway timeouts without treating the payment as failed');
$wrongOwnerPayment = $capturedFixture;
$wrongOwnerPayment['order_id'] = 'order_other123';
$crossOrderRejected = false;
try {
    payment_verification_inspect_gateway_payments(
        [['id' => $paymentId]],
        static fn(string $id): array => $wrongOwnerPayment,
        $orderId,
        250000,
        'INR'
    );
} catch (DomainException) {
    $crossOrderRejected = true;
}
$check($crossOrderRejected, 'Reject gateway payment data belonging to a different order');
$malformedGatewayListRejected = false;
try {
    payment_verification_inspect_gateway_payments(
        [['id' => 'invalid_payment_id']],
        $mockFetchPayment,
        $orderId,
        250000,
        'INR'
    );
} catch (UnexpectedValueException) {
    $malformedGatewayListRejected = true;
}
$check(
    $malformedGatewayListRejected,
    'Fail closed on malformed gateway entries instead of treating them as an unused order'
);

$service = file_get_contents(__DIR__ . '/../../services/payment-service.php');
$verification = file_get_contents(__DIR__ . '/../../services/payment-verification-service.php');
$reconciliation = file_get_contents(__DIR__ . '/../../services/reconciliation-service.php');
$webhook = file_get_contents(__DIR__ . '/../../integrations/payment-gateway/webhook.php');
$gatewayClient = file_get_contents(__DIR__ . '/../../integrations/payment-gateway/gateway-client.php');
$gatewayOperations = file_get_contents(__DIR__ . '/../../integrations/payment-gateway/create-order.php');
$feeDetails = file_get_contents(__DIR__ . '/../../parent/fees/view.php');
$createOrderRoute = file_get_contents(__DIR__ . '/../../parent/payments/create-order.php');
$checkoutPage = file_get_contents(__DIR__ . '/../../parent/payments/checkout.php');
$verifyRoute = file_get_contents(__DIR__ . '/../../parent/payments/verify.php');
$statusPage = file_get_contents(__DIR__ . '/../../parent/payments/status.php');
$auth = file_get_contents(__DIR__ . '/../../includes/auth.php');
$parentService = file_get_contents(__DIR__ . '/../../services/parent-portal-service.php');
$schema = file_get_contents(__DIR__ . '/../../database/schema.sql');
$sourcesAvailable = is_string($service)
    && is_string($verification)
    && is_string($reconciliation)
    && is_string($webhook)
    && is_string($gatewayClient)
    && is_string($gatewayOperations)
    && is_string($feeDetails)
    && is_string($createOrderRoute)
    && is_string($checkoutPage)
    && is_string($verifyRoute)
    && is_string($statusPage)
    && is_string($auth)
    && is_string($parentService)
    && is_string($schema);
$check($sourcesAvailable, 'Read the payment, parent-fee, webhook, and schema sources');

if ($sourcesAvailable) {
    $check(
        str_contains($service, "psr.can_view_fees = 1")
            && str_contains($service, 'psr.can_make_payments = 1')
            && str_contains($service, 'i.institution_id = ? AND i.id = ?')
            && str_contains($service, 'FOR UPDATE')
            && str_contains($service, 'payment_require_razorpay_ready')
            && !str_contains($service, 'razorpay_webhook_secret'),
        'Scope order creation and allow valid credentials without requiring webhooks'
    );
    $check(
        str_contains($verification, "!== 'captured'")
            && str_contains($verification, '$gatewayPayment[\'amount\'] !== $expectedPaise')
            && str_contains($verification, 'razorpay_fetch_payment')
            && str_contains($verification, 'FOR UPDATE')
            && str_contains($verification, 'receipt_service_issue_for_payment')
            && str_contains($verification, "payment.verified")
            && str_contains($verification, "if (is_array(\$existing) && \$existing['status'] === 'success')")
            && str_contains($verification, 'return (int) $existing[\'id\'];')
            && str_contains($verification, 'payment_verification_installment_is_fully_paid')
            && str_contains($verification, '$updateInstallment->bind_param(')
            && str_contains($verification, "'sssii',")
            && str_contains($verification, 'catch (Throwable $exception)')
            && str_contains($verification, '$db->rollback();')
            && str_contains($verification, 'payment_reconciliation_check_parent_order'),
        'Verify captured status, make repeated verification idempotent, and support server-side status checks'
    );
    $check(
        str_contains($verification, 'function payment_verification_reconcile_gateway_order(')
            && str_contains($verification, "gateway_order_id = ? LIMIT 1")
            && str_contains($verification, 'payment_verification_gateway_payment_matches(')
            && str_contains($verification, 'payment_verification_settle_captured('),
        'Reconcile against authoritative payment details scoped to the institution and local order'
    );
    $check(
        str_contains($verification, 'function payment_verification_mark_order_attempts_failed(')
            && str_contains($verification, 'SELECT status FROM payment_orders')
            && str_contains($verification, "status = 'success'")
            && str_contains($verification, 'FOR UPDATE')
            && str_contains($verification, "if (\$order['status'] === 'paid')"),
        'Do not regress an order or its attempts after successful settlement'
    );
    $check(
        str_contains($service, 'payment_verification_reconcile_gateway_order(')
            && str_contains($service, "\$recoveryState === 'pending'")
            && str_contains($service, "\$recoveryState === 'no_payment'")
            && str_contains($service, 'DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE)'),
        'Reconcile a stale checkout before cancelling or retrying its attempt'
    );
    $check(
        str_contains($reconciliation, "status IN ('pending', 'created', 'authorized', 'failed', 'cancelled')")
            && str_contains($reconciliation, 'payment_verification_reconcile_gateway_order(')
            && str_contains($reconciliation, "'payment_order_id' => \$orderId")
            && str_contains($reconciliation, "'error_type' => get_class(\$exception)"),
        'Recover gateway-linked pending and delayed orders and log safe local identifiers on failure'
    );
    $check(
        str_contains($gatewayOperations, '/payments?count=100&skip=')
            && str_contains($gatewayOperations, '$pageSize === 100')
            && str_contains($gatewayClient, 'allowedPaymentListPage')
            && str_contains($gatewayClient, '\?count=100(?:&skip=[0-9]+)?'),
        'Paginate Razorpay order payments with a strict allowlist so delayed captures are not skipped'
    );
    $check(
        str_contains($webhook, "if (\$eventType === 'payment.captured')")
            && str_contains($webhook, 'payment_verification_reconcile_gateway_order(')
            && !str_contains($webhook, 'payment_service_set_attempt_status(')
            && str_contains($schema, 'UNIQUE KEY uq_webhook_identifier (gateway, event_identifier)'),
        'Prevent delayed failed callbacks from regressing captured orders and retain webhook deduplication'
    );
    $check(
        str_contains($statusPage, '$paymentStatus === \'pending\' && payment_razorpay_is_ready()')
            && str_contains($statusPage, 'payment_reconciliation_check_parent_order')
            && str_contains($statusPage, '$paymentStatus = \'pending\';'),
        'Keep repeated status checks pending until server-side Razorpay reconciliation verifies capture'
    );
    $check(
        str_contains($statusPage, 'payment_reconciliation_check_parent_order')
            && str_contains($statusPage, 'payment_service_get_parent_order')
            && !str_contains($statusPage, 'attempt_status ='),
        'Recheck pending status from the server without trusting client-supplied payment outcomes'
    );
    $check(
        str_contains($webhook, 'HTTP_X_RAZORPAY_SIGNATURE')
            && str_contains($webhook, 'razorpay_verify_webhook_signature')
            && str_contains($webhook, 'payment_webhook_events')
            && str_contains($webhook, "\$processed = 'processed'")
            && str_contains($webhook, 'SET processing_status = ?')
            && str_contains($webhook, "\$config['razorpay_webhook_secret'] === ''")
            && str_contains($webhook, 'http_response_code(503)'),
        'Validate webhook signatures and persist idempotent event processing state'
    );
    $check(
        str_contains($feeDetails, 'name="installment_id"')
            && !preg_match('/<input[^>]+name="amount"/i', $feeDetails)
            && str_contains($parentService, "SELECT id, installment_number, due_date")
            && str_contains($createOrderRoute, "require_http_methods(['POST'])")
            && str_contains($createOrderRoute, 'require_valid_csrf_token()')
            && str_contains($feeDetails, 'method="post"')
            && str_contains($feeDetails, "route_url('/parent/payments/create-order')")
            && str_contains($feeDetails, 'csrf_field()'),
        'Submit payment orders using POST and CSRF without accepting a browser-supplied amount'
    );
    $check(
        str_contains($auth, "elseif (str_starts_with(\$scriptPath, '/parent/payments/'))")
            && str_contains($auth, "\$permission = 'payments.view';")
            && str_contains($createOrderRoute, 'parent_portal_require_current_parent($db)')
            && str_contains($service, 'psr.can_make_payments = 1')
            && str_contains($service, 'WHERE i.institution_id = ? AND i.id = ?'),
        'Authorize payment routes and enforce parent, institution, relationship, and payment permission checks'
    );
    $check(
        str_contains($checkoutPage, "method: 'POST'")
            && str_contains($checkoutPage, "credentials: 'same-origin'")
            && str_contains($checkoutPage, 'csrf_token: config.csrf_token')
            && str_contains($checkoutPage, 'verification_error')
            && str_contains($statusPage, 'We could not confirm the checkout response just now')
            && str_contains($verifyRoute, "'source' => \$exception->getFile()")
            && str_contains($verifyRoute, "'line' => \$exception->getLine()")
            && str_contains($statusPage, "'source' => \$exception->getFile()")
            && str_contains($statusPage, "'line' => \$exception->getLine()"),
        'Post checkout verification with the parent session and CSRF token and show a safe error state'
    );
    $check(
        str_contains($schema, 'uq_payment_orders_idempotency')
            && str_contains($schema, 'uq_payment_orders_gateway_order')
            && str_contains($schema, 'uq_payments_order')
            && str_contains($schema, 'uq_payments_gateway_txn')
            && str_contains($schema, 'uq_webhook_identifier')
            && str_contains($schema, 'uq_receipts_payment'),
        'Retain database idempotency and uniqueness constraints for orders, payments, webhooks, and receipts'
    );
    $check(
        str_contains($verification, '$db->begin_transaction();')
            && str_contains($verification, 'LIMIT 1 FOR UPDATE')
            && str_contains($verification, '$db->rollback();')
            && str_contains($verification, "if (is_array(\$existing) && \$existing['status'] === 'success')")
            && str_contains($verification, "return (int) \$existing['id'];"),
        'Keep concurrent settlement serialized and preserve existing successful payments and receipts'
    );
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "{$checks} Razorpay payment checks passed." . PHP_EOL);
