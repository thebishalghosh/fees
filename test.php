<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

header('Content-Type: text/plain; charset=utf-8');

function database_diagnostic_safe_message(Throwable $exception): string
{
    $message = $exception->getMessage();
    try {
        $config = db_config();
        foreach (['host', 'database', 'username', 'password'] as $key) {
            $value = (string) ($config[$key] ?? '');
            if ($value !== '') {
                $message = str_ireplace($value, '[redacted]', $message);
            }
        }
    } catch (Throwable) {
        // Keep the original diagnostic when configuration itself is invalid.
    }

    return $message;
}

try {
    $db = db_connect();
    $result = $db->query('SELECT 1');
    if ((int) $result->fetch_row()[0] !== 1) {
        throw new RuntimeException('Database diagnostic query returned an unexpected result.');
    }

    echo "Database connection successful." . PHP_EOL;
    $db->close();
} catch (Throwable $exception) {
    error_log('[FeesApp] database_diagnostic_failed type=' . get_class($exception));
    http_response_code(503);
    echo "Database connection failed." . PHP_EOL;
    echo 'Error: ' . database_diagnostic_safe_message($exception) . PHP_EOL;
}
