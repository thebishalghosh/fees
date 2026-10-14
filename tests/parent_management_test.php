<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/parent-management-service.php';

$checks = 0;
$failures = [];
$check = static function (bool $condition, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $label;
    }
};

$validProfile = parent_management_validate_profile([
    'full_name' => '  Sample Parent  ',
    'email' => '  PARENT@example.test ',
    'mobile' => '+91 (90000) 00012',
    'relationship_to_student' => 'Mother',
]);
$check($validProfile['errors'] === [], 'Accept valid parent details');
$check($validProfile['data']['full_name'] === 'Sample Parent', 'Trim the parent name');
$check($validProfile['data']['email'] === 'parent@example.test', 'Normalize parent email case and whitespace');
$check($validProfile['data']['mobile'] === '+919000000012', 'Normalize supported mobile formatting');
$check($validProfile['data']['relationship_to_student'] === 'mother', 'Normalize supported relationship values');
$check($validProfile['data']['country'] === 'India', 'Use the schema-supported country default');

$invalidProfile = parent_management_validate_profile([
    'full_name' => '',
    'email' => 'not-an-email',
    'mobile' => '12',
    'relationship_to_student' => 'administrator',
]);
$check(count($invalidProfile['errors']) === 4, 'Reject missing or invalid required profile values');

$check(parent_management_student_ids(['12', 12, '15']) === [12, 15], 'Normalize and deduplicate selected student IDs');
$check(parent_management_student_ids(null) === [], 'Treat an omitted student list as no selected students');
$invalidStudentIdsRejected = false;
try {
    parent_management_student_ids(['1 OR 1=1']);
} catch (InvalidArgumentException) {
    $invalidStudentIdsRejected = true;
}
$check($invalidStudentIdsRejected, 'Reject malformed student identifiers');

[$where, $types, $values] = parent_management_prepare_list_filters(
    34,
    'A_%!B',
    'active',
    'linked'
);
$check(str_contains($where, 'p.institution_id = ?'), 'Scope parent search to the authenticated institution');
$check(str_contains($where, 'p.status = ?'), 'Apply parent status filters in SQL');
$check(str_contains($where, 'EXISTS ('), 'Filter linked parents through active relationships');
$check(str_contains($where, "LIKE ? ESCAPE '!'"), 'Treat search wildcards as literal characters');
$check($types === 'issss' && count($values) === 5, 'Bind all dynamic parent filters with explicit parameter types');
$check($values[2] === '%A!_!%!!B%', 'Escape wildcard characters in parent search input');

$serviceSource = file_get_contents(__DIR__ . '/../services/parent-management-service.php');
$auditSource = file_get_contents(__DIR__ . '/../services/audit-service.php');
$createSource = file_get_contents(__DIR__ . '/../admin/parents/store.php');
$updateSource = file_get_contents(__DIR__ . '/../admin/parents/update.php');
$toggleSource = file_get_contents(__DIR__ . '/../admin/parents/toggle-status.php');
$indexSource = file_get_contents(__DIR__ . '/../admin/parents/index.php');
$allSourcesReadable = is_string($serviceSource)
    && is_string($auditSource)
    && is_string($createSource)
    && is_string($updateSource)
    && is_string($toggleSource)
    && is_string($indexSource);
$check($allSourcesReadable, 'Read parent workflow sources for static safety checks');
if ($allSourcesReadable) {
    $check(str_contains($serviceSource, 'WHERE p.institution_id = ?'), 'Keep parent lookups institution-scoped');
    $check(str_contains($serviceSource, 'WHERE s.institution_id = ?'), 'Keep student links institution-scoped');
    $check(str_contains($serviceSource, 'SELECT id FROM users WHERE institution_id = ? AND (email = ? OR mobile = ?)'), 'Check duplicate contact details within the institution');
    $check(str_contains($serviceSource, 'SET relationship_type = ?, status ='), 'Keep existing linked-student relationships synchronized');
    $check(str_contains($serviceSource, 'revoked') && !str_contains($serviceSource, 'DELETE FROM parent_student_relationships'), 'Revoke relationships without deleting history');
    $check(str_contains($serviceSource, 'email_verified_at = NULL'), 'Invalidate verification when the account email changes');
    $check(!str_contains($serviceSource, 'email_verified_at = CURRENT_TIMESTAMP'), 'Never auto-verify an account email');
    $check(str_contains($serviceSource, 'UPDATE user_sessions'), 'Invalidate active sessions on email change or deactivation');
    $check(str_contains($serviceSource, 'must be verified before the profile can be activated'), 'Require verified email before reactivating a parent profile');
    $check(str_contains($serviceSource, "in_array(\$parent['status'], ['active', 'pending_verification'], true)"), 'Deactivate unverified pending parents instead of offering an invalid activation');
    $check(str_contains($serviceSource, "purpose = 'email_verification'"), 'Use and invalidate email verification challenges on parent email changes');
    $check(str_contains($serviceSource, "r.name = \\'parent\\'"), 'Require the schema-defined parent role for parent records');
    $check(str_contains($auditSource, 'INSERT INTO audit_logs'), 'Write audit records for parent mutations');
    $check(str_contains($createSource, 'require_valid_csrf_token()'), 'Protect parent creation with CSRF validation');
    $check(str_contains($updateSource, 'require_valid_csrf_token()'), 'Protect parent edits with CSRF validation');
    $check(str_contains($toggleSource, 'require_valid_csrf_token()'), 'Protect status changes with CSRF validation');
    $check(str_contains($serviceSource, 'PARENT_MANAGEMENT_PAGE_SIZE'), 'Use server-side pagination for database results');
}

if ($failures !== []) {
    fwrite(STDERR, 'FAIL: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'PASS: ' . $checks . ' parent management checks.' . PHP_EOL);
