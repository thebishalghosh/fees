<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../includes/components/parent-profile-form.php';
require_once __DIR__ . '/../../services/parent-management-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$institutionId = parent_management_institution_id();
$db = db_connect();
$students = parent_management_get_student_options($db, $institutionId);
$old = $_SESSION['parent_management_old'] ?? [];
if (!is_array($old) || ($old['form'] ?? null) !== 'create') {
    $old = [];
} else {
    unset($_SESSION['parent_management_old']);
}
render_app_page_start(
    'Add Parent',
    'Create a parent profile and optionally link eligible students.',
    'admin',
    ['admin-parents.css']
);
?>
<?php echo render_flash_messages(); ?>
<nav aria-label="Breadcrumb">
    <ol class="breadcrumb parents-breadcrumb">
        <li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/parents/index')); ?>">Parents</a></li>
        <li class="breadcrumb-item active" aria-current="page">Add Parent</li>
    </ol>
</nav>
<section class="parents-form-panel" aria-labelledby="parent-form-title">
    <div class="parents-form-heading">
        <div>
            <span class="parents-demo-banner"><i class="bi bi-database" aria-hidden="true"></i> Live institution records</span>
            <h2 id="parent-form-title">Parent details</h2>
            <p>New accounts remain unverified until the approved email verification process is completed.</p>
        </div>
    </div>
    <?php render_parent_profile_form('/admin/parents/store', [], $students, $old, false); ?>
</section>
<?php render_app_page_end('admin'); ?>
