<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../services/payment-service.php';
require_once __DIR__ . '/../../services/payment-verification-service.php';

require_http_methods(['POST']);
header('Content-Type: text/plain; charset=utf-8');

$config = app_config()['payments'];
if ($config['razorpay_webhook_secret'] === '') {
    http_response_code(503);
    exit('Webhook is not configured.');
}
$body = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';
$eventId = $_SERVER['HTTP_X_RAZORPAY_EVENT_ID'] ?? '';
if (!is_string($body) || !is_string($signature) || !is_string($eventId)
    || strlen($body) > 1048576
    || preg_match('/^[A-Za-z0-9_.:-]{1,200}$/D', $eventId) !== 1
    || !razorpay_verify_webhook_signature($body, $signature, $config['razorpay_webhook_secret'])) {
    http_response_code(400);
    exit('Invalid webhook signature or request.');
}
$payload = json_decode($body, true);
if (!is_array($payload) || !in_array($payload['event'] ?? null, ['payment.captured', 'payment.failed'], true)) {
    http_response_code(200);
    exit('Event acknowledged.');
}
$entity = $payload['payload']['payment']['entity'] ?? null;
if (!is_array($entity)
    || !is_string($entity['id'] ?? null)
    || !is_string($entity['order_id'] ?? null)
    || preg_match('/^pay_[A-Za-z0-9]+$/D', $entity['id']) !== 1
    || preg_match('/^order_[A-Za-z0-9]+$/D', $entity['order_id']) !== 1) {
    http_response_code(400);
    exit('Invalid payment event.');
}

$db = db_connect();
$orderLookup = $db->prepare(
    "SELECT institution_id, id FROM payment_orders
     WHERE gateway = 'razorpay' AND gateway_order_id = ? LIMIT 2"
);
$orderLookup->bind_param('s', $entity['order_id']);
$orderLookup->execute();
$localOrders = $orderLookup->get_result()->fetch_all(MYSQLI_ASSOC);
$orderLookup->close();
if (count($localOrders) !== 1) {
    log_error('payment_webhook_order_lookup_failed', [
        'operation' => 'webhook_order_lookup',
        'error_type' => 'AmbiguousOrMissingGatewayOrder',
    ]);
    http_response_code(404);
    exit('Payment order not found.');
}
$localOrder = $localOrders[0];
$institutionId = (int) $localOrder['institution_id'];
$orderId = (int) $localOrder['id'];
$eventType = (string) $payload['event'];
$eventData = json_encode([
    'event' => $eventType,
    'payment_id' => $entity['id'],
    'order_id' => $entity['order_id'],
    'status' => is_string($entity['status'] ?? null) ? $entity['status'] : '',
    'amount' => is_int($entity['amount'] ?? null) ? $entity['amount'] : null,
    'currency' => is_string($entity['currency'] ?? null) ? $entity['currency'] : '',
], JSON_THROW_ON_ERROR);
$existingEventQuery = $db->prepare(
    "SELECT id, processing_status FROM payment_webhook_events
     WHERE gateway = 'razorpay' AND event_identifier = ? LIMIT 1"
);
$existingEventQuery->bind_param('s', $eventId);
$existingEventQuery->execute();
$existingEvent = $existingEventQuery->get_result()->fetch_assoc();
$existingEventQuery->close();
if (is_array($existingEvent) && $existingEvent['processing_status'] === 'processed') {
    http_response_code(200);
    exit('Event already processed.');
}

if (is_array($existingEvent)) {
    $eventRowId = (int) $existingEvent['id'];
    $resetEvent = $db->prepare(
        "UPDATE payment_webhook_events SET processing_status = 'received', payload = ?
         WHERE id = ? AND institution_id = ?"
    );
    $resetEvent->bind_param('sii', $eventData, $eventRowId, $institutionId);
    $resetEvent->execute();
    $resetEvent->close();
} else {
    $gateway = 'razorpay';
    $received = 'received';
    $eventInsert = $db->prepare(
        'INSERT INTO payment_webhook_events (
            institution_id, gateway, event_type, event_identifier, payload, processing_status
         ) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $eventInsert->bind_param('isssss', $institutionId, $gateway, $eventType, $eventId, $eventData, $received);
    $eventInsert->execute();
    $eventRowId = (int) $db->insert_id;
    $eventInsert->close();
}

try {
    $paymentId = null;
    if ($eventType === 'payment.captured') {
        $verifiedPayment = razorpay_fetch_payment((string) $entity['id']);
        $paymentId = payment_verification_settle_captured(
            $db,
            $institutionId,
            (string) $entity['order_id'],
            $verifiedPayment
        );
    } else {
        payment_verification_reconcile_gateway_order(
            $db,
            $institutionId,
            $orderId,
            (string) $entity['order_id']
        );
    }

    $processed = 'processed';
    $markProcessed = $db->prepare(
        'UPDATE payment_webhook_events
         SET processing_status = ?, processed_at = CURRENT_TIMESTAMP, payment_id = ?
         WHERE id = ? AND institution_id = ?'
    );
    $markProcessed->bind_param('siii', $processed, $paymentId, $eventRowId, $institutionId);
    $markProcessed->execute();
    $markProcessed->close();
    http_response_code(200);
    exit('Event processed.');
} catch (Throwable $exception) {
    $failed = 'failed';
    $markFailed = $db->prepare(
        'UPDATE payment_webhook_events SET processing_status = ?
         WHERE id = ? AND institution_id = ?'
    );
    $markFailed->bind_param('sii', $failed, $eventRowId, $institutionId);
    $markFailed->execute();
    $markFailed->close();
    log_error('payment_webhook_processing_failed', [
        'operation' => 'webhook',
        'institution_id' => $institutionId,
        'payment_order_id' => $orderId,
        'event_type' => $eventType,
        'error_type' => get_class($exception),
        'error_code' => $exception->getCode(),
    ]);
    http_response_code(500);
    exit('Event processing failed.');
}