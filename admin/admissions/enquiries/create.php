<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/page-placeholder.php';
require_once __DIR__ . '/../../../services/admission-workflow-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
if (!user_has_compatible_permission('admissions.create', 'admissions.manage')) {
    http_response_code(403);
    exit('Forbidden.');
}
$institutionId = authenticated_institution_id(['super_admin', 'admin', 'staff']);
if ($institutionId === null) {
    http_response_code(403);
    exit('Institution access is unavailable for this account.');
}
$db = db_connect();
if (!admission_workflow_schema_ready($db)) {
    http_response_code(503);
    exit('Apply database/migrations/20261113_add_admission_workflow.sql before creating enquiries.');
}
$options = admission_workflow_options($db, $institutionId);
$old = $_SESSION['admission_enquiry_old'] ?? [];
unset($_SESSION['admission_enquiry_old']);
if (!is_array($old)) {
    $old = [];
}

render_app_page_start('New Admission Enquiry', 'Record an applicant and schedule follow-up.', 'admin', ['admin-dashboard.css']);
?>
<?php echo render_flash_messages(); ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/admissions/index')); ?>">Admissions</a></li><li class="breadcrumb-item active" aria-current="page">New enquiry</li></ol></nav>
<section class="dashboard-panel">
    <form method="post" action="<?php echo e(route_url('/admin/admissions/enquiries/store')); ?>" class="row g-3">
        <?php echo csrf_field(); ?>
        <div class="col-md-6"><label class="form-label" for="enquiry-applicant">Applicant / parent name *</label><input class="form-control" id="enquiry-applicant" name="applicant_name" maxlength="150" required value="<?php echo e($old['applicant_name'] ?? ''); ?>"></div>
        <div class="col-md-6"><label class="form-label" for="enquiry-student">Student name *</label><input class="form-control" id="enquiry-student" name="student_name" maxlength="150" required value="<?php echo e($old['student_name'] ?? ''); ?>"></div>
        <div class="col-md-4"><label class="form-label" for="enquiry-mobile">Contact mobile *</label><input class="form-control" id="enquiry-mobile" name="mobile" type="tel" maxlength="20" required value="<?php echo e($old['mobile'] ?? ''); ?>"></div>
        <div class="col-md-4"><label class="form-label" for="enquiry-email">Email</label><input class="form-control" id="enquiry-email" name="email" type="email" maxlength="255" value="<?php echo e($old['email'] ?? ''); ?>"></div>
        <div class="col-md-4"><label class="form-label" for="enquiry-relationship">Relationship</label><select class="form-select" id="enquiry-relationship" name="relationship_to_student"><option value="">Select</option><?php foreach (['father','mother','guardian','other'] as $relationship): ?><option value="<?php echo e($relationship); ?>"<?php echo ($old['relationship_to_student'] ?? '') === $relationship ? ' selected' : ''; ?>><?php echo e(ucfirst($relationship)); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label" for="enquiry-year">Interested academic year</label><select class="form-select" id="enquiry-year" name="academic_year_id"><option value="">Select year</option><?php foreach ($options['academic_years'] as $year): ?><option value="<?php echo e((string) $year['id']); ?>"<?php echo (string) ($old['academic_year_id'] ?? '') === (string) $year['id'] ? ' selected' : ''; ?>><?php echo e($year['name']); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label" for="enquiry-course">Course / programme</label><select class="form-select" id="enquiry-course" name="course_id"><option value="">Select course</option><?php foreach ($options['courses'] as $course): ?><option value="<?php echo e((string) $course['id']); ?>"<?php echo (string) ($old['course_id'] ?? '') === (string) $course['id'] ? ' selected' : ''; ?>><?php echo e($course['name']); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label" for="enquiry-class">Class</label><select class="form-select" id="enquiry-class" name="class_id"><option value="">Select class</option><?php foreach ($options['classes'] as $class): ?><option value="<?php echo e((string) $class['id']); ?>"<?php echo (string) ($old['class_id'] ?? '') === (string) $class['id'] ? ' selected' : ''; ?>><?php echo e($class['name']); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label" for="enquiry-source">Enquiry source</label><input class="form-control" id="enquiry-source" name="source" maxlength="80" placeholder="Walk-in, referral, web..." value="<?php echo e($old['source'] ?? ''); ?>"></div>
        <div class="col-md-4"><label class="form-label" for="enquiry-status">Status</label><select class="form-select" id="enquiry-status" name="status"><?php foreach (['new' => 'New', 'contacted' => 'Contacted', 'follow_up' => 'Follow-up', 'closed' => 'Closed'] as $value => $label): ?><option value="<?php echo e($value); ?>"<?php echo ($old['status'] ?? 'new') === $value ? ' selected' : ''; ?>><?php echo e($label); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label" for="follow-up-date">Follow-up date</label><input class="form-control" id="follow-up-date" name="follow_up_date" type="date" value="<?php echo e($old['follow_up_date'] ?? ''); ?>"></div>
        <div class="col-12"><label class="form-label" for="enquiry-notes">Follow-up notes</label><textarea class="form-control" id="enquiry-notes" name="notes" rows="4" maxlength="20000"><?php echo e($old['notes'] ?? ''); ?></textarea></div>
        <div class="col-12"><button class="btn btn-primary" type="submit">Save enquiry</button><a class="btn btn-link" href="<?php echo e(route_url('/admin/admissions/index')); ?>">Cancel</a></div>
    </form>
</section>
<?php render_app_page_end('admin'); ?>
