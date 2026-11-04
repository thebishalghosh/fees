<?php
declare(strict_types=1);

$testDatabase = getenv('FEES_PARENT_CREATION_TEST_DB');
if (is_string($testDatabase) && $testDatabase !== '') {
    if (preg_match('/^fees_parent_creation_verify_[0-9]{8}_[0-9]{6}$/D', $testDatabase) !== 1) {
        fwrite(STDERR, 'Use only a dedicated disposable parent-creation verification database.' . PHP_EOL);
        exit(2);
    }
    putenv('DB_NAME=' . $testDatabase);
}

require_once __DIR__ . '/../services/parent-management-service.php';

$checks = 0;
$failures = [];
$check = static function (bool $condition, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $label;
    }
};

$serviceSource = file_get_contents(__DIR__ . '/../services/parent-management-service.php');
$createPageSource = file_get_contents(__DIR__ . '/../admin/parents/create.php');
$storeSource = file_get_contents(__DIR__ . '/../admin/parents/store.php');
$formSource = file_get_contents(__DIR__ . '/../includes/components/parent-profile-form.php');
$check(
    is_string($serviceSource)
        && str_contains($serviceSource, 'parent_management_resolve_create_institution')
        && str_contains($serviceSource, 'parent_management_assert_create_authorized')
        && str_contains($serviceSource, 'BINARY name = BINARY \'parent\''),
    'Resolve tenant from authenticated account and use the canonical Parent role'
);
$check(
    is_string($createPageSource)
        && str_contains($createPageSource, 'parent_management_get_active_institutions')
        && str_contains($createPageSource, 'method="get"')
        && str_contains($formSource, 'name="institution_id"'),
    'Show Super Admin an active-institution selector and scope students to its selection'
);
$check(
    is_string($storeSource)
        && str_contains($storeSource, 'require_valid_csrf_token()')
        && str_contains($storeSource, 'user_has_compatible_permission(\'parents.create\', \'parents.manage\')')
        && str_contains($storeSource, 'parent_management_resolve_create_institution'),
    'Enforce CSRF, create permission, and server-side institution resolution on submit'
);

