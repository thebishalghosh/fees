<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/role-management-service.php';

require_http_methods(['GET', 'POST']);
require_placeholder_route_access();
$db = db_connect();
$user = current_user();
$institutionId = (int) $user['institution_id'];
$actorUserId = (int) $user['id'];
rbac_assert_manager($institutionId);
$roles = rbac_list_roles($db, $institutionId);
$selectedRoleId = filter_var(
    $_POST['role_id'] ?? $_GET['role_id'] ?? null,
    FILTER_VALIDATE_INT
);
if ($selectedRoleId === false || $selectedRoleId === null || (int) $selectedRoleId < 1) {
    $selectedRoleId = isset($roles[0]['id']) ? (int) $roles[0]['id'] : null;
} else {
    $selectedRoleId = (int) $selectedRoleId;
}
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    $action = $_POST['action'] ?? null;
    try {
        if (!is_string($action)) {
            throw new InvalidArgumentException('Choose a valid role action.');
        }
        if ($action === 'create' || $action === 'duplicate') {
            $sourceRoleId = null;
            if ($action === 'duplicate') {
                $sourceRoleId = filter_var($_POST['source_role_id'] ?? null, FILTER_VALIDATE_INT);
                if ($sourceRoleId === false || $sourceRoleId < 1) {
                    throw new InvalidArgumentException('Choose a valid role to duplicate.');
                }
            }
            $roleId = rbac_create_role(
                $db,
                $institutionId,
                $actorUserId,
                is_string($_POST['name'] ?? null) ? $_POST['name'] : '',
                is_string($_POST['description'] ?? null) ? $_POST['description'] : null,
                $sourceRoleId
            );
            set_flash('success', $action === 'duplicate' ? 'Role duplicated.' : 'Role created.');
            safe_redirect('/admin/staff/permissions?role_id=' . $roleId, 303);
        }

        $roleId = filter_var($_POST['role_id'] ?? null, FILTER_VALIDATE_INT);
        if ($roleId === false || $roleId < 1) {
            throw new InvalidArgumentException('The selected role is invalid.');
        }
        $selectedRoleId = (int) $roleId;
        if ($action === 'update') {
            rbac_update_role(
                $db,
                $institutionId,
                $actorUserId,
                $selectedRoleId,
                is_string($_POST['name'] ?? null) ? $_POST['name'] : '',
                is_string($_POST['description'] ?? null) ? $_POST['description'] : null
            );
            set_flash('success', 'Role details updated.');
        } elseif ($action === 'activate' || $action === 'deactivate') {
            rbac_set_role_active($db, $institutionId, $actorUserId, $selectedRoleId, $action === 'activate');
            set_flash('success', $action === 'activate' ? 'Role activated.' : 'Role deactivated.');
        } elseif ($action === 'delete') {
            rbac_delete_role($db, $institutionId, $actorUserId, $selectedRoleId);
            set_flash('success', 'Role deleted.');
            safe_redirect('/admin/staff/permissions', 303);
        } elseif ($action === 'save_permissions') {
            $permissionKeys = $_POST['permissions'] ?? [];
            if (!is_array($permissionKeys)) {
                throw new InvalidArgumentException('The permission selection is invalid.');
            }
            rbac_save_role_permissions($db, $institutionId, $actorUserId, $selectedRoleId, $permissionKeys);
            set_flash('success', 'Role permissions saved.');
        } else {
            throw new InvalidArgumentException('Choose a valid role action.');
        }
        safe_redirect('/admin/staff/permissions?role_id=' . $selectedRoleId, 303);
    } catch (InvalidArgumentException | OutOfBoundsException $exception) {
        $errors[] = $exception->getMessage();
    } catch (Throwable $exception) {
        log_error('role_management_action_failed', [
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
        ]);
        $errors[] = 'The role change could not be completed. Check the role name and try again.';
    }
}

$selectedRole = $selectedRoleId === null
    ? null
    : rbac_role_for_institution($db, $selectedRoleId, $institutionId);
if ($selectedRole === null && $roles !== []) {
    $selectedRole = rbac_role_for_institution($db, (int) $roles[0]['id'], $institutionId);
    $selectedRoleId = is_array($selectedRole) ? (int) $selectedRole['id'] : null;
}
$selectedPermissionKeys = $selectedRoleId === null
    ? []
    : rbac_role_permissions($db, $selectedRoleId);
