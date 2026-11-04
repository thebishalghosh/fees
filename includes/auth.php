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
        || (isset($user['role_is_system']) && !is_bool($user['role_is_system']))
        || !is_array($user['permissions'] ?? null)) {
        return null;
    }

    return $user;
}

function admin_selected_institution_id(): ?int
{
    $user = current_user();
    if ($user === null || $user['user_type'] !== 'super_admin') {
        return null;
    }

    $institutionId = filter_var($_SESSION['admin_selected_institution_id'] ?? null, FILTER_VALIDATE_INT);
    return $institutionId !== false && $institutionId !== null && $institutionId > 0
        ? (int) $institutionId
        : null;
}

function authenticated_institution_id(array $allowedUserTypes): ?int
{
    $user = current_user();
    if ($user === null) {
        return null;
    }

    foreach ($allowedUserTypes as $userType) {
        if (!is_string($userType)
            || !in_array($userType, ['super_admin', 'admin', 'staff', 'parent'], true)) {
            throw new InvalidArgumentException('The requested institution account type is not recognized.');
        }
    }

    if (!in_array($user['user_type'], $allowedUserTypes, true)) {
        return null;
    }

    return $user['user_type'] === 'super_admin'
        ? admin_selected_institution_id()
        : (int) $user['institution_id'];
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
    if (isset($_SESSION['platform_admin'])
        || isset($_SESSION['platform_admin_session_id'])
        || isset($_SESSION['platform_admin_session_token'])) {
        return false;
    }

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
                r.name AS role_name, r.is_system AS role_is_system,
                r.institution_id AS role_institution_id, r.is_active AS role_is_active,
                i.status AS institution_status,
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
        || !authenticated_role_is_valid(
            (string) $record['user_type'],
            (string) $record['role_name'],
            (int) $record['role_is_system'] === 1,
            $record['role_institution_id'] === null ? null : (int) $record['role_institution_id'],
            (int) $record['institution_id'],
            (int) $record['role_is_active'] === 1
        )
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
        'role_is_system' => (int) $record['role_is_system'] === 1,
        'full_name' => (string) $record['full_name'],
        'status' => 'active',
        'permissions' => $permissions,
    ];

    return true;
}

