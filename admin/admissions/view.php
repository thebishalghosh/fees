<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/admission-workflow-service.php';

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
$schemaReady = academic_management_schema_ready($db) && admission_workflow_schema_ready($db);
$actor = current_user();

if (!$schemaReady) {
    http_response_code(503);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    try {
        $action = $_POST['action'] ?? null;
        if (in_array($action, ['under_review', 'information_requested', 'approve', 'reject'], true)) {
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
                'information_requested' => 'information_requested',
                default => 'under_review',
            };
            if ($status === 'approved'
                && user_has_compatible_permission('students.create', 'academics.manage')) {
                $applicationForApproval = admission_conversion_get($db, $institutionId, $admissionId);
                if (!is_array($applicationForApproval)) {
                    throw new OutOfBoundsException('Admission application not found in this institution.');
                }
                $approvalPlacementData = admission_conversion_placement_options($db, $institutionId);
                if ($approvalPlacementData['academic_model'] === 'school') {
                    $placement = admission_conversion_select_placement(
                        $applicationForApproval,
                        $approvalPlacementData['placements'],
                        $approvalPlacementData['academic_year_id'],
                        $approvalPlacementData['academic_model']
                    );
                    if ($approvalPlacementData['academic_year_id'] === null) {
                        throw new DomainException('Select an active academic year before approving and enrolling students.');
                    }
                    if (!is_array($placement)) {
                        if ($applicationForApproval['academic_year_id'] !== null
                            && (int) $applicationForApproval['academic_year_id']
                                !== (int) $approvalPlacementData['academic_year_id']) {
                            throw new DomainException('Update the application’s academic year to match the institution’s active year before approving.');
                        }
                        throw new DomainException('No active placement matches the application’s academic selection. Update it or add the matching year-specific placement before approving.');
                    }
                    $conversion = admission_conversion_convert(
                        $db,
                        $institutionId,
                        (int) $actor['id'],
                        (string) $actor['user_type'],
                        $admissionId,
                        (int) $approvalPlacementData['academic_year_id'],
                        (int) $placement['id'],
                        '',
                        null,
                        trim($notes)
                    );
                    $approvalNotificationResult = admission_workflow_notify_after_commit(
                        $db,
                        $institutionId,
                        $admissionId,
                        'application_approved'
                    );
                    $registrationNotificationResult = admission_workflow_notify_after_commit(
                        $db,
                        $institutionId,
                        $admissionId,
                        'student_registered'
                    );
                    $notificationFailed = $approvalNotificationResult === false
                        || $registrationNotificationResult === false;
                    set_flash($notificationFailed ? 'warning' : 'success', $notificationFailed
                        ? 'Student enrolled successfully with ID ' . $conversion['student_code'] . ', but one or more email notifications could not be delivered.'
                        : 'Student enrolled successfully. Student ID: ' . $conversion['student_code'] . '.');
                    safe_redirect('/admin/admissions/view?id=' . $admissionId, 303);
                }
            }
            admission_conversion_set_status(
                $db,
                $institutionId,
                (int) $actor['id'],
                (string) $actor['user_type'],
                $admissionId,
                $status,
                trim($notes)
            );
            $notificationEvent = match ($status) {
                'information_requested' => 'information_requested',
                'approved' => 'application_approved',
                'rejected' => 'application_rejected',
                default => null,
            };
            $notificationResult = $notificationEvent === null
                ? null
                : admission_workflow_notify_after_commit($db, $institutionId, $admissionId, $notificationEvent);
            set_flash($notificationResult === false ? 'warning' : 'success', $notificationResult === false
                ? 'Admission status updated, but its email notification could not be delivered.'
                : 'Admission application status updated.');
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
            $conversion = admission_conversion_convert(
                $db,
                $institutionId,
                (int) $actor['id'],
                (string) $actor['user_type'],
                $admissionId,
                $yearId,
                $groupId,
                '',
                null
            );
            $notificationResult = admission_workflow_notify_after_commit(
                $db,
                $institutionId,
                $admissionId,
                'student_registered'
            );
            set_flash($notificationResult === false ? 'warning' : 'success', $notificationResult === false
                ? 'Student registered with ID ' . $conversion['student_code'] . ', but its email notification could not be delivered.'
                : 'Student registered successfully. Student ID: ' . $conversion['student_code'] . '.');
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
if (is_array($application)) {
    $application['history'] = admission_workflow_status_history($db, $institutionId, $admissionId);
}
$placementData = $schemaReady ? admission_conversion_placement_options($db, $institutionId) : [
    'academic_model' => null,
    'academic_year_id' => null,
    'years' => [],
    'placements' => [],
];
$matchingPlacements = is_array($application)
    ? admission_conversion_matching_placements(
        $application,
        $placementData['placements'],
        $placementData['academic_year_id'],
        $placementData['academic_model']
    )
    : [];
$selectedPlacementId = null;
if (is_array($application) && $application['requested_academic_group_id'] !== null) {
    foreach ($matchingPlacements as $placement) {
        if ((int) $placement['id'] === (int) $application['requested_academic_group_id']) {
            $selectedPlacementId = (int) $placement['id'];
            break;
        }
    }
}
if ($selectedPlacementId === null && count($matchingPlacements) === 1) {
    $selectedPlacementId = (int) $matchingPlacements[0]['id'];
}
$documents = $schemaReady ? admission_workflow_list_documents($db, $institutionId, $admissionId) : [];
$canConvert = user_has_compatible_permission('admissions.approve', 'admissions.manage')
    && user_has_compatible_permission('students.create', 'academics.manage');
$autoEnrollmentPlacement = is_array($application)
    && $placementData['academic_model'] === 'school'
    ? admission_conversion_select_placement(
        $application,
        $placementData['placements'],
        $placementData['academic_year_id'],
        $placementData['academic_model']
    )
    : null;
$autoEnrollmentOnApproval = $canConvert && $placementData['academic_model'] === 'school';

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
            <dt class="col-sm-3">Submitted</dt><dd class="col-sm-9"><?php echo e($application['submitted_at'] ?? 'Not submitted'); ?></dd>
            <?php if ($application['approved_at'] !== null): ?><dt class="col-sm-3">Reviewed by</dt><dd class="col-sm-9"><?php echo e($application['reviewer_name'] ?? 'Platform administrator'); ?> — <?php echo e($application['approved_at']); ?></dd><?php endif; ?>
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
                <?php if ($application['linked_parent_id'] !== null && user_has_compatible_permission('parents.edit', 'parents.manage')): ?>
                    <a href="<?php echo e(route_url('/admin/parents/edit?id=' . (int) $application['linked_parent_id'])); ?>">Manage linked parent contact</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if (in_array($application['status'], ['submitted', 'under_review', 'information_requested'], true)): ?>
        <section class="dashboard-panel mb-4">
            <h2 class="h5">Review decision</h2>
            <?php if ($autoEnrollmentOnApproval): ?>
                <?php if (is_array($autoEnrollmentPlacement)): ?>
                    <p class="alert alert-info">Approving this application will create the student and enrol them in <strong><?php echo e($autoEnrollmentPlacement['label']); ?></strong>.</p>
                <?php elseif ($placementData['academic_year_id'] === null): ?>
                    <p class="alert alert-warning">Set an active academic year and placement before approving. Student enrolment will be created automatically after approval.</p>
                <?php else: ?>
                    <p class="alert alert-warning">No active placement matches this application. Update its academic selection or add the matching year-specific placement before approving.</p>
                <?php endif; ?>
            <?php endif; ?>
            <form method="post" action="<?php echo e(route_url('/admin/admissions/view?id=' . $admissionId)); ?>">
                <?php echo csrf_field(); ?>
                <label class="form-label" for="review-notes">Review notes</label>
                <textarea class="form-control mb-3" id="review-notes" name="review_notes" rows="3" maxlength="20000"><?php echo e((string) ($application['review_notes'] ?? '')); ?></textarea>
                <div class="d-flex flex-wrap gap-2">
                    <?php if (user_has_compatible_permission('admissions.edit', 'admissions.manage')): ?><button class="btn btn-outline-primary" name="action" value="under_review">Mark under review</button><?php endif; ?>
                    <?php if (user_has_compatible_permission('admissions.edit', 'admissions.manage')): ?><button class="btn btn-outline-warning" name="action" value="information_requested" onclick="return confirm('Request additional information from the applicant?')">Request information</button><?php endif; ?>
                    <?php if (user_has_compatible_permission('admissions.approve', 'admissions.manage')): ?><button class="btn btn-success" name="action" value="approve" <?php echo $autoEnrollmentOnApproval && !is_array($autoEnrollmentPlacement) ? 'disabled' : ''; ?> onclick="return confirm('<?php echo $autoEnrollmentOnApproval ? 'Approve this application and automatically enrol the student?' : 'Approve this admission application?'; ?>')"><?php echo $autoEnrollmentOnApproval ? 'Approve & enrol' : 'Approve'; ?></button><?php endif; ?>
                    <?php if (user_has_compatible_permission('admissions.reject', 'admissions.manage')): ?><button class="btn btn-outline-danger" name="action" value="reject" onclick="return confirm('Reject this admission application?')">Reject</button><?php endif; ?>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($application['status'] !== 'enrolled'
        && user_has_compatible_permission('admissions.edit', 'admissions.manage')): ?>
        <div class="mb-4"><a class="btn btn-outline-primary" href="<?php echo e(route_url('/admin/admissions/edit?id=' . $admissionId)); ?>">Edit application</a></div>
    <?php endif; ?>

    <section class="dashboard-panel mb-4">
        <div class="d-flex justify-content-between align-items-center gap-2"><h2 class="h5 mb-0">Application details</h2><span class="text-muted"><?php echo e($application['application_reference'] ?? 'Legacy application'); ?></span></div>
        <div class="row g-3 mt-1">
            <?php foreach ([
                'First name' => $application['first_name'] ?? null,
                'Middle name' => $application['middle_name'] ?? null,
                'Last name' => $application['last_name'] ?? null,
                'Gender' => $application['gender'] ?? null,
                'Student mobile' => $application['student_mobile'] ?? null,
                'Student email' => $application['student_email'] ?? null,
                'Address' => trim(implode(', ', array_filter([$application['address_line1'] ?? null, $application['address_line2'] ?? null, $application['city'] ?? null, $application['state'] ?? null, $application['postal_code'] ?? null, $application['country'] ?? null]))),
                'Previous school' => $application['previous_school'] ?? null,
                'Admission category' => $application['admission_category'] ?? null,
                'Father / mother' => trim(implode(' · ', array_filter([$application['father_name'] ?? null, $application['mother_name'] ?? null]))),
                'Father contact' => trim(implode(' · ', array_filter([$application['father_mobile'] ?? null, $application['father_email'] ?? null]))),
                'Mother contact' => trim(implode(' · ', array_filter([$application['mother_mobile'] ?? null, $application['mother_email'] ?? null]))),
                'Guardian' => $application['guardian_name'] ?? null,
                'Guardian contact' => trim(implode(' · ', array_filter([$application['guardian_mobile'] ?? null, $application['guardian_email'] ?? null]))),
                'Planned admission date' => $application['admission_date'] ?? null,
            ] as $label => $value): ?>
                <div class="col-md-4"><small class="text-muted d-block"><?php echo e($label); ?></small><?php echo e(is_string($value) && $value !== '' ? $value : '—'); ?></div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="dashboard-panel mb-4">
        <div class="d-flex justify-content-between align-items-center gap-3"><h2 class="h5">Documents</h2>
        <?php if ($application['status'] !== 'enrolled' && user_has_compatible_permission('admissions.edit', 'admissions.manage')): ?>
            <form method="post" enctype="multipart/form-data" action="<?php echo e(route_url('/admin/admissions/document-upload')); ?>" class="d-flex flex-wrap gap-2">
                <?php echo csrf_field(); ?><input type="hidden" name="admission_id" value="<?php echo e((string) $admissionId); ?>">
                <select class="form-select" name="document_type" required aria-label="Document category">
                    <?php foreach (['birth_certificate' => 'Birth certificate', 'identity' => 'Identity', 'address' => 'Address proof', 'previous_school' => 'Previous school', 'photo' => 'Photo', 'other' => 'Other'] as $value => $label): ?><option value="<?php echo e($value); ?>"><?php echo e($label); ?></option><?php endforeach; ?>
                </select>
                <input class="form-control" type="file" name="document" accept=".pdf,.jpg,.jpeg,.png,.webp" required aria-label="Admission document">
                <button class="btn btn-outline-primary" type="submit">Upload</button>
            </form>
        <?php endif; ?></div>
        <?php if ($documents === []): ?><p class="text-muted mb-0">No documents uploaded.</p><?php else: ?>
            <div class="table-responsive mt-3"><table class="table align-middle"><thead><tr><th>Document</th><th>Uploaded</th><th>Verification</th><th></th></tr></thead><tbody>
                <?php foreach ($documents as $document): ?><tr>
                    <td><?php echo e(ucwords(str_replace('_', ' ', $document['document_type']))); ?> — <?php echo e($document['display_name']); ?></td>
                    <td><?php echo e($document['created_at']); ?></td>
                    <td><span class="badge text-bg-<?php echo $document['verification_status'] === 'verified' ? 'success' : ($document['verification_status'] === 'rejected' ? 'danger' : 'secondary'); ?>"><?php echo e(ucfirst($document['verification_status'])); ?></span><?php if ($document['verification_remarks']): ?><br><small><?php echo e($document['verification_remarks']); ?></small><?php endif; ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?php echo e(route_url('/admin/admissions/document?id=' . (int) $document['id'])); ?>">View</a>
                        <?php if (user_has_compatible_permission('admissions.edit', 'admissions.manage') && $document['verification_status'] !== 'verified'): ?>
                            <form method="post" action="<?php echo e(route_url('/admin/admissions/document-verify')); ?>" class="d-inline-flex gap-1">
                                <?php echo csrf_field(); ?><input type="hidden" name="document_id" value="<?php echo e((string) $document['id']); ?>">
                                <input class="form-control form-control-sm" name="remarks" maxlength="1000" placeholder="Verification remark" aria-label="Verification remark">
                                <button class="btn btn-sm btn-success" name="status" value="verified">Verify</button>
                                <button class="btn btn-sm btn-outline-danger" name="status" value="rejected">Reject</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr><?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </section>

    <section class="dashboard-panel mb-4">
        <h2 class="h5">Application timeline</h2>
        <?php if (($application['history'] ?? []) === []): ?><p class="text-muted mb-0">No application activity recorded.</p><?php else: ?>
            <ol class="mb-0"><?php foreach ($application['history'] as $event): ?><li class="mb-3">
                <strong><?php echo e(ucwords(str_replace('_', ' ', $event['event_type']))); ?></strong>
                <?php if ($event['from_status'] !== null): ?> — <?php echo e(ucwords(str_replace('_', ' ', $event['from_status']))); ?> → <?php echo e(ucwords(str_replace('_', ' ', $event['to_status']))); ?><?php endif; ?>
                <div class="small text-muted"><?php echo e($event['changed_at']); ?><?php if ($event['actor_name']): ?> · <?php echo e($event['actor_name']); ?><?php endif; ?></div>
                <?php if ($event['remarks']): ?><div><?php echo nl2br(e($event['remarks'])); ?></div><?php endif; ?>
            </li><?php endforeach; ?></ol>
        <?php endif; ?>
    </section>

    <?php if ($application['status'] === 'approved' && $application['student_enrolment_id'] === null && $canConvert): ?>
        <section class="dashboard-panel">
            <h2 class="h5">Convert to student enrolment</h2>
            <?php if ($placementData['academic_year_id'] === null): ?>
                <p>Select an active academic year in Academic Years before converting applications.</p>
            <?php elseif ($matchingPlacements === []): ?>
                <?php if ($application['academic_year_id'] !== null
                    && (int) $application['academic_year_id'] !== (int) $placementData['academic_year_id']): ?>
                    <p>The academic year selected on this application is not the institution’s active academic year. Update the application’s academic selection before converting.</p>
                <?php else: ?>
                    <p>No active placement matches the academic selection on this application. Update its academic selection or add the matching year-specific placement before converting.</p>
                <?php endif; ?>
            <?php else: ?>
                <p>Academic model: <strong><?php echo e(ucfirst((string) $placementData['academic_model'])); ?></strong>.
                    Active year: <strong><?php echo e($placementData['years'][0]['name']); ?></strong>.</p>
                <?php if ($application['requested_academic_group_id'] !== null
                    && !in_array(
                        (int) $application['requested_academic_group_id'],
                        array_map(static fn (array $placement): int => (int) $placement['id'], $matchingPlacements),
                        true
                    )): ?>
                    <p class="text-muted">The preferred placement is unavailable. Choose an active placement matching the application’s academic selection.</p>
                <?php endif; ?>
                <form method="post" action="<?php echo e(route_url('/admin/admissions/view?id=' . $admissionId)); ?>" class="row g-3 align-items-end">
                    <?php echo csrf_field(); ?><input type="hidden" name="action" value="convert">
                    <input type="hidden" name="academic_year_id" value="<?php echo e((string) $placementData['academic_year_id']); ?>">
                    <label class="col-md-6">Academic placement
                        <select class="form-select" name="academic_group_id" required>
                            <option value="">Select a placement</option>
                            <?php foreach ($matchingPlacements as $placement): ?><option value="<?php echo e((string) $placement['id']); ?>"<?php echo $selectedPlacementId === (int) $placement['id'] ? ' selected' : ''; ?>><?php echo e($placement['label']); ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <div class="col-md-4"><span class="form-label d-block">Student ID</span><span class="text-muted">Generated automatically from the institution prefix and academic year.</span></div>
                    <div class="col-12"><button class="btn btn-cobalt" type="submit" onclick="return confirm('Register this student and create the academic enrolment?')">Register student and create enrolment</button></div>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>
<?php endif; ?>
<?php render_app_page_end('admin'); ?>
