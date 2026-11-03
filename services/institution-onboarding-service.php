<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/admin-panel-service.php';
require_once __DIR__ . '/audit-service.php';

function institution_onboarding_assert_super_admin(): array
{
    require_login();
    $user = current_user();
    if ($user === null
        || $user['user_type'] !== 'super_admin'
        || ($user['role_is_system'] ?? false) !== true) {
        http_response_code(403);
        exit('Forbidden.');
    }

    return $user;
}

function institution_onboarding_bind(mysqli_stmt $statement, string $types, array &$values): void
{
    $parameters = [$types];
    foreach ($values as &$value) {
        $parameters[] = &$value;
    }
    unset($value);
    if (!call_user_func_array([$statement, 'bind_param'], $parameters)) {
        throw new RuntimeException('The institution onboarding query could not bind its parameters.');
    }
}

function institution_onboarding_validate_input(array $input, bool $creating): array
{
    $profile = admin_panel_validate_institution($input);
    foreach (['code', 'email', 'phone', 'address_line1'] as $requiredField) {
        if (!is_string($profile[$requiredField]) || trim($profile[$requiredField]) === '') {
            throw new InvalidArgumentException('Complete the institution code, official contact details, and address.');
        }
    }

    if (!$creating) {
        return $profile;
    }

    $status = $input['status'] ?? 'active';
    if (!is_string($status) || !in_array($status, ['active', 'inactive'], true)) {
        throw new InvalidArgumentException('Choose an active or inactive institution status.');
    }

    $administratorName = $input['administrator_name'] ?? null;
    $administratorEmail = $input['administrator_email'] ?? null;
    $administratorPhone = $input['administrator_phone'] ?? null;
    if (!is_string($administratorName)
        || !is_string($administratorEmail)
        || !is_string($administratorPhone)) {
        throw new InvalidArgumentException('Enter the initial administrator details.');
    }
    $administratorName = trim($administratorName);
    $administratorEmail = strtolower(trim($administratorEmail));
    $administratorPhone = trim($administratorPhone);
    if ($administratorName === ''
        || mb_strlen($administratorName, 'UTF-8') > 150
        || preg_match('/[\x00-\x1F\x7F]/', $administratorName) === 1) {
        throw new InvalidArgumentException('Enter an administrator name using no more than 150 characters.');
    }
    if ($administratorEmail === ''
        || strlen($administratorEmail) > 255
        || filter_var($administratorEmail, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('Enter a valid initial administrator email address.');
    }
    if ($administratorPhone === ''
        || strlen($administratorPhone) > 20
        || preg_match('/^[+0-9().\-\s]+$/D', $administratorPhone) !== 1) {
        throw new InvalidArgumentException('Enter an initial administrator phone number using no more than 20 characters.');
    }
    if (($input['administrator_email_confirmed'] ?? null) !== '1') {
        throw new InvalidArgumentException('Confirm that the initial administrator can receive email at this address.');
    }

    return $profile + [
        'status' => $status,
        'administrator_name' => $administratorName,
        'administrator_email' => $administratorEmail,
        'administrator_phone' => $administratorPhone,
    ];
}

function institution_onboarding_admin_role_id(mysqli $db): int
{
    $statement = $db->prepare(
        "SELECT id FROM roles
         WHERE name = 'admin' AND is_system = 1
           AND institution_id IS NULL AND is_active = 1
         LIMIT 1"
    );
    $statement->execute();
    $role = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_array($role)) {
        throw new RuntimeException('The active global Admin system role is unavailable.');
    }

    return (int) $role['id'];
}

