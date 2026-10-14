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
    $user = current_user();
    if ($user === null || !in_array($user['user_type'], ['super_admin', 'admin', 'staff'], true)) {
        throw new RuntimeException('An authorized institution user is required.');
    }

    return (int) $user['institution_id'];
}

function parent_management_prepare_list_filters(
    int $institutionId,
    string $search,
    string $status,
    string $link
): array {
    $where = ['p.institution_id = ?'];
    $values = [$institutionId];
    $types = 'i';

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
         WHERE p.institution_id = ? AND u.user_type = \'parent\''
    );
    $statement->bind_param('i', $institutionId);
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
    int $institutionId,
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

    $countStatement = $db->prepare(
        "SELECT COUNT(*) AS result_count
         FROM parents p
         JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
         JOIN roles r ON r.id = u.role_id AND r.name = 'parent'
         WHERE {$where} AND u.user_type = 'parent'"
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
        "SELECT p.id, p.full_name, p.mobile, COALESCE(p.email, u.email) AS email,
                p.relationship_to_student, p.status, p.created_at,
                (u.email_verified_at IS NOT NULL) AS email_verified,
                COUNT(DISTINCT IF(psr.status = 'active', psr.student_id, NULL)) AS linked_student_count
         FROM parents p
         JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
         JOIN roles r ON r.id = u.role_id AND r.name = 'parent'
         LEFT JOIN parent_student_relationships psr
           ON psr.institution_id = p.institution_id AND psr.parent_id = p.id
         WHERE {$where} AND u.user_type = 'parent'
         GROUP BY p.id, u.id
         ORDER BY p.created_at DESC, p.id DESC
         LIMIT ? OFFSET ?"
    );
    parent_management_bind_params($statement, $queryTypes, $queryValues);
    $statement->execute();
    $result = $statement->get_result();
    $parents = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
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
        parent_management_assert_unique_contact(
            $db,
            $institutionId,
            $profile['email'],
            $profile['mobile']
        );
        $statement = $db->prepare('SELECT id FROM roles WHERE name = \'parent\' LIMIT 1');
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
