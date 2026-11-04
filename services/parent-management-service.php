<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/otp-service.php';
require_once __DIR__ . '/audit-service.php';

const PARENT_MANAGEMENT_PAGE_SIZE = 15;

function parent_management_bind_params(mysqli_stmt $statement, string $types, array &$values): void
{
    if ($types === '') {
        return;
    }

    $parameters = [$types];
    foreach ($values as $index => &$value) {
        $parameters[] = &$value;
    }
    unset($value);
    if (!call_user_func_array([$statement, 'bind_param'], $parameters)) {
        throw new RuntimeException('The parent management query could not bind its parameters.');
    }
}

function parent_management_institution_id(): int
{
    $institutionId = authenticated_institution_id(['super_admin', 'admin', 'staff']);
    if ($institutionId === null) {
        http_response_code(403);
        exit('Institution access is unavailable for this account.');
    }

    return $institutionId;
}

function parent_management_is_super_admin(): bool
{
    $user = current_user();
    return $user !== null
        && $user['user_type'] === 'super_admin'
        && ($user['role_is_system'] ?? false) === true;
}

function parent_management_resolve_parent_institution_id(mysqli $db, int $parentId): ?int
{
    if ($parentId < 1) {
        return null;
    }
    if (!parent_management_is_super_admin()) {
        return parent_management_institution_id();
    }

    $statement = $db->prepare(
        "SELECT p.institution_id
         FROM parents p
         JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
         JOIN roles r ON r.id = u.role_id AND r.name = 'parent'
         WHERE p.id = ? AND u.user_type = 'parent'
         LIMIT 1"
    );
    $statement->bind_param('i', $parentId);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc();
    $statement->close();

    return is_array($row) ? (int) $row['institution_id'] : null;
}

function parent_management_get_active_institutions(mysqli $db): array
{
    $result = $db->query(
        "SELECT id, name FROM institutions WHERE status = 'active' ORDER BY name, id"
    );
    $institutions = [];
    while ($institution = $result->fetch_assoc()) {
        $institution['id'] = (int) $institution['id'];
        $institutions[] = $institution;
    }

    return $institutions;
}

function parent_management_create_actor_record(mysqli $db, int $actorUserId, string $actorType): array
{
    if ($actorUserId < 1 || !in_array($actorType, ['super_admin', 'admin', 'staff'], true)) {
        throw new OutOfBoundsException('This account is not authorized to create parent profiles.');
    }

    $statement = $db->prepare(
        'SELECT u.id, u.institution_id, u.user_type, u.status, u.role_id,
                r.name AS role_name, r.is_system AS role_is_system,
                r.institution_id AS role_institution_id, r.is_active AS role_is_active,
                i.status AS institution_status
         FROM users u
         JOIN roles r ON r.id = u.role_id
         JOIN institutions i ON i.id = u.institution_id
         WHERE u.id = ?
         LIMIT 1'
    );
    $statement->bind_param('i', $actorUserId);
    $statement->execute();
    $actor = $statement->get_result()->fetch_assoc();
    $statement->close();

    if (!is_array($actor)
        || $actor['user_type'] !== $actorType
        || $actor['status'] !== 'active'
        || $actor['institution_status'] !== 'active'
        || !authenticated_role_is_valid(
            (string) $actor['user_type'],
            (string) $actor['role_name'],
            (int) $actor['role_is_system'] === 1,
            $actor['role_institution_id'] === null ? null : (int) $actor['role_institution_id'],
            (int) $actor['institution_id'],
            (int) $actor['role_is_active'] === 1
        )) {
        throw new OutOfBoundsException('This account is not authorized to create parent profiles.');
    }

    $roleId = (int) $actor['role_id'];
    $permissionStatement = $db->prepare(
        'SELECT p.permission_key
         FROM role_permissions rp
         JOIN permissions p ON p.id = rp.permission_id
         WHERE rp.role_id = ?
           AND p.permission_key IN (\'parents.create\', \'parents.manage\')'
    );
    $permissionStatement->bind_param('i', $roleId);
    $permissionStatement->execute();
    $permissions = [];
    $permissionResult = $permissionStatement->get_result();
    while ($permission = $permissionResult->fetch_assoc()) {
        $permissions[] = (string) $permission['permission_key'];
    }
    $permissionStatement->close();

    $isSystemRole = (int) $actor['role_is_system'] === 1;
    if (!in_array('parents.create', $permissions, true)
        && !($isSystemRole && in_array('parents.manage', $permissions, true))) {
        throw new OutOfBoundsException('Your role does not have permission to create parent profiles.');
    }

    $actor['institution_id'] = (int) $actor['institution_id'];
    return $actor;
}

function parent_management_resolve_create_institution(
    mysqli $db,
    array $authenticatedUser,
    mixed $submittedInstitutionId
): int {
    $actorId = filter_var($authenticatedUser['id'] ?? null, FILTER_VALIDATE_INT);
    $actorType = $authenticatedUser['user_type'] ?? null;
    $actorInstitutionId = filter_var($authenticatedUser['institution_id'] ?? null, FILTER_VALIDATE_INT);
    if ($actorId === false || $actorInstitutionId === false || !is_string($actorType)) {
        throw new OutOfBoundsException('This account is not authorized to create parent profiles.');
    }

    $actor = parent_management_create_actor_record($db, (int) $actorId, $actorType);
    if ((int) $actorInstitutionId !== $actor['institution_id']
        || (isset($authenticatedUser['role_is_system'])
            && $authenticatedUser['role_is_system'] !== ((int) $actor['role_is_system'] === 1))) {
        throw new OutOfBoundsException('The authenticated account no longer matches its institution or role.');
    }

    if ($actorType === 'super_admin') {
        if (!parent_management_is_super_admin()) {
            throw new OutOfBoundsException('Only a system Super Admin can choose a parent institution.');
        }
        $selectedInstitutionId = filter_var($submittedInstitutionId, FILTER_VALIDATE_INT);
        if ($selectedInstitutionId === false || $selectedInstitutionId < 1) {
            throw new InvalidArgumentException('Choose an active institution for the new parent.');
        }
        $institutionId = (int) $selectedInstitutionId;
    } else {
        $institutionId = $actor['institution_id'];
        if ($submittedInstitutionId !== null && $submittedInstitutionId !== '') {
            $submittedId = filter_var($submittedInstitutionId, FILTER_VALIDATE_INT);
            if ($submittedId === false || $submittedId < 1) {
                throw new InvalidArgumentException('The submitted institution is invalid.');
            }
            if ((int) $submittedId !== $institutionId) {
                throw new OutOfBoundsException('You cannot create a parent in another institution.');
            }
        }
    }

    $statement = $db->prepare(
        "SELECT id FROM institutions WHERE id = ? AND status = 'active' LIMIT 1"
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $activeInstitution = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_array($activeInstitution)) {
        throw new InvalidArgumentException('Choose an existing active institution.');
    }

    return $institutionId;
}

function parent_management_assert_create_authorized(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    string $actorType
): void {
    $actor = parent_management_create_actor_record($db, $actorUserId, $actorType);
    $authenticatedUser = current_user();
    if ($authenticatedUser === null
        || (int) $authenticatedUser['id'] !== $actorUserId
        || $authenticatedUser['user_type'] !== $actorType
        || (int) $authenticatedUser['institution_id'] !== $actor['institution_id']
        || (isset($authenticatedUser['role_is_system'])
            && $authenticatedUser['role_is_system'] !== ((int) $actor['role_is_system'] === 1))
        || $institutionId < 1
        || ($actorType !== 'super_admin' && $actor['institution_id'] !== $institutionId)) {
        throw new OutOfBoundsException('You cannot create a parent in another institution.');
    }

    $statement = $db->prepare(
        "SELECT id FROM institutions WHERE id = ? AND status = 'active' LIMIT 1"
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $activeInstitution = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_array($activeInstitution)) {
        throw new InvalidArgumentException('Choose an existing active institution.');
    }
}

function parent_management_prepare_list_filters(
    ?int $institutionId,
    string $search,
    string $status,
    string $link
): array {
    $where = [];
    $values = [];
    $types = '';
    if ($institutionId !== null) {
        $where[] = 'p.institution_id = ?';
        $values[] = $institutionId;
        $types .= 'i';
    }

    if ($status !== '') {
        $where[] = 'p.status = ?';
        $values[] = $status;
        $types .= 's';
    }

    $linkedCondition = "EXISTS (
        SELECT 1 FROM parent_student_relationships linked
        WHERE linked.institution_id = p.institution_id
          AND linked.parent_id = p.id AND linked.status = 'active'
    )";
    if ($link === 'linked') {
        $where[] = $linkedCondition;
    } elseif ($link === 'unlinked') {
        $where[] = 'NOT ' . $linkedCondition;
    }

    if ($search !== '') {
        $literalSearch = strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']);
        $pattern = '%' . $literalSearch . '%';
        $where[] = "(p.full_name LIKE ? ESCAPE '!'
            OR COALESCE(p.email, u.email, '') LIKE ? ESCAPE '!'
            OR p.mobile LIKE ? ESCAPE '!')";
        array_push($values, $pattern, $pattern, $pattern);
        $types .= 'sss';
    }

    return [implode(' AND ', $where), $types, $values];
}

