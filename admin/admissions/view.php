<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/admission-conversion-service.php';

require_http_methods(['GET', 'POST']);
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
$admissionId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!is_int($admissionId) || $admissionId < 1) {
    http_response_code(404);
    exit('Admission application not found.');
}
$errors = [];
$schemaReady = academic_management_schema_ready($db) && admission_conversion_schema_ready($db);
$actor = current_user();

if (!$schemaReady) {
    http_response_code(503);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    try {
        $action = $_POST['action'] ?? null;
        if (in_array($action, ['under_review', 'approve', 'reject'], true)) {
            $requiredPermission = $action === 'approve'
                ? 'admissions.approve'
                : ($action === 'reject' ? 'admissions.reject' : 'admissions.edit');
            if (!user_has_compatible_permission($requiredPermission, 'admissions.manage')) {
                http_response_code(403);
                exit('Forbidden.');
            }
            $notes = $_POST['review_notes'] ?? '';
            if (!is_string($notes)) {
                throw new InvalidArgumentException('Enter valid review notes.');
            }
            $status = match ($action) {
                'approve' => 'approved',
                'reject' => 'rejected',
                default => 'under_review',
            };
            admission_conversion_set_status(
                $db,
                $institutionId,
                (int) $actor['id'],
                (string) $actor['user_type'],
                $admissionId,
                $status,
                trim($notes)
            );
            set_flash('success', 'Admission application status updated.');
            safe_redirect('/admin/admissions/view?id=' . $admissionId, 303);
        }
        if ($action === 'convert') {
            if (!user_has_compatible_permission('admissions.approve', 'admissions.manage')
                || !user_has_compatible_permission('students.create', 'academics.manage')) {
                http_response_code(403);
                exit('Forbidden.');
            }
            $yearId = academic_management_id($_POST['academic_year_id'] ?? null, 'active academic year');
            $groupId = academic_management_id($_POST['academic_group_id'] ?? null, 'academic placement');
            $studentCode = $_POST['student_code'] ?? '';
            $admissionNumber = $_POST['admission_number'] ?? '';
            if (!is_string($studentCode) || !is_string($admissionNumber)) {
                throw new InvalidArgumentException('Enter valid student and admission numbers.');
            }
            admission_conversion_convert(
                $db,
                $institutionId,
                (int) $actor['id'],
                (string) $actor['user_type'],
                $admissionId,
                $yearId,
                $groupId,
                $studentCode,
                $admissionNumber
            );
            set_flash('success', 'Admission converted to a student enrolment.');
            safe_redirect('/admin/admissions/view?id=' . $admissionId, 303);
        }
        throw new InvalidArgumentException('Choose a supported admission action.');
    } catch (InvalidArgumentException | DomainException | OutOfBoundsException $exception) {
        $errors[] = $exception->getMessage();
    } catch (mysqli_sql_exception $exception) {
        if ((int) $exception->getCode() === 1062) {
            $errors[] = 'A student ID, admission number, or academic-year enrolment already exists. Review the matching record before retrying.';
        } else {
            log_error('admission_conversion_failed', [
                'error_type' => get_class($exception),
                'error_code' => $exception->getCode(),
            ]);
            $errors[] = 'The admission action could not be completed.';
        }
    } catch (Throwable $exception) {
        log_error('admission_conversion_failed', [
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
        ]);
        $errors[] = 'The admission action could not be completed.';
    }
}

$application = $schemaReady ? admission_conversion_get($db, $institutionId, $admissionId) : null;
if ($schemaReady && $application === null) {
    http_response_code(404);
    exit('Admission application not found.');
}
$placementData = $schemaReady ? admission_conversion_placement_options($db, $institutionId) : [
    'academic_model' => null,
    'academic_year_id' => null,
    'years' => [],
    'placements' => [],
];
$canConvert = user_has_compatible_permission('admissions.approve', 'admissions.manage')
    && user_has_compatible_permission('students.create', 'academics.manage');

render_app_page_start(
    'Review Admission',
    'Verify the application and assign the student to an active academic placement.',
    'admin',
    ['admin-dashboard.css']
);
?>
<?php echo render_flash_messages(); ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/admissions/index')); ?>">Admissions</a></li><li class="breadcrumb-item active" aria-current="page">Application #<?php echo e((string) $admissionId); ?></li></ol></nav>
<?php foreach ($errors as $error): ?><div class="alert alert-danger" role="alert"><?php echo e($error); ?></div><?php endforeach; ?>
<?php if (!$schemaReady): ?>
    <section class="dashboard-panel" role="status"><h2>Database update required</h2><p>Apply the academic-management and admission-conversion migrations before reviewing or converting applications.</p></section>
