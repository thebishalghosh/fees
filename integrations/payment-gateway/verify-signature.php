<?php
declare(strict_types=1);

function razorpay_verify_checkout_signature(
    string $orderId,
    string $paymentId,
    string $signature,
    string $secret
): bool {
    if ($orderId === '' || $paymentId === '' || $secret === ''
        || preg_match('/^[a-f0-9]{64}$/iD', $signature) !== 1) {
        return false;
    }

    return hash_equals(hash_hmac('sha256', $orderId . '|' . $paymentId, $secret), strtolower($signature));
}

function razorpay_verify_webhook_signature(string $body, string $signature, string $secret): bool
{
    if ($body === '' || $secret === '' || preg_match('/^[a-f0-9]{64}$/iD', $signature) !== 1) {
        return false;
    }

    return hash_equals(hash_hmac('sha256', $body, $secret), strtolower($signature));
}