function parent_management_get_summary(mysqli $db, int $institutionId): array
{
    $isSuperAdmin = parent_management_is_super_admin();
    $institutionFilter = $isSuperAdmin ? '' : ' AND p.institution_id = ?';
    $statement = $db->prepare(
        'SELECT COUNT(DISTINCT p.id) AS total_count,
                COUNT(DISTINCT IF(p.status = \'active\', p.id, NULL)) AS active_count,
                COUNT(DISTINCT IF(p.status = \'inactive\', p.id, NULL)) AS inactive_count,
                COUNT(DISTINCT IF(psr.id IS NOT NULL, p.id, NULL)) AS linked_count
         FROM parents p
         JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
         JOIN roles r ON r.id = u.role_id AND r.name = \'parent\'
         LEFT JOIN parent_student_relationships psr
           ON psr.institution_id = p.institution_id
          AND psr.parent_id = p.id AND psr.status = \'active\'
         WHERE u.user_type = \'parent\'' . $institutionFilter
    );
    if (!$isSuperAdmin) {
        $statement->bind_param('i', $institutionId);
    }
    $statement->execute();
    $summary = $statement->get_result()->fetch_assoc();
    $statement->close();

    if (!is_array($summary)) {
        throw new RuntimeException('Parent summary data could not be loaded.');
    }

    return array_map('intval', $summary);
}

function parent_management_list(
    mysqli $db,
    ?int $institutionId,
    string $search,
    string $status,
    string $link,
    int $page
): array {
    [$where, $filterTypes, $filterValues] = parent_management_prepare_list_filters(
        $institutionId,
        $search,
        $status,
        $link
    );
    $whereClause = $where === '' ? '' : $where . ' AND ';

    $countStatement = $db->prepare(
        "SELECT COUNT(*) AS result_count
         FROM parents p
         JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
         JOIN roles r ON r.id = u.role_id AND r.name = 'parent'
         WHERE {$whereClause}u.user_type = 'parent'"
    );
    parent_management_bind_params($countStatement, $filterTypes, $filterValues);
    $countStatement->execute();
    $total = (int) $countStatement->get_result()->fetch_assoc()['result_count'];
    $countStatement->close();

    $pageSize = PARENT_MANAGEMENT_PAGE_SIZE;
    $totalPages = max(1, (int) ceil($total / $pageSize));
    $page = min(max(1, $page), $totalPages);
    $offset = ($page - 1) * $pageSize;
    $queryValues = [...$filterValues, $pageSize, $offset];
    $queryTypes = $filterTypes . 'ii';
    $statement = $db->prepare(
        "SELECT p.id, p.institution_id, i.name AS institution_name,
                p.full_name, p.mobile, COALESCE(p.email, u.email) AS email,
                p.relationship_to_student, p.status, p.created_at,
                (u.email_verified_at IS NOT NULL) AS email_verified,
                COUNT(DISTINCT IF(psr.status = 'active', psr.student_id, NULL)) AS linked_student_count
         FROM parents p
         JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
         JOIN roles r ON r.id = u.role_id AND r.name = 'parent'
         JOIN institutions i ON i.id = p.institution_id
         LEFT JOIN parent_student_relationships psr
           ON psr.institution_id = p.institution_id AND psr.parent_id = p.id
         WHERE {$whereClause}u.user_type = 'parent'
         GROUP BY p.id, p.institution_id, i.name, u.id
         ORDER BY p.created_at DESC, p.id DESC
         LIMIT ? OFFSET ?"
    );
    parent_management_bind_params($statement, $queryTypes, $queryValues);
    $statement->execute();
    $result = $statement->get_result();
    $parents = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['institution_id'] = (int) $row['institution_id'];
        $row['linked_student_count'] = (int) $row['linked_student_count'];
        $parents[] = $row;
    }
    $statement->close();

    return [
        'parents' => $parents,
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => $totalPages,
    ];
}

function parent_management_get_student_options(mysqli $db, int $institutionId, array $currentIds = []): array
{
    $statement = $db->prepare(
        'SELECT id, student_code, full_name, status
         FROM students WHERE institution_id = ?
         ORDER BY full_name, student_code'
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $result = $statement->get_result();
    $students = [];
    while ($student = $result->fetch_assoc()) {
        $student['id'] = (int) $student['id'];
        $student['currently_linked'] = in_array($student['id'], $currentIds, true);
        $students[] = $student;
    }
    $statement->close();

    return $students;
}

function parent_management_get_parent(mysqli $db, int $institutionId, int $parentId): ?array
{
    $statement = $db->prepare(
        'SELECT p.id, p.institution_id, p.user_id, p.full_name, p.mobile,
                p.alternate_mobile, COALESCE(p.email, u.email) AS email, p.relationship_to_student,
                p.address_line1, p.address_line2, p.city, p.state, p.postal_code,
                p.country, p.emergency_contact, p.status, p.mobile_verified_at,
                p.created_at, p.updated_at, u.status AS account_status,
                (u.email_verified_at IS NOT NULL) AS email_verified
         FROM parents p
         JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
         JOIN roles r ON r.id = u.role_id AND r.name = \'parent\'
         WHERE p.institution_id = ? AND p.id = ? AND u.user_type = \'parent\'
         LIMIT 1'
    );
    $statement->bind_param('ii', $institutionId, $parentId);
    $statement->execute();
    $parent = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_array($parent)) {
        return null;
    }

    $parent['id'] = (int) $parent['id'];
    $parent['user_id'] = (int) $parent['user_id'];
    $parent['email_verified'] = (bool) $parent['email_verified'];
    $parent['linked_students'] = parent_management_get_linked_students($db, $institutionId, $parentId);
    $parent['linked_student_ids'] = array_column($parent['linked_students'], 'id');

    return $parent;
}

function parent_management_get_linked_students(mysqli $db, int $institutionId, int $parentId): array
{
    $statement = $db->prepare(
        'SELECT psr.id AS relationship_id, psr.student_id AS id,
                psr.relationship_type, psr.is_primary,
                psr.can_view_academics, psr.can_view_fees, psr.can_make_payments,
                psr.status AS relationship_status,
                s.student_code, s.full_name, s.status AS student_status
         FROM parent_student_relationships psr
         JOIN students s ON s.institution_id = psr.institution_id AND s.id = psr.student_id
         WHERE psr.institution_id = ? AND psr.parent_id = ? AND psr.status = \'active\'
         ORDER BY psr.is_primary DESC, s.full_name, s.student_code'
    );
    $statement->bind_param('ii', $institutionId, $parentId);
    $statement->execute();
    $result = $statement->get_result();
    $students = [];
    while ($student = $result->fetch_assoc()) {
        $student['id'] = (int) $student['id'];
        $students[] = $student;
    }
    $statement->close();

    return $students;
}

function parent_management_get_affiliation_context(mysqli $db, int $institutionId, int $parentId): ?array
{
    $statement = $db->prepare(
        'SELECT i.id, i.name, i.status
         FROM parents p
         JOIN institutions i ON i.id = p.institution_id
         WHERE p.institution_id = ? AND p.id = ?
         LIMIT 1'
    );
    $statement->bind_param('ii', $institutionId, $parentId);
    $statement->execute();
    $institution = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_array($institution)) {
        return null;
    }

    $statement = $db->prepare(
        'SELECT psr.id AS relationship_id, psr.student_id, psr.relationship_type,
                psr.is_primary, psr.can_view_academics, psr.can_view_fees,
                psr.can_make_payments, psr.status AS relationship_status,
                psr.created_at, psr.updated_at, s.institution_id AS student_institution_id,
                si.name AS student_institution_name, s.student_code, s.full_name,
                s.status AS student_status, s.academic_year_id,
                s.course_id, s.class_id, s.batch_id
         FROM parent_student_relationships psr
         JOIN students s ON s.institution_id = psr.institution_id AND s.id = psr.student_id
         JOIN institutions si ON si.id = s.institution_id
         WHERE psr.institution_id = ? AND psr.parent_id = ?
         ORDER BY s.full_name, s.student_code, psr.id'
    );
    $statement->bind_param('ii', $institutionId, $parentId);
    $statement->execute();
    $result = $statement->get_result();
    $students = [];
    while ($student = $result->fetch_assoc()) {
        foreach (['relationship_id', 'student_id', 'student_institution_id'] as $field) {
            $student[$field] = (int) $student[$field];
        }
        foreach (['academic_year_id', 'course_id', 'class_id', 'batch_id'] as $field) {
            $student[$field] = $student[$field] === null ? null : (int) $student[$field];
        }
        $students[] = $student;
    }
    $statement->close();

    return ['current_institution' => $institution, 'students' => $students];
}

function parent_management_affiliation_reference_counts(
    mysqli $db,
    string $referencedTable,
    int $recordId,
    array $excludedTables = []
): array {
    $statement = $db->prepare(
        'SELECT DISTINCT TABLE_NAME, COLUMN_NAME
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND REFERENCED_TABLE_NAME = ?
           AND REFERENCED_COLUMN_NAME = \'id\''
    );
    $statement->bind_param('s', $referencedTable);
    $statement->execute();
    $result = $statement->get_result();
    $references = [];
    while ($reference = $result->fetch_assoc()) {
        $references[] = $reference;
    }
    $statement->close();

    $counts = [];
    foreach ($references as $reference) {
        $table = (string) $reference['TABLE_NAME'];
        $column = (string) $reference['COLUMN_NAME'];
        if (in_array($table, $excludedTables, true)) {
            continue;
        }
        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $table) !== 1
            || preg_match('/\A[A-Za-z0-9_]+\z/D', $column) !== 1) {
            throw new RuntimeException('An affiliation dependency has an unsupported database identifier.');
        }

        $query = 'SELECT COUNT(*) AS result_count FROM `' . $table . '` WHERE `' . $column . '` = ?';
        $countStatement = $db->prepare($query);
        $countStatement->bind_param('i', $recordId);
        $countStatement->execute();
        $count = (int) $countStatement->get_result()->fetch_assoc()['result_count'];
        $countStatement->close();
        if ($count > 0) {
            $counts[$table] = ($counts[$table] ?? 0) + $count;
        }
    }

    return $counts;
}

