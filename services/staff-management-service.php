<?php
declare(strict_types=1);

require_once __DIR__ . '/role-management-service.php';

function staff_management_validate_input(
    array $input,
    bool $requireEmailConfirmation = true,
    bool $requireRole = true,
    bool $requireLastName = true
): array
{
    $firstName = trim(is_string($input['first_name'] ?? null) ? $input['first_name'] : '');
    $lastName = trim(is_string($input['last_name'] ?? null) ? $input['last_name'] : '');
    $email = strtolower(trim(is_string($input['email'] ?? null) ? $input['email'] : ''));
    $phone = trim(is_string($input['phone'] ?? null) ? $input['phone'] : '');
    $employeeCode = trim(is_string($input['employee_code'] ?? null) ? $input['employee_code'] : '');
    $status = is_string($input['status'] ?? null) ? $input['status'] : '';
    $roleId = filter_var($input['role_id'] ?? null, FILTER_VALIDATE_INT);
    $emailConfirmed = ($input['email_confirmed'] ?? null) === '1';
    $errors = [];

    foreach ([[$firstName, 'First name', 75]] as [$value, $label, $maximum]) {
        if ($value === '' || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $maximum) {
            $errors[] = $label . ' is required and must be no more than ' . $maximum . ' characters.';
        }
    }
    if (($requireLastName && $lastName === '')
        || !mb_check_encoding($lastName, 'UTF-8')
        || mb_strlen($lastName, 'UTF-8') > 75) {
        $errors[] = 'Last name is required and must be no more than 75 characters.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
        $errors[] = 'Enter a valid email address.';
    }
    if (strlen($phone) > 20 || ($phone !== '' && preg_match('/^[+0-9().\-\s]+$/D', $phone) !== 1)) {
        $errors[] = 'Enter a valid phone number of no more than 20 characters.';
    }
    if (strlen($employeeCode) > 50 || preg_match('/[\x00-\x1F\x7F]/', $employeeCode) === 1) {
        $errors[] = 'Employee ID must be no more than 50 characters.';
    }
    if (!in_array($status, ['active', 'inactive'], true)) {
        $errors[] = 'Choose an active or inactive account status.';
    }
    if ($requireRole && ($roleId === false || $roleId === null || (int) $roleId < 1)) {
        $errors[] = 'Choose a valid staff role.';
    }
    if ($requireEmailConfirmation && !$emailConfirmed) {
        $errors[] = 'Confirm that the staff member can receive email at this address.';
    }

    return [
        'data' => $errors === [] ? [
            'full_name' => trim($firstName . ' ' . $lastName),
            'email' => $email,
            'phone' => $phone !== '' ? $phone : null,
            'employee_code' => $employeeCode !== '' ? $employeeCode : null,
            'status' => $status,
            'role_id' => $roleId === false || $roleId === null ? 0 : (int) $roleId,
            'email_confirmed' => $emailConfirmed,
        ] : null,
        'errors' => $errors,
    ];
}

function staff_management_list_roles(mysqli $db, int $institutionId): array
{
    $statement = $db->prepare(
        "SELECT DISTINCT r.id, r.name
         FROM roles r
         JOIN users u ON u.role_id = r.id AND u.institution_id = ?
         WHERE (r.is_system = 1 OR r.institution_id = ?)
           AND u.user_type IN ('admin', 'staff', 'super_admin')
         ORDER BY r.name"
    );
    $statement->bind_param('ii', $institutionId, $institutionId);
    $statement->execute();
    $roles = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $roles;
}

