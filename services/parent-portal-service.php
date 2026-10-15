<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/notification-service.php';
require_once __DIR__ . '/otp-service.php';
require_once __DIR__ . '/audit-service.php';

function parent_portal_get_parent_context(mysqli $db, int $userId, int $institutionId): ?array
{
    $statement = $db->prepare(
    "SELECT p.id AS parent_id, p.full_name, u.email, u.email_verified_at,
            u.mobile, u.mobile_verified_at, p.alternate_mobile,
            p.relationship_to_student, p.address_line1, p.address_line2,
            p.city, p.state, p.postal_code, p.country, p.emergency_contact,
            i.name AS institution_name, i.code AS institution_code,
            i.email AS institution_email,
            i.phone AS institution_phone, i.address_line1 AS institution_address_line1,
            i.address_line2 AS institution_address_line2, i.city AS institution_city,
            i.state AS institution_state, i.postal_code AS institution_postal_code,
            i.country AS institution_country
         FROM users u
         JOIN roles r ON r.id = u.role_id AND r.name = 'parent'
         JOIN institutions i ON i.id = u.institution_id AND i.status = 'active'
         JOIN parents p ON p.institution_id = u.institution_id AND p.user_id = u.id
         WHERE u.id = ? AND u.institution_id = ? AND u.user_type = 'parent'
           AND u.status = 'active' AND u.email_verified_at IS NOT NULL
           AND p.status = 'active'
         LIMIT 1"
    );
    $statement->bind_param('ii', $userId, $institutionId);
    $statement->execute();
    $parent = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_array($parent)) {
        return null;
    }

    $parent['parent_id'] = (int) $parent['parent_id'];
    $parent['email_verified'] = $parent['email_verified_at'] !== null;
    $parent['mobile_verified'] = $parent['mobile_verified_at'] !== null;
    return $parent;
}

function parent_portal_bind_params(mysqli_stmt $statement, string $types, array &$values): void
{
    if ($types === '') {
        return;
    }
    $parameters = [$types];
    foreach ($values as &$value) {
        $parameters[] = &$value;
    }
    unset($value);
    if (!call_user_func_array([$statement, 'bind_param'], $parameters)) {
        throw new RuntimeException('The parent portal query could not bind its parameters.');
    }
}

function parent_portal_get_children(mysqli $db, int $parentId, int $institutionId): array
{
    $statement = $db->prepare(
        "SELECT s.id, s.student_code, s.full_name, s.photo_path, s.status AS student_status,
                s.admission_date,
                CASE WHEN psr.can_view_academics = 1 THEN ay.name ELSE NULL END AS academic_year_name,
                CASE WHEN psr.can_view_academics = 1 THEN c.name ELSE NULL END AS class_name,
                CASE WHEN psr.can_view_academics = 1 THEN b.name ELSE NULL END AS batch_name,
                psr.relationship_type, psr.can_view_academics, psr.can_view_fees,
                CASE WHEN psr.can_view_fees = 1 THEN COALESCE((
                    SELECT SUM(sfa.outstanding_amount)
                    FROM student_fee_assignments sfa
                    WHERE sfa.institution_id = psr.institution_id
                      AND sfa.student_id = s.id
                      AND sfa.status IN ('active', 'completed', 'suspended')
                ), 0) ELSE NULL END AS outstanding_amount
         FROM parent_student_relationships psr
         JOIN parents p ON p.institution_id = psr.institution_id AND p.id = psr.parent_id
         JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
         JOIN roles r ON r.id = u.role_id AND r.name = 'parent'
         JOIN institutions i ON i.id = psr.institution_id AND i.status = 'active'
         JOIN students s ON s.institution_id = psr.institution_id AND s.id = psr.student_id
         LEFT JOIN academic_years ay
           ON ay.institution_id = s.institution_id AND ay.id = s.academic_year_id
         LEFT JOIN classes c ON c.institution_id = s.institution_id AND c.id = s.class_id
         LEFT JOIN batches b ON b.institution_id = s.institution_id AND b.id = s.batch_id
         WHERE p.id = ? AND p.institution_id = ? AND p.status = 'active'
           AND u.user_type = 'parent' AND u.status = 'active'
           AND u.email_verified_at IS NOT NULL AND psr.status = 'active'
         ORDER BY s.full_name, s.student_code"
    );
    $statement->bind_param('ii', $parentId, $institutionId);
    $statement->execute();
    $result = $statement->get_result();
    $children = [];
    while ($child = $result->fetch_assoc()) {
        $child['id'] = (int) $child['id'];
        $child['can_view_academics'] = (bool) $child['can_view_academics'];
        $child['can_view_fees'] = (bool) $child['can_view_fees'];
        $child['outstanding_amount'] = $child['outstanding_amount'] === null
            ? null
            : (string) $child['outstanding_amount'];
        $children[] = $child;
    }
    $statement->close();

    return $children;
}

