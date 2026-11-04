<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/audit-service.php';
require_once __DIR__ . '/academic-management-service.php';

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

function fee_workflow_nonnegative_money_to_cents(string $amount): int
{
    $trimmed = trim($amount);
    if ($trimmed === '0' || $trimmed === '0.0' || $trimmed === '0.00') {
        return 0;
    }

    return fee_workflow_money_to_cents($trimmed);
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
    $configurationStatement = $db->prepare(
        'SELECT academic_model, active_academic_year_id FROM institutions WHERE id = ?'
    );
    $configurationStatement->bind_param('i', $institutionId);
    $configurationStatement->execute();
    $configuration = $configurationStatement->get_result()->fetch_assoc();
    $configurationStatement->close();
    if (!is_array($configuration)) {
        throw new OutOfBoundsException('The institution configuration was not found.');
    }

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
               a.academic_year_id, a.student_enrolment_id, a.total_fee, a.total_discount,
               a.total_paid, a.outstanding_amount, a.status,
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

    $enrolmentRows = [];
    $scopedStructureRows = [];
    if (is_string($configuration['academic_model'])) {
        $enrolments = $db->prepare(
            "SELECT e.id AS enrolment_id, e.student_id, e.academic_year_id, s.student_code,
                    s.full_name, ay.name AS academic_year_name, g.group_type,
                    c.name AS class_name, section.name AS section_name,
                    course.name AS course_name, term.name AS term_name, b.name AS batch_name
             FROM student_enrolments e
             JOIN institutions i ON i.id = e.institution_id AND i.active_academic_year_id = e.academic_year_id
             JOIN students s ON s.institution_id = e.institution_id AND s.id = e.student_id
             JOIN academic_years ay ON ay.institution_id = e.institution_id AND ay.id = e.academic_year_id
             JOIN student_enrolment_placements p
               ON p.institution_id = e.institution_id AND p.student_enrolment_id = e.id
              AND p.id = (
                  SELECT p2.id FROM student_enrolment_placements p2
                  WHERE p2.institution_id = e.institution_id AND p2.student_enrolment_id = e.id
                  ORDER BY p2.effective_at DESC, p2.id DESC LIMIT 1
              )
             JOIN academic_groups g
               ON g.institution_id = p.institution_id AND g.academic_year_id = p.academic_year_id
              AND g.id = p.academic_group_id
             LEFT JOIN classes c ON c.institution_id = g.institution_id AND c.id = g.class_id
             LEFT JOIN sections section
               ON section.institution_id = g.institution_id AND section.academic_year_id = g.academic_year_id
              AND section.class_id = g.class_id AND section.id = g.section_id
             LEFT JOIN courses course ON course.institution_id = g.institution_id AND course.id = g.course_id
             LEFT JOIN academic_terms term
               ON term.institution_id = g.institution_id AND term.academic_year_id = g.academic_year_id
              AND term.course_id = g.course_id AND term.id = g.academic_term_id
             LEFT JOIN batches b
               ON b.institution_id = g.institution_id AND b.course_id = g.course_id AND b.id = g.batch_id
             WHERE e.institution_id = ? AND e.enrolment_status = 'active'
             ORDER BY s.full_name, s.student_code"
        );
        $enrolments->bind_param('i', $institutionId);
        $enrolments->execute();
        $enrolmentRows = $enrolments->get_result()->fetch_all(MYSQLI_ASSOC);
        $enrolments->close();

        $scopedStructures = $db->prepare(
            "SELECT fs.id, fs.name, fs.academic_year_id, fs.target_model, fs.target_scope_kind,
                    fs.target_scope_id, fs.fee_group_code, fs.class_id, fs.section_id,
                    fs.course_id, fs.academic_term_id, fs.batch_id, fs.effective_from,
                    fs.effective_until, ay.name AS academic_year_name
             FROM fee_structures fs
             JOIN academic_years ay ON ay.institution_id = fs.institution_id AND ay.id = fs.academic_year_id
             WHERE fs.institution_id = ? AND fs.target_model <> 'legacy'
               AND fs.status = 'active' AND fs.effective_from <= CURRENT_DATE
               AND (fs.effective_until IS NULL OR fs.effective_until >= CURRENT_DATE)
             ORDER BY ay.start_date DESC, fs.name"
        );
        $scopedStructures->bind_param('i', $institutionId);
        $scopedStructures->execute();
        $scopedStructureRows = $scopedStructures->get_result()->fetch_all(MYSQLI_ASSOC);
        $scopedStructures->close();

    }

    return [
        'academic_model' => $configuration['academic_model'],
        'active_academic_year_id' => $configuration['active_academic_year_id'] === null
            ? null
            : (int) $configuration['active_academic_year_id'],
        'students' => $studentRows,
        'structures' => $structureRows,
        'plans' => $planRows,
        'assignments' => $assignmentRows,
        'enrolments' => $enrolmentRows,
        'scoped_structures' => $scopedStructureRows,
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

function fee_workflow_create_enrolment_assignment(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    int $enrolmentId,
    int $structureId,
    int $planId
): int {
    if ($institutionId < 1 || $actorUserId < 1 || $enrolmentId < 1 || $structureId < 1 || $planId < 1) {
        throw new InvalidArgumentException('Select an active enrolment, applicable fee structure, and payment plan.');
    }

    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            "SELECT i.academic_model, i.active_academic_year_id, e.id AS enrolment_id,
                    e.student_id, e.academic_year_id, e.enrolment_status,
                    student.status AS student_status, g.group_type, g.class_id, g.section_id, g.course_id,
                    g.academic_term_id, g.batch_id
             FROM student_enrolments e
             JOIN institutions i ON i.id = e.institution_id
             JOIN academic_years ay
               ON ay.institution_id = e.institution_id AND ay.id = e.academic_year_id
             JOIN students student
               ON student.institution_id = e.institution_id AND student.id = e.student_id
             JOIN student_enrolment_placements p
               ON p.institution_id = e.institution_id AND p.student_enrolment_id = e.id
              AND p.id = (
                  SELECT p2.id FROM student_enrolment_placements p2
                  WHERE p2.institution_id = e.institution_id AND p2.student_enrolment_id = e.id
                  ORDER BY p2.effective_at DESC, p2.id DESC LIMIT 1
              )
             JOIN academic_groups g
               ON g.institution_id = p.institution_id AND g.academic_year_id = p.academic_year_id
              AND g.id = p.academic_group_id
             WHERE e.institution_id = ? AND e.id = ? AND e.enrolment_status = 'active'
               AND ay.status = 'active' AND i.active_academic_year_id = e.academic_year_id
             FOR UPDATE"
        );
        $statement->bind_param('ii', $institutionId, $enrolmentId);
        $statement->execute();
        $enrolment = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($enrolment)) {
            throw new DomainException('Fee assignment requires an active enrolment in the selected academic year.');
        }
        if (!in_array($enrolment['student_status'], ['admitted', 'active'], true)) {
            throw new DomainException('Only admitted or active students can receive a fee assignment.');
        }
        $model = (string) $enrolment['academic_model'];
        if (!in_array($model, ['school', 'college', 'university', 'coaching'], true)) {
            throw new DomainException('Configure the academic model before assigning fees.');
        }
        $studentId = (int) $enrolment['student_id'];
        $yearId = (int) $enrolment['academic_year_id'];

        $statement = $db->prepare(
            "SELECT id, plan_type, installment_count
             FROM payment_plans
             WHERE institution_id = ? AND id = ? AND status = 'active'
               AND plan_type IN ('monthly','quarterly') AND installment_count > 0
             FOR UPDATE"
        );
        $statement->bind_param('ii', $institutionId, $planId);
        $statement->execute();
        $plan = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($plan)) {
            throw new InvalidArgumentException('Select an active monthly or quarterly payment plan.');
        }

        $statement = $db->prepare(
            "SELECT id, fee_group_code FROM fee_structures
             WHERE institution_id = ? AND id = ? AND academic_year_id = ?
               AND target_model <> 'legacy' AND status = 'active'
               AND effective_from <= CURRENT_DATE
               AND (effective_until IS NULL OR effective_until >= CURRENT_DATE)
             FOR UPDATE"
        );
        $statement->bind_param('iii', $institutionId, $structureId, $yearId);
        $statement->execute();
        $selectedStructure = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($selectedStructure)) {
            throw new OutOfBoundsException('Select an active, effective fee structure for this enrolment year.');
        }
        $feeGroupCode = (string) $selectedStructure['fee_group_code'];

        $statement = $db->prepare(
            "SELECT fs.id AS structure_id, fs.target_model, fs.class_id, fs.section_id,
                    fs.course_id, fs.academic_term_id, fs.batch_id,
                    item.id AS source_item_id, item.category_id, item.amount, item.frequency,
                    item.description, item.is_optional, category.name AS component_name
             FROM fee_structures fs
             JOIN fee_structure_items item
               ON item.institution_id = fs.institution_id AND item.fee_structure_id = fs.id
             JOIN fee_categories category
               ON category.institution_id = item.institution_id AND category.id = item.category_id
             WHERE fs.institution_id = ? AND fs.academic_year_id = ?
               AND fs.fee_group_code = ? AND fs.status = 'active'
               AND fs.effective_from <= CURRENT_DATE
               AND (fs.effective_until IS NULL OR fs.effective_until >= CURRENT_DATE)
               AND item.status = 'active' AND category.status IN ('active','inactive')
             ORDER BY fs.id, item.sort_order, item.id
             FOR UPDATE"
        );
        $statement->bind_param('iis', $institutionId, $yearId, $feeGroupCode);
        $statement->execute();
        $candidateItems = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
        $statement->close();
        $applicableItems = [];
        $selectedStructureApplies = false;
        foreach ($candidateItems as $item) {
            $priority = null;
            if ($model === 'school' && $item['target_model'] === 'class'
                && (int) $item['class_id'] === (int) $enrolment['class_id']) {
                if ($item['section_id'] === null) {
                    $priority = 0;
                } elseif ($enrolment['section_id'] !== null
                    && (int) $item['section_id'] === (int) $enrolment['section_id']) {
                    $priority = 1;
                }
            } elseif (in_array($model, ['college', 'university'], true)) {
                if ($item['target_model'] === 'course'
                    && (int) $item['course_id'] === (int) $enrolment['course_id']) {
                    $priority = 0;
                } elseif ($item['target_model'] === 'term'
                    && (int) $item['course_id'] === (int) $enrolment['course_id']
                    && (int) $item['academic_term_id'] === (int) $enrolment['academic_term_id']) {
                    $priority = 1;
                }
            } elseif ($model === 'coaching' && $item['target_model'] === 'batch'
                && (int) $item['batch_id'] === (int) $enrolment['batch_id']) {
                $priority = 1;
            }
            if ($priority === null) {
                continue;
            }
            if ((int) $item['structure_id'] === $structureId) {
                $selectedStructureApplies = true;
            }
            $item['scope_priority'] = $priority;
            $applicableItems[] = $item;
        }
        if (!$selectedStructureApplies) {
            throw new DomainException('The selected fee structure does not apply to the enrolment placement.');
        }
        usort($applicableItems, static fn(array $left, array $right): int =>
            [$left['scope_priority'], $left['source_item_id']] <=> [$right['scope_priority'], $right['source_item_id']]
        );
        $componentsByCategory = [];
        foreach ($applicableItems as $item) {
            $componentsByCategory[(int) $item['category_id']] = $item;
        }
        if ($componentsByCategory === []) {
            throw new DomainException('The effective fee structure has no active fee components.');
        }

        $totalCents = 0;
        foreach ($componentsByCategory as $item) {
            $totalCents += fee_workflow_money_to_cents((string) $item['amount']);
            if ($totalCents > 999999999999) {
                throw new DomainException('The effective fee total exceeds the supported range.');
            }
        }
        $totalFee = fee_workflow_money_from_cents($totalCents);
        $totalDiscount = '0.00';
        $outstanding = $totalFee;
        $assignmentStatus = 'active';
        $statement = $db->prepare(
            'INSERT INTO student_fee_assignments
                (institution_id, student_id, fee_structure_id, payment_plan_id, academic_year_id,
                 student_enrolment_id, total_fee, total_discount, total_paid, outstanding_amount, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0.00, ?, ?)'
        );
        $statement->bind_param(
            'iiiiiissss',
            $institutionId,
            $studentId,
            $structureId,
            $planId,
            $yearId,
            $enrolmentId,
            $totalFee,
            $totalDiscount,
            $outstanding,
            $assignmentStatus
        );
        $statement->execute();
        $assignmentId = (int) $statement->insert_id;
        $statement->close();

        $itemStatement = $db->prepare(
            'INSERT INTO student_fee_assignment_items
                (institution_id, student_fee_assignment_id, fee_category_id, source_fee_structure_item_id,
                 source_section_id, component_name, component_description, frequency, amount, is_optional, source_version)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $scheduleStatement = $db->prepare(
            'INSERT INTO student_fee_assignment_item_schedules
                (institution_id, assignment_item_id, installment_number, due_date, amount)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($componentsByCategory as $item) {
            $categoryId = (int) $item['category_id'];
            $sourceItemId = (int) $item['source_item_id'];
            $sectionId = $item['section_id'] === null ? null : (int) $item['section_id'];
            $componentName = (string) $item['component_name'];
            $description = $item['description'] === null ? null : (string) $item['description'];
            $frequency = $item['frequency'] === null ? null : (string) $item['frequency'];
            $amount = (string) $item['amount'];
            $optional = (int) $item['is_optional'];
            $sourceVersion = 'v1';
            $itemStatement->bind_param(
                'iiiiissssis',
                $institutionId,
                $assignmentId,
                $categoryId,
                $sourceItemId,
                $sectionId,
                $componentName,
                $description,
                $frequency,
                $amount,
                $optional,
                $sourceVersion
            );
            $itemStatement->execute();
            $assignmentItemId = (int) $itemStatement->insert_id;
            $scheduleRows = [];
            if ($frequency === 'custom') {
                $sourceSchedule = $db->prepare(
                    "SELECT installment_number, due_date, amount
                     FROM fee_component_schedule
                     WHERE institution_id = ? AND fee_structure_item_id = ? AND status = 'active'
                     ORDER BY installment_number"
                );
                $sourceSchedule->bind_param('ii', $institutionId, $sourceItemId);
                $sourceSchedule->execute();
                $scheduleRows = $sourceSchedule->get_result()->fetch_all(MYSQLI_ASSOC);
                $sourceSchedule->close();
                if ($scheduleRows === []) {
                    throw new DomainException('A custom fee component is missing its schedule.');
                }
            }
            foreach ($scheduleRows as $scheduleRow) {
                $number = (int) $scheduleRow['installment_number'];
                $dueDate = (string) $scheduleRow['due_date'];
                $scheduleAmount = (string) $scheduleRow['amount'];
                $scheduleStatement->bind_param(
                    'iiiss',
                    $institutionId,
                    $assignmentItemId,
                    $number,
                    $dueDate,
                    $scheduleAmount
                );
                $scheduleStatement->execute();
            }
        }
        $itemStatement->close();
        $scheduleStatement->close();

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
                'student_enrolment_id' => $enrolmentId,
                'academic_year_id' => $yearId,
                'fee_structure_id' => $structureId,
                'payment_plan_id' => $planId,
                'component_count' => count($componentsByCategory),
                'total_fee' => $totalFee,
                'total_discount' => $totalDiscount,
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

function fee_workflow_build_component_schedule(
    string $amount,
    ?string $frequency,
    string $planType,
    int $planCount,
    string $firstDueDate
): array {
    if ($frequency === null) {
        return fee_workflow_build_schedule($amount, $planType, $planCount, $firstDueDate);
    }
    $frequencySettings = [
        'one_time' => [1, 12],
        'monthly' => [12, 1],
        'quarterly' => [4, 3],
        'half_yearly' => [2, 6],
        'yearly' => [1, 12],
    ];
    if (!isset($frequencySettings[$frequency])) {
        throw new InvalidArgumentException('A custom component schedule must contain saved schedule rows.');
    }
    [$count, $intervalMonths] = $frequencySettings[$frequency];
    if (!fee_workflow_valid_date($firstDueDate)) {
        throw new InvalidArgumentException('Enter a valid first installment due date.');
    }
    $totalCents = fee_workflow_money_to_cents($amount);
    if ($count > $totalCents) {
        throw new InvalidArgumentException('The fee amount must cover at least one cent per component installment.');
    }
    $anchor = DateTimeImmutable::createFromFormat('!Y-m-d', $firstDueDate);
    if ($anchor === false) {
        throw new InvalidArgumentException('Enter a valid first installment due date.');
    }
    $anchorDay = (int) $anchor->format('j');
    $anchorMonthIndex = ((int) $anchor->format('Y') * 12) + (int) $anchor->format('n') - 1;
    $baseCents = intdiv($totalCents, $count);
    $remainderCents = $totalCents % $count;
    $schedule = [];
    for ($index = 0; $index < $count; $index++) {
        $monthIndex = $anchorMonthIndex + ($index * $intervalMonths);
        $year = intdiv($monthIndex, 12);
        $month = ($monthIndex % 12) + 1;
        if ($year > 9999) {
            throw new InvalidArgumentException('The component schedule exceeds the supported date range.');
        }
        $monthStart = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $day = min($anchorDay, (int) $monthStart->format('t'));
        $schedule[] = [
            'installment_number' => $index + 1,
            'due_date' => $monthStart->setDate($year, $month, $day)->format('Y-m-d'),
            'due_amount' => fee_workflow_money_from_cents($baseCents + ($index < $remainderCents ? 1 : 0)),
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
            "SELECT a.total_fee, a.total_discount, a.status AS assignment_status,
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

        $componentStatement = $db->prepare(
            'SELECT id, amount, frequency FROM student_fee_assignment_items
             WHERE institution_id = ? AND student_fee_assignment_id = ?
             ORDER BY id'
        );
        $componentStatement->bind_param('ii', $institutionId, $assignmentId);
        $componentStatement->execute();
        $components = $componentStatement->get_result()->fetch_all(MYSQLI_ASSOC);
        $componentStatement->close();
        $aggregateByDate = [];
        if ($components === []) {
            $schedule = fee_workflow_build_schedule(
                (string) $assignment['total_fee'],
                (string) $assignment['plan_type'],
                (int) $assignment['installment_count'],
                $firstDueDate
            );
        } else {
            foreach ($components as $component) {
                $itemId = (int) $component['id'];
                if ($component['frequency'] === 'custom') {
                    $customStatement = $db->prepare(
                        'SELECT installment_number, due_date, amount
                         FROM student_fee_assignment_item_schedules
                         WHERE institution_id = ? AND assignment_item_id = ?
                         ORDER BY installment_number'
                    );
                    $customStatement->bind_param('ii', $institutionId, $itemId);
                    $customStatement->execute();
                    $itemSchedule = $customStatement->get_result()->fetch_all(MYSQLI_ASSOC);
                    $customStatement->close();
                    if ($itemSchedule === []) {
                        throw new DomainException('A custom component has no saved schedule.');
                    }
                    $itemSchedule = array_map(
                        static fn(array $row): array => [
                            'installment_number' => (int) $row['installment_number'],
                            'due_date' => (string) $row['due_date'],
                            'due_amount' => (string) $row['amount'],
                        ],
                        $itemSchedule
                    );
                } else {
                    $itemSchedule = fee_workflow_build_component_schedule(
                        (string) $component['amount'],
                        $component['frequency'] === null ? null : (string) $component['frequency'],
                        (string) $assignment['plan_type'],
                        (int) $assignment['installment_count'],
                        $firstDueDate
                    );
                    $scheduleStatement = $db->prepare(
                        'INSERT INTO student_fee_assignment_item_schedules
                            (institution_id, assignment_item_id, installment_number, due_date, amount)
                         VALUES (?, ?, ?, ?, ?)'
                    );
                    foreach ($itemSchedule as $componentSchedule) {
                        $number = (int) $componentSchedule['installment_number'];
                        $dueDate = (string) $componentSchedule['due_date'];
                        $amount = (string) $componentSchedule['due_amount'];
                        $scheduleStatement->bind_param('iiiss', $institutionId, $itemId, $number, $dueDate, $amount);
                        $scheduleStatement->execute();
                    }
                    $scheduleStatement->close();
                }
                foreach ($itemSchedule as $componentSchedule) {
                    $date = (string) $componentSchedule['due_date'];
                    $aggregateByDate[$date] = ($aggregateByDate[$date] ?? 0)
                        + fee_workflow_money_to_cents((string) $componentSchedule['due_amount']);
                }
            }
            ksort($aggregateByDate);
            $discountRemaining = fee_workflow_nonnegative_money_to_cents((string) $assignment['total_discount']);
            foreach ($aggregateByDate as $date => &$amountCents) {
                $discount = min($discountRemaining, $amountCents);
                $amountCents -= $discount;
                $discountRemaining -= $discount;
            }
            unset($amountCents);
            if ($discountRemaining !== 0) {
                throw new DomainException('The saved assignment discount exceeds the component schedule total.');
            }
            $schedule = [];
            foreach ($aggregateByDate as $date => $amountCents) {
                if ($amountCents === 0) {
                    continue;
                }
                $schedule[] = [
                    'installment_number' => count($schedule) + 1,
                    'due_date' => (string) $date,
                    'due_amount' => fee_workflow_money_from_cents($amountCents),
                ];
            }
            if ($schedule === []) {
                $schedule[] = [
                    'installment_number' => 1,
                    'due_date' => $firstDueDate,
                    'due_amount' => '0.00',
                ];
            }
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
                'total_discount' => (string) $assignment['total_discount'],
                'component_count' => count($components),
            ]
        );
        $db->commit();
        return ['created' => count($schedule), 'already_exists' => false];
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}