function staff_management_get_account(mysqli $db, int $institutionId, int $userId): ?array
{
    $statement = $db->prepare(
        "SELECT u.id, u.role_id, u.full_name, u.email, u.mobile, u.user_type, u.status,
                u.email_verified_at, u.last_login_at, u.created_at, r.name AS role_name,
                sp.employee_code, sp.designation, sp.department, sp.phone
         FROM users u
         LEFT JOIN roles r ON r.id = u.role_id
         LEFT JOIN staff_profiles sp ON sp.institution_id = u.institution_id AND sp.user_id = u.id
         WHERE u.id = ? AND u.institution_id = ? AND u.user_type = 'staff'
         LIMIT 1"
    );
    $statement->bind_param('ii', $userId, $institutionId);
    $statement->execute();
    $account = $statement->get_result()->fetch_assoc();
    $statement->close();
    return is_array($account) ? $account : null;
}

function staff_management_create(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    array $data
): int {
    $actor = rbac_assert_manager_permission($institutionId, 'staff.create');
    if ($actorUserId !== (int) $actor['id'] || !user_has_compatible_permission('staff.assign_role', 'staff.manage')) {
        http_response_code(403);
        exit('Forbidden.');
    }

    $db->begin_transaction();
    try {
        $role = rbac_role_for_institution($db, (int) $data['role_id'], $institutionId, true);
        if ($role === null
            || (int) $role['is_active'] !== 1
            || ((int) $role['is_system'] === 1 && $role['name'] !== 'staff')) {
            throw new InvalidArgumentException('Choose an active staff role for this institution.');
        }

        $status = (string) $data['status'];
        $fullName = (string) $data['full_name'];
        $email = (string) $data['email'];
        $mobile = $data['phone'];
        $roleId = (int) $role['id'];
        $statement = $db->prepare(
            "INSERT INTO users (
                institution_id, role_id, user_type, full_name, email, mobile,
                email_verified_at, status
             ) VALUES (?, ?, 'staff', ?, ?, ?, CURRENT_TIMESTAMP, ?)"
        );
        $statement->bind_param('iissss', $institutionId, $roleId, $fullName, $email, $mobile, $status);
        $statement->execute();
        $userId = (int) $db->insert_id;
        $statement->close();

        $employeeCode = $data['employee_code'];
        $statement = $db->prepare(
            'INSERT INTO staff_profiles (institution_id, user_id, employee_code, phone)
             VALUES (?, ?, ?, ?)'
        );
        $statement->bind_param('iiss', $institutionId, $userId, $employeeCode, $mobile);
        $statement->execute();
        $statement->close();

        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            (string) $actor['user_type'],
            'staff.created',
            'user',
            (string) $userId,
            null,
            [
                'full_name' => $fullName,
                'email' => $email,
                'employee_code' => $employeeCode,
                'role_id' => $roleId,
                'role_name' => (string) $role['name'],
                'status' => $status,
                'email_admin_confirmed' => true,
            ]
        );
        $db->commit();
        return $userId;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function staff_management_update(
    mysqli $db,
    int $institutionId,
    int $staffUserId,
    int $actorUserId,
    array $data
): void {
    $actor = rbac_assert_manager_permission($institutionId, 'staff.edit');
    if ($actorUserId !== (int) $actor['id'] || $staffUserId < 1) {
        throw new InvalidArgumentException('The staff account update is invalid.');
    }

    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            "SELECT id, email, full_name, mobile, status
             FROM users
             WHERE id = ? AND institution_id = ? AND user_type = 'staff'
             LIMIT 1 FOR UPDATE"
        );
        $statement->bind_param('ii', $staffUserId, $institutionId);
        $statement->execute();
        $before = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($before)) {
            throw new OutOfBoundsException('Staff account not found.');
        }

        $emailChanged = strcasecmp((string) $before['email'], (string) $data['email']) !== 0;
        if ($emailChanged && !$data['email_confirmed']) {
            throw new InvalidArgumentException('Confirm that the staff member can receive email at the new address.');
        }
        $fullName = (string) $data['full_name'];
        $email = (string) $data['email'];
        $oldEmail = (string) $before['email'];
        $mobile = $data['phone'];
        $statement = $db->prepare(
            "UPDATE users
             SET full_name = ?, email = ?, mobile = ?,
                 email_verified_at = IF(? <> email, CURRENT_TIMESTAMP, email_verified_at)
             WHERE id = ? AND institution_id = ? AND user_type = 'staff'"
        );
        $statement->bind_param('ssssii', $fullName, $email, $mobile, $oldEmail, $staffUserId, $institutionId);
        $statement->execute();
        $statement->close();

        $employeeCode = $data['employee_code'];
        $statement = $db->prepare(
            'INSERT INTO staff_profiles (institution_id, user_id, employee_code, phone)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE employee_code = VALUES(employee_code), phone = VALUES(phone)'
        );
        $statement->bind_param('iiss', $institutionId, $staffUserId, $employeeCode, $mobile);
        $statement->execute();
        $statement->close();

        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            (string) $actor['user_type'],
            'staff.updated',
            'user',
            (string) $staffUserId,
            [
                'full_name' => (string) $before['full_name'],
                'email' => (string) $before['email'],
                'mobile' => $before['mobile'],
                'status' => (string) $before['status'],
            ],
            [
                'full_name' => $fullName,
                'email' => $email,
                'mobile' => $mobile,
                'employee_code' => $employeeCode,
            ]
        );
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function staff_management_account_page(mysqli $db, int $institutionId, array $filters, int $page): array
{
    $pageSize = 25;
    $page = max(1, min($page, 1000000));
    $roleId = (int) ($filters['role_id'] ?? 0);
    $status = in_array($filters['status'] ?? '', ['active', 'inactive'], true)
        ? (string) $filters['status']
        : '';
    $search = trim((string) ($filters['search'] ?? ''));
    $search = mb_substr($search, 0, 100, 'UTF-8');
    $searchTerm = '%' . $search . '%';
    $where = "u.institution_id = ? AND u.user_type IN ('admin', 'staff', 'super_admin')
              AND (? = 0 OR u.role_id = ?)
              AND (? = '' OR (? = 'active' AND u.status = 'active') OR (? = 'inactive' AND u.status <> 'active'))
              AND (? = '' OR u.full_name LIKE ? OR COALESCE(u.email, '') LIKE ? OR COALESCE(sp.employee_code, '') LIKE ?)";
    $types = 'iiisssssss';
    $statement = $db->prepare(
        "SELECT COUNT(*)
         FROM users u
         LEFT JOIN staff_profiles sp ON sp.institution_id = u.institution_id AND sp.user_id = u.id
         WHERE {$where}"
    );
    $statement->bind_param(
        $types,
        $institutionId,
        $roleId,
        $roleId,
        $status,
        $status,
        $status,
        $search,
        $searchTerm,
        $searchTerm,
        $searchTerm
    );
    $statement->execute();
    $total = (int) $statement->get_result()->fetch_row()[0];
    $statement->close();
    $pages = max(1, (int) ceil($total / $pageSize));
    $page = min($page, $pages);
    $offset = ($page - 1) * $pageSize;

    $statement = $db->prepare(
        "SELECT u.id, u.role_id, u.full_name, u.email, u.mobile, u.user_type, u.status,
                u.email_verified_at, u.last_login_at, u.created_at, r.name AS role_name,
                sp.employee_code, sp.designation, sp.department, sp.phone
         FROM users u
         LEFT JOIN roles r ON r.id = u.role_id
         LEFT JOIN staff_profiles sp ON sp.institution_id = u.institution_id AND sp.user_id = u.id
         WHERE {$where}
         ORDER BY FIELD(u.user_type, 'super_admin', 'admin', 'staff'), u.full_name
         LIMIT ? OFFSET ?"
    );
    $statement->bind_param(
        $types . 'ii',
        $institutionId,
        $roleId,
        $roleId,
        $status,
        $status,
        $status,
        $search,
        $searchTerm,
        $searchTerm,
        $searchTerm,
        $pageSize,
        $offset
    );
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return [
        'rows' => $rows,
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'pages' => $pages,
    ];
}
