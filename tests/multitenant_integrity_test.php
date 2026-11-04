<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$testDatabase = getenv('FEES_ISOLATION_TEST_DB');
if (is_string($testDatabase) && $testDatabase !== '') {
    putenv('DB_NAME=' . $testDatabase);
    putenv('OTP_HASH_KEY=multi-tenant-isolation-test-key-2026');
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../services/otp-service.php';
}

$checks = 0;
$failures = [];
$check = static function (bool $condition, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $label;
    }
};

$schema = file_get_contents(__DIR__ . '/../database/schema.sql');
$migration = file_get_contents(__DIR__ . '/../database/migrations/20261103_enforce_tenant_identity_integrity.sql');
$roleManagement = file_get_contents(__DIR__ . '/../services/role-management-service.php');
$check(
    is_string($schema)
        && str_contains($schema, 'FOREIGN KEY (institution_id, user_id)')
        && str_contains($schema, 'trg_users_role_tenant_bi')
        && str_contains($schema, 'trg_roles_tenant_bu'),
    'Define the same-institution session, OTP, and role constraints for new installations'
);
$check(
    is_string($migration)
        && str_contains($migration, 'migration_20261103_assert_tenant_identity_integrity')
        && str_contains($migration, 'fk_user_sessions_user_institution')
        && str_contains($migration, 'fk_user_otp_user_institution')
        && str_contains($migration, 'trg_users_role_tenant_bi')
        && str_contains($migration, 'trg_roles_tenant_bu')
        && str_contains($migration, 'BINARY role_name <> BINARY NEW.user_type'),
    'Guard and apply the existing-database integrity migration'
);
$check(
    is_string($roleManagement)
        && substr_count($roleManagement, 'WHERE role_id = ? AND institution_id = ?') === 2,
    'Scope role deactivation and deletion counts to the institution'
);