function parent_portal_get_student(
    mysqli $db,
    int $parentId,
    int $institutionId,
    int $studentId
): ?array {
    $statement = $db->prepare(
        "SELECT s.id, s.student_code, s.full_name, s.photo_path, s.date_of_birth,
                s.gender, s.admission_date, s.status AS student_status,
                ay.name AS academic_year_name, c.name AS class_name,
                b.name AS batch_name, psr.relationship_type, psr.can_view_fees,
                COALESCE((
                    SELECT SUM(sfa.total_fee)
                    FROM student_fee_assignments sfa
                    WHERE sfa.institution_id = psr.institution_id
                      AND sfa.student_id = s.id
                      AND sfa.status IN ('active', 'completed', 'suspended')
                ), 0) AS assigned_fee_total,
                COALESCE((
                    SELECT SUM(sfa.total_paid)
                    FROM student_fee_assignments sfa
                    WHERE sfa.institution_id = psr.institution_id
                      AND sfa.student_id = s.id
                      AND sfa.status IN ('active', 'completed', 'suspended')
                ), 0) AS total_paid,
                COALESCE((
                    SELECT SUM(sfa.outstanding_amount)
                    FROM student_fee_assignments sfa
                    WHERE sfa.institution_id = psr.institution_id
                      AND sfa.student_id = s.id
                      AND sfa.status IN ('active', 'completed', 'suspended')
                ), 0) AS outstanding_amount
         FROM parent_student_relationships psr
         JOIN parents p ON p.institution_id = psr.institution_id AND p.id = psr.parent_id
         JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
         JOIN roles r ON r.id = u.role_id AND r.name = 'parent'
         JOIN institutions i ON i.id = psr.institution_id AND i.status = 'active'
         JOIN students s ON s.institution_id = psr.institution_id AND s.id = psr.student_id
         LEFT JOIN academic_years ay
           ON ay.institution_id = s.institution_id AND ay.id = s.academic_year_id
         LEFT JOIN classes c ON c.institution_id = s.institution_id AND c.id = s.class_id
         LEFT JOIN batches b ON b.institution_id = s.institution_id AND b.id = s.batch_id
         WHERE p.id = ? AND p.institution_id = ? AND p.status = 'active'
           AND u.user_type = 'parent' AND u.status = 'active'
           AND u.email_verified_at IS NOT NULL AND psr.status = 'active'
           AND psr.can_view_academics = 1 AND s.id = ?
         LIMIT 1"
    );
    $statement->bind_param('iii', $parentId, $institutionId, $studentId);
    $statement->execute();
    $student = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_array($student)) {
        return null;
    }

    $student['id'] = (int) $student['id'];
    $student['can_view_fees'] = (bool) $student['can_view_fees'];
    if (!$student['can_view_fees']) {
        $student['assigned_fee_total'] = null;
        $student['total_paid'] = null;
        $student['outstanding_amount'] = null;
        $student['payments'] = [];
        return $student;
    }

    $paymentStatement = $db->prepare(
        "SELECT pay.id, pay.currency, pay.status,
                COALESCE(pay.verified_at, pay.captured_at, pay.created_at) AS paid_at,
                CASE
                    WHEN pay.status = 'refunded' THEN 0
                    WHEN pay.amount > COALESCE(refund_totals.refunded_amount, 0)
                    THEN pay.amount - COALESCE(refund_totals.refunded_amount, 0)
                    ELSE 0
                END AS net_amount,
                rec.receipt_number
         FROM payments pay
         LEFT JOIN (
             SELECT institution_id, payment_id, SUM(amount) AS refunded_amount
             FROM refunds
             WHERE institution_id = ? AND status = 'processed'
             GROUP BY institution_id, payment_id
         ) refund_totals
           ON refund_totals.institution_id = pay.institution_id
          AND refund_totals.payment_id = pay.id
         LEFT JOIN receipts rec
           ON rec.institution_id = pay.institution_id AND rec.payment_id = pay.id
          AND rec.status IN ('issued', 'downloaded')
         WHERE pay.institution_id = ? AND pay.student_id = ?
           AND pay.status IN ('success', 'partial_refund', 'refunded')
           AND pay.verified_at IS NOT NULL
         ORDER BY pay.verified_at DESC, pay.id DESC
         LIMIT 10"
    );
    $paymentStatement->bind_param('iii', $institutionId, $institutionId, $studentId);
    $paymentStatement->execute();
    $paymentResult = $paymentStatement->get_result();
    $student['payments'] = [];
    while ($payment = $paymentResult->fetch_assoc()) {
        $student['payments'][] = $payment;
    }
    $paymentStatement->close();

    return $student;
}

function parent_portal_initials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($parts)) {
        return '';
    }

    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1, 'UTF-8'), 'UTF-8');
    }

    return $initials;
}