function parent_management_user_tenant_reference_counts(mysqli $db, int $userId): array
{
    $statement = $db->prepare(
        'SELECT DISTINCT TABLE_NAME, CONSTRAINT_NAME
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND REFERENCED_TABLE_NAME = \'users\'
           AND REFERENCED_COLUMN_NAME = \'institution_id\''
    );
    $statement->execute();
    $constraints = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();

    $counts = [];
    foreach ($constraints as $constraint) {
        $table = (string) $constraint['TABLE_NAME'];
        $constraintName = (string) $constraint['CONSTRAINT_NAME'];
        $columns = $db->prepare(
            'SELECT COLUMN_NAME, REFERENCED_COLUMN_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?'
        );
        $columns->bind_param('ss', $table, $constraintName);
        $columns->execute();
        $columnRows = $columns->get_result()->fetch_all(MYSQLI_ASSOC);
        $columns->close();
        $userColumn = null;
        foreach ($columnRows as $columnRow) {
            if ($columnRow['REFERENCED_COLUMN_NAME'] === 'id') {
                $userColumn = (string) $columnRow['COLUMN_NAME'];
                break;
            }
        }
        if ($userColumn === null
            || preg_match('/\A[A-Za-z0-9_]+\z/D', $table) !== 1
            || preg_match('/\A[A-Za-z0-9_]+\z/D', $userColumn) !== 1) {
            throw new RuntimeException('A user affiliation dependency has an unsupported database identifier.');
        }
        if (in_array($table, ['user_sessions', 'user_otp_verifications'], true)) {
            continue;
        }

        $countStatement = $db->prepare(
            'SELECT COUNT(*) AS result_count FROM `' . $table . '` WHERE `' . $userColumn . '` = ?'
        );
        $countStatement->bind_param('i', $userId);
        $countStatement->execute();
        $count = (int) $countStatement->get_result()->fetch_assoc()['result_count'];
        $countStatement->close();
        if ($count > 0) {
            $counts[$table] = ($counts[$table] ?? 0) + $count;
        }
    }

    return $counts;
}