if (is_string($testDatabase) && $testDatabase !== '') {
    $check(
        preg_match('/^fees_(?:backup|schema)_verify_[0-9]{8}_[0-9]{6}$/D', $testDatabase) === 1,
        'Use only a dedicated disposable backup or schema verification database'
    );
    if (preg_match('/^fees_(?:backup|schema)_verify_[0-9]{8}_[0-9]{6}$/D', $testDatabase) === 1) {
        $config = db_config();
        $db = new mysqli(
            (string) $config['host'],
            (string) $config['username'],
            (string) $config['password'],
            $testDatabase,
            (int) $config['port']
        );
        $db->set_charset('utf8mb4');
        $db->begin_transaction();

        $suffix = bin2hex(random_bytes(5));
        $institutionCodeA = 'TEST-A-' . $suffix;
        $institutionCodeB = 'TEST-B-' . $suffix;
        $institutionNameA = 'Isolation Test A ' . $suffix;
        $institutionNameB = 'Isolation Test B ' . $suffix;
        $statement = $db->prepare('INSERT INTO institutions (name, code) VALUES (?, ?)');
        $statement->bind_param('ss', $institutionNameA, $institutionCodeA);
        $statement->execute();
        $institutionA = (int) $db->insert_id;
        $statement->bind_param('ss', $institutionNameB, $institutionCodeB);
        $statement->execute();
        $institutionB = (int) $db->insert_id;
        $statement->close();

        $roleNameA = 'Isolation Staff A ' . $suffix;
        $roleNameB = 'Isolation Staff B ' . $suffix;
        $isSystem = 0;
        $statement = $db->prepare('INSERT INTO roles (institution_id, name, is_system) VALUES (?, ?, ?)');
        $statement->bind_param('isi', $institutionA, $roleNameA, $isSystem);
        $statement->execute();
        $roleA = (int) $db->insert_id;
        $statement->bind_param('isi', $institutionB, $roleNameB, $isSystem);
        $statement->execute();
        $roleB = (int) $db->insert_id;
        $statement->close();

        $userNameA = 'Isolation Staff A ' . $suffix;
        $userNameB = 'Isolation Staff B ' . $suffix;
        $userType = 'staff';
        $userStatus = 'active';
        $statement = $db->prepare(
            'INSERT INTO users (institution_id, role_id, user_type, full_name, status) VALUES (?, ?, ?, ?, ?)'
        );
        $statement->bind_param('iisss', $institutionA, $roleA, $userType, $userNameA, $userStatus);
        $statement->execute();
        $userA = (int) $db->insert_id;
        $statement->bind_param('iisss', $institutionB, $roleB, $userType, $userNameB, $userStatus);
        $statement->execute();
        $userB = (int) $db->insert_id;
        $statement->close();
        $staffEmail = 'isolation-' . $suffix . '@example.test';
        $statement = $db->prepare(
            'UPDATE users SET email = ?, email_verified_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $statement->bind_param('si', $staffEmail, $userA);
        $statement->execute();
        $statement->close();

        $rejected = static function (callable $operation): bool {
            try {
                $operation();
                return false;
            } catch (mysqli_sql_exception) {
                return true;
            }
        };
        $systemRoles = [];
        $systemRoleResult = $db->query(
            "SELECT id, name FROM roles WHERE is_system = 1 AND institution_id IS NULL"
        );
        while ($systemRole = $systemRoleResult->fetch_assoc()) {
            $systemRoles[(string) $systemRole['name']] = (int) $systemRole['id'];
        }
        $systemRoleResult->free();
        $insertUser = static function (
            int $institutionId,
            int $roleId,
            string $type,
            string $name
        ) use ($db): int {
            $status = 'active';
            $statement = $db->prepare(
                'INSERT INTO users (institution_id, role_id, user_type, full_name, status) VALUES (?, ?, ?, ?, ?)'
            );
            $statement->bind_param('iisss', $institutionId, $roleId, $type, $name, $status);
            $statement->execute();
            $id = (int) $db->insert_id;
            $statement->close();
            return $id;
        };
        $check(
            isset($systemRoles['admin'], $systemRoles['parent'], $systemRoles['super_admin'], $systemRoles['staff']),
            'Keep all canonical global system roles available'
        );
        foreach ([
            [$institutionA, 'admin', 'Isolation Admin A ' . $suffix],
            [$institutionB, 'admin', 'Isolation Admin B ' . $suffix],
            [$institutionA, 'parent', 'Isolation Parent A ' . $suffix],
            [$institutionB, 'parent', 'Isolation Parent B ' . $suffix],
            [$institutionA, 'super_admin', 'Isolation Super Admin ' . $suffix],
        ] as [$institutionId, $type, $name]) {
            if (isset($systemRoles[$type])) {
                $insertUser((int) $institutionId, $systemRoles[$type], (string) $type, (string) $name);
            }
        }
        $check(
            $rejected(static function () use ($db, $institutionB, $roleA, $suffix): void {
                $name = 'Invalid Cross Tenant Staff B ' . $suffix;
                $type = 'staff';
                $status = 'active';
                $statement = $db->prepare(
                    'INSERT INTO users (institution_id, role_id, user_type, full_name, status) VALUES (?, ?, ?, ?, ?)'
                );
                $statement->bind_param('iisss', $institutionB, $roleA, $type, $name, $status);
                $statement->execute();
                $statement->close();
            }),
            'Reject assigning Institution A custom role to Institution B user'
        );
        $crossRoleName = 'Invalid Cross Tenant Staff ' . $suffix;
        $check(
            $rejected(static function () use ($db, $institutionA, $roleB, $crossRoleName, $userType, $userStatus): void {
                $statement = $db->prepare(
                    'INSERT INTO users (institution_id, role_id, user_type, full_name, status) VALUES (?, ?, ?, ?, ?)'
                );
                $statement->bind_param('iisss', $institutionA, $roleB, $userType, $crossRoleName, $userStatus);
                $statement->execute();
                $statement->close();
            }),
            'Reject assigning Institution B custom role to Institution A user'
        );
        $check(
            $rejected(static function () use ($db, $userA, $roleB): void {
                $statement = $db->prepare('UPDATE users SET role_id = ? WHERE id = ?');
                $statement->bind_param('ii', $roleB, $userA);
                $statement->execute();
                $statement->close();
            }),
            'Reject cross-institution role change on an existing user'
        );
        $check(
            $rejected(static function () use ($db, $institutionA, $roleA, $suffix): void {
                $name = 'Invalid Custom Parent ' . $suffix;
                $type = 'parent';
                $status = 'active';
                $statement = $db->prepare(
                    'INSERT INTO users (institution_id, role_id, user_type, full_name, status) VALUES (?, ?, ?, ?, ?)'
                );
                $statement->bind_param('iisss', $institutionA, $roleA, $type, $name, $status);
                $statement->execute();
                $statement->close();
            }),
            'Reject assigning a custom staff role to a parent user'
        );
        $check(
            $rejected(static function () use ($insertUser, $institutionA, $systemRoles, $suffix): void {
                $insertUser(
                    $institutionA,
                    $systemRoles['staff'],
                    'admin',
                    'Invalid Global Role Type ' . $suffix
                );
            }),
            'Reject an incompatible global system-role assignment'
        );
        $check(
            $rejected(static function () use ($db, $roleB, $institutionA): void {
                $statement = $db->prepare('UPDATE roles SET institution_id = ? WHERE id = ?');
                $statement->bind_param('ii', $institutionA, $roleB);
                $statement->execute();
                $statement->close();
            }),
            'Reject changing a role owner when it would invalidate assigned users'
        );
        $check(
            $rejected(static function () use ($db, $roleB): void {
                $statement = $db->prepare(
                    "UPDATE roles SET is_system = 1, institution_id = NULL, name = 'STAFF' WHERE id = ?"
                );
                $statement->bind_param('i', $roleB);
                $statement->execute();
                $statement->close();
            }),
            'Reject non-canonical casing for a global system role name'
        );

        $sessionIdentifier = bin2hex(random_bytes(16));
        $sessionTokenHash = hash('sha256', random_bytes(32));
        $expiry = date('Y-m-d H:i:s', time() + 3600);
        $statement = $db->prepare(
            'INSERT INTO user_sessions (user_id, institution_id, session_identifier, session_token_hash, expires_at) VALUES (?, ?, ?, ?, ?)'
        );
        $statement->bind_param('iisss', $userA, $institutionA, $sessionIdentifier, $sessionTokenHash, $expiry);
        $sessionInserted = $statement->execute();
        $statement->close();
        $check($sessionInserted, 'Accept a session for a user in the same institution');
        $check(
            $rejected(static function () use ($db, $userA, $institutionB, $expiry): void {
                $identifier = bin2hex(random_bytes(16));
                $hash = hash('sha256', random_bytes(32));
                $statement = $db->prepare(
                    'INSERT INTO user_sessions (user_id, institution_id, session_identifier, session_token_hash, expires_at) VALUES (?, ?, ?, ?, ?)'
                );
                $statement->bind_param('iisss', $userA, $institutionB, $identifier, $hash, $expiry);
                $statement->execute();
                $statement->close();
            }),
            'Reject a session with a mismatched user/institution pair'
        );

        $purpose = 'login';
        $requestId = 'test-' . $suffix;
        $otpValue = '123456';
        $otpHash = hash_login_otp($otpValue);
        $otpExpiry = date('Y-m-d H:i:s', time() + 600);
        $statement = $db->prepare(
            'INSERT INTO user_otp_verifications (institution_id, user_id, purpose, request_id, otp_hash, expires_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->bind_param('iissss', $institutionA, $userA, $purpose, $requestId, $otpHash, $otpExpiry);
        $otpInserted = $statement->execute();
        $statement->close();
        $check($otpInserted, 'Accept an OTP for a user in the same institution');
        $check(
            $rejected(static function () use ($db, $userA, $institutionB, $suffix, $purpose, $otpHash, $otpExpiry): void {
                $request = 'wrong-' . $suffix;
                $statement = $db->prepare(
                    'INSERT INTO user_otp_verifications (institution_id, user_id, purpose, request_id, otp_hash, expires_at) VALUES (?, ?, ?, ?, ?, ?)'
                );
                $statement->bind_param('iissss', $institutionB, $userA, $purpose, $request, $otpHash, $otpExpiry);
                $statement->execute();
                $statement->close();
            }),
            'Reject an OTP with a mismatched user/institution pair'
        );

        $expiredRequestId = 'expired-' . $suffix;
        $expiredHash = hash_login_otp('654321');
        $expiredAt = date('Y-m-d H:i:s', time() - 60);
        $statement = $db->prepare(
            'INSERT INTO user_otp_verifications (institution_id, user_id, purpose, request_id, otp_hash, expires_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->bind_param('iissss', $institutionB, $userB, $purpose, $expiredRequestId, $expiredHash, $expiredAt);
        $statement->execute();
        $statement->close();

        $db->commit();

        $_SESSION['user'] = [
            'id' => $userA,
            'institution_id' => $institutionA,
            'user_type' => 'staff',
            'status' => 'active',
            'permissions' => [],
        ];
        $_SESSION['auth_session'] = [
            'identifier' => $sessionIdentifier,
            'token_hash' => $sessionTokenHash,
        ];
        $check(revalidate_authenticated_session(), 'Revalidate a session for its same-institution active user');

        $expiredResult = verify_login_challenge([
            'user_id' => $userB,
            'institution_id' => $institutionB,
            'request_id' => $expiredRequestId,
            'login_type' => 'staff_email',
            'email' => '',
        ], '654321');
        $check($expiredResult === 'expired', 'Continue rejecting an expired persisted OTP challenge');

        $verifiedResult = verify_login_challenge([
            'user_id' => $userA,
            'institution_id' => $institutionA,
            'request_id' => $requestId,
            'login_type' => 'staff_email',
            'email' => $staffEmail,
        ], $otpValue);
        $check($verifiedResult === 'authenticated', 'Verify a same-institution OTP and issue an authenticated session');
        $check(revalidate_authenticated_session(), 'Revalidate the session created by successful OTP verification');

        $systemStaffRoleId = (int) ($systemRoles['staff'] ?? 0);
        $globalStaffName = 'Isolation Global Staff ' . $suffix;
        $check($systemStaffRoleId > 0, 'Find the canonical global Staff role');
        if ($systemStaffRoleId > 0) {
            $statement = $db->prepare(
                'INSERT INTO users (institution_id, role_id, user_type, full_name, status) VALUES (?, ?, ?, ?, ?)'
            );
            $statement->bind_param('iisss', $institutionA, $systemStaffRoleId, $userType, $globalStaffName, $userStatus);
            $globalInserted = $statement->execute();
            $statement->close();
            $check($globalInserted, 'Continue accepting compatible global system-role assignments');
        }

        $statement = $db->prepare('SELECT COUNT(*) AS n FROM users WHERE role_id = ? AND institution_id = ?');
        $statement->bind_param('ii', $roleA, $institutionA);
        $statement->execute();
        $countA = (int) $statement->get_result()->fetch_assoc()['n'];
        $statement->close();
        $statement = $db->prepare('SELECT COUNT(*) AS n FROM users WHERE role_id = ? AND institution_id = ?');
        $statement->bind_param('ii', $roleB, $institutionB);
        $statement->execute();
        $countB = (int) $statement->get_result()->fetch_assoc()['n'];
        $statement->close();
        $check($countA === 1 && $countB === 1, 'Keep role assignment counts institution-specific');

        $db->close();
    }
} else {
    fwrite(STDOUT, "Database assertions skipped; set FEES_ISOLATION_TEST_DB to a disposable fees_*_verify_ database to enable them." . PHP_EOL);
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'Passed ' . $checks . ' multi-tenant integrity checks.' . PHP_EOL);
