<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/audit-service.php';

function admin_panel_institution_id(): int
{
    $user = current_user();
    if ($user === null
        || !in_array($user['user_type'], ['super_admin', 'admin', 'staff'], true)
        || !user_can_access_institution((int) $user['institution_id'])) {
        throw new RuntimeException('An authenticated institution account is required.');
    }

    return (int) $user['institution_id'];
}

function admin_panel_scalar(mysqli $db, int $institutionId, string $query): int|string
{
    $statement = $db->prepare($query);
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $value = $statement->get_result()->fetch_row();
    $statement->close();
    if (!is_array($value) || !isset($value[0])) {
        throw new RuntimeException('Dashboard summary data could not be loaded.');
    }

    return is_numeric($value[0]) ? (string) $value[0] : (string) $value[0];
}

function admin_panel_dashboard_data(mysqli $db, int $institutionId, bool $includeRecentPayments = true): array
{
    $counts = [
        'active_students' => admin_panel_scalar(
            $db,
            $institutionId,
            "SELECT COUNT(*) FROM students WHERE institution_id = ? AND status = 'active'"
        ),
        'active_parents' => admin_panel_scalar(
            $db,
            $institutionId,
            "SELECT COUNT(*) FROM parents p
             JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
             WHERE p.institution_id = ? AND p.status = 'active'
               AND u.user_type = 'parent' AND u.status = 'active'
               AND u.email_verified_at IS NOT NULL"
        ),
        'active_staff' => admin_panel_scalar(
            $db,
            $institutionId,
            "SELECT COUNT(*) FROM users
             WHERE institution_id = ? AND user_type = 'staff' AND status = 'active'
               AND email_verified_at IS NOT NULL"
        ),
        'assigned_fees' => admin_panel_scalar(
            $db,
            $institutionId,
            "SELECT COALESCE(SUM(total_fee), 0) FROM student_fee_assignments
             WHERE institution_id = ? AND status IN ('active', 'completed', 'suspended')"
        ),
        'outstanding_balance' => admin_panel_scalar(
            $db,
            $institutionId,
            "SELECT COALESCE(SUM(outstanding_amount), 0) FROM student_fee_assignments
             WHERE institution_id = ? AND status IN ('active', 'completed', 'suspended')"
        ),
        'overdue_balance' => admin_panel_scalar(
            $db,
            $institutionId,
            "SELECT COALESCE(SUM(GREATEST(
                due_amount + penalty_amount + adjustment_amount - discount_amount - amount_paid, 0
             )), 0)
             FROM installments
             WHERE institution_id = ? AND due_date < CURRENT_DATE
               AND status IN ('pending', 'partial', 'overdue')"
        ),
        'pending_orders' => admin_panel_scalar(
            $db,
            $institutionId,
            "SELECT COUNT(*) FROM payment_orders
             WHERE institution_id = ? AND status IN ('pending', 'created', 'authorized')"
        ),
        'pending_refunds' => admin_panel_scalar(
            $db,
            $institutionId,
            "SELECT COUNT(*) FROM refunds
             WHERE institution_id = ? AND status IN ('requested', 'approved')"
        ),
        'failed_webhooks' => admin_panel_scalar(
            $db,
            $institutionId,
            "SELECT COUNT(*) FROM payment_webhook_events
             WHERE institution_id = ? AND processing_status = 'failed'"
        ),
    ];

    $statement = $db->prepare(
        "SELECT COALESCE(SUM(
            CASE
                WHEN pay.status = 'refunded' THEN 0
                ELSE GREATEST(pay.amount - COALESCE(refund_totals.refunded_amount, 0), 0)
            END
         ), 0) AS collected_amount
         FROM payments pay
         LEFT JOIN (
             SELECT institution_id, payment_id, SUM(amount) AS refunded_amount
             FROM refunds WHERE status = 'processed'
             GROUP BY institution_id, payment_id
         ) refund_totals
           ON refund_totals.institution_id = pay.institution_id
          AND refund_totals.payment_id = pay.id
         WHERE pay.institution_id = ?
           AND pay.status IN ('success', 'partial_refund', 'refunded')
           AND pay.verified_at IS NOT NULL"
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $collected = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_array($collected)) {
        throw new RuntimeException('Collected payment totals could not be loaded.');
    }
    $counts['collected_amount'] = (string) $collected['collected_amount'];

    $payments = [];
    if ($includeRecentPayments) {
        $statement = $db->prepare(
        "SELECT pay.id, pay.amount, pay.currency, pay.status, pay.gateway,
                pay.gateway_reference, pay.gateway_transaction_id,
                COALESCE(pay.verified_at, pay.captured_at, pay.created_at) AS occurred_at,
                s.id AS student_id, s.full_name AS student_name, s.student_code,
                p.full_name AS parent_name, rec.receipt_number,
                COALESCE(refund_totals.refunded_amount, 0) AS refunded_amount
         FROM payments pay
         JOIN students s ON s.institution_id = pay.institution_id AND s.id = pay.student_id
         JOIN parents p ON p.institution_id = pay.institution_id AND p.id = pay.parent_id
         LEFT JOIN receipts rec
           ON rec.institution_id = pay.institution_id AND rec.payment_id = pay.id
          AND rec.status IN ('issued', 'downloaded')
         LEFT JOIN (
             SELECT institution_id, payment_id, SUM(amount) AS refunded_amount
             FROM refunds WHERE status = 'processed'
             GROUP BY institution_id, payment_id
         ) refund_totals
           ON refund_totals.institution_id = pay.institution_id
          AND refund_totals.payment_id = pay.id
         WHERE pay.institution_id = ?
         ORDER BY pay.created_at DESC, pay.id DESC
         LIMIT 8"
        );
        $statement->bind_param('i', $institutionId);
        $statement->execute();
        $result = $statement->get_result();
        while ($payment = $result->fetch_assoc()) {
            $payments[] = $payment;
        }
        $statement->close();
    }

    $statement = $db->prepare(
        "SELECT DATE_FORMAT(pay.verified_at, '%Y-%m') AS month_key,
                COALESCE(SUM(
                    CASE
                        WHEN pay.status = 'refunded' THEN 0
                        ELSE GREATEST(pay.amount - COALESCE(refund_totals.refunded_amount, 0), 0)
                    END
                ), 0) AS amount
         FROM payments pay
         LEFT JOIN (
             SELECT institution_id, payment_id, SUM(amount) AS refunded_amount
             FROM refunds WHERE status = 'processed'
             GROUP BY institution_id, payment_id
         ) refund_totals
           ON refund_totals.institution_id = pay.institution_id
          AND refund_totals.payment_id = pay.id
         WHERE pay.institution_id = ?
           AND pay.status IN ('success', 'partial_refund', 'refunded')
           AND pay.verified_at >= DATE_SUB(CURRENT_DATE, INTERVAL 5 MONTH)
           AND pay.verified_at < DATE_ADD(LAST_DAY(CURRENT_DATE), INTERVAL 1 DAY)
         GROUP BY DATE_FORMAT(pay.verified_at, '%Y-%m')
         ORDER BY month_key"
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $result = $statement->get_result();
    $collectionByMonth = [];
    while ($row = $result->fetch_assoc()) {
        $collectionByMonth[$row['month_key']] = (string) $row['amount'];
    }
    $statement->close();

    $monthLabels = [];
    $maxCollection = 0.0;
    for ($offset = 5; $offset >= 0; $offset--) {
        $month = new DateTimeImmutable('first day of this month');
        if ($offset > 0) {
            $month = $month->modify('-' . $offset . ' months');
        }
        $key = $month->format('Y-m');
        $amount = (float) ($collectionByMonth[$key] ?? 0);
        $monthLabels[] = ['label' => $month->format('M'), 'amount' => $amount];
        $maxCollection = max($maxCollection, $amount);
    }
    foreach ($monthLabels as &$monthLabel) {
        $monthLabel['height'] = $maxCollection > 0
            ? (int) round(140 * $monthLabel['amount'] / $maxCollection)
            : 0;
    }
    unset($monthLabel);

    return [
        'counts' => $counts,
        'payments' => $payments,
        'monthly_collections' => $monthLabels,
        'month_max' => $maxCollection,
    ];
}

