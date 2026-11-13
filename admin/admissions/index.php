<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/admission-workflow-service.php';

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
$schemaReady = academic_management_schema_ready($db) && admission_workflow_schema_ready($db);
$search = isset($_GET['search']) && is_string($_GET['search']) ? trim(mb_substr($_GET['search'], 0, 100, 'UTF-8')) : '';
$status = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : '';
$validStatuses = ['', ...ADMISSION_WORKFLOW_STATUSES];
if (!in_array($status, $validStatuses, true)) {
    $status = '';
}
$enquiryStatus = isset($_GET['enquiry_status']) && is_string($_GET['enquiry_status']) ? $_GET['enquiry_status'] : '';
if (!in_array($enquiryStatus, ['', 'new', 'contacted', 'follow_up', 'converted', 'closed'], true)) {
    $enquiryStatus = '';
}
$dateError = false;
$parseDate = static function (mixed $value) use (&$dateError): ?string {
    if (!is_string($value) || $value === '') {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d') !== $value) {
        $dateError = true;
        return null;
    }
    return $value;
};
$dateFrom = $parseDate($_GET['date_from'] ?? '');
$dateTo = $parseDate($_GET['date_to'] ?? '');
if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
    $dateError = true;
    $dateFrom = null;
    $dateTo = null;
}
$applicationPage = max(1, min(100000, (int) ($_GET['application_page'] ?? 1)));
$enquiryPage = max(1, min(100000, (int) ($_GET['enquiry_page'] ?? 1)));
$pageSize = 25;
$stats = $schemaReady ? admission_workflow_dashboard_stats($db, $institutionId, $dateFrom, $dateTo) : [];
$applications = $schemaReady ? admission_conversion_list(
    $db,
    $institutionId,
    $search,
    $status,
    $dateFrom,
    $dateTo,
    $pageSize + 1,
    ($applicationPage - 1) * $pageSize
) : [];
$hasMoreApplications = count($applications) > $pageSize;
$applications = array_slice($applications, 0, $pageSize);
$enquiries = $schemaReady ? admission_workflow_list_enquiries(
    $db,
    $institutionId,
    $search,
    $enquiryStatus,
    $dateFrom,
    $dateTo,
    $pageSize + 1,
    ($enquiryPage - 1) * $pageSize
) : [];
$hasMoreEnquiries = count($enquiries) > $pageSize;
$enquiries = array_slice($enquiries, 0, $pageSize);
$pageUrl = static function (string $pageKey, int $page) use (
    $search,
    $status,
    $enquiryStatus,
    $dateFrom,
    $dateTo
): string {
    $query = [
        'search' => $search,
        'status' => $status,
        'enquiry_status' => $enquiryStatus,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'application_page' => 1,
        'enquiry_page' => 1,
    ];
    $query[$pageKey] = $page;
    return route_url('/admin/admissions/index?' . http_build_query(array_filter(
        $query,
        static fn (mixed $value): bool => $value !== null && $value !== ''
    )));
};
$canCreate = user_has_compatible_permission('admissions.create', 'admissions.manage');
$canEdit = user_has_compatible_permission('admissions.edit', 'admissions.manage');

render_app_page_start(
    'Admissions',
    'Track enquiries, process applications, and register admitted students.',
    'admin',
    ['admin-dashboard.css']
);
?>
<?php echo render_flash_messages(); ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item active" aria-current="page">Admissions</li></ol></nav>
<?php if (!$schemaReady): ?>
    <section class="dashboard-panel" role="status">
        <h2>Database update required</h2>
        <p>Apply the academic-management, admission-conversion, and workflow migrations using the project migration procedure.</p>
        <p><code>database/migrations/20261113_add_admission_workflow.sql</code></p>
    </section>
