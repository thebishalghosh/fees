<?php
declare(strict_types=1);

$checks = 0;
$failures = [];
$check = static function (bool $condition, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $label;
    }
};

$service = file_get_contents(__DIR__ . '/../../services/admission-conversion-service.php');
$view = file_get_contents(__DIR__ . '/../../admin/admissions/view.php');
$list = file_get_contents(__DIR__ . '/../../admin/admissions/index.php');
$auth = file_get_contents(__DIR__ . '/../../includes/auth.php');
$sidebar = file_get_contents(__DIR__ . '/../../includes/layouts/sidebar.php');
$schema = file_get_contents(__DIR__ . '/../../database/schema.sql');
$migration = file_get_contents(__DIR__ . '/../../database/migrations/20261104_add_admission_enrolment_conversion.sql');
$check(
    is_string($service) && is_string($view) && is_string($list) && is_string($auth)
        && is_string($sidebar) && is_string($schema) && is_string($migration),
    'Read admission service, UI, routing, and schema sources'
);

if (is_string($service) && is_string($view) && is_string($list) && is_string($auth)
    && is_string($sidebar) && is_string($schema) && is_string($migration)) {
    $check(
        str_contains($service, 'begin_transaction()')
            && str_contains($service, 'FROM admissions WHERE institution_id = ? AND id = ? FOR UPDATE')
            && str_contains($service, 'active_academic_year_id')
            && str_contains($service, 'academic_management_validate_group_for_model')
            && str_contains($service, 'student_management_sync_parents')
            && str_contains($service, 'already has an enrolment in the selected academic year'),
        'Lock and validate tenant-scoped application, academic placement, parents, and duplicate enrolments'
    );
    $check(
        str_contains($service, "bind_param('ssisii'")
            && str_contains($service, "'iiiiiiiiisii'")
            && str_contains($service, "status = \\'approved\\'")
            && str_contains($service, 'student_enrolment_id IS NULL')
            && str_contains($service, 'write_audit_log(')
            && str_contains($service, '$db->rollback()'),
        'Use correct parameter bindings and make conversion, application update, audit, and rollback transactional'
    );
    $check(
        str_contains($view, "require_http_methods(['GET', 'POST'])")
            && str_contains($view, 'require_valid_csrf_token()')
            && str_contains($view, "'admissions.approve'")
            && str_contains($view, "'students.create'")
            && str_contains($view, 'academic_group_id')
            && str_contains($view, 'Open student')
            && str_contains($view, 'Database update required')
            && str_contains($list, 'admission_conversion_list('),
        'Protect admission review and conversion actions and link migrated schema guidance'
    );
    $check(
        str_contains($auth, "'convert' => ['admissions.approve', 'admissions.manage']")
            && str_contains($auth, "'reject' => ['admissions.reject', 'admissions.manage']")
            && str_contains($sidebar, "'Admissions', '/admin/admissions/index'"),
        'Route approvals, rejections, and conversions through matching permission checks and navigation'
    );
    $check(
        str_contains($schema, 'uq_admissions_enrolment_conversion')
            && str_contains($schema, 'uq_admissions_placement_conversion')
            && str_contains($schema, 'fk_admissions_conversion_enrolment')
            && str_contains($schema, 'fk_admissions_conversion_placement')
            && str_contains($migration, 'student_enrolment_placements(institution_id, academic_year_id,')
            && str_contains($migration, 'ON DELETE RESTRICT'),
        'Enforce unique conversion references and tenant-safe composite foreign keys in fresh and migrated schemas'
    );
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "{$checks} admission conversion checks passed." . PHP_EOL);