function parent_portal_children_summary(array $children): array
{
    $summary = [
        'total_count' => count($children),
        'active_count' => 0,
        'outstanding_cents' => 0,
        'has_fee_access' => false,
    ];
    foreach ($children as $child) {
        if (($child['student_status'] ?? null) === 'active') {
            $summary['active_count']++;
        }
        if (isset($child['outstanding_amount'])) {
            $amount = parent_portal_money_to_cents((string) $child['outstanding_amount']);
            if ($amount === null) {
                continue;
            }
            $summary['has_fee_access'] = true;
            $summary['outstanding_cents'] += $amount;
        }
    }
    $summary['outstanding_amount'] = $summary['outstanding_cents'] / 100;

    return $summary;
}

function parent_portal_money_to_cents(string $amount): ?int
{
    if (preg_match('/^(-?)([0-9]+)(?:\.([0-9]{1,2}))?$/D', $amount, $matches) !== 1) {
        return null;
    }
    $whole = (int) $matches[2];
    $fraction = (int) str_pad($matches[3] ?? '', 2, '0');
    $cents = ($whole * 100) + $fraction;

    return ($matches[1] ?? '') === '-' ? -$cents : $cents;
}

function parent_portal_sum_money(array $rows, string $field): string
{
    $cents = 0;
    foreach ($rows as $row) {
        $value = parent_portal_money_to_cents((string) ($row[$field] ?? '0'));
        if ($value !== null) {
            $cents += $value;
        }
    }
    return number_format($cents / 100, 2, '.', '');
}

function parent_portal_net_payment_amount(string $amount, string $refundedAmount, string $status): string
{
    $amountCents = parent_portal_money_to_cents($amount);
    $refundedCents = parent_portal_money_to_cents($refundedAmount);
    if ($amountCents === null || $refundedCents === null) {
        throw new UnexpectedValueException('A payment amount could not be interpreted safely.');
    }
    $netCents = $status === 'refunded' ? 0 : max(0, $amountCents - $refundedCents);

    return intdiv($netCents, 100) . '.' . str_pad((string) ($netCents % 100), 2, '0', STR_PAD_LEFT);
}

function parent_portal_get_fee_assignments(
    mysqli $db,
    int $parentId,
    int $institutionId,
    int $studentId = 0
): array {
    $studentFilter = $studentId > 0 ? ' AND s.id = ?' : '';
    $statement = $db->prepare(
        "SELECT sfa.id, sfa.student_id, sfa.fee_structure_id,
                s.student_code, s.full_name AS student_name,
                fs.name AS fee_structure_name, ay.name AS academic_year_name,
                sfa.total_fee, sfa.total_discount, sfa.total_adjustments,
                sfa.total_paid, sfa.outstanding_amount, sfa.status,
                psr.can_make_payments,
                (SELECT MIN(ins.due_date)
                 FROM installments ins
                 WHERE ins.institution_id = sfa.institution_id
                   AND ins.student_fee_assignment_id = sfa.id
                   AND ins.status IN ('pending', 'partial', 'overdue')
                   AND ins.due_date >= CURRENT_DATE) AS next_due_date
         FROM student_fee_assignments sfa
         JOIN students s ON s.institution_id = sfa.institution_id AND s.id = sfa.student_id
         JOIN fee_structures fs
           ON fs.institution_id = sfa.institution_id AND fs.id = sfa.fee_structure_id
         JOIN academic_years ay
           ON ay.institution_id = sfa.institution_id AND ay.id = sfa.academic_year_id
         JOIN parent_student_relationships psr
           ON psr.institution_id = sfa.institution_id AND psr.student_id = sfa.student_id
          AND psr.parent_id = ? AND psr.status = 'active' AND psr.can_view_fees = 1
         JOIN parents p ON p.institution_id = psr.institution_id AND p.id = psr.parent_id
         JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
         WHERE sfa.institution_id = ? AND sfa.status IN ('active', 'completed', 'suspended')
           AND p.status = 'active' AND u.status = 'active'
           AND u.email_verified_at IS NOT NULL AND u.user_type = 'parent'{$studentFilter}
         ORDER BY ay.start_date DESC, s.full_name, fs.name"
    );
    if ($studentId > 0) {
        $statement->bind_param('iii', $parentId, $institutionId, $studentId);
    } else {
        $statement->bind_param('ii', $parentId, $institutionId);
    }
    $statement->execute();
    $result = $statement->get_result();
    $assignments = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['student_id'] = (int) $row['student_id'];
        $row['can_make_payments'] = (bool) $row['can_make_payments'];
        $assignments[] = $row;
    }
    $statement->close();

    return $assignments;
}

