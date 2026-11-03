<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/admin-panel-service.php';
require_once __DIR__ . '/../../services/role-management-service.php';
require_once __DIR__ . '/../../services/staff-management-service.php';

require_http_methods(['GET', 'POST']);
require_placeholder_route_access();
$db = db_connect();
$institutionId = admin_panel_institution_id();
$user = current_user();
$actorUserId = (int) $user['id'];
$isRoleManager = in_array($user['user_type'], ['admin', 'super_admin'], true)
    && (user_has_permission('roles.view')
        || (($user['role_is_system'] ?? true) && user_has_permission('staff.manage')));
$canAssignRoles = $isRoleManager && user_has_compatible_permission('staff.assign_role', 'staff.manage');
$canCreateStaff = $isRoleManager
    && user_has_compatible_permission('staff.create', 'staff.manage')
    && $canAssignRoles;
$canEditStaff = $isRoleManager && user_has_compatible_permission('staff.edit', 'staff.manage');
$assignableRoles = $canAssignRoles ? rbac_assignable_staff_roles($db, $institutionId) : [];
$filterRoles = staff_management_list_roles($db, $institutionId);
$errors = [];
$formMode = null;
$formValues = [];
$formAccount = null;
$viewAccount = null;
$postTargetId = null;
$filters = [
    'search' => is_string($_GET['search'] ?? null) ? trim($_GET['search']) : '',
    'role_id' => filter_var($_GET['role_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0,
    'status' => is_string($_GET['status'] ?? null) ? $_GET['status'] : '',
];
$requestedPage = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$page = $requestedPage === false || $requestedPage === null ? 1 : max(1, (int) $requestedPage);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if ($action === 'create' || $action === 'update') {
        $postTargetId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $formMode = $action === 'create' ? 'create' : 'edit';
        $formValues = [];
        foreach (['first_name', 'last_name', 'email', 'phone', 'employee_code', 'status', 'role_id', 'email_confirmed'] as $field) {
            $formValues[$field] = is_string($_POST[$field] ?? null) ? $_POST[$field] : '';
        }
        $validation = staff_management_validate_input(
            $_POST,
            $action === 'create',
            $action === 'create',
            $action === 'create'
        );
        $errors = $validation['errors'];
        if ($action === 'update' && ($postTargetId === false || $postTargetId === null || (int) $postTargetId < 1)) {
            $errors[] = 'The staff account to update is invalid.';
        }

        if ($errors === []) {
            try {
                if ($action === 'create') {
                    staff_management_create($db, $institutionId, $actorUserId, $validation['data']);
                    set_flash('success', 'Staff account created. The staff member can sign in using the existing email OTP workflow.');
                } else {
                    staff_management_update(
                        $db,
                        $institutionId,
                        (int) $postTargetId,
                        $actorUserId,
                        $validation['data']
                    );
                    set_flash('success', 'Staff details updated.');
                }
                safe_redirect('/admin/staff/index', 303);
            } catch (InvalidArgumentException | OutOfBoundsException $exception) {
                $errors[] = $exception->getMessage();
            } catch (mysqli_sql_exception $exception) {
                if ((int) $exception->getCode() === 1062) {
                    $errors[] = 'That email address or employee ID is already in use at this institution.';
                } else {
                    log_error('admin_staff_save_failed', [
                        'error_type' => get_class($exception),
                        'error_code' => $exception->getCode(),
                    ]);
                    $errors[] = 'The staff account could not be saved. Please try again.';
                }
            } catch (Throwable $exception) {
                log_error('admin_staff_save_failed', [
                    'error_type' => get_class($exception),
                    'error_code' => $exception->getCode(),
                ]);
                $errors[] = 'The staff account could not be saved. Please try again.';
            }
        }
    } elseif ($action === 'assign_role') {
        $targetId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $roleId = filter_var($_POST['role_id'] ?? null, FILTER_VALIDATE_INT);
        if ($targetId !== false && $targetId !== null && (int) $targetId > 0) {
            $formMode = 'edit';
            $postTargetId = (int) $targetId;
        }
        if ($targetId === false || $roleId === false) {
            $errors[] = 'The staff role assignment is invalid.';
        } else {
            try {
                rbac_assign_staff_role($db, $institutionId, $actorUserId, (int) $targetId, (int) $roleId);
                set_flash('success', 'Staff role updated. Their permissions take effect on their next request.');
                safe_redirect('/admin/staff/index?edit=' . (int) $targetId, 303);
            } catch (InvalidArgumentException | OutOfBoundsException $exception) {
                $errors[] = $exception->getMessage();
            } catch (Throwable $exception) {
                log_error('admin_staff_role_assignment_failed', [
                    'error_type' => get_class($exception),
                    'error_code' => $exception->getCode(),
                ]);
                $errors[] = 'The staff role could not be updated. Please try again.';
            }
        }
    } elseif ($action === 'set_status') {
        $targetId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $status = $_POST['status'] ?? null;
        if ($targetId === false || !is_string($status)) {
            $errors[] = 'The staff status request is invalid.';
        } else {
            try {
                admin_panel_set_staff_status($db, $institutionId, $actorUserId, (int) $targetId, $status);
                set_flash('success', 'Staff account status updated.');
                safe_redirect('/admin/staff/index', 303);
            } catch (InvalidArgumentException | OutOfBoundsException $exception) {
                $errors[] = $exception->getMessage();
            } catch (Throwable $exception) {
                log_error('admin_staff_status_update_failed', [
                    'error_type' => get_class($exception),
                    'error_code' => $exception->getCode(),
                ]);
                $errors[] = 'The staff account could not be updated. Please try again.';
            }
        }
    } else {
        $errors[] = 'The staff management request is invalid.';
    }

    if ($formMode === 'edit' && is_int($postTargetId) && $postTargetId > 0) {
        $formAccount = staff_management_get_account($db, $institutionId, $postTargetId);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['create'])) {
        $formMode = 'create';
    } elseif (isset($_GET['edit'])) {
        $formMode = 'edit';
        $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
        if ($editId !== false && $editId !== null && (int) $editId > 0) {
            $formAccount = staff_management_get_account($db, $institutionId, (int) $editId);
        }
    } elseif (isset($_GET['view'])) {
        $viewId = filter_var($_GET['view'], FILTER_VALIDATE_INT);
        if ($viewId !== false && $viewId !== null && (int) $viewId > 0) {
            $viewAccount = staff_management_get_account($db, $institutionId, (int) $viewId);
        }
    }
}