$permissionResult = $db->query('SELECT permission_key FROM permissions');
$availablePermissionKeys = array_map(
    'strval',
    array_column($permissionResult->fetch_all(MYSQLI_ASSOC), 'permission_key')
);
$permissionResult->free();
$catalog = rbac_permission_catalog();
$missingPermissions = array_values(array_diff(rbac_permission_keys(), $availablePermissionKeys));
$isSystemRole = is_array($selectedRole) && (int) $selectedRole['is_system'] === 1;
$canCreateRole = user_has_compatible_permission('roles.create', 'staff.manage');
$canEditRole = user_has_compatible_permission('roles.edit', 'staff.manage');
$canDeleteRole = user_has_compatible_permission('roles.delete', 'staff.manage');
$canManageRolePermissions = user_has_compatible_permission('roles.permissions.manage', 'staff.manage');
$canEditSelectedRole = is_array($selectedRole)
    && !$isSystemRole
    && (int) $selectedRole['is_active'] === 1
    && $canEditRole;
$canEditSelectedPermissions = is_array($selectedRole)
    && !$isSystemRole
    && (int) $selectedRole['is_active'] === 1
    && $canManageRolePermissions;
$actions = [
    'view' => 'View',
    'create' => 'Create',
    'edit' => 'Edit',
    'delete' => 'Delete',
    'approve' => 'Approve',
    'reject' => 'Reject',
    'export' => 'Export',
    'publish' => 'Publish',
    'reconcile' => 'Reconcile',
    'refund' => 'Refund',
    'manage' => 'Manage',
    'permissions.manage' => 'Manage permissions',
    'assign_role' => 'Assign roles',
];

render_app_page_start(
    'Role Permissions',
    'Create institution roles and control the permissions assigned to staff accounts.',
    'admin',
    ['admin-dashboard.css', 'admin-role-permissions.css']
);
?>
<?php echo render_flash_messages(); ?>
<?php foreach ($errors as $error): ?><div class="alert alert-danger" role="alert"><?php echo e($error); ?></div><?php endforeach; ?>