function parent_management_validate_profile(array $input): array
{
    $errors = [];
    $name = isset($input['full_name']) && is_string($input['full_name']) ? trim($input['full_name']) : '';
    $email = isset($input['email']) && is_string($input['email']) ? strtolower(trim($input['email'])) : '';
    $rawMobile = isset($input['mobile']) && is_string($input['mobile']) ? trim($input['mobile']) : '';
    $mobile = normalize_mobile_number($rawMobile);
    $relationship = isset($input['relationship_to_student']) && is_string($input['relationship_to_student'])
        ? strtolower(trim($input['relationship_to_student']))
        : '';
    $alternateMobileInput = isset($input['alternate_mobile']) && is_string($input['alternate_mobile'])
        ? trim($input['alternate_mobile'])
        : '';
    $alternateMobile = $alternateMobileInput === '' ? null : normalize_mobile_number($alternateMobileInput);
    $emergencyInput = isset($input['emergency_contact']) && is_string($input['emergency_contact'])
        ? trim($input['emergency_contact'])
        : '';
    $emergencyContact = $emergencyInput === '' ? null : normalize_mobile_number($emergencyInput);
    $address = [];
    foreach ([
        'address_line1' => 255,
        'address_line2' => 255,
        'city' => 120,
        'state' => 120,
        'postal_code' => 20,
        'country' => 120,
    ] as $field => $maxLength) {
        if (array_key_exists($field, $input) && !is_string($input[$field])) {
            $errors[] = 'One or more address fields contain unsupported values.';
            $address[$field] = '';
            continue;
        }
        $address[$field] = isset($input[$field]) && is_string($input[$field])
            ? trim($input[$field])
            : '';
        if (mb_strlen($address[$field], 'UTF-8') > $maxLength
            || preg_match('/[\x00-\x1F\x7F]/', $address[$field]) === 1) {
            $errors[] = 'One or more address fields contain unsupported values or are too long.';
            continue;
        }
    }
    if ($address['country'] === '') {
        $address['country'] = 'India';
    }
    if (array_key_exists('alternate_mobile', $input) && !is_string($input['alternate_mobile'])) {
        $errors[] = 'The alternate mobile value is invalid.';
    }
    if (array_key_exists('emergency_contact', $input) && !is_string($input['emergency_contact'])) {
        $errors[] = 'The emergency contact value is invalid.';
    }

    if ($name === '' || mb_strlen($name, 'UTF-8') > 150 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
        $errors[] = 'Enter a parent name using no more than 150 characters.';
    }
    if (!validate_email($email) || mb_strlen($email, 'UTF-8') > 255) {
        $errors[] = 'Enter a valid email address using no more than 255 characters.';
    }
    if ($mobile === null || strlen($mobile) > 20) {
        $errors[] = 'Enter a valid mobile number with 8 to 15 digits.';
    }
    if ($alternateMobileInput !== '' && ($alternateMobile === null || strlen($alternateMobile) > 20)) {
        $errors[] = 'Enter a valid alternate mobile number or leave it blank.';
    }
    if ($emergencyInput !== '' && ($emergencyContact === null || strlen($emergencyContact) > 30)) {
        $errors[] = 'Enter a valid emergency contact mobile number or leave it blank.';
    }
    if (!in_array($relationship, ['father', 'mother', 'guardian', 'other'], true)) {
        $errors[] = 'Choose a supported relationship to the student.';
    }

    return [
        'data' => [
            'full_name' => $name,
            'email' => $email,
            'mobile' => $mobile ?? $rawMobile,
            'alternate_mobile' => $alternateMobile,
            'relationship_to_student' => $relationship,
            'address_line1' => $address['address_line1'] !== '' ? $address['address_line1'] : null,
            'address_line2' => $address['address_line2'] !== '' ? $address['address_line2'] : null,
            'city' => $address['city'] !== '' ? $address['city'] : null,
            'state' => $address['state'] !== '' ? $address['state'] : null,
            'postal_code' => $address['postal_code'] !== '' ? $address['postal_code'] : null,
            'country' => $address['country'],
            'emergency_contact' => $emergencyContact,
        ],
        'errors' => $errors,
    ];
}

function parent_management_old_form_values(array $input, string $form, ?int $parentId = null): array
{
    $values = ['form' => $form];
    if ($parentId !== null) {
        $values['parent_id'] = $parentId;
    }
    foreach ([
        'full_name',
        'email',
        'mobile',
        'alternate_mobile',
        'relationship_to_student',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'postal_code',
        'country',
        'emergency_contact',
        'initial_status',
        'institution_id',
        'affiliation_target_institution_id',
    ] as $field) {
        if (array_key_exists($field, $input)) {
            $values[$field] = is_string($input[$field]) ? trim($input[$field]) : '';
        }
    }
    $values['student_ids'] = [];
    if (is_array($input['student_ids'] ?? null)) {
        foreach ($input['student_ids'] as $studentId) {
            if (is_string($studentId) || is_int($studentId)) {
                $values['student_ids'][] = (string) $studentId;
            }
        }
    }
    $values['affiliation_student_ids'] = [];
    if (is_array($input['affiliation_student_ids'] ?? null)) {
        foreach ($input['affiliation_student_ids'] as $studentId) {
            if (is_string($studentId) || is_int($studentId)) {
                $values['affiliation_student_ids'][] = (string) $studentId;
            }
        }
    }

    return $values;
}

function parent_management_student_ids(mixed $rawIds): array
{
    if ($rawIds === null) {
        return [];
    }
    if (!is_array($rawIds)) {
        throw new InvalidArgumentException('The selected students are invalid.');
    }

    $ids = [];
    foreach ($rawIds as $rawId) {
        if (!is_string($rawId) && !is_int($rawId)) {
            throw new InvalidArgumentException('The selected students are invalid.');
        }
        $id = filter_var((string) $rawId, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            throw new InvalidArgumentException('The selected students are invalid.');
        }
        $ids[] = (int) $id;
    }

    if (count($ids) > 100) {
        throw new InvalidArgumentException('Select no more than 100 students at a time.');
    }

    return array_values(array_unique($ids));
}

function parent_management_assert_unique_contact(
    mysqli $db,
    int $institutionId,
    string $email,
    string $mobile,
    ?int $excludeUserId = null
): void {
    $query = 'SELECT id FROM users WHERE institution_id = ? AND (email = ? OR mobile = ?)';
    if ($excludeUserId !== null) {
        $query .= ' AND id <> ?';
    }
    $query .= ' LIMIT 1';
    $statement = $db->prepare($query);
    if ($excludeUserId === null) {
        $statement->bind_param('iss', $institutionId, $email, $mobile);
    } else {
        $statement->bind_param('issi', $institutionId, $email, $mobile, $excludeUserId);
    }
    $statement->execute();
    $conflict = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (is_array($conflict)) {
        throw new InvalidArgumentException(
            'That email address or mobile number is already used by an account in this institution.'
        );
    }

}

function parent_management_assert_students_linkable(
    mysqli $db,
    int $institutionId,
    int $parentId,
    array $studentIds
): void {
    if ($studentIds === []) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    $statement = $db->prepare(
        "SELECT s.id, s.status,
                EXISTS (
                    SELECT 1 FROM parent_student_relationships psr
                    WHERE psr.institution_id = s.institution_id
                      AND psr.parent_id = ? AND psr.student_id = s.id
                      AND psr.status = 'active'
                ) AS already_linked
         FROM students s
         WHERE s.institution_id = ? AND s.id IN ({$placeholders})"
    );
    $values = [$parentId, $institutionId, ...$studentIds];
    parent_management_bind_params($statement, 'ii' . str_repeat('i', count($studentIds)), $values);
    $statement->execute();
    $result = $statement->get_result();
    $found = [];
    while ($student = $result->fetch_assoc()) {
        $studentId = (int) $student['id'];
        $found[$studentId] = in_array($student['status'], ['admitted', 'active'], true)
            || (bool) $student['already_linked'];
    }
    $statement->close();

    foreach ($studentIds as $studentId) {
        if (!isset($found[$studentId]) || !$found[$studentId]) {
            throw new InvalidArgumentException(
                'One or more selected students are unavailable for linking in this institution.'
            );
        }
    }
}

