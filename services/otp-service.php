<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/email-delivery.php';

final class OtpGenerationException extends RuntimeException
{
}

function otp_settings(): array
{
    return app_config()['auth'];
}

function normalize_mobile_number(string $mobile): ?string
{
    $mobile = preg_replace('/[\s().-]+/', '', trim($mobile));
    if (!is_string($mobile) || preg_match('/^\+?[1-9][0-9]{7,14}$/D', $mobile) !== 1) {
        return null;
    }

    return $mobile;
}

function validate_institution_code(string $code): bool
{
    return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,49}$/D', $code) === 1;
}

function otp_hash_key(): string
{
    $key = (string) otp_settings()['otp_hash_key'];
    if (strlen($key) < 32) {
        throw new RuntimeException('OTP_HASH_KEY must contain at least 32 characters.');
    }

    return $key;
}

function otp_hash_key_is_configured(): bool
{
    return strlen((string) otp_settings()['otp_hash_key']) >= 32;
}

function hash_login_otp(string $otp): string
{
    return hash_hmac('sha256', $otp, otp_hash_key());
}

function login_otp_hash_matches(string $otp, string $expectedHash): bool
{
    return preg_match('/^[0-9]{6}$/D', $otp) === 1
        && preg_match('/^[a-f0-9]{64}$/D', $expectedHash) === 1
        && hash_equals($expectedHash, hash_login_otp($otp));
}

function login_otp_is_usable(string $status, int $attempts, int $maxAttempts, bool $unexpired): bool
{
    return $status === 'pending'
        && $unexpired
        && $maxAttempts > 0
        && $attempts < $maxAttempts;
}

function login_otp_resend_cooldown_active(int $secondsSinceSent, int $cooldownSeconds): bool
{
    return $secondsSinceSent >= 0 && $cooldownSeconds > 0 && $secondsSinceSent < $cooldownSeconds;
}

function next_login_otp_attempt(int $attempts, int $maxAttempts): array
{
    if ($attempts < 0 || $maxAttempts < 1 || $attempts >= $maxAttempts) {
        throw new InvalidArgumentException('OTP attempt counters are invalid.');
    }

    $nextAttempts = $attempts + 1;
    return [
        'attempts' => $nextAttempts,
        'status' => $nextAttempts >= $maxAttempts ? 'failed' : 'pending',
    ];
}

function create_login_otp(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function create_auth_request_id(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);

    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
        . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

function consume_auth_rate_limit(string $scope, int $limit, int $windowSeconds): bool
{
    if ($scope === '' || $limit < 1 || $windowSeconds < 1) {
        throw new InvalidArgumentException('Rate limit parameters are invalid.');
    }

    $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'feesportal-auth-rate-limit';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('The authentication rate limiter is unavailable.');
    }

    $path = $directory . DIRECTORY_SEPARATOR . hash('sha256', $scope) . '.json';
    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException('The authentication rate limiter is unavailable.');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('The authentication rate limiter is unavailable.');
        }

        $contents = stream_get_contents($handle);
        if ($contents === false) {
            throw new RuntimeException('The authentication rate limiter is unavailable.');
        }
        $state = $contents === '' ? null : json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        if ($state !== null && (!is_array($state)
            || !isset($state['started_at'], $state['count'])
            || !is_int($state['started_at'])
            || !is_int($state['count']))) {
            throw new RuntimeException('The authentication rate limiter state is invalid.');
        }

        $now = time();
        if ($state === null || $now - $state['started_at'] >= $windowSeconds) {
            $state = ['started_at' => $now, 'count' => 0];
        }

        if ($state['count'] >= $limit) {
            return false;
        }

        $state['count']++;
        $encoded = json_encode($state, JSON_THROW_ON_ERROR);
        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $encoded) !== strlen($encoded)) {
            throw new RuntimeException('The authentication rate limiter state could not be stored.');
        }
        fflush($handle);

        return true;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function auth_client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : 'unknown';
}

function auth_rate_limit_scope(string $kind, string $value): string
{
    return $kind . ':' . hash_hmac('sha256', $value, otp_hash_key());
}

function request_ip_rate_limit_allows(): bool
{
    $settings = otp_settings();
    return consume_auth_rate_limit(
        auth_rate_limit_scope('request-ip', auth_client_ip()),
        (int) $settings['otp_request_limit'],
        (int) $settings['otp_request_window_seconds']
    );
}

function verification_rate_limit_allows(): bool
{
    $settings = otp_settings();
    return consume_auth_rate_limit(
        auth_rate_limit_scope('verify-ip', auth_client_ip()),
        (int) $settings['otp_verify_limit'],
        (int) $settings['otp_verify_window_seconds']
    );
}

