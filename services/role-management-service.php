<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/audit-service.php';

function rbac_permission_catalog(): array
{
    $moduleActions = [
        'Dashboard' => ['view'],
        'Students' => ['view', 'create', 'edit', 'delete'],
        'Parents' => ['view', 'create', 'edit', 'delete'],
        'Staff and Administrators' => ['view', 'create', 'edit', 'delete', 'assign_role'],
        'Role Permissions' => ['view', 'create', 'edit', 'delete', 'permissions.manage'],
        'Courses' => ['view', 'create', 'edit', 'delete'],
        'Batches' => ['view', 'create', 'edit', 'delete'],
        'Academic Years' => ['view', 'create', 'edit', 'delete'],
        'Classes and Subjects' => ['view', 'create', 'edit', 'delete'],
        'Attendance' => ['view', 'create', 'edit', 'delete', 'export'],
        'Examinations' => ['view', 'create', 'edit', 'delete'],
        'Results' => ['view', 'create', 'edit', 'approve', 'publish'],
        'Fee Structures' => ['view', 'create', 'edit', 'delete'],
        'Student Fees' => ['view', 'create', 'edit', 'delete', 'approve', 'reject', 'export'],
        'Payments' => ['view', 'export', 'reconcile', 'refund'],
        'Financial Reports' => ['view', 'export'],
        'Announcements' => ['view', 'create', 'edit', 'delete', 'publish'],
        'Notifications' => ['view', 'manage'],
        'Library' => ['view', 'create', 'edit', 'delete', 'manage'],
        'Study Materials' => ['view', 'create', 'edit', 'delete', 'manage'],
        'Admissions' => ['view', 'create', 'edit', 'approve', 'reject'],
        'Institution Settings' => ['view', 'manage'],
        'General Reports' => ['view', 'export'],
    ];
    $catalog = [];
    foreach ($moduleActions as $module => $actions) {
        $prefix = match ($module) {
            'Classes and Subjects' => 'classes',
            'Role Permissions' => 'roles',
            'Academic Years' => 'academic_years',
            'Fee Structures' => 'fee_structures',
            'Student Fees' => 'student_fees',
            'Financial Reports' => 'financial_reports',
            'Institution Settings' => 'institution_settings',
            'General Reports' => 'general_reports',
            'Staff and Administrators' => 'staff',
            default => strtolower(str_replace(' ', '_', $module)),
        };
        $catalog[$module] = [];
        foreach ($actions as $action) {
            $key = match ([$prefix, $action]) {
                ['staff', 'assign_role'] => 'staff.assign_role',
                ['roles', 'permissions.manage'] => 'roles.permissions.manage',
                default => $prefix . '.' . $action,
            };
            $label = match ($action) {
                'assign_role' => 'Assign roles',
                'permissions.manage' => 'Manage permissions',
                'publish' => 'Publish',
                'reconcile' => 'Reconcile',
                'refund' => 'Refund',
                default => ucfirst($action),
            };
            $catalog[$module][$key] = $label;
        }
    }

    return $catalog;
}

function rbac_permission_keys(): array
{
    $keys = [];
    foreach (rbac_permission_catalog() as $permissions) {
        foreach ($permissions as $key => $_label) {
            $keys[] = $key;
        }
    }
    return $keys;
}

function rbac_role_name_is_valid(string $name): bool
{
    return trim($name) === $name
        && mb_check_encoding($name, 'UTF-8')
        && mb_strlen($name, 'UTF-8') >= 2
        && mb_strlen($name, 'UTF-8') <= 80
        && preg_match('/[\x00-\x1F\x7F]/', $name) !== 1;
}

function rbac_role_description_is_valid(?string $description): bool
{
    return $description === null
        || (mb_check_encoding($description, 'UTF-8')
            && mb_strlen($description, 'UTF-8') <= 255
            && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $description) !== 1);
}

function rbac_assert_manager(int $institutionId): array
{
    $user = current_user();
    $isSystemAdministrator = is_array($user)
        && in_array($user['user_type'], ['admin', 'super_admin'], true);
    $hasManagementPermission = is_array($user)
        && (user_has_permission('roles.view')
            || (($user['role_is_system'] ?? true) && user_has_permission('staff.manage')));
    if (!$isSystemAdministrator
        || !$hasManagementPermission
        || (int) $user['institution_id'] !== $institutionId) {
        http_response_code(403);
        exit('Forbidden.');
    }

    return $user;
}

