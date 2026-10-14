<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../includes/components/parent-profile-form.php';
require_once __DIR__ . '/../../services/parent-management-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$institutionId = parent_management_institution_id();
$parentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!is_int($parentId) || $parentId < 1) {
    http_response_code(404);
    exit('Parent not found.');
}
$db = db_connect();
$parent = parent_management_get_parent($db, $institutionId, $parentId);
if ($parent === null) {
    http_response_code(404);
    exit('Parent not found.');
}
$students = parent_management_get_student_options($db, $institutionId, $parent['linked_student_ids']);
$old = $_SESSION['parent_management_old'] ?? [];
if (!is_array($old) || ($old['form'] ?? null) !== 'edit' || (int) ($old['parent_id'] ?? 0) !== $parentId) {
    $old = [];
} else {
    unset($_SESSION['parent_management_old']);
}

render_app_page_start(
    'Edit Parent',
    'Update this parent profile and its student relationships.',
    'admin',
    ['admin-parents.css']
);
?>
<?php echo render_flash_messages(); ?>
<nav aria-label="Breadcrumb">
    <ol class="breadcrumb parents-breadcrumb">
        <li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/parents/index')); ?>">Parents</a></li>
        <li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/parents/view') . '?id=' . $parentId); ?>"><?php echo e($parent['full_name']); ?></a></li>
        <li class="breadcrumb-item active" aria-current="page">Edit</li>
    </ol>
</nav>
<section class="parents-form-panel" aria-labelledby="parent-form-title">
    <div class="parents-form-heading">
        <div>
            <span class="parents-record-reference">Parent #<?php echo e((string) $parent['id']); ?></span>
            <h2 id="parent-form-title">Parent details</h2>
            <p>Changes are limited to this parent account and students in your institution.</p>
        </div>
    </div>
    <?php render_parent_profile_form('/admin/parents/update?id=' . $parentId, $parent, $students, $old, true); ?>
</section>
<?php render_app_page_end('admin'); ?>
