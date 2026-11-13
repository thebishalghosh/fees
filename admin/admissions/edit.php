<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../includes/components/admission-application-form.php';
require_once __DIR__ . '/../../services/admission-workflow-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
if (!user_has_compatible_permission('admissions.edit', 'admissions.manage')) {
    http_response_code(403);
    exit('Forbidden.');
}
$institutionId = authenticated_institution_id(['super_admin', 'admin', 'staff']);
$admissionId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($institutionId === null || !is_int($admissionId) || $admissionId < 1) {
    http_response_code(404);
    exit('Admission application not found.');
}
$db = db_connect();
if (!admission_workflow_schema_ready($db)) {
    http_response_code(503);
    exit('Apply database/migrations/20261113_add_admission_workflow.sql before editing applications.');
}
$application = admission_workflow_get_application($db, $institutionId, $admissionId);
if ($application === null) {
    http_response_code(404);
    exit('Admission application not found.');
}
if ($application['status'] === 'enrolled') {
    http_response_code(409);
    exit('Enrolled applications cannot be edited.');
}
$preserveStatus = !in_array($application['status'], ['draft', 'information_requested'], true);
$options = admission_workflow_options($db, $institutionId);
$groups = admission_workflow_group_options($db, $institutionId);
$old = $_SESSION['admission_workflow_old'] ?? [];
unset($_SESSION['admission_workflow_old']);
if (is_array($old)) {
    $application = array_replace($application, $old);
}

render_app_page_start('Edit Admission Application', 'Update the application before resubmitting it.', 'admin', ['admin-dashboard.css']);
?>
<?php echo render_flash_messages(); ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/admissions/index')); ?>">Admissions</a></li><li class="breadcrumb-item active" aria-current="page"><?php echo e($application['application_reference'] ?? ('Application #' . $admissionId)); ?></li></ol></nav>
<section class="dashboard-panel">
    <div class="d-flex justify-content-between align-items-start"><div><p class="text-muted mb-1">Reference <?php echo e($application['application_reference'] ?? 'Pending reference'); ?></p><h2 class="h4">Application details</h2></div><span class="badge text-bg-warning"><?php echo e(ucwords(str_replace('_', ' ', $application['status']))); ?></span></div>
    <?php render_admission_application_form($application, $options, $groups, true, $preserveStatus); ?>
</section>
<?php render_app_page_end('admin'); ?>
