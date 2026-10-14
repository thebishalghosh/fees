<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/parent-management-service.php';

require_post();
require_placeholder_route_access();
require_valid_csrf_token();

$user = current_user();
$institutionId = parent_management_institution_id();
$parentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!is_int($parentId) || $parentId < 1) {
    set_flash('danger', 'The parent record was not found.');
    safe_redirect(route_url('/admin/parents/index'));
}

$db = db_connect();
$existingParent = parent_management_get_parent($db, $institutionId, $parentId);
if ($existingParent === null) {
    set_flash('danger', 'The parent record was not found in your institution.');
    safe_redirect(route_url('/admin/parents/index'));
}

$profileResult = parent_management_validate_profile($_POST);
$errors = $profileResult['errors'];
foreach ([
    'alternate_mobile',
    'address_line1',
    'address_line2',
    'city',
    'state',
    'postal_code',
    'country',
    'emergency_contact',
] as $optionalField) {
    if (!array_key_exists($optionalField, $_POST)) {
        $profileResult['data'][$optionalField] = $existingParent[$optionalField]
            ?? ($optionalField === 'country' ? 'India' : null);
    }
}
try {
    $studentIds = ($_POST['manage_links'] ?? null) === '1'
        ? parent_management_student_ids($_POST['student_ids'] ?? [])
        : $existingParent['linked_student_ids'];
} catch (InvalidArgumentException $exception) {
    $studentIds = $existingParent['linked_student_ids'];
    $errors[] = $exception->getMessage();
}

if ($errors !== []) {
    $_SESSION['parent_management_old'] = parent_management_old_form_values($_POST, 'edit', $parentId);
    if (!isset($_POST['student_ids'])) {
        $_SESSION['parent_management_old']['student_ids'] = $existingParent['linked_student_ids'];
    }
    set_flash('danger', implode(' ', $errors));
    safe_redirect(route_url('/admin/parents/edit') . '?id=' . $parentId);
}

try {
    parent_management_update(
        $db,
        $institutionId,
        $parentId,
        (int) $user['id'],
        (string) $user['user_type'],
        $profileResult['data'],
        $studentIds
    );
    set_flash('success', 'Parent profile updated. Any changed email address must be verified again.');
    safe_redirect(route_url('/admin/parents/view') . '?id=' . $parentId);
} catch (Throwable $exception) {
    $_SESSION['parent_management_old'] = parent_management_old_form_values($_POST, 'edit', $parentId);
    if (!isset($_POST['student_ids'])) {
        $_SESSION['parent_management_old']['student_ids'] = $existingParent['linked_student_ids'];
    }
    parent_management_handle_failure($exception, 'update');
    safe_redirect(route_url('/admin/parents/edit') . '?id=' . $parentId);
}
