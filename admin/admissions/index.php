<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/admission-conversion-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
if (!user_has_compatible_permission('admissions.view', 'admissions.manage')) {
    http_response_code(403);
    exit('Forbidden.');
}
$db = db_connect();
$institutionId = authenticated_institution_id(['super_admin', 'admin', 'staff']);
if ($institutionId === null) {
    http_response_code(403);
    exit('Institution access is unavailable for this account.');
}
$schemaReady = academic_management_schema_ready($db) && admission_conversion_schema_ready($db);
$applications = $schemaReady ? admission_conversion_list($db, $institutionId) : [];

render_app_page_start(
    'Admissions',
    'Review applications and convert approved students into academic enrolments.',
    'admin',
    ['admin-dashboard.css']
);
?>
<?php echo render_flash_messages(); ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item active" aria-current="page">Admissions</li></ol></nav>
<?php if (!$schemaReady): ?>
    <section class="dashboard-panel" role="status">
        <h2>Database update required</h2>
        <p>Admission conversion requires the academic-management schema and conversion-link migration.</p>
        <p>Back up the database, then apply <code>database/migrations/20261104_add_academic_management.sql</code> and <code>database/migrations/20261104_add_admission_enrolment_conversion.sql</code> using the project’s migration procedure.</p>
    </section>
<?php elseif ($applications === []): ?>
    <section class="dashboard-panel"><h2>No admission applications</h2><p>Submitted applications for this institution will appear here.</p></section>
<?php else: ?>
    <section class="dashboard-panel">
        <div class="table-responsive"><table class="table admin-panel-table align-middle">
            <thead><tr><th>Application</th><th>Applicant</th><th>Student</th><th>Submitted</th><th>Academic year</th><th>Status</th><th></th></tr></thead>
            <tbody><?php foreach ($applications as $application): ?>
                <tr>
                    <td>#<?php echo e((string) $application['id']); ?></td>
                    <td><?php echo e($application['applicant_name']); ?><br><small><?php echo e($application['applicant_mobile']); ?></small></td>
                    <td><?php echo e($application['student_name']); ?><?php if ($application['student_code'] !== null): ?><br><small><?php echo e($application['student_code']); ?></small><?php endif; ?></td>
                    <td><?php echo e(date('j M Y', strtotime((string) $application['created_at']))); ?></td>
                    <td><?php echo e($application['academic_year_name'] ?? 'Not converted'); ?></td>
                    <td><span class="badge text-bg-<?php echo e(match ($application['status']) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        'enrolled' => 'primary',
                        'under_review' => 'warning',
                        default => 'secondary',
                    }); ?>"><?php echo e(ucwords(str_replace('_', ' ', (string) $application['status']))); ?></span></td>
                    <td><a class="btn btn-sm btn-outline-primary" href="<?php echo e(route_url('/admin/admissions/view?id=' . (int) $application['id'])); ?>">Review</a></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
    </section>
<?php endif; ?>
<?php render_app_page_end('admin'); ?>