function admin_panel_decimal_cents(string $value): int
{
    if (preg_match('/^(-?)([0-9]+)(?:\.([0-9]{1,2}))?$/D', $value, $matches) !== 1) {
        throw new UnexpectedValueException('A monetary database value has an invalid format.');
    }
    $cents = ((int) $matches[2] * 100) + (int) str_pad($matches[3] ?? '0', 2, '0');
    return ($matches[1] ?? '') === '-' ? -$cents : $cents;
}

function admin_panel_net_payment_amount(array $payment): string
{
    $amount = admin_panel_decimal_cents((string) ($payment['amount'] ?? '0'));
    $refunded = admin_panel_decimal_cents((string) ($payment['refunded_amount'] ?? '0'));
    $net = ($payment['status'] ?? '') === 'refunded' ? 0 : max(0, $amount - $refunded);

    return intdiv($net, 100) . '.' . str_pad((string) ($net % 100), 2, '0', STR_PAD_LEFT);
}

function admin_panel_bind_params(mysqli_stmt $statement, string $types, array &$values): void
{
    $parameters = [$types];
    foreach ($values as &$value) {
        $parameters[] = &$value;
    }
    unset($value);
    if (!call_user_func_array([$statement, 'bind_param'], $parameters)) {
        throw new RuntimeException('The admin panel query could not bind its parameters.');
    }
}

function admin_panel_payment_list(
    mysqli $db,
    int $institutionId,
    string $search = '',
    string $status = '',
    int $page = 1,
    int $pageSize = 20
): array {
    $where = ['pay.institution_id = ?'];
    $values = [$institutionId];
    $types = 'i';
    if ($status !== '') {
        $where[] = 'pay.status = ?';
        $values[] = $status;
        $types .= 's';
    }
    if ($search !== '') {
        $pattern = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $where[] = '(s.full_name LIKE ? ESCAPE \'!\' OR s.student_code LIKE ? ESCAPE \'!\' OR p.full_name LIKE ? ESCAPE \'!\' OR pay.gateway_reference LIKE ? ESCAPE \'!\' OR pay.gateway_transaction_id LIKE ? ESCAPE \'!\')';
        array_push($values, $pattern, $pattern, $pattern, $pattern, $pattern);
        $types .= 'sssss';
    }
    $from = ' FROM payments pay
        JOIN students s ON s.institution_id = pay.institution_id AND s.id = pay.student_id
        JOIN parents p ON p.institution_id = pay.institution_id AND p.id = pay.parent_id
        LEFT JOIN receipts rec
          ON rec.institution_id = pay.institution_id AND rec.payment_id = pay.id
         AND rec.status IN (\'issued\', \'downloaded\')
        WHERE ' . implode(' AND ', $where);

    $count = $db->prepare('SELECT COUNT(DISTINCT pay.id)' . $from);
    $countValues = $values;
    admin_panel_bind_params($count, $types, $countValues);
    $count->execute();
    $total = (int) $count->get_result()->fetch_row()[0];
    $count->close();

    $pageSize = min(max($pageSize, 1), 100);
    $totalPages = max(1, (int) ceil($total / $pageSize));
    $page = min(max($page, 1), $totalPages);
    $offset = ($page - 1) * $pageSize;
    $query = 'SELECT DISTINCT pay.id, pay.amount, pay.currency, pay.status, pay.gateway,
                     pay.gateway_reference, pay.gateway_transaction_id, pay.verified_at,
                     pay.captured_at, pay.created_at, s.full_name AS student_name,
                     s.student_code, p.full_name AS parent_name, rec.receipt_number
              ' . $from . '
              ORDER BY pay.created_at DESC, pay.id DESC LIMIT ? OFFSET ?';
    $statement = $db->prepare($query);
    $queryValues = [...$values, $pageSize, $offset];
    admin_panel_bind_params($statement, $types . 'ii', $queryValues);
    $statement->execute();
    $result = $statement->get_result();
    $payments = [];
    while ($payment = $result->fetch_assoc()) {
        $payments[] = $payment;
    }
    $statement->close();

    return ['payments' => $payments, 'total' => $total, 'page' => $page, 'total_pages' => $totalPages];
}

function admin_panel_payment_details(mysqli $db, int $institutionId, int $paymentId): ?array
{
    $statement = $db->prepare(
        'SELECT pay.id, pay.payment_order_id, pay.installment_id, pay.amount, pay.currency,
                pay.status, pay.gateway, pay.gateway_reference, pay.gateway_transaction_id,
                pay.verified_at, pay.captured_at, pay.created_at, pay.updated_at,
                po.order_number, po.status AS order_status,
                s.id AS student_id, s.full_name AS student_name, s.student_code,
                p.full_name AS parent_name, rec.id AS receipt_id, rec.receipt_number
         FROM payments pay
         JOIN payment_orders po
           ON po.institution_id = pay.institution_id AND po.id = pay.payment_order_id
         JOIN students s ON s.institution_id = pay.institution_id AND s.id = pay.student_id
         JOIN parents p ON p.institution_id = pay.institution_id AND p.id = pay.parent_id
         LEFT JOIN receipts rec
           ON rec.institution_id = pay.institution_id AND rec.payment_id = pay.id
         WHERE pay.institution_id = ? AND pay.id = ? LIMIT 1'
    );
    $statement->bind_param('ii', $institutionId, $paymentId);
    $statement->execute();
    $payment = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_array($payment)) {
        return null;
    }

    $statement = $db->prepare(
        "SELECT amount, reason, status, processed_at, created_at
         FROM refunds WHERE institution_id = ? AND payment_id = ?
         ORDER BY created_at DESC, id DESC"
    );
    $statement->bind_param('ii', $institutionId, $paymentId);
    $statement->execute();
    $payment['refunds'] = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();

    $statement = $db->prepare(
        'SELECT attempt_number, gateway, gateway_reference, status, error_message,
                created_at, updated_at
         FROM payment_attempts WHERE institution_id = ? AND payment_order_id = ?
         ORDER BY attempt_number DESC'
    );
    $statement->bind_param('ii', $institutionId, $payment['payment_order_id']);
    $statement->execute();
    $payment['attempts'] = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $payment;
}

