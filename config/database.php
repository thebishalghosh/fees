<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

function db_config(): array
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
    $database = (string) ($combined['DB_NAME'] ?? 'fees_management_test');

    if ($database !== 'fees_management_test') {
        throw new RuntimeException('Database access is restricted to the approved disposable test database.');
    }

    $config = [
        'host' => $combined['DB_HOST'] ?? '127.0.0.1',
        'port' => (int) ($combined['DB_PORT'] ?? 3306),
        'database' => $database,
        'username' => $combined['DB_USERNAME'] ?? 'root',
        'password' => $combined['DB_PASSWORD'] ?? '',
        'charset' => 'utf8mb4',
    ];

    if ($config['host'] === '' || $config['port'] < 1 || $config['port'] > 65535) {
        throw new RuntimeException('Database host or port configuration is invalid.');
    }

    return $config;
}

function db_connect(): mysqli
{
    static $connection = null;
    if ($connection instanceof mysqli) {
        return $connection;
    }

    $config = db_config();
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $newConnection = null;
    try {
        $newConnection = new mysqli(
            $config['host'],
            $config['username'],
            $config['password'],
            $config['database'],
            $config['port']
        );
        $newConnection->set_charset($config['charset']);
    } catch (mysqli_sql_exception $exception) {
        if ($newConnection instanceof mysqli) {
            $newConnection->close();
        }
        if (function_exists('log_error')) {
            log_error('database_connection_failed', [
                'error_type' => get_class($exception),
                'error_code' => $exception->getCode(),
            ]);
        } else {
            error_log('[FeesApp] event=database_connection_failed type=' . get_class($exception));
        }
        throw new RuntimeException('Database is temporarily unavailable.', 0, $exception);
    }

    $connection = $newConnection;
    return $connection;
}