function parent_portal_get_fee_assignment(
    mysqli $db,
    int $parentId,
    int $institutionId,
    int $assignmentId
): ?array {
    $assignments = parent_portal_get_fee_assignments($db, $parentId, $institutionId);
    foreach ($assignments as $assignment) {
        if ($assignment['id'] === $assignmentId) {
            $statement = $db->prepare(
                'SELECT fsi.amount, fsi.is_optional, fc.name AS category_name
                 FROM fee_structure_items fsi
                 JOIN fee_categories fc
                   ON fc.institution_id = fsi.institution_id AND fc.id = fsi.category_id
                 WHERE fsi.institution_id = ? AND fsi.fee_structure_id = ?
                 ORDER BY fsi.sort_order, fc.name'
            );
            $statement->bind_param('ii', $institutionId, $assignment['fee_structure_id']);
            $statement->execute();
            $items = $statement->get_result();
            $assignment['items'] = [];
            while ($item = $items->fetch_assoc()) {
                $assignment['items'][] = $item;
            }
            $statement->close();

            $statement = $db->prepare(
                'SELECT id, installment_number, due_date, due_amount, amount_paid,
                        penalty_amount, discount_amount, adjustment_amount, status
                 FROM installments
                 WHERE institution_id = ? AND student_fee_assignment_id = ?
                 ORDER BY installment_number'
            );
            $statement->bind_param('ii', $institutionId, $assignmentId);
            $statement->execute();
            $installments = $statement->get_result();
            $assignment['installments'] = [];
            while ($installment = $installments->fetch_assoc()) {
                $assignment['installments'][] = $installment;
            }
            $statement->close();

            return $assignment;
        }
    }

    return null;
}

function parent_portal_get_upcoming_installments(
    mysqli $db,
    int $parentId,
    int $institutionId,
    int $limit = 5
): array {
    $limit = min(max($limit, 1), 20);
    $statement = $db->prepare(
        "SELECT ins.id, ins.due_date, ins.installment_number, ins.due_amount,
                ins.amount_paid, ins.penalty_amount, ins.discount_amount,
                ins.adjustment_amount, ins.status,
                s.id AS student_id, s.full_name AS student_name,
                s.student_code, sfa.id AS assignment_id,
                GREATEST(ins.due_amount + ins.penalty_amount + ins.adjustment_amount
                    - ins.discount_amount - ins.amount_paid, 0) AS remaining_amount
         FROM installments ins
         JOIN student_fee_assignments sfa
           ON sfa.institution_id = ins.institution_id
          AND sfa.id = ins.student_fee_assignment_id
         JOIN students s ON s.institution_id = sfa.institution_id AND s.id = sfa.student_id
         JOIN parent_student_relationships psr
           ON psr.institution_id = s.institution_id AND psr.student_id = s.id
          AND psr.parent_id = ? AND psr.status = 'active' AND psr.can_view_fees = 1
         JOIN parents p ON p.institution_id = psr.institution_id AND p.id = psr.parent_id
         JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
         WHERE ins.institution_id = ? AND ins.due_date >= CURRENT_DATE
           AND ins.status IN ('pending', 'partial', 'overdue')
           AND sfa.status IN ('active', 'completed', 'suspended')
           AND p.status = 'active' AND u.status = 'active'
           AND u.email_verified_at IS NOT NULL AND u.user_type = 'parent'
           AND (ins.due_amount + ins.penalty_amount + ins.adjustment_amount
                - ins.discount_amount - ins.amount_paid) > 0
         ORDER BY ins.due_date, ins.id LIMIT ?"
    );
    $statement->bind_param('iii', $parentId, $institutionId, $limit);
    $statement->execute();
    $result = $statement->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $statement->close();

    return $rows;
}

