<?php
declare(strict_types=1);

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/flash.php';
require_once __DIR__ . '/validation.php';

function e(mixed $value): string
{
    if ($value !== null && !is_scalar($value) && !$value instanceof Stringable) {
        throw new InvalidArgumentException('HTML output values must be scalar or stringable.');
    }

    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function route_url(string $path = '/'): string
{
    if ($path === '' || preg_match('/[\x00-\x20\x7F\\\\]/', $path) === 1) {
        throw new InvalidArgumentException('The application route is invalid.');
    }

    $route = parse_url($path);
    if (!is_array($route)
        || isset($route['scheme'])
        || isset($route['host'])
        || isset($route['user'])
        || isset($route['pass'])) {
        throw new InvalidArgumentException('The application route must be a local path.');
    }

    $routePath = (string) ($route['path'] ?? '/');
    if (!str_starts_with($routePath, '/')
        || preg_match('#(?:^|/)\.\.(?:/|$)#', $routePath) === 1
        || preg_match('#[^A-Za-z0-9._~/-]#', $routePath) === 1
        || str_ends_with(strtolower($routePath), '.php')) {
        throw new InvalidArgumentException('The application route is invalid.');
    }
    $base = rtrim(APP_URL, '/');
    $url = $base . ($routePath === '/' ? '/' : $routePath);
    if (isset($route['query'])) {
        $url .= '?' . $route['query'];
    }
    if (isset($route['fragment'])) {
        $url .= '#' . $route['fragment'];
    }

    return $url;
}

function mask_email_address(string $email): string
{
    $separator = strrpos($email, '@');
    if ($separator === false || $separator === 0 || $separator === strlen($email) - 1) {
        return 'your registered email address';
    }

    $localPart = substr($email, 0, $separator);
    $domain = substr($email, $separator + 1);
    $maskedLocalPart = strlen($localPart) === 1
        ? '*'
        : $localPart[0] . str_repeat('*', min(strlen($localPart) - 1, 6));

    return $maskedLocalPart . '@' . $domain;
}

function require_http_methods(array $allowedMethods): void
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, $allowedMethods, true)) {
        header('Allow: ' . implode(', ', $allowedMethods));
        http_response_code(405);
        exit('Method Not Allowed.');
    }
}

function log_error(string $event, array $context = []): void
{
    $safeEvent = preg_match('/^[a-z][a-z0-9_.-]{2,80}$/D', $event) === 1
        ? $event
        : 'application_error';
    $safeContext = [];
    foreach (['error_type', 'operation', 'reference', 'source', 'line', 'status', 'error_code'] as $key) {
        if (!array_key_exists($key, $context) || !is_scalar($context[$key])) {
            continue;
        }

        $value = (string) $context[$key];
        if ($key === 'source') {
            $value = basename($value);
        }
        if (in_array($key, ['error_type', 'operation', 'reference', 'source'], true)
            && preg_match('/^[A-Za-z0-9_.-]{1,100}$/D', $value) !== 1) {
            continue;
        }
        if (in_array($key, ['line', 'status', 'error_code'], true) && !ctype_digit($value)) {
            continue;
        }

        $safeContext[$key] = $value;
    }

    $entry = ['event' => $safeEvent] + $safeContext;
    error_log('[FeesApp] ' . json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
}

function is_safe_redirect(string $location): bool
{
    if ($location === '' || trim($location) !== $location
        || preg_match('/[\x00-\x20\x7F\\\\]/', $location) === 1
        || str_starts_with($location, '//')) {
        return false;
    }

    $target = parse_url($location);
    $application = parse_url(APP_URL);
    if (!is_array($target) || !is_array($application)
        || !isset($application['scheme'], $application['host'])) {
        return false;
    }

    $targetHasOrigin = isset($target['scheme']) || isset($target['host']);
    if ($targetHasOrigin) {
        if (!isset($target['scheme'], $target['host'])
            || isset($target['user'])
            || isset($target['pass'])
            || !in_array(strtolower($target['scheme']), ['http', 'https'], true)) {
            return false;
        }

        $targetPort = $target['port'] ?? (strtolower($target['scheme']) === 'https' ? 443 : 80);
        $appScheme = strtolower($application['scheme']);
        $appPort = $application['port'] ?? ($appScheme === 'https' ? 443 : 80);
        if (strtolower($target['scheme']) !== $appScheme
            || strtolower($target['host']) !== strtolower($application['host'])
            || $targetPort !== $appPort) {
            return false;
        }
    } elseif (isset($target['user']) || isset($target['pass'])) {
        return false;
    }

    $basePath = rtrim((string) ($application['path'] ?? ''), '/');
    $path = (string) ($target['path'] ?? '');
    if ($targetHasOrigin) {
        return $basePath === ''
            || $path === $basePath
            || str_starts_with($path, $basePath . '/');
    }

    return true;
}

function safe_redirect(string $location, int $statusCode = 302): never
{
    if (!is_safe_redirect($location) || !in_array($statusCode, [300, 301, 302, 303, 307, 308], true)) {
        throw new InvalidArgumentException('The redirect target or status is not allowed.');
    }

    $target = parse_url($location);
    $application = parse_url(APP_URL);
    $origin = strtolower((string) $application['scheme']) . '://' . $application['host'];
    if (isset($application['port'])) {
        $origin .= ':' . $application['port'];
    }

    $basePath = rtrim((string) ($application['path'] ?? ''), '/');
    $path = (string) ($target['path'] ?? '');
    if (isset($target['scheme'], $target['host'])) {
        $origin = strtolower($target['scheme']) . '://' . $target['host'];
        if (isset($target['port'])) {
            $origin .= ':' . $target['port'];
        }
        $redirectPath = $path;
    } elseif ($path === '') {
        $redirectPath = $basePath . '/';
    } elseif ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
        $redirectPath = $path;
    } else {
        $redirectPath = $basePath . '/' . ltrim($path, '/');
    }

    $suffix = (isset($target['query']) ? '?' . $target['query'] : '')
        . (isset($target['fragment']) ? '#' . $target['fragment'] : '');
    header('Location: ' . $origin . $redirectPath . $suffix, true, $statusCode);
    exit;
}

function sanitize_text(string $value): string
{
    return trim(strip_tags($value));
}

function validate_email(string $value): bool
{
    return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
}

function validate_required(string $value): bool
{
    return trim($value) !== '';
}

function require_post(): void
{
    require_http_methods(['POST']);
}