function rbac_assert_manager_permission(int $institutionId, string $permission): array
{
    $actor = rbac_assert_manager($institutionId);
    if (!user_has_compatible_permission($permission, 'staff.manage')) {
        http_response_code(403);
        exit('Forbidden.');
    }

    return $actor;
}

function rbac_role_for_institution(mysqli $db, int $roleId, int $institutionId, bool $forUpdate = false): ?array
{
    $query = 'SELECT id, institution_id, name, description, is_system, is_active
              FROM roles
              WHERE id = ? AND (is_system = 1 OR institution_id = ?)
              LIMIT 1';
    if ($forUpdate) {
        $query .= ' FOR UPDATE';
    }
    $statement = $db->prepare($query);
    $statement->bind_param('ii', $roleId, $institutionId);
    $statement->execute();
    $role = $statement->get_result()->fetch_assoc();
    $statement->close();
    return is_array($role) ? $role : null;
}

function rbac_list_roles(mysqli $db, int $institutionId): array
{
    $statement = $db->prepare(
        'SELECT r.id, r.institution_id, r.name, r.description, r.is_system, r.is_active,
                (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS permission_count,
                (SELECT COUNT(*) FROM users u
                 WHERE u.role_id = r.id AND u.institution_id = ?) AS assigned_users
         FROM roles r
         WHERE r.is_system = 1 OR r.institution_id = ?
         ORDER BY r.is_system DESC, r.name'
    );
    $statement->bind_param('ii', $institutionId, $institutionId);
    $statement->execute();
    $roles = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $roles;
}

function rbac_role_permissions(mysqli $db, int $roleId): array
{
    $statement = $db->prepare(
        'SELECT p.permission_key
         FROM role_permissions rp
         JOIN permissions p ON p.id = rp.permission_id
         WHERE rp.role_id = ?
         ORDER BY p.permission_key'
    );
    $statement->bind_param('i', $roleId);
    $statement->execute();
    $permissions = array_column($statement->get_result()->fetch_all(MYSQLI_ASSOC), 'permission_key');
    $statement->close();
    return array_map('strval', $permissions);
}

function rbac_assignable_staff_roles(mysqli $db, int $institutionId): array
{
    $statement = $db->prepare(
        "SELECT id, name
         FROM roles
         WHERE is_active = 1
           AND ((is_system = 1 AND name = 'staff') OR institution_id = ?)
         ORDER BY is_system DESC, name"
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $roles = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $roles;
}

function rbac_create_role(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    string $name,
    ?string $description,
    ?int $duplicateRoleId = null
): int {
    $actor = rbac_assert_manager_permission($institutionId, 'roles.create');
    $name = trim($name);
    $description = $description !== null && trim($description) !== '' ? trim($description) : null;
    if (!rbac_role_name_is_valid($name) || !rbac_role_description_is_valid($description)) {
        throw new InvalidArgumentException('Enter a role name of 2–80 characters and a description of at most 255 characters.');
    }
    if ((int) $actor['id'] !== $actorUserId) {
        throw new InvalidArgumentException('The role change actor is invalid.');
    }

    $db->begin_transaction();
    try {
        $source = null;
        if ($duplicateRoleId !== null) {
            $source = rbac_role_for_institution($db, $duplicateRoleId, $institutionId, true);
            if ($source === null) {
                throw new OutOfBoundsException('The role to duplicate was not found.');
            }
        }
        $isSystem = 0;
        $statement = $db->prepare(
            'INSERT INTO roles (institution_id, name, description, is_system, is_active)
             VALUES (?, ?, ?, ?, 1)'
        );
        $statement->bind_param('issi', $institutionId, $name, $description, $isSystem);
        $statement->execute();
        $roleId = (int) $db->insert_id;
        $statement->close();

        if ($source !== null) {
            $sourceId = (int) $source['id'];
            $permissionList = array_map(
                static fn(string $permissionKey): string => "'" . $db->real_escape_string($permissionKey) . "'",
                rbac_permission_keys()
            );
            $statement = $db->prepare(
                'INSERT INTO role_permissions (role_id, permission_id)
                 SELECT ?, rp.permission_id
                 FROM role_permissions rp
                 JOIN permissions p ON p.id = rp.permission_id
                 WHERE rp.role_id = ? AND p.permission_key IN (' . implode(',', $permissionList) . ')'
            );
            $statement->bind_param('ii', $roleId, $sourceId);
            $statement->execute();
            $statement->close();
        }
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            (string) $actor['user_type'],
            $duplicateRoleId === null ? 'role.created' : 'role.duplicated',
            'role',
            (string) $roleId,
            null,
            ['name' => $name, 'source_role_id' => $duplicateRoleId]
        );
        $db->commit();
        return $roleId;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function rbac_update_role(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    int $roleId,
    string $name,
    ?string $description
): void {
    $actor = rbac_assert_manager_permission($institutionId, 'roles.edit');
    $name = trim($name);
    $description = $description !== null && trim($description) !== '' ? trim($description) : null;
    if ($actorUserId !== (int) $actor['id']
        || !rbac_role_name_is_valid($name)
        || !rbac_role_description_is_valid($description)) {
        throw new InvalidArgumentException('The role details are invalid.');
    }

    $db->begin_transaction();
    try {
        $role = rbac_role_for_institution($db, $roleId, $institutionId, true);
        if ($role === null || (int) $role['is_system'] === 1) {
            throw new InvalidArgumentException('System roles cannot be changed.');
        }
        $statement = $db->prepare(
            'UPDATE roles SET name = ?, description = ? WHERE id = ? AND institution_id = ? AND is_system = 0'
        );
        $statement->bind_param('ssii', $name, $description, $roleId, $institutionId);
        $statement->execute();
        $statement->close();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            (string) $actor['user_type'],
            'role.updated',
            'role',
            (string) $roleId,
            ['name' => (string) $role['name'], 'description' => $role['description']],
            ['name' => $name, 'description' => $description]
        );
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function rbac_set_role_active(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    int $roleId,
    bool $active
): void {
    $actor = rbac_assert_manager_permission($institutionId, 'roles.edit');
    if ($actorUserId !== (int) $actor['id']) {
        throw new InvalidArgumentException('The role change actor is invalid.');
    }

    $db->begin_transaction();
    try {
        $role = rbac_role_for_institution($db, $roleId, $institutionId, true);
        if ($role === null || (int) $role['is_system'] === 1) {
            throw new InvalidArgumentException('System roles cannot be deactivated.');
        }
        if (!$active) {
            $statement = $db->prepare('SELECT COUNT(*) FROM users WHERE role_id = ? AND institution_id = ?');
            $statement->bind_param('ii', $roleId, $institutionId);
            $statement->execute();
            $assignedUsers = (int) $statement->get_result()->fetch_row()[0];
            $statement->close();
            if ($assignedUsers > 0) {
                throw new InvalidArgumentException('Reassign staff from this role before deactivating it.');
            }
        }
        $newActive = $active ? 1 : 0;
        $statement = $db->prepare(
            'UPDATE roles SET is_active = ? WHERE id = ? AND institution_id = ? AND is_system = 0'
        );
        $statement->bind_param('iii', $newActive, $roleId, $institutionId);
        $statement->execute();
        $statement->close();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            (string) $actor['user_type'],
            $active ? 'role.activated' : 'role.deactivated',
            'role',
            (string) $roleId,
            ['is_active' => (int) $role['is_active']],
            ['is_active' => $newActive]
        );
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function rbac_delete_role(mysqli $db, int $institutionId, int $actorUserId, int $roleId): void
{
    $actor = rbac_assert_manager_permission($institutionId, 'roles.delete');
    if ($actorUserId !== (int) $actor['id']) {
        throw new InvalidArgumentException('The role change actor is invalid.');
    }

    $db->begin_transaction();
    try {
        $role = rbac_role_for_institution($db, $roleId, $institutionId, true);
        if ($role === null || (int) $role['is_system'] === 1) {
            throw new InvalidArgumentException('System roles cannot be deleted.');
        }
        $statement = $db->prepare('SELECT COUNT(*) FROM users WHERE role_id = ? AND institution_id = ?');
        $statement->bind_param('ii', $roleId, $institutionId);
        $statement->execute();
        $assignedUsers = (int) $statement->get_result()->fetch_row()[0];
        $statement->close();
        if ($assignedUsers > 0) {
            throw new InvalidArgumentException('Reassign staff from this role before deleting it.');
        }

        $statement = $db->prepare(
            'DELETE FROM roles WHERE id = ? AND institution_id = ? AND is_system = 0'
        );
        $statement->bind_param('ii', $roleId, $institutionId);
        $statement->execute();
        $statement->close();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            (string) $actor['user_type'],
            'role.deleted',
            'role',
            (string) $roleId,
            ['name' => (string) $role['name']],
            null
        );
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function rbac_save_role_permissions(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    int $roleId,
    array $permissionKeys
): void {
    $actor = rbac_assert_manager_permission($institutionId, 'roles.permissions.manage');
    if ($actorUserId !== (int) $actor['id']) {
        throw new InvalidArgumentException('The role change actor is invalid.');
    }
    $allowedKeys = rbac_permission_keys();
    foreach ($permissionKeys as $key) {
        if (!is_string($key) || !in_array($key, $allowedKeys, true)) {
            throw new InvalidArgumentException('The permission selection contains an unknown permission.');
        }
    }
    $permissionKeys = array_values(array_unique($permissionKeys));

    $db->begin_transaction();
    try {
        $role = rbac_role_for_institution($db, $roleId, $institutionId, true);
        if ($role === null || (int) $role['is_system'] === 1) {
            throw new InvalidArgumentException('Permissions on system roles are protected.');
        }
        $permissionIds = [];
        $result = $db->query('SELECT id, permission_key FROM permissions');
        while ($permission = $result->fetch_assoc()) {
            if (in_array((string) $permission['permission_key'], $permissionKeys, true)) {
                $permissionIds[] = (int) $permission['id'];
            }
        }
        $result->free();
        if (count($permissionIds) !== count($permissionKeys)) {
            throw new RuntimeException('The RBAC migration is incomplete; reload after installing all permission records.');
        }
        $oldPermissions = rbac_role_permissions($db, $roleId);
        $statement = $db->prepare('DELETE FROM role_permissions WHERE role_id = ?');
        $statement->bind_param('i', $roleId);
        $statement->execute();
        $statement->close();
        if ($permissionIds !== []) {
            $statement = $db->prepare(
                'INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)'
            );
            foreach ($permissionIds as $permissionId) {
                $statement->bind_param('ii', $roleId, $permissionId);
                $statement->execute();
            }
            $statement->close();
        }
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            (string) $actor['user_type'],
            'role.permissions_changed',
            'role',
            (string) $roleId,
            ['permissions' => $oldPermissions],
            ['permissions' => $permissionKeys]
        );
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function rbac_assign_staff_role(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    int $staffUserId,
    int $roleId
): void {
    $actor = rbac_assert_manager($institutionId);
    if (!user_has_compatible_permission('staff.assign_role', 'staff.manage')) {
        http_response_code(403);
        exit('Forbidden.');
    }
    if ($actorUserId !== (int) $actor['id'] || $staffUserId < 1 || $roleId < 1) {
        throw new InvalidArgumentException('The staff role assignment is invalid.');
    }

    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            "SELECT id, role_id FROM users
             WHERE id = ? AND institution_id = ? AND user_type = 'staff'
             LIMIT 1 FOR UPDATE"
        );
        $statement->bind_param('ii', $staffUserId, $institutionId);
        $statement->execute();
        $staff = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($staff)) {
            throw new OutOfBoundsException('The staff account was not found in this institution.');
        }
        $role = rbac_role_for_institution($db, $roleId, $institutionId, true);
        if ($role === null
            || (int) $role['is_active'] !== 1
            || ((int) $role['is_system'] === 1 && $role['name'] !== 'staff')) {
            throw new InvalidArgumentException('Choose an active staff role for this institution.');
        }

        $oldRole = $staff['role_id'] === null
            ? null
            : rbac_role_for_institution($db, (int) $staff['role_id'], $institutionId);
        $statement = $db->prepare(
            "UPDATE users SET role_id = ?
             WHERE id = ? AND institution_id = ? AND user_type = 'staff'"
        );
        $statement->bind_param('iii', $roleId, $staffUserId, $institutionId);
        $statement->execute();
        $statement->close();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            (string) $actor['user_type'],
            'staff.role_changed',
            'user',
            (string) $staffUserId,
            ['role_id' => $staff['role_id'], 'role_name' => $oldRole['name'] ?? null],
            ['role_id' => $roleId, 'role_name' => (string) $role['name']]
        );
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}