function parent_portal_list_payments(
    mysqli $db,
    int $parentId,
    int $institutionId,
    array $filters = [],
    int $page = 1,
    int $pageSize = 15
): array {
    $where = [
        'pay.institution_id = ?',
        "psr.status = 'active'",
        'psr.can_view_fees = 1',
        "p.status = 'active'",
        "u.status = 'active'",
        "u.email_verified_at IS NOT NULL",
        "u.user_type = 'parent'",
    ];
    $values = [$institutionId];
    $types = 'i';

    if (($filters['student_id'] ?? 0) > 0) {
        $where[] = 'pay.student_id = ?';
        $values[] = (int) $filters['student_id'];
        $types .= 'i';
    }
    if (($filters['assignment_id'] ?? 0) > 0) {
        $where[] = 'EXISTS (
            SELECT 1 FROM installments payment_installment
            WHERE payment_installment.institution_id = pay.institution_id
              AND payment_installment.id = COALESCE(pay.installment_id, po.installment_id)
              AND payment_installment.student_fee_assignment_id = ?
        )';
        $values[] = (int) $filters['assignment_id'];
        $types .= 'i';
    }
    if (($filters['status'] ?? '') !== '') {
        $where[] = 'pay.status = ?';
        $values[] = (string) $filters['status'];
        $types .= 's';
    }
    foreach (['from' => 'DATE(COALESCE(pay.verified_at, pay.captured_at, pay.created_at)) >= ?', 'to' => 'DATE(COALESCE(pay.verified_at, pay.captured_at, pay.created_at)) <= ?'] as $key => $condition) {
        if (($filters[$key] ?? '') !== '') {
            $where[] = $condition;
            $values[] = (string) $filters[$key];
            $types .= 's';
        }
    }
    $from = 'FROM payments pay
        JOIN students s ON s.institution_id = pay.institution_id AND s.id = pay.student_id
        JOIN payment_orders po ON po.institution_id = pay.institution_id AND po.id = pay.payment_order_id
        JOIN parent_student_relationships psr
          ON psr.institution_id = s.institution_id AND psr.student_id = s.id AND psr.parent_id = ?
        JOIN parents p ON p.institution_id = psr.institution_id AND p.id = psr.parent_id
        JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
        LEFT JOIN receipts rec
          ON rec.institution_id = pay.institution_id AND rec.payment_id = pay.id
         AND rec.status IN (\'issued\', \'downloaded\')
        WHERE ' . implode(' AND ', $where);
    $countFrom = 'FROM payments pay
        JOIN students s ON s.institution_id = pay.institution_id AND s.id = pay.student_id
        JOIN payment_orders po ON po.institution_id = pay.institution_id AND po.id = pay.payment_order_id
        JOIN parent_student_relationships psr
          ON psr.institution_id = s.institution_id AND psr.student_id = s.id AND psr.parent_id = ?
        JOIN parents p ON p.institution_id = psr.institution_id AND p.id = psr.parent_id
        JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
        WHERE ' . implode(' AND ', $where);
    $countStatement = $db->prepare('SELECT COUNT(DISTINCT pay.id) AS total ' . $countFrom);
    $countValues = [$parentId, ...$values];
    $countTypes = 'i' . $types;
    parent_portal_bind_params($countStatement, $countTypes, $countValues);
    $countStatement->execute();
    $total = (int) $countStatement->get_result()->fetch_assoc()['total'];
    $countStatement->close();

    $pageSize = min(max($pageSize, 1), 50);
    $totalPages = max(1, (int) ceil($total / $pageSize));
    $page = min(max($page, 1), $totalPages);
    $offset = ($page - 1) * $pageSize;
    $query = 'SELECT DISTINCT pay.id, pay.gateway, pay.gateway_transaction_id,
                     pay.gateway_reference, pay.amount, pay.currency, pay.status,
                     pay.verified_at, pay.captured_at, pay.created_at,
                     COALESCE((
                         SELECT SUM(ref.amount)
                         FROM refunds ref
                         WHERE ref.institution_id = pay.institution_id
                           AND ref.payment_id = pay.id AND ref.status = \'processed\'
                     ), 0) AS refunded_amount,
                     s.id AS student_id, s.full_name AS student_name, s.student_code,
                     rec.id AS receipt_id, rec.receipt_number
              ' . $from . '
              ORDER BY pay.created_at DESC, pay.id DESC LIMIT ? OFFSET ?';
    $statement = $db->prepare($query);
    $queryValues = [$parentId, ...$values, $pageSize, $offset];
    $queryTypes = 'i' . $types . 'ii';
    parent_portal_bind_params($statement, $queryTypes, $queryValues);
    $statement->execute();
    $result = $statement->get_result();
    $payments = [];
    while ($payment = $result->fetch_assoc()) {
        $payment['net_amount'] = parent_portal_net_payment_amount(
            (string) $payment['amount'],
            (string) $payment['refunded_amount'],
            (string) $payment['status']
        );
        $payments[] = $payment;
    }
    $statement->close();

    return ['payments' => $payments, 'total' => $total, 'page' => $page, 'total_pages' => $totalPages];
}

function parent_portal_get_payment(
    mysqli $db,
    int $parentId,
    int $institutionId,
    int $paymentId
): ?array {
    $statement = $db->prepare(
        "SELECT pay.id, pay.institution_id, pay.payment_order_id, pay.installment_id,
                pay.gateway, pay.gateway_transaction_id, pay.gateway_reference,
                pay.amount, pay.currency, pay.status, pay.verified_at, pay.captured_at,
                pay.created_at, s.id AS student_id, s.full_name AS student_name,
                COALESCE((
                    SELECT SUM(ref.amount)
                    FROM refunds ref
                    WHERE ref.institution_id = pay.institution_id
                      AND ref.payment_id = pay.id AND ref.status = 'processed'
                ), 0) AS refunded_amount,
                s.student_code, po.order_number, po.status AS order_status,
                ins.installment_number, ins.due_date, sfa.id AS assignment_id,
                rec.id AS receipt_id, rec.receipt_number
         FROM payments pay
         JOIN students s ON s.institution_id = pay.institution_id AND s.id = pay.student_id
         JOIN parent_student_relationships psr
           ON psr.institution_id = s.institution_id AND psr.student_id = s.id
          AND psr.parent_id = ? AND psr.status = 'active' AND psr.can_view_fees = 1
         JOIN parents p ON p.institution_id = psr.institution_id AND p.id = psr.parent_id
         JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
         JOIN payment_orders po
           ON po.institution_id = pay.institution_id AND po.id = pay.payment_order_id
         LEFT JOIN installments ins
           ON ins.institution_id = pay.institution_id AND ins.id = pay.installment_id
         LEFT JOIN student_fee_assignments sfa
           ON sfa.institution_id = ins.institution_id AND sfa.id = ins.student_fee_assignment_id
         LEFT JOIN receipts rec
           ON rec.institution_id = pay.institution_id AND rec.payment_id = pay.id
          AND rec.status IN ('issued', 'downloaded')
         WHERE pay.institution_id = ? AND pay.id = ?
           AND p.status = 'active' AND u.status = 'active'
           AND u.email_verified_at IS NOT NULL AND u.user_type = 'parent'
         LIMIT 1"
    );
    $statement->bind_param('iii', $parentId, $institutionId, $paymentId);
    $statement->execute();
    $payment = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_array($payment)) {
        return null;
    }
    $payment['net_amount'] = parent_portal_net_payment_amount(
        (string) $payment['amount'],
        (string) $payment['refunded_amount'],
        (string) $payment['status']
    );
    return $payment;
}

