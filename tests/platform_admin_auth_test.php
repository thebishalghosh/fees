<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

function platform_test_database_target_is_safe(string $target, array $protectedNames, string $environment): bool
{
    if (in_array(strtolower($environment), ['prod', 'production', 'demo', 'stage', 'staging', 'live'], true)
        || preg_match('/^fees_platform_test_[a-f0-9]{24}$/D', $target) !== 1
        || preg_match('/(?:prod|production|demo|stag(?:e|ing)?|live)/i', $target) === 1) {
        return false;
    }

    foreach ($protectedNames as $protectedName) {
        if (is_string($protectedName) && strcasecmp($target, $protectedName) === 0) {
            return false;
        }
    }

    return true;
}

function platform_test_database_host_is_local(string $host): bool
{
    return in_array(strtolower(trim($host)), ['localhost', '127.0.0.1', '::1'], true);
}

function platform_test_spawn_workers(array $workerSettings, array $databaseConfig, string $testDatabaseName): array
{
    if (!function_exists('proc_open')) {
        throw new RuntimeException('Process workers are unavailable for concurrency tests.');
    }

    $baseEnvironment = getenv();
    $baseEnvironment = is_array($baseEnvironment) ? $baseEnvironment : [];
    $processes = [];
    foreach ($workerSettings as $settings) {
        $environment = array_merge($baseEnvironment, [
            'DB_HOST' => (string) $databaseConfig['host'],
            'DB_PORT' => (string) $databaseConfig['port'],
            'DB_NAME' => $testDatabaseName,
            'DB_USERNAME' => (string) $databaseConfig['username'],
            'DB_PASSWORD' => (string) $databaseConfig['password'],
        ], $settings);
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, __FILE__],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__),
            $environment
        );
        if (!is_resource($process)) {
            throw new RuntimeException('A platform authentication test worker could not start.');
        }
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }

    $results = [];
    foreach ($processes as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $results[] = [
            'output' => is_string($stdout) ? trim($stdout) : '',
            'error' => is_string($stderr) ? trim($stderr) : '',
            'exit_code' => $exitCode,
        ];
    }

    return $results;
}

$testWorkerMode = getenv('FEES_PLATFORM_TEST_WORKER_MODE');
if ($testWorkerMode === 'bootstrap') {
    try {
        platform_admin_bootstrap_initialize(
            db_connect(),
            'Concurrent Platform Admin',
            (string) getenv('FEES_PLATFORM_TEST_WORKER_EMAIL'),
            'concurrent-bootstrap-password-2026',
            (string) platform_admin_bootstrap_token()
        );
        fwrite(STDOUT, 'BOOTSTRAP_SUCCEEDED');
    } catch (Throwable $exception) {
        fwrite(STDOUT, 'BOOTSTRAP_REJECTED');
    }
    exit(0);
}
if ($testWorkerMode === 'login') {
    try {
        $result = platform_admin_login(
            db_connect(),
            (string) getenv('FEES_PLATFORM_TEST_WORKER_EMAIL'),
            'intentionally-wrong-password',
            'Platform Rate Test Worker',
            (string) getenv('FEES_PLATFORM_TEST_WORKER_IP')
        );
        fwrite(STDOUT, $result === null ? 'LOGIN_FAILED' : 'LOGIN_SUCCEEDED');
    } catch (PlatformAdminLoginRateLimited $exception) {
        fwrite(STDOUT, 'LOGIN_RATE_LIMITED');
    }
    exit(0);
}
if ($testWorkerMode === 'logout') {
    $_SERVER['REQUEST_METHOD'] = (string) (getenv('FEES_PLATFORM_TEST_LOGOUT_METHOD') ?: 'GET');
    $csrfSessionToken = getenv('FEES_PLATFORM_TEST_LOGOUT_CSRF_SESSION');
    if (is_string($csrfSessionToken) && $csrfSessionToken !== '') {
        $_SESSION['csrf_token'] = $csrfSessionToken;
    }
    $csrfPostToken = getenv('FEES_PLATFORM_TEST_LOGOUT_CSRF_POST');
    if (is_string($csrfPostToken) && $csrfPostToken !== '') {
        $_POST['csrf_token'] = $csrfPostToken;
    }
    if (getenv('FEES_PLATFORM_TEST_LOGOUT_ID') !== false) {
        $_SESSION['platform_admin'] = [
            'id' => (int) getenv('FEES_PLATFORM_TEST_LOGOUT_ADMIN_ID'),
            'name' => 'Platform Test Admin',
            'email' => 'platform.test@example.invalid',
            'status' => 'active',
            'permissions' => platform_admin_bootstrap_permission_grants(),
        ];
        $_SESSION['platform_admin_session_id'] = (int) getenv('FEES_PLATFORM_TEST_LOGOUT_ID');
        $_SESSION['platform_admin_session_token'] = (string) getenv('FEES_PLATFORM_TEST_LOGOUT_TOKEN');
    }
    if (getenv('FEES_PLATFORM_TEST_LOGOUT_INSTITUTION') === '1') {
        $institutionUserId = (int) getenv('FEES_PLATFORM_TEST_LOGOUT_INSTITUTION_USER_ID');
        $institutionId = (int) getenv('FEES_PLATFORM_TEST_LOGOUT_INSTITUTION_ID');
        $institutionIdentifier = (string) getenv('FEES_PLATFORM_TEST_LOGOUT_INSTITUTION_IDENTIFIER');
        $institutionTokenHash = (string) getenv('FEES_PLATFORM_TEST_LOGOUT_INSTITUTION_TOKEN_HASH');
        $_SESSION['user'] = [
            'id' => $institutionUserId,
            'institution_id' => $institutionId,
            'user_type' => 'admin',
            'status' => 'active',
            'permissions' => [],
        ];
        $_SESSION['auth_session'] = [
            'identifier' => $institutionIdentifier,
            'token_hash' => $institutionTokenHash,
        ];
    }
    require __DIR__ . '/../auth/platform-admin-logout.php';
    exit(0);
}

$checks = 0;
$failures = [];
$integrationStatus = 'SKIP: database integration disabled; set FEES_PLATFORM_TEST_RUN_DB_INTEGRATION=1 to create an isolated disposable database.';
$check = static function (bool $condition, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $label;
    }
};

