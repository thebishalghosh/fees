<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function current_user(): ?array
{
    $user = $_SESSION['user'] ?? null;
    if (!is_array($user)
        || !isset($user['id'], $user['institution_id'], $user['user_type'], $user['status'])
        || filter_var($user['id'], FILTER_VALIDATE_INT) === false
        || (int) $user['id'] < 1
        || filter_var($user['institution_id'], FILTER_VALIDATE_INT) === false
        || (int) $user['institution_id'] < 1
        || !in_array($user['user_type'], ['super_admin', 'admin', 'staff', 'parent'], true)
        || $user['status'] !== 'active'
        || !is_array($user['permissions'] ?? null)) {
        return null;
    }

    return $user;
}

function is_authenticated(): bool
{
    return current_user() !== null
        && isset($_SESSION['auth_session']['identifier'], $_SESSION['auth_session']['token_hash']);
}

function authenticated_dashboard_route(): string
{
    $user = current_user();
    if ($user === null) {
        throw new RuntimeException('An authenticated user is required to select a dashboard.');
    }

    return match ($user['user_type']) {
        'parent' => '/parent/dashboard',
        'super_admin', 'admin', 'staff' => '/admin/dashboard',
        default => throw new RuntimeException('The authenticated user role is unsupported.'),
    };
}

