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
$globalWhere = parent_management_prepare_list_filters(null, '', '', '');
$check($globalWhere === ['', '', []], 'Allow a global parent list only when no institution filter is supplied');

$serviceSource = file_get_contents(__DIR__ . '/../services/parent-management-service.php');
$auditSource = file_get_contents(__DIR__ . '/../services/audit-service.php');
$createSource = file_get_contents(__DIR__ . '/../admin/parents/store.php');
$updateSource = file_get_contents(__DIR__ . '/../admin/parents/update.php');
$toggleSource = file_get_contents(__DIR__ . '/../admin/parents/toggle-status.php');
$indexSource = file_get_contents(__DIR__ . '/../admin/parents/index.php');
$viewSource = file_get_contents(__DIR__ . '/../admin/parents/view.php');
$editSource = file_get_contents(__DIR__ . '/../admin/parents/edit.php');
$updatePageSource = file_get_contents(__DIR__ . '/../admin/parents/update.php');
$togglePageSource = file_get_contents(__DIR__ . '/../admin/parents/toggle-status.php');
$verificationPageSource = file_get_contents(__DIR__ . '/../admin/parents/send-verification.php');
$formSource = file_get_contents(__DIR__ . '/../includes/components/parent-profile-form.php');
$allSourcesReadable = is_string($serviceSource)
    && is_string($auditSource)
    && is_string($createSource)
    && is_string($updateSource)
    && is_string($toggleSource)
    && is_string($indexSource)
    && is_string($viewSource)
    && is_string($editSource)
    && is_string($updatePageSource)
    && is_string($togglePageSource)
    && is_string($verificationPageSource)
    && is_string($formSource);
$check($allSourcesReadable, 'Read parent workflow sources for static safety checks');
if ($allSourcesReadable) {
    $check(str_contains($serviceSource, 'WHERE p.institution_id = ?'), 'Keep parent lookups institution-scoped');
    $check(str_contains($serviceSource, 'WHERE s.institution_id = ?'), 'Keep student links institution-scoped');
    $check(str_contains($serviceSource, 'SELECT id FROM users WHERE institution_id = ? AND (email = ? OR mobile = ?)'), 'Check duplicate contact details within the institution');
    $check(str_contains($serviceSource, 'SET relationship_type = ?, status ='), 'Keep existing linked-student relationships synchronized');
    $linkSyncStart = strpos($serviceSource, 'function parent_management_sync_links(');
    $linkAuditStart = strpos($serviceSource, 'function parent_management_audit_values(');
    $linkSyncSource = $linkSyncStart !== false && $linkAuditStart !== false
        ? substr($serviceSource, $linkSyncStart, $linkAuditStart - $linkSyncStart)
        : '';
    $check(str_contains($linkSyncSource, 'revoked') && !str_contains($linkSyncSource, 'DELETE FROM parent_student_relationships'), 'Revoke ordinary profile relationships without deleting history');
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
    $check(str_contains($serviceSource, 'function parent_management_resolve_parent_institution_id(')
        && str_contains($serviceSource, 'if (!parent_management_is_super_admin())')
        && str_contains($serviceSource, 'JOIN institutions i ON i.id = p.institution_id'), 'Resolve tenant context for global Super Admin parent rows');
    $check(str_contains($indexSource, '$isSuperAdmin ? null : $institutionId')
        && str_contains($indexSource, '$parent[\'institution_name\']'), 'List all institutions’ parents for Super Admin and identify each tenant');
    $check(str_contains($viewSource, 'parent_management_resolve_parent_institution_id')
        && str_contains($editSource, 'parent_management_resolve_parent_institution_id')
        && str_contains($updatePageSource, 'parent_management_resolve_parent_institution_id')
        && str_contains($togglePageSource, 'parent_management_resolve_parent_institution_id')
        && str_contains($verificationPageSource, 'parent_management_resolve_parent_institution_id'), 'Keep Super Admin parent actions tied to each parent’s institution');
    $check(str_contains($editSource, 'parent_management_is_super_admin()') && str_contains($formSource, 'Institution Affiliation'), 'Show affiliation controls only to system Super Admins');
    $check(str_contains($updateSource, 'require_valid_csrf_token()') && str_contains($updateSource, 'confirm_affiliation'), 'Require CSRF and explicit confirmation for affiliation changes');
    $check(str_contains($serviceSource, 'parent_management_transfer_affiliation(')
        && str_contains($serviceSource, "'relationship_records' => \$relationships")
        && str_contains($serviceSource, 'parent.institution_affiliation_corrected'), 'Transfer affiliations transactionally with an auditable relationship snapshot');
}

if ($failures !== []) {
    fwrite(STDERR, 'FAIL: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'PASS: ' . $checks . ' parent management checks.' . PHP_EOL);