if ($formMode === 'create' && !$canCreateStaff) {
    http_response_code(403);
    exit('Forbidden.');
}
if ($formMode === 'edit' && !$canEditStaff) {
    http_response_code(403);
    exit('Forbidden.');
}
if ($formMode === 'edit' && $formAccount === null) {
    http_response_code(404);
    exit('Staff account not found.');
}
if (isset($_GET['view']) && $viewAccount === null) {
    http_response_code(404);
    exit('Staff account not found.');
}

if ($formMode === 'edit' && $formValues === []) {
    $nameParts = preg_split('/\s+/', trim((string) $formAccount['full_name']), 2) ?: [];
    $formValues = [
        'first_name' => $nameParts[0] ?? '',
        'last_name' => $nameParts[1] ?? '',
        'email' => (string) ($formAccount['email'] ?? ''),
        'phone' => (string) ($formAccount['mobile'] ?? ''),
        'employee_code' => (string) ($formAccount['employee_code'] ?? ''),
        'status' => $formAccount['status'] === 'active' ? 'active' : 'inactive',
        'role_id' => (string) ($formAccount['role_id'] ?? ''),
    ];
}
$accountPage = staff_management_account_page($db, $institutionId, $filters, $page);
$accounts = $accountPage['rows'];
$paginationQuery = [
    'search' => $filters['search'],
    'role_id' => $filters['role_id'],
    'status' => $filters['status'],
];
render_app_page_start('Staff & Administrators', 'Manage institution staff accounts, roles, and access status.', 'admin', ['admin-dashboard.css']);
?>
<?php echo render_flash_messages(); ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item active" aria-current="page">Staff &amp; Administrators</li></ol></nav>
<?php foreach ($errors as $error): ?><div class="alert alert-danger" role="alert"><?php echo e($error); ?></div><?php endforeach; ?>