<section class="dashboard-panel rbac-role-panel" aria-labelledby="rbac-role-list-title">
    <div class="dashboard-panel-heading">
        <div>
            <h2 id="rbac-role-list-title">Roles</h2>
            <p>System roles are shared across institutions and their permissions are protected. Duplicate a system role to create an editable, institution-specific staff role.</p>
        </div>
    </div>
    <div class="rbac-create-grid">
        <?php if ($canCreateRole): ?>
        <form method="post" action="<?php echo e(route_url('/admin/staff/permissions')); ?>" class="rbac-role-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create">
            <h3>Create a role</h3>
            <label class="form-label" for="new-role-name">Role name</label>
            <input class="form-control" id="new-role-name" name="name" maxlength="80" required>
            <label class="form-label mt-2" for="new-role-description">Description</label>
            <textarea class="form-control" id="new-role-description" name="description" rows="2" maxlength="255"></textarea>
            <button class="btn btn-cobalt mt-3" type="submit">Create role</button>
        </form>
        <?php endif; ?>
        <?php if ($canCreateRole && $selectedRole !== null): ?>
            <form method="post" action="<?php echo e(route_url('/admin/staff/permissions')); ?>" class="rbac-role-form">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="duplicate">
                <input type="hidden" name="source_role_id" value="<?php echo e((string) $selectedRole['id']); ?>">
                <h3>Duplicate selected role</h3>
                <p class="text-muted">Copy <?php echo e((string) $selectedRole['name']); ?> and its current permission set into a new custom role.</p>
                <label class="form-label" for="duplicate-role-name">New role name</label>
                <input class="form-control" id="duplicate-role-name" name="name" maxlength="80" required>
                <label class="form-label mt-2" for="duplicate-role-description">Description</label>
                <textarea class="form-control" id="duplicate-role-description" name="description" rows="2" maxlength="255"></textarea>
                <button class="btn btn-outline-primary mt-3" type="submit">Duplicate role</button>
            </form>
        <?php endif; ?>
    </div>
    <div class="table-responsive mt-4">
        <table class="table admin-panel-table align-middle rbac-role-list">
            <thead><tr><th>Role</th><th>Status</th><th>Permissions</th><th>Assigned users</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($roles as $role): ?>
                <tr>
                    <td><a href="<?php echo e(route_url('/admin/staff/permissions?role_id=' . (int) $role['id'])); ?>"><strong><?php echo e((string) $role['name']); ?></strong></a>
                        <?php if ($role['description'] !== null): ?><small><?php echo e((string) $role['description']); ?></small><?php endif; ?>
                        <?php if ((int) $role['is_system'] === 1): ?><small>System role</small><?php endif; ?>
                    </td>
                    <td><?php echo (int) $role['is_active'] === 1 ? 'Active' : 'Inactive'; ?></td>
                    <td><?php echo e((string) $role['permission_count']); ?></td>
                    <td><?php echo e((string) $role['assigned_users']); ?></td>
                    <td><a class="btn btn-sm btn-outline-primary" href="<?php echo e(route_url('/admin/staff/permissions?role_id=' . (int) $role['id'])); ?>">View permissions</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($selectedRole !== null): ?>
    <section class="dashboard-panel rbac-editor-panel" aria-labelledby="rbac-selected-role-title">
        <div class="dashboard-panel-heading">
            <div>
                <span class="dashboard-section-kicker">SELECTED ROLE</span>
                <h2 id="rbac-selected-role-title"><?php echo e((string) $selectedRole['name']); ?></h2>
                <p><?php echo $isSystemRole
                    ? 'This system role is shared across institutions, so its permissions are read-only. Use “Duplicate selected role” above to create an editable role for this institution.'
                    : 'Changes apply to staff assigned to this role on their next request.'; ?></p>
            </div>
            <span class="admin-status-badge is-<?php echo (int) $selectedRole['is_active'] === 1 ? 'active' : 'inactive'; ?>"><?php echo (int) $selectedRole['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span>
        </div>

        <?php if (!$isSystemRole): ?>
            <div class="rbac-role-actions">
                <?php if ($canEditRole): ?>
                <form method="post" action="<?php echo e(route_url('/admin/staff/permissions')); ?>" class="rbac-role-form rbac-role-edit">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="role_id" value="<?php echo e((string) $selectedRole['id']); ?>">
                    <label class="form-label" for="selected-role-name">Role name</label>
                    <input class="form-control" id="selected-role-name" name="name" maxlength="80" value="<?php echo e((string) $selectedRole['name']); ?>" required>
                    <label class="form-label mt-2" for="selected-role-description">Description</label>
                    <textarea class="form-control" id="selected-role-description" name="description" rows="2" maxlength="255"><?php echo e((string) ($selectedRole['description'] ?? '')); ?></textarea>
                    <button class="btn btn-outline-primary mt-3" type="submit" <?php echo !$canEditSelectedRole ? 'disabled' : ''; ?>>Save role details</button>
                </form>
                <?php endif; ?>
                <div class="rbac-role-form rbac-role-maintenance">
                    <h3>Role status and deletion</h3>
                    <?php if ((int) $selectedRole['assigned_users'] > 0): ?><p class="text-muted">Reassign the <?php echo e((string) $selectedRole['assigned_users']); ?> assigned staff member(s) before deactivating or deleting this role.</p><?php endif; ?>
                    <?php if ($canEditRole): ?>
                    <form method="post" action="<?php echo e(route_url('/admin/staff/permissions')); ?>" data-rbac-confirm="<?php echo (int) $selectedRole['is_active'] === 1 ? 'Deactivate this role?' : 'Activate this role?'; ?>">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="role_id" value="<?php echo e((string) $selectedRole['id']); ?>">
                        <input type="hidden" name="action" value="<?php echo (int) $selectedRole['is_active'] === 1 ? 'deactivate' : 'activate'; ?>">
                        <button class="btn btn-outline-secondary" type="submit" <?php echo (int) $selectedRole['assigned_users'] > 0 ? 'disabled' : ''; ?>><?php echo (int) $selectedRole['is_active'] === 1 ? 'Deactivate role' : 'Activate role'; ?></button>
                    </form>
                    <?php endif; ?>
                    <?php if ($canDeleteRole): ?>
                    <form method="post" action="<?php echo e(route_url('/admin/staff/permissions')); ?>" class="mt-2" data-rbac-confirm="Permanently delete this role and its permissions?">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="role_id" value="<?php echo e((string) $selectedRole['id']); ?>">
                        <input type="hidden" name="action" value="delete">
                        <button class="btn btn-outline-danger" type="submit" <?php echo (int) $selectedRole['assigned_users'] > 0 ? 'disabled' : ''; ?>>Delete role</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($missingPermissions !== []): ?>
            <div class="alert alert-warning" role="alert">
                RBAC permission records are missing. Install the RBAC migration before editing permissions.
            </div>
        <?php endif; ?>
        <form method="post" action="<?php echo e(route_url('/admin/staff/permissions')); ?>" data-rbac-confirm="Save this role's permission changes?">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_permissions">
            <input type="hidden" name="role_id" value="<?php echo e((string) $selectedRole['id']); ?>">
            <div class="rbac-matrix-toolbar">
                <div>
                    <button class="btn btn-sm btn-outline-primary" type="button" data-rbac-select-all <?php echo !$canEditSelectedPermissions ? 'disabled' : ''; ?>>Select all</button>
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-rbac-deselect-all <?php echo !$canEditSelectedPermissions ? 'disabled' : ''; ?>>Deselect all</button>
                </div>
                <span><?php echo e((string) count($selectedPermissionKeys)); ?> permissions selected</span>
            </div>
            <div class="table-responsive rbac-matrix-scroll">
                <table class="table table-bordered align-middle rbac-permission-matrix">
                    <thead><tr><th scope="col">Module</th><th scope="col">All</th>
                        <?php foreach ($actions as $label): ?><th scope="col"><?php echo e($label); ?></th><?php endforeach; ?>
                    </tr></thead>
                    <tbody>
                    <?php $moduleIndexCounter = 0; ?>
                    <?php foreach ($catalog as $module => $modulePermissions): ?>
                        <?php $moduleIndex = 'rbac-module-' . $moduleIndexCounter++; ?>
                        <tr data-rbac-module-row="<?php echo e($moduleIndex); ?>">
                            <th scope="row"><?php echo e($module); ?></th>
                            <td><input class="form-check-input" type="checkbox" data-rbac-module-toggle="<?php echo e($moduleIndex); ?>" aria-label="Select all <?php echo e($module); ?> permissions" <?php echo !$canEditSelectedPermissions ? 'disabled' : ''; ?>></td>
                            <?php foreach (array_keys($actions) as $action): ?>
                                <?php
                                $permissionKey = null;
                                foreach ($modulePermissions as $key => $_label) {
                                    if ($action === 'permissions.manage' && str_ends_with($key, '.permissions.manage')) {
                                        $permissionKey = $key;
                                        break;
                                    }
                                    if ($action !== 'permissions.manage'
                                        && !str_ends_with($key, '.permissions.manage')
                                        && str_ends_with($key, '.' . $action)) {
                                        $permissionKey = $key;
                                        break;
                                    }
                                }
                                $permissionExists = $permissionKey !== null
                                    && in_array($permissionKey, $availablePermissionKeys, true);
                                ?>
                                <td>
                                    <?php if ($permissionKey !== null): ?>
                                        <input class="form-check-input" type="checkbox" name="permissions[]" value="<?php echo e($permissionKey); ?>"
                                            data-rbac-permission data-rbac-module="<?php echo e($moduleIndex); ?>"
                                            aria-label="<?php echo e($module . ': ' . $actions[$action]); ?>"
                                            <?php echo in_array($permissionKey, $selectedPermissionKeys, true) ? 'checked ' : ''; ?>
                                            <?php echo (!$canEditSelectedPermissions || !$permissionExists) ? 'disabled ' : ''; ?>>
                                    <?php else: ?><span class="text-muted" aria-hidden="true">—</span><?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button class="btn btn-cobalt" type="submit" <?php echo (!$canEditSelectedPermissions || $missingPermissions !== []) ? 'disabled' : ''; ?>>Save permissions</button>
        </form>
    </section>
<?php endif; ?>
<?php render_app_page_end('admin'); ?>
