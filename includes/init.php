<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('html_errors', '0');

function report_uncaught_exception(Throwable $exception): void
{
    $reference = bin2hex(random_bytes(8));
    $event = [
        'event' => 'uncaught_exception',
        'reference' => $reference,
        'type' => get_class($exception),
        'source' => basename($exception->getFile()),
        'line' => $exception->getLine(),
    ];
    if (function_exists('log_error')) {
        log_error('uncaught_exception', [
            'reference' => $reference,
            'error_type' => $event['type'],
            'source' => $event['source'],
            'line' => $event['line'],
            'error_code' => $exception->getCode(),
        ]);
    } else {
        error_log('[FeesApp] ' . json_encode($event, JSON_UNESCAPED_SLASHES));
    }

    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'An unexpected error occurred. Reference: ' . $reference;
}

set_exception_handler('report_uncaught_exception');

$appConfig = app_config();
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
if (!defined('APP_URL')) {
    define('APP_URL', rtrim($appConfig['base_url'], '/'));
}
date_default_timezone_set($appConfig['timezone']);

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    if (headers_sent()) {
        throw new RuntimeException('The application session could not be started.');
    }

    $appConfig = app_config();
    $appPath = (string) (parse_url(APP_URL, PHP_URL_PATH) ?: '/');
    $cookiePath = '/' . trim($appPath, '/');
    if ($cookiePath !== '/') {
        $cookiePath = rtrim($cookiePath, '/') . '/';
    }
    $secureCookie = $appConfig['env'] === 'production'
        || (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off');

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_name('fees_portal_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookiePath,
        'domain' => '',
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!session_start()) {
        throw new RuntimeException('The application session could not be started.');
    }
}

function regenerate_session_id(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE || headers_sent()) {
        throw new RuntimeException('The session identifier could not be renewed.');
    }
    if (!session_regenerate_id(true)) {
        throw new RuntimeException('The session identifier could not be renewed.');
    }

    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function clear_app_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION = [];
    $cookie = session_get_cookie_params();
    if (ini_get('session.use_cookies') && !headers_sent()) {
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $cookie['path'],
            'domain' => $cookie['domain'],
            'secure' => $cookie['secure'],
            'httponly' => $cookie['httponly'],
            'samesite' => $cookie['samesite'] ?? 'Lax',
        ]);
    }
    if (!session_destroy()) {
        throw new RuntimeException('The application session could not be cleared.');
    }
}

start_app_session();
