<?php
declare(strict_types=1);

require_once __DIR__ . '/otp-service.php';
require_once __DIR__ . '/audit-service.php';

function parent_email_verification_content(
    string $otp,
    int $lifetimeSeconds,
    string $requestId,
    ?string $recipientName = null
): array {
    if (preg_match('/^[0-9]{6}$/D', $otp) !== 1
        || preg_match('/^[a-f0-9-]{36}$/D', $requestId) !== 1
        || $lifetimeSeconds < 1) {
        throw new InvalidArgumentException('The email verification message data is invalid.');
    }
    $appName = (string) app_config()['app_name'];
    $verificationUrl = route_url('/verify-parent-email') . '?request_id=' . rawurlencode($requestId);
    $name = is_string($recipientName) ? trim($recipientName) : '';
    $greeting = $name === '' ? 'Hello,' : 'Hello, ' . $name . ',';
    $minutes = max(1, (int) ceil($lifetimeSeconds / 60));
    $safeAppName = htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeGreeting = htmlspecialchars($greeting, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeCode = htmlspecialchars($otp, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeUrl = htmlspecialchars($verificationUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $duration = $minutes . ' ' . ($minutes === 1 ? 'minute' : 'minutes');
    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>Verify your parent account</title></head>'
        . '<body style="margin:0;padding:24px;background:#f3f6fb;color:#172b4d;font-family:Arial,Helvetica,sans-serif;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
        . 'style="max-width:580px;background:#fff;border:1px solid #dfe6f0;border-radius:12px;">'
        . '<tr><td style="padding:22px 28px;background:#102a43;color:#fff;font-size:18px;font-weight:bold;">'
        . $safeAppName . '</td></tr><tr><td style="padding:28px;">'
        . '<p>' . $safeGreeting . '</p><h1 style="font-size:23px;color:#102a43;">Verify your email address</h1>'
        . '<p>Use this code to verify the email address on your parent account:</p>'
        . '<p style="padding:18px;text-align:center;background:#f1f6fc;color:#125b9a;font-size:30px;font-weight:bold;letter-spacing:8px;">'
        . $safeCode . '</p><p>This code expires in ' . htmlspecialchars($duration, ENT_QUOTES, 'UTF-8') . '.</p>'
        . '<p>You can also open this verification page and enter the code:</p>'
        . '<p><a href="' . $safeUrl . '">' . $safeUrl . '</a></p>'
        . '<p>If you did not expect this email, you can ignore it. Never share your code.</p>'
        . '</td></tr></table></td></tr></table></body></html>';
    $text = $greeting . "\n\nVerify your email address\n\n"
        . "Your verification code: {$otp}\n"
        . "This code expires in {$duration}.\n\n"
        . "Open this page to enter the code: {$verificationUrl}\n\n"
        . "If you did not expect this email, you can ignore it. Never share your code.\n\n{$appName}";

    return ['html' => $html, 'text' => $text, 'url' => $verificationUrl];
}

function deliver_parent_email_verification(
    string $email,
    string $otp,
    string $requestId,
    ?string $recipientName = null,
    ?callable $mockTransport = null
): bool {
    $content = parent_email_verification_content(
        $otp,
        (int) otp_settings()['otp_lifetime_seconds'],
        $requestId,
        $recipientName
    );
    $appName = (string) app_config()['app_name'];

    return send_otp_email(
        $email,
        'Verify your ' . $appName . ' parent account',
        $content['html'],
        $mockTransport,
        null,
        $content['text']
    );
}

function parent_email_verification_decision(
    string $otp,
    string $expectedHash,
    string $status,
    int $attempts,
    int $maxAttempts,
    bool $unexpired
): string {
    if ($status !== 'pending') {
        return 'invalid';
    }
    if (!$unexpired) {
        return 'expired';
    }
    if ($maxAttempts < 1 || $attempts >= $maxAttempts) {
        return 'failed';
    }
    return login_otp_hash_matches($otp, $expectedHash) ? 'verified' : 'invalid';
}

function parent_email_verification_next_statuses(string $parentStatus, string $userStatus): array
{
    return [
        'parent_status' => $parentStatus === 'pending_verification' ? 'active' : $parentStatus,
        'user_status' => $userStatus === 'pending' ? 'active' : $userStatus,
    ];
}

function parent_email_verification_issue(
    mysqli $db,
    int $institutionId,
    int $userId,
    string $email,
    string $fullName,
    ?callable $mockTransport = null
): array {
    if (!otp_hash_key_is_configured()) {
        throw new OtpEmailDeliveryException('Email verification is temporarily unavailable.');
    }
    if ($mockTransport === null && !otp_delivery_available()) {
        throw new OtpEmailDeliveryException('Email verification is temporarily unavailable.');
    }
    if (!validate_email($email) || $institutionId < 1 || $userId < 1) {
        throw new InvalidArgumentException('The parent email verification request is invalid.');
    }

    $requestId = create_auth_request_id();
    $otp = create_login_otp();
    $otpHash = hash_login_otp($otp);
    $lifetime = (int) otp_settings()['otp_lifetime_seconds'];
    $maxAttempts = (int) otp_settings()['otp_max_attempts'];
    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            "SELECT u.email, u.email_verified_at, u.status AS user_status,
                    p.status AS parent_status, r.name AS role_name
             FROM users u
             JOIN roles r ON r.id = u.role_id AND r.name = 'parent'
             JOIN parents p ON p.institution_id = u.institution_id AND p.user_id = u.id
             JOIN institutions i ON i.id = u.institution_id
             WHERE u.institution_id = ? AND u.id = ? AND u.user_type = 'parent'
               AND i.status = 'active'
             FOR UPDATE"
        );
        $statement->bind_param('ii', $institutionId, $userId);
        $statement->execute();
        $account = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($account)
            || $account['email_verified_at'] !== null
            || !is_string($account['email'])
            || strcasecmp($account['email'], $email) !== 0
            || !in_array($account['parent_status'], ['pending_verification', 'inactive', 'active'], true)
            || !in_array($account['user_status'], ['pending', 'inactive', 'active'], true)) {
            $db->rollback();
            return ['sent' => false, 'reason' => 'ineligible'];
        }

        $statement = $db->prepare(
            "SELECT request_id,
                    TIMESTAMPDIFF(SECOND, sent_at, CURRENT_TIMESTAMP) AS seconds_since_sent
             FROM user_otp_verifications
             WHERE institution_id = ? AND user_id = ?
               AND purpose = 'email_verification'
             ORDER BY created_at DESC, id DESC LIMIT 1 FOR UPDATE"
        );
        $statement->bind_param('ii', $institutionId, $userId);
        $statement->execute();
        $previous = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (is_array($previous)
            && login_otp_resend_cooldown_active(
                (int) $previous['seconds_since_sent'],
                (int) otp_settings()['otp_resend_cooldown_seconds']
            )) {
            $db->commit();
            return ['sent' => false, 'reason' => 'cooldown'];
        }
        $statement = $db->prepare(
            "SELECT COUNT(*) AS request_count FROM user_otp_verifications
             WHERE institution_id = ? AND user_id = ? AND purpose = 'email_verification'
               AND created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE)"
        );
        $statement->bind_param('ii', $institutionId, $userId);
        $statement->execute();
        $requestCount = (int) $statement->get_result()->fetch_assoc()['request_count'];
        $statement->close();
        if ($requestCount >= 5) {
            $db->commit();
            return ['sent' => false, 'reason' => 'rate_limited'];
        }

        $statement = $db->prepare(
            "UPDATE user_otp_verifications SET status = 'invalidated'
             WHERE institution_id = ? AND user_id = ?
               AND purpose = 'email_verification' AND status = 'pending'"
        );
        $statement->bind_param('ii', $institutionId, $userId);
        $statement->execute();
        $statement->close();
        $statement = $db->prepare(
            "INSERT INTO user_otp_verifications
                (institution_id, user_id, purpose, request_id, otp_hash, expires_at,
                 attempts_count, max_attempts, status)
             VALUES (?, ?, 'email_verification', ?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ? SECOND),
                     0, ?, 'failed')"
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
        static fn (): bool => deliver_parent_email_verification(
            $email,
            $otp,
            $requestId,
            $fullName,
            $mockTransport
        ),
        static function () use ($db, $institutionId, $userId, $requestId): void {
            $statement = $db->prepare(
                "UPDATE user_otp_verifications SET status = 'invalidated'
                 WHERE institution_id = ? AND user_id = ? AND purpose = 'email_verification'
                   AND request_id = ? AND status = 'failed'"
            );
            $statement->bind_param('iis', $institutionId, $userId, $requestId);
            $statement->execute();
            $statement->close();
        }
    );

    $statement = $db->prepare(
        "UPDATE user_otp_verifications SET status = 'pending', sent_at = CURRENT_TIMESTAMP
         WHERE institution_id = ? AND user_id = ? AND purpose = 'email_verification'
           AND request_id = ? AND status = 'failed'"
    );
    $statement->bind_param('iis', $institutionId, $userId, $requestId);
    $statement->execute();
    $activated = $statement->affected_rows === 1;
    $statement->close();
    if (!$activated) {
        throw new RuntimeException('The email verification challenge could not be activated.');
    }

    return ['sent' => true, 'request_id' => $requestId, 'reason' => 'sent'];
}