function parent_portal_get_receipt(
    mysqli $db,
    int $parentId,
    int $institutionId,
    int $receiptId
): ?array {
    $statement = $db->prepare(
        "SELECT rec.id, rec.receipt_number, rec.total_amount,
                rec.status AS receipt_status, rec.issued_at, rec.created_at,
                pay.id AS payment_id, pay.currency, pay.status AS payment_status,
                pay.gateway, pay.gateway_transaction_id, pay.gateway_reference,
                pay.verified_at, pay.captured_at, pay.created_at AS payment_created_at,
                s.full_name AS student_name, s.student_code,
                p.full_name AS parent_name, ins.name AS institution_name,
                ins.logo_path AS institution_logo_path,
                ins.address_line1 AS institution_address_line1,
                ins.address_line2 AS institution_address_line2,
                ins.city AS institution_city, ins.state AS institution_state,
                ins.postal_code AS institution_postal_code, ins.country AS institution_country,
                ins.phone AS institution_phone, ins.email AS institution_email,
                co.name AS course_name, c.name AS class_name, b.name AS batch_name,
                ay.name AS academic_year_name,
                inst.installment_number, inst.due_date AS installment_due_date,
                sfa.outstanding_amount AS remaining_balance
         FROM receipts rec
         JOIN payments pay
           ON pay.institution_id = rec.institution_id AND pay.id = rec.payment_id
         JOIN students s ON s.institution_id = rec.institution_id AND s.id = rec.student_id
         JOIN institutions ins ON ins.id = rec.institution_id AND ins.status = 'active'
         JOIN parent_student_relationships psr
           ON psr.institution_id = s.institution_id AND psr.student_id = s.id
          AND psr.parent_id = ? AND psr.status = 'active' AND psr.can_view_fees = 1
         JOIN parents p ON p.institution_id = psr.institution_id AND p.id = psr.parent_id
         JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
         LEFT JOIN courses co ON co.institution_id = s.institution_id AND co.id = s.course_id
         LEFT JOIN classes c ON c.institution_id = s.institution_id AND c.id = s.class_id
         LEFT JOIN batches b ON b.institution_id = s.institution_id AND b.id = s.batch_id
         LEFT JOIN academic_years ay ON ay.institution_id = s.institution_id AND ay.id = s.academic_year_id
         LEFT JOIN installments inst
           ON inst.institution_id = pay.institution_id AND inst.id = pay.installment_id
         LEFT JOIN student_fee_assignments sfa
           ON sfa.institution_id = inst.institution_id AND sfa.id = inst.student_fee_assignment_id
         WHERE rec.institution_id = ? AND rec.id = ?
           AND rec.status IN ('issued', 'downloaded')
           AND rec.parent_id = ? AND pay.parent_id = rec.parent_id
           AND rec.student_id = pay.student_id
           AND pay.status IN ('success', 'partial_refund', 'refunded')
           AND pay.verified_at IS NOT NULL
           AND p.status = 'active' AND u.status = 'active'
           AND u.email_verified_at IS NOT NULL AND u.user_type = 'parent'
         LIMIT 1"
    );
    $statement->bind_param('iiii', $parentId, $institutionId, $receiptId, $parentId);
    $statement->execute();
    $receipt = $statement->get_result()->fetch_assoc();
    $statement->close();
    return is_array($receipt) ? $receipt : null;
}

