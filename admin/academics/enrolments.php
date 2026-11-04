<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/academic-management-service.php';

require_http_methods(['GET', 'POST']);
require_placeholder_route_access();
$db = db_connect();
$institutionId = authenticated_institution_id(['super_admin', 'admin', 'staff']);
if ($institutionId === null) {
    http_response_code(403);
    exit('Institution access is unavailable for this account.');
}
if (!academic_management_schema_ready($db)) {
    academic_management_render_schema_required_page();
    exit;
}
$user = current_user();
$errors = [];
$configuration = academic_management_configuration($db, $institutionId);
$canEnrol = user_has_compatible_permission('students.create', 'academics.manage');
$canEdit = user_has_compatible_permission('students.edit', 'academics.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    try {
        $action = $_POST['action'] ?? null;
        if ($action === 'enrol') {
            $yearId = $configuration['active_academic_year_id'];
            if ($yearId === null) {
                throw new DomainException('Select an active academic year before enrolling students.');
            }
            $studentId = academic_management_id($_POST['student_id'] ?? null, 'student');
            $groupId = academic_management_id($_POST['academic_group_id'] ?? null, 'academic placement');
            $reason = academic_management_text($_POST['reason'] ?? null, 'enrolment reason', 500);
            $admissionNumber = $_POST['admission_number'] ?? null;
            if ($admissionNumber !== null && !is_string($admissionNumber)) {
                throw new InvalidArgumentException('Enter a valid admission number.');
            }
            academic_management_create_enrolment(
                $db,
                $institutionId,
                (int) $user['id'],
                $studentId,
                $yearId,
                $groupId,
                $reason,
                $admissionNumber
            );
            set_flash('success', 'Student enrolled in the selected academic year.');
        } elseif ($action === 'withdraw' || $action === 'readmit') {
            $enrolmentId = academic_management_id($_POST['enrolment_id'] ?? null, 'enrolment');
            $reason = academic_management_text($_POST['reason'] ?? null, 'status-change reason', 500);
            academic_management_change_enrolment_status(
                $db,
                $institutionId,
                (int) $user['id'],
                $enrolmentId,
                $action === 'withdraw' ? 'withdrawn' : 'active',
                $reason
            );
            set_flash('success', $action === 'withdraw' ? 'Student enrolment withdrawn.' : 'Student enrolment reactivated.');
        } elseif ($action === 'change_section') {
            $enrolmentId = academic_management_id($_POST['enrolment_id'] ?? null, 'enrolment');
            $groupId = academic_management_id($_POST['academic_group_id'] ?? null, 'section');
            $reason = academic_management_text($_POST['reason'] ?? null, 'section-change reason', 500);
            academic_management_change_section(
                $db,
                $institutionId,
                (int) $user['id'],
                $enrolmentId,
                $groupId,
                $reason
            );
            set_flash('success', 'Student section placement updated; its history was retained.');
        } elseif ($action === 'promote') {
            $targetYearId = $configuration['active_academic_year_id'];
            if ($targetYearId === null) {
                throw new DomainException('Select the target academic year before promoting students.');
            }
            $enrolmentId = academic_management_id($_POST['enrolment_id'] ?? null, 'enrolment');
            $groupId = academic_management_id($_POST['academic_group_id'] ?? null, 'academic placement');
            $reason = academic_management_text($_POST['reason'] ?? null, 'promotion reason', 500);
            academic_management_promote_enrolment(
                $db,
                $institutionId,
                (int) $user['id'],
                $enrolmentId,
                $targetYearId,
                $groupId,
                $reason
            );
            set_flash('success', 'Student promoted to the selected academic year.');
        } else {
            throw new InvalidArgumentException('Choose a supported enrolment action.');
        }
        safe_redirect('/admin/academics/enrolments', 303);
    } catch (InvalidArgumentException | DomainException | OutOfBoundsException $exception) {
        $errors[] = $exception->getMessage();
    } catch (mysqli_sql_exception $exception) {
        if ((int) $exception->getCode() === 1062) {
            $errors[] = 'This student already has an enrolment or admission number in the selected year.';
        } else {
            log_error('academic_enrolment_save_failed', [
                'error_type' => get_class($exception),
                'error_code' => $exception->getCode(),
            ]);
            $errors[] = 'The enrolment could not be saved.';
        }
    } catch (Throwable $exception) {
        log_error('academic_enrolment_save_failed', [
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
        ]);
        $errors[] = 'The enrolment could not be saved.';
    }
}