function parent_email_verification_verify(
    mysqli $db,
    string $requestId,
    string $otp,
    int $actorUserId = 0,
    string $actorType = 'parent'
): string {
    if (preg_match('/^[a-f0-9-]{36}$/D', $requestId) !== 1) {
        return 'invalid';
    }
    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            "SELECT o.id AS challenge_id, o.institution_id, o.user_id, o.otp_hash,
                    o.attempts_count, o.max_attempts, o.status,
                    (o.expires_at > CURRENT_TIMESTAMP) AS is_unexpired,
                    u.email, u.email_verified_at, u.status AS user_status,
                    p.id AS parent_id, p.status AS parent_status,
                    r.name AS role_name
             FROM user_otp_verifications o
             JOIN users u ON u.institution_id = o.institution_id AND u.id = o.user_id
             JOIN roles r ON r.id = u.role_id AND r.name = 'parent'
             JOIN parents p ON p.institution_id = u.institution_id AND p.user_id = u.id
             JOIN institutions i ON i.id = o.institution_id
             WHERE o.request_id = ? AND o.purpose = 'email_verification'
               AND u.user_type = 'parent' AND i.status = 'active'
             LIMIT 1 FOR UPDATE"
        );
        $statement->bind_param('s', $requestId);
        $statement->execute();
        $challenge = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($challenge) || $challenge['email_verified_at'] !== null) {
            $db->rollback();
            return 'invalid';
        }
        $decision = parent_email_verification_decision(
            $otp,
            (string) $challenge['otp_hash'],
            (string) $challenge['status'],
            (int) $challenge['attempts_count'],
            (int) $challenge['max_attempts'],
            (int) $challenge['is_unexpired'] === 1
        );
        if (in_array($decision, ['expired', 'failed'], true)) {
            $challengeId = (int) $challenge['challenge_id'];
            $statement = $db->prepare(
                "UPDATE user_otp_verifications SET status = ?
                 WHERE id = ? AND status = 'pending'"
            );
            $statement->bind_param('si', $decision, $challengeId);
            $statement->execute();
            $statement->close();
            $db->commit();
            return $decision;
        }
        if ($decision === 'invalid') {
            $attemptResult = next_login_otp_attempt(
                (int) $challenge['attempts_count'],
                (int) $challenge['max_attempts']
            );
            $challengeId = (int) $challenge['challenge_id'];
            $statement = $db->prepare(
                'UPDATE user_otp_verifications SET attempts_count = ?, status = ?
                 WHERE id = ? AND status = \'pending\''
            );
            $statement->bind_param('isi', $attemptResult['attempts'], $attemptResult['status'], $challengeId);
            $statement->execute();
            $statement->close();
            $db->commit();
            return 'invalid';
        }

        $institutionId = (int) $challenge['institution_id'];
        $userId = (int) $challenge['user_id'];
        $parentId = (int) $challenge['parent_id'];
        $oldParentStatus = (string) $challenge['parent_status'];
        $oldUserStatus = (string) $challenge['user_status'];
        $newStatuses = parent_email_verification_next_statuses($oldParentStatus, $oldUserStatus);
        $newParentStatus = $newStatuses['parent_status'];
        $newUserStatus = $newStatuses['user_status'];
        $challengeId = (int) $challenge['challenge_id'];
        $statement = $db->prepare(
            "UPDATE user_otp_verifications SET status = 'verified', verified_at = CURRENT_TIMESTAMP
             WHERE id = ? AND status = 'pending'"
        );
        $statement->bind_param('i', $challengeId);
        $statement->execute();
        if ($statement->affected_rows !== 1) {
            $statement->close();
            $db->rollback();
            return 'invalid';
        }
        $statement->close();
        $statement = $db->prepare(
            'UPDATE users SET email_verified_at = CURRENT_TIMESTAMP, status = ?
             WHERE id = ? AND institution_id = ? AND user_type = \'parent\'
               AND email_verified_at IS NULL'
        );
        $statement->bind_param('sii', $newUserStatus, $userId, $institutionId);
        $statement->execute();
        if ($statement->affected_rows !== 1) {
            $statement->close();
            $db->rollback();
            return 'invalid';
        }
        $statement->close();
        $statement = $db->prepare(
            'UPDATE parents SET status = ? WHERE id = ? AND institution_id = ?'
        );
        $statement->bind_param('sii', $newParentStatus, $parentId, $institutionId);
        $statement->execute();
        $statement->close();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId > 0 ? $actorUserId : null,
            $actorType,
            'parent.email_verified',
            'parent',
            (string) $parentId,
            ['email_verified' => false, 'status' => $oldParentStatus, 'account_status' => $oldUserStatus],
            ['email_verified' => true, 'status' => $newParentStatus, 'account_status' => $newUserStatus]
        );
        $db->commit();

        return 'verified';
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function parent_email_verification_request_resend(
    mysqli $db,
    string $institutionCode,
    string $email
): void {
    $institutionCode = trim($institutionCode);
    $email = strtolower(trim($email));
    if (!validate_institution_code($institutionCode)
        || !validate_email($email)
        || !otp_hash_key_is_configured()
        || !otp_delivery_available()) {
        return;
    }
    $accountScope = auth_rate_limit_scope(
        'parent-email-verification',
        strtolower($institutionCode) . ':' . $email
    );
    if (!consume_auth_rate_limit($accountScope, 5, 900) || !request_ip_rate_limit_allows()) {
        return;
    }

    $statement = $db->prepare(
        "SELECT u.id, u.institution_id, u.full_name, u.email
         FROM institutions i
         JOIN users u ON u.institution_id = i.id
         JOIN roles r ON r.id = u.role_id AND r.name = 'parent'
         JOIN parents p ON p.institution_id = u.institution_id AND p.user_id = u.id
         WHERE LOWER(i.code) = LOWER(?) AND i.status = 'active'
           AND LOWER(COALESCE(p.email, u.email)) = ?
           AND u.email_verified_at IS NULL AND u.user_type = 'parent'
           AND u.status IN ('pending', 'inactive')
           AND p.status IN ('pending_verification', 'inactive')
         LIMIT 2"
    );
    $statement->bind_param('ss', $institutionCode, $email);
    $statement->execute();
    $result = $statement->get_result();
    $accounts = [];
    while ($row = $result->fetch_assoc()) {
        $accounts[] = $row;
    }
    $statement->close();
    if (count($accounts) !== 1) {
        return;
    }

    try {
        parent_email_verification_issue(
            $db,
            (int) $accounts[0]['institution_id'],
            (int) $accounts[0]['id'],
            (string) $accounts[0]['email'],
            (string) $accounts[0]['full_name']
        );
    } catch (Throwable $exception) {
        log_error('parent_email_verification_delivery_failed', [
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
        ]);
    }
}