function parent_portal_list_receipts(mysqli $db, int $parentId, int $institutionId): array
{
    $statement = $db->prepare(
        "SELECT rec.id, rec.receipt_number, rec.total_amount, rec.issued_at,
                rec.created_at, pay.currency, pay.status AS payment_status,
                pay.verified_at, s.full_name AS student_name,
                s.student_code
         FROM receipts rec
         JOIN payments pay
           ON pay.institution_id = rec.institution_id AND pay.id = rec.payment_id
         JOIN students s ON s.institution_id = rec.institution_id AND s.id = rec.student_id
         JOIN parent_student_relationships psr
           ON psr.institution_id = s.institution_id AND psr.student_id = s.id
          AND psr.parent_id = ? AND psr.status = 'active' AND psr.can_view_fees = 1
         JOIN parents p ON p.institution_id = psr.institution_id AND p.id = psr.parent_id
         JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
         WHERE rec.institution_id = ? AND rec.status IN ('issued', 'downloaded')
           AND rec.parent_id = ? AND pay.parent_id = rec.parent_id
           AND rec.student_id = pay.student_id
           AND pay.status IN ('success', 'partial_refund', 'refunded')
           AND pay.verified_at IS NOT NULL
           AND p.status = 'active' AND u.status = 'active'
           AND u.email_verified_at IS NOT NULL AND u.user_type = 'parent'
         ORDER BY COALESCE(rec.issued_at, rec.created_at) DESC, rec.id DESC
         LIMIT 100"
    );
    $statement->bind_param('iii', $parentId, $institutionId, $parentId);
    $statement->execute();
    $result = $statement->get_result();
    $receipts = [];
    while ($receipt = $result->fetch_assoc()) {
        $receipts[] = $receipt;
    }
    $statement->close();
    return $receipts;
}

function parent_portal_get_assignment_components(mysqli $db, int $institutionId, int $assignmentId): array
{
    $statement = $db->prepare(
        'SELECT adjustment_type, amount, reason, created_at
         FROM fee_adjustments
         WHERE institution_id = ? AND student_fee_assignment_id = ?
         ORDER BY created_at, adjustment_type'
    );
    $statement->bind_param('ii', $institutionId, $assignmentId);
    $statement->execute();
    $result = $statement->get_result();
    $adjustments = [];
    while ($row = $result->fetch_assoc()) {
        $adjustments[] = $row;
    }
    $statement->close();

    $statement = $db->prepare(
        'SELECT discount_type, amount, reason, applied_at
         FROM fee_discounts
         WHERE institution_id = ? AND student_fee_assignment_id = ?
         ORDER BY applied_at, discount_type'
    );
    $statement->bind_param('ii', $institutionId, $assignmentId);
    $statement->execute();
    $result = $statement->get_result();
    $discounts = [];
    while ($row = $result->fetch_assoc()) {
        $discounts[] = $row;
    }
    $statement->close();
    return ['adjustments' => $adjustments, 'discounts' => $discounts];
}

function parent_portal_require_current_parent(mysqli $db): array
{
    $user = current_user();
    if ($user === null || $user['user_type'] !== 'parent') {
        http_response_code(403);
        exit('Forbidden.');
    }
    $parent = parent_portal_get_parent_context(
        $db,
        (int) $user['id'],
        (int) $user['institution_id']
    );
    if ($parent === null) {
        http_response_code(403);
        exit('Forbidden.');
    }
    return ['user' => $user, 'parent' => $parent];
}

function parent_portal_get_announcements(mysqli $db, int $parentId, int $institutionId, int $limit = 50): array
{
    $limit = min(max($limit, 1), 100);
    $statement = $db->prepare(
        "SELECT DISTINCT a.id, a.title, a.message, a.publish_at, a.expires_at,
                a.created_at
         FROM announcements a
         WHERE a.institution_id = ? AND a.status = 'published'
           AND (a.publish_at IS NULL OR a.publish_at <= CURRENT_TIMESTAMP)
           AND (a.expires_at IS NULL OR a.expires_at >= CURRENT_TIMESTAMP)
           AND (
               NOT EXISTS (
                   SELECT 1 FROM announcement_batches ab
                   WHERE ab.institution_id = a.institution_id AND ab.announcement_id = a.id
               )
               OR EXISTS (
                   SELECT 1 FROM announcement_batches ab
                   JOIN batches b ON b.institution_id = ab.institution_id AND b.id = ab.batch_id
                   JOIN students s ON s.institution_id = b.institution_id AND s.batch_id = b.id
                   JOIN parent_student_relationships psr
                     ON psr.institution_id = s.institution_id AND psr.student_id = s.id
                   JOIN parents p ON p.institution_id = psr.institution_id AND p.id = psr.parent_id
                   JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
                   WHERE ab.institution_id = a.institution_id AND ab.announcement_id = a.id
                     AND psr.parent_id = ? AND psr.status = 'active'
                     AND psr.can_view_academics = 1 AND p.status = 'active'
                     AND u.status = 'active' AND u.email_verified_at IS NOT NULL
               )
           )
         ORDER BY COALESCE(a.publish_at, a.created_at) DESC, a.id DESC LIMIT ?"
    );
    $statement->bind_param('iii', $institutionId, $parentId, $limit);
    $statement->execute();
    $result = $statement->get_result();
    $announcements = [];
    while ($row = $result->fetch_assoc()) {
        $announcements[] = $row;
    }
    $statement->close();
    return $announcements;
}

