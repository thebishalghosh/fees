<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';

function razorpay_api_request(string $method, string $path, ?array $payload = null): array
{
    $config = app_config()['payments'];
    if ($config['razorpay_key_id'] === '' || $config['razorpay_key_secret'] === '') {
        throw new RuntimeException('Razorpay API credentials are not configured.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required for Razorpay payments.');
    }
    $allowedPath = preg_match(
        '#^/(?:orders(?:/[A-Za-z0-9_-]+(?:/payments)?)?|payments/[A-Za-z0-9_-]+)$#D',
        $path
    ) === 1;
    $allowedPaymentListPage = $method === 'GET'
        && preg_match(
            '#^/orders/order_[A-Za-z0-9]+/payments\?count=100(?:&skip=[0-9]+)?$#D',
            $path
        ) === 1;
    if (!in_array($method, ['GET', 'POST'], true)
        || (!$allowedPath && !$allowedPaymentListPage)) {
        throw new InvalidArgumentException('The Razorpay API request is invalid.');
    }

    $handle = curl_init('https://api.razorpay.com/v1' . $path);
    if ($handle === false) {
        throw new RuntimeException('Razorpay could not be reached.');
    }
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $config['razorpay_key_id'] . ':' . $config['razorpay_key_secret'],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => (int) $config['timeout_seconds'],
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ];
    if ($payload !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
        $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
    }
    curl_setopt_array($handle, $options);
    $responseBody = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $curlError = curl_errno($handle);
    curl_close($handle);

    if (!is_string($responseBody) || $curlError !== 0) {
        throw new RuntimeException('Razorpay did not return a usable response.');
    }
    $response = json_decode($responseBody, true);
    if (!is_array($response) || $status < 200 || $status >= 300) {
        throw new RuntimeException('Razorpay rejected the payment request.');
    }

    return $response;
}