function parent_management_sync_links(
    mysqli $db,
    int $institutionId,
    int $parentId,
    string $relationship,
    array $studentIds
): void {
    $statement = $db->prepare(
        'SELECT student_id FROM parent_student_relationships
         WHERE institution_id = ? AND parent_id = ? AND status = \'active\''
    );
    $statement->bind_param('ii', $institutionId, $parentId);
    $statement->execute();
    $result = $statement->get_result();
    $existingIds = [];
    while ($row = $result->fetch_assoc()) {
        $existingIds[] = (int) $row['student_id'];
    }
    $statement->close();

    foreach (array_diff($existingIds, $studentIds) as $studentId) {
        $statement = $db->prepare(
            'UPDATE parent_student_relationships
             SET status = \'revoked\', updated_at = CURRENT_TIMESTAMP
             WHERE institution_id = ? AND parent_id = ? AND student_id = ? AND status = \'active\''
        );
        $studentId = (int) $studentId;
        $statement->bind_param('iii', $institutionId, $parentId, $studentId);
        $statement->execute();
        $statement->close();
    }

    foreach ($studentIds as $studentId) {
        $statement = $db->prepare(
            'SELECT id, status FROM parent_student_relationships
             WHERE institution_id = ? AND parent_id = ? AND student_id = ? LIMIT 1'
        );
        $statement->bind_param('iii', $institutionId, $parentId, $studentId);
        $statement->execute();
        $existing = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (is_array($existing)) {
            $relationshipId = (int) $existing['id'];
            $statement = $db->prepare(
                'UPDATE parent_student_relationships
                 SET relationship_type = ?, status = \'active\', updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND institution_id = ? AND parent_id = ?'
            );
            $statement->bind_param('siii', $relationship, $relationshipId, $institutionId, $parentId);
            $statement->execute();
            $statement->close();
        } else {
            $statement = $db->prepare(
                'INSERT INTO parent_student_relationships
                    (institution_id, parent_id, student_id, relationship_type, status)
                 VALUES (?, ?, ?, ?, \'active\')'
            );
            $statement->bind_param('iiis', $institutionId, $parentId, $studentId, $relationship);
            $statement->execute();
            $statement->close();
        }
    }
}

function parent_management_audit_values(array $parent, array $linkedStudentIds): array
{
    return [
        'full_name' => (string) $parent['full_name'],
        'email' => (string) $parent['email'],
        'mobile' => (string) $parent['mobile'],
        'alternate_mobile' => $parent['alternate_mobile'],
        'relationship_to_student' => (string) $parent['relationship_to_student'],
        'address_line1' => $parent['address_line1'],
        'address_line2' => $parent['address_line2'],
        'city' => $parent['city'],
        'state' => $parent['state'],
        'postal_code' => $parent['postal_code'],
        'country' => $parent['country'],
        'emergency_contact' => $parent['emergency_contact'],
        'status' => (string) $parent['status'],
        'email_verified' => (bool) ($parent['email_verified'] ?? false),
        'mobile_verified' => ($parent['mobile_verified_at'] ?? null) !== null,
        'linked_student_ids' => array_values(array_map('intval', $linkedStudentIds)),
    ];
}

function parent_management_create(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    string $actorType,
    array $profile,
    string $initialStatus,
    array $studentIds
): int {
    if (!in_array($initialStatus, ['pending_verification', 'inactive'], true)) {
        throw new InvalidArgumentException('The initial parent status is invalid.');
    }

    $db->begin_transaction();
    try {
        parent_management_assert_create_authorized($db, $institutionId, $actorUserId, $actorType);
        parent_management_assert_unique_contact(
            $db,
            $institutionId,
            $profile['email'],
            $profile['mobile']
        );
        $statement = $db->prepare(
            "SELECT id FROM roles
             WHERE BINARY name = BINARY 'parent' AND is_system = 1
               AND institution_id IS NULL AND is_active = 1
             LIMIT 1"
        );
        $statement->execute();
        $role = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($role)) {
            throw new RuntimeException('The parent role is not configured.');
        }
        $roleId = (int) $role['id'];
        $accountStatus = $initialStatus === 'inactive' ? 'inactive' : 'pending';
        $statement = $db->prepare(
            'INSERT INTO users (
                institution_id, role_id, user_type, full_name, email, mobile,
                email_verified_at, status
             ) VALUES (?, ?, \'parent\', ?, ?, ?, NULL, ?)'
        );
        $statement->bind_param(
            'iissss',
            $institutionId,
            $roleId,
            $profile['full_name'],
            $profile['email'],
            $profile['mobile'],
            $accountStatus
        );
        $statement->execute();
        $userId = (int) $db->insert_id;
        $statement->close();

        $statement = $db->prepare(
            'INSERT INTO parents (
                institution_id, user_id, full_name, mobile, alternate_mobile, email,
                relationship_to_student, address_line1, address_line2, city, state,
                postal_code, country, emergency_contact, status
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->bind_param(
            'iisssssssssssss',
            $institutionId,
            $userId,
            $profile['full_name'],
            $profile['mobile'],
            $profile['alternate_mobile'],
            $profile['email'],
            $profile['relationship_to_student'],
            $profile['address_line1'],
            $profile['address_line2'],
            $profile['city'],
            $profile['state'],
            $profile['postal_code'],
            $profile['country'],
            $profile['emergency_contact'],
            $initialStatus
        );
        $statement->execute();
        $parentId = (int) $db->insert_id;
        $statement->close();

        parent_management_assert_students_linkable($db, $institutionId, $parentId, $studentIds);
        parent_management_sync_links(
            $db,
            $institutionId,
            $parentId,
            $profile['relationship_to_student'],
            $studentIds
        );
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            $actorType,
            'parent.created',
            'parent',
            (string) $parentId,
            null,
            [
                'full_name' => $profile['full_name'],
                'email' => $profile['email'],
                'mobile' => $profile['mobile'],
                'relationship_to_student' => $profile['relationship_to_student'],
                'status' => $initialStatus,
                'linked_student_ids' => $studentIds,
            ]
        );
        $db->commit();
        return $parentId;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function parent_management_update(
    mysqli $db,
    int $institutionId,
    int $parentId,
    int $actorUserId,
    string $actorType,
    array $profile,
    array $studentIds
): void {
    $db->begin_transaction();
    try {
        $lock = $db->prepare(
            'SELECT p.id FROM parents p
             JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
             JOIN roles r ON r.id = u.role_id AND r.name = \'parent\'
             WHERE p.institution_id = ? AND p.id = ? AND u.user_type = \'parent\' FOR UPDATE'
        );
        $lock->bind_param('ii', $institutionId, $parentId);
        $lock->execute();
        $lockedParent = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!is_array($lockedParent)) {
            throw new OutOfBoundsException('The parent record was not found.');
        }
        $parent = parent_management_get_parent($db, $institutionId, $parentId);
        if ($parent === null) {
            throw new OutOfBoundsException('The parent record was not found.');
        }
        parent_management_assert_unique_contact(
            $db,
            $institutionId,
            $profile['email'],
            $profile['mobile'],
            $parent['user_id']
        );
        parent_management_assert_students_linkable($db, $institutionId, $parentId, $studentIds);
        $oldValues = parent_management_audit_values($parent, $parent['linked_student_ids']);
        $emailChanged = $profile['email'] !== strtolower((string) $parent['email']);
        $mobileChanged = $profile['mobile'] !== (string) $parent['mobile'];
        $emailChangedInt = $emailChanged ? 1 : 0;
        $mobileChangedInt = $mobileChanged ? 1 : 0;

        $statement = $db->prepare(
            'UPDATE parents
             SET full_name = ?, email = ?, mobile = ?, alternate_mobile = ?,
                 relationship_to_student = ?, address_line1 = ?, address_line2 = ?,
                 city = ?, state = ?, postal_code = ?, country = ?, emergency_contact = ?,
                 status = IF(? = 1 AND status = \'active\', \'pending_verification\', status),
                 mobile_verified_at = IF(? = 1, NULL, mobile_verified_at)
             WHERE id = ? AND institution_id = ?'
        );
        $statement->bind_param(
            'ssssssssssssiiii',
            $profile['full_name'],
            $profile['email'],
            $profile['mobile'],
            $profile['alternate_mobile'],
            $profile['relationship_to_student'],
            $profile['address_line1'],
            $profile['address_line2'],
            $profile['city'],
            $profile['state'],
            $profile['postal_code'],
            $profile['country'],
            $profile['emergency_contact'],
            $emailChangedInt,
            $mobileChangedInt,
            $parentId,
            $institutionId
        );
        $statement->execute();
        $statement->close();

        if ($emailChanged) {
            $statement = $db->prepare(
                'UPDATE users
                 SET full_name = ?, email = ?, mobile = ?, email_verified_at = NULL,
                     mobile_verified_at = IF(? = 1, NULL, mobile_verified_at),
                     status = IF(status = \'active\', \'pending\', status)
                 WHERE id = ? AND institution_id = ? AND user_type = \'parent\''
            );
        } else {
            $statement = $db->prepare(
                'UPDATE users
                 SET full_name = ?, email = ?, mobile = ?,
                     mobile_verified_at = IF(? = 1, NULL, mobile_verified_at)
                 WHERE id = ? AND institution_id = ? AND user_type = \'parent\''
            );
        }
        $statement->bind_param(
            'sssiii',
            $profile['full_name'],
            $profile['email'],
            $profile['mobile'],
            $mobileChangedInt,
            $parent['user_id'],
            $institutionId
        );
        $statement->execute();
        if ($statement->affected_rows === 0 && $statement->errno !== 0) {
            $statement->close();
            throw new RuntimeException('The parent account could not be updated.');
        }
        $statement->close();

        if ($emailChanged) {
            $statement = $db->prepare(
                "UPDATE user_otp_verifications
                 SET status = 'invalidated'
                 WHERE institution_id = ? AND user_id = ?
                   AND purpose = 'email_verification' AND status IN ('pending', 'failed')"
            );
            $statement->bind_param('ii', $institutionId, $parent['user_id']);
            $statement->execute();
            $statement->close();
            $statement = $db->prepare(
                'UPDATE user_sessions
                 SET revoked_at = CURRENT_TIMESTAMP
                 WHERE user_id = ? AND institution_id = ? AND revoked_at IS NULL'
            );
            $statement->bind_param('ii', $parent['user_id'], $institutionId);
            $statement->execute();
            $statement->close();
        }

        parent_management_sync_links(
            $db,
            $institutionId,
            $parentId,
            $profile['relationship_to_student'],
            $studentIds
        );
        $updatedParent = parent_management_get_parent($db, $institutionId, $parentId);
        if ($updatedParent === null) {
            throw new RuntimeException('The updated parent record could not be reloaded.');
        }
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            $actorType,
            'parent.updated',
            'parent',
            (string) $parentId,
            $oldValues,
            parent_management_audit_values($updatedParent, $updatedParent['linked_student_ids'])
        );
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function parent_management_transfer_affiliation(
    mysqli $db,
    int $sourceInstitutionId,
    int $parentId,
    int $targetInstitutionId,
    array $selectedStudentIds,
    int $actorUserId
): array {
    if ($sourceInstitutionId < 1 || $parentId < 1 || $targetInstitutionId < 1 || $actorUserId < 1) {
        throw new InvalidArgumentException('The institution affiliation request is invalid.');
    }

    $selectedStudentIds = parent_management_student_ids($selectedStudentIds);
    $transferVariableSet = false;
    $db->begin_transaction();
    try {
        $actor = $db->prepare(
            "SELECT u.id
             FROM users u
             JOIN roles r ON r.id = u.role_id
             JOIN institutions i ON i.id = u.institution_id
             WHERE u.id = ? AND u.user_type = 'super_admin' AND u.status = 'active'
               AND r.is_system = 1 AND r.institution_id IS NULL
               AND BINARY r.name = BINARY 'super_admin' AND i.status = 'active'
             LIMIT 1 FOR UPDATE"
        );
        $actor->bind_param('i', $actorUserId);
        $actor->execute();
        $authorizedActor = $actor->get_result()->fetch_assoc();
        $actor->close();
        if (!is_array($authorizedActor)) {
            throw new OutOfBoundsException('Only an active Super Admin can correct institution affiliations.');
        }

        $parentLock = $db->prepare(
            "SELECT p.id, p.institution_id, p.user_id, p.mobile, p.email,
                    u.email AS account_email, u.mobile AS account_mobile, u.username
             FROM parents p
             JOIN users u ON u.id = p.user_id AND u.institution_id = p.institution_id
             JOIN roles r ON r.id = u.role_id AND r.name = 'parent'
             WHERE p.institution_id = ? AND p.id = ? AND u.user_type = 'parent'
             LIMIT 1 FOR UPDATE"
        );
        $parentLock->bind_param('ii', $sourceInstitutionId, $parentId);
        $parentLock->execute();
        $parent = $parentLock->get_result()->fetch_assoc();
        $parentLock->close();
        if (!is_array($parent)) {
            throw new OutOfBoundsException('The parent record was not found in the current institution.');
        }
        $userId = (int) $parent['user_id'];
        if ($targetInstitutionId === $sourceInstitutionId) {
            throw new InvalidArgumentException('Choose a different institution to correct this affiliation.');
        }

        $targetLock = $db->prepare(
            "SELECT id FROM institutions WHERE id = ? AND status = 'active' LIMIT 1 FOR UPDATE"
        );
        $targetLock->bind_param('i', $targetInstitutionId);
        $targetLock->execute();
        $target = $targetLock->get_result()->fetch_assoc();
        $targetLock->close();
        if (!is_array($target)) {
            throw new InvalidArgumentException('Choose an active institution from the list.');
        }

        $relationshipsStatement = $db->prepare(
            'SELECT id, institution_id, parent_id, student_id, relationship_type,
                    is_primary, can_view_academics, can_view_fees, can_make_payments,
                    status, created_at, updated_at
             FROM parent_student_relationships
             WHERE institution_id = ? AND parent_id = ?
             ORDER BY student_id, id FOR UPDATE'
        );
        $relationshipsStatement->bind_param('ii', $sourceInstitutionId, $parentId);
        $relationshipsStatement->execute();
        $relationships = $relationshipsStatement->get_result()->fetch_all(MYSQLI_ASSOC);
        $relationshipsStatement->close();

        $linkedStudentIds = array_values(array_unique(array_map(
            static fn(array $relationship): int => (int) $relationship['student_id'],
            $relationships
        )));
        $expectedStudentIds = $linkedStudentIds;
        $submittedStudentIds = $selectedStudentIds;
        sort($expectedStudentIds);
        sort($submittedStudentIds);
        if ($expectedStudentIds !== $submittedStudentIds) {
            throw new InvalidArgumentException(
                'Select every student linked to this parent. Tenant-scoped parent-student links cannot be left in the previous institution.'
            );
        }

        $students = [];
        if ($linkedStudentIds !== []) {
            $placeholders = implode(',', array_fill(0, count($linkedStudentIds), '?'));
            $studentQuery = $db->prepare(
                'SELECT id, institution_id, student_code, photo_path, academic_year_id,
                        course_id, class_id, batch_id
                 FROM students
                 WHERE institution_id = ? AND id IN (' . $placeholders . ')
                 ORDER BY id FOR UPDATE'
            );
            $studentValues = [$sourceInstitutionId, ...$linkedStudentIds];
            $studentTypes = str_repeat('i', count($studentValues));
            parent_management_bind_params($studentQuery, $studentTypes, $studentValues);
            $studentQuery->execute();
            $students = $studentQuery->get_result()->fetch_all(MYSQLI_ASSOC);
            $studentQuery->close();
            if (count($students) !== count($linkedStudentIds)) {
                throw new OutOfBoundsException('One or more linked students are no longer in the parent institution.');
            }
        }

        $affiliationBlockers = [];
        $parentDependencies = parent_management_affiliation_reference_counts(
            $db,
            'parents',
            $parentId,
            ['parent_student_relationships']
        );
        if ($parentDependencies !== []) {
            $tables = implode(', ', array_keys($parentDependencies));
            $affiliationBlockers[] =
                'This parent has institution-bound records (' . $tables . ') that cannot be safely transferred. No changes were made.';
        }

        $userDependencies = parent_management_user_tenant_reference_counts($db, $userId);
        if ($userDependencies !== []) {
            $tables = implode(', ', array_keys($userDependencies));
            $affiliationBlockers[] =
                'This parent account has institution-bound records (' . $tables . ') that cannot be safely transferred. No changes were made.';
        }

        foreach ($students as $student) {
            $studentId = (int) $student['id'];
            $studentDependencies = parent_management_affiliation_reference_counts(
                $db,
                'students',
                $studentId,
                ['parent_student_relationships']
            );
            if ($studentDependencies !== []) {
                $tables = implode(', ', array_keys($studentDependencies));
                $affiliationBlockers[] =
                    'Student #' . $studentId . ' has institution-bound records (' . $tables . '), including possible fee, payment, receipt, attendance, or academic history. No records were moved.';
            }

            if ($student['photo_path'] !== null && $student['photo_path'] !== '') {
                $affiliationBlockers[] =
                    'Student #' . $studentId . ' has an uploaded photo. Its institution-specific file cannot be safely transferred.';
            }

            foreach (['academic_year_id', 'course_id', 'class_id', 'batch_id'] as $field) {
                if ($student[$field] !== null) {
                    $affiliationBlockers[] =
                        'Student #' . $studentId . ' has an assigned academic year, course, class, or batch. Reassigning it could invalidate academic records, so the transfer was blocked.';
                    break;
                }
            }

            $otherParent = $db->prepare(
                'SELECT COUNT(*) AS result_count
                 FROM parent_student_relationships
                 WHERE student_id = ? AND parent_id <> ?'
            );
            $otherParent->bind_param('ii', $studentId, $parentId);
            $otherParent->execute();
            $otherParentCount = (int) $otherParent->get_result()->fetch_assoc()['result_count'];
            $otherParent->close();
            if ($otherParentCount > 0) {
                $affiliationBlockers[] =
                    'Student #' . $studentId . ' is also linked to another parent. All linked parent accounts must be resolved before transferring this student.';
            }

            $codeCheck = $db->prepare(
                'SELECT id FROM students
                 WHERE institution_id = ? AND student_code = ? AND id <> ?
                 LIMIT 1'
            );
            $studentCode = (string) $student['student_code'];
            $codeCheck->bind_param('isi', $targetInstitutionId, $studentCode, $studentId);
            $codeCheck->execute();
            $duplicateCode = $codeCheck->get_result()->fetch_assoc();
            $codeCheck->close();
            if (is_array($duplicateCode)) {
                throw new InvalidArgumentException(
                    'Student code ' . $studentCode . ' already exists in the selected institution.'
                );
            }
        }

        if ($affiliationBlockers !== []) {
            throw new InvalidArgumentException(implode(' ', array_unique($affiliationBlockers)));
        }

        $email = $parent['account_email'];
        $mobile = $parent['account_mobile'];
        $username = $parent['username'];
        $duplicateUser = $db->prepare(
            'SELECT id FROM users
             WHERE institution_id = ? AND id <> ?
               AND ((? IS NOT NULL AND email = ?)
                 OR (? IS NOT NULL AND mobile = ?)
                 OR (? IS NOT NULL AND username = ?))
             LIMIT 1'
        );
        $duplicateUser->bind_param(
            'iissssss',
            $targetInstitutionId,
            $userId,
            $email,
            $email,
            $mobile,
            $mobile,
            $username,
            $username
        );
        $duplicateUser->execute();
        $duplicateUserRow = $duplicateUser->get_result()->fetch_assoc();
        $duplicateUser->close();
        if (is_array($duplicateUserRow)) {
            throw new InvalidArgumentException(
                'The destination institution already has an account with this parent\'s email, mobile number, or username.'
            );
        }

        $duplicateParent = $db->prepare(
            'SELECT id FROM parents
             WHERE institution_id = ? AND mobile = ? AND id <> ?
             LIMIT 1'
        );
        $parentMobile = (string) $parent['mobile'];
        $duplicateParent->bind_param('isi', $targetInstitutionId, $parentMobile, $parentId);
        $duplicateParent->execute();
        $duplicateParentRow = $duplicateParent->get_result()->fetch_assoc();
        $duplicateParent->close();
        if (is_array($duplicateParentRow)) {
            throw new InvalidArgumentException(
                'The parent mobile number is already registered in the selected institution.'
            );
        }

        $statement = $db->prepare('SET @fees_parent_affiliation_transfer_user_id = ?');
        $statement->bind_param('i', $userId);
        $statement->execute();
        $statement->close();
        $transferVariableSet = true;

        $statement = $db->prepare(
            'UPDATE user_sessions
             SET revoked_at = COALESCE(revoked_at, CURRENT_TIMESTAMP)
             WHERE user_id = ? AND institution_id = ?'
        );
        $statement->bind_param('ii', $userId, $sourceInstitutionId);
        $statement->execute();
        $statement->close();
        $statement = $db->prepare(
            "UPDATE user_otp_verifications
             SET status = 'invalidated'
             WHERE user_id = ? AND institution_id = ? AND status = 'pending'"
        );
        $statement->bind_param('ii', $userId, $sourceInstitutionId);
        $statement->execute();
        $statement->close();

        if ($relationships !== []) {
            $statement = $db->prepare(
                'DELETE FROM parent_student_relationships
                 WHERE institution_id = ? AND parent_id = ?'
            );
            $statement->bind_param('ii', $sourceInstitutionId, $parentId);
            $statement->execute();
            if ($statement->affected_rows !== count($relationships)) {
                $statement->close();
                throw new RuntimeException('The parent-student links changed during the affiliation transfer.');
            }
            $statement->close();
        }

        $statement = $db->prepare(
            "UPDATE users
             SET institution_id = ?
             WHERE id = ? AND institution_id = ? AND user_type = 'parent'"
        );
        $statement->bind_param('iii', $targetInstitutionId, $userId, $sourceInstitutionId);
        $statement->execute();
        if ($statement->affected_rows !== 1) {
            $statement->close();
            throw new RuntimeException('The parent account affiliation could not be updated.');
        }
        $statement->close();

        $statement = $db->prepare(
            'UPDATE parents SET institution_id = ?
             WHERE id = ? AND institution_id = ? AND user_id = ?'
        );
        $statement->bind_param('iiii', $targetInstitutionId, $parentId, $sourceInstitutionId, $userId);
        $statement->execute();
        if ($statement->affected_rows !== 1) {
            $statement->close();
            throw new RuntimeException('The parent affiliation could not be updated.');
        }
        $statement->close();

        foreach ($linkedStudentIds as $studentId) {
            $statement = $db->prepare(
                'UPDATE students SET institution_id = ?
                 WHERE id = ? AND institution_id = ?'
            );
            $statement->bind_param('iii', $targetInstitutionId, $studentId, $sourceInstitutionId);
            $statement->execute();
            if ($statement->affected_rows !== 1) {
                $statement->close();
                throw new RuntimeException('A linked student affiliation could not be updated.');
            }
            $statement->close();
        }

        foreach ($relationships as $relationship) {
            $relationshipId = (int) $relationship['id'];
            $studentId = (int) $relationship['student_id'];
            $relationshipType = (string) $relationship['relationship_type'];
            $isPrimary = (int) $relationship['is_primary'];
            $canViewAcademics = (int) $relationship['can_view_academics'];
            $canViewFees = (int) $relationship['can_view_fees'];
            $canMakePayments = (int) $relationship['can_make_payments'];
            $status = (string) $relationship['status'];
            $createdAt = (string) $relationship['created_at'];
            $updatedAt = (string) $relationship['updated_at'];
            $statement = $db->prepare(
                'INSERT INTO parent_student_relationships (
                    id, institution_id, parent_id, student_id, relationship_type,
                    is_primary, can_view_academics, can_view_fees, can_make_payments,
                    status, created_at, updated_at
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->bind_param(
                'iiiisiiiisss',
                $relationshipId,
                $targetInstitutionId,
                $parentId,
                $studentId,
                $relationshipType,
                $isPrimary,
                $canViewAcademics,
                $canViewFees,
                $canMakePayments,
                $status,
                $createdAt,
                $updatedAt
            );
            $statement->execute();
            $statement->close();
        }

        $statement = $db->prepare('SET @fees_parent_affiliation_transfer_user_id = NULL');
        $statement->execute();
        $statement->close();
        $transferVariableSet = false;

        $consistency = $db->prepare(
            'SELECT COUNT(*) AS mismatch_count
             FROM parents p
             JOIN users u ON u.id = p.user_id
             WHERE p.id = ? AND (
                 p.institution_id <> ? OR u.institution_id <> ?
             )'
        );
        $consistency->bind_param('iii', $parentId, $targetInstitutionId, $targetInstitutionId);
        $consistency->execute();
        $mismatchCount = (int) $consistency->get_result()->fetch_assoc()['mismatch_count'];
        $consistency->close();
        if ($mismatchCount !== 0) {
            throw new RuntimeException('The parent and account institution consistency check failed.');
        }

        $oldValues = [
            'institution_id' => $sourceInstitutionId,
            'parent_id' => $parentId,
            'user_id' => $userId,
            'student_ids' => $linkedStudentIds,
            'relationship_records' => $relationships,
        ];
        $newValues = [
            'institution_id' => $targetInstitutionId,
            'parent_id' => $parentId,
            'user_id' => $userId,
            'student_ids' => $linkedStudentIds,
            'relationship_ids' => array_values(array_map(
                static fn(array $relationship): int => (int) $relationship['id'],
                $relationships
            )),
        ];
        write_audit_log(
            $db,
            $sourceInstitutionId,
            $actorUserId,
            'super_admin',
            'parent.institution_affiliation_corrected',
            'parent',
            (string) $parentId,
            $oldValues,
            $newValues
        );
        $db->commit();

        return [
            'parent_id' => $parentId,
            'user_id' => $userId,
            'previous_institution_id' => $sourceInstitutionId,
            'new_institution_id' => $targetInstitutionId,
            'student_ids' => $linkedStudentIds,
        ];
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    } finally {
        if ($transferVariableSet) {
            $statement = $db->prepare('SET @fees_parent_affiliation_transfer_user_id = NULL');
            $statement->execute();
            $statement->close();
        }
    }
}

function parent_management_toggle_status(
    mysqli $db,
    int $institutionId,
    int $parentId,
    int $actorUserId,
    string $actorType
): string {
    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            'SELECT p.id, p.user_id, p.full_name, COALESCE(p.email, u.email) AS email, p.mobile,
                    p.relationship_to_student, p.status, u.status AS account_status,
                    u.email_verified_at
             FROM parents p
             JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
             JOIN roles r ON r.id = u.role_id AND r.name = \'parent\'
             WHERE p.institution_id = ? AND p.id = ? AND u.user_type = \'parent\'
             FOR UPDATE'
        );
        $statement->bind_param('ii', $institutionId, $parentId);
        $statement->execute();
        $parent = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($parent)) {
            throw new OutOfBoundsException('The parent record was not found.');
        }
        if (!in_array($parent['status'], ['active', 'inactive', 'pending_verification'], true)) {
            throw new InvalidArgumentException(
                'Suspended parent records require separate review.'
            );
        }

        $newStatus = in_array($parent['status'], ['active', 'pending_verification'], true)
            ? 'inactive'
            : 'active';
        if ($newStatus === 'active' && $parent['email_verified_at'] === null) {
            throw new InvalidArgumentException(
                'This parent email must be verified before the profile can be activated.'
            );
        }
        if ($newStatus === 'active' && in_array($parent['account_status'], ['suspended', 'blocked'], true)) {
            throw new InvalidArgumentException(
                'This parent account is suspended or blocked and cannot be reactivated from this page.'
            );
        }
        $emailVerified = $parent['email_verified_at'] !== null && trim((string) $parent['email']) !== '';
        $newAccountStatus = $newStatus === 'inactive'
            ? 'inactive'
            : ($emailVerified ? 'active' : 'pending');

        $statement = $db->prepare(
            'UPDATE parents SET status = ? WHERE id = ? AND institution_id = ?'
        );
        $statement->bind_param('sii', $newStatus, $parentId, $institutionId);
        $statement->execute();
        $statement->close();

        if ($newStatus === 'inactive') {
            $statement = $db->prepare(
                'UPDATE user_sessions
                 SET revoked_at = CURRENT_TIMESTAMP
                 WHERE user_id = ? AND institution_id = ? AND revoked_at IS NULL'
            );
            $statement->bind_param('ii', $parent['user_id'], $institutionId);
            $statement->execute();
            $statement->close();
        }

        $statement = $db->prepare(
            'UPDATE users SET status = ? WHERE id = ? AND institution_id = ? AND user_type = \'parent\''
        );
        $statement->bind_param('sii', $newAccountStatus, $parent['user_id'], $institutionId);
        $statement->execute();
        $statement->close();

        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            $actorType,
            'parent.status_changed',
            'parent',
            (string) $parentId,
            ['status' => (string) $parent['status'], 'account_status' => (string) $parent['account_status']],
            ['status' => $newStatus, 'account_status' => $newAccountStatus]
        );
        $db->commit();
        return $newStatus;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function parent_management_handle_failure(Throwable $exception, string $operation): void
{
    if ($exception instanceof InvalidArgumentException || $exception instanceof OutOfBoundsException) {
        set_flash('danger', $exception->getMessage());
        return;
    }

    if ($exception instanceof mysqli_sql_exception && $exception->getCode() === 1062) {
        set_flash('danger', 'The email address, mobile number, or parent-student link is already registered.');
        return;
    }

    log_error('parent_management_operation_failed', [
        'operation' => $operation,
        'error_type' => get_class($exception),
        'error_code' => $exception->getCode(),
    ]);
    set_flash('danger', 'The parent record could not be saved. Please try again or contact an administrator.');
}