function parent_email_verification_admin_result(string $reason): string
{
    return match ($reason) {
        'sent' => 'sent',
        'already_verified' => 'already_verified',
        'cooldown', 'rate_limited' => 'rate_limited',
        'delivery_failed' => 'delivery_failed',
        default => 'failed',
    };
}

function parent_email_verification_send_for_admin(
    mysqli $db,
    int $institutionId,
    int $parentId,
    int $actorUserId,
    string $actorType
): string {
    $statement = $db->prepare(
        "SELECT u.id AS user_id, u.email, u.email_verified_at,
                p.full_name
         FROM parents p
         JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
         JOIN roles r ON r.id = u.role_id AND r.name = 'parent'
         WHERE p.institution_id = ? AND p.id = ? AND u.user_type = 'parent'
         LIMIT 1"
    );
    $statement->bind_param('ii', $institutionId, $parentId);
    $statement->execute();
    $parent = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_array($parent)) {
        throw new OutOfBoundsException('The parent record was not found.');
    }

    if ($parent['email_verified_at'] !== null) {
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            $actorType,
            'parent.email_verification_requested',
            'parent',
            (string) $parentId,
            ['email_verified' => true],
            ['outcome' => 'already_verified']
        );
        return 'already_verified';
    }

    $accountScope = auth_rate_limit_scope(
        'admin-parent-email-verification',
        $institutionId . ':' . $parentId
    );
    if (!consume_auth_rate_limit($accountScope, 5, 900) || !request_ip_rate_limit_allows()) {
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            $actorType,
            'parent.email_verification_requested',
            'parent',
            (string) $parentId,
            ['email_verified' => false],
            ['outcome' => 'rate_limited']
        );
        return 'rate_limited';
    }

    $outcome = 'failed';
    try {
        $issueResult = parent_email_verification_issue(
            $db,
            $institutionId,
            (int) $parent['user_id'],
            (string) $parent['email'],
            (string) $parent['full_name']
        );
        $outcome = parent_email_verification_admin_result((string) ($issueResult['reason'] ?? 'failed'));
    } catch (OtpEmailDeliveryException) {
        $outcome = 'delivery_failed';
    } catch (Throwable $exception) {
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            $actorType,
            'parent.email_verification_requested',
            'parent',
            (string) $parentId,
            ['email_verified' => false],
            ['outcome' => 'failed']
        );
        throw $exception;
    }

    write_audit_log(
        $db,
        $institutionId,
        $actorUserId,
        $actorType,
        'parent.email_verification_requested',
        'parent',
        (string) $parentId,
        ['email_verified' => false],
        ['outcome' => $outcome]
    );

    return $outcome;
}
