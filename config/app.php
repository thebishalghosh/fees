<?php
declare(strict_types=1);

if (!function_exists('load_env_file')) {
    function load_env_file(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $values = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $parts = explode('=', $trimmed, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);

            if (preg_match('/^"(.*)"$/', $value, $matches) === 1) {
                $value = $matches[1];
            } elseif (preg_match("/^'(.*)'$/", $value, $matches) === 1) {
                $value = $matches[1];
            }

            $values[$key] = $value;
        }

        return $values;
    }
}

function app_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $env = load_env_file(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');
    $processEnvironment = getenv();
    $combined = array_merge(
        $env,
        is_array($processEnvironment) ? $processEnvironment : [],
        $_ENV,
        $_SERVER
    );
    $environment = strtolower((string) ($combined['APP_ENV'] ?? 'development'));
    $timezone = (string) ($combined['APP_TIMEZONE'] ?? 'Asia/Kolkata');
    $baseUrl = trim((string) ($combined['APP_URL'] ?? 'http://localhost/fees'));
    $parsedBaseUrl = filter_var($baseUrl, FILTER_VALIDATE_URL) !== false ? parse_url($baseUrl) : false;

    $resolvedConfig = [
        'app_name' => $combined['APP_NAME'] ?? 'Fees Management System',
        'env' => $environment,
        'debug' => $environment !== 'production'
            && filter_var($combined['APP_DEBUG'] ?? '0', FILTER_VALIDATE_BOOLEAN),
        'base_url' => rtrim($baseUrl, '/'),
        'timezone' => $timezone,
        'auth' => [
            'otp_hash_key' => (string) ($combined['OTP_HASH_KEY'] ?? ''),
            'otp_lifetime_seconds' => 300,
            'otp_resend_cooldown_seconds' => 60,
            'otp_max_attempts' => 5,
            'otp_request_limit' => 10,
            'otp_request_window_seconds' => 900,
            'otp_verify_limit' => 20,
            'otp_verify_window_seconds' => 900,
            'session_idle_timeout_seconds' => 1800,
            'session_absolute_timeout_seconds' => 28800,
        ],
        'mail' => [
            'provider' => strtolower((string) ($combined['MAIL_PROVIDER'] ?? '')),
            'host' => (string) ($combined['MAIL_HOST'] ?? ''),
            'port' => (int) ($combined['MAIL_PORT'] ?? 0),
            'username' => (string) ($combined['MAIL_USERNAME'] ?? ''),
            'password' => (string) ($combined['MAIL_PASSWORD'] ?? ''),
            'encryption' => strtolower((string) ($combined['MAIL_ENCRYPTION'] ?? '')),
            'from_address' => (string) ($combined['MAIL_FROM_ADDRESS'] ?? ''),
            'from_name' => (string) ($combined['MAIL_FROM_NAME'] ?? ''),
            'timeout_seconds' => (int) ($combined['MAIL_TIMEOUT'] ?? 5),
        ],
        'payments' => [
            'razorpay_key_id' => trim((string) ($combined['RAZORPAY_KEY_ID'] ?? '')),
            'razorpay_key_secret' => (string) ($combined['RAZORPAY_KEY_SECRET'] ?? ''),
            'razorpay_webhook_secret' => (string) ($combined['RAZORPAY_WEBHOOK_SECRET'] ?? ''),
            'timeout_seconds' => 15,
        ],
    ];

    if (!in_array($environment, ['development', 'testing', 'production'], true)) {
        throw new RuntimeException('APP_ENV must be development, testing, or production.');
    }

    if (!is_array($parsedBaseUrl)
        || !isset($parsedBaseUrl['scheme'], $parsedBaseUrl['host'])
        || !in_array(strtolower($parsedBaseUrl['scheme']), ['http', 'https'], true)
        || (isset($parsedBaseUrl['port']) && ($parsedBaseUrl['port'] < 1 || $parsedBaseUrl['port'] > 65535))
        || isset($parsedBaseUrl['user'])
        || isset($parsedBaseUrl['pass'])
        || isset($parsedBaseUrl['query'])
        || isset($parsedBaseUrl['fragment'])) {
        throw new RuntimeException('APP_URL must be an HTTP(S) origin with an optional application path.');
    }

    try {
        new DateTimeZone($timezone);
    } catch (Exception $exception) {
        throw new RuntimeException('APP_TIMEZONE must be a valid timezone identifier.');
    }

    $config = $resolvedConfig;
    return $config;
}
