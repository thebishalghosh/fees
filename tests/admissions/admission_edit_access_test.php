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

$view = file_get_contents(__DIR__ . '/../../admin/admissions/view.php');
$edit = file_get_contents(__DIR__ . '/../../admin/admissions/edit.php');
$list = file_get_contents(__DIR__ . '/../../admin/admissions/index.php');
$store = file_get_contents(__DIR__ . '/../../admin/admissions/store.php');
$form = file_get_contents(__DIR__ . '/../../includes/components/admission-application-form.php');
$service = file_get_contents(__DIR__ . '/../../services/admission-workflow-service.php');

$check(
    is_string($view) && is_string($edit) && is_string($list) && is_string($store)
        && is_string($form) && is_string($service),
    'Read admission edit route, form, and service'
);

if (is_string($view) && is_string($edit) && is_string($list) && is_string($store)
    && is_string($form) && is_string($service)) {
    $check(
        str_contains($view, "\$application['status'] !== 'enrolled'")
            && str_contains($list, "\$application['status'] !== 'enrolled' && \$canEdit"),
        'Show edit access for permitted users on non-enrolled applications'
    );
    $check(
        str_contains($edit, "\$application['status'] === 'enrolled'")
            && str_contains($form, 'value="save_changes"')
            && str_contains($store, "'save_changes' => 'save_changes'"),
        'Keep enrolled records locked and route non-enrolled edits to the save-changes action'
    );
    $check(
        str_contains($service, "['draft', 'submitted', 'save_changes']")
            && str_contains($service, "array_diff(\$columns, ['status', 'submitted_at'])")
            && str_contains($service, "'details_updated'"),
        'Preserve application status and submission time while auditing admin detail edits'
    );
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "{$checks} admission edit-access checks passed." . PHP_EOL);