<?php if ($viewAccount !== null): ?>
    <section class="dashboard-panel mb-4">
        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
            <div><h2 class="h5 mb-1"><?php echo e((string) $viewAccount['full_name']); ?></h2><p class="text-muted mb-0">Staff account details</p></div>
            <a class="btn btn-sm btn-outline-secondary" href="<?php echo e(route_url('/admin/staff/index')); ?>">Back to staff</a>
        </div>
        <dl class="row mb-0">
            <dt class="col-sm-3">Employee ID</dt><dd class="col-sm-9"><?php echo e((string) ($viewAccount['employee_code'] ?: '—')); ?></dd>
            <dt class="col-sm-3">Email</dt><dd class="col-sm-9"><?php echo e((string) ($viewAccount['email'] ?: '—')); ?></dd>
            <dt class="col-sm-3">Phone</dt><dd class="col-sm-9"><?php echo e((string) ($viewAccount['mobile'] ?: '—')); ?></dd>
            <dt class="col-sm-3">Role</dt><dd class="col-sm-9"><?php echo e((string) ($viewAccount['role_name'] ?? 'Unassigned')); ?></dd>
            <dt class="col-sm-3">Status</dt><dd class="col-sm-9"><?php echo e(ucwords((string) $viewAccount['status'])); ?></dd>
            <dt class="col-sm-3">Email verification</dt><dd class="col-sm-9"><?php echo $viewAccount['email_verified_at'] !== null ? 'Admin-confirmed' : 'Not verified'; ?></dd>
            <dt class="col-sm-3">Date added</dt><dd class="col-sm-9"><?php echo e((string) $viewAccount['created_at']); ?></dd>
            <dt class="col-sm-3">Last login</dt><dd class="col-sm-9"><?php echo e((string) ($viewAccount['last_login_at'] ?? 'Never')); ?></dd>
        </dl>
    </section>
<?php endif; ?>

