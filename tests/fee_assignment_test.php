<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/fee-assignment-service.php';

$checks = 0;
$failures = [];
$check = static function (bool $condition, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $label;
    }
};

$monthly = fee_workflow_build_schedule('24000.00', 'monthly', 12, '2027-01-31');
$check(
    count($monthly) === 12
        && $monthly[0] === ['installment_number' => 1, 'due_date' => '2027-01-31', 'due_amount' => '2000.00']
        && $monthly[1]['due_date'] === '2027-02-28'
        && $monthly[2]['due_date'] === '2027-03-31'
        && $monthly[3]['due_date'] === '2027-04-30',
    'Generate equal monthly installments with anchored month-end clamping'
);
$quarterly = fee_workflow_build_schedule('24000', 'quarterly', 4, '2027-01-31');
$check(
    count($quarterly) === 4
        && array_column($quarterly, 'due_date') === ['2027-01-31', '2027-04-30', '2027-07-31', '2027-10-31']
        && array_unique(array_column($quarterly, 'due_amount')) === ['6000.00'],
    'Generate four equal quarterly installments from the administrator-entered date'
);
$remainder = fee_workflow_build_schedule('100.00', 'monthly', 3, '2027-01-01');
$check(
    array_column($remainder, 'due_amount') === ['33.34', '33.33', '33.33'],
    'Distribute remainder cents to earlier installments without losing money'
);
$leapYear = fee_workflow_build_schedule('0.05', 'monthly', 5, '2028-01-31');
$check(
    $leapYear[1]['due_date'] === '2028-02-29'
        && $leapYear[2]['due_date'] === '2028-03-31'
        && array_column($leapYear, 'due_amount') === ['0.01', '0.01', '0.01', '0.01', '0.01'],
    'Handle leap-February dates and a remainder of one cent'
);
$check(fee_workflow_money_to_cents('0.09') === 9, 'Convert decimal amounts to integer cents');
$check(fee_workflow_money_from_cents(33) === '0.33', 'Format cents without floating-point rounding');

$invalidSchedules = [
    ['100.00', 'annual', 1, '2027-01-01'],
    ['100.00', 'monthly', 0, '2027-01-01'],
    ['100.00', 'monthly', 121, '2027-01-01'],
    ['0.05', 'monthly', 6, '2027-01-01'],
    ['100.00', 'quarterly', 1, '2027-02-29'],
    ['100.00', 'monthly', 1, '0999-12-31'],
    ['0.00', 'monthly', 1, '2027-01-01'],
];
foreach ($invalidSchedules as $input) {
    $rejected = false;
    try {
        fee_workflow_build_schedule(...$input);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    $check($rejected, 'Reject invalid plan, amount, or due-date input');
}

$validPlan = fee_workflow_validate_plan([
    'name' => 'Monthly 12',
    'code' => 'MONTHLY_12',
    'plan_type' => 'monthly',
    'installment_count' => '12',
]);
$check($validPlan['installment_count'] === 12, 'Validate and normalize a supported payment plan');
$invalidPlanRejected = false;
try {
    fee_workflow_validate_plan([
        'name' => 'Annual',
        'code' => 'ANNUAL',
        'plan_type' => 'annual',
        'installment_count' => '1',
    ]);
} catch (InvalidArgumentException) {
    $invalidPlanRejected = true;
}
$check($invalidPlanRejected, 'Reject plans that do not use a supported installment interval');

$serviceSource = file_get_contents(__DIR__ . '/../services/fee-assignment-service.php');
$assignmentPage = file_get_contents(__DIR__ . '/../admin/fees/assignments.php');
$generationRoute = file_get_contents(__DIR__ . '/../admin/fees/generate-installments.php');
$parentService = file_get_contents(__DIR__ . '/../services/parent-portal-service.php');
$schema = file_get_contents(__DIR__ . '/../database/schema.sql');
$validSources = is_string($serviceSource)
    && is_string($assignmentPage)
    && is_string($generationRoute)
    && is_string($parentService)
    && is_string($schema);
$check($validSources, 'Read the fee workflow and parent regression sources');
if ($validSources) {
    $check(
        str_contains($serviceSource, 'WHERE s.institution_id = ? AND s.id = ?')
            && str_contains($serviceSource, 'WHERE a.institution_id = ? AND a.id = ?')
            && str_contains($serviceSource, 'fs.institution_id = s.institution_id')
            && str_contains($serviceSource, 'pp.institution_id = s.institution_id')
            && str_contains($serviceSource, 'FOR UPDATE'),
        'Scope assignment and generation lookups to the institution and lock the assignment'
    );
    $check(
        str_contains($assignmentPage, "require_permission('fees.manage')")
            && str_contains($generationRoute, "require_http_methods(['POST'])")
            && str_contains($generationRoute, 'require_valid_csrf_token()'),
        'Require fee-management permission, POST, and CSRF protection for changes'
    );
    $check(
        str_contains($serviceSource, 'INSERT INTO installments')
            && str_contains($serviceSource, 'ON DUPLICATE KEY UPDATE') === false
            && str_contains($serviceSource, 'write_audit_log(')
            && !preg_match('/UPDATE\s+(?:student_fee_assignments|installments|payments|receipts)\s+SET/i', $serviceSource),
        'Insert and audit a schedule without overwriting balances, payments, or receipts'
    );
    $check(
        str_contains($serviceSource, 'installment_count_existing')
            && str_contains($serviceSource, 'already_exists')
            && str_contains($parentService, 'outstanding_amount'),
        'Keep schedule generation repeat-safe and preserve parent balance reads'
    );
    $check(
        str_contains($schema, 'uq_student_fee_assignment (institution_id, student_id, academic_year_id, fee_structure_id)')
            && str_contains($schema, 'uq_installments_assignment_number (student_fee_assignment_id, installment_number)')
            && str_contains($schema, "gateway ENUM('manual','razorpay'")
            && str_contains($schema, 'uq_payment_orders_idempotency')
            && str_contains($schema, 'uq_payments_gateway_txn')
            && str_contains($schema, 'uq_payments_idempotency')
            && str_contains($schema, 'FOREIGN KEY (institution_id, installment_id) REFERENCES installments(institution_id, id)'),
        'Preserve assignment/installment uniqueness and schema support for future Razorpay idempotency'
    );
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "{$checks} fee assignment checks passed." . PHP_EOL);