function institution_onboarding_list(
    mysqli $db,
    string $search = '',
    string $status = ''
): array {
    institution_onboarding_assert_super_admin();
    $search = trim($search);
    if (mb_strlen($search, 'UTF-8') > 120) {
        throw new InvalidArgumentException('The institution search is too long.');
    }
    if ($status !== '' && !in_array($status, ['active', 'inactive', 'trial', 'archived'], true)) {
        throw new InvalidArgumentException('Choose a supported institution status filter.');
    }

    $where = [];
    $types = '';
    $values = [];
    if ($search !== '') {
        $where[] = '(i.name LIKE ? OR i.code LIKE ? OR i.email LIKE ?
            OR admin.full_name LIKE ? OR admin.email LIKE ?)';
        $pattern = '%' . $search . '%';
        $types .= 'sssss';
        array_push($values, $pattern, $pattern, $pattern, $pattern, $pattern);
    }
    if ($status !== '') {
        $where[] = 'i.status = ?';
        $types .= 's';
        $values[] = $status;
    }

    $query = 'SELECT i.id, i.name, i.code, i.institution_type, i.email,
                     i.currency_code, i.status, i.created_at,
                     admin.full_name AS administrator_name,
                     admin.email AS administrator_email
              FROM institutions i
              LEFT JOIN users admin
                ON admin.id = (
                    SELECT MIN(candidate.id) FROM users candidate
                    WHERE candidate.institution_id = i.id AND candidate.user_type = \'admin\'
                )';
    if ($where !== []) {
        $query .= ' WHERE ' . implode(' AND ', $where);
    }
    $query .= ' ORDER BY i.created_at DESC, i.id DESC LIMIT 500';
    $statement = $db->prepare($query);
    if ($values !== []) {
        institution_onboarding_bind($statement, $types, $values);
    }
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();

    return $rows;
}

