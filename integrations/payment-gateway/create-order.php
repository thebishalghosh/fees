<?php
declare(strict_types=1);

require_once __DIR__ . '/gateway-client.php';

if (isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

function razorpay_create_order(string $localOrderNumber, int $amountPaise, string $currency): array
{
    if ($amountPaise < 1 || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
        throw new InvalidArgumentException('The payment order amount or currency is invalid.');
    }

    return razorpay_api_request('POST', '/orders', [
        'amount' => $amountPaise,
        'currency' => $currency,
        'receipt' => $localOrderNumber,
        'partial_payment' => false,
        'notes' => ['merchant_order' => $localOrderNumber],
    ]);
}

function razorpay_fetch_payment(string $paymentId): array
{
    if (preg_match('/^pay_[A-Za-z0-9]+$/D', $paymentId) !== 1) {
        throw new InvalidArgumentException('The Razorpay payment reference is invalid.');
    }

    return razorpay_api_request('GET', '/payments/' . rawurlencode($paymentId));
}

function razorpay_fetch_order_payments(string $orderId): array
{
    if (preg_match('/^order_[A-Za-z0-9]+$/D', $orderId) !== 1) {
        throw new InvalidArgumentException('The Razorpay order reference is invalid.');
    }

    $payments = [];
    $skip = 0;
    do {
        $path = '/orders/' . rawurlencode($orderId) . '/payments?count=100&skip=' . $skip;
        $response = razorpay_api_request('GET', $path);
        if (!isset($response['items']) || !is_array($response['items'])) {
            throw new UnexpectedValueException('Razorpay returned an invalid order payment list.');
        }
        $page = $response['items'];
        $payments = [...$payments, ...$page];
        $pageSize = count($page);
        $skip += $pageSize;
    } while ($pageSize === 100);

    return $payments;
}