function revalidate_authenticated_session(): bool
{
    if (!is_authenticated()) {
        return false;
    }

    $user = current_user();
    $authSession = $_SESSION['auth_session'];
    if (!is_array($authSession)
        || !is_string($authSession['identifier'] ?? null)
        || preg_match('/^[a-f0-9]{32}$/D', $authSession['identifier']) !== 1
        || !is_string($authSession['token_hash'] ?? null)
        || preg_match('/^[a-f0-9]{64}$/D', $authSession['token_hash']) !== 1) {
        return false;
    }

    $settings = app_config()['auth'];
    $userId = (int) $user['id'];
    $institutionId = (int) $user['institution_id'];
    $sessionIdentifier = $authSession['identifier'];
    $sessionTokenHash = $authSession['token_hash'];
    $idleTimeout = (int) $settings['session_idle_timeout_seconds'];

    $db = db_connect();
    $statement = $db->prepare(
        'SELECT u.id, u.institution_id, u.role_id, u.user_type, u.full_name, u.status,
                r.name AS role_name, i.status AS institution_status,
                us.id AS session_record_id,
                TIMESTAMPDIFF(SECOND, us.last_activity_at, CURRENT_TIMESTAMP) AS idle_seconds
         FROM user_sessions us
         JOIN users u ON u.id = us.user_id AND u.institution_id = us.institution_id
         JOIN roles r ON r.id = u.role_id
         JOIN institutions i ON i.id = u.institution_id
         LEFT JOIN parents p ON p.institution_id = u.institution_id AND p.user_id = u.id
         WHERE us.session_identifier = ? AND us.session_token_hash = ?
           AND us.user_id = ? AND us.institution_id = ?
           AND us.revoked_at IS NULL AND us.expires_at > CURRENT_TIMESTAMP
           AND (u.user_type <> \'parent\' OR p.status = \'active\')
         LIMIT 1'
    );
    $statement->bind_param('ssii', $sessionIdentifier, $sessionTokenHash, $userId, $institutionId);
    $statement->execute();
    $result = $statement->get_result();
    $record = $result->fetch_assoc();
    $statement->close();

    if (!is_array($record)
        || $record['status'] !== 'active'
        || $record['institution_status'] !== 'active'
        || $record['role_name'] !== $record['user_type']
        || !in_array($record['user_type'], ['super_admin', 'admin', 'staff', 'parent'], true)
        || (int) $record['idle_seconds'] > $idleTimeout) {
        return false;
    }

    $sessionRecordId = (int) $record['session_record_id'];
    $statement = $db->prepare(
        'UPDATE user_sessions SET last_activity_at = CURRENT_TIMESTAMP
         WHERE id = ? AND revoked_at IS NULL AND expires_at > CURRENT_TIMESTAMP'
    );
    $statement->bind_param('i', $sessionRecordId);
    $statement->execute();
    $statement->close();

    $roleId = (int) $record['role_id'];
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
    while ($permission = $result->fetch_assoc()) {
        $permissions[] = (string) $permission['permission_key'];
    }
    $statement->close();

    $_SESSION['user'] = [
        'id' => $userId,
        'institution_id' => $institutionId,
        'role_id' => $roleId,
        'user_type' => (string) $record['user_type'],
        'full_name' => (string) $record['full_name'],
        'status' => 'active',
        'permissions' => $permissions,
    ];

    return true;
}

function require_login(): void
{
    $valid = false;
    try {
        $valid = revalidate_authenticated_session();
    } catch (Throwable $exception) {
        log_error('authentication_session_validation_failed', [
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
        ]);
    }

    if (!$valid) {
        clear_app_session();
        start_app_session();
        set_flash('warning', 'Your session has expired. Please sign in again.');
        safe_redirect('/login');
    }
}

function user_has_permission(string $permission): bool
{
    if ($permission === '' || preg_match('/^[a-z][a-z0-9_.-]*$/D', $permission) !== 1) {
        return false;
    }

    $user = current_user();
    $permissions = $user['permissions'] ?? null;
    if ($user === null || !is_array($permissions)) {
        return false;
    }

    foreach ($permissions as $grantedPermission) {
        if (is_string($grantedPermission) && hash_equals($grantedPermission, $permission)) {
            return true;
        }
    }

    return false;
}

function user_can_access_institution(int $institutionId): bool
{
    $user = current_user();
    return $institutionId > 0
        && $user !== null
        && (int) $user['institution_id'] === $institutionId;
}

function require_permission(string $permission): void
{
    require_login();
    if (!user_has_permission($permission)) {
        http_response_code(403);
        exit('Forbidden.');
    }
}

function require_institution_access(int $institutionId): void
{
    require_login();
    if (!user_can_access_institution($institutionId)) {
        http_response_code(403);
        exit('Forbidden.');
    }
}

function user_can_access_student(int $studentId, ?string $scope = null): bool
{
    $user = current_user();
    if ($studentId < 1
        || $user === null
        || ($scope !== null && !in_array($scope, ['academics', 'fees', 'payments'], true))) {
        return false;
    }

    $userId = (int) $user['id'];
    $institutionId = (int) $user['institution_id'];
    $db = db_connect();
    if ($user['user_type'] !== 'parent') {
        $statement = $db->prepare(
            'SELECT 1 FROM students WHERE id = ? AND institution_id = ? LIMIT 1'
        );
        $statement->bind_param('ii', $studentId, $institutionId);
    } else {
        $permissionColumn = match ($scope) {
            'academics' => 'can_view_academics',
            'fees' => 'can_view_fees',
            'payments' => 'can_make_payments',
            default => null,
        };
        $query = 'SELECT 1
                  FROM parents p
                  JOIN parent_student_relationships psr
                    ON psr.institution_id = p.institution_id AND psr.parent_id = p.id
                  JOIN students s
                    ON s.institution_id = psr.institution_id AND s.id = psr.student_id
                  WHERE p.user_id = ? AND p.institution_id = ? AND s.id = ?
                    AND p.status = \'active\' AND psr.status = \'active\'';
        if ($permissionColumn !== null) {
            $query .= ' AND psr.' . $permissionColumn . ' = 1';
        }
        $query .= ' LIMIT 1';
        $statement = $db->prepare($query);
        $statement->bind_param('iii', $userId, $institutionId, $studentId);
    }

    $statement->execute();
    $allowed = $statement->get_result()->num_rows === 1;
    $statement->close();

    return $allowed;
}

function require_student_access(int $studentId, ?string $scope = null): void
{
    require_login();
    if (!user_can_access_student($studentId, $scope)) {
        http_response_code(403);
        exit('Forbidden.');
    }
}

function require_user_types(array $allowedTypes): void
{
    require_login();
    $user = current_user();
    $validTypes = ['super_admin', 'admin', 'staff', 'parent'];
    foreach ($allowedTypes as $type) {
        if (!is_string($type) || !in_array($type, $validTypes, true)) {
            throw new InvalidArgumentException('The requested role is not recognized.');
        }
    }

    if ($user === null || !in_array($user['user_type'], $allowedTypes, true)) {
        http_response_code(403);
        exit('Forbidden.');
    }
}

function require_placeholder_route_access(): void
{
    $scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = rtrim((string) (parse_url(APP_URL, PHP_URL_PATH) ?: ''), '/');
    if ($basePath !== '' && str_starts_with($scriptPath, $basePath . '/')) {
        $scriptPath = substr($scriptPath, strlen($basePath));
    }
    $scriptPath = '/' . ltrim($scriptPath, '/');

    if (str_starts_with($scriptPath, '/parent/')) {
        require_login();
        $user = current_user();
        if ($user === null || $user['user_type'] !== 'parent') {
            http_response_code(403);
            exit('Forbidden.');
        }
        $permission = null;
        if (preg_match('#^/parent/(?:index|dashboard)\.php$#', $scriptPath) === 1) {
            $permission = 'dashboard.view';
        } elseif (preg_match('#^/parent/(?:children|child-details)\.php$#', $scriptPath) === 1) {
            $permission = 'students.view';
        } elseif ($scriptPath === '/parent/institution-logo.php') {
            $permission = 'dashboard.view';
        } elseif ($scriptPath === '/parent/admissions/download-pdf.php') {
            $permission = 'students.view';
        } elseif (str_starts_with($scriptPath, '/parent/fees/')) {
            $permission = 'fees.view';
        } elseif (str_starts_with($scriptPath, '/parent/receipts/')) {
            $permission = 'receipts.view';
        } elseif (str_starts_with($scriptPath, '/parent/payments/')) {
            $permission = 'payments.view';
        } elseif (str_starts_with($scriptPath, '/parent/academics/')) {
            $permission = 'students.view';
        } elseif (in_array($scriptPath, [
            '/parent/attendance.php',
            '/parent/announcements.php',
            '/parent/notifications/index.php',
        ], true)) {
            $permission = 'dashboard.view';
        } elseif (str_starts_with($scriptPath, '/parent/profile/')
            || in_array($scriptPath, ['/parent/account.php', '/parent/help.php'], true)) {
            $permission = 'parents.view';
        }

        if ($permission === null) {
            http_response_code(403);
            exit('Forbidden.');
        }
        if (!user_has_permission($permission)) {
            http_response_code(403);
            exit('Forbidden.');
        }
        return;
    }

    if (!str_starts_with($scriptPath, '/admin/')) {
        http_response_code(403);
        exit('Forbidden.');
    }

    require_login();
    $user = current_user();
    if ($user === null || !in_array($user['user_type'], ['super_admin', 'admin', 'staff'], true)) {
        http_response_code(403);
        exit('Forbidden.');
    }
    $relativePath = substr($scriptPath, strlen('/admin/'));
    $section = explode('/', $relativePath)[0] ?? '';
    $action = pathinfo($relativePath, PATHINFO_FILENAME);
    $permission = null;

    if (in_array($relativePath, ['dashboard.php', 'index.php'], true)) {
        $permission = 'dashboard.view';
    } elseif ($relativePath === 'institution-logo.php') {
        $permission = 'dashboard.view';
    } elseif (in_array($section, ['parents', 'students', 'admissions'], true)) {
        $readActions = ['index', 'view', 'admission-form'];
        $permission = match ($section) {
            'parents' => in_array($action, $readActions, true) ? 'parents.view' : 'parents.manage',
            'students' => in_array($action, $readActions, true) ? 'students.view' : 'students.manage',
            'admissions' => in_array($action, $readActions, true) ? 'admissions.view' : 'admissions.manage',
        };
    } elseif (in_array($section, ['courses', 'batches'], true)) {
        $permission = 'courses.manage';
    } elseif ($section === 'fees') {
        $permission = in_array($action, ['categories', 'create-structure', 'edit-structure', 'generate-installments'], true)
            ? 'fees.manage'
            : 'fees.view';
    } elseif ($section === 'payments') {
        $permission = match ($action) {
            'reconcile' => 'payments.reconcile',
            'refunds' => 'payments.refund',
            default => 'payments.view',
        };
    } elseif ($section === 'receipts') {
        $permission = 'receipts.view';
    } elseif ($section === 'reports') {
        $permission = 'reports.view';
    } elseif ($section === 'academics') {
        $permission = 'academics.manage';
    } elseif ($section === 'notifications') {
        $permission = 'notifications.manage';
    } elseif ($section === 'settings') {
        $permission = 'settings.manage';
    } elseif ($section === 'staff') {
        $permission = 'staff.manage';
    } elseif ($section === 'audit') {
        $permission = 'audit.view';
    }

    if ($permission === null) {
        http_response_code(403);
        exit('Forbidden.');
    }
    if (!user_has_permission($permission)) {
        http_response_code(403);
        exit('Forbidden.');
    }
}

function logout_user(): bool
{
    $revoked = true;
    $user = current_user();
    $authSession = $_SESSION['auth_session'] ?? null;
    if ($user !== null && is_array($authSession)
        && is_string($authSession['identifier'] ?? null)
        && is_string($authSession['token_hash'] ?? null)) {
        try {
            $db = db_connect();
            $userId = (int) $user['id'];
            $identifier = $authSession['identifier'];
            $tokenHash = $authSession['token_hash'];
            $statement = $db->prepare(
                'UPDATE user_sessions SET revoked_at = CURRENT_TIMESTAMP
                 WHERE user_id = ? AND session_identifier = ? AND session_token_hash = ? AND revoked_at IS NULL'
            );
            $statement->bind_param('iss', $userId, $identifier, $tokenHash);
            $statement->execute();
            $revoked = $statement->affected_rows === 1;
            $statement->close();
        } catch (Throwable $exception) {
            $revoked = false;
            log_error('authentication_session_revocation_failed', [
                'error_type' => get_class($exception),
                'error_code' => $exception->getCode(),
            ]);
        }
    }

    clear_app_session();
    return $revoked;
}