function institution_onboarding_get(mysqli $db, int $institutionId): ?array
{
    institution_onboarding_assert_super_admin();
    if ($institutionId < 1) {
        return null;
    }
    $statement = $db->prepare(
        'SELECT id, name, short_name, code, institution_type, email, phone,
                address_line1, address_line2, city, state, postal_code, country,
                website, logo_path, principal_name, principal_email, principal_phone,
                registration_number, affiliation_body, affiliation_number,
                established_year, currency_code, status, created_at, updated_at,
                (SELECT COUNT(*) FROM students s WHERE s.institution_id = institutions.id) AS student_count,
                (SELECT COUNT(*) FROM users staff
                 WHERE staff.institution_id = institutions.id AND staff.user_type = \'staff\') AS staff_count
         FROM institutions WHERE id = ? LIMIT 1'
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $institution = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_array($institution)) {
        return null;
    }
    $institution['student_count'] = (int) $institution['student_count'];
    $institution['staff_count'] = (int) $institution['staff_count'];

    $statement = $db->prepare(
        "SELECT id, full_name, email, mobile, status, email_verified_at, created_at
         FROM users
         WHERE institution_id = ? AND user_type = 'admin'
         ORDER BY id LIMIT 1"
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $institution['administrator'] = $statement->get_result()->fetch_assoc() ?: null;
    $statement->close();

    return $institution;
}

function institution_onboarding_create(mysqli $db, array $input): int
{
    $actor = institution_onboarding_assert_super_admin();
    $values = institution_onboarding_validate_input($input, true);
    $db->begin_transaction();
    try {
        $roleId = institution_onboarding_admin_role_id($db);
        $institutionFields = [
            'name', 'short_name', 'code', 'institution_type', 'email', 'phone',
            'address_line1', 'address_line2', 'city', 'state', 'postal_code',
            'country', 'website', 'principal_name', 'principal_email',
            'principal_phone', 'registration_number', 'affiliation_body',
            'affiliation_number', 'established_year', 'currency_code', 'status',
        ];
        $columns = implode(', ', $institutionFields);
        $placeholders = implode(', ', array_fill(0, count($institutionFields), '?'));
        $statement = $db->prepare(
            'INSERT INTO institutions (' . $columns . ') VALUES (' . $placeholders . ')'
        );
        $institutionValues = array_map(
            static fn(string $field): mixed => $values[$field],
            $institutionFields
        );
        institution_onboarding_bind(
            $statement,
            str_repeat('s', 19) . 'iss',
            $institutionValues
        );
        $statement->execute();
        $institutionId = (int) $statement->insert_id;
        $statement->close();

        $administratorStatus = 'active';
        $statement = $db->prepare(
            "INSERT INTO users (
                institution_id, role_id, user_type, full_name, email, mobile,
                email_verified_at, status
             ) VALUES (?, ?, 'admin', ?, ?, ?, CURRENT_TIMESTAMP, ?)"
        );
        $statement->bind_param(
            'iissss',
            $institutionId,
            $roleId,
            $values['administrator_name'],
            $values['administrator_email'],
            $values['administrator_phone'],
            $administratorStatus
        );
        $statement->execute();
        $administratorId = (int) $statement->insert_id;
        $statement->close();

        write_audit_log(
            $db,
            $institutionId,
            (int) $actor['id'],
            'super_admin',
            'institution.created',
            'institution',
            (string) $institutionId,
            null,
            array_intersect_key($values, array_flip($institutionFields))
        );
        write_audit_log(
            $db,
            $institutionId,
            (int) $actor['id'],
            'super_admin',
            'institution.initial_admin_created',
            'user',
            (string) $administratorId,
            null,
            [
                'full_name' => $values['administrator_name'],
                'email' => $values['administrator_email'],
                'status' => $administratorStatus,
                'role_id' => $roleId,
                'email_confirmed_by_super_admin' => true,
            ]
        );
        $db->commit();

        return $institutionId;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function institution_onboarding_update(mysqli $db, int $institutionId, array $input): void
{
    $actor = institution_onboarding_assert_super_admin();
    $values = institution_onboarding_validate_input($input, false);
    if ($institutionId < 1) {
        throw new InvalidArgumentException('The institution selection is invalid.');
    }
    $before = institution_onboarding_get($db, $institutionId);
    if ($before === null) {
        throw new OutOfBoundsException('Institution not found.');
    }

    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            'UPDATE institutions
             SET name = ?, short_name = ?, code = ?, email = ?, phone = ?,
                 address_line1 = ?, address_line2 = ?, city = ?, state = ?,
                 postal_code = ?, country = ?, website = ?, institution_type = ?,
                 principal_name = ?, principal_email = ?, principal_phone = ?,
                 registration_number = ?, affiliation_body = ?, affiliation_number = ?,
                 established_year = ?, currency_code = ?
             WHERE id = ?'
        );
        $fields = [
            'name', 'short_name', 'code', 'email', 'phone', 'address_line1',
            'address_line2', 'city', 'state', 'postal_code', 'country', 'website',
            'institution_type', 'principal_name', 'principal_email', 'principal_phone',
            'registration_number', 'affiliation_body', 'affiliation_number',
            'established_year', 'currency_code',
        ];
        $bindValues = array_map(static fn(string $field): mixed => $values[$field], $fields);
        $bindValues[] = $institutionId;
        institution_onboarding_bind($statement, str_repeat('s', 19) . 'isi', $bindValues);
        $statement->execute();
        $statement->close();

        write_audit_log(
            $db,
            $institutionId,
            (int) $actor['id'],
            'super_admin',
            'institution.profile_updated',
            'institution',
            (string) $institutionId,
            array_intersect_key($before, $values),
            $values
        );
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function institution_onboarding_set_status(
    mysqli $db,
    int $institutionId,
    string $status
): void {
    $actor = institution_onboarding_assert_super_admin();
    if ($institutionId < 1 || !in_array($status, ['active', 'inactive'], true)) {
        throw new InvalidArgumentException('Choose an active or inactive institution status.');
    }

    $db->begin_transaction();
    try {
        $statement = $db->prepare('SELECT status FROM institutions WHERE id = ? LIMIT 1 FOR UPDATE');
        $statement->bind_param('i', $institutionId);
        $statement->execute();
        $before = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($before)) {
            throw new OutOfBoundsException('Institution not found.');
        }
        if ($before['status'] === $status) {
            $db->commit();
            return;
        }

        $statement = $db->prepare('UPDATE institutions SET status = ? WHERE id = ?');
        $statement->bind_param('si', $status, $institutionId);
        $statement->execute();
        $statement->close();
        write_audit_log(
            $db,
            $institutionId,
            (int) $actor['id'],
            'super_admin',
            $status === 'active' ? 'institution.activated' : 'institution.deactivated',
            'institution',
            (string) $institutionId,
            ['status' => (string) $before['status']],
            ['status' => $status]
        );
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}
