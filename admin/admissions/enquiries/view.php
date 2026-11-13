<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/page-placeholder.php';
require_once __DIR__ . '/../../../services/admission-workflow-service.php';

require_http_methods(['GET', 'POST']);
require_placeholder_route_access();
$institutionId = authenticated_institution_id(['super_admin', 'admin', 'staff']);
$actor = current_user();
$enquiryId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($institutionId === null || $actor === null || !is_int($enquiryId) || $enquiryId < 1) {
    http_response_code(404);
    exit('Enquiry not found.');
}
$db = db_connect();
if (!admission_workflow_schema_ready($db)) {
    http_response_code(503);
    exit('Apply database/migrations/20261113_add_admission_workflow.sql before managing enquiries.');
}
$enquiry = admission_workflow_get_enquiry($db, $institutionId, $enquiryId);
if ($enquiry === null) {
    http_response_code(404);
    exit('Enquiry not found.');
}
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    try {
        if (($_POST['action'] ?? null) === 'convert') {
            if (!user_has_compatible_permission('admissions.create', 'admissions.manage')) {
                http_response_code(403);
                exit('Forbidden.');
            }
            $admissionId = admission_workflow_convert_enquiry(
                $db,
                $institutionId,
                (int) $actor['id'],
                (string) $actor['user_type'],
                $enquiryId
            );
            set_flash('success', 'Enquiry converted to a draft application.');
            safe_redirect('/admin/admissions/edit?id=' . $admissionId, 303);
        }
        if (!user_has_compatible_permission('admissions.edit', 'admissions.manage')) {
            http_response_code(403);
            exit('Forbidden.');
        }
        admission_workflow_update_enquiry(
            $db,
            $institutionId,
            (int) $actor['id'],
            (string) $actor['user_type'],
            $enquiryId,
            $_POST
        );
        set_flash('success', 'Enquiry follow-up updated.');
        safe_redirect('/admin/admissions/enquiries/view?id=' . $enquiryId, 303);
    } catch (InvalidArgumentException | DomainException | OutOfBoundsException $exception) {
        $error = $exception->getMessage();
        $enquiry = array_replace($enquiry, array_intersect_key($_POST, $enquiry));
    } catch (Throwable $exception) {
        log_error('admission_enquiry_action_failed', [
            'operation' => 'update',
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
        ]);
        $error = 'The enquiry could not be updated.';
    }
}
$options = admission_workflow_options($db, $institutionId);

render_app_page_start('Admission Enquiry', 'Review and update enquiry follow-up details.', 'admin', ['admin-dashboard.css']);
?>
<?php echo render_flash_messages(); ?>
<?php if ($error !== null): ?><div class="alert alert-danger" role="alert"><?php echo e($error); ?></div><?php endif; ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/admissions/index')); ?>">Admissions</a></li><li class="breadcrumb-item active" aria-current="page"><?php echo e($enquiry['reference_number']); ?></li></ol></nav>
<section class="dashboard-panel">
    <div class="d-flex justify-content-between align-items-start gap-2"><div><p class="text-muted mb-1">Enquiry reference</p><h2 class="h4"><?php echo e($enquiry['reference_number']); ?></h2></div><span class="badge text-bg-secondary"><?php echo e(ucwords(str_replace('_', ' ', $enquiry['status']))); ?></span></div>
    <?php if ($enquiry['admission_id'] !== null): ?>
        <div class="alert alert-success">Converted to application <a href="<?php echo e(route_url('/admin/admissions/view?id=' . (int) $enquiry['admission_id'])); ?>">#<?php echo e((string) $enquiry['admission_id']); ?></a></div>
    <?php else: ?>
        <form method="post" class="row g-3 mt-2">
            <?php echo csrf_field(); ?>
            <div class="col-md-6"><label class="form-label">Applicant / parent</label><input class="form-control" name="applicant_name" maxlength="150" required value="<?php echo e($enquiry['applicant_name']); ?>"></div>
            <div class="col-md-6"><label class="form-label">Student</label><input class="form-control" name="student_name" maxlength="150" required value="<?php echo e($enquiry['student_name']); ?>"></div>
            <div class="col-md-4"><label class="form-label">Mobile</label><input class="form-control" name="mobile" maxlength="20" required value="<?php echo e($enquiry['mobile']); ?>"></div>
            <div class="col-md-4"><label class="form-label">Email</label><input class="form-control" name="email" type="email" maxlength="255" value="<?php echo e($enquiry['email']); ?>"></div>
            <div class="col-md-4"><label class="form-label">Relationship</label><input class="form-control" name="relationship_to_student" maxlength="80" value="<?php echo e($enquiry['relationship_to_student']); ?>"></div>
            <div class="col-md-4"><label class="form-label">Academic year</label><select class="form-select" name="academic_year_id"><option value="">Select</option><?php foreach ($options['academic_years'] as $year): ?><option value="<?php echo e((string) $year['id']); ?>"<?php echo (string) $enquiry['academic_year_id'] === (string) $year['id'] ? ' selected' : ''; ?>><?php echo e($year['name']); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label">Course</label><select class="form-select" name="course_id"><option value="">Select</option><?php foreach ($options['courses'] as $course): ?><option value="<?php echo e((string) $course['id']); ?>"<?php echo (string) $enquiry['course_id'] === (string) $course['id'] ? ' selected' : ''; ?>><?php echo e($course['name']); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label">Class</label><select class="form-select" name="class_id"><option value="">Select</option><?php foreach ($options['classes'] as $class): ?><option value="<?php echo e((string) $class['id']); ?>"<?php echo (string) $enquiry['class_id'] === (string) $class['id'] ? ' selected' : ''; ?>><?php echo e($class['name']); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label">Source</label><input class="form-control" name="source" maxlength="80" value="<?php echo e($enquiry['source']); ?>"></div>
            <div class="col-md-4"><label class="form-label">Status</label><select class="form-select" name="status"><?php foreach (['new','contacted','follow_up','closed'] as $status): ?><option value="<?php echo e($status); ?>"<?php echo $enquiry['status'] === $status ? ' selected' : ''; ?>><?php echo e(ucwords(str_replace('_', ' ', $status))); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label">Follow-up date</label><input class="form-control" type="date" name="follow_up_date" value="<?php echo e($enquiry['follow_up_date']); ?>"></div>
            <div class="col-12"><label class="form-label">Follow-up notes</label><textarea class="form-control" name="notes" rows="4" maxlength="20000"><?php echo e($enquiry['notes']); ?></textarea></div>
            <div class="col-12 d-flex gap-2"><button class="btn btn-primary" type="submit" name="action" value="update">Save follow-up</button><button class="btn btn-success" type="submit" name="action" value="convert">Convert to application draft</button></div>
        </form>
    <?php endif; ?>
</section>
<?php render_app_page_end('admin'); ?>