$students = academic_management_student_options($db, $institutionId);
$placements = academic_management_list_placement_options($db, $institutionId);
$enrolments = academic_management_list_enrolments($db, $institutionId);
$model = $configuration['academic_model'];
$activeYearId = $configuration['active_academic_year_id'];
$activeYearLabel = null;
if ($activeYearId !== null) {
    foreach (academic_management_list_years($db, $institutionId) as $year) {
        if ((int) $year['id'] === $activeYearId) {
            $activeYearLabel = (string) $year['name'];
            break;
        }
    }
}

render_app_page_start(
    'Student Enrolments',
    'Enrol, place, withdraw, readmit, or promote students while retaining placement history.',
    'admin',
    ['admin-dashboard.css']
);
?>
<?php echo render_flash_messages(); ?>
<?php foreach ($errors as $error): ?><div class="alert alert-danger" role="alert"><?php echo e($error); ?></div><?php endforeach; ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/academics/years')); ?>">Academics</a></li><li class="breadcrumb-item active" aria-current="page">Student enrolments</li></ol></nav>
<?php if (!is_string($model)): ?>
    <section class="dashboard-panel"><h2>Academic model required</h2><p>Configure this institution's academic model before enrolling students.</p><a class="btn btn-cobalt" href="<?php echo e(route_url('/admin/academics/years')); ?>">Configure academic model</a></section>
<?php elseif ($activeYearId === null): ?>
    <section class="dashboard-panel"><h2>Active academic year required</h2><p>Select an active academic year before enrolling or promoting students.</p><a class="btn btn-cobalt" href="<?php echo e(route_url('/admin/academics/years')); ?>">Select academic year</a></section>
<?php elseif ($placements === []): ?>
    <section class="dashboard-panel"><h2>Placement options required</h2><p>Add the year-specific classes, sections, programme terms, or coaching batches before enrolling students.</p><a class="btn btn-cobalt" href="<?php echo e(route_url('/admin/academics/classes')); ?>">Configure academic placements</a></section>
