<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

function notification_service_type_label(string $notificationType): string
{
    return match ($notificationType) {
        'fee_reminder' => 'Fee reminder',
        'payment_success' => 'Payment confirmation',
        'payment_failed' => 'Payment failed',
        'payment_pending' => 'Payment pending',
        'class_update' => 'Class update',
        'exam_reminder' => 'Examination update',
        'holiday_update' => 'Holiday update',
        'announcement' => 'Announcement',
        'admission_update' => 'Admission update',
        'refund_update' => 'Refund update',
        'system' => 'System',
        default => 'Other',
    };
}

function notification_service_user_access_sql(): string
{
    return "n.institution_id = ? AND n.recipient_user_id = ? AND n.channel = 'portal'
        AND n.status <> 'failed'
        AND EXISTS (
            SELECT 1
            FROM parents recipient_parent
            JOIN users recipient_user
              ON recipient_user.institution_id = recipient_parent.institution_id
             AND recipient_user.id = recipient_parent.user_id
            WHERE recipient_parent.institution_id = n.institution_id
              AND recipient_parent.id = ?
              AND recipient_parent.user_id = n.recipient_user_id
              AND recipient_parent.status = 'active'
              AND recipient_user.user_type = 'parent'
              AND recipient_user.status = 'active'
              AND recipient_user.email_verified_at IS NOT NULL
        )
        AND (
            n.student_id IS NULL OR EXISTS (
                SELECT 1
                FROM parent_student_relationships psr
                WHERE psr.institution_id = n.institution_id
                  AND psr.student_id = n.student_id
                  AND psr.parent_id = ?
                  AND psr.status = 'active'
                  AND (
                      (n.notification_type IN
                          ('fee_reminder','payment_success','payment_failed','payment_pending','refund_update')
                       AND psr.can_view_fees = 1)
                      OR (n.notification_type IN ('class_update','exam_reminder','holiday_update','announcement')
                          AND psr.can_view_academics = 1)
                      OR n.notification_type NOT IN
                          ('fee_reminder','payment_success','payment_failed','payment_pending',
                           'refund_update','class_update','exam_reminder','holiday_update','announcement')
                  )
            )
        )
        AND (
            n.notification_type <> 'announcement'
            OR COALESCE(n.related_entity_type, '') <> 'announcement'
            OR EXISTS (
                SELECT 1
                FROM announcements a
                WHERE a.institution_id = n.institution_id
                  AND a.id = n.related_entity_id
                  AND a.status = 'published'
                  AND (a.publish_at IS NULL OR a.publish_at <= CURRENT_TIMESTAMP)
                  AND (a.expires_at IS NULL OR a.expires_at >= CURRENT_TIMESTAMP)
                  AND n.dedupe_key = CONCAT(
                      'announcement:', a.id, ':', DATE_FORMAT(a.updated_at, '%Y-%m-%d %H:%i:%s')
                  )
                  AND (
                      NOT EXISTS (
                          SELECT 1 FROM announcement_batches ab
                          WHERE ab.institution_id = a.institution_id
                            AND ab.announcement_id = a.id
                      )
                      OR EXISTS (
                          SELECT 1
                          FROM announcement_batches ab
                          JOIN batches b
                            ON b.institution_id = ab.institution_id AND b.id = ab.batch_id
                          JOIN students s
                            ON s.institution_id = b.institution_id AND s.batch_id = b.id
                          JOIN parent_student_relationships psr
                            ON psr.institution_id = s.institution_id AND psr.student_id = s.id
                          WHERE ab.institution_id = a.institution_id
                            AND ab.announcement_id = a.id
                            AND psr.parent_id = ?
                            AND psr.status = 'active'
                            AND psr.can_view_academics = 1
                      )
                  )
            )
        )";
}

function notification_service_bind_parent_scope(
    mysqli_stmt $statement,
    int $institutionId,
    int $userId,
    int $parentId,
    ?int $notificationId = null
): void {
    if ($notificationId === null) {
        $statement->bind_param('iiiii', $institutionId, $userId, $parentId, $parentId, $parentId);
    } else {
        $statement->bind_param(
            'iiiiii',
            $institutionId,
            $userId,
            $parentId,
            $parentId,
            $parentId,
            $notificationId
        );
    }
}

