<?php
declare(strict_types=1);

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('A session is required to create a CSRF token.');
    }

    if (!isset($_SESSION['csrf_token'])
        || !is_string($_SESSION['csrf_token'])
        || preg_match('/^[a-f0-9]{64}$/D', $_SESSION['csrf_token']) !== 1) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '">';
}

function verify_csrf_token(?string $token): bool
{
    if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1
        || session_status() !== PHP_SESSION_ACTIVE
        || !isset($_SESSION['csrf_token'])
        || !is_string($_SESSION['csrf_token'])) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

function verify_csrf_request(): bool
{
    $token = $_POST['csrf_token'] ?? null;
    return is_string($token) && verify_csrf_token($token);
}

function require_valid_csrf_token(): void
{
    if (!verify_csrf_request()) {
        http_response_code(403);
        exit('Invalid request token.');
    }
}