<?php else: ?>
    <?php if ($canEnrol): ?>
        <section class="dashboard-panel mb-4" aria-labelledby="enrol-student-title">
            <h2 id="enrol-student-title">Enrol a student<?php if ($activeYearLabel !== null): ?> in <?php echo e($activeYearLabel); ?><?php endif; ?></h2>
            <?php if ($students === []): ?>
                <p>Create or admit a student before assigning an academic placement.</p>
            <?php else: ?>
                <form method="post" action="<?php echo e(route_url('/admin/academics/enrolments')); ?>" class="row g-3 align-items-end">
                    <?php echo csrf_field(); ?><input type="hidden" name="action" value="enrol">
                    <label class="col-md-4">Student<select class="form-select" name="student_id" required><option value="">Select a student</option><?php foreach ($students as $student): ?><option value="<?php echo e((string) $student['id']); ?>"><?php echo e($student['full_name'] . ' (' . $student['student_code'] . ')'); ?></option><?php endforeach; ?></select></label>
                    <label class="col-md-4">Placement<select class="form-select" name="academic_group_id" required><option value="">Select a placement</option><?php foreach ($placements as $placement): ?><option value="<?php echo e((string) $placement['id']); ?>"><?php echo e($placement['label']); ?></option><?php endforeach; ?></select></label>
                    <label class="col-md-4">Admission number (optional)<input class="form-control" name="admission_number" maxlength="50"></label>
                    <label class="col-md-9">Reason<input class="form-control" name="reason" maxlength="500" required></label>
                    <div class="col-md-3"><button class="btn btn-primary" type="submit">Enrol student</button></div>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="dashboard-panel" aria-labelledby="enrolment-list-title">
        <h2 id="enrolment-list-title">Enrolment records</h2>
        <?php if ($enrolments === []): ?>
            <p>No student enrolments have been recorded.</p>
        <?php else: ?>
            <div class="table-responsive"><table class="table align-middle">
                <thead><tr><th>Student</th><th>Academic year</th><th>Placement</th><th>Status</th><?php if ($canEdit): ?><th>Actions</th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($enrolments as $enrolment): ?>
                    <?php
                    $parts = array_filter([
                        $enrolment['class_name'] ?? null,
                        $enrolment['section_name'] ?? null,
                        $enrolment['course_name'] ?? null,
                        $enrolment['term_name'] ?? null,
                        $enrolment['batch_name'] ?? null,
                    ], static fn($part): bool => is_string($part) && $part !== '');
                    $status = (string) $enrolment['enrolment_status'];
                    ?>
                    <tr>
                        <td><a href="<?php echo e(route_url('/admin/students/view') . '?id=' . (int) $enrolment['student_id']); ?>"><?php echo e($enrolment['full_name'] . ' (' . $enrolment['student_code'] . ')'); ?></a></td>
                        <td><?php echo e($enrolment['academic_year_name']); ?></td>
                        <td><?php echo e($parts === [] ? 'Not assigned' : implode(' / ', $parts)); ?></td>
                        <td><?php echo e(ucfirst($status)); ?></td>
                        <?php if ($canEdit): ?>
                            <td>
                                <?php if ($status === 'active'): ?>
                                    <form method="post" action="<?php echo e(route_url('/admin/academics/enrolments')); ?>" class="d-inline-flex flex-wrap gap-2">
                                        <?php echo csrf_field(); ?><input type="hidden" name="action" value="withdraw"><input type="hidden" name="enrolment_id" value="<?php echo e((string) $enrolment['enrolment_id']); ?>">
                                        <input class="form-control form-control-sm" name="reason" maxlength="500" placeholder="Withdrawal reason" required aria-label="Withdrawal reason for <?php echo e($enrolment['full_name']); ?>">
                                        <button class="btn btn-outline-danger btn-sm" type="submit">Withdraw</button>
                                    </form>
                                    <?php if ($model === 'school' && $activeYearId === (int) $enrolment['academic_year_id']): ?>
                                        <form method="post" action="<?php echo e(route_url('/admin/academics/enrolments')); ?>" class="d-inline-flex flex-wrap gap-2 mt-2">
                                            <?php echo csrf_field(); ?><input type="hidden" name="action" value="change_section"><input type="hidden" name="enrolment_id" value="<?php echo e((string) $enrolment['enrolment_id']); ?>">
                                            <select class="form-select form-select-sm" name="academic_group_id" required aria-label="New section for <?php echo e($enrolment['full_name']); ?>"><option value="">Select section</option><?php foreach ($placements as $placement): ?><option value="<?php echo e((string) $placement['id']); ?>"><?php echo e($placement['label']); ?></option><?php endforeach; ?></select>
                                            <input class="form-control form-control-sm" name="reason" maxlength="500" placeholder="Change reason" required aria-label="Section change reason for <?php echo e($enrolment['full_name']); ?>">
                                            <button class="btn btn-outline-primary btn-sm" type="submit">Change section</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($activeYearId !== (int) $enrolment['academic_year_id']): ?>
                                        <form method="post" action="<?php echo e(route_url('/admin/academics/enrolments')); ?>" class="d-inline-flex flex-wrap gap-2 mt-2">
                                            <?php echo csrf_field(); ?><input type="hidden" name="action" value="promote"><input type="hidden" name="enrolment_id" value="<?php echo e((string) $enrolment['enrolment_id']); ?>">
                                            <select class="form-select form-select-sm" name="academic_group_id" required aria-label="Promotion placement for <?php echo e($enrolment['full_name']); ?>"><option value="">Select promotion placement</option><?php foreach ($placements as $placement): ?><option value="<?php echo e((string) $placement['id']); ?>"><?php echo e($placement['label']); ?></option><?php endforeach; ?></select>
                                            <input class="form-control form-control-sm" name="reason" maxlength="500" placeholder="Promotion reason" required aria-label="Promotion reason for <?php echo e($enrolment['full_name']); ?>">
                                            <button class="btn btn-outline-success btn-sm" type="submit">Promote</button>
                                        </form>
                                    <?php endif; ?>
                                <?php elseif (in_array($status, ['withdrawn', 'cancelled'], true)): ?>
                                    <form method="post" action="<?php echo e(route_url('/admin/academics/enrolments')); ?>" class="d-inline-flex flex-wrap gap-2">
                                        <?php echo csrf_field(); ?><input type="hidden" name="action" value="readmit"><input type="hidden" name="enrolment_id" value="<?php echo e((string) $enrolment['enrolment_id']); ?>">
                                        <input class="form-control form-control-sm" name="reason" maxlength="500" placeholder="Re-admission reason" required aria-label="Re-admission reason for <?php echo e($enrolment['full_name']); ?>">
                                        <button class="btn btn-outline-success btn-sm" type="submit">Re-admit</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php render_app_page_end('admin'); ?>