function authenticated_role_is_valid(
    string $userType,
    string $roleName,
    bool $roleIsSystem,
    ?int $roleInstitutionId,
    int $userInstitutionId,
    bool $roleIsActive
): bool {
    if (!$roleIsActive || $userInstitutionId < 1) {
        return false;
    }
    if ($userType === 'staff') {
        return ($roleIsSystem && $roleName === 'staff' && $roleInstitutionId === null)
            || (!$roleIsSystem && $roleInstitutionId === $userInstitutionId);
    }

    return in_array($userType, ['super_admin', 'admin', 'parent'], true)
        && $roleIsSystem
        && $roleInstitutionId === null
        && $roleName === $userType;
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

function user_has_compatible_permission(string $permission, string $systemRoleFallback): bool
{
    if (user_has_permission($permission)) {
        return true;
    }
    $user = current_user();
    return $user !== null
        && ($user['role_is_system'] ?? true)
        && user_has_permission($systemRoleFallback);
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
    if ($user['user_type'] === 'super_admin') {
        return true;
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
    if ($institutionId < 1 || $user === null) {
        return false;
    }

    return $user['user_type'] === 'super_admin'
        ? admin_selected_institution_id() === $institutionId
        : (int) $user['institution_id'] === $institutionId;
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
    if ($user['user_type'] === 'super_admin') {
        $requestData = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
        $requestHasInstitution = array_key_exists('institution_id', $requestData);
        $requestedInstitution = $requestData['institution_id'] ?? null;
        if ($requestHasInstitution) {
            if ($requestedInstitution === null || $requestedInstitution === '') {
                unset($_SESSION['admin_selected_institution_id']);
            } else {
                $requestedId = filter_var($requestedInstitution, FILTER_VALIDATE_INT);
                if ($requestedId === false || $requestedId < 1) {
                    unset($_SESSION['admin_selected_institution_id']);
                    http_response_code(400);
                    exit('Choose a valid institution.');
                }
                $db = db_connect();
                $statement = $db->prepare(
                    "SELECT id FROM institutions WHERE id = ? AND status <> 'archived' LIMIT 1"
                );
                $statement->bind_param('i', $requestedId);
                $statement->execute();
                $availableInstitutionId = $statement->get_result()->fetch_column();
                $statement->close();
                if ($availableInstitutionId === false) {
                    unset($_SESSION['admin_selected_institution_id']);
                    http_response_code(404);
                    exit('The selected institution is unavailable.');
                }
                $_SESSION['admin_selected_institution_id'] = (int) $availableInstitutionId;
            }
        } elseif (admin_selected_institution_id() !== null) {
            $selectedId = admin_selected_institution_id();
            $db = db_connect();
            $statement = $db->prepare(
                "SELECT id FROM institutions WHERE id = ? AND status <> 'archived' LIMIT 1"
            );
            $statement->bind_param('i', $selectedId);
            $statement->execute();
            $stillAvailable = $statement->get_result()->fetch_column();
            $statement->close();
            if ($stillAvailable === false) {
                unset($_SESSION['admin_selected_institution_id']);
            }
        }
        if (admin_selected_institution_id() === null
            && !in_array($relativePath, ['dashboard.php', 'index.php'], true)) {
            safe_redirect('/admin/dashboard?select_institution=1', 303);
        }
    }
    $section = explode('/', $relativePath)[0] ?? '';
    $action = pathinfo($relativePath, PATHINFO_FILENAME);
    $permission = null;

    if (in_array($relativePath, ['dashboard.php', 'index.php'], true)) {
        $permission = 'dashboard.view';
    } elseif ($relativePath === 'institution-logo.php') {
        $permission = 'dashboard.view';
    } elseif (in_array($section, ['parents', 'students', 'admissions', 'courses', 'batches', 'fees', 'payments', 'receipts', 'reports', 'academics', 'notifications', 'settings', 'staff', 'audit'], true)) {
        [$permission, $legacyPermission] = admin_route_permissions($relativePath, $section, $action);
    }

    if ($permission === null) {
        http_response_code(403);
        exit('Forbidden.');
    }
    $superAdminClassRoute = $user['user_type'] === 'super_admin'
        && in_array($relativePath, ['academics/classes.php', 'academics/create-class.php'], true);
    if (!$superAdminClassRoute
        && !user_has_compatible_permission($permission, $legacyPermission ?? $permission)) {
        http_response_code(403);
        exit('Forbidden.');
    }
}

function admin_route_permissions(string $relativePath, string $section, string $action): array
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $operation = match (true) {
        in_array($action, ['store', 'create'], true) => 'create',
        in_array($action, ['update', 'edit'], true) => 'edit',
        in_array($action, ['delete', 'remove'], true) => 'delete',
        $action === 'approve' => 'approve',
        $action === 'reject' => 'reject',
        str_contains($action, 'export') => 'export',
        $action === 'publish' => 'publish',
        $method === 'POST' => 'edit',
        default => 'view',
    };

    if ($section === 'payments') {
        return match ($action) {
            'reconcile' => ['payments.reconcile', 'payments.reconcile'],
            'refunds' => ['payments.refund', 'payments.refund'],
            'export' => ['payments.export', 'payments.view'],
            default => ['payments.view', 'payments.view'],
        };
    }
    if ($section === 'reports') {
        $financial = in_array($action, ['collections', 'outstanding-fees', 'export'], true);
        $permission = $financial ? 'financial_reports.' . ($action === 'export' ? 'export' : $operation) : 'general_reports.' . ($action === 'export' ? 'export' : $operation);
        if (!in_array($operation, ['view', 'export'], true)) {
            $permission = $financial ? 'financial_reports.view' : 'general_reports.view';
        }
        return [$permission, 'reports.view'];
    }
    if ($section === 'receipts') {
        return ['receipts.view', 'receipts.view'];
    }
    if ($section === 'staff' && $action === 'permissions') {
        if ($method === 'POST') {
            $permission = match ($_POST['action'] ?? null) {
                'create', 'duplicate' => 'roles.create',
                'update', 'activate', 'deactivate' => 'roles.edit',
                'delete' => 'roles.delete',
                'save_permissions' => 'roles.permissions.manage',
                default => 'roles.view',
            };
            return [$permission, 'staff.manage'];
        }
        return ['roles.view', 'staff.manage'];
    }
    if ($section === 'notifications') {
        if ($action === 'send') {
            return ['notifications.view', 'notifications.manage'];
        }
        if ($action === 'index' && $method === 'POST') {
            return ['announcements.publish', 'notifications.manage'];
        }
        if ($action === 'create') {
            $editing = isset($_GET['id']) && filter_var($_GET['id'], FILTER_VALIDATE_INT) !== false;
            return [$editing ? 'announcements.edit' : 'announcements.create', 'notifications.manage'];
        }
    }
    if ($section === 'admissions' && $action === 'view' && $method === 'POST') {
        return match ($_POST['action'] ?? null) {
            'approve' => ['admissions.approve', 'admissions.manage'],
            'reject' => ['admissions.reject', 'admissions.manage'],
            'convert' => ['admissions.approve', 'admissions.manage'],
            default => ['admissions.edit', 'admissions.manage'],
        };
    }
    if ($section === 'academics') {
        if ($action === 'create-class') {
            return ['classes.create', 'academics.manage'];
        }
        if ($action === 'classes' && $method === 'POST') {
            return in_array($_POST['action'] ?? null, ['update_class', 'update_section'], true)
                ? ['classes.edit', 'academics.manage']
                : ['classes.create', 'academics.manage'];
        }
        if (in_array($action, ['subjects', 'programmes'], true) && $method === 'POST') {
            return ['academic_years.edit', 'academics.manage'];
        }
        if ($action === 'enrolments' && $method === 'POST') {
            return (($_POST['action'] ?? null) === 'enrol')
                ? ['students.create', 'academics.manage']
                : ['students.edit', 'academics.manage'];
        }
        if ($action === 'years' && $method === 'POST') {
            return (($_POST['action'] ?? null) === 'create')
                ? ['academic_years.create', 'academics.manage']
                : ['academic_years.edit', 'academics.manage'];
        }
        if ($action === 'attendance' && $method === 'POST') {
            return ['attendance.view', 'academics.manage'];
        }
        if ($action === 'examinations') {
            if ($method === 'POST') {
                if (($_POST['action'] ?? null) === 'publication') {
                    return ['results.publish', 'academics.manage'];
                }
                $examId = filter_var($_POST['exam_id'] ?? 0, FILTER_VALIDATE_INT);
                return $examId !== false && $examId > 0
                    ? ['examinations.edit', 'academics.manage']
                    : ['examinations.create', 'academics.manage'];
            }
            if (isset($_GET['edit'])) {
                return ['examinations.edit', 'academics.manage'];
            }
        }
        if ($action === 'results' && $method === 'POST') {
            return ['results.view', 'academics.manage'];
        }
    }
    if ($section === 'fees' && $action === 'categories' && $method === 'POST') {
        return (($_POST['action'] ?? null) === 'create')
            ? ['fee_structures.create', 'fees.manage']
            : ['fee_structures.edit', 'fees.manage'];
    }
    if ($section === 'fees') {
        if ($action === 'create-structure') {
            return ['fee_structures.create', 'fees.manage'];
        }
        if ($action === 'edit-structure') {
            return ['fee_structures.edit', 'fees.manage'];
        }
        if (in_array($action, ['assignments', 'payment-plans', 'generate-installments'], true)) {
            $operationPermission = $method === 'POST' || $action === 'generate-installments'
                ? 'student_fees.create'
                : 'student_fees.view';
            return [$operationPermission, 'fees.manage'];
        }
    }

    $module = match ($section) {
        'students' => 'students',
        'parents' => 'parents',
        'admissions' => 'admissions',
        'courses' => 'courses',
        'batches' => 'batches',
        'staff' => 'staff',
        'notifications' => 'announcements',
        'settings' => 'institution_settings',
        'audit' => 'audit',
        'academics' => match ($action) {
            'years' => 'academic_years',
            'classes', 'create-class' => 'classes',
            'subjects', 'programmes', 'enrolments' => 'academic_years',
            'attendance' => 'attendance',
            'examinations' => 'examinations',
            'results' => 'results',
            default => 'academic_years',
        },
        'fees' => match ($action) {
            'structures', 'create-structure', 'edit-structure', 'categories' => 'fee_structures',
            default => 'student_fees',
        },
        default => $section,
    };
    $mappedOperation = $operation;
    if ($section === 'fees' && in_array($action, ['categories', 'payment-plans', 'discounts', 'adjustments', 'installments'], true)) {
        $mappedOperation = $method === 'POST' ? 'edit' : 'view';
    }
    if ($section === 'notifications' && $action === 'index' && $method === 'POST') {
        $mappedOperation = 'edit';
    }
    if ($section === 'settings') {
        $mappedOperation = $method === 'POST' ? 'manage' : 'view';
    }
    if ($section === 'staff' && $action === 'index') {
        if ($method === 'POST') {
            $mappedOperation = match ($_POST['action'] ?? null) {
                'create' => 'create',
                'assign_role' => 'assign_role',
                default => 'edit',
            };
        } elseif (isset($_GET['create'])) {
            $mappedOperation = 'create';
        } elseif (isset($_GET['edit'])) {
            $mappedOperation = 'edit';
        }
    }
    $permission = $module . '.' . $mappedOperation;
    $legacy = match ($section) {
        'students' => $operation === 'view' ? 'students.view' : 'students.manage',
        'parents' => $operation === 'view' ? 'parents.view' : 'parents.manage',
        'admissions' => $operation === 'view' ? 'admissions.view' : 'admissions.manage',
        'courses', 'batches' => 'courses.manage',
        'staff' => 'staff.manage',
        'notifications' => 'notifications.manage',
        'settings' => 'settings.manage',
        'audit' => 'audit.view',
        'academics' => 'academics.manage',
        'fees' => in_array($action, ['structures', 'payment-plans', 'discounts', 'installments', 'adjustments'], true)
            && $operation === 'view' ? 'fees.view' : 'fees.manage',
        default => $permission,
    };
    if ($section === 'audit') {
        $permission = 'audit.view';
    }

    return [$permission, $legacy];
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

function platform_admin_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function platform_admin_session_token_hash(string $token): string
{
    return hash('sha256', $token);
}

function platform_admin_permission_catalog(): array
{
    return [
        'institutions.view',
        'institutions.create',
        'institutions.update',
        'institutions.suspend',
        'platform_admins.manage',
        'platform_permissions.manage',
        'platform_audit.view',
        'institution_support.read',
    ];
}

function platform_admin_bootstrap_permission_grants(): array
{
    return ['platform_admins.manage', 'platform_permissions.manage', 'platform_audit.view'];
}

function current_platform_admin(): ?array
{
    $admin = $_SESSION['platform_admin'] ?? null;
    if (!is_array($admin)
        || !isset($admin['id'], $admin['name'], $admin['email'], $admin['status'])
        || filter_var($admin['id'], FILTER_VALIDATE_INT) === false
        || (int) $admin['id'] < 1
        || !is_string($admin['name'])
        || !is_string($admin['email'])
        || !is_string($admin['status'])
        || !isset($admin['permissions'])
        || !is_array($admin['permissions'])) {
        return null;
    }

    return $admin;
}

function platform_admin_has_permission(string $permission, ?mysqli $db = null): bool
{
    if (!in_array($permission, platform_admin_permission_catalog(), true)
        || current_platform_admin() === null
        || !platform_admin_revalidate_session($db)) {
        return false;
    }

    return platform_admin_session_has_permission($permission);
}

function platform_admin_session_has_permission(string $permission): bool
{
    if (!in_array($permission, platform_admin_permission_catalog(), true)) {
        return false;
    }

    $admin = current_platform_admin();
    return $admin !== null && in_array($permission, $admin['permissions'], true);
}

function require_platform_admin(): void
{
    $admin = current_platform_admin();
    if ($admin === null || !platform_admin_revalidate_session(db_connect())) {
        http_response_code(401);
        exit('Platform admin authentication required.');
    }
}

function require_platform_permission(string $permission): void
{
    require_platform_admin();
    if (!platform_admin_session_has_permission($permission)) {
        http_response_code(403);
        exit('Forbidden.');
    }
}

function platform_admin_bootstrap_token(): ?string
{
    $token = getenv('FEES_PLATFORM_BOOTSTRAP_TOKEN');
    return is_string($token) && trim($token) !== '' ? trim($token) : null;
}

function platform_admin_correlation_id(): string
{
    return bin2hex(random_bytes(16));
}

function platform_admin_bootstrap_available(mysqli $db): bool
{
    $statement = $db->prepare(
        'SELECT initialized_at FROM platform_bootstrap_state WHERE id = 1 FOR UPDATE'
    );
    $statement->execute();
    $state = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_array($state)) {
        throw new RuntimeException('The platform bootstrap state is not available.');
    }
    if ($state['initialized_at'] !== null) {
        return false;
    }

    $result = $db->query('SELECT COUNT(*) FROM platform_admins');
    if ($result === false) {
        throw new RuntimeException('The platform admin table is not available.');
    }

    return (int) $result->fetch_row()[0] === 0;
}

function platform_admin_log_bootstrap_failure(string $operation, ?Throwable $exception = null): void
{
    log_error('platform_bootstrap_failed', [
        'operation' => $operation,
        'error_type' => $exception === null ? null : get_class($exception),
        'error_code' => $exception === null ? 0 : $exception->getCode(),
    ]);
}

function write_platform_audit_log(
    mysqli $db,
    ?int $platformAdminId,
    string $action,
    ?string $targetType = null,
    ?int $targetId = null,
    ?int $targetInstitutionId = null,
    ?array $metadata = null,
    ?string $correlationId = null,
    ?string $userAgent = null,
    ?string $ipAddress = null
): void {
    if ($metadata !== null) {
        $allowedMetadata = [
            'bootstrap' => static fn(mixed $value): bool => is_bool($value),
            'status' => static fn(mixed $value): bool => is_string($value)
                && in_array($value, ['active', 'pending', 'suspended', 'disabled'], true),
            'login_mode' => static fn(mixed $value): bool => $value === 'password',
            'logout' => static fn(mixed $value): bool => is_bool($value),
            'target_identity' => static fn(mixed $value): bool => in_array($value, ['platform_admin', 'institution_user'], true),
            'permission' => static fn(mixed $value): bool => is_string($value)
                && in_array($value, platform_admin_permission_catalog(), true),
            'granted' => static fn(mixed $value): bool => is_bool($value),
        ];
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || !isset($allowedMetadata[$key]) || !$allowedMetadata[$key]($value)) {
                throw new InvalidArgumentException('Platform audit metadata contains an unsupported field.');
            }
        }
    }
    $metadataJson = $metadata === null ? null : json_encode(
        $metadata,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if (is_string($metadataJson) && strlen($metadataJson) > 2048) {
        throw new InvalidArgumentException('Platform audit metadata is too large.');
    }
    $actorName = null;
    $statement = $db->prepare(
        'INSERT INTO platform_audit_logs (
            actor_platform_admin_id, actor_name, action, target_type, target_id,
            target_institution_id, ip_address, user_agent, correlation_id, metadata
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $statement->bind_param(
        'isssiissss',
        $platformAdminId,
        $actorName,
        $action,
        $targetType,
        $targetId,
        $targetInstitutionId,
        $ipAddress,
        $userAgent,
        $correlationId,
        $metadataJson
    );
    $statement->execute();
    $statement->close();
}

function grant_platform_admin_permission(mysqli $db, int $targetPlatformAdminId, string $permission): bool
{
    return update_platform_admin_permission_grant($db, $targetPlatformAdminId, $permission, true);
}

function revoke_platform_admin_permission(mysqli $db, int $targetPlatformAdminId, string $permission): bool
{
    return update_platform_admin_permission_grant($db, $targetPlatformAdminId, $permission, false);
}

function update_platform_admin_permission_grant(
    mysqli $db,
    int $targetPlatformAdminId,
    string $permission,
    bool $grant
): bool {
    if ($targetPlatformAdminId < 1 || !in_array($permission, platform_admin_permission_catalog(), true)) {
        throw new InvalidArgumentException('The platform permission grant is invalid.');
    }
    if (!platform_admin_has_permission('platform_permissions.manage', $db)) {
        throw new RuntimeException('Platform permission management is not authorized.');
    }

    $actor = current_platform_admin();
    if ($actor === null) {
        throw new RuntimeException('Platform admin authentication required.');
    }

    $db->begin_transaction();
    try {
        $targetStatement = $db->prepare('SELECT id FROM platform_admins WHERE id = ? FOR UPDATE');
        $targetStatement->bind_param('i', $targetPlatformAdminId);
        $targetStatement->execute();
        $targetExists = $targetStatement->get_result()->fetch_assoc() !== null;
        $targetStatement->close();
        if (!$targetExists) {
            throw new InvalidArgumentException('The target platform administrator is unavailable.');
        }

        if ($grant) {
            $statement = $db->prepare(
                'INSERT IGNORE INTO platform_admin_permission_grants
                    (platform_admin_id, permission_key, granted_by_platform_admin_id)
                 VALUES (?, ?, ?)'
            );
            $actorId = (int) $actor['id'];
            $statement->bind_param('isi', $targetPlatformAdminId, $permission, $actorId);
        } else {
            $statement = $db->prepare(
                'DELETE FROM platform_admin_permission_grants
                 WHERE platform_admin_id = ? AND permission_key = ?'
            );
            $statement->bind_param('is', $targetPlatformAdminId, $permission);
        }
        $statement->execute();
        $changed = $statement->affected_rows === 1;
        $statement->close();

        if ($changed) {
            write_platform_audit_log(
                $db,
                (int) $actor['id'],
                $grant ? 'platform.admin.permission.granted' : 'platform.admin.permission.revoked',
                'platform_admin',
                $targetPlatformAdminId,
                null,
                ['permission' => $permission, 'granted' => $grant],
                platform_admin_correlation_id(),
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                filter_var($_SERVER['REMOTE_ADDR'] ?? null, FILTER_VALIDATE_IP) ?: null
            );
        }

        $db->commit();
        return $changed;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function prepare_identity_session(mysqli $db, string $targetIdentity): void
{
    if (!in_array($targetIdentity, ['platform_admin', 'institution_user'], true)) {
        throw new InvalidArgumentException('The requested authentication identity is not recognized.');
    }

    $institutionUser = current_user();
    $institutionSession = $_SESSION['auth_session'] ?? null;
    if ($institutionUser !== null
        && is_array($institutionSession)
        && is_string($institutionSession['identifier'] ?? null)
        && preg_match('/^[a-f0-9]{32}$/D', $institutionSession['identifier']) === 1
        && is_string($institutionSession['token_hash'] ?? null)
        && preg_match('/^[a-f0-9]{64}$/D', $institutionSession['token_hash']) === 1) {
        $userId = (int) $institutionUser['id'];
        $institutionId = (int) $institutionUser['institution_id'];
        $identifier = $institutionSession['identifier'];
        $tokenHash = $institutionSession['token_hash'];
        $statement = $db->prepare(
            'UPDATE user_sessions SET revoked_at = CURRENT_TIMESTAMP
             WHERE user_id = ? AND institution_id = ? AND session_identifier = ?
               AND session_token_hash = ? AND revoked_at IS NULL'
        );
        $statement->bind_param('iiss', $userId, $institutionId, $identifier, $tokenHash);
        $statement->execute();
        $statement->close();
    }

    $platformSessionId = filter_var($_SESSION['platform_admin_session_id'] ?? null, FILTER_VALIDATE_INT);
    $platformSessionToken = $_SESSION['platform_admin_session_token'] ?? null;
    if ($platformSessionId !== false && $platformSessionId !== null
        && is_string($platformSessionToken)
        && preg_match('/^[a-f0-9]{64}$/D', $platformSessionToken) === 1) {
        $platformSessionHash = platform_admin_session_hash($platformSessionToken);
        $statement = $db->prepare(
            'SELECT platform_admin_id FROM platform_sessions
             WHERE id = ? AND session_token_hash = ? AND revoked_at IS NULL
             LIMIT 1 FOR UPDATE'
        );
        $statement->bind_param('is', $platformSessionId, $platformSessionHash);
        $statement->execute();
        $platformSession = $statement->get_result()->fetch_assoc();
        $statement->close();

        if (is_array($platformSession)) {
            $platformAdminId = (int) $platformSession['platform_admin_id'];
            $statement = $db->prepare(
                'UPDATE platform_sessions SET revoked_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND session_token_hash = ? AND revoked_at IS NULL'
            );
            $statement->bind_param('is', $platformSessionId, $platformSessionHash);
            $statement->execute();
            $revoked = $statement->affected_rows === 1;
            $statement->close();

            if ($revoked) {
                write_platform_audit_log(
                    $db,
                    $platformAdminId,
                    'platform.auth.identity_switch',
                    'platform_admin',
                    $platformAdminId,
                    null,
                    ['target_identity' => $targetIdentity],
                    platform_admin_correlation_id(),
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                    filter_var($_SERVER['REMOTE_ADDR'] ?? null, FILTER_VALIDATE_IP) ?: null
                );
            }
        }
    }

    regenerate_session_id();
    unset(
        $_SESSION['user'],
        $_SESSION['auth_session'],
        $_SESSION['auth_issued_at'],
        $_SESSION['admin_selected_institution_id'],
        $_SESSION['platform_admin'],
        $_SESSION['platform_admin_session_id'],
        $_SESSION['platform_admin_session_token'],
        $_SESSION['pending_auth'],
        $_SESSION['otp_modal_message']
    );
}

function platform_admin_bootstrap_initialize(
    mysqli $db,
    string $name,
    string $email,
    string $password,
    string $bootstrapToken
): int {
    $name = trim($name);
    if ($name === '' || strlen($name) > 150) {
        platform_admin_log_bootstrap_failure('invalid_input');
        throw new InvalidArgumentException('Platform administrator name is required.');
    }
    $normalizedEmail = platform_admin_normalize_email($email);
    if (strlen($normalizedEmail) > 255 || !filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
        platform_admin_log_bootstrap_failure('invalid_input');
        throw new InvalidArgumentException('Enter a valid platform administrator email address.');
    }
    if (strlen($password) < 12 || strlen($password) > 72) {
        platform_admin_log_bootstrap_failure('invalid_input');
        throw new InvalidArgumentException('Platform administrator password length is invalid.');
    }

    $expectedToken = platform_admin_bootstrap_token();
    if ($expectedToken === null || !hash_equals($expectedToken, $bootstrapToken)) {
        platform_admin_log_bootstrap_failure('invalid_token');
        throw new RuntimeException('The platform bootstrap token is invalid or not configured.');
    }
    $db->begin_transaction();
    try {
        if (!platform_admin_bootstrap_available($db)) {
            throw new RuntimeException('Platform bootstrap is already complete.');
        }

        $statement = $db->prepare(
            'INSERT INTO platform_admins (
                name, email, password_hash, status, created_by_platform_admin_id, is_initial_bootstrap
             ) VALUES (?, ?, ?, ?, NULL, 1)'
        );
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $status = 'active';
        $statement->bind_param('ssss', $name, $normalizedEmail, $passwordHash, $status);
        $statement->execute();
        $adminId = (int) $db->insert_id;
        $statement->close();

        $grantStatement = $db->prepare(
            'INSERT INTO platform_admin_permission_grants (platform_admin_id, permission_key)
             VALUES (?, ?)'
        );
        foreach (platform_admin_bootstrap_permission_grants() as $permission) {
            $grantStatement->bind_param('is', $adminId, $permission);
            $grantStatement->execute();
        }
        $grantStatement->close();

        $stateStatement = $db->prepare(
            'UPDATE platform_bootstrap_state
             SET initialized_at = CURRENT_TIMESTAMP, initialized_by_platform_admin_id = ?
             WHERE id = 1 AND initialized_at IS NULL'
        );
        $stateStatement->bind_param('i', $adminId);
        $stateStatement->execute();
        $stateUpdated = $stateStatement->affected_rows === 1;
        $stateStatement->close();
        if (!$stateUpdated) {
            throw new RuntimeException('Platform bootstrap is already complete.');
        }

        write_platform_audit_log(
            $db,
            $adminId,
            'platform.bootstrap.created',
            'platform_admin',
            $adminId,
            null,
            [
                'bootstrap' => true,
                'status' => 'active',
            ],
            platform_admin_correlation_id(),
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            filter_var($_SERVER['REMOTE_ADDR'] ?? null, FILTER_VALIDATE_IP) ?: null
        );

        $db->commit();
        return $adminId;
    } catch (Throwable $exception) {
        $db->rollback();
        platform_admin_log_bootstrap_failure('initialize', $exception);
        throw $exception;
    }
}

function platform_admin_session_hash(string $rawToken): string
{
    return platform_admin_session_token_hash($rawToken);
}

final class PlatformAdminLoginRateLimited extends RuntimeException
{
}

function platform_admin_login_rate_limit_policy(): array
{
    return [
        'account_limit' => 5,
        'ip_limit' => 30,
        'window_seconds' => 900,
    ];
}

function platform_admin_login_rate_limit_scopes(string $emailIdentifier, string $ipAddress): array
{
    $packedIpAddress = inet_pton($ipAddress);
    $ipScope = is_string($packedIpAddress) ? bin2hex($packedIpAddress) : 'unknown';

    return [
        [
            'scope_type' => 'account',
            'scope_hash' => hash('sha256', $emailIdentifier),
            'limit' => platform_admin_login_rate_limit_policy()['account_limit'],
        ],
        [
            'scope_type' => 'ip',
            'scope_hash' => hash('sha256', $ipScope),
            'limit' => platform_admin_login_rate_limit_policy()['ip_limit'],
        ],
    ];
}

function platform_admin_login_rate_limit_allows(mysqli $db, array $scopes): bool
{
    $insert = $db->prepare(
        'INSERT INTO platform_login_rate_limits (scope_type, scope_hash, window_started_at, failed_attempts)
         VALUES (?, ?, CURRENT_TIMESTAMP, 0)
         ON DUPLICATE KEY UPDATE scope_hash = VALUES(scope_hash)'
    );
    $lock = $db->prepare(
        'SELECT failed_attempts,
                (window_started_at <= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ? SECOND)) AS window_expired
         FROM platform_login_rate_limits
         WHERE scope_type = ? AND scope_hash = ?
         FOR UPDATE'
    );
    $reset = $db->prepare(
        'UPDATE platform_login_rate_limits
         SET window_started_at = CURRENT_TIMESTAMP, failed_attempts = 0
         WHERE scope_type = ? AND scope_hash = ?'
    );
    $windowSeconds = platform_admin_login_rate_limit_policy()['window_seconds'];

    foreach ($scopes as $scope) {
        $scopeType = $scope['scope_type'];
        $scopeHash = $scope['scope_hash'];
        $insert->bind_param('ss', $scopeType, $scopeHash);
        $insert->execute();
        $lock->bind_param('iss', $windowSeconds, $scopeType, $scopeHash);
        $lock->execute();
        $record = $lock->get_result()->fetch_assoc();
        if (!is_array($record)) {
            $insert->close();
            $lock->close();
            $reset->close();
            throw new RuntimeException('The platform login rate limiter is unavailable.');
        }
        if ((int) $record['window_expired'] === 1) {
            $reset->bind_param('ss', $scopeType, $scopeHash);
            $reset->execute();
            $record['failed_attempts'] = 0;
        }
        if ((int) $record['failed_attempts'] >= (int) $scope['limit']) {
            $insert->close();
            $lock->close();
            $reset->close();
            return false;
        }
    }

    $insert->close();
    $lock->close();
    $reset->close();
    return true;
}

function platform_admin_login_rate_limit_record_failure(mysqli $db, array $scopes): void
{
    $statement = $db->prepare(
        'UPDATE platform_login_rate_limits
         SET failed_attempts = failed_attempts + 1
         WHERE scope_type = ? AND scope_hash = ?'
    );
    foreach ($scopes as $scope) {
        $scopeType = $scope['scope_type'];
        $scopeHash = $scope['scope_hash'];
        $statement->bind_param('ss', $scopeType, $scopeHash);
        $statement->execute();
    }
    $statement->close();
}

function platform_admin_login(
    mysqli $db,
    string $email,
    string $password,
    ?string $userAgent = null,
    ?string $ipAddress = null
): ?array {
    $emailIsBounded = strlen($email) <= 255;
    $normalizedEmail = $emailIsBounded ? platform_admin_normalize_email($email) : '__oversized__';
    $emailIsValid = $emailIsBounded && filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL) !== false;
    $passwordIsBounded = strlen($password) <= 72;
    $normalizedIp = filter_var($ipAddress, FILTER_VALIDATE_IP);
    if ($normalizedIp === false) {
        $normalizedIp = 'unknown';
    }
    $scopes = platform_admin_login_rate_limit_scopes($normalizedEmail, $normalizedIp);
    $sessionRotated = false;
    $db->query(
        'DELETE FROM platform_login_rate_limits
         WHERE window_started_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 DAY)'
    );
    $db->begin_transaction();
    try {
        if (!platform_admin_login_rate_limit_allows($db, $scopes)) {
            $db->commit();
            throw new PlatformAdminLoginRateLimited('Platform login temporarily rate limited.');
        }

        $admin = null;
        if ($emailIsValid && $passwordIsBounded) {
            $statement = $db->prepare(
                'SELECT id, name, email, password_hash, status
                 FROM platform_admins
                 WHERE email = ? LIMIT 1 FOR UPDATE'
            );
            $statement->bind_param('s', $normalizedEmail);
            $statement->execute();
            $admin = $statement->get_result()->fetch_assoc();
            $statement->close();
        }

        static $dummyPasswordHash = null;
        if (!is_string($dummyPasswordHash)) {
            $dummyPasswordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        }
        $passwordMatches = password_verify(
            $passwordIsBounded ? $password : '',
            is_array($admin) ? (string) $admin['password_hash'] : $dummyPasswordHash
        );
        if (!is_array($admin) || !$passwordMatches || (string) $admin['status'] !== 'active') {
            platform_admin_login_rate_limit_record_failure($db, $scopes);
            $db->commit();
            return null;
        }

        $statement = $db->prepare(
            'SELECT permission_key FROM platform_admin_permission_grants
             WHERE platform_admin_id = ? ORDER BY permission_key'
        );
        $adminId = (int) $admin['id'];
        $statement->bind_param('i', $adminId);
        $statement->execute();
        $permissions = [];
        $permissionResult = $statement->get_result();
        while ($grant = $permissionResult->fetch_assoc()) {
            $permission = (string) $grant['permission_key'];
            if (in_array($permission, platform_admin_permission_catalog(), true)) {
                $permissions[] = $permission;
            }
        }
        $statement->close();

        prepare_identity_session($db, 'platform_admin');
        $sessionRotated = true;
        $sessionToken = bin2hex(random_bytes(32));
        $sessionIdentifier = bin2hex(random_bytes(16));
        $sessionHash = platform_admin_session_hash($sessionToken);
        $auditUserAgent = is_string($userAgent) ? substr($userAgent, 0, 512) : '';
        $auditIpAddress = $normalizedIp;
        $statement = $db->prepare(
            'INSERT INTO platform_sessions (
                platform_admin_id, session_identifier, session_token_hash, user_agent, ip_address, expires_at, last_activity_at
             ) VALUES (?, ?, ?, ?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 30 MINUTE), CURRENT_TIMESTAMP)'
        );
        $statement->bind_param('issss', $adminId, $sessionIdentifier, $sessionHash, $auditUserAgent, $auditIpAddress);
        $statement->execute();
        $sessionId = (int) $db->insert_id;
        $statement->close();

        $updateStatement = $db->prepare('UPDATE platform_admins SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?');
        $updateStatement->bind_param('i', $adminId);
        $updateStatement->execute();
        $updateStatement->close();

        write_platform_audit_log(
            $db,
            $adminId,
            'platform.auth.login.success',
            'platform_admin',
            $adminId,
            null,
            ['login_mode' => 'password'],
            platform_admin_correlation_id(),
            $auditUserAgent,
            filter_var($auditIpAddress, FILTER_VALIDATE_IP) ?: null
        );
        $db->commit();

        $_SESSION['platform_admin'] = [
            'id' => $adminId,
            'name' => (string) $admin['name'],
            'email' => (string) $admin['email'],
            'status' => 'active',
            'permissions' => $permissions,
        ];
        $_SESSION['platform_admin_session_id'] = $sessionId;
        $_SESSION['platform_admin_session_token'] = $sessionToken;

        return [
            'id' => $adminId,
            'name' => (string) $admin['name'],
            'email' => (string) $admin['email'],
            'status' => 'active',
            'permissions' => $permissions,
        ];
    } catch (PlatformAdminLoginRateLimited $exception) {
        throw $exception;
    } catch (Throwable $exception) {
        $db->rollback();
        if ($sessionRotated) {
            unset(
                $_SESSION['user'],
                $_SESSION['auth_session'],
                $_SESSION['auth_issued_at'],
                $_SESSION['admin_selected_institution_id'],
                $_SESSION['platform_admin'],
                $_SESSION['platform_admin_session_id'],
                $_SESSION['platform_admin_session_token'],
                $_SESSION['pending_auth'],
                $_SESSION['otp_modal_message']
            );
        }
        throw $exception;
    }
}