function admin_panel_refunds(mysqli $db, int $institutionId): array
{
    $statement = $db->prepare(
        'SELECT ref.id, ref.amount, ref.reason, ref.status, ref.processed_at, ref.created_at,
                pay.id AS payment_id, pay.currency, pay.status AS payment_status,
                pay.gateway_reference, s.full_name AS student_name, s.student_code,
                p.full_name AS parent_name
         FROM refunds ref
         JOIN payments pay ON pay.institution_id = ref.institution_id AND pay.id = ref.payment_id
         JOIN students s ON s.institution_id = ref.institution_id AND s.id = ref.student_id
         JOIN parents p ON p.institution_id = ref.institution_id AND p.id = ref.parent_id
         WHERE ref.institution_id = ?
         ORDER BY FIELD(ref.status, \'requested\', \'approved\', \'processed\', \'rejected\'),
                  ref.created_at DESC, ref.id DESC LIMIT 200'
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $refunds = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $refunds;
}

function admin_panel_webhook_events(mysqli $db, int $institutionId): array
{
    $statement = $db->prepare(
        'SELECT id, payment_id, gateway, event_type, processing_status, processed_at, created_at
         FROM payment_webhook_events
         WHERE institution_id = ?
         ORDER BY FIELD(processing_status, \'failed\', \'received\', \'processed\'), created_at DESC, id DESC
         LIMIT 200'
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}

function admin_panel_receipts(mysqli $db, int $institutionId): array
{
    $statement = $db->prepare(
        'SELECT rec.id, rec.receipt_number, rec.total_amount, rec.status, rec.file_path,
                rec.issued_at, rec.created_at, pay.currency, pay.status AS payment_status,
                s.full_name AS student_name, s.student_code, p.full_name AS parent_name
         FROM receipts rec
         JOIN payments pay ON pay.institution_id = rec.institution_id AND pay.id = rec.payment_id
         JOIN students s ON s.institution_id = rec.institution_id AND s.id = rec.student_id
         JOIN parents p ON p.institution_id = rec.institution_id AND p.id = rec.parent_id
         WHERE rec.institution_id = ?
         ORDER BY COALESCE(rec.issued_at, rec.created_at) DESC, rec.id DESC LIMIT 200'
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $receipts = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $receipts;
}

function admin_panel_receipt(mysqli $db, int $institutionId, int $receiptId): ?array
{
    $statement = $db->prepare(
        'SELECT id, receipt_number, file_path, status
         FROM receipts WHERE institution_id = ? AND id = ?
           AND status IN (\'issued\', \'downloaded\') LIMIT 1'
    );
    $statement->bind_param('ii', $institutionId, $receiptId);
    $statement->execute();
    $receipt = $statement->get_result()->fetch_assoc();
    $statement->close();
    return is_array($receipt) ? $receipt : null;
}

function admin_panel_outstanding_assignments(mysqli $db, int $institutionId, int $limit = 250): array
{
    $limit = min(max($limit, 1), 500);
    $statement = $db->prepare(
        "SELECT sfa.id AS assignment_id, s.id AS student_id,
                sfa.total_fee, sfa.total_paid, sfa.outstanding_amount, sfa.status,
                ay.name AS academic_year_name, s.full_name AS student_name, s.student_code,
                c.name AS class_name, fs.name AS fee_structure_name,
                (SELECT MIN(ins.due_date)
                 FROM installments ins
                 WHERE ins.institution_id = sfa.institution_id
                   AND ins.student_fee_assignment_id = sfa.id
                   AND ins.status IN ('pending', 'partial', 'overdue')
                   AND ins.due_date < CURRENT_DATE
                   AND (ins.due_amount + ins.penalty_amount + ins.adjustment_amount
                        - ins.discount_amount - ins.amount_paid) > 0) AS oldest_overdue_date
         FROM student_fee_assignments sfa
         JOIN students s ON s.institution_id = sfa.institution_id AND s.id = sfa.student_id
         LEFT JOIN classes c ON c.institution_id = s.institution_id AND c.id = s.class_id
         JOIN fee_structures fs ON fs.institution_id = sfa.institution_id AND fs.id = sfa.fee_structure_id
         JOIN academic_years ay ON ay.institution_id = sfa.institution_id AND ay.id = sfa.academic_year_id
         WHERE sfa.institution_id = ? AND sfa.status IN ('active', 'completed', 'suspended')
           AND sfa.outstanding_amount > 0
         ORDER BY (oldest_overdue_date IS NULL), oldest_overdue_date, s.full_name
         LIMIT ?"
    );
    $statement->bind_param('ii', $institutionId, $limit);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}

function admin_panel_collection_report(
    mysqli $db,
    int $institutionId,
    string $fromDate,
    string $toDate
): array {
    $statement = $db->prepare(
        "SELECT DATE(pay.verified_at) AS collection_date,
                COUNT(*) AS payment_count,
                COALESCE(SUM(CASE
                    WHEN pay.status = 'refunded' THEN 0
                    ELSE GREATEST(pay.amount - COALESCE(refund_totals.refunded_amount, 0), 0)
                END), 0) AS net_collected
         FROM payments pay
         LEFT JOIN (
             SELECT institution_id, payment_id, SUM(amount) AS refunded_amount
             FROM refunds WHERE status = 'processed'
             GROUP BY institution_id, payment_id
         ) refund_totals
           ON refund_totals.institution_id = pay.institution_id
          AND refund_totals.payment_id = pay.id
         WHERE pay.institution_id = ?
           AND pay.status IN ('success', 'partial_refund', 'refunded')
           AND pay.verified_at IS NOT NULL
           AND DATE(pay.verified_at) BETWEEN ? AND ?
         GROUP BY DATE(pay.verified_at)
         ORDER BY collection_date DESC"
    );
    $statement->bind_param('iss', $institutionId, $fromDate, $toDate);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}

function admin_panel_student_fee_report(mysqli $db, int $institutionId, int $limit = 300): array
{
    $limit = min(max($limit, 1), 500);
    $statement = $db->prepare(
        "SELECT s.id, s.full_name, s.student_code, s.status AS student_status,
                c.name AS class_name, COUNT(sfa.id) AS assignment_count,
                COALESCE(SUM(sfa.total_fee), 0) AS assigned_fee,
                COALESCE(SUM(sfa.total_paid), 0) AS recorded_paid,
                COALESCE(SUM(sfa.outstanding_amount), 0) AS outstanding
         FROM students s
         LEFT JOIN classes c ON c.institution_id = s.institution_id AND c.id = s.class_id
         LEFT JOIN student_fee_assignments sfa
           ON sfa.institution_id = s.institution_id AND sfa.student_id = s.id
          AND sfa.status IN ('active', 'completed', 'suspended')
         WHERE s.institution_id = ?
         GROUP BY s.id, c.name
         ORDER BY s.full_name, s.student_code LIMIT ?"
    );
    $statement->bind_param('ii', $institutionId, $limit);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}

function admin_panel_parent_fee_report(mysqli $db, int $institutionId, int $limit = 300): array
{
    $limit = min(max($limit, 1), 500);
    $statement = $db->prepare(
        "SELECT p.id, p.full_name, COALESCE(p.email, u.email) AS email,
                COUNT(DISTINCT IF(psr.status = 'active', psr.student_id, NULL)) AS linked_students,
                COALESCE((
                    SELECT SUM(sfa.outstanding_amount)
                    FROM parent_student_relationships active_link
                    JOIN student_fee_assignments sfa
                      ON sfa.institution_id = active_link.institution_id
                     AND sfa.student_id = active_link.student_id
                    WHERE active_link.institution_id = p.institution_id
                      AND active_link.parent_id = p.id AND active_link.status = 'active'
                      AND active_link.can_view_fees = 1
                      AND sfa.status IN ('active', 'completed', 'suspended')
                ), 0) AS linked_outstanding
         FROM parents p
         JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
         LEFT JOIN parent_student_relationships psr
           ON psr.institution_id = p.institution_id AND psr.parent_id = p.id
         WHERE p.institution_id = ? AND u.user_type = 'parent'
         GROUP BY p.id, u.id
         ORDER BY p.full_name LIMIT ?"
    );
    $statement->bind_param('ii', $institutionId, $limit);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}

function admin_panel_audit_logs(mysqli $db, int $institutionId, string $search = '', int $limit = 200): array
{
    $limit = min(max($limit, 1), 500);
    $search = trim($search);
    $pattern = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    $statement = $db->prepare(
        'SELECT al.id, al.action, al.entity_type, al.entity_id, al.actor_type,
                al.ip_address, al.created_at, u.full_name AS actor_name
         FROM audit_logs al
         LEFT JOIN users u ON u.id = al.actor_user_id AND u.institution_id = al.institution_id
         WHERE al.institution_id = ?
           AND (? = \'\' OR al.action LIKE ? ESCAPE \'!\' OR al.entity_type LIKE ? ESCAPE \'!\' OR al.entity_id LIKE ? ESCAPE \'!\' OR u.full_name LIKE ? ESCAPE \'!\')
         ORDER BY al.created_at DESC, al.id DESC LIMIT ?'
    );
    $statement->bind_param('isssssi', $institutionId, $search, $pattern, $pattern, $pattern, $pattern, $limit);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}

function admin_panel_simple_list(mysqli $db, int $institutionId, string $table): array
{
    $queries = [
        'courses' => 'SELECT id, name, code, status, created_at FROM courses WHERE institution_id = ? ORDER BY name',
        'batches' => 'SELECT b.id, b.name, b.code, b.status, c.name AS class_name, co.name AS course_name
                      FROM batches b JOIN classes c ON c.institution_id = b.institution_id AND c.id = b.class_id
                      JOIN courses co ON co.institution_id = b.institution_id AND co.id = b.course_id
                      WHERE b.institution_id = ? ORDER BY b.name',
        'classes' => 'SELECT id, name, code, status, created_at FROM classes WHERE institution_id = ? ORDER BY name',
        'academic_years' => 'SELECT id, name, start_date, end_date, status FROM academic_years WHERE institution_id = ? ORDER BY start_date DESC',
        'fee_categories' => 'SELECT id, name, code, is_mandatory, status, created_at FROM fee_categories WHERE institution_id = ? ORDER BY name',
        'installments' => 'SELECT i.id, s.full_name AS student_name, s.student_code, fs.name AS fee_structure,
                                  ay.name AS academic_year, i.installment_number, i.due_date,
                                  i.due_amount, i.amount_paid, i.penalty_amount, i.discount_amount,
                                  i.adjustment_amount, i.status
                           FROM installments i
                           JOIN student_fee_assignments a ON a.institution_id = i.institution_id AND a.id = i.student_fee_assignment_id
                           JOIN students s ON s.institution_id = a.institution_id AND s.id = a.student_id
                           JOIN fee_structures fs ON fs.institution_id = a.institution_id AND fs.id = a.fee_structure_id
                           JOIN academic_years ay ON ay.institution_id = a.institution_id AND ay.id = a.academic_year_id
                           WHERE i.institution_id = ? ORDER BY i.due_date DESC, i.id DESC LIMIT 500',
        'fee_adjustments' => 'SELECT fa.id, s.full_name AS student_name, fa.adjustment_type, fa.amount,
                                     fa.reason, fa.created_at, u.full_name AS approved_by
                              FROM fee_adjustments fa
                              JOIN student_fee_assignments a ON a.institution_id = fa.institution_id AND a.id = fa.student_fee_assignment_id
                              JOIN students s ON s.institution_id = a.institution_id AND s.id = a.student_id
                              LEFT JOIN users u ON u.id = fa.approved_by AND u.institution_id = fa.institution_id
                              WHERE fa.institution_id = ? ORDER BY fa.created_at DESC, fa.id DESC LIMIT 500',
        'fee_discounts' => 'SELECT fd.id, s.full_name AS student_name, fd.discount_type, fd.amount,
                                   fd.reason, fd.applied_at, u.full_name AS approved_by
                             FROM fee_discounts fd
                             JOIN student_fee_assignments a ON a.institution_id = fd.institution_id AND a.id = fd.student_fee_assignment_id
                             JOIN students s ON s.institution_id = a.institution_id AND s.id = a.student_id
                             LEFT JOIN users u ON u.id = fd.approved_by AND u.institution_id = fd.institution_id
                             WHERE fd.institution_id = ? ORDER BY fd.applied_at DESC, fd.id DESC LIMIT 500',
        'fee_structures' => 'SELECT fs.id, fs.name, fs.status, fs.total_amount, ay.name AS academic_year_name,
                            c.name AS class_name, co.name AS course_name
                            FROM fee_structures fs
                            JOIN academic_years ay ON ay.institution_id = fs.institution_id AND ay.id = fs.academic_year_id
                            LEFT JOIN classes c ON c.institution_id = fs.institution_id AND c.id = fs.class_id
                            LEFT JOIN courses co ON co.institution_id = fs.institution_id AND co.id = fs.course_id
                            WHERE fs.institution_id = ? ORDER BY ay.start_date DESC, fs.name',
        'payment_plans' => 'SELECT id, code, name, plan_type, installment_count, status FROM payment_plans WHERE institution_id = ? ORDER BY name',
        'announcements' => 'SELECT a.id, a.title, a.status, a.publish_at, a.expires_at, a.created_at, u.full_name AS creator_name,
                            (SELECT COUNT(*) FROM announcement_batches ab WHERE ab.institution_id = a.institution_id AND ab.announcement_id = a.id) AS target_batch_count
                            FROM announcements a LEFT JOIN users u ON u.id = a.created_by AND u.institution_id = a.institution_id
                            WHERE a.institution_id = ? ORDER BY a.created_at DESC, a.id DESC',
        'holidays' => 'SELECT id, name, start_date, end_date, is_recurring, status, description FROM holidays WHERE institution_id = ? ORDER BY start_date DESC',
        'examinations' => 'SELECT e.id, e.name, e.exam_date, e.start_time, e.end_time, b.name AS batch_name, c.name AS course_name
                           FROM examinations e
                           JOIN batches b ON b.institution_id = e.institution_id AND b.id = e.batch_id
                           LEFT JOIN courses c ON c.institution_id = e.institution_id AND c.id = e.course_id
                           WHERE e.institution_id = ? ORDER BY e.exam_date DESC',
        'timetables' => 'SELECT t.id, t.day_of_week, t.start_time, t.end_time, t.subject_name, t.instructor_name, t.room_number, b.name AS batch_name
                         FROM timetables t
                         JOIN batches b ON b.institution_id = t.institution_id AND b.id = t.batch_id
                         WHERE t.institution_id = ? ORDER BY FIELD(t.day_of_week, "monday", "tuesday", "wednesday", "thursday", "friday", "saturday", "sunday"), t.start_time',
    ];
    if (!isset($queries[$table])) {
        throw new InvalidArgumentException('The requested admin list is not supported.');
    }
    $statement = $db->prepare($queries[$table]);
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}

function admin_panel_validate_class(array $input): array
{
    $name = $input['name'] ?? null;
    $code = $input['code'] ?? null;
    $description = $input['description'] ?? null;
    $status = $input['status'] ?? null;
    if (!is_string($name) || !is_string($code) || !is_string($description)
        || !is_string($status)) {
        throw new InvalidArgumentException('The class form contains invalid values.');
    }

    $name = trim($name);
    $code = trim($code);
    $description = trim($description);
    if ($name === '' || mb_strlen($name, 'UTF-8') > 120
        || mb_strlen($code, 'UTF-8') > 50
        || mb_strlen($description, 'UTF-8') > 20000
        || preg_match('/[\x00-\x1F\x7F]/', $name . $code) === 1
        || !in_array($status, ['active', 'inactive'], true)) {
        throw new InvalidArgumentException('Enter a valid class name, code, description, and status.');
    }

    return [
        'name' => $name,
        'code' => $code === '' ? null : $code,
        'description' => $description === '' ? null : $description,
        'status' => $status,
    ];
}

function admin_panel_create_class(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    array $values
): int {
    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            'SELECT id FROM classes
             WHERE institution_id = ? AND (name = ? OR (? IS NOT NULL AND code = ?))
             LIMIT 1'
        );
        $statement->bind_param(
            'isss',
            $institutionId,
            $values['name'],
            $values['code'],
            $values['code']
        );
        $statement->execute();
        $duplicate = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (is_array($duplicate)) {
            throw new InvalidArgumentException('A class with this name or code already exists in this institution.');
        }

        $statement = $db->prepare(
            'INSERT INTO classes (institution_id, name, code, description, status)
             VALUES (?, ?, ?, ?, ?)'
        );
        $statement->bind_param(
            'issss',
            $institutionId,
            $values['name'],
            $values['code'],
            $values['description'],
            $values['status']
        );
        $statement->execute();
        $classId = (int) $statement->insert_id;
        $statement->close();

        $actor = current_user();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            is_array($actor) ? (string) $actor['user_type'] : 'admin',
            'class.created',
            'class',
            (string) $classId,
            null,
            $values
        );
        $db->commit();
        return $classId;
    } catch (Throwable $exception) {
        $db->rollback();
        if ($exception instanceof mysqli_sql_exception && (int) $exception->getCode() === 1062) {
            throw new InvalidArgumentException(
                'A class with this name or code already exists in this institution.',
                0,
                $exception
            );
        }
        throw $exception;
    }
}

function admin_panel_get_announcement_batches(mysqli $db, int $institutionId): array
{
    return admin_panel_simple_list($db, $institutionId, 'batches');
}

function admin_panel_save_announcement(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    string $title,
    string $message,
    string $status,
    ?string $publishAt,
    ?string $expiresAt,
    array $batchIds
): int {
    $title = trim($title);
    $message = trim($message);
    if ($title === '' || mb_strlen($title, 'UTF-8') > 220
        || preg_match('/[\x00-\x1F\x7F]/', $title) === 1
        || $message === '' || mb_strlen($message, 'UTF-8') > 20000
        || !in_array($status, ['draft', 'published'], true)) {
        throw new InvalidArgumentException('Enter a title, message and supported announcement status.');
    }
    $normalizedTimes = [];
    foreach ([$publishAt, $expiresAt] as $value) {
        if ($value === null || $value === '') {
            $normalizedTimes[] = null;
            continue;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
        if ($parsed === false || $parsed->format('Y-m-d\TH:i') !== $value) {
            throw new InvalidArgumentException('Enter valid publication and expiry dates.');
        }
        $normalizedTimes[] = $parsed->format('Y-m-d H:i:s');
    }
    [$publishDateTime, $expiryDateTime] = $normalizedTimes;
    if ($publishDateTime !== null && $expiryDateTime !== null && $expiryDateTime < $publishDateTime) {
        throw new InvalidArgumentException('The expiry date must be after the publication date.');
    }
    $batchIds = array_values(array_unique(array_map('intval', $batchIds)));
    if (count($batchIds) > 100 || in_array(0, $batchIds, true) || min($batchIds ?: [1]) < 1) {
        throw new InvalidArgumentException('The selected announcement targets are invalid.');
    }
    if ($batchIds !== []) {
        $placeholders = implode(',', array_fill(0, count($batchIds), '?'));
        $statement = $db->prepare(
            "SELECT COUNT(*) FROM batches WHERE institution_id = ? AND id IN ({$placeholders})"
        );
        $values = [$institutionId, ...$batchIds];
        admin_panel_bind_params($statement, str_repeat('i', count($values)), $values);
        $statement->execute();
        $validCount = (int) $statement->get_result()->fetch_row()[0];
        $statement->close();
        if ($validCount !== count($batchIds)) {
            throw new InvalidArgumentException('One or more selected batches are not in this institution.');
        }
    }

    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            'INSERT INTO announcements
                (institution_id, created_by, title, message, publish_at, expires_at, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->bind_param(
            'iisssss',
            $institutionId,
            $actorUserId,
            $title,
            $message,
            $publishDateTime,
            $expiryDateTime,
            $status
        );
        $statement->execute();
        $announcementId = (int) $statement->insert_id;
        $statement->close();

        if ($batchIds !== []) {
            $statement = $db->prepare(
                'INSERT INTO announcement_batches (institution_id, announcement_id, batch_id)
                 VALUES (?, ?, ?)'
            );
            foreach ($batchIds as $batchId) {
                $statement->bind_param('iii', $institutionId, $announcementId, $batchId);
                $statement->execute();
            }
            $statement->close();
        }
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            'admin',
            'announcement.created',
            'announcement',
            (string) $announcementId,
            null,
            ['status' => $status, 'target_batch_count' => count($batchIds)]
        );
        $db->commit();
        return $announcementId;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function admin_panel_notifications(mysqli $db, int $institutionId): array
{
    $statement = $db->prepare(
        'SELECT n.id, n.notification_type, n.title, n.channel, n.status, n.scheduled_at,
                n.sent_at, n.created_at, u.full_name AS recipient_name,
                u.email AS recipient_email, s.full_name AS student_name, s.student_code
         FROM notifications n
         JOIN users u ON u.institution_id = n.institution_id AND u.id = n.recipient_user_id
         LEFT JOIN students s ON s.institution_id = n.institution_id AND s.id = n.student_id
         WHERE n.institution_id = ?
         ORDER BY n.created_at DESC, n.id DESC LIMIT 200'
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}

function admin_panel_institution(mysqli $db, int $institutionId): ?array
{
    $statement = $db->prepare(
        'SELECT id, name, short_name, code, email, phone, address_line1, address_line2,
                logo_path,
                city, state, postal_code, country, website, status
         FROM institutions WHERE id = ? LIMIT 1'
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $institution = $statement->get_result()->fetch_assoc();
    $statement->close();
    return is_array($institution) ? $institution : null;
}

function admin_panel_validate_course(array $input): array
{
    $name = $input['name'] ?? null;
    $code = $input['code'] ?? null;
    $description = $input['description'] ?? null;
    $duration = $input['duration_months'] ?? null;
    $status = $input['status'] ?? 'active';
    if (!is_string($name) || !is_string($code) || !is_string($description)
        || !is_string($duration) || !is_string($status)) {
        throw new InvalidArgumentException('The course form contains invalid values.');
    }
    $name = trim($name);
    $code = trim($code);
    $description = trim($description);
    $duration = trim($duration);
    if ($name === '' || mb_strlen($name, 'UTF-8') > 150
        || mb_strlen($code, 'UTF-8') > 50
        || mb_strlen($description, 'UTF-8') > 20000
        || preg_match('/[\x00-\x1F\x7F]/', $name . $code) === 1
        || !in_array($status, ['active', 'inactive'], true)) {
        throw new InvalidArgumentException('Enter a valid course name, code, description, and status.');
    }
    $durationMonths = null;
    if ($duration !== '') {
        $parsed = filter_var($duration, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1200]]);
        if ($parsed === false) {
            throw new InvalidArgumentException('Course duration must be between 1 and 1200 months.');
        }
        $durationMonths = $parsed;
    }
    return [
        'name' => $name,
        'code' => $code === '' ? null : $code,
        'description' => $description === '' ? null : $description,
        'duration_months' => $durationMonths,
        'status' => $status,
    ];
}

function admin_panel_save_course(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    array $values,
    ?int $courseId = null
): int {
    $before = $courseId === null ? null : admin_panel_course($db, $institutionId, $courseId);
    if ($courseId !== null && $before === null) {
        throw new OutOfBoundsException('Course not found.');
    }
    $db->begin_transaction();
    try {
        if ($courseId === null) {
            $statement = $db->prepare(
                'INSERT INTO courses (institution_id, name, code, description, duration_months, status)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $statement->bind_param(
                'isssis',
                $institutionId,
                $values['name'],
                $values['code'],
                $values['description'],
                $values['duration_months'],
                $values['status']
            );
        } else {
            $statement = $db->prepare(
                'UPDATE courses SET name = ?, code = ?, description = ?, duration_months = ?, status = ?
                 WHERE institution_id = ? AND id = ?'
            );
            $statement->bind_param(
                'sssissi',
                $values['name'],
                $values['code'],
                $values['description'],
                $values['duration_months'],
                $values['status'],
                $institutionId,
                $courseId
            );
        }
        $statement->execute();
        $savedId = $courseId ?? (int) $statement->insert_id;
        $statement->close();
        $actor = current_user();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            is_array($actor) ? (string) $actor['user_type'] : 'admin',
            $courseId === null ? 'course.created' : 'course.updated',
            'course',
            (string) $savedId,
            $before,
            $values
        );
        $db->commit();
        return $savedId;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function admin_panel_course(mysqli $db, int $institutionId, int $courseId): ?array
{
    $statement = $db->prepare(
        'SELECT id, name, code, description, duration_months, status
         FROM courses WHERE institution_id = ? AND id = ? LIMIT 1'
    );
    $statement->bind_param('ii', $institutionId, $courseId);
    $statement->execute();
    $course = $statement->get_result()->fetch_assoc();
    $statement->close();
    return is_array($course) ? $course : null;
}

function admin_panel_validate_batch(array $input): array
{
    $name = $input['name'] ?? null;
    $code = $input['code'] ?? null;
    $courseId = filter_var($input['course_id'] ?? null, FILTER_VALIDATE_INT);
    $classId = filter_var($input['class_id'] ?? null, FILTER_VALIDATE_INT);
    $capacityValue = $input['capacity'] ?? '';
    $startTime = $input['start_time'] ?? '';
    $endTime = $input['end_time'] ?? '';
    $status = $input['status'] ?? 'active';
    if (!is_string($name) || !is_string($code) || !is_string($capacityValue)
        || !is_string($startTime) || !is_string($endTime) || !is_string($status)
        || $courseId === false || $classId === false || $courseId < 1 || $classId < 1) {
        throw new InvalidArgumentException('The batch form contains invalid values.');
    }
    $name = trim($name);
    $code = trim($code);
    $capacityValue = trim($capacityValue);
    if ($name === '' || mb_strlen($name, 'UTF-8') > 120
        || mb_strlen($code, 'UTF-8') > 50
        || preg_match('/[\x00-\x1F\x7F]/', $name . $code) === 1
        || !in_array($status, ['active', 'inactive', 'full'], true)) {
        throw new InvalidArgumentException('Enter a valid batch name, code, and status.');
    }
    $capacity = null;
    if ($capacityValue !== '') {
        $capacity = filter_var($capacityValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
        if ($capacity === false) {
            throw new InvalidArgumentException('Batch capacity must be a positive whole number.');
        }
    }
    foreach ([$startTime, $endTime] as $time) {
        if ($time !== '' && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $time) !== 1) {
            throw new InvalidArgumentException('Enter valid batch start and end times.');
        }
    }
    $normalizedStart = $startTime === '' ? '' : (strlen($startTime) === 5 ? $startTime . ':00' : $startTime);
    $normalizedEnd = $endTime === '' ? '' : (strlen($endTime) === 5 ? $endTime . ':00' : $endTime);
    if ($normalizedStart !== '' && $normalizedEnd !== '' && $normalizedEnd <= $normalizedStart) {
        throw new InvalidArgumentException('The batch end time must be later than its start time.');
    }
    return [
        'name' => $name,
        'code' => $code === '' ? null : $code,
        'course_id' => $courseId,
        'class_id' => $classId,
        'start_time' => $normalizedStart === '' ? null : $normalizedStart,
        'end_time' => $normalizedEnd === '' ? null : $normalizedEnd,
        'capacity' => $capacity,
        'status' => $status,
    ];
}

function admin_panel_batch(mysqli $db, int $institutionId, int $batchId): ?array
{
    $statement = $db->prepare(
        'SELECT id, name, code, course_id, class_id, start_time, end_time, capacity, status
         FROM batches WHERE institution_id = ? AND id = ? LIMIT 1'
    );
    $statement->bind_param('ii', $institutionId, $batchId);
    $statement->execute();
    $batch = $statement->get_result()->fetch_assoc();
    $statement->close();
    return is_array($batch) ? $batch : null;
}

function admin_panel_save_batch(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    array $values,
    ?int $batchId = null
): int {
    $before = $batchId === null ? null : admin_panel_batch($db, $institutionId, $batchId);
    if ($batchId !== null && $before === null) {
        throw new OutOfBoundsException('Batch not found.');
    }
    $statement = $db->prepare(
        'SELECT
            (SELECT COUNT(*) FROM courses WHERE institution_id = ? AND id = ?) AS course_count,
            (SELECT COUNT(*) FROM classes WHERE institution_id = ? AND id = ?) AS class_count'
    );
    $courseId = (int) $values['course_id'];
    $classId = (int) $values['class_id'];
    $statement->bind_param('iiii', $institutionId, $courseId, $institutionId, $classId);
    $statement->execute();
    $valid = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_array($valid) || (int) $valid['course_count'] !== 1 || (int) $valid['class_count'] !== 1) {
        throw new InvalidArgumentException('Select a course and class from this institution.');
    }
    $db->begin_transaction();
    try {
        if ($batchId === null) {
            $statement = $db->prepare(
                'INSERT INTO batches (institution_id, course_id, class_id, name, code, start_time, end_time, capacity, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->bind_param(
                'iiissssis',
                $institutionId,
                $values['course_id'],
                $values['class_id'],
                $values['name'],
                $values['code'],
                $values['start_time'],
                $values['end_time'],
                $values['capacity'],
                $values['status']
            );
        } else {
            $statement = $db->prepare(
                'UPDATE batches
                 SET course_id = ?, class_id = ?, name = ?, code = ?, start_time = ?, end_time = ?, capacity = ?, status = ?
                 WHERE institution_id = ? AND id = ?'
            );
            $statement->bind_param(
                'iissssisii',
                $values['course_id'],
                $values['class_id'],
                $values['name'],
                $values['code'],
                $values['start_time'],
                $values['end_time'],
                $values['capacity'],
                $values['status'],
                $institutionId,
                $batchId
            );
        }
        $statement->execute();
        $savedId = $batchId ?? (int) $statement->insert_id;
        $statement->close();
        $actor = current_user();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            is_array($actor) ? (string) $actor['user_type'] : 'admin',
            $batchId === null ? 'batch.created' : 'batch.updated',
            'batch',
            (string) $savedId,
            $before,
            $values
        );
        $db->commit();
        return $savedId;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function admin_panel_validate_fee_structure(array $input): array
    {
        $name = $input['name'] ?? null;
        $description = $input['description'] ?? '';
        $academicYearId = filter_var($input['academic_year_id'] ?? null, FILTER_VALIDATE_INT);
        $courseValue = $input['course_id'] ?? '';
        $classValue = $input['class_id'] ?? '';
        $courseId = $courseValue === '' ? null : filter_var($courseValue, FILTER_VALIDATE_INT);
        $classId = $classValue === '' ? null : filter_var($classValue, FILTER_VALIDATE_INT);
        $status = $input['status'] ?? 'draft';
        $rawItems = $input['items'] ?? null;
        $selectedCategories = $input['category_ids'] ?? [];
        $items = [];
        if (!is_array($selectedCategories) || !is_array($rawItems)) {
            throw new InvalidArgumentException('The fee structure items are invalid.');
        }
        if (is_array($selectedCategories) && is_array($rawItems)) {
            if (count($selectedCategories) > 100) {
                throw new InvalidArgumentException('Select no more than 100 fee categories.');
            }

            foreach ($selectedCategories as $rawCategoryId) {
                $categoryId = is_string($rawCategoryId) ? filter_var($rawCategoryId, FILTER_VALIDATE_INT) : false;
                if ($categoryId === false || $categoryId < 1 || !is_string($rawItems[(string) $categoryId] ?? null)) {
                    throw new InvalidArgumentException('The selected fee categories are invalid.');
                }
                $items[$categoryId] = $rawItems[(string) $categoryId];
            }
        }
        if (!is_string($name) || !is_string($description) || !is_string($status)
            || !is_array($rawItems) || $academicYearId === false || $academicYearId < 1
            || ($courseValue !== '' && ($courseId === false || $courseId < 1))
            || ($classValue !== '' && ($classId === false || $classId < 1))) {
            throw new InvalidArgumentException('The fee structure form contains invalid values.');
        }
        $name = trim($name);
        $description = trim($description);
        if ($name === '' || mb_strlen($name, 'UTF-8') > 150 || mb_strlen($description, 'UTF-8') > 20000
            || preg_match('/[\x00-\x1F\x7F]/', $name) === 1
            || !in_array($status, ['draft', 'active', 'inactive'], true)) {
            throw new InvalidArgumentException('Enter a valid fee structure name, description, and status.');
        }
        if ($items === [] || count($items) > 100) {
            throw new InvalidArgumentException('Select at least one fee category.');
        }
        $normalizedItems = [];
        $totalCents = 0;
        foreach ($items as $rawCategoryId => $rawAmount) {
            $categoryId = filter_var((string) $rawCategoryId, FILTER_VALIDATE_INT);
            if ($categoryId === false || $categoryId < 1 || !is_string($rawAmount)
                || preg_match('/^[0-9]{1,10}(?:\.[0-9]{1,2})?$/D', trim($rawAmount)) !== 1) {
                throw new InvalidArgumentException('Enter a valid positive amount for every selected fee category.');
            }
            $amountCents = admin_panel_decimal_cents(trim($rawAmount));
            if ($amountCents < 1 || $amountCents > 999999999999) {
                throw new InvalidArgumentException('Fee category amounts must be positive and within the supported range.');
            }
            $totalCents += $amountCents;
            if ($totalCents > 999999999999) {
                throw new InvalidArgumentException('The fee structure total exceeds the supported range.');
            }
            $normalizedItems[] = [
                'category_id' => $categoryId,
                'amount' => intdiv($amountCents, 100) . '.' . str_pad((string) ($amountCents % 100), 2, '0', STR_PAD_LEFT),
            ];
        }
        return [
            'name' => $name,
            'description' => $description === '' ? null : $description,
            'academic_year_id' => $academicYearId,
            'course_id' => $courseId,
            'class_id' => $classId,
            'status' => $status,
            'items' => $normalizedItems,
            'total_amount' => intdiv($totalCents, 100) . '.' . str_pad((string) ($totalCents % 100), 2, '0', STR_PAD_LEFT),
        ];
    }

    function admin_panel_fee_structure(mysqli $db, int $institutionId, int $structureId): ?array
    {
        $statement = $db->prepare(
            'SELECT id, name, description, academic_year_id, course_id, class_id, status
             FROM fee_structures WHERE institution_id = ? AND id = ? LIMIT 1'
        );
        $statement->bind_param('ii', $institutionId, $structureId);
        $statement->execute();
        $structure = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($structure)) {
            return null;
        }
        $statement = $db->prepare(
            'SELECT category_id, amount FROM fee_structure_items
             WHERE institution_id = ? AND fee_structure_id = ? ORDER BY sort_order, id'
        );
        $statement->bind_param('ii', $institutionId, $structureId);
        $statement->execute();
        $structure['items'] = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
        $statement->close();
        return $structure;
    }

    function admin_panel_save_fee_structure(
        mysqli $db,
        int $institutionId,
        int $actorUserId,
        array $values,
        ?int $structureId = null
    ): int {
        $db->begin_transaction();
        try {
            $before = null;
            if ($structureId !== null) {
                $statement = $db->prepare(
                    'SELECT id FROM fee_structures
                     WHERE institution_id = ? AND id = ? FOR UPDATE'
                );
                $statement->bind_param('ii', $institutionId, $structureId);
                $statement->execute();
                $exists = $statement->get_result()->fetch_assoc();
                $statement->close();
                if (!is_array($exists)) {
                    throw new OutOfBoundsException('Fee structure not found.');
                }
                $statement = $db->prepare(
                    'SELECT COUNT(*) FROM student_fee_assignments
                     WHERE institution_id = ? AND fee_structure_id = ?'
                );
                $statement->bind_param('ii', $institutionId, $structureId);
                $statement->execute();
                $assignmentCount = (int) $statement->get_result()->fetch_row()[0];
                $statement->close();
                if ($assignmentCount > 0) {
                    throw new DomainException('A fee structure with student assignments is immutable.');
                }
                $before = admin_panel_fee_structure($db, $institutionId, $structureId);
            }

            $referenceChecks = [
                ['academic_years', (int) $values['academic_year_id'], 'academic year'],
                ['courses', $values['course_id'] === null ? null : (int) $values['course_id'], 'course'],
                ['classes', $values['class_id'] === null ? null : (int) $values['class_id'], 'class'],
            ];
            foreach ($referenceChecks as [$table, $referenceId, $label]) {
                if ($referenceId === null) {
                    continue;
                }
                $statement = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE institution_id = ? AND id = ?");
                $statement->bind_param('ii', $institutionId, $referenceId);
                $statement->execute();
                $validReference = (int) $statement->get_result()->fetch_row()[0] === 1;
                $statement->close();
                if (!$validReference) {
                    throw new InvalidArgumentException('Select an ' . $label . ' from this institution.');
                }
            }
            $categoryIds = array_column($values['items'], 'category_id');
            $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
            $statement = $db->prepare(
                "SELECT COUNT(*) FROM fee_categories c
                 WHERE c.institution_id = ? AND c.id IN ({$placeholders})
                   AND (c.status = 'active' OR (? > 0 AND EXISTS (
                        SELECT 1 FROM fee_structure_items existing_item
                        WHERE existing_item.institution_id = c.institution_id
                          AND existing_item.fee_structure_id = ?
                          AND existing_item.category_id = c.id
                   )))"
            );
            $categoryValues = [$institutionId, ...$categoryIds, $structureId ?? 0, $structureId ?? 0];
            admin_panel_bind_params($statement, str_repeat('i', count($categoryValues)), $categoryValues);
            $statement->execute();
            $validCategories = (int) $statement->get_result()->fetch_row()[0];
            $statement->close();
            if ($validCategories !== count($categoryIds)) {
                throw new InvalidArgumentException('Select active fee categories from this institution.');
            }

            if ($structureId === null) {
                $statement = $db->prepare(
                    'INSERT INTO fee_structures
                        (institution_id, academic_year_id, course_id, class_id, name, description, total_amount, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $statement->bind_param(
                    'iiiissss',
                    $institutionId,
                    $values['academic_year_id'],
                    $values['course_id'],
                    $values['class_id'],
                    $values['name'],
                    $values['description'],
                    $values['total_amount'],
                    $values['status']
                );
            } else {
                $statement = $db->prepare(
                    'UPDATE fee_structures SET academic_year_id = ?, course_id = ?, class_id = ?,
                        name = ?, description = ?, total_amount = ?, status = ?
                     WHERE institution_id = ? AND id = ?'
                );
                $statement->bind_param(
                    'iiissssii',
                    $values['academic_year_id'],
                    $values['course_id'],
                    $values['class_id'],
                    $values['name'],
                    $values['description'],
                    $values['total_amount'],
                    $values['status'],
                    $institutionId,
                    $structureId
                );
            }
            $statement->execute();
            $savedId = $structureId ?? (int) $statement->insert_id;
            $statement->close();
            if ($structureId !== null) {
                $statement = $db->prepare(
                    'DELETE FROM fee_structure_items WHERE institution_id = ? AND fee_structure_id = ?'
                );
                $statement->bind_param('ii', $institutionId, $structureId);
                $statement->execute();
                $statement->close();
            }
            $statement = $db->prepare(
                'INSERT INTO fee_structure_items
                    (institution_id, fee_structure_id, category_id, amount, sort_order)
                 VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($values['items'] as $sortOrder => $item) {
                $categoryId = (int) $item['category_id'];
                $amount = (string) $item['amount'];
                $order = (int) $sortOrder;
                $statement->bind_param('iiisi', $institutionId, $savedId, $categoryId, $amount, $order);
                $statement->execute();
            }
            $statement->close();
            $actor = current_user();
            write_audit_log(
                $db,
                $institutionId,
                $actorUserId,
                is_array($actor) ? (string) $actor['user_type'] : 'admin',
                $structureId === null ? 'fee_structure.created' : 'fee_structure.updated',
                'fee_structure',
                (string) $savedId,
                $before,
                $values
            );
            $db->commit();
            return $savedId;
        } catch (Throwable $exception) {
            $db->rollback();
            throw $exception;
        }
    }
function admin_panel_validate_fee_category(array $input): array
{
    $name = $input['name'] ?? null;
    $code = $input['code'] ?? '';
    $description = $input['description'] ?? '';
    $mandatory = $input['is_mandatory'] ?? '0';
    if (!is_string($name) || !is_string($code) || !is_string($description)
        || !is_string($mandatory) || !in_array($mandatory, ['0', '1'], true)) {
        throw new InvalidArgumentException('The fee category form contains invalid values.');
    }
    $name = trim($name);
    $code = trim($code);
    $description = trim($description);
    if ($name === '' || mb_strlen($name, 'UTF-8') > 150 || mb_strlen($code, 'UTF-8') > 50
        || mb_strlen($description, 'UTF-8') > 20000
        || preg_match('/[\x00-\x1F\x7F]/', $name . $code) === 1) {
        throw new InvalidArgumentException('Enter a valid fee category name and code.');
    }
    return [
        'name' => $name,
        'code' => $code === '' ? null : $code,
        'description' => $description === '' ? null : $description,
        'is_mandatory' => (int) $mandatory,
    ];
}

function admin_panel_create_fee_category(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    array $values
): int {
    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            "INSERT INTO fee_categories (institution_id, name, code, description, is_mandatory, status)
             VALUES (?, ?, ?, ?, ?, 'active')"
        );
        $statement->bind_param(
            'isssi',
            $institutionId,
            $values['name'],
            $values['code'],
            $values['description'],
            $values['is_mandatory']
        );
        $statement->execute();
        $categoryId = (int) $statement->insert_id;
        $statement->close();
        $actor = current_user();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            is_array($actor) ? (string) $actor['user_type'] : 'admin',
            'fee_category.created',
            'fee_category',
            (string) $categoryId,
            null,
            $values
        );
        $db->commit();
        return $categoryId;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function admin_panel_set_fee_category_status(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    int $categoryId,
    string $status
): void {
    if ($categoryId < 1 || !in_array($status, ['active', 'inactive'], true)) {
        throw new InvalidArgumentException('The fee category action is invalid.');
    }
    $statement = $db->prepare(
        'SELECT status FROM fee_categories WHERE institution_id = ? AND id = ? LIMIT 1'
    );
    $statement->bind_param('ii', $institutionId, $categoryId);
    $statement->execute();
    $before = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_array($before)) {
        throw new OutOfBoundsException('Fee category not found.');
    }
    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            'UPDATE fee_categories SET status = ? WHERE institution_id = ? AND id = ?'
        );
        $statement->bind_param('sii', $status, $institutionId, $categoryId);
        $statement->execute();
        $statement->close();
        $actor = current_user();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            is_array($actor) ? (string) $actor['user_type'] : 'admin',
            'fee_category.status_changed',
            'fee_category',
            (string) $categoryId,
            $before,
            ['status' => $status]
        );
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function admin_panel_validate_academic_year(array $input): array
{
    $name = $input['name'] ?? null;
    $startDate = $input['start_date'] ?? null;
    $endDate = $input['end_date'] ?? null;
    if (!is_string($name) || !is_string($startDate) || !is_string($endDate)) {
        throw new InvalidArgumentException('The academic-year form contains invalid values.');
    }
    $name = trim($name);
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate);
    if ($name === '' || mb_strlen($name, 'UTF-8') > 120
        || preg_match('/[\x00-\x1F\x7F]/', $name) === 1
        || $start === false || $start->format('Y-m-d') !== $startDate
        || $end === false || $end->format('Y-m-d') !== $endDate
        || $endDate < $startDate) {
        throw new InvalidArgumentException('Enter a valid academic-year name and date range.');
    }
    return ['name' => $name, 'start_date' => $startDate, 'end_date' => $endDate];
}

function admin_panel_create_academic_year(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    array $values
): int {
    $db->begin_transaction();
    try {
        $status = 'draft';
        $statement = $db->prepare(
            'INSERT INTO academic_years (institution_id, name, start_date, end_date, status)
             VALUES (?, ?, ?, ?, ?)'
        );
        $statement->bind_param(
            'issss',
            $institutionId,
            $values['name'],
            $values['start_date'],
            $values['end_date'],
            $status
        );
        $statement->execute();
        $yearId = (int) $statement->insert_id;
        $statement->close();
        $actor = current_user();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            is_array($actor) ? (string) $actor['user_type'] : 'admin',
            'academic_year.created',
            'academic_year',
            (string) $yearId,
            null,
            [...$values, 'status' => $status]
        );
        $db->commit();
        return $yearId;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function admin_panel_set_academic_year_status(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    int $yearId,
    string $status
): void {
    if ($yearId < 1 || !in_array($status, ['active', 'closed'], true)) {
        throw new InvalidArgumentException('The academic-year action is invalid.');
    }
    $statement = $db->prepare(
        'SELECT status FROM academic_years WHERE institution_id = ? AND id = ? LIMIT 1'
    );
    $statement->bind_param('ii', $institutionId, $yearId);
    $statement->execute();
    $before = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_array($before)) {
        throw new OutOfBoundsException('Academic year not found.');
    }
    if ($before['status'] === 'closed' || ($before['status'] === 'active' && $status === 'active')) {
        throw new DomainException('This academic-year status transition is not allowed.');
    }
    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            'UPDATE academic_years SET status = ? WHERE institution_id = ? AND id = ?'
        );
        $statement->bind_param('sii', $status, $institutionId, $yearId);
        $statement->execute();
        $statement->close();
        $actor = current_user();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            is_array($actor) ? (string) $actor['user_type'] : 'admin',
            'academic_year.status_changed',
            'academic_year',
            (string) $yearId,
            $before,
            ['status' => $status]
        );
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function admin_panel_validate_institution(array $input): array
{
    $lengths = [
        'name' => 255,
        'short_name' => 80,
        'code' => 50,
        'email' => 255,
        'phone' => 30,
        'address_line1' => 255,
        'address_line2' => 255,
        'city' => 120,
        'state' => 120,
        'postal_code' => 20,
        'country' => 120,
        'website' => 255,
    ];
    $values = [];
    foreach ($lengths as $field => $maxLength) {
        $value = $input[$field] ?? '';
        if (!is_string($value)) {
            throw new InvalidArgumentException('The institution profile contains invalid values.');
        }
        $value = trim($value);
        if (mb_strlen($value, 'UTF-8') > $maxLength || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('One or more institution fields are too long or invalid.');
        }
        $values[$field] = $value === '' && !in_array($field, ['name', 'country'], true) ? null : $value;
    }
    if ($values['name'] === null) {
        throw new InvalidArgumentException('Enter an institution name.');
    }
    if ($values['country'] === null) {
        throw new InvalidArgumentException('Enter the institution country.');
    }
    if ($values['email'] !== null && filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('Enter a valid institution email address.');
    }
    if ($values['website'] !== null
        && (filter_var($values['website'], FILTER_VALIDATE_URL) === false
            || !in_array(strtolower((string) parse_url($values['website'], PHP_URL_SCHEME)), ['http', 'https'], true))) {
        throw new InvalidArgumentException('Enter a valid institution website using HTTP or HTTPS.');
    }
    return $values;
}

function admin_panel_update_institution(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    array $values
): void {
    $before = admin_panel_institution($db, $institutionId);
    if ($before === null) {
        throw new OutOfBoundsException('Institution not found.');
    }
    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            'UPDATE institutions
             SET name = ?, short_name = ?, code = ?, email = ?, phone = ?,
                 address_line1 = ?, address_line2 = ?, city = ?, state = ?,
                 postal_code = ?, country = ?, website = ?
             WHERE id = ?'
        );
        $statement->bind_param(
            'ssssssssssssi',
            $values['name'],
            $values['short_name'],
            $values['code'],
            $values['email'],
            $values['phone'],
            $values['address_line1'],
            $values['address_line2'],
            $values['city'],
            $values['state'],
            $values['postal_code'],
            $values['country'],
            $values['website'],
            $institutionId
        );
        $statement->execute();
        $statement->close();
        $actor = current_user();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            is_array($actor) ? (string) $actor['user_type'] : 'admin',
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

function admin_panel_staff_accounts(mysqli $db, int $institutionId): array
{
    $statement = $db->prepare(
        "SELECT u.id, u.full_name, u.email, u.mobile, u.user_type, u.status, u.last_login_at,
                u.created_at, r.name AS role_name, sp.employee_code, sp.designation, sp.department, sp.phone
         FROM users u
         LEFT JOIN roles r ON r.id = u.role_id
         LEFT JOIN staff_profiles sp ON sp.institution_id = u.institution_id AND sp.user_id = u.id
         WHERE u.institution_id = ? AND u.user_type IN ('admin', 'staff', 'super_admin')
         ORDER BY FIELD(u.user_type, 'super_admin', 'admin', 'staff'), u.full_name"
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}

function admin_panel_set_staff_status(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    int $targetUserId,
    string $status
): void {
    if ($targetUserId < 1 || $targetUserId === $actorUserId || !in_array($status, ['active', 'inactive'], true)) {
        throw new InvalidArgumentException('This staff account cannot be changed.');
    }

    $statement = $db->prepare(
        "SELECT id, full_name, status, email_verified_at
         FROM users WHERE id = ? AND institution_id = ? AND user_type = 'staff'
         LIMIT 1"
    );
    $statement->bind_param('ii', $targetUserId, $institutionId);
    $statement->execute();
    $target = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_array($target)) {
        throw new OutOfBoundsException('Staff account not found.');
    }
    if ($status === 'active' && $target['email_verified_at'] === null) {
        throw new InvalidArgumentException('The staff account email must be verified before activation.');
    }
    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            "UPDATE users SET status = ?
             WHERE id = ? AND institution_id = ? AND user_type = 'staff'"
        );
        $statement->bind_param('sii', $status, $targetUserId, $institutionId);
        $statement->execute();
        $statement->close();
        if ($status !== 'active') {
            $statement = $db->prepare(
                'UPDATE user_sessions SET revoked_at = CURRENT_TIMESTAMP
                 WHERE user_id = ? AND institution_id = ? AND revoked_at IS NULL'
            );
            $statement->bind_param('ii', $targetUserId, $institutionId);
            $statement->execute();
            $statement->close();
        }
        $actor = current_user();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            is_array($actor) ? (string) $actor['user_type'] : 'admin',
            'staff.status_changed',
            'user',
            (string) $targetUserId,
            ['status' => $target['status']],
            ['status' => $status]
        );
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function admin_panel_set_announcement_status(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    int $announcementId,
    string $status
): void {
    if ($announcementId < 1 || !in_array($status, ['draft', 'published'], true)) {
        throw new InvalidArgumentException('The announcement status is invalid.');
    }
    $statement = $db->prepare(
        'SELECT status FROM announcements WHERE institution_id = ? AND id = ? LIMIT 1'
    );
    $statement->bind_param('ii', $institutionId, $announcementId);
    $statement->execute();
    $record = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_array($record)) {
        throw new OutOfBoundsException('Announcement not found.');
    }
    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            'UPDATE announcements SET status = ? WHERE institution_id = ? AND id = ?'
        );
        $statement->bind_param('sii', $status, $institutionId, $announcementId);
        $statement->execute();
        $statement->close();
        $actor = current_user();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            is_array($actor) ? (string) $actor['user_type'] : 'admin',
            'announcement.status_changed',
            'announcement',
            (string) $announcementId,
            ['status' => $record['status']],
            ['status' => $status]
        );
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}