function parent_portal_get_notifications(mysqli $db, int $parentId, int $institutionId, int $userId): array
{
    return notification_service_get_parent_notifications(
        $db,
        $parentId,
        $institutionId,
        $userId,
        1,
        100
    )['items'];
}

function parent_portal_get_profile(mysqli $db, int $parentId, int $institutionId, int $userId): ?array
{
    $statement = $db->prepare(
        "SELECT p.full_name, p.mobile, p.alternate_mobile, COALESCE(p.email, u.email) AS email,
                p.relationship_to_student, p.address_line1, p.address_line2,
                p.city, p.state, p.postal_code, p.country, p.emergency_contact,
                p.status AS parent_status, u.email AS account_email,
                u.email_verified_at, u.mobile_verified_at, u.status AS account_status,
                u.created_at
         FROM parents p
         JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
         JOIN roles r ON r.id = u.role_id AND r.name = 'parent'
         WHERE p.id = ? AND p.institution_id = ? AND p.user_id = ?
           AND u.user_type = 'parent'
         LIMIT 1"
    );
    $statement->bind_param('iii', $parentId, $institutionId, $userId);
    $statement->execute();
    $profile = $statement->get_result()->fetch_assoc();
    $statement->close();
    return is_array($profile) ? $profile : null;
}

function parent_portal_validate_profile_update(array $input): array
{
    $fields = [
        'full_name' => 150,
        'alternate_mobile' => 20,
        'address_line1' => 255,
        'address_line2' => 255,
        'city' => 120,
        'state' => 120,
        'postal_code' => 20,
        'country' => 120,
        'emergency_contact' => 30,
    ];
    $data = [];
    $errors = [];
    foreach ($fields as $field => $maxLength) {
        $value = $input[$field] ?? '';
        if (!is_string($value)) {
            $errors[] = 'One or more profile fields contain invalid values.';
            $value = '';
        }
        $value = trim($value);
        if (mb_strlen($value, 'UTF-8') > $maxLength || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            $errors[] = 'One or more profile fields are too long or contain invalid characters.';
        }
        if (in_array($field, ['alternate_mobile', 'emergency_contact'], true) && $value !== '') {
            $normalized = normalize_mobile_number($value);
            if ($normalized === null || strlen($normalized) > $maxLength) {
                $errors[] = 'Enter a valid alternate and emergency contact number, or leave it blank.';
            }
            $value = $normalized ?? $value;
        }
        $data[$field] = $value === '' && $field !== 'full_name' && $field !== 'country'
            ? null
            : $value;
    }
    if ($data['full_name'] === '') {
        $errors[] = 'Enter your name.';
    }
    if ($data['country'] === '') {
        $data['country'] = 'India';
    }
    return ['data' => $data, 'errors' => array_values(array_unique($errors))];
}

function parent_portal_update_profile(
    mysqli $db,
    int $parentId,
    int $institutionId,
    int $userId,
    array $profile
): void {
    $old = parent_portal_get_profile($db, $parentId, $institutionId, $userId);
    if ($old === null) {
        throw new OutOfBoundsException('Parent profile not found.');
    }
    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            'UPDATE parents
             SET full_name = ?, alternate_mobile = ?, address_line1 = ?, address_line2 = ?,
                 city = ?, state = ?, postal_code = ?, country = ?, emergency_contact = ?
             WHERE id = ? AND institution_id = ? AND user_id = ?'
        );
        $statement->bind_param(
            'sssssssssiii',
            $profile['full_name'],
            $profile['alternate_mobile'],
            $profile['address_line1'],
            $profile['address_line2'],
            $profile['city'],
            $profile['state'],
            $profile['postal_code'],
            $profile['country'],
            $profile['emergency_contact'],
            $parentId,
            $institutionId,
            $userId
        );
        $statement->execute();
        $statement->close();

        $statement = $db->prepare(
            "UPDATE users SET full_name = ?
             WHERE id = ? AND institution_id = ? AND user_type = 'parent'"
        );
        $statement->bind_param('sii', $profile['full_name'], $userId, $institutionId);
        $statement->execute();
        $statement->close();

        write_audit_log(
            $db,
            $institutionId,
            $userId,
            'parent',
            'parent.self_profile_updated',
            'parent',
            (string) $parentId,
            [
                'full_name' => $old['full_name'],
                'alternate_mobile' => $old['alternate_mobile'],
                'address_line1' => $old['address_line1'],
                'address_line2' => $old['address_line2'],
                'city' => $old['city'],
                'state' => $old['state'],
                'postal_code' => $old['postal_code'],
                'country' => $old['country'],
                'emergency_contact' => $old['emergency_contact'],
            ],
            $profile
        );
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}