$databaseConfig = db_config();
$protectedDatabaseNames = array_values(array_filter([
    $databaseConfig['database'],
    getenv('DB_DEMO_NAME') ?: null,
    getenv('DB_STAGING_NAME') ?: null,
    getenv('DB_PRODUCTION_NAME') ?: null,
]));
$configuredEnvironment = (string) (app_config()['env'] ?? 'development');
$check(
    !platform_test_database_target_is_safe(
        (string) $databaseConfig['database'],
        $protectedDatabaseNames,
        $configuredEnvironment
    ),
    'Reject the configured application database before any database setup'
);
$check(
    !platform_test_database_target_is_safe('fees_demo', $protectedDatabaseNames, 'development'),
    'Reject a demo database target before any database setup'
);
$check(
    !platform_test_database_target_is_safe('fees_staging', $protectedDatabaseNames, 'development'),
    'Reject a staging database target before any database setup'
);
$check(
    !platform_test_database_target_is_safe('fees_production', $protectedDatabaseNames, 'development'),
    'Reject a production database target before any database setup'
);
$check(
    !platform_test_database_target_is_safe(
        'fees_platform_test_0123456789abcdef01234567',
        $protectedDatabaseNames,
        'staging'
    ),
    'Reject disposable database setup in a staging environment'
);
$check(
    platform_test_database_target_is_safe(
        'fees_platform_test_0123456789abcdef01234567',
        $protectedDatabaseNames,
        'development'
    ),
    'Accept only the generated disposable database naming format outside production'
);
$check(
    !platform_test_database_host_is_local('mysql.production.example'),
    'Reject non-local database hosts before disposable database setup'
);

$check(
    platform_admin_normalize_email('  Platform.Admin@Example.com  ') === 'platform.admin@example.com',
    'Normalize platform email addresses before comparison'
);
$check(
    platform_admin_session_hash('platform-session-token') === hash('sha256', 'platform-session-token'),
    'Store platform session tokens as hashes, not raw tokens'
);
$check(
    count(platform_admin_permission_catalog()) > 0,
    'Keep the platform permission catalog defined independently from institution RBAC'
);
$check(
    in_array('institutions.view', platform_admin_permission_catalog(), true),
    'Include explicit institution permissions in the platform catalog'
);
$check(
    platform_admin_bootstrap_permission_grants() === [
        'platform_admins.manage',
        'platform_permissions.manage',
        'platform_audit.view',
    ],
    'Restrict bootstrap to the explicitly defined platform-control grants'
);
$check(
    array_intersect(platform_admin_bootstrap_permission_grants(), ['institutions.view', 'institution_support.read']) === [],
    'Do not grant institution-data access during bootstrap'
);
$check(
    in_array('institutions.admin', platform_admin_permission_catalog(), true)
        && !in_array('institutions.admin', platform_admin_bootstrap_permission_grants(), true),
    'Keep full institution-admin access as an explicit platform permission'
);
$_SESSION['platform_admin'] = [
    'id' => 7,
    'name' => 'Platform Admin',
    'email' => 'platform.admin@example.com',
    'status' => 'active',
    'permissions' => ['institutions.view', 'platform_admins.manage'],
];
$check(
    current_platform_admin()['email'] === 'platform.admin@example.com',
    'Expose the authenticated platform admin from session state'
);
$check(
    platform_admin_session_has_permission('institutions.view'),
    'Recognize a permission explicitly present in refreshed platform session state'
);
$check(
    !platform_admin_session_has_permission('institutions.suspend'),
    'Reject a permission not present in the platform session grants'
);
$check(
    !platform_admin_session_has_permission('payments.refund'),
    'Reject unknown or institution-financial permissions by default'
);
unset($_SESSION['platform_admin']);
$check(
    current_platform_admin() === null,
    'Reject an unset platform admin session'
);

$_SESSION = [
    'user' => [
        'id' => 17,
        'institution_id' => 9,
        'user_type' => 'admin',
        'status' => 'active',
        'permissions' => [],
    ],
    'auth_session' => ['identifier' => str_repeat('a', 32), 'token_hash' => str_repeat('b', 64)],
];
$check(
    !platform_admin_revalidate_session(),
    'Reject an institution-only session at the platform validator'
);
$_SESSION = [
    'platform_admin' => [
        'id' => 7,
        'name' => 'Platform Admin',
        'email' => 'platform.admin@example.com',
        'status' => 'active',
        'permissions' => [],
    ],
    'platform_admin_session_id' => 1,
    'platform_admin_session_token' => str_repeat('a', 64),
];
$check(
    !revalidate_authenticated_session(),
    'Reject a platform-only session at the institution validator'
);
$_SESSION['user'] = [
    'id' => 17,
    'institution_id' => 9,
    'user_type' => 'admin',
    'status' => 'active',
    'permissions' => [],
];
$_SESSION['auth_session'] = ['identifier' => str_repeat('a', 32), 'token_hash' => str_repeat('b', 64)];
$check(
    !platform_admin_revalidate_session(),
    'Reject a merged legacy session at the platform validator'
);
$check(
    !revalidate_authenticated_session(),
    'Reject a merged legacy session at the institution validator'
);
$_SESSION = [];
$originalSession = $_SESSION;
$_SESSION = [
    'platform_admin' => [
        'id' => 17,
        'name' => 'Platform Operator',
        'email' => 'platform@example.invalid',
        'status' => 'active',
        'permissions' => ['institutions.admin'],
    ],
    'platform_admin_selected_institution_id' => 42,
];
$platformAdminContext = current_user();
$platformContextIsSeparate = is_array($platformAdminContext)
    && $platformAdminContext['identity_type'] === 'platform_admin'
    && $platformAdminContext['user_type'] === 'super_admin'
    && $platformAdminContext['id'] === 0
    && $platformAdminContext['institution_id'] === 42
    && !isset($_SESSION['user'])
    && admin_selected_institution_id() === 42
    && user_has_permission('fees.delete')
    && user_can_access_institution(42)
    && !user_can_access_institution(43);
$_SESSION = $originalSession;
$check(
    $platformContextIsSeparate,
    'Provide selected-institution compatibility context without creating an institution user session'
);

$csrfToken = csrf_token();
$check(verify_csrf_token($csrfToken), 'Accept a valid platform logout CSRF token');
$check(!verify_csrf_token(null), 'Reject a missing platform logout CSRF token');
$check(!verify_csrf_token('not-a-csrf-token'), 'Reject a malformed platform logout CSRF token');
regenerate_session_id();
$check(!verify_csrf_token($csrfToken), 'Reject a stale CSRF token after session rotation');

$ratePolicy = platform_admin_login_rate_limit_policy();
$check(
    $ratePolicy === ['account_limit' => 5, 'ip_limit' => 30, 'window_seconds' => 900],
    'Use bounded account and IP failure windows'
);
$accountScope = platform_admin_login_rate_limit_scopes('admin@example.invalid', '192.0.2.1');
$differentAccountScope = platform_admin_login_rate_limit_scopes('other@example.invalid', '192.0.2.1');
$check(
    $accountScope[0]['scope_hash'] !== $differentAccountScope[0]['scope_hash']
        && $accountScope[1]['scope_hash'] === $differentAccountScope[1]['scope_hash'],
    'Separate account buckets while sharing the client IP bucket'
);
$check(
    platform_admin_login_rate_limit_scopes('same@example.invalid', '2001:0db8:0:0:0:0:0:1')[1]['scope_hash']
        === platform_admin_login_rate_limit_scopes('same@example.invalid', '2001:db8::1')[1]['scope_hash'],
    'Canonicalize equivalent IPv6 addresses into the same IP rate bucket'
);