function notification_service_get_parent_notifications(
    mysqli $db,
    int $parentId,
    int $institutionId,
    int $userId,
    int $page = 1,
    int $pageSize = 20
): array {
    $page = max(1, $page);
    $pageSize = min(max(1, $pageSize), 100);
    $offset = ($page - 1) * $pageSize;
    $accessSql = notification_service_user_access_sql();

    $statement = $db->prepare(
        "SELECT COUNT(*)
         FROM notifications n
         WHERE {$accessSql}"
    );
    notification_service_bind_parent_scope($statement, $institutionId, $userId, $parentId);
    $statement->execute();
    $total = (int) $statement->get_result()->fetch_row()[0];
    $statement->close();

    $statement = $db->prepare(
        "SELECT n.id, n.notification_type, n.title, n.message, n.status, n.created_at,
                n.read_at, n.student_id, s.full_name AS student_name
         FROM notifications n
         LEFT JOIN students s
           ON s.institution_id = n.institution_id AND s.id = n.student_id
         WHERE {$accessSql}
         ORDER BY n.created_at DESC, n.id DESC
         LIMIT ? OFFSET ?"
    );
    $statement->bind_param(
        'iiiiiii',
        $institutionId,
        $userId,
        $parentId,
        $parentId,
        $parentId,
        $pageSize,
        $offset
    );
    $statement->execute();
    $notifications = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();

    return [
        'items' => $notifications,
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'page_count' => max(1, (int) ceil($total / $pageSize)),
    ];
}

function notification_service_unread_count(
    mysqli $db,
    int $parentId,
    int $institutionId,
    int $userId
): int {
    $accessSql = notification_service_user_access_sql();
    $statement = $db->prepare(
        "SELECT COUNT(*)
         FROM notifications n
         WHERE {$accessSql}
           AND n.status <> 'read' AND n.read_at IS NULL"
    );
    notification_service_bind_parent_scope($statement, $institutionId, $userId, $parentId);
    $statement->execute();
    $count = (int) $statement->get_result()->fetch_row()[0];
    $statement->close();
    return $count;
}

function notification_service_unread_count_for_user(
    mysqli $db,
    int $institutionId,
    int $userId
): int {
    $statement = $db->prepare(
        "SELECT p.id AS parent_id
         FROM parents p
         JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
         JOIN roles r ON r.id = u.role_id AND r.name = 'parent'
         JOIN institutions i ON i.id = p.institution_id AND i.status = 'active'
         WHERE p.institution_id = ? AND p.user_id = ?
           AND p.status = 'active' AND u.user_type = 'parent'
           AND u.status = 'active' AND u.email_verified_at IS NOT NULL
         LIMIT 1"
    );
    $statement->bind_param('ii', $institutionId, $userId);
    $statement->execute();
    $parent = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_array($parent)) {
        return 0;
    }

    return notification_service_unread_count(
        $db,
        (int) $parent['parent_id'],
        $institutionId,
        $userId
    );
}

function notification_service_mark_parent_notification_read(
    mysqli $db,
    int $parentId,
    int $institutionId,
    int $userId,
    int $notificationId
): bool {
    if ($notificationId < 1) {
        return false;
    }
    $accessSql = notification_service_user_access_sql();
    $statement = $db->prepare(
        "UPDATE notifications n
         SET n.status = 'read', n.read_at = COALESCE(n.read_at, CURRENT_TIMESTAMP)
         WHERE {$accessSql} AND n.id = ?"
    );
    notification_service_bind_parent_scope($statement, $institutionId, $userId, $parentId, $notificationId);
    $statement->execute();
    $statement->close();

    $statement = $db->prepare(
        "SELECT 1 FROM notifications n
         WHERE {$accessSql} AND n.id = ? LIMIT 1"
    );
    notification_service_bind_parent_scope($statement, $institutionId, $userId, $parentId, $notificationId);
    $statement->execute();
    $exists = $statement->get_result()->num_rows === 1;
    $statement->close();
    return $exists;
}