function user_login_otp_request_limit_allows(mysqli $db, int $userId): bool
{
    $windowSeconds = (int) otp_settings()['otp_request_window_seconds'];
    $statement = $db->prepare(
        'SELECT COUNT(*) AS request_count
         FROM user_otp_verifications
         WHERE user_id = ? AND purpose = \'login\'
           AND TIMESTAMPDIFF(SECOND, created_at, CURRENT_TIMESTAMP) < ?'
    );
    $statement->bind_param('ii', $userId, $windowSeconds);
    $statement->execute();
    $result = $statement->get_result();
    $row = $result->fetch_assoc();
    $statement->close();

    return is_array($row) && (int) $row['request_count'] < 5;
}

function otp_delivery_available(): bool
{
    return otp_email_delivery_available();
}

function log_otp_authentication_unavailable(string $reason): void
{
    $allowedReasons = ['hash_key_missing', 'delivery_unavailable', 'rate_limited'];
    if (!in_array($reason, $allowedReasons, true) || app_config()['env'] !== 'development') {
        return;
    }

    error_log('[FeesApp] ' . json_encode([
        'event' => 'authentication_otp_unavailable',
        'reason' => $reason,
    ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
}

function log_parent_otp_diagnostic(string $correlationId, string $outcome): void
{
    $allowedOutcomes = [
        'input_rejected',
        'account_ineligible',
        'rate_limited',
        'cooldown_active',
        'challenge_unavailable',
        'otp_generation_failed',
        'delivery_failed',
        'delivery_accepted',
        'challenge_created',
    ];
    if (app_config()['env'] !== 'development'
        || preg_match('/^[a-f0-9-]{36}$/D', $correlationId) !== 1
        || !in_array($outcome, $allowedOutcomes, true)) {
        return;
    }

    error_log('[FeesApp] ' . json_encode([
        'event' => 'parent_otp_diagnostic',
        'correlation_id' => $correlationId,
        'outcome' => $outcome,
    ] + mailpit_runtime_configuration_status(), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
}

function login_otp_email_content(
    string $otp,
    int $lifetimeSeconds,
    ?string $recipientName,
    string $appName
): array {
    if ($lifetimeSeconds < 1) {
        throw new InvalidArgumentException('The OTP lifetime must be a positive duration.');
    }

    $minutes = intdiv($lifetimeSeconds, 60);
    $seconds = $lifetimeSeconds % 60;
    $durationParts = [];
    if ($minutes > 0) {
        $durationParts[] = $minutes . ' ' . ($minutes === 1 ? 'minute' : 'minutes');
    }
    if ($seconds > 0) {
        $durationParts[] = $seconds . ' ' . ($seconds === 1 ? 'second' : 'seconds');
    }
    $duration = implode(' and ', $durationParts);
    $name = is_string($recipientName) ? trim($recipientName) : '';
    $greeting = $name !== '' ? 'Hello, ' . $name . ',' : 'Hello,';
    $safeAppName = htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeGreeting = htmlspecialchars($greeting, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeOtp = htmlspecialchars($otp, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeDuration = htmlspecialchars($duration, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>Verify your email address</title></head>'
        . '<body style="margin:0;padding:0;background-color:#f3f6fb;color:#172b4d;'
        . 'font-family:Arial,Helvetica,sans-serif;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
        . 'style="background-color:#f3f6fb;padding:32px 12px;"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
        . 'style="max-width:600px;background-color:#ffffff;border:1px solid #dfe6f0;'
        . 'border-radius:14px;overflow:hidden;">'
        . '<tr><td style="padding:24px 32px;background-color:#102a43;color:#ffffff;'
        . 'font-size:18px;font-weight:bold;letter-spacing:0.2px;">' . $safeAppName . '</td></tr>'
        . '<tr><td style="padding:36px 32px 32px;">'
        . '<p style="margin:0 0 20px;color:#334e68;font-size:15px;line-height:1.6;">' . $safeGreeting . '</p>'
        . '<h1 style="margin:0 0 14px;color:#102a43;font-size:26px;line-height:1.25;">'
        . 'Verify your email address</h1>'
        . '<p style="margin:0 0 24px;color:#486581;font-size:15px;line-height:1.7;">'
        . 'A sign-in verification code was requested for your account. Enter this code to continue:</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
        . '<tr><td align="center" style="padding:22px 12px;background-color:#f1f6fc;'
        . 'border:1px solid #d8e5f2;border-radius:10px;color:#125b9a;'
        . 'font-size:34px;font-weight:bold;letter-spacing:10px;line-height:1.3;">'
        . $safeOtp . '</td></tr></table>'
        . '<p style="margin:18px 0 24px;color:#486581;font-size:14px;line-height:1.6;">'
        . 'This code expires in <strong style="color:#243b53;">' . $safeDuration . '</strong>.</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
        . 'style="margin:0 0 20px;background-color:#f8fafc;border-left:3px solid #3b82c4;">'
        . '<tr><td style="padding:14px 16px;color:#334e68;font-size:14px;line-height:1.6;">'
        . '<strong style="color:#102a43;">Keep your code private.</strong> Never share it with anyone, '
        . 'including staff or support personnel.</td></tr></table>'
        . '<p style="margin:0;color:#627d98;font-size:14px;line-height:1.6;">'
        . 'If you did not request this code, you can safely ignore this email.</p>'
        . '</td></tr><tr><td style="padding:20px 32px;background-color:#f8fafc;border-top:1px solid #e6edf5;'
        . 'color:#627d98;font-size:12px;line-height:1.6;">'
        . '<strong style="color:#334e68;">' . $safeAppName . '</strong><br>'
        . 'This is an automated message. Please do not reply to this email.'
        . '</td></tr></table></td></tr></table></body></html>';

    $plainText = $greeting . "\n\n"
        . "Verify your email address\n\n"
        . "A sign-in verification code was requested for your account.\n\n"
        . "Your verification code: {$otp}\n"
        . "This code expires in {$duration}.\n\n"
        . "Never share this code with anyone, including staff or support personnel.\n"
        . "If you did not request this code, you can safely ignore this email.\n\n"
        . $appName . "\n"
        . "This is an automated message. Please do not reply to this email.";

    return ['html' => $html, 'text' => $plainText, 'duration' => $duration];
}

function deliver_login_otp(
    string $email,
    string $otp,
    ?callable $mockTransport = null,
    ?string $correlationId = null,
    ?string $recipientName = null
): bool
{
    $appName = (string) app_config()['app_name'];
    $content = login_otp_email_content(
        $otp,
        (int) otp_settings()['otp_lifetime_seconds'],
        $recipientName,
        $appName
    );
    $sent = send_otp_email(
        $email,
        'Your ' . $appName . ' verification code',
        $content['html'],
        $mockTransport,
        $correlationId,
        $content['text']
    );
    if ($sent && $correlationId !== null) {
        log_parent_otp_diagnostic($correlationId, 'delivery_accepted');
    }

    return $sent;
}

function deliver_otp_with_failure_cleanup(
    callable $deliver,
    callable $invalidate,
    ?string $correlationId = null
): void
{
    try {
        if ($deliver() !== true) {
            throw new OtpEmailDeliveryException('The email provider did not confirm delivery.');
        }
    } catch (Throwable $exception) {
        $invalidate();
        if ($correlationId !== null) {
            log_parent_otp_diagnostic($correlationId, 'delivery_failed');
        }
        if ($exception instanceof OtpEmailDeliveryException) {
            throw $exception;
        }
        throw new OtpEmailDeliveryException('The email provider failed to deliver the OTP.', 0, $exception);
    }
}

function unique_staff_login_account(array $accounts, string $institutionCode, string $email): ?array
{
    if (count($accounts) !== 1 || !is_array($accounts[0])) {
        return null;
    }

    $account = $accounts[0];
    $role = $account['user_type'] ?? null;
    if (!is_string($account['institution_code'] ?? null)
        || strcasecmp($account['institution_code'], $institutionCode) !== 0
        || ($account['email'] ?? null) !== $email
        || ($account['institution_status'] ?? null) !== 'active'
        || ($account['status'] ?? null) !== 'active'
        || ($account['email_verified_at'] ?? null) === null
        || !is_string($role)
        || !in_array($role, ['super_admin', 'admin', 'staff'], true)
        || ($account['role_name'] ?? null) !== $role) {
        return null;
    }

    return $account;
}

function active_staff_login_user(mysqli $db, string $institutionCode, string $email): ?array
{
    $statement = $db->prepare(
        'SELECT u.id, u.institution_id, u.role_id, u.user_type, u.full_name, u.email,
                u.email_verified_at, u.status, r.name AS role_name,
                i.code AS institution_code, i.status AS institution_status
         FROM institutions i
         JOIN users u ON u.institution_id = i.id
         JOIN roles r ON r.id = u.role_id AND r.name = u.user_type
         WHERE i.code = ? AND i.status = \'active\'
           AND u.email = ? AND u.email_verified_at IS NOT NULL
           AND u.status = \'active\'
           AND u.user_type IN (\'super_admin\', \'admin\', \'staff\')
         LIMIT 2'
    );
    $statement->bind_param('ss', $institutionCode, $email);
    $statement->execute();
    $result = $statement->get_result();
    $accounts = [];
    while ($row = $result->fetch_assoc()) {
        $accounts[] = $row;
    }
    $statement->close();

    return unique_staff_login_account($accounts, $institutionCode, $email);
}

function unique_parent_login_account(array $accounts): ?array
{
    return count($accounts) === 1 && is_array($accounts[0]) ? $accounts[0] : null;
}

function active_parent_login_account(mysqli $db, string $email): ?array
{
    $statement = $db->prepare(
        'SELECT u.id, u.institution_id, u.role_id, u.user_type, u.full_name, u.mobile, u.email
         FROM institutions i
         JOIN users u ON u.institution_id = i.id
         JOIN roles r ON r.id = u.role_id AND r.name = \'parent\'
         JOIN parents p ON p.institution_id = u.institution_id AND p.user_id = u.id
         WHERE i.status = \'active\'
           AND u.email = ? AND u.email_verified_at IS NOT NULL
           AND u.status = \'active\' AND u.user_type = \'parent\'
           AND p.status = \'active\'
         LIMIT 2'
    );
    $statement->bind_param('s', $email);
    $statement->execute();
    $result = $statement->get_result();
    $accounts = [];
    while ($row = $result->fetch_assoc()) {
        $accounts[] = $row;
    }
    $statement->close();

    return unique_parent_login_account($accounts);
}

function latest_login_otp(mysqli $db, int $userId): ?array
{
    $statement = $db->prepare(
        'SELECT request_id, status,
                TIMESTAMPDIFF(SECOND, sent_at, CURRENT_TIMESTAMP) AS seconds_since_sent,
                (expires_at > CURRENT_TIMESTAMP) AS is_unexpired
         FROM user_otp_verifications
         WHERE user_id = ? AND purpose = \'login\'
         ORDER BY created_at DESC, id DESC
         LIMIT 1
         FOR UPDATE'
    );
    $statement->bind_param('i', $userId);
    $statement->execute();
    $result = $statement->get_result();
    $row = $result->fetch_assoc();
    $statement->close();

    return is_array($row) ? $row : null;
}

function invalidate_pending_login_otps(mysqli $db, int $institutionId, int $userId): void
{
    $statement = $db->prepare(
        'UPDATE user_otp_verifications
         SET status = \'invalidated\'
         WHERE institution_id = ? AND user_id = ? AND purpose = \'login\' AND status = \'pending\''
    );
    $statement->bind_param('ii', $institutionId, $userId);
    $statement->execute();
    $statement->close();
}

function issue_login_otp(mysqli $db, array $user, ?string $correlationId = null): array
{
    $settings = otp_settings();
    $institutionId = (int) $user['institution_id'];
    $userId = (int) $user['id'];
    $requestId = create_auth_request_id();
    try {
        $otp = create_login_otp();
    } catch (Throwable $exception) {
        if ($correlationId !== null) {
            log_parent_otp_diagnostic($correlationId, 'otp_generation_failed');
        }
        throw new OtpGenerationException('The login code could not be generated.', 0, $exception);
    }

    $db->begin_transaction();
    try {
        $previous = latest_login_otp($db, $userId);
        if (!user_login_otp_request_limit_allows($db, $userId)) {
            $db->commit();
            if ($correlationId !== null) {
                log_parent_otp_diagnostic($correlationId, 'rate_limited');
            }
            return [
                'request_id' => $previous !== null
                    && $previous['status'] === 'pending'
                    && (int) $previous['is_unexpired'] === 1
                    ? (string) $previous['request_id']
                    : null,
                'cooldown' => true,
            ];
        }

        if ($previous !== null && $previous['status'] === 'pending'
            && login_otp_resend_cooldown_active(
                (int) $previous['seconds_since_sent'],
                (int) $settings['otp_resend_cooldown_seconds']
            )) {
            $db->commit();
            if ($correlationId !== null) {
                log_parent_otp_diagnostic($correlationId, 'cooldown_active');
            }
            return [
                'request_id' => $previous['status'] === 'pending' && (int) $previous['is_unexpired'] === 1
                    ? (string) $previous['request_id']
                    : null,
                'cooldown' => true,
            ];
        }

        invalidate_pending_login_otps($db, $institutionId, $userId);
        $otpHash = hash_login_otp($otp);
        $lifetime = (int) $settings['otp_lifetime_seconds'];
        $maxAttempts = (int) $settings['otp_max_attempts'];
        $statement = $db->prepare(
            'INSERT INTO user_otp_verifications
                (institution_id, user_id, purpose, request_id, otp_hash, expires_at, attempts_count, max_attempts, status)
             VALUES (?, ?, \'login\', ?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ? SECOND), 0, ?, \'failed\')'
        );
        $statement->bind_param('iissii', $institutionId, $userId, $requestId, $otpHash, $lifetime, $maxAttempts);
        $statement->execute();
        $statement->close();
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }

    deliver_otp_with_failure_cleanup(
        static fn(): bool => deliver_login_otp(
            (string) $user['email'],
            $otp,
            null,
            $correlationId,
            is_string($user['full_name'] ?? null) ? $user['full_name'] : null
        ),
        static function () use ($db, $institutionId, $userId, $requestId): void {
            $statement = $db->prepare(
                'UPDATE user_otp_verifications
                 SET status = \'invalidated\'
                 WHERE institution_id = ? AND user_id = ? AND purpose = \'login\'
                   AND request_id = ? AND status = \'failed\''
            );
            $statement->bind_param('iis', $institutionId, $userId, $requestId);
            $statement->execute();
            $statement->close();
        },
        $correlationId
    );

    $statement = $db->prepare(
        'UPDATE user_otp_verifications
         SET status = \'pending\', sent_at = CURRENT_TIMESTAMP
         WHERE institution_id = ? AND user_id = ? AND purpose = \'login\'
           AND request_id = ? AND status = \'failed\''
    );
    $statement->bind_param('iis', $institutionId, $userId, $requestId);
    $statement->execute();
    $activated = $statement->affected_rows === 1;
    $statement->close();
    if (!$activated) {
        if ($correlationId !== null) {
            log_parent_otp_diagnostic($correlationId, 'challenge_unavailable');
        }
        throw new RuntimeException('The delivered OTP could not be activated.');
    }

    if ($correlationId !== null) {
        log_parent_otp_diagnostic($correlationId, 'challenge_created');
    }

    return [
        'request_id' => $requestId,
        'cooldown' => false,
    ];
}

function begin_staff_email_challenge(string $institutionCode, string $email): array
{
    if (!validate_institution_code($institutionCode) || !validate_email($email)) {
        return ['available' => false, 'request_id' => null, 'reason' => 'invalid_input'];
    }
    if (!otp_hash_key_is_configured()) {
        return ['available' => false, 'request_id' => null, 'reason' => 'hash_key_missing'];
    }

    if (!otp_delivery_available()) {
        return ['available' => false, 'request_id' => null, 'reason' => 'delivery_unavailable'];
    }

    if (!request_ip_rate_limit_allows()) {
        return ['available' => false, 'request_id' => null, 'reason' => 'rate_limited'];
    }

    $db = db_connect();
    $user = active_staff_login_user($db, $institutionCode, $email);
    if ($user === null) {
        return [
            'available' => true,
            'request_id' => create_auth_request_id(),
            'user' => null,
        ];
    }

    $issued = issue_login_otp($db, $user);
    if ($issued['request_id'] === null) {
        return [
            'available' => false,
            'request_id' => null,
            'reason' => ($issued['cooldown'] ?? false) ? 'rate_limited' : 'challenge_unavailable',
        ];
    }

    return [
        'available' => true,
        'request_id' => $issued['request_id'],
        'user' => $user,
        'sent' => !($issued['cooldown'] ?? false),
        'cooldown' => (bool) ($issued['cooldown'] ?? false),
    ];
}

function begin_parent_email_challenge(string $email, ?string $correlationId = null): array
{
    if ($correlationId !== null && preg_match('/^[a-f0-9-]{36}$/D', $correlationId) !== 1) {
        throw new InvalidArgumentException('The OTP diagnostic correlation ID is invalid.');
    }
    if (!otp_hash_key_is_configured()) {
        if ($correlationId !== null) {
            log_parent_otp_diagnostic($correlationId, 'challenge_unavailable');
        }
        return ['available' => false, 'request_id' => null, 'reason' => 'hash_key_missing'];
    }
    if (!otp_delivery_available()) {
        if ($correlationId !== null) {
            log_parent_otp_diagnostic($correlationId, 'challenge_unavailable');
        }
        return ['available' => false, 'request_id' => null, 'reason' => 'delivery_unavailable'];
    }
    if (!request_ip_rate_limit_allows()) {
        if ($correlationId !== null) {
            log_parent_otp_diagnostic($correlationId, 'rate_limited');
        }
        return ['available' => false, 'request_id' => null, 'reason' => 'rate_limited'];
    }

    $db = db_connect();
    $user = active_parent_login_account($db, $email);
    if ($user === null) {
        if ($correlationId !== null) {
            log_parent_otp_diagnostic($correlationId, 'account_ineligible');
        }
        return [
            'available' => true,
            'request_id' => create_auth_request_id(),
            'user' => null,
        ];
    }

    $issued = issue_login_otp($db, $user, $correlationId);
    if ($correlationId !== null && $issued['request_id'] === null) {
        log_parent_otp_diagnostic($correlationId, 'challenge_unavailable');
    }
    return [
        'available' => $issued['request_id'] !== null,
        'request_id' => $issued['request_id'],
        'user' => $user,
    ];
}

function resend_login_challenge(array $pending): array
{
    $settings = otp_settings();
    $mobile = (string) ($pending['mobile'] ?? '');
    $institutionCode = (string) ($pending['institution_code'] ?? '');
    if (!otp_hash_key_is_configured()) {
        return ['available' => false, 'reason' => 'hash_key_missing'];
    }
    if (!otp_delivery_available()) {
        return ['available' => false, 'reason' => 'delivery_unavailable'];
    }
    if (!request_ip_rate_limit_allows()) {
        return ['available' => false, 'reason' => 'rate_limited'];
    }

    if (($pending['login_type'] ?? null) === 'parent_email') {
        $userId = $pending['user_id'] ?? null;
        $institutionId = $pending['institution_id'] ?? null;
        $email = $pending['email'] ?? null;
        if (!is_int($userId) || !is_int($institutionId) || !is_string($email)) {
            $lastSentAt = (int) ($pending['created_at'] ?? 0);
            if ($lastSentAt > 0
                && login_otp_resend_cooldown_active(
                    time() - $lastSentAt,
                    (int) $settings['otp_resend_cooldown_seconds']
                )) {
                return ['available' => true];
            }
            $_SESSION['pending_auth']['created_at'] = time();
            return ['available' => true];
        }

        $db = db_connect();
        $statement = $db->prepare(
            'SELECT u.id, u.institution_id, u.role_id, u.user_type, u.full_name, u.mobile, u.email
             FROM institutions i
             JOIN users u ON u.institution_id = i.id
             JOIN roles r ON r.id = u.role_id AND r.name = \'parent\'
             JOIN parents p ON p.institution_id = u.institution_id AND p.user_id = u.id
             WHERE i.id = ? AND i.status = \'active\'
               AND u.id = ? AND u.institution_id = ? AND u.email = ?
               AND u.email_verified_at IS NOT NULL
               AND u.status = \'active\' AND u.user_type = \'parent\'
               AND p.status = \'active\''
        );
        $statement->bind_param('iiis', $institutionId, $userId, $institutionId, $email);
        $statement->execute();
        $result = $statement->get_result();
        $user = $result->fetch_assoc();
        $statement->close();
        if (!is_array($user)) {
            return ['available' => true];
        }

        $issued = issue_login_otp($db, $user);
        if ($issued['request_id'] !== null && !($issued['cooldown'] ?? false)) {
            $_SESSION['pending_auth']['request_id'] = $issued['request_id'];
            $_SESSION['pending_auth']['created_at'] = time();
        }

        return [
            'available' => true,
            'sent' => $issued['request_id'] !== null && !($issued['cooldown'] ?? false),
            'cooldown' => (bool) ($issued['cooldown'] ?? false),
        ];
    }

    $userId = $pending['user_id'] ?? null;
    $institutionId = $pending['institution_id'] ?? null;
    if (($pending['login_type'] ?? null) === 'staff_email') {
        $email = $pending['email'] ?? null;
        if (!is_int($userId) || !is_int($institutionId) || !is_string($email)) {
            return ['available' => false, 'reason' => 'invalid_challenge'];
        }

        $db = db_connect();
        $statement = $db->prepare(
            'SELECT u.id, u.institution_id, u.role_id, u.user_type, u.full_name, u.email
             FROM institutions i
             JOIN users u ON u.institution_id = i.id
             JOIN roles r ON r.id = u.role_id AND r.name = u.user_type
             WHERE i.id = ? AND i.code = ? AND i.status = \'active\'
               AND u.id = ? AND u.institution_id = ? AND u.email = ?
               AND u.email_verified_at IS NOT NULL AND u.status = \'active\'
               AND u.user_type IN (\'super_admin\', \'admin\', \'staff\')
             LIMIT 2'
        );
        $statement->bind_param('isiis', $institutionId, $institutionCode, $userId, $institutionId, $email);
        $statement->execute();
        $result = $statement->get_result();
        $accounts = [];
        while ($row = $result->fetch_assoc()) {
            $accounts[] = $row;
        }
        $statement->close();
        $user = count($accounts) === 1 && is_array($accounts[0]) ? $accounts[0] : null;
        if ($user === null) {
            return ['available' => true];
        }

        $issued = issue_login_otp($db, $user);
        if ($issued['request_id'] !== null && !($issued['cooldown'] ?? false)) {
            $_SESSION['pending_auth']['request_id'] = $issued['request_id'];
            $_SESSION['pending_auth']['created_at'] = time();
        }

        return [
            'available' => true,
            'sent' => $issued['request_id'] !== null && !($issued['cooldown'] ?? false),
            'cooldown' => (bool) ($issued['cooldown'] ?? false),
        ];
    }

    if (($pending['login_type'] ?? null) !== 'mobile') {
        return ['available' => false, 'reason' => 'invalid_challenge'];
    }

    if (!is_int($userId) || !is_int($institutionId)) {
        $lastSentAt = (int) ($pending['created_at'] ?? 0);
        if ($lastSentAt > 0
            && login_otp_resend_cooldown_active(
                time() - $lastSentAt,
                (int) $settings['otp_resend_cooldown_seconds']
            )) {
            return ['available' => true];
        }

        $_SESSION['pending_auth']['created_at'] = time();
        return ['available' => true];
    }

    $db = db_connect();
    $statement = $db->prepare(
        'SELECT u.id, u.institution_id, u.full_name, u.mobile, u.email
         FROM institutions i
         JOIN users u ON u.institution_id = i.id
         WHERE i.id = ? AND i.code = ? AND i.status = \'active\'
           AND u.id = ? AND u.mobile = ? AND u.status = \'active\'
           AND u.mobile_verified_at IS NOT NULL
           AND u.email IS NOT NULL AND TRIM(u.email) <> \'\' AND u.email_verified_at IS NOT NULL
           AND (u.user_type <> \'parent\' OR EXISTS (
               SELECT 1 FROM parents p
               WHERE p.institution_id = u.institution_id
                 AND p.user_id = u.id AND p.status = \'active\'
           ))'
    );
    $statement->bind_param('isis', $institutionId, $institutionCode, $userId, $mobile);
    $statement->execute();
    $result = $statement->get_result();
    $user = $result->fetch_assoc();
    $statement->close();
    if (!is_array($user)) {
        return ['available' => true];
    }

    $issued = issue_login_otp($db, $user);
    if ($issued['request_id'] !== null && !($issued['cooldown'] ?? false)) {
        $_SESSION['pending_auth']['request_id'] = $issued['request_id'];
        $_SESSION['pending_auth']['created_at'] = time();
    }

    return [
        'available' => true,
        'sent' => $issued['request_id'] !== null && !($issued['cooldown'] ?? false),
        'cooldown' => (bool) ($issued['cooldown'] ?? false),
    ];
}

function verify_login_challenge(array $pending, string $otp): string
{
    if (!isset($pending['user_id'], $pending['institution_id'], $pending['request_id'])
        || !is_int($pending['user_id'])
        || !is_int($pending['institution_id'])
        || !is_string($pending['request_id'])) {
        return 'invalid';
    }

    $settings = otp_settings();
    $db = db_connect();
    $db->begin_transaction();
    $sessionUser = null;

    try {
        $userId = $pending['user_id'];
        $institutionId = $pending['institution_id'];
        $requestId = $pending['request_id'];
        $statement = $db->prepare(
            'SELECT id, otp_hash, attempts_count, max_attempts, status,
                    (expires_at > CURRENT_TIMESTAMP) AS is_unexpired
             FROM user_otp_verifications
             WHERE institution_id = ? AND user_id = ? AND purpose = \'login\' AND request_id = ?
             LIMIT 1
             FOR UPDATE'
        );
        $statement->bind_param('iis', $institutionId, $userId, $requestId);
        $statement->execute();
        $result = $statement->get_result();
        $challenge = $result->fetch_assoc();
        $statement->close();
        if (!is_array($challenge) || $challenge['status'] !== 'pending') {
            $db->rollback();
            return 'invalid';
        }

        $unexpired = (int) $challenge['is_unexpired'] === 1;
        $attempts = (int) $challenge['attempts_count'];
        $maxAttempts = (int) $challenge['max_attempts'];
        if (!login_otp_is_usable('pending', $attempts, $maxAttempts, $unexpired)) {
            $status = $unexpired ? 'failed' : 'expired';
            $statement = $db->prepare('UPDATE user_otp_verifications SET status = ? WHERE id = ?');
            $challengeId = (int) $challenge['id'];
            $statement->bind_param('si', $status, $challengeId);
            $statement->execute();
            $statement->close();
            $db->commit();
            return 'expired';
        }

        if (!login_otp_hash_matches($otp, (string) $challenge['otp_hash'])) {
            $attemptResult = next_login_otp_attempt($attempts, $maxAttempts);
            $nextAttempts = $attemptResult['attempts'];
            $status = $attemptResult['status'];
            $challengeId = (int) $challenge['id'];
            $statement = $db->prepare(
                'UPDATE user_otp_verifications SET attempts_count = ?, status = ? WHERE id = ?'
            );
            $statement->bind_param('isi', $nextAttempts, $status, $challengeId);
            $statement->execute();
            $statement->close();
            $db->commit();
            return 'invalid';
        }

        $statement = $db->prepare(
            'SELECT u.id, u.institution_id, u.role_id, u.user_type, u.status,
                    r.name AS role_name, i.status AS institution_status
             FROM users u
             JOIN roles r ON r.id = u.role_id
             JOIN institutions i ON i.id = u.institution_id
             LEFT JOIN parents p ON p.institution_id = u.institution_id AND p.user_id = u.id
             WHERE u.id = ? AND u.institution_id = ?
               AND (u.user_type <> \'parent\' OR (
                   p.status = \'active\' AND r.name = \'parent\'
                   AND u.email IS NOT NULL AND TRIM(u.email) <> \'\'
                   AND u.email_verified_at IS NOT NULL
               ))
               AND (? <> \'parent_email\' OR (
                   u.user_type = \'parent\' AND u.email = ?
                   AND u.email_verified_at IS NOT NULL
               ))
               AND (? <> \'staff_email\' OR (
                   u.user_type IN (\'super_admin\', \'admin\', \'staff\')
                   AND u.email = ? AND u.email_verified_at IS NOT NULL
               ))
             LIMIT 1
             FOR UPDATE'
        );
        $loginType = (string) ($pending['login_type'] ?? '');
        $pendingEmail = (string) ($pending['email'] ?? '');
        $statement->bind_param('iissss', $userId, $institutionId, $loginType, $pendingEmail, $loginType, $pendingEmail);
        $statement->execute();
        $result = $statement->get_result();
        $user = $result->fetch_assoc();
        $statement->close();
        if (!is_array($user)
            || $user['status'] !== 'active'
            || $user['institution_status'] !== 'active'
            || $user['role_name'] !== $user['user_type']
            || !in_array($user['user_type'], ['super_admin', 'admin', 'staff', 'parent'], true)) {
            $challengeId = (int) $challenge['id'];
            $statement = $db->prepare(
                'UPDATE user_otp_verifications SET status = \'failed\' WHERE id = ? AND status = \'pending\''
            );
            $statement->bind_param('i', $challengeId);
            $statement->execute();
            $statement->close();
            $db->commit();
            return 'invalid';
        }

        $roleId = (int) $user['role_id'];
        $statement = $db->prepare(
            'SELECT p.permission_key
             FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.role_id = ?
             ORDER BY p.permission_key'
        );
        $statement->bind_param('i', $roleId);
        $statement->execute();
        $result = $statement->get_result();
        $permissions = [];
        while ($row = $result->fetch_assoc()) {
            $permissions[] = (string) $row['permission_key'];
        }
        $statement->close();

        regenerate_session_id();
        $phpSessionId = session_id();
        $sessionIdentifier = bin2hex(random_bytes(16));
        $sessionTokenHash = hash('sha256', $phpSessionId);
        $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);
        $ipAddress = auth_client_ip();
        if ($ipAddress === 'unknown') {
            $ipAddress = '';
        }
        $absoluteTimeout = (int) $settings['session_absolute_timeout_seconds'];
        $statement = $db->prepare(
            'INSERT INTO user_sessions
                (user_id, institution_id, session_identifier, session_token_hash,
                 user_agent, ip_address, expires_at, last_activity_at)
             VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ? SECOND), CURRENT_TIMESTAMP)'
        );
        $statement->bind_param(
            'iissssi',
            $userId,
            $institutionId,
            $sessionIdentifier,
            $sessionTokenHash,
            $userAgent,
            $ipAddress,
            $absoluteTimeout
        );
        $statement->execute();
        $statement->close();

        $challengeId = (int) $challenge['id'];
        $statement = $db->prepare(
            'UPDATE user_otp_verifications
             SET status = \'verified\', verified_at = CURRENT_TIMESTAMP
             WHERE id = ? AND status = \'pending\''
        );
        $statement->bind_param('i', $challengeId);
        $statement->execute();
        if ($statement->affected_rows !== 1) {
            $statement->close();
            $db->rollback();
            return 'invalid';
        }
        $statement->close();

        $statement = $db->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?');
        $statement->bind_param('i', $userId);
        $statement->execute();
        $statement->close();
        $db->commit();

        $sessionUser = [
            'id' => $userId,
            'institution_id' => $institutionId,
            'role_id' => $roleId,
            'user_type' => (string) $user['user_type'],
            'status' => 'active',
            'permissions' => $permissions,
        ];
        $_SESSION['auth_session'] = [
            'identifier' => $sessionIdentifier,
            'token_hash' => $sessionTokenHash,
        ];
        $_SESSION['auth_issued_at'] = time();
        $_SESSION['user'] = $sessionUser;
        unset($_SESSION['pending_auth']);

        return 'authenticated';
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function store_pending_authentication(
    ?array $user,
    ?string $requestId,
    string $email,
    string $institutionCode,
    bool $challengeReady = false
): void
{
    $_SESSION['pending_auth'] = [
        'login_type' => 'staff_email',
        'user_id' => $user === null ? null : (int) $user['id'],
        'institution_id' => $user === null ? null : (int) $user['institution_id'],
        'request_id' => $requestId,
        'email' => is_string($user['email'] ?? null) ? $user['email'] : null,
        'masked_email' => is_string($user['email'] ?? null) ? mask_email_address($user['email']) : null,
        'institution_code' => $institutionCode,
        'challenge_ready' => $challengeReady,
        'created_at' => time(),
    ];
}

function store_pending_parent_authentication(
    ?array $user,
    ?string $requestId,
    bool $challengeReady = false
): void
{
    $_SESSION['pending_auth'] = [
        'login_type' => 'parent_email',
        'user_id' => $user === null ? null : (int) $user['id'],
        'institution_id' => $user === null ? null : (int) $user['institution_id'],
        'request_id' => $requestId,
        'email' => is_string($user['email'] ?? null) ? $user['email'] : null,
        'masked_email' => is_string($user['email'] ?? null) ? mask_email_address($user['email']) : null,
        'challenge_ready' => $challengeReady,
        'created_at' => time(),
    ];
}