$adminAuthSource = file_get_contents(__DIR__ . '/../services/admin-password-auth-service.php');
$otpAuthSource = file_get_contents(__DIR__ . '/../services/otp-service.php');
$loginEndpointSource = file_get_contents(__DIR__ . '/../auth/platform-admin-login.php');
$logoutEndpointSource = file_get_contents(__DIR__ . '/../auth/platform-admin-logout.php');
$bootstrapEndpointSource = file_get_contents(__DIR__ . '/../auth/platform-admin-bootstrap.php');
$platformDashboardSource = file_get_contents(__DIR__ . '/../platform-admin/dashboard.php');
$appScriptSource = file_get_contents(__DIR__ . '/../public/assets/js/app.js');
$authSource = file_get_contents(__DIR__ . '/../includes/auth.php');
$sidebarSource = file_get_contents(__DIR__ . '/../includes/layouts/sidebar.php');
$auditServiceSource = file_get_contents(__DIR__ . '/../services/audit-service.php');
$sharedPageSource = file_get_contents(__DIR__ . '/../includes/layouts/page.php');
$check(
    is_string($adminAuthSource) && str_contains($adminAuthSource, 'prepare_identity_session($db, \'institution_user\')'),
    'Apply identity isolation to institution password authentication'
);
$check(
    is_string($otpAuthSource) && str_contains($otpAuthSource, 'prepare_identity_session($db, \'institution_user\')'),
    'Apply identity isolation to institution OTP authentication'
);
$check(
    is_string($loginEndpointSource)
        && str_contains($loginEndpointSource, 'PlatformAdminLoginRateLimited')
        && str_contains($loginEndpointSource, 'http_response_code(429)')
        && str_contains($loginEndpointSource, "header('Retry-After: 900')"),
    'Return a temporary generic response when platform login is throttled'
);
$check(
    is_string($loginEndpointSource)
        && str_contains($loginEndpointSource, "safe_redirect('/platform-admin/dashboard')"),
    'Redirect successful platform logins to their own workspace'
);
$check(
    is_string($platformDashboardSource)
        && str_contains($platformDashboardSource, 'require_platform_admin()')
        && str_contains($platformDashboardSource, 'render_app_page_start(')
        && str_contains($platformDashboardSource, 'institution_onboarding_list(')
        && str_contains($platformDashboardSource, 'Open workspace')
        && str_contains($platformDashboardSource, "institutions.create")
        && str_contains($platformDashboardSource, "institutions.update")
        && str_contains($platformDashboardSource, "institutions.suspend"),
    'Render the institution manager inside the shared Platform Admin workspace'
);
$check(
    is_string($platformDashboardSource)
        && str_contains($platformDashboardSource, '($_GET[\'select_institution\'] ?? null) === \'1\'')
        && str_contains($platformDashboardSource, 'data-auto-show-toast')
        && str_contains($platformDashboardSource, 'Select an institution to open its admin workspace.')
        && is_string($appScriptSource)
        && str_contains($appScriptSource, "querySelectorAll('[data-auto-show-toast]')")
        && str_contains($appScriptSource, 'window.bootstrap.Toast.getOrCreateInstance(toastElement).show()'),
    'Show a toast when Platform Admin is redirected to select an institution'
);
$check(
    is_string($sidebarSource)
        && str_contains($sidebarSource, 'current_platform_admin() !== null')
        && str_contains($sidebarSource, "'/platform-admin/dashboard'")
        && is_string($sharedPageSource)
        && str_contains($sharedPageSource, 'Platform administrator workspace')
        && str_contains($sharedPageSource, 'full access to its admin workspace')
        && str_contains($sharedPageSource, 'app-page-platform-admin'),
    'Expose the shared institution admin navigation and selector to Platform Admin'
);
$check(
    is_string($auditServiceSource)
        && str_contains($auditServiceSource, 'platform.institution_admin.')
        && str_contains($auditServiceSource, 'platform_admin_selected_institution_id()')
        && str_contains($auditServiceSource, 'institution_audit_actor_user_id'),
    'Keep institution actions scoped and audit them as Platform Admin, not as a user row'
);
$check(
    is_string($authSource)
        && str_contains($authSource, 'platform_admin_select_institution(db_connect(), $_GET[\'institution_id\'])')
        && str_contains($authSource, "status <> 'archived'")
        && str_contains($authSource, 'platform_admin_selected_institution_id'),
    'Validate the selected tenant for Platform Admin routes and keep selection in its separate session'
);
$check(
    is_string($logoutEndpointSource)
        && str_contains($logoutEndpointSource, "require_http_methods(['POST'])")
        && str_contains($logoutEndpointSource, 'require_valid_csrf_token()')
        && str_contains($logoutEndpointSource, 'require_platform_admin()')
        && strpos($logoutEndpointSource, 'require_valid_csrf_token()') < strpos($logoutEndpointSource, 'require_platform_admin()')
        && strpos($logoutEndpointSource, 'require_platform_admin()') < strpos($logoutEndpointSource, 'platform_admin_logout()'),
    'Require POST, CSRF, and active platform identity before logout'
);
$check(
    is_string($bootstrapEndpointSource)
        && str_contains($bootstrapEndpointSource, 'platform_admin_log_bootstrap_failure')
        && str_contains($bootstrapEndpointSource, 'Bootstrap is unavailable.'),
    'Log bootstrap failures without exposing account or token details'
);