<?php if ($formMode !== null): ?>
    <?php
    $isCreating = $formMode === 'create';
    $formAction = $isCreating ? 'create' : 'update';
    $nameParts = [];
    if (!$isCreating && $formValues === []) {
        $nameParts = preg_split('/\s+/', trim((string) $formAccount['full_name']), 2) ?: [];
    }
    $firstNameValue = (string) ($formValues['first_name'] ?? $nameParts[0] ?? '');
    $lastNameValue = (string) ($formValues['last_name'] ?? $nameParts[1] ?? '');
    ?>
    <section class="dashboard-panel mb-4">
        <h2 class="h5 mb-3"><?php echo $isCreating ? 'Add staff member' : 'Edit staff member'; ?></h2>
        <?php if ($isCreating): ?><p class="text-muted">The account will use the existing staff email OTP sign-in. Confirm that the address belongs to the staff member.</p><?php endif; ?>
        <form method="post" action="<?php echo e(route_url('/admin/staff/index')); ?>" class="row g-3">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="<?php echo e($formAction); ?>">
            <?php if (!$isCreating): ?><input type="hidden" name="user_id" value="<?php echo e((string) $formAccount['id']); ?>"><?php endif; ?>
            <div class="col-md-6"><label class="form-label" for="staff-first-name">First name</label><input class="form-control" id="staff-first-name" name="first_name" maxlength="75" required value="<?php echo e($firstNameValue); ?>"></div>
            <div class="col-md-6"><label class="form-label" for="staff-last-name">Last name</label><input class="form-control" id="staff-last-name" name="last_name" maxlength="75"<?php echo $isCreating ? ' required' : ''; ?> value="<?php echo e($lastNameValue); ?>"></div>
            <div class="col-md-6"><label class="form-label" for="staff-email">Email</label><input class="form-control" id="staff-email" type="email" name="email" maxlength="255" required value="<?php echo e((string) ($formValues['email'] ?? $formAccount['email'] ?? '')); ?>"></div>
            <div class="col-md-6"><label class="form-label" for="staff-phone">Phone</label><input class="form-control" id="staff-phone" type="tel" name="phone" maxlength="20" value="<?php echo e((string) ($formValues['phone'] ?? $formAccount['mobile'] ?? '')); ?>"></div>
            <div class="col-md-6"><label class="form-label" for="staff-employee-code">Employee ID</label><input class="form-control" id="staff-employee-code" name="employee_code" maxlength="50" value="<?php echo e((string) ($formValues['employee_code'] ?? $formAccount['employee_code'] ?? '')); ?>"></div>
            <?php if ($isCreating): ?>
                <div class="col-md-6"><label class="form-label" for="staff-role">Assigned role</label>
                    <select class="form-select" id="staff-role" name="role_id" required>
                        <option value="">Choose a role</option>
                        <?php foreach ($assignableRoles as $role): ?>
                            <option value="<?php echo e((string) $role['id']); ?>"<?php echo (string) ($formValues['role_id'] ?? '') === (string) $role['id'] ? ' selected' : ''; ?>><?php echo e((string) $role['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php else: ?>
                <input type="hidden" name="role_id" value="<?php echo e((string) ($formAccount['role_id'] ?? '')); ?>">
            <?php endif; ?>
            <?php if ($isCreating): ?>
                <div class="col-md-6"><label class="form-label" for="staff-status">Account status</label>
                    <select class="form-select" id="staff-status" name="status" required>
                        <?php foreach (['active' => 'Active', 'inactive' => 'Inactive'] as $statusValue => $statusLabel): ?>
                            <option value="<?php echo e($statusValue); ?>"<?php echo ($formValues['status'] ?? 'active') === $statusValue ? ' selected' : ''; ?>><?php echo e($statusLabel); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php else: ?>
                <input type="hidden" name="status" value="<?php echo $formAccount['status'] === 'active' ? 'active' : 'inactive'; ?>">
            <?php endif; ?>
            <div class="col-12">
                <label class="form-check">
                    <input class="form-check-input" type="checkbox" name="email_confirmed" value="1"<?php echo ($formValues['email_confirmed'] ?? null) === '1' ? ' checked' : ''; ?>>
                    <span class="form-check-label">I confirm this staff member can receive email at the address above<?php echo $isCreating ? ' (required)' : ' (required if changing the address)'; ?>.</span>
                </label>
            </div>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary" type="submit"><?php echo $isCreating ? 'Create staff account' : 'Save staff details'; ?></button>
                <a class="btn btn-outline-secondary" href="<?php echo e(route_url('/admin/staff/index')); ?>">Cancel</a>
            </div>
        </form>
        <?php if (!$isCreating && $canAssignRoles && $assignableRoles !== []): ?>
            <hr class="my-4">
            <h3 class="h6 mb-3">Assigned role</h3>
            <form method="post" action="<?php echo e(route_url('/admin/staff/index')); ?>" class="row g-2 align-items-end">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="assign_role">
                <input type="hidden" name="user_id" value="<?php echo e((string) $formAccount['id']); ?>">
                <div class="col-md-6">
                    <label class="form-label" for="staff-edit-role">Role</label>
                    <select class="form-select" id="staff-edit-role" name="role_id" required>
                        <?php foreach ($assignableRoles as $role): ?>
                            <option value="<?php echo e((string) $role['id']); ?>"<?php echo (int) $formAccount['role_id'] === (int) $role['id'] ? ' selected' : ''; ?>><?php echo e((string) $role['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto"><button class="btn btn-outline-primary" type="submit">Save role</button></div>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($viewAccount === null): ?>
<section class="dashboard-panel">
    <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-3">
        <div><h2 class="h5 mb-1">Staff accounts</h2><p class="text-muted mb-0"><?php echo e((string) $accountPage['total']); ?> matching account(s)</p></div>
        <?php if ($canCreateStaff): ?><a class="btn btn-primary" href="<?php echo e(route_url('/admin/staff/index') . '?create=1'); ?>"><i class="bi bi-person-plus" aria-hidden="true"></i> Add staff</a><?php endif; ?>
    </div>
    <form method="get" action="<?php echo e(route_url('/admin/staff/index')); ?>" class="row g-2 align-items-end mb-3">
        <div class="col-md-5"><label class="form-label" for="staff-search">Search</label><input class="form-control" id="staff-search" name="search" value="<?php echo e($filters['search']); ?>" placeholder="Name, email, or employee ID"></div>
        <div class="col-md-3"><label class="form-label" for="staff-role-filter">Role</label>
            <select class="form-select" id="staff-role-filter" name="role_id"><option value="0">All roles</option>
                <?php foreach ($filterRoles as $role): ?><option value="<?php echo e((string) $role['id']); ?>"<?php echo (int) $filters['role_id'] === (int) $role['id'] ? ' selected' : ''; ?>><?php echo e((string) $role['name']); ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><label class="form-label" for="staff-status-filter">Status</label><select class="form-select" id="staff-status-filter" name="status">
            <option value="">All statuses</option><option value="active"<?php echo $filters['status'] === 'active' ? ' selected' : ''; ?>>Active</option><option value="inactive"<?php echo $filters['status'] === 'inactive' ? ' selected' : ''; ?>>Inactive</option>
        </select></div>
        <div class="col-md-2 d-flex gap-2"><button class="btn btn-outline-primary" type="submit">Filter</button><a class="btn btn-outline-secondary" href="<?php echo e(route_url('/admin/staff/index')); ?>">Clear</a></div>
    </form>
    <?php if ($accounts === []): ?>
        <div class="admin-panel-empty"><i class="bi bi-person-badge" aria-hidden="true"></i><strong><?php echo $accountPage['total'] === 0 ? 'No staff accounts found' : 'No accounts on this page'; ?></strong><span><?php echo $accountPage['total'] === 0 ? 'Try adjusting the search or filters, or add a staff member.' : 'Use the pagination controls to view other matching accounts.'; ?></span></div>
    <?php else: ?>
        <div class="table-responsive"><table class="table admin-panel-table align-middle"><thead><tr><th>Staff ID / Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Status</th><th>Date added</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($accounts as $account): ?>
            <tr>
                <td><strong><?php echo e((string) $account['full_name']); ?></strong><small><?php echo e(($account['employee_code'] ? 'Employee ID ' . $account['employee_code'] . ' · ' : '') . '#' . $account['id']); ?></small></td>
                <td><?php echo e((string) ($account['email'] ?? '—')); ?></td>
                <td><?php echo e((string) ($account['mobile'] ?? '—')); ?></td>
                <td>
                    <?php echo e((string) ($account['role_name'] ?? $account['user_type'])); ?>
                </td>
                <td><span class="admin-status-badge is-<?php echo e((string) $account['status']); ?>"><?php echo e(ucwords((string) $account['status'])); ?></span></td>
                <td><?php echo e((string) $account['created_at']); ?></td>
                <td class="text-nowrap">
                    <?php if ($account['user_type'] === 'staff'): ?><a class="btn btn-sm btn-outline-secondary" href="<?php echo e(route_url('/admin/staff/index') . '?view=' . (int) $account['id']); ?>">View</a><?php endif; ?>
                    <?php if ($account['user_type'] === 'staff' && $canEditStaff): ?>
                        <a class="btn btn-sm btn-outline-primary" href="<?php echo e(route_url('/admin/staff/index') . '?edit=' . (int) $account['id']); ?>">Edit</a>
                        <?php if ($account['status'] === 'active'): ?>
                            <form method="post" action="<?php echo e(route_url('/admin/staff/index')); ?>" class="d-inline" onsubmit="return confirm('Deactivate this staff account? Existing sessions will be revoked.');">
                                <?php echo csrf_field(); ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="user_id" value="<?php echo e((string) $account['id']); ?>"><input type="hidden" name="status" value="inactive"><button class="btn btn-sm btn-outline-danger" type="submit">Deactivate</button>
                            </form>
                        <?php elseif ($account['email_verified_at'] !== null): ?>
                            <form method="post" action="<?php echo e(route_url('/admin/staff/index')); ?>" class="d-inline">
                                <?php echo csrf_field(); ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="user_id" value="<?php echo e((string) $account['id']); ?>"><input type="hidden" name="status" value="active"><button class="btn btn-sm btn-outline-primary" type="submit">Activate</button>
                            </form>
                        <?php else: ?><span class="text-muted">Email confirmation required</span><?php endif; ?>
                    <?php elseif ($account['user_type'] === 'staff'): ?><span class="text-muted">Restricted</span>
                    <?php else: ?><span class="text-muted">Protected account</span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <?php if ($accountPage['pages'] > 1): ?>
            <nav aria-label="Staff account pages"><ul class="pagination justify-content-end">
                <li class="page-item<?php echo $page <= 1 ? ' disabled' : ''; ?>"><a class="page-link" href="<?php echo e(route_url('/admin/staff/index') . '?' . http_build_query([...$paginationQuery, 'page' => max(1, $page - 1)])); ?>" aria-label="Previous">Previous</a></li>
                <li class="page-item disabled"><span class="page-link">Page <?php echo e((string) $page); ?> of <?php echo e((string) $accountPage['pages']); ?></span></li>
                <li class="page-item<?php echo $page >= $accountPage['pages'] ? ' disabled' : ''; ?>"><a class="page-link" href="<?php echo e(route_url('/admin/staff/index') . '?' . http_build_query([...$paginationQuery, 'page' => min($accountPage['pages'], $page + 1)])); ?>" aria-label="Next">Next</a></li>
            </ul></nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php render_app_page_end('admin'); ?>