<?php else: ?>
    <section class="dashboard-panel mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div><h2 class="h4 mb-1">Admission workspace</h2><p class="mb-0 text-muted">Search and manage enquiries and student applications.</p></div>
            <div class="d-flex gap-2"><?php if ($canCreate): ?><a class="btn btn-outline-primary" href="<?php echo e(route_url('/admin/admissions/enquiries/create')); ?>">New enquiry</a><a class="btn btn-primary" href="<?php echo e(route_url('/admin/admissions/create')); ?>">New application</a><?php endif; ?><?php if ($canEdit): ?><a class="btn btn-outline-secondary" href="<?php echo e(route_url('/admin/admissions/settings')); ?>">Student ID settings</a><?php endif; ?></div>
        </div>
        <?php if ($dateError): ?><div class="alert alert-warning mt-3 mb-0" role="alert">Enter a valid date range with the start date on or before the end date.</div><?php endif; ?>
        <form class="row g-2 mt-3" method="get" action="<?php echo e(route_url('/admin/admissions/index')); ?>">
            <div class="col-md-5"><label class="visually-hidden" for="admission-search">Search admissions</label><input class="form-control" id="admission-search" type="search" name="search" value="<?php echo e($search); ?>" placeholder="Search reference, applicant, student or mobile"></div>
            <div class="col-md-3"><label class="visually-hidden" for="application-status">Application status</label><select class="form-select" id="application-status" name="status"><option value="">All application statuses</option><?php foreach (ADMISSION_WORKFLOW_STATUSES as $option): ?><option value="<?php echo e($option); ?>"<?php echo $status === $option ? ' selected' : ''; ?>><?php echo e(ucwords(str_replace('_', ' ', $option))); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="visually-hidden" for="enquiry-status">Enquiry status</label><select class="form-select" id="enquiry-status" name="enquiry_status"><option value="">All enquiry statuses</option><?php foreach (['new','contacted','follow_up','converted','closed'] as $option): ?><option value="<?php echo e($option); ?>"<?php echo $enquiryStatus === $option ? ' selected' : ''; ?>><?php echo e(ucwords(str_replace('_', ' ', $option))); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="visually-hidden" for="date-from">Created from</label><input class="form-control" id="date-from" type="date" name="date_from" value="<?php echo e($dateFrom ?? ''); ?>" aria-label="Created from"></div>
            <div class="col-md-2"><label class="visually-hidden" for="date-to">Created to</label><input class="form-control" id="date-to" type="date" name="date_to" value="<?php echo e($dateTo ?? ''); ?>" aria-label="Created to"></div>
            <div class="col-md-1"><button class="btn btn-outline-primary w-100" type="submit">Filter</button></div>
        </form>
    </section>
    <section class="row g-3 mb-4" aria-label="Admission summary">
        <?php foreach ([
            'Total enquiries' => $stats['total_enquiries'],
            'Draft applications' => $stats['draft'],
            'Submitted applications' => $stats['submitted'],
            'Under review' => $stats['under_review'],
            'Information requested' => $stats['information_requested'],
            'Approved' => $stats['approved'],
            'Rejected' => $stats['rejected'],
            'Converted admissions' => $stats['enrolled'],
        ] as $label => $count): ?>
            <div class="col-6 col-md-3"><div class="dashboard-panel h-100"><small class="text-muted"><?php echo e($label); ?></small><div class="h3 mb-0"><?php echo e((string) $count); ?></div></div></div>
        <?php endforeach; ?>
    </section>
    <section class="dashboard-panel mb-4">
        <h2 class="h5">Applications <span class="badge text-bg-secondary"><?php echo e((string) count($applications)); ?></span></h2>
        <?php if ($applications === []): ?><p class="text-muted">No applications match this search.</p><?php else: ?>
            <div class="table-responsive"><table class="table admin-panel-table align-middle">
                <thead><tr><th>Reference</th><th>Applicant</th><th>Student</th><th>Submitted</th><th>Academic year</th><th>Status</th><th></th></tr></thead>
                <tbody><?php foreach ($applications as $application): ?>
                    <tr>
                        <td><?php echo e($application['application_reference'] ?? ('#' . $application['id'])); ?></td>
                        <td><?php echo e($application['applicant_name']); ?><br><small><?php echo e($application['applicant_mobile']); ?></small></td>
                        <td><?php echo e($application['student_name']); ?><?php if ($application['student_code'] !== null): ?><br><small><?php echo e($application['student_code']); ?></small><?php endif; ?></td>
                        <td><?php echo e($application['submitted_at'] !== null ? date('j M Y', strtotime((string) $application['submitted_at'])) : 'Draft'); ?></td>
                        <td><?php echo e($application['academic_year_name'] ?? 'Not assigned'); ?></td>
                        <td><span class="badge text-bg-<?php echo e(match ($application['status']) {
                            'approved' => 'success', 'rejected' => 'danger', 'enrolled' => 'primary',
                            'under_review', 'information_requested' => 'warning', 'submitted' => 'info',
                            default => 'secondary',
                        }); ?>"><?php echo e(ucwords(str_replace('_', ' ', (string) $application['status']))); ?></span></td>
                        <td><a class="btn btn-sm btn-outline-primary" href="<?php echo e(route_url('/admin/admissions/view?id=' . (int) $application['id'])); ?>"><?php echo $application['status'] !== 'enrolled' && $canEdit ? 'Edit' : 'Review'; ?></a></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
            <nav aria-label="Application pages" class="d-flex justify-content-between mt-3">
                <?php if ($applicationPage > 1): ?><a class="btn btn-sm btn-outline-secondary" href="<?php echo e($pageUrl('application_page', $applicationPage - 1)); ?>">Previous applications</a><?php else: ?><span></span><?php endif; ?>
                <?php if ($hasMoreApplications): ?><a class="btn btn-sm btn-outline-secondary" href="<?php echo e($pageUrl('application_page', $applicationPage + 1)); ?>">Next applications</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
    <section class="dashboard-panel">
        <h2 class="h5">Enquiries <span class="badge text-bg-secondary"><?php echo e((string) count($enquiries)); ?></span></h2>
        <?php if ($enquiries === []): ?><p class="text-muted">No enquiries match this search.</p><?php else: ?>
            <div class="table-responsive"><table class="table admin-panel-table align-middle">
                <thead><tr><th>Reference</th><th>Applicant / student</th><th>Contact</th><th>Academic interest</th><th>Follow-up</th><th>Status</th><th></th></tr></thead>
                <tbody><?php foreach ($enquiries as $enquiry): ?>
                    <tr>
                        <td><?php echo e($enquiry['reference_number']); ?></td>
                        <td><?php echo e($enquiry['applicant_name']); ?><br><small><?php echo e($enquiry['student_name']); ?></small></td>
                        <td><?php echo e($enquiry['mobile']); ?><br><small><?php echo e($enquiry['email'] ?? ''); ?></small></td>
                        <td><?php echo e($enquiry['academic_year_name'] ?? ''); ?><br><small><?php echo e($enquiry['class_name'] ?? $enquiry['course_name'] ?? ''); ?></small></td>
                        <td><?php echo e($enquiry['follow_up_date'] ?? '—'); ?></td>
                        <td><?php echo e(ucwords(str_replace('_', ' ', $enquiry['status']))); ?></td>
                        <td><a class="btn btn-sm btn-outline-primary" href="<?php echo e(route_url('/admin/admissions/enquiries/view?id=' . (int) $enquiry['id'])); ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
            <nav aria-label="Enquiry pages" class="d-flex justify-content-between mt-3">
                <?php if ($enquiryPage > 1): ?><a class="btn btn-sm btn-outline-secondary" href="<?php echo e($pageUrl('enquiry_page', $enquiryPage - 1)); ?>">Previous enquiries</a><?php else: ?><span></span><?php endif; ?>
                <?php if ($hasMoreEnquiries): ?><a class="btn btn-sm btn-outline-secondary" href="<?php echo e($pageUrl('enquiry_page', $enquiryPage + 1)); ?>">Next enquiries</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php render_app_page_end('admin'); ?>
