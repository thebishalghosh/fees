<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/examination-results-service.php';

$checks = 0;
$failures = [];
$check = static function (bool $condition, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $label;
    }
};

$check(examination_results_validate_marks('0', '100') === '0.00', 'Accept zero marks and normalize decimals');
$check(examination_results_validate_marks('075.5', '100') === '75.50', 'Normalize marks with leading zeroes');
$check(examination_results_percentage('45.00', '60.00') === '75.00', 'Calculate percentage from marks and maximum');

foreach (['-1', '100.01', 'not-a-mark', '1.234', '1000000'] as $invalidMark) {
    $rejected = false;
    try {
        examination_results_validate_marks($invalidMark, '100.00');
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    $check($rejected, 'Reject invalid or out-of-range marks: ' . $invalidMark);
}

$validExam = examination_results_validate_exam_input([
    'name' => 'Mid-term',
    'exam_date' => '2026-10-10',
    'batch_id' => '12',
    'academic_year_id' => '3',
    'max_marks' => '80',
    'description' => '',
]);
$check(
    $validExam['name'] === 'Mid-term'
        && $validExam['batch_id'] === 12
        && $validExam['academic_year_id'] === 3
        && $validExam['max_marks'] === '80.00',
    'Validate and normalize required examination fields'
);

$invalidExamRejected = false;
try {
    examination_results_validate_exam_input([
        'name' => 'Bad date',
        'exam_date' => '2026-02-30',
        'batch_id' => '12',
        'academic_year_id' => '3',
        'max_marks' => '0',
    ]);
} catch (InvalidArgumentException) {
    $invalidExamRejected = true;
}
$check($invalidExamRejected, 'Reject invalid dates and zero maximum marks');

$service = file_get_contents(__DIR__ . '/../services/examination-results-service.php');
$adminExams = file_get_contents(__DIR__ . '/../admin/academics/examinations.php');
$adminResults = file_get_contents(__DIR__ . '/../admin/academics/results.php');
$parentResults = file_get_contents(__DIR__ . '/../parent/academics/examinations.php');
$schema = file_get_contents(__DIR__ . '/../database/schema.sql');
$migration = file_get_contents(__DIR__ . '/../database/migrations/20261014_add_examination_results.sql');
$readable = is_string($service)
    && is_string($adminExams)
    && is_string($adminResults)
    && is_string($parentResults)
    && is_string($schema)
    && is_string($migration);
$check($readable, 'Read examination workflows and database schema');

if ($readable) {
    $check(
        str_contains($adminExams, 'require_placeholder_route_access()')
            && str_contains($adminExams, 'require_valid_csrf_token()')
            && str_contains($adminExams, 'admin_panel_institution_id()'),
        'Require admin permission, institution scope, and CSRF validation'
    );
    $check(
        str_contains($adminResults, 'require_placeholder_route_access()')
            && str_contains($adminResults, 'require_valid_csrf_token()')
            && str_contains($service, 'WHERE institution_id = ? AND id = ?'),
        'Scope result entry and examination lookup to the authorized institution'
    );
    $check(
        str_contains($service, 'psr.can_view_academics = 1')
            && str_contains($service, 'psr.status = \'active\'')
            && str_contains($service, 'WHERE er.institution_id = ? AND er.student_id = ?')
            && str_contains($service, 'e.results_published_at IS NOT NULL')
            && str_contains($parentResults, 'parent_portal_require_current_parent'),
        'Restrict parent results to linked students with academic access and published results'
    );
    $check(
        str_contains($service, 'ON DUPLICATE KEY UPDATE')
            && str_contains($schema, 'UNIQUE KEY uq_examination_results_student (institution_id, examination_id, student_id)')
            && str_contains($migration, 'UNIQUE KEY uq_examination_results_student (institution_id, examination_id, student_id)'),
        'Prevent duplicate result rows using idempotent updates and a database uniqueness constraint'
    );
    $check(
        !str_contains($service, 'payment_orders')
            && !str_contains($service, 'payments')
            && !str_contains($service, 'Razorpay'),
        'Keep examination results isolated from payment processing'
    );
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "{$checks} examination result checks passed." . PHP_EOL);
