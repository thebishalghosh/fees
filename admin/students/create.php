<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../includes/components/student-profile-form.php';
require_once __DIR__ . '/../../services/student-management-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$institutionId = student_management_institution_id();
$options = student_management_get_options(db_connect(), $institutionId);
$old = $_SESSION['student_management_old'] ?? [];
if (!is_array($old) || ($old['form'] ?? null) !== 'create') {
    $old = [];
} else {
    unset($_SESSION['student_management_old']);
}

render_app_page_start('Add Student', 'Create a student record for this institution.', 'admin', ['admin-students.css']);
?>
<?php echo render_flash_messages(); ?>
<nav aria-label="Breadcrumb">
    <ol class="breadcrumb students-breadcrumb">
        <li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/students/index')); ?>">Students</a></li>
        <li class="breadcrumb-item active" aria-current="page">Add Student</li>
    </ol>
</nav>
<section class="students-form-panel" aria-labelledby="student-form-title">
    <div class="students-form-heading">
        <div><span class="students-record-reference">New institution record</span><h2 id="student-form-title">Student profile</h2><p>Student IDs are unique within this institution. No separate student login is created.</p></div>
    </div>
    <?php render_student_profile_form('/admin/students/store', [], $options, $old, false); ?>
</section>
<?php render_app_page_end('admin'); ?>
