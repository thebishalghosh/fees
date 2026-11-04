<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$testDatabase = getenv('FEES_AFFILIATION_TEST_DB');
if (is_string($testDatabase) && $testDatabase !== '') {
    putenv('DB_NAME=' . $testDatabase);
    require_once __DIR__ . '/../services/parent-management-service.php';
    set_exception_handler(static function (Throwable $exception): never {
        fwrite(STDERR, $exception->__toString() . PHP_EOL);
        exit(1);
    });
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
$migration = file_get_contents(__DIR__ . '/../database/migrations/20261103_enable_parent_affiliation_transfer.sql');
$editPage = file_get_contents(__DIR__ . '/../admin/parents/edit.php');
$updatePage = file_get_contents(__DIR__ . '/../admin/parents/update.php');
$form = file_get_contents(__DIR__ . '/../includes/components/parent-profile-form.php');
$service = file_get_contents(__DIR__ . '/../services/parent-management-service.php');

$check(
    is_string($schema)
        && str_contains($schema, 'ON DELETE CASCADE ON UPDATE CASCADE')
        && str_contains($schema, 'trg_users_parent_affiliation_bu')
        && str_contains($schema, 'trg_parents_affiliation_bu'),
    'Constrain parent/user affiliation updates and preserve tenant-scoped session and OTP rows'
);
$check(
    is_string($migration)
        && str_contains($migration, 'migration_20261103_assert_parent_affiliation_transfer')
        && str_contains($migration, 'fk_parents_user_institution')
        && str_contains($migration, 'ON DELETE CASCADE ON UPDATE CASCADE'),
    'Provide a guarded existing-database affiliation migration'
);
$check(
    is_string($editPage)
        && str_contains($editPage, 'parent_management_is_super_admin()')
        && str_contains($form, 'Institution Affiliation')
        && str_contains($form, 'student_institution_name'),
    'Show current affiliation and linked-student institutions to Super Admins'
);
$check(
    is_string($updatePage)
        && str_contains($updatePage, 'require_valid_csrf_token()')
        && str_contains($updatePage, 'confirm_affiliation')
        && str_contains($updatePage, 'parent_management_transfer_affiliation'),
    'Require CSRF, explicit confirmation, and the protected transfer action'
);
$check(
    is_string($service)
        && str_contains($service, "WHERE status = 'active'")
        && str_contains($service, 'parent_management_affiliation_reference_counts')
        && str_contains($service, 'parent.institution_affiliation_corrected')
        && str_contains($service, 'begin_transaction()'),
    'Validate active institutions, block historical references, and audit transfers transactionally'
);

if (is_string($testDatabase) && $testDatabase !== '') {
    $check(
        preg_match('/^fees_affiliation_verify_[0-9]{8}_[0-9]{6}$/D', $testDatabase) === 1,
        'Use only a dedicated disposable affiliation-verification database'
    );
    if (preg_match('/^fees_affiliation_verify_[0-9]{8}_[0-9]{6}$/D', $testDatabase) === 1) {
        $config = db_config();
        $db = new mysqli(
            (string) $config['host'],
            (string) $config['username'],
            (string) $config['password'],
            $testDatabase,
            (int) $config['port']
        );
        $db->set_charset('utf8mb4');
        $suffix = bin2hex(random_bytes(5));

        $createInstitution = static function (string $name) use ($db, $suffix): int {
            $code = 'AFF-' . strtoupper(substr(hash('sha256', $name . $suffix), 0, 10));
            $statement = $db->prepare('INSERT INTO institutions (name, code) VALUES (?, ?)');
            $statement->bind_param('ss', $name, $code);
            $statement->execute();
            $id = (int) $db->insert_id;
            $statement->close();
            return $id;
        };
        $createUser = static function (
            int $institutionId,
            int $roleId,
            string $userType,
            string $name
        ) use ($db): int {
            $status = 'active';
            $statement = $db->prepare(
                'INSERT INTO users (institution_id, role_id, user_type, full_name, status)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $statement->bind_param('iisss', $institutionId, $roleId, $userType, $name, $status);
            $statement->execute();
            $id = (int) $db->insert_id;
            $statement->close();
            return $id;
        };
        $createParent = static function (
            int $institutionId,
            int $parentRoleId,
            string $name
        ) use ($db, $createUser, $suffix): array {
            $userId = $createUser($institutionId, $parentRoleId, 'parent', $name);
            $mobile = '+9199' . substr((string) hexdec(substr(hash('sha256', $name . $suffix), 0, 10)), 0, 10);
            $statement = $db->prepare(
                'INSERT INTO parents (institution_id, user_id, full_name, mobile)
                 VALUES (?, ?, ?, ?)'
            );
            $statement->bind_param('iiss', $institutionId, $userId, $name, $mobile);
            $statement->execute();
            $parentId = (int) $db->insert_id;
            $statement->close();
            return ['parent_id' => $parentId, 'user_id' => $userId];
        };
        $createStudent = static function (int $institutionId, string $name) use ($db, $suffix): int {
            $code = 'S-' . strtoupper(substr(hash('sha256', $name . $suffix), 0, 10));
            $admissionDate = '2026-01-01';
            $status = 'prospect';
            $statement = $db->prepare(
                'INSERT INTO students (institution_id, student_code, full_name, admission_date, status)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $statement->bind_param('issss', $institutionId, $code, $name, $admissionDate, $status);
            $statement->execute();
            $studentId = (int) $db->insert_id;
            $statement->close();
            return $studentId;
        };
        $createLink = static function (
            int $institutionId,
            int $parentId,
            int $studentId,
            string $status,
            int $canViewFees
        ) use ($db): int {
            $statement = $db->prepare(
                'INSERT INTO parent_student_relationships
                    (institution_id, parent_id, student_id, status, can_view_fees)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $statement->bind_param('iiisi', $institutionId, $parentId, $studentId, $status, $canViewFees);
            $statement->execute();
            $relationshipId = (int) $db->insert_id;
            $statement->close();
            return $relationshipId;
        };
        $countRows = static function (string $query, string $types, array $values) use ($db): int {
            $statement = $db->prepare($query);
            if ($types !== '') {
                $parameters = [$types];
                foreach ($values as &$value) {
                    $parameters[] = &$value;
                }
                unset($value);
                call_user_func_array([$statement, 'bind_param'], $parameters);
            }
            $statement->execute();
            $count = (int) $statement->get_result()->fetch_assoc()['result_count'];
            $statement->close();
            return $count;
        };

        $roleResult = $db->query(
            "SELECT name, id FROM roles
             WHERE is_system = 1 AND institution_id IS NULL
               AND name IN ('super_admin', 'admin', 'parent')"
        );
        $systemRoles = [];
        while ($role = $roleResult->fetch_assoc()) {
            $systemRoles[$role['name']] = (int) $role['id'];
        }
        $check(
            isset($systemRoles['super_admin'], $systemRoles['admin'], $systemRoles['parent']),
            'Find canonical system roles for Super Admin, Institution Admin, and Parent fixtures'
        );

        if (isset($systemRoles['super_admin'], $systemRoles['admin'], $systemRoles['parent'])) {
            $sourceInstitutionId = $createInstitution('Affiliation Source ' . $suffix);
            $targetInstitutionId = $createInstitution('Affiliation Target ' . $suffix);
            $inactiveInstitutionId = $createInstitution('Affiliation Inactive ' . $suffix);
            $db->query("UPDATE institutions SET status = 'inactive' WHERE id = " . $inactiveInstitutionId);
            $superAdminId = $createUser(
                $sourceInstitutionId,
                $systemRoles['super_admin'],
                'super_admin',
                'Affiliation Super Admin ' . $suffix
            );
            $institutionAdminId = $createUser(
                $sourceInstitutionId,
                $systemRoles['admin'],
                'admin',
                'Affiliation Institution Admin ' . $suffix
            );

            $requestedParentStatement = $db->prepare(
                'SELECT institution_id FROM parents WHERE id = 1406 LIMIT 1'
            );
            $requestedParentStatement->execute();
            $requestedParent = $requestedParentStatement->get_result()->fetch_assoc();
            $requestedParentStatement->close();
            if (is_array($requestedParent)) {
                $requestedSourceInstitutionId = (int) $requestedParent['institution_id'];
                $requestedContext = parent_management_get_affiliation_context(
                    $db,
                    $requestedSourceInstitutionId,
                    1406
                );
                $requestedStudentIds = array_values(array_unique(array_map(
                    static fn(array $student): int => (int) $student['student_id'],
                    $requestedContext['students'] ?? []
                )));
                $requestedBlockers = '';
                try {
                    parent_management_transfer_affiliation(
                        $db,
                        $requestedSourceInstitutionId,
                        1406,
                        $targetInstitutionId,
                        $requestedStudentIds,
                        $superAdminId
                    );
                } catch (InvalidArgumentException $exception) {
                    $requestedBlockers = $exception->getMessage();
                }
                $check(
                    str_contains($requestedBlockers, 'notifications')
                        && str_contains($requestedBlockers, 'attendance_records')
                        && str_contains($requestedBlockers, 'student_fee_assignments'),
                    'Block parent 1406 and report its notification, attendance, and fee-assignment history'
                );
                $check(
                    $countRows(
                        'SELECT COUNT(*) AS result_count FROM parents p
                         JOIN users u ON u.id = p.user_id
                         WHERE p.id = 1406 AND p.institution_id = ? AND u.institution_id = ?',
                        'ii',
                        [$requestedSourceInstitutionId, $requestedSourceInstitutionId]
                    ) === 1,
                    'Keep parent 1406 and its account unchanged when historical records block transfer'
                );
            }

            $parent = $createParent($sourceInstitutionId, $systemRoles['parent'], 'Affiliation Parent ' . $suffix);
            $studentOne = $createStudent($sourceInstitutionId, 'Affiliation Student One ' . $suffix);
            $studentTwo = $createStudent($sourceInstitutionId, 'Affiliation Student Two ' . $suffix);
            $relationshipOne = $createLink($sourceInstitutionId, $parent['parent_id'], $studentOne, 'active', 1);
            $relationshipTwo = $createLink($sourceInstitutionId, $parent['parent_id'], $studentTwo, 'revoked', 0);

            $sessionIdentifier = substr(hash('sha256', 'session-' . $suffix), 0, 32);
            $sessionTokenHash = hash('sha256', 'session-token-' . $suffix);
            $expiresAt = '2030-01-01 00:00:00';
            $statement = $db->prepare(
                'INSERT INTO user_sessions
                    (user_id, institution_id, session_identifier, session_token_hash, expires_at)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $statement->bind_param(
                'iisss',
                $parent['user_id'],
                $sourceInstitutionId,
                $sessionIdentifier,
                $sessionTokenHash,
                $expiresAt
            );
            $statement->execute();
            $statement->close();
            $requestId = '123e4567-e89b-42d3-a456-' . substr(hash('sha256', $suffix), 0, 12);
            $otpHash = hash('sha256', 'otp-' . $suffix);
            $purpose = 'email_verification';
            $statement = $db->prepare(
                'INSERT INTO user_otp_verifications
                    (institution_id, user_id, purpose, request_id, otp_hash, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $statement->bind_param(
                'iissss',
                $sourceInstitutionId,
                $parent['user_id'],
                $purpose,
                $requestId,
                $otpHash,
                $expiresAt
            );
            $statement->execute();
            $statement->close();

            $context = parent_management_get_affiliation_context($db, $sourceInstitutionId, $parent['parent_id']);
            $check(
                is_array($context)
                    && count($context['students']) === 2
                    && $context['students'][0]['student_institution_id'] === $sourceInstitutionId,
                'Display all linked students with their current institutions'
            );

            $unauthorizedBlocked = false;
            try {
                parent_management_transfer_affiliation(
                    $db,
                    $sourceInstitutionId,
                    $parent['parent_id'],
                    $targetInstitutionId,
                    [$studentOne, $studentTwo],
                    $institutionAdminId
                );
            } catch (OutOfBoundsException) {
                $unauthorizedBlocked = true;
            }
            $check($unauthorizedBlocked, 'Reject Institution Admin affiliation transfers');

            $invalidInstitutionBlocked = false;
            try {
                parent_management_transfer_affiliation(
                    $db,
                    $sourceInstitutionId,
                    $parent['parent_id'],
                    $inactiveInstitutionId,
                    [$studentOne, $studentTwo],
                    $superAdminId
                );
            } catch (InvalidArgumentException) {
                $invalidInstitutionBlocked = true;
            }
            $check($invalidInstitutionBlocked, 'Reject an inactive or invalid destination institution');

            $unknownInstitutionBlocked = false;
            try {
                parent_management_transfer_affiliation(
                    $db,
                    $sourceInstitutionId,
                    $parent['parent_id'],
                    999999999,
                    [$studentOne, $studentTwo],
                    $superAdminId
                );
            } catch (InvalidArgumentException) {
                $unknownInstitutionBlocked = true;
            }
            $check($unknownInstitutionBlocked, 'Reject a fabricated institution ID');

            $partialSelectionBlocked = false;
            try {
                parent_management_transfer_affiliation(
                    $db,
                    $sourceInstitutionId,
                    $parent['parent_id'],
                    $targetInstitutionId,
                    [$studentOne],
                    $superAdminId
                );
            } catch (InvalidArgumentException) {
                $partialSelectionBlocked = true;
            }
            $check($partialSelectionBlocked, 'Reject a partial selection of linked students');

            $transfer = parent_management_transfer_affiliation(
                $db,
                $sourceInstitutionId,
                $parent['parent_id'],
                $targetInstitutionId,
                [$studentOne, $studentTwo],
                $superAdminId
            );
            $check($transfer['student_ids'] === [$studentOne, $studentTwo], 'Transfer the explicitly selected linked students');
            $check(
                $countRows(
                    'SELECT COUNT(*) AS result_count FROM parents p
                     JOIN users u ON u.id = p.user_id
                     WHERE p.id = ? AND p.institution_id = ? AND u.institution_id = ?',
                    'iii',
                    [$parent['parent_id'], $targetInstitutionId, $targetInstitutionId]
                ) === 1,
                'Keep the parent profile and account in the same destination institution'
            );
            $check(
                $countRows(
                    'SELECT COUNT(*) AS result_count FROM students
                     WHERE institution_id = ? AND id IN (?, ?)',
                    'iii',
                    [$targetInstitutionId, $studentOne, $studentTwo]
                ) === 2,
                'Move every selected student with the parent'
            );
            $check(
                $countRows(
                    'SELECT COUNT(*) AS result_count FROM parent_student_relationships
                     WHERE institution_id = ? AND parent_id = ? AND student_id IN (?, ?)',
                    'iiii',
                    [$targetInstitutionId, $parent['parent_id'], $studentOne, $studentTwo]
                ) === 2,
                'Preserve every parent-student relationship in the destination'
            );
            $check(
                $countRows(
                    'SELECT COUNT(*) AS result_count FROM parent_student_relationships
                     WHERE id = ? AND institution_id = ? AND status = \'active\' AND can_view_fees = 1',
                    'ii',
                    [$relationshipOne, $targetInstitutionId]
                ) === 1
                && $countRows(
                    'SELECT COUNT(*) AS result_count FROM parent_student_relationships
                     WHERE id = ? AND institution_id = ? AND status = \'revoked\' AND can_view_fees = 0',
                    'ii',
                    [$relationshipTwo, $targetInstitutionId]
                ) === 1,
                'Preserve relationship IDs, statuses, and permissions'
            );
            $check(
                $countRows(
                    'SELECT COUNT(*) AS result_count FROM user_sessions
                     WHERE user_id = ? AND institution_id = ? AND revoked_at IS NOT NULL',
                    'ii',
                    [$parent['user_id'], $targetInstitutionId]
                ) === 1,
                'Revoke the parent session while preserving it under the destination institution'
            );
            $check(
                $countRows(
                    'SELECT COUNT(*) AS result_count FROM user_otp_verifications
                     WHERE user_id = ? AND institution_id = ? AND status = \'invalidated\'',
                    'ii',
                    [$parent['user_id'], $targetInstitutionId]
                ) === 1,
                'Invalidate pending OTP challenges during the affiliation change'
            );
            $check(
                parent_management_get_parent($db, $sourceInstitutionId, $parent['parent_id']) === null
                    && parent_management_get_parent($db, $targetInstitutionId, $parent['parent_id']) !== null,
                'Remove access through the former tenant and allow access only in the new tenant'
            );
            $auditStatement = $db->prepare(
                'SELECT actor_user_id, old_values, new_values, created_at
                 FROM audit_logs
                 WHERE actor_user_id = ? AND action = \'parent.institution_affiliation_corrected\'
                   AND entity_id = ?'
            );
            $parentEntityId = (string) $parent['parent_id'];
            $auditStatement->bind_param('is', $superAdminId, $parentEntityId);
            $auditStatement->execute();
            $audit = $auditStatement->get_result()->fetch_assoc();
            $auditStatement->close();
            $oldAuditValues = is_array($audit) ? json_decode((string) $audit['old_values'], true) : null;
            $newAuditValues = is_array($audit) ? json_decode((string) $audit['new_values'], true) : null;
            $check(
                is_array($audit)
                    && (int) $audit['actor_user_id'] === $superAdminId
                    && (string) $audit['created_at'] !== ''
                    && is_array($oldAuditValues)
                    && (int) ($oldAuditValues['institution_id'] ?? 0) === $sourceInstitutionId
                    && (int) ($oldAuditValues['parent_id'] ?? 0) === $parent['parent_id']
                    && ($oldAuditValues['student_ids'] ?? null) === [$studentOne, $studentTwo]
                    && is_array($newAuditValues)
                    && (int) ($newAuditValues['institution_id'] ?? 0) === $targetInstitutionId
                    && (int) ($newAuditValues['parent_id'] ?? 0) === $parent['parent_id']
                    && ($newAuditValues['student_ids'] ?? null) === [$studentOne, $studentTwo],
                'Audit the administrator, timestamp, parent/student IDs, and previous/new institutions'
            );

            $historyParent = $createParent(
                $sourceInstitutionId,
                $systemRoles['parent'],
                'Affiliation Historical Payment Parent ' . $suffix
            );
            $historyStudent = $createStudent($sourceInstitutionId, 'Affiliation Payment Student ' . $suffix);
            $createLink($sourceInstitutionId, $historyParent['parent_id'], $historyStudent, 'active', 1);
            $orderNumber = 'AFF-ORDER-' . strtoupper($suffix);
            $idempotencyKey = 'AFF-IDEM-' . strtoupper($suffix);
            $statement = $db->prepare(
                'INSERT INTO payment_orders
                    (institution_id, student_id, parent_id, order_number, idempotency_key)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $statement->bind_param(
                'iiiss',
                $sourceInstitutionId,
                $historyStudent,
                $historyParent['parent_id'],
                $orderNumber,
                $idempotencyKey
            );
            $statement->execute();
            $paymentOrderId = (int) $db->insert_id;
            $statement->close();
            $gateway = 'manual';
            $statement = $db->prepare(
                'INSERT INTO payments
                    (institution_id, payment_order_id, student_id, parent_id, gateway, idempotency_key)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $paymentIdempotencyKey = 'AFF-PAYMENT-' . strtoupper($suffix);
            $statement->bind_param(
                'iiiiss',
                $sourceInstitutionId,
                $paymentOrderId,
                $historyStudent,
                $historyParent['parent_id'],
                $gateway,
                $paymentIdempotencyKey
            );
            $statement->execute();
            $statement->close();

            $paymentHistoryBlocked = false;
            try {
                parent_management_transfer_affiliation(
                    $db,
                    $sourceInstitutionId,
                    $historyParent['parent_id'],
                    $targetInstitutionId,
                    [$historyStudent],
                    $superAdminId
                );
            } catch (InvalidArgumentException $exception) {
                $paymentHistoryBlocked = str_contains($exception->getMessage(), 'payment_orders')
                    || str_contains($exception->getMessage(), 'payments');
            }
            $check($paymentHistoryBlocked, 'Block parents with payment/order history without changing payment records');
            $check(
                $countRows(
                    'SELECT COUNT(*) AS result_count FROM payments
                     WHERE institution_id = ? AND payment_order_id = ?',
                    'ii',
                    [$sourceInstitutionId, $paymentOrderId]
                ) === 1
                && $countRows(
                    'SELECT COUNT(*) AS result_count FROM parents p
                     JOIN users u ON u.id = p.user_id
                     WHERE p.id = ? AND p.institution_id = ? AND u.institution_id = ?',
                    'iii',
                    [$historyParent['parent_id'], $sourceInstitutionId, $sourceInstitutionId]
                ) === 1,
                'Keep payment history and parent/account affiliations unchanged after rejection'
            );

            $admissionParent = $createParent(
                $sourceInstitutionId,
                $systemRoles['parent'],
                'Affiliation Admission Parent ' . $suffix
            );
            $admissionStudent = $createStudent($sourceInstitutionId, 'Affiliation Admission Student ' . $suffix);
            $createLink($sourceInstitutionId, $admissionParent['parent_id'], $admissionStudent, 'active', 1);
            $applicantName = 'Admission history ' . $suffix;
            $applicantMobile = '+9188' . substr(hash('sha256', $suffix), 0, 10);
            $studentName = 'Affiliation Admission Student ' . $suffix;
            $statement = $db->prepare(
                'INSERT INTO admissions
                    (institution_id, student_id, applicant_name, applicant_mobile, student_name)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $statement->bind_param(
                'iisss',
                $sourceInstitutionId,
                $admissionStudent,
                $applicantName,
                $applicantMobile,
                $studentName
            );
            $statement->execute();
            $statement->close();
            $admissionHistoryBlocked = false;
            try {
                parent_management_transfer_affiliation(
                    $db,
                    $sourceInstitutionId,
                    $admissionParent['parent_id'],
                    $targetInstitutionId,
                    [$admissionStudent],
                    $superAdminId
                );
            } catch (InvalidArgumentException $exception) {
                $admissionHistoryBlocked = str_contains($exception->getMessage(), 'admissions');
            }
            $check($admissionHistoryBlocked, 'Block student reassignment when institution-bound academic records exist');

            $rollbackParent = $createParent(
                $sourceInstitutionId,
                $systemRoles['parent'],
                'Affiliation Rollback Parent ' . $suffix
            );
            $rollbackStudent = $createStudent($sourceInstitutionId, 'Affiliation Rollback Student ' . $suffix);
            $rollbackRelationshipId = $createLink(
                $sourceInstitutionId,
                $rollbackParent['parent_id'],
                $rollbackStudent,
                'active',
                1
            );
            $rollbackSessionIdentifier = substr(hash('sha256', 'rollback-session-' . $suffix), 0, 32);
            $rollbackSessionTokenHash = hash('sha256', 'rollback-token-' . $suffix);
            $statement = $db->prepare(
                'INSERT INTO user_sessions
                    (user_id, institution_id, session_identifier, session_token_hash, expires_at)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $statement->bind_param(
                'iisss',
                $rollbackParent['user_id'],
                $sourceInstitutionId,
                $rollbackSessionIdentifier,
                $rollbackSessionTokenHash,
                $expiresAt
            );
            $statement->execute();
            $statement->close();
            $rollbackRequestId = '123e4567-e89b-42d3-b456-' . substr(hash('sha256', $suffix), 0, 12);
            $rollbackOtpHash = hash('sha256', 'rollback-otp-' . $suffix);
            $statement = $db->prepare(
                'INSERT INTO user_otp_verifications
                    (institution_id, user_id, purpose, request_id, otp_hash, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $statement->bind_param(
                'iissss',
                $sourceInstitutionId,
                $rollbackParent['user_id'],
                $purpose,
                $rollbackRequestId,
                $rollbackOtpHash,
                $expiresAt
            );
            $statement->execute();
            $statement->close();
            $rollbackTrigger = 'trg_affiliation_test_force_student_failure';
            $db->query(
                'CREATE TRIGGER ' . $rollbackTrigger . '
                 BEFORE UPDATE ON students FOR EACH ROW
                 BEGIN
                     IF OLD.institution_id <> NEW.institution_id THEN
                         SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'Forced affiliation rollback test.\';
                     END IF;
                 END'
            );
            $transactionRolledBack = false;
            try {
                parent_management_transfer_affiliation(
                    $db,
                    $sourceInstitutionId,
                    $rollbackParent['parent_id'],
                    $targetInstitutionId,
                    [$rollbackStudent],
                    $superAdminId
                );
            } catch (Throwable) {
                $transactionRolledBack = true;
            } finally {
                $db->query('DROP TRIGGER ' . $rollbackTrigger);
            }
            $check($transactionRolledBack, 'Surface a late transfer failure');
            $check(
                $countRows(
                    'SELECT COUNT(*) AS result_count FROM parents p
                     JOIN users u ON u.id = p.user_id
                     JOIN students s ON s.id = ?
                     JOIN parent_student_relationships psr
                       ON psr.parent_id = p.id AND psr.student_id = s.id
                     WHERE p.id = ? AND p.institution_id = ? AND u.institution_id = ?
                       AND s.institution_id = ? AND psr.id = ? AND psr.institution_id = ?',
                    'iiiiiii',
                    [
                        $rollbackStudent,
                        $rollbackParent['parent_id'],
                        $sourceInstitutionId,
                        $sourceInstitutionId,
                        $sourceInstitutionId,
                        $rollbackRelationshipId,
                        $sourceInstitutionId,
                    ]
                ) === 1,
                'Roll back parent, account, student, and relationship changes atomically'
            );
            $check(
                $countRows(
                    'SELECT COUNT(*) AS result_count FROM user_sessions
                     WHERE user_id = ? AND institution_id = ? AND revoked_at IS NULL',
                    'ii',
                    [$rollbackParent['user_id'], $sourceInstitutionId]
                ) === 1
                && $countRows(
                    'SELECT COUNT(*) AS result_count FROM user_otp_verifications
                     WHERE user_id = ? AND institution_id = ? AND status = \'pending\'',
                    'ii',
                    [$rollbackParent['user_id'], $sourceInstitutionId]
                ) === 1,
                'Roll back session revocation and OTP invalidation after a failed transfer'
            );
        }

        if ($failures === []) {
            fwrite(STDOUT, 'Passed ' . $checks . ' parent affiliation checks.' . PHP_EOL);
        }
        $db->close();
    }
} else {
    fwrite(STDOUT, 'Database assertions skipped; use only a disposable fees_affiliation_verify_ database.' . PHP_EOL);
}

if ($failures !== []) {
    fwrite(STDERR, 'FAIL: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}

if (!is_string($testDatabase) || $testDatabase === '') {
    fwrite(STDOUT, 'Passed ' . $checks . ' parent affiliation static checks.' . PHP_EOL);
}