function notification_service_mark_all_parent_notifications_read(
    mysqli $db,
    int $parentId,
    int $institutionId,
    int $userId
): int {
    $accessSql = notification_service_user_access_sql();
    $statement = $db->prepare(
        "UPDATE notifications n
         SET n.status = 'read', n.read_at = COALESCE(n.read_at, CURRENT_TIMESTAMP)
         WHERE {$accessSql} AND n.status <> 'read' AND n.read_at IS NULL"
    );
    notification_service_bind_parent_scope($statement, $institutionId, $userId, $parentId);
    $statement->execute();
    $updated = $statement->affected_rows;
    $statement->close();
    return $updated;
}

function notification_service_create_once(
    mysqli $db,
    int $institutionId,
    int $recipientUserId,
    ?int $studentId,
    string $notificationType,
    string $title,
    string $message,
    string $relatedEntityType,
    int $relatedEntityId,
    string $dedupeKey
): bool {
    $allowedTypes = [
        'fee_reminder',
        'payment_success',
        'payment_failed',
        'payment_pending',
        'class_update',
        'exam_reminder',
        'holiday_update',
        'announcement',
        'admission_update',
        'refund_update',
        'system',
        'other',
    ];
    if ($institutionId < 1 || $recipientUserId < 1 || ($studentId !== null && $studentId < 1)
        || !in_array($notificationType, $allowedTypes, true)
        || trim($title) === '' || mb_strlen($title, 'UTF-8') > 220
        || trim($message) === '' || mb_strlen($message, 'UTF-8') > 65535
        || $relatedEntityType === '' || mb_strlen($relatedEntityType, 'UTF-8') > 80
        || $relatedEntityId < 1 || $dedupeKey === '' || mb_strlen($dedupeKey, 'UTF-8') > 191) {
        throw new InvalidArgumentException('The notification data is invalid.');
    }

    $statement = $db->prepare(
        "INSERT INTO notifications
            (institution_id, recipient_user_id, student_id, notification_type, title, message,
             channel, status, related_entity_type, related_entity_id, dedupe_key, sent_at)
         VALUES (?, ?, ?, ?, ?, ?, 'portal', 'sent', ?, ?, ?, CURRENT_TIMESTAMP)
         ON DUPLICATE KEY UPDATE id = id"
    );
    $statement->bind_param(
        'iiissssis',
        $institutionId,
        $recipientUserId,
        $studentId,
        $notificationType,
        $title,
        $message,
        $relatedEntityType,
        $relatedEntityId,
        $dedupeKey
    );
    $statement->execute();
    $inserted = $statement->affected_rows === 1;
    $statement->close();
    return $inserted;
}

function notification_service_generation_cursor(mysqli $db, string $key): string
{
    $statement = $db->prepare(
        'SELECT last_processed_at FROM notification_generation_cursors
         WHERE cursor_key = ? LIMIT 1'
    );
    $statement->bind_param('s', $key);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_array($row) || !is_string($row['last_processed_at'] ?? null)) {
        throw new RuntimeException('A notification generation cursor is missing.');
    }
    return $row['last_processed_at'];
}

function notification_service_advance_generation_cursor(
    mysqli $db,
    string $key,
    string $runStartedAt
): void {
    $statement = $db->prepare(
        'UPDATE notification_generation_cursors
         SET last_processed_at = GREATEST(last_processed_at, ?)
         WHERE cursor_key = ?'
    );
    $statement->bind_param('ss', $runStartedAt, $key);
    $statement->execute();
    $statement->close();
}

