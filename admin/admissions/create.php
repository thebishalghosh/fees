<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../includes/components/admission-application-form.php';
require_once __DIR__ . '/../../services/admission-workflow-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
if (!user_has_compatible_permission('admissions.create', 'admissions.manage')) {
    http_response_code(403);
    exit('Forbidden.');
}
$db = db_connect();
$institutionId = authenticated_institution_id(['super_admin', 'admin', 'staff']);
if ($institutionId === null) {
    http_response_code(403);
    exit('Institution access is unavailable for this account.');
}
if (!admission_workflow_schema_ready($db)) {
    http_response_code(503);
    exit('Apply database/migrations/20261113_add_admission_workflow.sql before creating applications.');
}
$old = $_SESSION['admission_workflow_old'] ?? [];
unset($_SESSION['admission_workflow_old']);
$application = is_array($old) ? $old : [];
$options = admission_workflow_options($db, $institutionId);
$groups = admission_workflow_group_options($db, $institutionId);

render_app_page_start('New Admission Application', 'Capture student, parent, and academic details.', 'admin', ['admin-dashboard.css']);
?>
<?php echo render_flash_messages(); ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/admissions/index')); ?>">Admissions</a></li><li class="breadcrumb-item active" aria-current="page">New application</li></ol></nav>
<section class="dashboard-panel">
    <p class="text-muted">Save a draft to continue later. An application reference is assigned automatically when the record is saved.</p>
    <?php render_admission_application_form($application, $options, $groups, false); ?>
</section>
<?php render_app_page_end('admin'); ?>