function platform_admin_revalidate_session(?mysqli $db = null): bool
{
    if (isset($_SESSION['user']) || isset($_SESSION['auth_session'])) {
        return false;
    }

    $sessionId = filter_var($_SESSION['platform_admin_session_id'] ?? null, FILTER_VALIDATE_INT);
    $sessionToken = $_SESSION['platform_admin_session_token'] ?? null;
    if ($sessionId === false || $sessionId === null || !is_string($sessionToken) || $sessionToken === '') {
        return false;
    }

    $db ??= db_connect();
    $statement = $db->prepare(
        'SELECT ps.id, ps.platform_admin_id, ps.session_token_hash, ps.expires_at, ps.revoked_at,
                pa.name, pa.email, pa.status
         FROM platform_sessions ps
         JOIN platform_admins pa ON pa.id = ps.platform_admin_id
         WHERE ps.id = ? AND ps.revoked_at IS NULL
           AND ps.expires_at > CURRENT_TIMESTAMP
         LIMIT 1'
    );
    $statement->bind_param('i', $sessionId);
    $statement->execute();
    $session = $statement->get_result()->fetch_assoc();
    $statement->close();

    if (!is_array($session)
        || (string) $session['status'] !== 'active'
        || !hash_equals((string) $session['session_token_hash'], platform_admin_session_hash($sessionToken))) {
        return false;
    }

    $permissionStatement = $db->prepare(
        'SELECT permission_key FROM platform_admin_permission_grants
         WHERE platform_admin_id = ? ORDER BY permission_key'
    );
    $adminId = (int) $session['platform_admin_id'];
    $permissionStatement->bind_param('i', $adminId);
    $permissionStatement->execute();
    $grants = [];
    $permissionResult = $permissionStatement->get_result();
    while ($grant = $permissionResult->fetch_assoc()) {
        $permission = (string) $grant['permission_key'];
        if (in_array($permission, platform_admin_permission_catalog(), true)) {
            $grants[] = $permission;
        }
    }
    $permissionStatement->close();

    $_SESSION['platform_admin'] = [
        'id' => $adminId,
        'name' => (string) $session['name'],
        'email' => (string) $session['email'],
        'status' => 'active',
        'permissions' => $grants,
    ];

    $statement = $db->prepare(
        'UPDATE platform_sessions SET last_activity_at = CURRENT_TIMESTAMP WHERE id = ?'
    );
    $statement->bind_param('i', $sessionId);
    $statement->execute();
    $statement->close();

    return true;
}