function notification_service_generate_payment_notifications(mysqli $db): int
{
    $db->begin_transaction();
    try {
        $runStartedAt = (string) $db->query('SELECT CURRENT_TIMESTAMP')->fetch_row()[0];
        $cursor = notification_service_generation_cursor($db, 'payment_events');
        $statement = $db->prepare(
            "SELECT pay.institution_id, pay.id AS payment_id, pay.parent_id,
                    pay.student_id, pay.amount, pay.currency, s.full_name AS student_name,
                    u.id AS recipient_user_id
             FROM payments pay
             JOIN parents p
               ON p.institution_id = pay.institution_id AND p.id = pay.parent_id
             JOIN users u
               ON u.institution_id = p.institution_id AND u.id = p.user_id
             JOIN students s
               ON s.institution_id = pay.institution_id AND s.id = pay.student_id
             WHERE pay.updated_at >= ?
               AND pay.status = 'success' AND pay.verified_at IS NOT NULL
               AND p.status = 'active' AND u.user_type = 'parent'
               AND u.status = 'active' AND u.email_verified_at IS NOT NULL
               AND EXISTS (
                   SELECT 1 FROM parent_student_relationships psr
                   WHERE psr.institution_id = pay.institution_id
                     AND psr.parent_id = pay.parent_id
                     AND psr.student_id = pay.student_id
                     AND psr.status = 'active' AND psr.can_view_fees = 1
               )
             ORDER BY pay.updated_at, pay.id"
        );
        $statement->bind_param('s', $cursor);
        $statement->execute();
        $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
        $statement->close();

        $inserted = 0;
        foreach ($rows as $payment) {
            $studentName = (string) $payment['student_name'];
            $inserted += notification_service_create_once(
                $db,
                (int) $payment['institution_id'],
                (int) $payment['recipient_user_id'],
                (int) $payment['student_id'],
                'payment_success',
                'Payment confirmed',
                'Your payment of ' . (string) $payment['currency'] . ' '
                    . (string) $payment['amount'] . ' has been verified for ' . $studentName . '.',
                'payment',
                (int) $payment['payment_id'],
                'payment:' . (int) $payment['payment_id'] . ':success'
            ) ? 1 : 0;
        }
        notification_service_advance_generation_cursor($db, 'payment_events', $runStartedAt);

        $attemptCursor = notification_service_generation_cursor($db, 'payment_attempt_events');
        $statement = $db->prepare(
            "SELECT po.institution_id, po.id AS order_id, po.parent_id, po.student_id,
                    po.amount, po.currency, pa.id AS attempt_id, pa.status,
                    s.full_name AS student_name, u.id AS recipient_user_id
             FROM payment_attempts pa
             JOIN payment_orders po
               ON po.institution_id = pa.institution_id AND po.id = pa.payment_order_id
             JOIN parents p
               ON p.institution_id = po.institution_id AND p.id = po.parent_id
             JOIN users u
               ON u.institution_id = p.institution_id AND u.id = p.user_id
             JOIN students s
               ON s.institution_id = po.institution_id AND s.id = po.student_id
             WHERE pa.updated_at >= ? AND pa.status IN ('pending','failed')
               AND p.status = 'active' AND u.user_type = 'parent'
               AND u.status = 'active' AND u.email_verified_at IS NOT NULL
               AND EXISTS (
                   SELECT 1 FROM parent_student_relationships psr
                   WHERE psr.institution_id = po.institution_id
                     AND psr.parent_id = po.parent_id
                     AND psr.student_id = po.student_id
                     AND psr.status = 'active' AND psr.can_view_fees = 1
               )
             ORDER BY pa.updated_at, pa.id"
        );
        $statement->bind_param('s', $attemptCursor);
        $statement->execute();
        $attempts = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
        $statement->close();
        foreach ($attempts as $attempt) {
            $status = (string) $attempt['status'];
            $isFailed = $status === 'failed';
            $type = $isFailed ? 'payment_failed' : 'payment_pending';
            $title = $isFailed ? 'Payment was not completed' : 'Payment pending';
            $message = $isFailed
                ? 'The payment of ' . (string) $attempt['currency'] . ' '
                    . (string) $attempt['amount'] . ' for ' . (string) $attempt['student_name']
                    . ' was not completed. Check your payment history before retrying.'
                : 'The payment of ' . (string) $attempt['currency'] . ' '
                    . (string) $attempt['amount'] . ' for ' . (string) $attempt['student_name']
                    . ' is awaiting confirmation.';
            $inserted += notification_service_create_once(
                $db,
                (int) $attempt['institution_id'],
                (int) $attempt['recipient_user_id'],
                (int) $attempt['student_id'],
                $type,
                $title,
                $message,
                'payment_attempt',
                (int) $attempt['attempt_id'],
                'payment_attempt:' . (int) $attempt['attempt_id'] . ':' . $status
            ) ? 1 : 0;
        }
        notification_service_advance_generation_cursor(
            $db,
            'payment_attempt_events',
            $runStartedAt
        );
        $db->commit();
        return $inserted;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function notification_service_generate_due_reminders(mysqli $db, DateTimeImmutable $today): int
{
    $today = $today->setTime(0, 0);
    $todayValue = $today->format('Y-m-d');
    $upcomingEnd = $today->modify('+7 days')->format('Y-m-d');
    $statement = $db->prepare(
        "SELECT i.institution_id, i.id AS installment_id, i.installment_number, i.due_date,
                s.id AS student_id, s.full_name AS student_name,
                p.user_id AS recipient_user_id, p.id AS parent_id,
                CASE WHEN i.due_date < ? THEN 'overdue' ELSE 'upcoming' END AS reminder_kind
         FROM installments i
         JOIN student_fee_assignments a
           ON a.institution_id = i.institution_id AND a.id = i.student_fee_assignment_id
         JOIN students s
           ON s.institution_id = a.institution_id AND s.id = a.student_id
         JOIN parent_student_relationships psr
           ON psr.institution_id = s.institution_id AND psr.student_id = s.id
         JOIN parents p
           ON p.institution_id = psr.institution_id AND p.id = psr.parent_id
         JOIN users u
           ON u.institution_id = p.institution_id AND u.id = p.user_id
         WHERE i.status IN ('pending','partial','overdue')
           AND i.due_amount + i.penalty_amount + i.adjustment_amount
               - i.discount_amount - i.amount_paid > 0
           AND psr.status = 'active' AND psr.can_view_fees = 1
           AND p.status = 'active' AND u.user_type = 'parent'
           AND u.status = 'active' AND u.email_verified_at IS NOT NULL
           AND (
               i.due_date < ?
               OR (i.due_date >= ? AND i.due_date <= ?)
           )
         ORDER BY i.due_date, i.id, p.user_id"
    );
    $statement->bind_param('ssss', $todayValue, $todayValue, $todayValue, $upcomingEnd);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();

    $inserted = 0;
    foreach ($rows as $installment) {
        $isOverdue = $installment['reminder_kind'] === 'overdue';
        $type = 'fee_reminder';
        $title = $isOverdue ? 'Overdue installment reminder' : 'Upcoming installment reminder';
        $message = $isOverdue
            ? 'Installment ' . (int) $installment['installment_number'] . ' for '
                . (string) $installment['student_name'] . ' was due on '
                . (string) $installment['due_date'] . ' and remains unpaid. Please review the current balance in Fees.'
            : 'Installment ' . (int) $installment['installment_number'] . ' for '
                . (string) $installment['student_name'] . ' is due on '
                . (string) $installment['due_date'] . '. Please review the current balance in Fees.';
        $kind = $isOverdue ? 'overdue' : 'upcoming_7_day';
        $inserted += notification_service_create_once(
            $db,
            (int) $installment['institution_id'],
            (int) $installment['recipient_user_id'],
            (int) $installment['student_id'],
            $type,
            $title,
            $message,
            'installment_' . $kind,
            (int) $installment['installment_id'],
            'installment:' . (int) $installment['installment_id'] . ':' . $kind
        ) ? 1 : 0;
    }
    return $inserted;
}

function notification_service_generate_academic_notifications(mysqli $db): array
{
    $db->begin_transaction();
    try {
        $runStartedAt = (string) $db->query('SELECT CURRENT_TIMESTAMP')->fetch_row()[0];
        $created = ['timetables' => 0, 'examinations' => 0, 'holidays' => 0];

        $cursor = notification_service_generation_cursor($db, 'timetable_events');
        $statement = $db->prepare(
            "SELECT t.institution_id, t.id AS timetable_id, t.updated_at, t.day_of_week,
                    t.start_time, t.end_time, t.subject_name, b.name AS batch_name,
                    s.id AS student_id, s.full_name AS student_name, u.id AS recipient_user_id
             FROM timetables t
             JOIN batches b
               ON b.institution_id = t.institution_id AND b.id = t.batch_id
             JOIN students s
               ON s.institution_id = b.institution_id AND s.batch_id = b.id
             JOIN parent_student_relationships psr
               ON psr.institution_id = s.institution_id AND psr.student_id = s.id
             JOIN parents p
               ON p.institution_id = psr.institution_id AND p.id = psr.parent_id
             JOIN users u
               ON u.institution_id = p.institution_id AND u.id = p.user_id
             WHERE t.updated_at >= ? AND psr.status = 'active'
               AND psr.can_view_academics = 1 AND p.status = 'active'
               AND u.user_type = 'parent' AND u.status = 'active'
               AND u.email_verified_at IS NOT NULL
             ORDER BY t.updated_at, t.id, s.id, u.id"
        );
        $statement->bind_param('s', $cursor);
        $statement->execute();
        $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
        $statement->close();
        foreach ($rows as $row) {
            $updatedAt = (string) $row['updated_at'];
            $created['timetables'] += notification_service_create_once(
                $db,
                (int) $row['institution_id'],
                (int) $row['recipient_user_id'],
                (int) $row['student_id'],
                'class_update',
                'Timetable updated',
                'The timetable for ' . (string) $row['batch_name'] . ' has changed. '
                    . ((string) ($row['subject_name'] ?? '') !== ''
                        ? (string) $row['subject_name'] . ' · '
                        : '')
                    . ucfirst((string) $row['day_of_week']) . ', '
                    . substr((string) $row['start_time'], 0, 5) . '–'
                    . substr((string) $row['end_time'], 0, 5)
                    . ' for ' . (string) $row['student_name'] . '.',
                'timetable',
                (int) $row['timetable_id'],
                'timetable:' . (int) $row['timetable_id'] . ':' . $updatedAt
            ) ? 1 : 0;
        }
        notification_service_advance_generation_cursor($db, 'timetable_events', $runStartedAt);

        $cursor = notification_service_generation_cursor($db, 'examination_events');
        $statement = $db->prepare(
            "SELECT e.institution_id, e.id AS examination_id, e.updated_at, e.name,
                    e.exam_date, e.start_time, e.end_time, b.name AS batch_name,
                    s.id AS student_id, s.full_name AS student_name, u.id AS recipient_user_id
             FROM examinations e
             JOIN batches b
               ON b.institution_id = e.institution_id AND b.id = e.batch_id
             JOIN students s
               ON s.institution_id = b.institution_id AND s.batch_id = b.id
             JOIN parent_student_relationships psr
               ON psr.institution_id = s.institution_id AND psr.student_id = s.id
             JOIN parents p
               ON p.institution_id = psr.institution_id AND p.id = psr.parent_id
             JOIN users u
               ON u.institution_id = p.institution_id AND u.id = p.user_id
             WHERE e.updated_at >= ? AND psr.status = 'active'
               AND psr.can_view_academics = 1 AND p.status = 'active'
               AND u.user_type = 'parent' AND u.status = 'active'
               AND u.email_verified_at IS NOT NULL
             ORDER BY e.updated_at, e.id, s.id, u.id"
        );
        $statement->bind_param('s', $cursor);
        $statement->execute();
        $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
        $statement->close();
        foreach ($rows as $row) {
            $updatedAt = (string) $row['updated_at'];
            $time = $row['start_time'] === null
                ? ''
                : ' at ' . substr((string) $row['start_time'], 0, 5);
            $created['examinations'] += notification_service_create_once(
                $db,
                (int) $row['institution_id'],
                (int) $row['recipient_user_id'],
                (int) $row['student_id'],
                'exam_reminder',
                'Examination schedule updated',
                (string) $row['name'] . ' for ' . (string) $row['batch_name']
                    . ' is scheduled on ' . (string) $row['exam_date'] . $time
                    . ' for ' . (string) $row['student_name'] . '.',
                'examination',
                (int) $row['examination_id'],
                'examination:' . (int) $row['examination_id'] . ':' . $updatedAt
            ) ? 1 : 0;
        }
        notification_service_advance_generation_cursor($db, 'examination_events', $runStartedAt);

        $cursor = notification_service_generation_cursor($db, 'holiday_events');
        $statement = $db->prepare(
            "SELECT h.institution_id, h.id AS holiday_id, h.updated_at, h.name,
                    h.start_date, h.end_date, h.status, u.id AS recipient_user_id
             FROM holidays h
             JOIN parents p ON p.institution_id = h.institution_id
             JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
             WHERE h.updated_at >= ? AND p.status = 'active'
               AND u.user_type = 'parent' AND u.status = 'active'
               AND u.email_verified_at IS NOT NULL
             ORDER BY h.updated_at, h.id, u.id"
        );
        $statement->bind_param('s', $cursor);
        $statement->execute();
        $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
        $statement->close();
        foreach ($rows as $row) {
            $updatedAt = (string) $row['updated_at'];
            $dateRange = (string) $row['start_date'];
            if ($row['end_date'] !== null && $row['end_date'] !== $row['start_date']) {
                $dateRange .= ' to ' . (string) $row['end_date'];
            }
            $statusMessage = $row['status'] === 'active'
                ? 'is scheduled for ' . $dateRange
                : 'has been marked inactive';
            $created['holidays'] += notification_service_create_once(
                $db,
                (int) $row['institution_id'],
                (int) $row['recipient_user_id'],
                null,
                'holiday_update',
                'Holiday schedule updated',
                (string) $row['name'] . ' ' . $statusMessage . '.',
                'holiday',
                (int) $row['holiday_id'],
                'holiday:' . (int) $row['holiday_id'] . ':' . $updatedAt
            ) ? 1 : 0;
        }
        notification_service_advance_generation_cursor($db, 'holiday_events', $runStartedAt);

        $db->commit();
        return $created;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function notification_service_generate_announcement_notifications(mysqli $db): int
{
    $statement = $db->prepare(
        "SELECT a.institution_id, a.id AS announcement_id, a.title, a.message,
                a.updated_at,
                u.id AS recipient_user_id
         FROM announcements a
         JOIN parents p ON p.institution_id = a.institution_id
         JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
         WHERE a.status = 'published'
           AND (a.publish_at IS NULL OR a.publish_at <= CURRENT_TIMESTAMP)
           AND (a.expires_at IS NULL OR a.expires_at >= CURRENT_TIMESTAMP)
           AND p.status = 'active' AND u.user_type = 'parent'
           AND u.status = 'active' AND u.email_verified_at IS NOT NULL
           AND (
               NOT EXISTS (
                   SELECT 1 FROM announcement_batches ab
                   WHERE ab.institution_id = a.institution_id
                     AND ab.announcement_id = a.id
               )
               OR EXISTS (
                   SELECT 1
                   FROM announcement_batches ab
                   JOIN students s
                     ON s.institution_id = ab.institution_id AND s.batch_id = ab.batch_id
                   JOIN parent_student_relationships psr
                     ON psr.institution_id = s.institution_id AND psr.student_id = s.id
                   WHERE ab.institution_id = a.institution_id
                     AND ab.announcement_id = a.id
                     AND psr.parent_id = p.id
                     AND psr.status = 'active'
                     AND psr.can_view_academics = 1
               )
           )
         ORDER BY announcement_id, recipient_user_id"
    );
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();

    $inserted = 0;
    foreach ($rows as $announcement) {
        $inserted += notification_service_create_once(
            $db,
            (int) $announcement['institution_id'],
            (int) $announcement['recipient_user_id'],
            null,
            'announcement',
            (string) $announcement['title'],
            (string) $announcement['message'],
            'announcement',
            (int) $announcement['announcement_id'],
            'announcement:' . (int) $announcement['announcement_id']
                . ':' . (string) $announcement['updated_at']
        ) ? 1 : 0;
    }
    return $inserted;
}

function notification_service_generate_admission_notifications(mysqli $db): int
{
    $db->begin_transaction();
    try {
        $runStartedAt = (string) $db->query('SELECT CURRENT_TIMESTAMP')->fetch_row()[0];
        $cursor = notification_service_generation_cursor($db, 'admission_events');
        $statement = $db->prepare(
            "SELECT a.institution_id, a.id AS admission_id, a.status, a.student_name,
                    p.user_id AS recipient_user_id
             FROM admissions a
             JOIN parents p ON p.institution_id = a.institution_id AND p.id = a.parent_id
             JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
             WHERE a.updated_at >= ?
               AND a.status IN ('submitted','under_review','approved','rejected','enrolled')
               AND p.status = 'active' AND u.user_type = 'parent'
               AND u.status = 'active' AND u.email_verified_at IS NOT NULL
             ORDER BY a.updated_at, a.id"
        );
        $statement->bind_param('s', $cursor);
        $statement->execute();
        $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
        $statement->close();

        $inserted = 0;
        foreach ($rows as $admission) {
            $status = (string) $admission['status'];
            $inserted += notification_service_create_once(
                $db,
                (int) $admission['institution_id'],
                (int) $admission['recipient_user_id'],
                null,
                'admission_update',
                'Admission status updated',
                'The admission application for ' . (string) $admission['student_name']
                    . ' is now ' . str_replace('_', ' ', $status) . '.',
                'admission',
                (int) $admission['admission_id'],
                'admission:' . (int) $admission['admission_id'] . ':' . $status
            ) ? 1 : 0;
        }
        notification_service_advance_generation_cursor($db, 'admission_events', $runStartedAt);
        $db->commit();
        return $inserted;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function notification_service_generate_refund_notifications(mysqli $db): int
{
    $db->begin_transaction();
    try {
        $runStartedAt = (string) $db->query('SELECT CURRENT_TIMESTAMP')->fetch_row()[0];
        $cursor = notification_service_generation_cursor($db, 'refund_events');
        $statement = $db->prepare(
            "SELECT ref.institution_id, ref.id AS refund_id, ref.status, ref.amount,
                    ref.payment_id, ref.student_id, p.user_id AS recipient_user_id,
                    s.full_name AS student_name
             FROM refunds ref
             JOIN parents p ON p.institution_id = ref.institution_id AND p.id = ref.parent_id
             JOIN users u ON u.institution_id = p.institution_id AND u.id = p.user_id
             JOIN students s ON s.institution_id = ref.institution_id AND s.id = ref.student_id
             WHERE ref.processed_at IS NOT NULL AND ref.processed_at >= ?
               AND ref.status IN ('processed','rejected')
               AND p.status = 'active' AND u.user_type = 'parent'
               AND u.status = 'active' AND u.email_verified_at IS NOT NULL
               AND EXISTS (
                   SELECT 1 FROM parent_student_relationships psr
                   WHERE psr.institution_id = ref.institution_id
                     AND psr.parent_id = ref.parent_id
                     AND psr.student_id = ref.student_id
                     AND psr.status = 'active' AND psr.can_view_fees = 1
               )
             ORDER BY ref.processed_at, ref.id"
        );
        $statement->bind_param('s', $cursor);
        $statement->execute();
        $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
        $statement->close();

        $inserted = 0;
        foreach ($rows as $refund) {
            $status = (string) $refund['status'];
            $inserted += notification_service_create_once(
                $db,
                (int) $refund['institution_id'],
                (int) $refund['recipient_user_id'],
                (int) $refund['student_id'],
                'refund_update',
                'Refund status updated',
                'The refund of ' . (string) $refund['amount'] . ' for '
                    . (string) $refund['student_name'] . ' is now ' . $status . '.',
                'refund',
                (int) $refund['refund_id'],
                'refund:' . (int) $refund['refund_id'] . ':' . $status
            ) ? 1 : 0;
        }
        notification_service_advance_generation_cursor($db, 'refund_events', $runStartedAt);
        $db->commit();
        return $inserted;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}