<?php elseif (is_array($application)): ?>
    <section class="dashboard-panel mb-4">
        <div class="d-flex justify-content-between align-items-start gap-3"><div><span class="text-muted">Application #<?php echo e((string) $application['id']); ?></span><h2><?php echo e($application['student_name']); ?></h2></div><span class="badge text-bg-<?php echo e(match ($application['status']) {
            'approved' => 'success', 'rejected' => 'danger', 'enrolled' => 'primary',
            'under_review' => 'warning', default => 'secondary',
        }); ?>"><?php echo e(ucwords(str_replace('_', ' ', (string) $application['status']))); ?></span></div>
        <dl class="row mb-0">
            <dt class="col-sm-3">Applicant</dt><dd class="col-sm-9"><?php echo e($application['applicant_name']); ?></dd>
            <dt class="col-sm-3">Mobile / email</dt><dd class="col-sm-9"><?php echo e($application['applicant_mobile']); ?><?php if ($application['applicant_email'] !== null): ?> / <?php echo e($application['applicant_email']); ?><?php endif; ?></dd>
            <dt class="col-sm-3">Relationship</dt><dd class="col-sm-9"><?php echo e($application['relationship_to_student'] ?? 'Not provided'); ?></dd>
            <dt class="col-sm-3">Linked parent</dt><dd class="col-sm-9"><?php echo e($application['linked_parent_name'] ?? 'No parent account linked'); ?></dd>
            <dt class="col-sm-3">Date of birth</dt><dd class="col-sm-9"><?php echo e($application['date_of_birth'] ?? 'Not provided'); ?></dd>
            <dt class="col-sm-3">Submitted</dt><dd class="col-sm-9"><?php echo e($application['created_at']); ?></dd>
            <dt class="col-sm-3">Application notes</dt><dd class="col-sm-9"><?php echo nl2br(e($application['submission_notes'] ?? '')); ?></dd>
            <dt class="col-sm-3">Review notes</dt><dd class="col-sm-9"><?php echo nl2br(e($application['review_notes'] ?? '')); ?></dd>
        </dl>
        <?php if ($application['student_enrolment_id'] !== null): ?>
            <div class="alert alert-success mt-3 mb-0">
                Converted to student <strong><?php echo e($application['linked_student_name'] . ' (' . $application['linked_student_code'] . ')'); ?></strong>
                in <?php echo e($application['converted_year_name'] ?? 'the academic year'); ?>.
                Enrolment #<?php echo e((string) $application['student_enrolment_id']); ?>.
                <?php if (user_has_compatible_permission('students.view', 'students.manage')): ?>
                    <a href="<?php echo e(route_url('/admin/students/view?id=' . (int) $application['student_id'])); ?>">Open student</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if (in_array($application['status'], ['submitted', 'under_review'], true)): ?>
        <section class="dashboard-panel mb-4">
            <h2 class="h5">Review decision</h2>
            <form method="post" action="<?php echo e(route_url('/admin/admissions/view?id=' . $admissionId)); ?>">
                <?php echo csrf_field(); ?>
                <label class="form-label" for="review-notes">Review notes</label>
                <textarea class="form-control mb-3" id="review-notes" name="review_notes" rows="3" maxlength="20000"><?php echo e((string) ($application['review_notes'] ?? '')); ?></textarea>
                <div class="d-flex flex-wrap gap-2">
                    <?php if (user_has_compatible_permission('admissions.edit', 'admissions.manage')): ?><button class="btn btn-outline-primary" name="action" value="under_review">Mark under review</button><?php endif; ?>
                    <?php if (user_has_compatible_permission('admissions.approve', 'admissions.manage')): ?><button class="btn btn-success" name="action" value="approve">Approve</button><?php endif; ?>
                    <?php if (user_has_compatible_permission('admissions.reject', 'admissions.manage')): ?><button class="btn btn-outline-danger" name="action" value="reject">Reject</button><?php endif; ?>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($application['status'] === 'approved' && $application['student_enrolment_id'] === null && $canConvert): ?>
        <section class="dashboard-panel">
            <h2 class="h5">Convert to student enrolment</h2>
            <?php if ($placementData['academic_year_id'] === null): ?>
                <p>Select an active academic year in Academic Years before converting applications.</p>
            <?php elseif ($placementData['placements'] === []): ?>
                <p>Add valid year-specific class/section, programme/term, or course/batch placements before converting.</p>
            <?php else: ?>
                <p>Academic model: <strong><?php echo e(ucfirst((string) $placementData['academic_model'])); ?></strong>.
                    Active year: <strong><?php echo e($placementData['years'][0]['name']); ?></strong>.</p>
                <form method="post" action="<?php echo e(route_url('/admin/admissions/view?id=' . $admissionId)); ?>" class="row g-3 align-items-end">
                    <?php echo csrf_field(); ?><input type="hidden" name="action" value="convert">
                    <input type="hidden" name="academic_year_id" value="<?php echo e((string) $placementData['academic_year_id']); ?>">
                    <label class="col-md-6">Academic placement
                        <select class="form-select" name="academic_group_id" required>
                            <option value="">Select a placement</option>
                            <?php foreach ($placementData['placements'] as $placement): ?><option value="<?php echo e((string) $placement['id']); ?>"><?php echo e($placement['label']); ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <?php if ($application['student_id'] === null): ?>
                        <label class="col-md-3">Student ID<input class="form-control" name="student_code" maxlength="50" required></label>
                    <?php else: ?>
                        <div class="col-md-3"><span class="form-label d-block">Existing student</span><strong><?php echo e($application['linked_student_name'] . ' (' . $application['linked_student_code'] . ')'); ?></strong></div>
                    <?php endif; ?>
                    <label class="col-md-3">Admission number (optional)<input class="form-control" name="admission_number" maxlength="50"></label>
                    <div class="col-12"><button class="btn btn-cobalt" type="submit">Create enrolment</button></div>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>
<?php endif; ?>
<?php render_app_page_end('admin'); ?>
