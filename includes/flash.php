<?php
declare(strict_types=1);

function set_flash(string $type, string $message, ?string $scope = null): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('A session is required to store a flash message.');
    }

    if ($scope !== null && preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $scope) !== 1) {
        throw new InvalidArgumentException('The flash message scope is invalid.');
    }

    $allowedTypes = ['success', 'danger', 'warning', 'info'];
    $flash = [
        'type' => in_array($type, $allowedTypes, true) ? $type : 'info',
        'message' => $message,
    ];
    if ($scope !== null) {
        $flash['scope'] = $scope;
    }
    $_SESSION['flash'][] = $flash;
}

function render_flash_messages(?string $scope = null): string
{
    if ($scope !== null && preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $scope) !== 1) {
        throw new InvalidArgumentException('The flash message scope is invalid.');
    }

    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        return '';
    }

    $html = '';
    $remaining = [];
    foreach ($_SESSION['flash'] as $flash) {
        if (!is_array($flash)) {
            continue;
        }

        $flashScope = $flash['scope'] ?? null;
        // Existing sessions may contain the old unscoped parent-only message.
        if ($flashScope === null
            && ($flash['message'] ?? null)
                === 'If the address matches one eligible parent account, a code may be sent to its registered email address.') {
            $flashScope = 'parent_auth';
        }
        if ($scope !== null && $flashScope !== null && $flashScope !== $scope) {
            $remaining[] = $flash;
            continue;
        }

        $type = $flash['type'] ?? 'info';
        if (!in_array($type, ['success', 'danger', 'warning', 'info'], true)) {
            $type = 'info';
        }
        $message = is_string($flash['message'] ?? null) ? $flash['message'] : 'An unexpected error occurred.';

        $html .= '<div class="alert alert-' . $type . ' alert-dismissible fade show" role="alert">'
            . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
            . '</div>';
    }

    if ($remaining === []) {
        unset($_SESSION['flash']);
    } else {
        $_SESSION['flash'] = $remaining;
    }
    return $html;
}