if (getenv('FEES_PLATFORM_TEST_RUN_DB_INTEGRATION') === '1') {
    $testDatabaseName = 'fees_platform_test_' . bin2hex(random_bytes(12));
    $testEnvironment = (string) (app_config()['env'] ?? 'development');
    $server = null;
    $db = null;
    $databaseCreatedByThisRun = false;
    $originalBootstrapToken = getenv('FEES_PLATFORM_BOOTSTRAP_TOKEN');

    try {
        if (!platform_test_database_target_is_safe($testDatabaseName, $protectedDatabaseNames, $testEnvironment)
            || !platform_test_database_host_is_local((string) $databaseConfig['host'])) {
            throw new RuntimeException('Disposable database target or host failed safety checks.');
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $server = new mysqli(
            (string) $databaseConfig['host'],
            (string) $databaseConfig['username'],
            (string) $databaseConfig['password'],
            '',
            (int) $databaseConfig['port']
        );
        $server->set_charset('utf8mb4');
        $server->query('CREATE DATABASE `' . $testDatabaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $databaseCreatedByThisRun = true;
        $db = new mysqli(
            (string) $databaseConfig['host'],
            (string) $databaseConfig['username'],
            (string) $databaseConfig['password'],
            $testDatabaseName,
            (int) $databaseConfig['port']
        );
        $db->set_charset('utf8mb4');
        $db->query(
            "CREATE TABLE institutions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(255) NOT NULL,
                status ENUM('active','inactive','trial','archived') NOT NULL DEFAULT 'active',
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        foreach ([
            __DIR__ . '/../database/migrations/20261104_add_platform_admin_identity.sql',
            __DIR__ . '/../database/migrations/20261104_harden_platform_admin_security.sql',
        ] as $migrationPath) {
            $migration = file_get_contents($migrationPath);
            if (!is_string($migration)) {
                throw new RuntimeException('Platform migration file could not be read.');
            }
            foreach (array_filter(array_map('trim', explode(';', $migration))) as $statementSql) {
                $db->query($statementSql);
            }
        }
        $db->query(
            'CREATE TABLE user_sessions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                institution_id BIGINT UNSIGNED NOT NULL,
                session_identifier CHAR(32) NOT NULL,
                session_token_hash CHAR(64) NOT NULL,
                user_agent VARCHAR(512) NULL,
                ip_address VARCHAR(45) NULL,
                expires_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                revoked_at DATETIME NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $db->query(
            "CREATE TABLE roles (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(80) NOT NULL,
                is_system TINYINT(1) NOT NULL DEFAULT 1,
                institution_id BIGINT UNSIGNED NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $db->query(
            "CREATE TABLE permissions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                permission_key VARCHAR(125) NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $db->query(
            'CREATE TABLE role_permissions (
                role_id BIGINT UNSIGNED NOT NULL,
                permission_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (role_id, permission_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $db->query(
            "CREATE TABLE users (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                institution_id BIGINT UNSIGNED NOT NULL,
                role_id BIGINT UNSIGNED NOT NULL,
                user_type ENUM('super_admin','admin','staff','parent') NOT NULL,
                full_name VARCHAR(150) NOT NULL,
                username VARCHAR(100) NULL,
                email VARCHAR(255) NULL,
                password_hash VARCHAR(255) NULL,
                status ENUM('pending','active','inactive','suspended','blocked') NOT NULL DEFAULT 'active',
                last_login_at DATETIME NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $institutionName = 'Test Institution';
        $statement = $db->prepare('INSERT INTO institutions (name) VALUES (?)');
        $statement->bind_param('s', $institutionName);
        $statement->execute();
        $institutionId = (int) $db->insert_id;
        $statement->close();

        putenv('FEES_PLATFORM_BOOTSTRAP_TOKEN=platform-test-bootstrap-token');
        $db->query(
            "CREATE TRIGGER reject_platform_audit BEFORE INSERT ON platform_audit_logs
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected audit failure'"
        );
        try {
            platform_admin_bootstrap_initialize(
                $db,
                'Bootstrap Test Admin',
                'bootstrap.test@example.invalid',
                'bootstrap-test-password-2026',
                'platform-test-bootstrap-token'
            );
            $check(false, 'Fail bootstrap when its audit write fails');
        } catch (mysqli_sql_exception $exception) {
            $result = $db->query('SELECT COUNT(*) FROM platform_admins');
            $adminCount = (int) $result->fetch_row()[0];
            $state = $db->query('SELECT initialized_at FROM platform_bootstrap_state WHERE id = 1')->fetch_assoc();
            $grantCount = (int) $db->query('SELECT COUNT(*) FROM platform_admin_permission_grants')->fetch_row()[0];
            $check(
                $adminCount === 0 && $state['initialized_at'] === null && $grantCount === 0,
                'Roll back bootstrap identity, grants, and state when its audit write fails'
            );
        }
        $db->query('DROP TRIGGER reject_platform_audit');

        try {
            platform_admin_bootstrap_initialize(
                $db,
                'Invalid Token Admin',
                'invalid-token@example.invalid',
                'invalid-token-password-2026',
                'incorrect-bootstrap-token'
            );
            $check(false, 'Reject an invalid bootstrap token');
        } catch (RuntimeException $exception) {
            $adminCount = (int) $db->query('SELECT COUNT(*) FROM platform_admins')->fetch_row()[0];
            $check($adminCount === 0, 'Do not create an account when the bootstrap token is invalid');
        }

        $db->query(
            "CREATE TRIGGER slow_platform_bootstrap BEFORE INSERT ON platform_admins
             FOR EACH ROW DO SLEEP(1)"
        );
        $adminName = 'Concurrent Platform Admin';
        $adminEmail = 'platform.concurrent@example.invalid';
        $adminPassword = 'concurrent-bootstrap-password-2026';
        $bootstrapWorkers = platform_test_spawn_workers([
            [
                'FEES_PLATFORM_TEST_WORKER_MODE' => 'bootstrap',
                'FEES_PLATFORM_TEST_WORKER_EMAIL' => $adminEmail,
                'FEES_PLATFORM_BOOTSTRAP_TOKEN' => 'platform-test-bootstrap-token',
            ],
            [
                'FEES_PLATFORM_TEST_WORKER_MODE' => 'bootstrap',
                'FEES_PLATFORM_TEST_WORKER_EMAIL' => $adminEmail,
                'FEES_PLATFORM_BOOTSTRAP_TOKEN' => 'platform-test-bootstrap-token',
            ],
        ], $databaseConfig, $testDatabaseName);
        $db->query('DROP TRIGGER slow_platform_bootstrap');
        $bootstrapResults = array_column($bootstrapWorkers, 'output');
        sort($bootstrapResults);
        $adminCount = (int) $db->query('SELECT COUNT(*) FROM platform_admins')->fetch_row()[0];
        $initialAdminCount = (int) $db->query('SELECT COUNT(*) FROM platform_admins WHERE is_initial_bootstrap = 1')->fetch_row()[0];
        $state = $db->query('SELECT initialized_at FROM platform_bootstrap_state WHERE id = 1')->fetch_assoc();
        $check(
            $bootstrapResults === ['BOOTSTRAP_REJECTED', 'BOOTSTRAP_SUCCEEDED']
                && $adminCount === 1
                && $initialAdminCount === 1
                && $state['initialized_at'] !== null,
            'Serialize simultaneous first-bootstrap requests and create exactly one initial admin'
        );

        $adminQuery = $db->prepare(
            'SELECT id, name, email, password_hash, status
             FROM platform_admins WHERE is_initial_bootstrap = 1'
        );
        $adminQuery->execute();
        $bootstrapAdmin = $adminQuery->get_result()->fetch_assoc();
        $adminQuery->close();
        $platformAdminId = (int) $bootstrapAdmin['id'];
        $adminName = (string) $bootstrapAdmin['name'];
        $adminEmail = (string) $bootstrapAdmin['email'];
        $adminPasswordHash = (string) $bootstrapAdmin['password_hash'];
        $adminStatus = (string) $bootstrapAdmin['status'];
        $adminPassword = 'concurrent-bootstrap-password-2026';
        $initialGrants = $db->query(
            'SELECT permission_key FROM platform_admin_permission_grants
             WHERE platform_admin_id = ' . $platformAdminId . ' ORDER BY permission_key'
        )->fetch_all(MYSQLI_ASSOC);
        $initialGrants = array_column($initialGrants, 'permission_key');
        $expectedInitialGrants = platform_admin_bootstrap_permission_grants();
        sort($expectedInitialGrants);
        $check(
            $initialGrants === $expectedInitialGrants
                && !in_array('institutions.view', $initialGrants, true)
                && !in_array('institution_support.read', $initialGrants, true),
            'Assign only the documented initial grants to a bootstrapped administrator'
        );

        try {
            platform_admin_bootstrap_initialize(
                $db,
                'Replacement Admin',
                'replacement@example.invalid',
                'replacement-admin-password-2026',
                'platform-test-bootstrap-token'
            );
            $check(false, 'Reject a second bootstrap request');
        } catch (RuntimeException $exception) {
            $adminCount = (int) $db->query('SELECT COUNT(*) FROM platform_admins')->fetch_row()[0];
            $currentAdmin = $db->query(
                'SELECT name, email, password_hash FROM platform_admins WHERE id = ' . $platformAdminId
            )->fetch_assoc();
            $check(
                $adminCount === 1
                    && $currentAdmin['name'] === $adminName
                    && $currentAdmin['email'] === $adminEmail
                    && hash_equals($adminPasswordHash, (string) $currentAdmin['password_hash']),
                'Reject second bootstrap without replacing or resetting the existing administrator'
            );
        }

        $noContextAction = 'platform.test.audit.no_context';
        write_platform_audit_log($db, $platformAdminId, $noContextAction, 'test', null, null, ['bootstrap' => false]);
        $statement = $db->prepare('SELECT target_institution_id IS NULL FROM platform_audit_logs WHERE action = ?');
        $statement->bind_param('s', $noContextAction);
        $statement->execute();
        $nullContextStored = (int) $statement->get_result()->fetch_column() === 1;
        $statement->close();
        $check($nullContextStored, 'Store an audit event with absent institution context as SQL NULL');

        $validContextAction = 'platform.test.audit.valid_context';
        write_platform_audit_log($db, $platformAdminId, $validContextAction, 'test', null, $institutionId);
        $statement = $db->prepare('SELECT target_institution_id = ? FROM platform_audit_logs WHERE action = ?');
        $statement->bind_param('is', $institutionId, $validContextAction);
        $statement->execute();
        $validContextStored = (int) $statement->get_result()->fetch_column() === 1;
        $statement->close();
        $check($validContextStored, 'Store a valid institution foreign key in an audit event');

        $auditCountResult = $db->query('SELECT COUNT(*) FROM platform_audit_logs');
        $auditCountBeforeInvalid = (int) $auditCountResult->fetch_row()[0];
        try {
            write_platform_audit_log($db, $platformAdminId, 'platform.test.audit.invalid_context', 'test', null, 999999999);
            $check(false, 'Reject an invalid non-null institution foreign key');
        } catch (mysqli_sql_exception $exception) {
            $auditCountResult = $db->query('SELECT COUNT(*) FROM platform_audit_logs');
            $check((int) $auditCountResult->fetch_row()[0] === $auditCountBeforeInvalid, 'Do not write an audit row with an invalid institution foreign key');
        }
        try {
            write_platform_audit_log($db, $platformAdminId, 'platform.test.audit.secret', 'test', null, null, ['password' => 'must-not-be-stored']);
            $check(false, 'Reject secrets in platform audit metadata');
        } catch (InvalidArgumentException $exception) {
            $check(true, 'Reject secrets in platform audit metadata');
        }

        $db->query(
            "CREATE TRIGGER reject_platform_audit BEFORE INSERT ON platform_audit_logs
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected audit failure'"
        );
        $_SESSION = [];
        try {
            platform_admin_login($db, $adminEmail, $adminPassword, 'Platform Test Agent', '127.0.0.1');
            $check(false, 'Fail platform login when its audit write fails');
        } catch (mysqli_sql_exception $exception) {
            $sessionCount = (int) $db->query('SELECT COUNT(*) FROM platform_sessions')->fetch_row()[0];
            $check($sessionCount === 0 && current_platform_admin() === null, 'Roll back platform session and auth state when login audit fails');
        }
        $db->query('DROP TRIGGER reject_platform_audit');

        $institutionRoleName = 'admin';
        $statement = $db->prepare('INSERT INTO roles (name) VALUES (?)');
        $statement->bind_param('s', $institutionRoleName);
        $statement->execute();
        $roleId = (int) $db->insert_id;
        $statement->close();
        $institutionPassword = 'institution-test-password-2026';
        $institutionPasswordHash = password_hash($institutionPassword, PASSWORD_DEFAULT);
        $institutionUsername = 'institution.test';
        $institutionEmail = 'institution.test@example.invalid';
        $institutionFullName = 'Institution Test Admin';
        $institutionUserType = 'admin';
        $institutionStatus = 'active';
        $statement = $db->prepare(
            'INSERT INTO users (institution_id, role_id, user_type, full_name, username, email, password_hash, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->bind_param(
            'iissssss',
            $institutionId,
            $roleId,
            $institutionUserType,
            $institutionFullName,
            $institutionUsername,
            $institutionEmail,
            $institutionPasswordHash,
            $institutionStatus
        );
        $statement->execute();
        $statement->close();
        require_once __DIR__ . '/../services/admin-password-auth-service.php';

        $_SESSION = [];
        $oldPhpSessionId = session_id();
        $platformLogin = platform_admin_login($db, $adminEmail, $adminPassword, 'Platform Test Agent', '127.0.0.1');
        $platformSessionId = (int) $_SESSION['platform_admin_session_id'];
        $check(
            is_array($platformLogin)
                && session_id() !== $oldPhpSessionId
                && current_platform_admin() !== null
                && current_user() === null,
            'Authenticate from an unauthenticated session, rotate its ID, and establish only platform identity'
        );
        $check(platform_admin_revalidate_session($db), 'Revalidate a valid platform session');
        $check(
            platform_admin_has_permission('platform_admins.manage', $db)
                && platform_admin_has_permission('platform_permissions.manage', $db)
                && !platform_admin_has_permission('institutions.view', $db)
                && !platform_admin_has_permission('institution_support.read', $db),
            'Allow only explicit bootstrap control grants and deny institution-data permissions'
        );
        $check(
            grant_platform_admin_permission($db, $platformAdminId, 'institutions.view')
                && platform_admin_has_permission('institutions.view', $db),
            'Allow a platform operation after an explicit permission grant'
        );
        $check(
            revoke_platform_admin_permission($db, $platformAdminId, 'institutions.view')
                && !platform_admin_has_permission('institutions.view', $db),
            'Stop a revoked permission immediately on the same session'
        );
        $check(
            !platform_admin_has_permission('students.view', $db)
                && current_user() === null
                && authenticated_institution_id(['admin']) === null,
            'Reject unknown platform permissions and prevent platform permission from granting institution access'
        );

        $platformSessionState = $_SESSION;
        $_SESSION = [
            'user' => [
                'id' => 31,
                'institution_id' => $institutionId,
                'user_type' => 'admin',
                'status' => 'active',
                'permissions' => ['platform_permissions.manage'],
            ],
            'auth_session' => ['identifier' => str_repeat('a', 32), 'token_hash' => str_repeat('b', 64)],
        ];
        try {
            grant_platform_admin_permission($db, $platformAdminId, 'institutions.view');
            $check(false, 'Reject institution role as a source of platform permission authority');
        } catch (RuntimeException $exception) {
            $check(true, 'Reject institution role as a source of platform permission authority');
        }
        $_SESSION = $platformSessionState;

        $accountRateIp = '198.51.100.40';
        $failedAttempts = 0;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            if (platform_admin_login($db, $adminEmail, 'incorrect-platform-password', null, $accountRateIp) === null) {
                $failedAttempts++;
            }
        }
        $accountRateLimited = false;
        try {
            platform_admin_login($db, $adminEmail, 'incorrect-platform-password', null, $accountRateIp);
        } catch (PlatformAdminLoginRateLimited $exception) {
            $accountRateLimited = true;
        }
        $check($failedAttempts === 5 && $accountRateLimited, 'Throttle repeated incorrect credentials by account identifier');

        $differentAccountWasNotThrottled = false;
        try {
            $differentAccountWasNotThrottled = platform_admin_login(
                $db,
                'another-platform-admin@example.invalid',
                'incorrect-platform-password',
                null,
                '198.51.100.41'
            ) === null;
        } catch (PlatformAdminLoginRateLimited $exception) {
            $differentAccountWasNotThrottled = false;
        }
        $check($differentAccountWasNotThrottled, 'Keep separate account identifiers in separate failure buckets');

        foreach (platform_admin_login_rate_limit_scopes($adminEmail, $accountRateIp) as $scope) {
            $scopeType = $scope['scope_type'];
            $scopeHash = $scope['scope_hash'];
            $statement = $db->prepare(
                'UPDATE platform_login_rate_limits
                 SET window_started_at = DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 16 MINUTE)
                 WHERE scope_type = ? AND scope_hash = ?'
            );
            $statement->bind_param('ss', $scopeType, $scopeHash);
            $statement->execute();
            $statement->close();
        }
        $expiredAttempt = platform_admin_login($db, $adminEmail, 'incorrect-platform-password', null, $accountRateIp);
        $accountScope = platform_admin_login_rate_limit_scopes($adminEmail, $accountRateIp)[0];
        $statement = $db->prepare(
            'SELECT failed_attempts FROM platform_login_rate_limits WHERE scope_type = ? AND scope_hash = ?'
        );
        $statement->bind_param('ss', $accountScope['scope_type'], $accountScope['scope_hash']);
        $statement->execute();
        $failedCountAfterExpiry = (int) $statement->get_result()->fetch_column();
        $statement->close();
        $beforeSuccessfulLogin = session_id();
        $correctCredentials = platform_admin_login($db, $adminEmail, $adminPassword, 'Platform Test Agent', $accountRateIp);
        $statement = $db->prepare(
            'SELECT failed_attempts FROM platform_login_rate_limits WHERE scope_type = ? AND scope_hash = ?'
        );
        $statement->bind_param('ss', $accountScope['scope_type'], $accountScope['scope_hash']);
        $statement->execute();
        $failedCountAfterSuccess = (int) $statement->get_result()->fetch_column();
        $statement->close();
        $check(
            $expiredAttempt === null
                && $failedCountAfterExpiry === 1
                && is_array($correctCredentials)
                && session_id() !== $beforeSuccessfulLogin
                && $failedCountAfterSuccess === $failedCountAfterExpiry,
            'Expire throttling windows and do not count successful authentication as a failure'
        );
        $check(platform_admin_logout($db), 'Revoke the initial platform login session');
        start_app_session();
        $_SESSION = [];

        $ipLimit = '203.0.113.20';
        $ipScope = platform_admin_login_rate_limit_scopes('ip-limit-first@example.invalid', $ipLimit)[1];
        $statement = $db->prepare(
            'INSERT INTO platform_login_rate_limits (scope_type, scope_hash, window_started_at, failed_attempts)
             VALUES (?, ?, CURRENT_TIMESTAMP, 29)
             ON DUPLICATE KEY UPDATE window_started_at = CURRENT_TIMESTAMP, failed_attempts = 29'
        );
        $statement->bind_param('ss', $ipScope['scope_type'], $ipScope['scope_hash']);
        $statement->execute();
        $statement->close();
        $ipAttempt = platform_admin_login($db, 'ip-limit-first@example.invalid', 'incorrect-platform-password', null, $ipLimit);
        $ipLimitApplied = false;
        try {
            platform_admin_login($db, 'ip-limit-second@example.invalid', 'incorrect-platform-password', null, $ipLimit);
        } catch (PlatformAdminLoginRateLimited $exception) {
            $ipLimitApplied = true;
        }
        $check($ipAttempt === null && $ipLimitApplied, 'Apply IP-wide limits across different account identifiers');

        $concurrentIp = '203.0.113.21';
        $concurrentIpScope = platform_admin_login_rate_limit_scopes('concurrent-ip-a@example.invalid', $concurrentIp)[1];
        $statement = $db->prepare(
            'INSERT INTO platform_login_rate_limits (scope_type, scope_hash, window_started_at, failed_attempts)
             VALUES (?, ?, CURRENT_TIMESTAMP, 29)
             ON DUPLICATE KEY UPDATE window_started_at = CURRENT_TIMESTAMP, failed_attempts = 29'
        );
        $statement->bind_param('ss', $concurrentIpScope['scope_type'], $concurrentIpScope['scope_hash']);
        $statement->execute();
        $statement->close();
        $loginWorkers = platform_test_spawn_workers([
            [
                'FEES_PLATFORM_TEST_WORKER_MODE' => 'login',
                'FEES_PLATFORM_TEST_WORKER_EMAIL' => 'concurrent-ip-a@example.invalid',
                'FEES_PLATFORM_TEST_WORKER_IP' => $concurrentIp,
            ],
            [
                'FEES_PLATFORM_TEST_WORKER_MODE' => 'login',
                'FEES_PLATFORM_TEST_WORKER_EMAIL' => 'concurrent-ip-b@example.invalid',
                'FEES_PLATFORM_TEST_WORKER_IP' => $concurrentIp,
            ],
        ], $databaseConfig, $testDatabaseName);
        $loginWorkerResults = array_column($loginWorkers, 'output');
        sort($loginWorkerResults);
        $statement = $db->prepare(
            'SELECT failed_attempts FROM platform_login_rate_limits WHERE scope_type = ? AND scope_hash = ?'
        );
        $statement->bind_param('ss', $concurrentIpScope['scope_type'], $concurrentIpScope['scope_hash']);
        $statement->execute();
        $concurrentIpFailures = (int) $statement->get_result()->fetch_column();
        $statement->close();
        $check(
            $loginWorkerResults === ['LOGIN_FAILED', 'LOGIN_RATE_LIMITED'] && $concurrentIpFailures === 30,
            'Serialize simultaneous failed logins so they cannot exceed the IP threshold'
        );

        $secondPlatformSessionId = null;
        $secondToken = bin2hex(random_bytes(32));
        $secondIdentifier = bin2hex(random_bytes(16));
        $secondHash = platform_admin_session_hash($secondToken);
        $statement = $db->prepare(
            'INSERT INTO platform_sessions (platform_admin_id, session_identifier, session_token_hash, expires_at)
               VALUES (?, ?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 30 MINUTE))'
        );
           $statement->bind_param('iss', $platformAdminId, $secondIdentifier, $secondHash);
        $statement->execute();
        $secondPlatformSessionId = (int) $db->insert_id;
        $statement->close();

        $_SESSION['user'] = [
            'id' => 31,
            'institution_id' => $institutionId,
            'user_type' => 'admin',
            'status' => 'active',
            'permissions' => [],
        ];
        $_SESSION['auth_session'] = [
            'identifier' => bin2hex(random_bytes(16)),
            'token_hash' => hash('sha256', session_id()),
        ];
        $institutionSessionIdentifier = $_SESSION['auth_session']['identifier'];
        $institutionSessionHash = $_SESSION['auth_session']['token_hash'];
        $institutionSessionUserId = 31;
        $statement = $db->prepare(
            'INSERT INTO user_sessions (user_id, institution_id, session_identifier, session_token_hash)
             VALUES (?, ?, ?, ?)'
        );
        $statement->bind_param('iiss', $institutionSessionUserId, $institutionId, $institutionSessionIdentifier, $institutionSessionHash);
        $statement->execute();
        $statement->close();
        unset($_SESSION['platform_admin'], $_SESSION['platform_admin_session_id'], $_SESSION['platform_admin_session_token']);
        $oldPhpSessionId = session_id();
        $platformLoginAfterInstitution = platform_admin_login($db, $adminEmail, $adminPassword, 'Platform Test Agent', '127.0.0.1');
        $statement = $db->prepare('SELECT revoked_at IS NOT NULL FROM user_sessions WHERE session_identifier = ?');
        $statement->bind_param('s', $institutionSessionIdentifier);
        $statement->execute();
        $institutionRevoked = (int) $statement->get_result()->fetch_column() === 1;
        $statement->close();
        $check(
            is_array($platformLoginAfterInstitution)
                && session_id() !== $oldPhpSessionId
                && current_user() === null
                && $institutionRevoked,
            'Replace institution identity with platform identity, rotate ID, and revoke the institution session'
        );
        $platformLoginAfterInstitutionSessionId = (int) $_SESSION['platform_admin_session_id'];
        $platformLoginAfterInstitutionToken = (string) $_SESSION['platform_admin_session_token'];

        $_SESSION = [
            'user' => [
                'id' => 31,
                'institution_id' => $institutionId,
                'user_type' => 'admin',
                'status' => 'active',
                'permissions' => [],
            ],
            'auth_session' => [
                'identifier' => bin2hex(random_bytes(16)),
                'token_hash' => hash('sha256', session_id()),
            ],
        ];
        $check(
            !platform_admin_revalidate_session($db),
            'Reject institution session state at the platform session validator'
        );
        $_SESSION = [
            'platform_admin' => [
                'id' => $platformAdminId,
                'name' => $adminName,
                'email' => $adminEmail,
                'status' => 'active',
                'permissions' => platform_admin_permission_catalog(),
            ],
            'platform_admin_session_id' => $platformLoginAfterInstitutionSessionId,
            'platform_admin_session_token' => str_repeat('0', 64),
        ];
        $check(
            !revalidate_authenticated_session(),
            'Reject platform session state at the institution session validator'
        );

        $_SESSION = [
            'platform_admin' => $platformLoginAfterInstitution,
            'platform_admin_session_id' => $platformLoginAfterInstitutionSessionId,
            'platform_admin_session_token' => $platformLoginAfterInstitutionToken,
        ];
        $institutionLoginPhpSessionId = session_id();
        $institutionLoginResult = admin_auth_password_login($db, $institutionUsername, $institutionPassword);
        $platformRevocationCheck = $db->prepare('SELECT revoked_at IS NOT NULL FROM platform_sessions WHERE id = ?');
        $platformRevocationCheck->bind_param('i', $platformLoginAfterInstitutionSessionId);
        $platformRevocationCheck->execute();
        $platformSessionRevoked = (int) $platformRevocationCheck->get_result()->fetch_column() === 1;
        $platformRevocationCheck->close();
        $check(
            $institutionLoginResult === 'authenticated'
                && session_id() !== $institutionLoginPhpSessionId
                && current_platform_admin() === null
                && current_user() !== null
                && $platformSessionRevoked,
            'Replace platform identity with institution identity, rotate ID, and revoke the platform session'
        );

        $institutionAuthSession = $_SESSION['auth_session'];
        $institutionUser = $_SESSION['user'];
        $institutionSessionBeforeLogoutAttempt = $_SESSION;
        $institutionLogoutSessionId = $institutionAuthSession['identifier'];
        $institutionLogoutCheck = $db->prepare(
            'SELECT revoked_at IS NULL FROM user_sessions WHERE session_identifier = ?'
        );
        $institutionLogoutCheck->bind_param('s', $institutionLogoutSessionId);
        $institutionLogoutCheck->execute();
        $institutionSessionWasActive = (int) $institutionLogoutCheck->get_result()->fetch_column() === 1;
        $institutionLogoutCheck->close();
        $check(
            !platform_admin_logout($db)
                && $_SESSION === $institutionSessionBeforeLogoutAttempt
                && $institutionSessionWasActive,
            'Do not revoke or clear an institution session through the platform logout helper'
        );

        $getLogout = platform_test_spawn_workers([[
            'FEES_PLATFORM_TEST_WORKER_MODE' => 'logout',
            'FEES_PLATFORM_TEST_LOGOUT_METHOD' => 'GET',
        ]], $databaseConfig, $testDatabaseName)[0];
        $check(str_starts_with($getLogout['output'], 'Method Not Allowed.'), 'Reject GET at the platform logout endpoint');

        $missingCsrfLogout = platform_test_spawn_workers([[
            'FEES_PLATFORM_TEST_WORKER_MODE' => 'logout',
            'FEES_PLATFORM_TEST_LOGOUT_METHOD' => 'POST',
        ]], $databaseConfig, $testDatabaseName)[0];
        $check(str_starts_with($missingCsrfLogout['output'], 'Invalid request token.'), 'Reject missing CSRF at platform logout');

        $invalidCsrf = bin2hex(random_bytes(32));
        $invalidCsrfLogout = platform_test_spawn_workers([[
            'FEES_PLATFORM_TEST_WORKER_MODE' => 'logout',
            'FEES_PLATFORM_TEST_LOGOUT_METHOD' => 'POST',
            'FEES_PLATFORM_TEST_LOGOUT_CSRF_SESSION' => str_repeat('a', 64),
            'FEES_PLATFORM_TEST_LOGOUT_CSRF_POST' => $invalidCsrf,
        ]], $databaseConfig, $testDatabaseName)[0];
        $check(str_starts_with($invalidCsrfLogout['output'], 'Invalid request token.'), 'Reject an invalid CSRF token at platform logout');

        $logoutCsrf = bin2hex(random_bytes(32));
        $unauthenticatedLogout = platform_test_spawn_workers([[
            'FEES_PLATFORM_TEST_WORKER_MODE' => 'logout',
            'FEES_PLATFORM_TEST_LOGOUT_METHOD' => 'POST',
            'FEES_PLATFORM_TEST_LOGOUT_CSRF_SESSION' => $logoutCsrf,
            'FEES_PLATFORM_TEST_LOGOUT_CSRF_POST' => $logoutCsrf,
        ]], $databaseConfig, $testDatabaseName)[0];
        $check(
            str_starts_with($unauthenticatedLogout['output'], 'Platform admin authentication required.'),
            'Reject unauthenticated platform logout after CSRF validation'
        );

        $institutionLogoutCsrf = bin2hex(random_bytes(32));
        $institutionLogoutResponse = platform_test_spawn_workers([[
            'FEES_PLATFORM_TEST_WORKER_MODE' => 'logout',
            'FEES_PLATFORM_TEST_LOGOUT_METHOD' => 'POST',
            'FEES_PLATFORM_TEST_LOGOUT_CSRF_SESSION' => $institutionLogoutCsrf,
            'FEES_PLATFORM_TEST_LOGOUT_CSRF_POST' => $institutionLogoutCsrf,
            'FEES_PLATFORM_TEST_LOGOUT_INSTITUTION' => '1',
            'FEES_PLATFORM_TEST_LOGOUT_INSTITUTION_USER_ID' => (string) $institutionUser['id'],
            'FEES_PLATFORM_TEST_LOGOUT_INSTITUTION_ID' => (string) $institutionUser['institution_id'],
            'FEES_PLATFORM_TEST_LOGOUT_INSTITUTION_IDENTIFIER' => $institutionAuthSession['identifier'],
            'FEES_PLATFORM_TEST_LOGOUT_INSTITUTION_TOKEN_HASH' => $institutionAuthSession['token_hash'],
        ]], $databaseConfig, $testDatabaseName)[0];
        $institutionLogoutCheck = $db->prepare(
            'SELECT revoked_at IS NULL FROM user_sessions WHERE session_identifier = ?'
        );
        $institutionLogoutCheck->bind_param('s', $institutionLogoutSessionId);
        $institutionLogoutCheck->execute();
        $institutionStillActive = (int) $institutionLogoutCheck->get_result()->fetch_column() === 1;
        $institutionLogoutCheck->close();
        $check(
            str_starts_with($institutionLogoutResponse['output'], 'Platform admin authentication required.')
                && $institutionStillActive,
            'Reject institution identity at the platform logout endpoint without revoking its session'
        );

        $_SESSION = [];
        $platformLogin = platform_admin_login($db, $adminEmail, $adminPassword, 'Platform Test Agent', '127.0.0.1');
        $logoutSessionId = (int) $_SESSION['platform_admin_session_id'];
        $logoutCsrf = csrf_token();
        $authenticatedLogout = platform_test_spawn_workers([[
            'FEES_PLATFORM_TEST_WORKER_MODE' => 'logout',
            'FEES_PLATFORM_TEST_LOGOUT_METHOD' => 'POST',
            'FEES_PLATFORM_TEST_LOGOUT_CSRF_SESSION' => $logoutCsrf,
            'FEES_PLATFORM_TEST_LOGOUT_CSRF_POST' => $logoutCsrf,
            'FEES_PLATFORM_TEST_LOGOUT_ID' => (string) $logoutSessionId,
            'FEES_PLATFORM_TEST_LOGOUT_TOKEN' => (string) $_SESSION['platform_admin_session_token'],
            'FEES_PLATFORM_TEST_LOGOUT_ADMIN_ID' => (string) $platformAdminId,
        ]], $databaseConfig, $testDatabaseName)[0];
        $check(is_array($platformLogin) && $authenticatedLogout['exit_code'] === 0, 'Accept valid POST and CSRF for an active platform session');
        $statement = $db->prepare('SELECT revoked_at IS NOT NULL FROM platform_sessions WHERE id = ?');
        $statement->bind_param('i', $logoutSessionId);
        $statement->execute();
        $logoutRevoked = (int) $statement->get_result()->fetch_column() === 1;
        $statement->close();
        $statement = $db->prepare('SELECT revoked_at IS NULL FROM platform_sessions WHERE id = ?');
        $statement->bind_param('i', $secondPlatformSessionId);
        $statement->execute();
        $otherSessionUnaffected = (int) $statement->get_result()->fetch_column() === 1;
        $statement->close();
        $check($logoutRevoked && $otherSessionUnaffected, 'Revoke only the matching platform session on logout');

        $auditCountBeforeRollback = (int) $db->query('SELECT COUNT(*) FROM platform_audit_logs')->fetch_row()[0];
        $downMigration = file_get_contents(__DIR__ . '/../database/migrations/20261104_harden_platform_admin_security.down.sql');
        if (!is_string($downMigration)) {
            throw new RuntimeException('Platform security rollback migration file could not be read.');
        }
        foreach (array_filter(array_map('trim', explode(';', $downMigration))) as $statementSql) {
            $db->query($statementSql);
        }
        $auditCountAfterRollback = (int) $db->query('SELECT COUNT(*) FROM platform_audit_logs')->fetch_row()[0];
        $check(
            $auditCountAfterRollback === $auditCountBeforeRollback,
            'Preserve every platform audit row when rolling back the security migration'
        );

        $integrationStatus = 'PASS: disposable-database integration checks completed.';
    } catch (Throwable $exception) {
        if (!$databaseCreatedByThisRun) {
            $integrationStatus = 'SKIP: an isolated disposable database could not be established; no database setup was performed.';
        } else {
            $failures[] = 'Disposable database integration failed: ' . get_class($exception) . ' (' . $exception->getCode() . ')';
        }
    } finally {
        if (is_string($originalBootstrapToken)) {
            putenv('FEES_PLATFORM_BOOTSTRAP_TOKEN=' . $originalBootstrapToken);
        } else {
            putenv('FEES_PLATFORM_BOOTSTRAP_TOKEN');
        }
        if ($db instanceof mysqli) {
            $db->close();
        }
        if ($databaseCreatedByThisRun && $server instanceof mysqli
            && platform_test_database_target_is_safe($testDatabaseName, $protectedDatabaseNames, $testEnvironment)) {
            try {
                $server->query('DROP DATABASE `' . $testDatabaseName . '`');
            } catch (Throwable $exception) {
                $failures[] = 'Failed to remove the disposable platform test database created by this run.';
            }
        }
        if ($server instanceof mysqli) {
            $server->close();
        }
    }
} else {
    $integrationStatus = 'SKIP: database integration disabled; set FEES_PLATFORM_TEST_RUN_DB_INTEGRATION=1 to create an isolated disposable database.';
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'Passed ' . $checks . ' platform admin auth checks.' . PHP_EOL);
fwrite(STDOUT, $integrationStatus . PHP_EOL);