function platform_admin_logout(?mysqli $db = null): bool
{
    $sessionId = filter_var($_SESSION['platform_admin_session_id'] ?? null, FILTER_VALIDATE_INT);
    $sessionToken = $_SESSION['platform_admin_session_token'] ?? null;
    if ($sessionId === false || $sessionId === null
        || !is_string($sessionToken)
        || preg_match('/^[a-f0-9]{64}$/D', $sessionToken) !== 1) {
        return false;
    }

    $db ??= db_connect();
    if (!platform_admin_revalidate_session($db)) {
        return false;
    }
    $sessionHash = platform_admin_session_hash($sessionToken);
    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            'SELECT platform_admin_id FROM platform_sessions
             WHERE id = ? AND session_token_hash = ? AND revoked_at IS NULL
             LIMIT 1 FOR UPDATE'
        );
        $statement->bind_param('is', $sessionId, $sessionHash);
        $statement->execute();
        $session = $statement->get_result()->fetch_assoc();
        $statement->close();

        if (is_array($session)) {
            $platformAdminId = (int) $session['platform_admin_id'];
            $statement = $db->prepare(
                'UPDATE platform_sessions SET revoked_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND session_token_hash = ? AND revoked_at IS NULL'
            );
            $statement->bind_param('is', $sessionId, $sessionHash);
            $statement->execute();
            $revoked = $statement->affected_rows === 1;
            $statement->close();

            if ($revoked) {
                write_platform_audit_log(
                    $db,
                    $platformAdminId,
                    'platform.auth.logout',
                    'platform_admin',
                    $platformAdminId,
                    null,
                    ['logout' => true],
                    platform_admin_correlation_id(),
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                    filter_var($_SERVER['REMOTE_ADDR'] ?? null, FILTER_VALIDATE_IP) ?: null
                );
            }
        }

        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }

    clear_app_session();
    return true;
}
