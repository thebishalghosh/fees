<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/otp-service.php';

function admin_auth_unique_account(array $accounts): ?array
{
    if (count($accounts) !== 1 || !is_array($accounts[0])) {
        return null;
    }

    $account = $accounts[0];
    if (!isset($account['id'], $account['institution_id'], $account['role_id'])
        || !in_array($account['user_type'] ?? null, ['admin', 'super_admin'], true)
        || ($account['role_name'] ?? null) !== $account['user_type']
        || ($account['status'] ?? null) !== 'active'
        || ($account['institution_status'] ?? null) !== 'active') {
        return null;
    }

    return $account;
}

function admin_auth_password_matches(string $password, ?string $passwordHash): bool
{
    return $password !== ''
        && is_string($passwordHash)
        && $passwordHash !== ''
        && password_verify($password, $passwordHash);
}

function admin_auth_password_is_valid(string $password): bool
{
    return mb_check_encoding($password, 'UTF-8')
        && mb_strlen($password, 'UTF-8') >= 12
        && strlen($password) <= 72
        && preg_match('/[\x00-\x1F\x7F]/', $password) !== 1;
}

function admin_auth_password_login(mysqli $db, string $identifier, string $password): string
{
    $identifier = trim($identifier);
    if ($identifier === '' || strlen($identifier) > 255 || strlen($password) > 1024) {
        return 'invalid';
    }

    $ipScope = 'admin-password-ip:' . hash('sha256', auth_client_ip());
    $accountScope = 'admin-password-account:' . hash('sha256', strtolower($identifier));
    if (!consume_auth_rate_limit($ipScope, 10, 900)
        || !consume_auth_rate_limit($accountScope, 5, 900)) {
        return 'rate_limited';
    }

    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            "SELECT u.id, u.institution_id, u.role_id, u.user_type, u.password_hash,
                    u.status, r.name AS role_name, i.status AS institution_status
             FROM users u
             JOIN roles r ON r.id = u.role_id
             JOIN institutions i ON i.id = u.institution_id
             WHERE (u.username = ? OR u.email = ?)
               AND u.user_type IN ('super_admin','admin')
               AND r.name = u.user_type
             LIMIT 2
             FOR UPDATE"
        );
        $statement->bind_param('ss', $identifier, $identifier);
        $statement->execute();
        $accounts = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
        $statement->close();

        $account = admin_auth_unique_account($accounts);
        $passwordHash = is_array($account) && is_string($account['password_hash'] ?? null)
            ? $account['password_hash']
            : null;
        if ($account === null || !admin_auth_password_matches($password, $passwordHash)) {
            $db->rollback();
            return 'invalid';
        }

        $userId = (int) $account['id'];
        $institutionId = (int) $account['institution_id'];
        $roleId = (int) $account['role_id'];
        $statement = $db->prepare(
            'SELECT p.permission_key
             FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.role_id = ?
             ORDER BY p.permission_key'
        );
        $statement->bind_param('i', $roleId);
        $statement->execute();
        $permissions = array_map(
            static fn(array $row): string => (string) $row['permission_key'],
            $statement->get_result()->fetch_all(MYSQLI_ASSOC)
        );
        $statement->close();

        regenerate_session_id();
        $sessionIdentifier = bin2hex(random_bytes(16));
        $sessionTokenHash = hash('sha256', session_id());
        $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);
        $ipAddress = auth_client_ip();
        if ($ipAddress === 'unknown') {
            $ipAddress = '';
        }
        $absoluteTimeout = (int) app_config()['auth']['session_absolute_timeout_seconds'];
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

        $statement = $db->prepare(
            'UPDATE users SET last_login_at = CURRENT_TIMESTAMP
             WHERE id = ? AND institution_id = ? AND status = \'active\''
        );
        $statement->bind_param('ii', $userId, $institutionId);
        $statement->execute();
        $statement->close();
        $db->commit();

        $_SESSION['auth_session'] = [
            'identifier' => $sessionIdentifier,
            'token_hash' => $sessionTokenHash,
        ];
        $_SESSION['auth_issued_at'] = time();
        $_SESSION['user'] = [
            'id' => $userId,
            'institution_id' => $institutionId,
            'role_id' => $roleId,
            'user_type' => (string) $account['user_type'],
            'status' => 'active',
            'permissions' => $permissions,
        ];
        unset($_SESSION['pending_auth'], $_SESSION['otp_modal_message']);

        return 'authenticated';
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function admin_auth_update_own_password(
    mysqli $db,
    int $userId,
    int $institutionId,
    string $password
): bool {
    if (!admin_auth_password_is_valid($password)) {
        throw new InvalidArgumentException('The administrator password does not meet the password requirements.');
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($hash)) {
        throw new RuntimeException('The administrator password could not be secured.');
    }

    $statement = $db->prepare(
        "UPDATE users SET password_hash = ?
         WHERE id = ? AND institution_id = ?
           AND user_type IN ('super_admin','admin') AND status = 'active'"
    );
    $statement->bind_param('sii', $hash, $userId, $institutionId);
    $statement->execute();
    $updated = $statement->affected_rows === 1;
    $statement->close();

    return $updated;
}
