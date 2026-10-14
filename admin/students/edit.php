<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../includes/components/student-profile-form.php';
require_once __DIR__ . '/../../services/student-management-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$institutionId = student_management_institution_id();
$studentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!is_int($studentId) || $studentId < 1) {
    http_response_code(404);
    exit('Student not found.');
}
$db = db_connect();
$student = student_management_get_student($db, $institutionId, $studentId);
if ($student === null) {
    http_response_code(404);
    exit('Student not found.');
}
$options = student_management_get_options($db, $institutionId);
$old = $_SESSION['student_management_old'] ?? [];
if (!is_array($old) || ($old['form'] ?? null) !== 'edit' || (int) ($old['student_id'] ?? 0) !== $studentId) {
    $old = [];
} else {
    unset($_SESSION['student_management_old']);
}

render_app_page_start('Edit Student', 'Update this student profile and parent links.', 'admin', ['admin-students.css']);
?>
<?php echo render_flash_messages(); ?>
<nav aria-label="Breadcrumb">
    <ol class="breadcrumb students-breadcrumb">
        <li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/students/index')); ?>">Students</a></li>
        <li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/students/view') . '?id=' . $studentId); ?>"><?php echo e($student['full_name']); ?></a></li>
        <li class="breadcrumb-item active" aria-current="page">Edit</li>
    </ol>
</nav>
<section class="students-form-panel" aria-labelledby="student-form-title">
    <div class="students-form-heading">
        <div><span class="students-record-reference">Student #<?php echo e((string) $student['id']); ?></span><h2 id="student-form-title">Student profile</h2><p>Only this institution's student and linked parent relationships can be changed.</p></div>
    </div>
    <?php render_student_profile_form('/admin/students/update?id=' . $studentId, $student, $options, $old, true); ?>
</section>
<?php render_app_page_end('admin'); ?>
