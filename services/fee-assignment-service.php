<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/audit-service.php';

function fee_workflow_money_to_cents(string $amount): int
{
    if (preg_match('/^([0-9]{1,10})(?:\.([0-9]{1,2}))?$/D', trim($amount), $matches) !== 1) {
        throw new InvalidArgumentException('Enter a valid positive fee amount.');
    }

    $cents = ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    if ($cents < 1 || $cents > 999999999999) {
        throw new InvalidArgumentException('The fee amount is outside the supported range.');
    }

    return $cents;
}

function fee_workflow_money_from_cents(int $cents): string
{
    if ($cents < 0) {
        throw new InvalidArgumentException('A fee amount cannot be negative.');
    }

    return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
}

function fee_workflow_valid_date(string $date): bool
{
    if (preg_match('/^(\d{4})-\d{2}-\d{2}$/D', $date, $matches) !== 1 || (int) $matches[1] < 1000) {
        return false;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function fee_workflow_validate_plan(array $input): array
{
    $name = $input['name'] ?? null;
    $code = $input['code'] ?? null;
    $type = $input['plan_type'] ?? null;
    $rawCount = $input['installment_count'] ?? null;

    if (!is_string($name) || !is_string($code) || !is_string($type) || !is_string($rawCount)) {
        throw new InvalidArgumentException('Complete all payment plan fields.');
    }
    $name = trim($name);
    $code = trim($code);
    $count = filter_var($rawCount, FILTER_VALIDATE_INT);
    if ($name === '' || mb_strlen($name, 'UTF-8') > 120
        || preg_match('/[\x00-\x1F\x7F]/', $name) === 1
        || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,49}$/D', $code) !== 1
        || !in_array($type, ['monthly', 'quarterly'], true)
        || $count === false || $count < 1 || $count > 120) {
        throw new InvalidArgumentException('Enter a valid monthly or quarterly plan and installment count.');
    }

    return [
        'name' => $name,
        'code' => $code,
        'plan_type' => $type,
        'installment_count' => $count,
        'description' => null,
        'status' => 'active',
    ];
}

function fee_workflow_create_plan(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    array $values
): int {
    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            'INSERT INTO payment_plans
                (institution_id, code, name, plan_type, installment_count, status)
             VALUES (?, ?, ?, ?, ?, \'active\')'
        );
        $statement->bind_param(
            'isssi',
            $institutionId,
            $values['code'],
            $values['name'],
            $values['plan_type'],
            $values['installment_count']
        );
        $statement->execute();
        $planId = (int) $statement->insert_id;
        $statement->close();
        $actor = current_user();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            is_array($actor) ? (string) $actor['user_type'] : 'admin',
            'payment_plan.created',
            'payment_plan',
            (string) $planId,
            null,
            $values
        );
        $db->commit();
        return $planId;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function fee_workflow_options(mysqli $db, int $institutionId): array
{
    $students = $db->prepare(
        "SELECT s.id, s.student_code, s.full_name, s.academic_year_id, s.course_id, s.class_id,
                ay.name AS academic_year_name
         FROM students s
         JOIN academic_years ay
           ON ay.institution_id = s.institution_id AND ay.id = s.academic_year_id
         WHERE s.institution_id = ? AND s.status IN ('admitted', 'active')
         ORDER BY s.full_name, s.student_code"
    );
    $students->bind_param('i', $institutionId);
    $students->execute();
    $studentRows = $students->get_result()->fetch_all(MYSQLI_ASSOC);
    $students->close();

    $structures = $db->prepare(
        "SELECT fs.id, fs.name, fs.academic_year_id, fs.course_id, fs.class_id,
                fs.total_amount, ay.name AS academic_year_name
         FROM fee_structures fs
         JOIN academic_years ay
           ON ay.institution_id = fs.institution_id AND ay.id = fs.academic_year_id
         WHERE fs.institution_id = ? AND fs.status = 'active' AND fs.total_amount > 0
         ORDER BY ay.start_date DESC, fs.name"
    );
    $structures->bind_param('i', $institutionId);
    $structures->execute();
    $structureRows = $structures->get_result()->fetch_all(MYSQLI_ASSOC);
    $structures->close();

    $plans = $db->prepare(
        "SELECT id, code, name, plan_type, installment_count
         FROM payment_plans
         WHERE institution_id = ? AND status = 'active'
           AND plan_type IN ('monthly', 'quarterly') AND installment_count > 0
         ORDER BY name"
    );
    $plans->bind_param('i', $institutionId);
    $plans->execute();
    $planRows = $plans->get_result()->fetch_all(MYSQLI_ASSOC);
    $plans->close();

    $assignments = $db->prepare(
        "SELECT a.id, a.student_id, a.fee_structure_id, a.payment_plan_id,
                a.academic_year_id, a.total_fee, a.total_paid, a.outstanding_amount, a.status,
                s.student_code, s.full_name AS student_name,
                fs.name AS fee_structure_name, ay.name AS academic_year_name,
                pp.name AS plan_name, pp.plan_type, pp.installment_count,
                COALESCE(schedule.installment_count_existing, 0) AS installment_count_existing,
                schedule.first_due_date, schedule.last_due_date
         FROM student_fee_assignments a
         JOIN students s ON s.institution_id = a.institution_id AND s.id = a.student_id
         JOIN fee_structures fs ON fs.institution_id = a.institution_id AND fs.id = a.fee_structure_id
         JOIN academic_years ay ON ay.institution_id = a.institution_id AND ay.id = a.academic_year_id
         LEFT JOIN payment_plans pp ON pp.institution_id = a.institution_id AND pp.id = a.payment_plan_id
         LEFT JOIN (
             SELECT institution_id, student_fee_assignment_id, COUNT(*) AS installment_count_existing,
                    MIN(due_date) AS first_due_date, MAX(due_date) AS last_due_date
             FROM installments
             GROUP BY institution_id, student_fee_assignment_id
         ) schedule
           ON schedule.institution_id = a.institution_id AND schedule.student_fee_assignment_id = a.id
         WHERE a.institution_id = ?
         ORDER BY a.created_at DESC, a.id DESC
         LIMIT 500"
    );
    $assignments->bind_param('i', $institutionId);
    $assignments->execute();
    $assignmentRows = $assignments->get_result()->fetch_all(MYSQLI_ASSOC);
    $assignments->close();

    return [
        'students' => $studentRows,
        'structures' => $structureRows,
        'plans' => $planRows,
        'assignments' => $assignmentRows,
    ];
}

function fee_workflow_create_assignment(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    int $studentId,
    int $structureId,
    int $planId
): int {
    if ($institutionId < 1 || $actorUserId < 1 || $studentId < 1 || $structureId < 1 || $planId < 1) {
        throw new InvalidArgumentException('Select a student, fee structure, and payment plan.');
    }

    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            "SELECT s.status AS student_status, s.academic_year_id AS student_year_id,
                    s.course_id AS student_course_id, s.class_id AS student_class_id,
                    fs.academic_year_id AS structure_year_id,
                    fs.course_id AS structure_course_id, fs.class_id AS structure_class_id,
                    fs.total_amount, fs.status AS structure_status,
                    pp.plan_type, pp.installment_count, pp.status AS plan_status
             FROM students s
             JOIN fee_structures fs
               ON fs.institution_id = s.institution_id AND fs.id = ?
             JOIN payment_plans pp
               ON pp.institution_id = s.institution_id AND pp.id = ?
             WHERE s.institution_id = ? AND s.id = ?
             FOR UPDATE"
        );
        $statement->bind_param('iiii', $structureId, $planId, $institutionId, $studentId);
        $statement->execute();
        $record = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($record)) {
            throw new InvalidArgumentException('The selected records must belong to this institution.');
        }
        if (!in_array($record['student_status'], ['admitted', 'active'], true)) {
            throw new InvalidArgumentException('Only admitted or active students can receive a fee assignment.');
        }
        if ($record['structure_status'] !== 'active') {
            throw new InvalidArgumentException('Select an active fee structure with a positive total.');
        }
        if ($record['plan_status'] !== 'active'
            || !in_array($record['plan_type'], ['monthly', 'quarterly'], true)
            || (int) $record['installment_count'] < 1) {
            throw new InvalidArgumentException('Select an active monthly or quarterly payment plan.');
        }
        if ($record['student_year_id'] === null
            || (int) $record['student_year_id'] !== (int) $record['structure_year_id']) {
            throw new InvalidArgumentException('The student must be assigned to the fee structure academic year.');
        }
        if ($record['structure_course_id'] !== null
            && (int) $record['structure_course_id'] !== (int) $record['student_course_id']) {
            throw new InvalidArgumentException('The fee structure course does not match the student.');
        }
        if ($record['structure_class_id'] !== null
            && (int) $record['structure_class_id'] !== (int) $record['student_class_id']) {
            throw new InvalidArgumentException('The fee structure class does not match the student.');
        }
        $totalFee = fee_workflow_money_from_cents(fee_workflow_money_to_cents((string) $record['total_amount']));
        $outstanding = $totalFee;
        $assignmentStatus = 'active';
        $academicYearId = (int) $record['structure_year_id'];
        $statement = $db->prepare(
            'INSERT INTO student_fee_assignments
                (institution_id, student_id, fee_structure_id, payment_plan_id, academic_year_id,
                 total_fee, total_paid, outstanding_amount, status)
             VALUES (?, ?, ?, ?, ?, ?, 0.00, ?, ?)'
        );
        $statement->bind_param(
            'iiiiisss',
            $institutionId,
            $studentId,
            $structureId,
            $planId,
            $academicYearId,
            $totalFee,
            $outstanding,
            $assignmentStatus
        );
        $statement->execute();
        $assignmentId = (int) $statement->insert_id;
        $statement->close();
        $actor = current_user();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            is_array($actor) ? (string) $actor['user_type'] : 'admin',
            'student_fee_assignment.created',
            'student_fee_assignment',
            (string) $assignmentId,
            null,
            [
                'student_id' => $studentId,
                'fee_structure_id' => $structureId,
                'payment_plan_id' => $planId,
                'academic_year_id' => $academicYearId,
                'total_fee' => $totalFee,
                'outstanding_amount' => $outstanding,
            ]
        );
        $db->commit();
        return $assignmentId;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function fee_workflow_build_schedule(
    string $totalAmount,
    string $planType,
    int $installmentCount,
    string $firstDueDate
): array {
    if (!in_array($planType, ['monthly', 'quarterly'], true) || $installmentCount < 1 || $installmentCount > 120) {
        throw new InvalidArgumentException('Only valid monthly or quarterly plans can generate installments.');
    }
    if (!fee_workflow_valid_date($firstDueDate)) {
        throw new InvalidArgumentException('Enter a valid first installment due date.');
    }
    $totalCents = fee_workflow_money_to_cents($totalAmount);
    if ($installmentCount > $totalCents) {
        throw new InvalidArgumentException('The fee amount must cover at least one cent per installment.');
    }
    $baseCents = intdiv($totalCents, $installmentCount);
    $remainderCents = $totalCents % $installmentCount;
    $intervalMonths = $planType === 'monthly' ? 1 : 3;
    $anchor = DateTimeImmutable::createFromFormat('!Y-m-d', $firstDueDate);
    if ($anchor === false) {
        throw new InvalidArgumentException('Enter a valid first installment due date.');
    }
    $anchorDay = (int) $anchor->format('j');
    $anchorMonthIndex = ((int) $anchor->format('Y') * 12) + (int) $anchor->format('n') - 1;
    $schedule = [];

    for ($index = 0; $index < $installmentCount; $index++) {
        $monthIndex = $anchorMonthIndex + ($index * $intervalMonths);
        $year = intdiv($monthIndex, 12);
        $month = ($monthIndex % 12) + 1;
        if ($year > 9999) {
            throw new InvalidArgumentException('The installment schedule exceeds the supported date range.');
        }
        $monthStart = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $day = min($anchorDay, (int) $monthStart->format('t'));
        $dueDate = $monthStart->setDate($year, $month, $day)->format('Y-m-d');
        $amountCents = $baseCents + ($index < $remainderCents ? 1 : 0);
        $schedule[] = [
            'installment_number' => $index + 1,
            'due_date' => $dueDate,
            'due_amount' => fee_workflow_money_from_cents($amountCents),
        ];
    }

    return $schedule;
}