if (!is_string($testDatabase) || $testDatabase === '') {
    fwrite(STDOUT, 'Database assertions skipped; use only a disposable fees_parent_creation_verify_ database.' . PHP_EOL);
} else {
    $db = db_connect();
    $suffix = bin2hex(random_bytes(6));
    $createInstitution = static function (string $name) use ($db, $suffix): int {
        $code = 'PCR-' . strtoupper(substr(hash('sha256', $name . $suffix), 0, 10));
        $statement = $db->prepare('INSERT INTO institutions (name, code) VALUES (?, ?)');
        $statement->bind_param('ss', $name, $code);
        $statement->execute();
        $id = (int) $db->insert_id;
        $statement->close();
        return $id;
    };
    $createActor = static function (
        int $institutionId,
        int $roleId,
        string $userType,
        string $name
    ) use ($db): array {
        $status = 'active';
        $statement = $db->prepare(
            'INSERT INTO users (institution_id, role_id, user_type, full_name, status)
             VALUES (?, ?, ?, ?, ?)'
        );
        $statement->bind_param('iisss', $institutionId, $roleId, $userType, $name, $status);
        $statement->execute();
        $userId = (int) $db->insert_id;
        $statement->close();

        $roleStatement = $db->prepare(
            'SELECT is_system FROM roles WHERE id = ?'
        );
        $roleStatement->bind_param('i', $roleId);
        $roleStatement->execute();
        $isSystemRole = (int) $roleStatement->get_result()->fetch_assoc()['is_system'] === 1;
        $roleStatement->close();
        return [
            'id' => $userId,
            'institution_id' => $institutionId,
            'user_type' => $userType,
            'status' => 'active',
            'role_is_system' => $isSystemRole,
            'permissions' => [],
        ];
    };
    $setActor = static function (array $actor): void {
        $_SESSION['user'] = $actor;
    };
    $makeProfile = static function (string $label, string $suffix): array {
        $result = parent_management_validate_profile([
            'full_name' => $label . ' ' . $suffix,
            'email' => strtolower($label) . '.' . $suffix . '@example.test',
            'mobile' => '+919' . substr((string) hexdec(substr(hash('sha256', $label . $suffix), 0, 10)), 0, 10),
            'relationship_to_student' => 'guardian',
        ]);
        if ($result['errors'] !== []) {
            throw new RuntimeException(implode(' ', $result['errors']));
        }
        return $result['data'];
    };
    $resolve = static function (array $actor, mixed $submitted) use ($db): int {
        $setActor = static function (array $value): void {
            $_SESSION['user'] = $value;
        };
        $setActor($actor);
        return parent_management_resolve_create_institution($db, $actor, $submitted);
    };
    $expectException = static function (callable $operation, string $label) use ($check): void {
        try {
            $operation();
            $check(false, $label);
        } catch (InvalidArgumentException|OutOfBoundsException) {
            $check(true, $label);
        }
    };

    $roleResult = $db->query(
        "SELECT id, name FROM roles
         WHERE is_system = 1 AND institution_id IS NULL
           AND BINARY name IN (BINARY 'super_admin', BINARY 'admin', BINARY 'parent')"
    );
    $systemRoles = [];
    while ($role = $roleResult->fetch_assoc()) {
        $systemRoles[$role['name']] = (int) $role['id'];
    }
    if (!isset($systemRoles['super_admin'], $systemRoles['admin'], $systemRoles['parent'])) {
        throw new RuntimeException('Canonical system roles are missing from the test database.');
    }

    $sourceInstitutionId = $createInstitution('Parent Creation Source ' . $suffix);
    $targetInstitutionId = $createInstitution('Parent Creation Target ' . $suffix);
    $inactiveInstitutionId = $createInstitution('Parent Creation Inactive ' . $suffix);
    $db->query("UPDATE institutions SET status = 'inactive' WHERE id = " . $inactiveInstitutionId);
    $superAdmin = $createActor(
        $sourceInstitutionId,
        $systemRoles['super_admin'],
        'super_admin',
        'Parent Creation Super Admin ' . $suffix
    );
    $admin = $createActor(
        $sourceInstitutionId,
        $systemRoles['admin'],
        'admin',
        'Parent Creation Admin ' . $suffix
    );

    $parentPermission = $db->prepare(
        "SELECT id FROM permissions WHERE permission_key = 'parents.create' LIMIT 1"
    );
    $parentPermission->execute();
    $permissionId = (int) $parentPermission->get_result()->fetch_assoc()['id'];
    $parentPermission->close();
    $staffRoleStatement = $db->prepare(
        'INSERT INTO roles (name, institution_id, is_system)
         VALUES (?, ?, 0)'
    );
    $authorizedStaffRoleName = 'Parent Creator ' . $suffix;
    $staffRoleStatement->bind_param('si', $authorizedStaffRoleName, $sourceInstitutionId);
    $staffRoleStatement->execute();
    $authorizedStaffRoleId = (int) $db->insert_id;
    $staffRoleStatement->close();
    $grantStatement = $db->prepare(
        'INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)'
    );
    $grantStatement->bind_param('ii', $authorizedStaffRoleId, $permissionId);
    $grantStatement->execute();
    $grantStatement->close();
    $unauthorizedStaffRoleName = 'Parent Viewer ' . $suffix;
    $staffRoleStatement = $db->prepare(
        'INSERT INTO roles (name, institution_id, is_system)
         VALUES (?, ?, 0)'
    );
    $staffRoleStatement->bind_param('si', $unauthorizedStaffRoleName, $sourceInstitutionId);
    $staffRoleStatement->execute();
    $unauthorizedStaffRoleId = (int) $db->insert_id;
    $staffRoleStatement->close();
    $staff = $createActor(
        $sourceInstitutionId,
        $authorizedStaffRoleId,
        'staff',
        'Parent Creator Staff ' . $suffix
    );
    $unauthorizedStaff = $createActor(
        $sourceInstitutionId,
        $unauthorizedStaffRoleId,
        'staff',
        'Parent Viewer Staff ' . $suffix
    );

    $activeInstitutions = parent_management_get_active_institutions($db);
    $check(
        in_array($targetInstitutionId, array_column($activeInstitutions, 'id'), true)
            && !in_array($inactiveInstitutionId, array_column($activeInstitutions, 'id'), true),
        'List active institutions only'
    );

    $resolvedSuperAdminInstitution = $resolve($superAdmin, (string) $targetInstitutionId);
    $check($resolvedSuperAdminInstitution === $targetInstitutionId, 'Allow Super Admin to select an active institution');
    $expectException(
        static fn() => $resolve($superAdmin, (string) $inactiveInstitutionId),
        'Reject an inactive institution for Super Admin'
    );
    $expectException(
        static fn() => $resolve($superAdmin, '999999999'),
        'Reject a nonexistent institution for Super Admin'
    );
    $expectException(
        static fn() => $resolve($superAdmin, ['malformed']),
        'Reject a malformed institution ID'
    );

    $check(
        $resolve($admin, null) === $sourceInstitutionId,
        'Resolve Institution Admin creation to the database-assigned institution'
    );
    $expectException(
        static fn() => $resolve($admin, (string) $targetInstitutionId),
        'Reject an Institution Admin institution override'
    );
    $check(
        $resolve($staff, null) === $sourceInstitutionId,
        'Allow authorized staff to create only in their assigned institution'
    );
    $expectException(
        static fn() => $resolve($staff, (string) $targetInstitutionId),
        'Reject a staff institution override'
    );
    $expectException(
        static fn() => $resolve($unauthorizedStaff, null),
        'Reject staff without parent-creation permission'
    );

    $assertParentInstitution = static function (int $parentId, int $expectedInstitutionId) use ($db): bool {
        $statement = $db->prepare(
            'SELECT p.institution_id AS parent_institution_id,
                    u.institution_id AS user_institution_id
             FROM parents p
             JOIN users u ON u.id = p.user_id
             WHERE p.id = ?'
        );
        $statement->bind_param('i', $parentId);
        $statement->execute();
        $record = $statement->get_result()->fetch_assoc();
        $statement->close();
        return is_array($record)
            && (int) $record['parent_institution_id'] === $expectedInstitutionId
            && (int) $record['user_institution_id'] === $expectedInstitutionId;
    };

    $setActor($admin);
    $adminParentId = parent_management_create(
        $db,
        $sourceInstitutionId,
        $admin['id'],
        $admin['user_type'],
        $makeProfile('admin', $suffix),
        'pending_verification',
        []
    );
    $check(
        $assertParentInstitution($adminParentId, $sourceInstitutionId),
        'Create an Institution Admin parent and account in the authenticated institution'
    );

    $setActor($staff);
    $staffParentId = parent_management_create(
        $db,
        $sourceInstitutionId,
        $staff['id'],
        $staff['user_type'],
        $makeProfile('staff', $suffix),
        'pending_verification',
        []
    );
    $check(
        $assertParentInstitution($staffParentId, $sourceInstitutionId),
        'Create a parent for authorized staff only in its assigned institution'
    );

    $studentCode = 'PCR-S-' . strtoupper(substr(hash('sha256', $suffix), 0, 10));
    $studentName = 'Parent Creation Student ' . $suffix;
    $admissionDate = '2026-01-01';
    $studentStatus = 'admitted';
    $studentStatement = $db->prepare(
        'INSERT INTO students (institution_id, student_code, full_name, admission_date, status)
         VALUES (?, ?, ?, ?, ?)'
    );
    $studentStatement->bind_param(
        'issss',
        $targetInstitutionId,
        $studentCode,
        $studentName,
        $admissionDate,
        $studentStatus
    );
    $studentStatement->execute();
    $studentId = (int) $db->insert_id;
    $studentStatement->close();

    $setActor($superAdmin);
    $selectedParentId = parent_management_create(
        $db,
        $targetInstitutionId,
        $superAdmin['id'],
        $superAdmin['user_type'],
        $makeProfile('superadmin', $suffix),
        'pending_verification',
        [$studentId]
    );
    $consistencyStatement = $db->prepare(
        'SELECT p.institution_id AS parent_institution_id,
                u.institution_id AS user_institution_id,
                psr.institution_id AS relationship_institution_id,
                r.name AS role_name, r.is_system AS role_is_system
         FROM parents p
         JOIN users u ON u.id = p.user_id
         JOIN roles r ON r.id = u.role_id
         JOIN parent_student_relationships psr
           ON psr.parent_id = p.id AND psr.student_id = ?
         WHERE p.id = ?'
    );
    $consistencyStatement->bind_param('ii', $studentId, $selectedParentId);
    $consistencyStatement->execute();
    $consistency = $consistencyStatement->get_result()->fetch_assoc();
    $consistencyStatement->close();
    $check(
        is_array($consistency)
            && (int) $consistency['parent_institution_id'] === $targetInstitutionId
            && (int) $consistency['user_institution_id'] === $targetInstitutionId
            && (int) $consistency['relationship_institution_id'] === $targetInstitutionId
            && $consistency['role_name'] === 'parent'
            && (int) $consistency['role_is_system'] === 1,
        'Keep parent, user, relationship, and canonical Parent role consistent'
    );

    $sourceStudentCode = 'PCR-X-' . strtoupper(substr(hash('sha256', 'source-' . $suffix), 0, 10));
    $sourceStudentStatement = $db->prepare(
        'INSERT INTO students (institution_id, student_code, full_name, admission_date, status)
         VALUES (?, ?, ?, ?, ?)'
    );
    $sourceStudentName = 'Parent Creation Source Student ' . $suffix;
    $sourceStudentStatement->bind_param(
        'issss',
        $sourceInstitutionId,
        $sourceStudentCode,
        $sourceStudentName,
        $admissionDate,
        $studentStatus
    );
    $sourceStudentStatement->execute();
    $sourceStudentId = (int) $db->insert_id;
    $sourceStudentStatement->close();
    $crossTenantProfile = $makeProfile('cross-tenant', $suffix);
    $crossTenantRejected = false;
    try {
        parent_management_create(
            $db,
            $targetInstitutionId,
            $superAdmin['id'],
            $superAdmin['user_type'],
            $crossTenantProfile,
            'pending_verification',
            [$sourceStudentId]
        );
    } catch (InvalidArgumentException) {
        $crossTenantRejected = true;
    }
    $crossTenantCount = $db->prepare(
        'SELECT COUNT(*) AS result_count FROM users
         WHERE institution_id = ? AND email = ? AND mobile = ?'
    );
    $crossTenantCount->bind_param(
        'iss',
        $targetInstitutionId,
        $crossTenantProfile['email'],
        $crossTenantProfile['mobile']
    );
    $crossTenantCount->execute();
    $crossTenantUserCount = (int) $crossTenantCount->get_result()->fetch_assoc()['result_count'];
    $crossTenantCount->close();
    $check(
        $crossTenantRejected && $crossTenantUserCount === 0,
        'Reject cross-institution student links and roll back the new account'
    );

    $rollbackProfile = $makeProfile('rollback', $suffix);
    $rollbackTrigger = 'trg_parent_creation_test_' . substr($suffix, 0, 8);
    $db->query(
        'CREATE TRIGGER ' . $rollbackTrigger . '
         BEFORE INSERT ON parents
         FOR EACH ROW
         SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'Forced parent creation rollback test.\''
    );
    $creationRolledBack = false;
    try {
        parent_management_create(
            $db,
            $sourceInstitutionId,
            $admin['id'],
            $admin['user_type'],
            $rollbackProfile,
            'pending_verification',
            []
        );
    } catch (Throwable) {
        $creationRolledBack = true;
    } finally {
        $db->query('DROP TRIGGER ' . $rollbackTrigger);
    }
    $rollbackCountStatement = $db->prepare(
        'SELECT COUNT(*) AS result_count FROM users
         WHERE institution_id = ? AND email = ? AND mobile = ?'
    );
    $rollbackCountStatement->bind_param(
        'iss',
        $sourceInstitutionId,
        $rollbackProfile['email'],
        $rollbackProfile['mobile']
    );
    $rollbackCountStatement->execute();
    $rollbackUserCount = (int) $rollbackCountStatement->get_result()->fetch_assoc()['result_count'];
    $rollbackCountStatement->close();
    $check(
        $creationRolledBack && $rollbackUserCount === 0,
        'Roll back the user insert when parent creation fails'
    );

    $setActor($unauthorizedStaff);
    $unauthorizedCreationRejected = false;
    try {
        parent_management_create(
            $db,
            $sourceInstitutionId,
            $unauthorizedStaff['id'],
            $unauthorizedStaff['user_type'],
            $makeProfile('unauthorized', $suffix),
            'pending_verification',
            []
        );
    } catch (OutOfBoundsException) {
        $unauthorizedCreationRejected = true;
    }
    $check($unauthorizedCreationRejected, 'Enforce staff permission inside the creation service');

    if ($failures === []) {
        fwrite(STDOUT, 'Passed ' . $checks . ' parent creation checks.' . PHP_EOL);
    }
    $db->close();
}

if ($failures !== []) {
    fwrite(STDERR, 'FAIL: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}

if (!is_string($testDatabase) || $testDatabase === '') {
    fwrite(STDOUT, 'Passed ' . $checks . ' parent creation static checks.' . PHP_EOL);
}