function fee_workflow_generate_installments(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    int $assignmentId,
    string $firstDueDate
): array {
    if ($institutionId < 1 || $actorUserId < 1 || $assignmentId < 1) {
        throw new InvalidArgumentException('The fee assignment is invalid.');
    }
    if (!fee_workflow_valid_date($firstDueDate)) {
        throw new InvalidArgumentException('Enter a valid first installment due date.');
    }

    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            "SELECT a.total_fee, a.status AS assignment_status,
                    pp.plan_type, pp.installment_count, pp.status AS plan_status
             FROM student_fee_assignments a
             JOIN payment_plans pp
               ON pp.institution_id = a.institution_id AND pp.id = a.payment_plan_id
             WHERE a.institution_id = ? AND a.id = ?
             FOR UPDATE"
        );
        $statement->bind_param('ii', $institutionId, $assignmentId);
        $statement->execute();
        $assignment = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($assignment)) {
            throw new OutOfBoundsException('Fee assignment not found.');
        }
        if ($assignment['assignment_status'] !== 'active' || $assignment['plan_status'] !== 'active') {
            throw new DomainException('Only active fee assignments with active plans can generate installments.');
        }
        $schedule = fee_workflow_build_schedule(
            (string) $assignment['total_fee'],
            (string) $assignment['plan_type'],
            (int) $assignment['installment_count'],
            $firstDueDate
        );

        $existing = $db->prepare(
            'SELECT COUNT(*) AS row_count FROM installments
             WHERE institution_id = ? AND student_fee_assignment_id = ?'
        );
        $existing->bind_param('ii', $institutionId, $assignmentId);
        $existing->execute();
        $existingCount = (int) $existing->get_result()->fetch_assoc()['row_count'];
        $existing->close();
        if ($existingCount > 0) {
            $db->commit();
            return ['created' => 0, 'already_exists' => true];
        }

        $statement = $db->prepare(
            "INSERT INTO installments
                (institution_id, student_fee_assignment_id, installment_number, due_date, due_amount, status)
             VALUES (?, ?, ?, ?, ?, 'pending')"
        );
        foreach ($schedule as $installment) {
            $number = (int) $installment['installment_number'];
            $dueDate = (string) $installment['due_date'];
            $amount = (string) $installment['due_amount'];
            $statement->bind_param('iiiss', $institutionId, $assignmentId, $number, $dueDate, $amount);
            $statement->execute();
        }
        $statement->close();
        $actor = current_user();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            is_array($actor) ? (string) $actor['user_type'] : 'admin',
            'installments.generated',
            'student_fee_assignment',
            (string) $assignmentId,
            null,
            [
                'plan_type' => $assignment['plan_type'],
                'installment_count' => count($schedule),
                'first_due_date' => $firstDueDate,
                'total_fee' => (string) $assignment['total_fee'],
            ]
        );
        $db->commit();
        return ['created' => count($schedule), 'already_exists' => false];
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}
