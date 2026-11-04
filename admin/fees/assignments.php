<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layouts/page.php';
require_once __DIR__ . '/../../services/fee-assignment-service.php';

require_http_methods(['GET', 'HEAD', 'POST']);
require_placeholder_route_access();
$requestedAction = $_POST['action'] ?? null;
$requiredPermission = $_SERVER['REQUEST_METHOD'] !== 'POST'
    ? 'student_fees.view'
    : 'student_fees.create';
require_permission($requiredPermission);
$db = db_connect();
$user = current_user();
if ($user === null) {
    throw new RuntimeException('An authenticated institution account is required.');
}
$institutionId = authenticated_institution_id(['super_admin', 'admin', 'staff']);
if ($institutionId === null) {
    http_response_code(403);
    exit('Institution access is unavailable for this account.');
}
$errors = [];
$canCreateAssignment = user_has_compatible_permission('student_fees.create', 'fees.manage');
$canViewPlans = user_has_compatible_permission('student_fees.view', 'fees.view');
$configurationStatement = $db->prepare('SELECT academic_model FROM institutions WHERE id = ?');
$configurationStatement->bind_param('i', $institutionId);
$configurationStatement->execute();
$academicModel = $configurationStatement->get_result()->fetch_column();
$configurationStatement->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    try {
        $action = $_POST['action'] ?? null;
        if ($action === 'create_assignment') {
            $structureId = filter_var($_POST['fee_structure_id'] ?? null, FILTER_VALIDATE_INT);
            $planId = filter_var($_POST['payment_plan_id'] ?? null, FILTER_VALIDATE_INT);
            if ($structureId === false || $planId === false) {
                throw new InvalidArgumentException('Select a fee structure and payment plan.');
            }
            if (is_string($academicModel)) {
                $enrolmentId = filter_var($_POST['enrolment_id'] ?? null, FILTER_VALIDATE_INT);
                if ($enrolmentId === false || $enrolmentId < 1) {
                    throw new InvalidArgumentException('Select an active student enrolment.');
                }
                fee_workflow_create_enrolment_assignment(
                    $db,
                    $institutionId,
                    (int) $user['id'],
                    $enrolmentId,
                    $structureId,
                    $planId
                );
            } else {
                $studentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
                if ($studentId === false || $studentId < 1) {
                    throw new InvalidArgumentException('Select a student.');
                }
                fee_workflow_create_assignment(
                    $db,
                    $institutionId,
                    (int) $user['id'],
                    $studentId,
                    $structureId,
                    $planId
                );
            }
            set_flash('success', 'Fee assignment created with a locked component snapshot. Generate its installment schedule to finish setup.');
        } else {
            throw new InvalidArgumentException('Choose a supported fee-assignment action.');
        }
        safe_redirect('/admin/fees/assignments', 303);
    } catch (InvalidArgumentException | DomainException | OutOfBoundsException $exception) {
        $errors[] = $exception->getMessage();
    } catch (mysqli_sql_exception $exception) {
        if ($exception->getCode() === 1062) {
            $errors[] = 'A duplicate fee assignment already exists.';
        } else {
            log_error('fee_assignment_create_failed', [
                'error_type' => get_class($exception),
                'error_code' => $exception->getCode(),
            ]);
            $errors[] = 'The fee assignment could not be created. Check the selected records and try again.';
        }
    } catch (Throwable $exception) {
        log_error('fee_assignment_create_failed', [
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
        ]);
        $errors[] = 'The fee assignment could not be created. Check the selected records and try again.';
    }
}

$data = fee_workflow_options($db, $institutionId);
render_app_page_start(
    'Student Fee Assignments',
    'Assign an institution fee structure and monthly or quarterly plan, then generate a saved installment schedule.',
    'admin',
    ['admin-dashboard.css']
);
?>
<?php echo render_flash_messages(); ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item active" aria-current="page">Student fee assignments</li></ol></nav>
<?php foreach ($errors as $error): ?><div class="alert alert-danger" role="alert"><?php echo e($error); ?></div><?php endforeach; ?>
<section class="dashboard-panel admin-form-panel mb-4">
    <h2 class="h5">Assign a fee structure</h2>
    <?php if (!$canCreateAssignment): ?>
        <div class="admin-panel-empty"><i class="bi bi-shield-lock" aria-hidden="true"></i><strong>Fee assignment is read-only</strong><span>Your account does not have permission to create student fee assignments.</span></div>
    <?php elseif ($data['plans'] === [] || (is_string($data['academic_model'])
        ? $data['enrolments'] === [] || $data['scoped_structures'] === []
        : $data['students'] === [] || $data['structures'] === [])): ?>
        <div class="admin-panel-empty"><i class="bi bi-info-circle" aria-hidden="true"></i><strong>Assignment options are incomplete</strong><span>Add an admitted or active student assigned to an academic year, an active fee structure for the same academic year, and an active monthly or quarterly payment plan for this institution.</span><?php if ($canViewPlans): ?><a class="btn btn-outline-primary mt-2" href="<?php echo e(route_url('/admin/fees/payment-plans')); ?>">Manage payment plans</a><?php endif; ?></div>
    <?php else: ?>
        <form method="post" action="<?php echo e(route_url('/admin/fees/assignments')); ?>" class="admin-form-grid">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create_assignment">
            <?php if (is_string($data['academic_model'])): ?>
            <label class="admin-form-field">Active enrolment
                <select class="form-select" name="enrolment_id" required>
                    <option value="">Select an active enrolment</option>
                    <?php foreach ($data['enrolments'] as $enrolment): ?>
                        <?php $placementParts = array_filter([$enrolment['class_name'] ?? null, $enrolment['section_name'] ?? null, $enrolment['course_name'] ?? null, $enrolment['term_name'] ?? null, $enrolment['batch_name'] ?? null], static fn($part): bool => is_string($part) && $part !== ''); ?>
                        <option value="<?php echo e((string) $enrolment['enrolment_id']); ?>"><?php echo e($enrolment['full_name'] . ' (' . $enrolment['student_code'] . ') — ' . $enrolment['academic_year_name'] . ' — ' . implode(' / ', $placementParts)); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="admin-form-field">Applicable fee structure
                <select class="form-select" name="fee_structure_id" required>
                    <option value="">Select a fee structure</option>
                    <?php foreach ($data['scoped_structures'] as $structure): ?>
                        <option value="<?php echo e((string) $structure['id']); ?>"><?php echo e($structure['name'] . ' — ' . $structure['academic_year_name'] . ' — ' . $structure['target_model'] . ' / ' . $structure['fee_group_code']); ?></option>
                    <?php endforeach; ?>
                </select>
                <small>Class/programme components are inherited; a matching section/term component replaces the same category.</small>
            </label>
            <?php else: ?>
            <label class="admin-form-field">Student
                <select class="form-select" name="student_id" required>
                    <option value="">Select a student</option>
                    <?php foreach ($data['students'] as $student): ?>
                        <option value="<?php echo e((string) $student['id']); ?>"><?php echo e($student['full_name'] . ' (' . $student['student_code'] . ') — ' . $student['academic_year_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="admin-form-field">Fee structure
                <select class="form-select" name="fee_structure_id" required>
                    <option value="">Select a structure</option>
                    <?php foreach ($data['structures'] as $structure): ?>
                        <option value="<?php echo e((string) $structure['id']); ?>"><?php echo e($structure['name'] . ' — ' . $structure['academic_year_name'] . ' — ₹' . $structure['total_amount']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php endif; ?>
            <label class="admin-form-field">Payment plan
                <select class="form-select" name="payment_plan_id" required>
                    <option value="">Select a payment plan</option>
                    <?php foreach ($data['plans'] as $plan): ?>
                        <option value="<?php echo e((string) $plan['id']); ?>"><?php echo e($plan['name'] . ' — ' . ucfirst($plan['plan_type']) . ' (' . $plan['installment_count'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="admin-form-actions"><button class="btn btn-cobalt" type="submit">Assign fee structure</button></div>
        </form>
    <?php endif; ?>
</section>
<section class="dashboard-panel">
    <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
        <h2 class="h5 mb-0">Existing assignments</h2>
        <a class="btn btn-outline-primary btn-sm" href="<?php echo e(route_url('/admin/fees/installments')); ?>">View installments</a>
    </div>
    <?php if ($data['assignments'] === []): ?>
        <div class="admin-panel-empty"><i class="bi bi-inbox" aria-hidden="true"></i><strong>No fee assignments</strong><span>Assignments created for this institution will appear here.</span></div>
    <?php else: ?>
        <div class="table-responsive"><table class="table admin-panel-table align-middle">
            <thead><tr><th>Student</th><th>Fee structure</th><th>Academic year</th><th>Plan</th><th>Total fee</th><th>Discount</th><th>Paid</th><th>Outstanding</th><th>Schedule</th><th>Action</th></tr></thead>
            <tbody><?php foreach ($data['assignments'] as $assignment): ?>
                <tr>
                    <td><?php echo e($assignment['student_name'] . ' (' . $assignment['student_code'] . ')'); ?></td>
                    <td><?php echo e($assignment['fee_structure_name']); ?></td>
                    <td><?php echo e($assignment['academic_year_name']); ?></td>
                    <td><?php echo e($assignment['plan_name'] ?? 'Plan unavailable'); ?></td>
                    <td>₹<?php echo e($assignment['total_fee']); ?></td>
                    <td>₹<?php echo e($assignment['total_discount']); ?></td>
                    <td>₹<?php echo e($assignment['total_paid']); ?></td>
                    <td>₹<?php echo e($assignment['outstanding_amount']); ?></td>
                    <td>
                        <?php if ((int) $assignment['installment_count_existing'] > 0): ?>
                            <?php echo e($assignment['installment_count_existing'] . ' installment(s), ' . $assignment['first_due_date'] . ' – ' . $assignment['last_due_date']); ?>
                        <?php else: ?>Not generated<?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int) $assignment['installment_count_existing'] === 0 && $assignment['plan_type'] !== null): ?>
                            <form method="post" action="<?php echo e(route_url('/admin/fees/generate-installments')); ?>" class="d-flex flex-wrap gap-2 align-items-end">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="assignment_id" value="<?php echo e((string) $assignment['id']); ?>">
                                <label class="small">First due date<input class="form-control form-control-sm" type="date" name="first_due_date" required></label>
                                <button class="btn btn-sm btn-cobalt" type="submit">Generate schedule</button>
                            </form>
                        <?php elseif ((int) $assignment['installment_count_existing'] > 0): ?>
                            <span class="text-muted">Schedule is locked</span>
                        <?php else: ?>
                            <span class="text-muted">Active plan required</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <p class="text-muted mb-0">Showing up to <?php echo e(number_format(count($data['assignments']))); ?> most recent assignments. Existing schedules are never rewritten.</p>
    <?php endif; ?>
</section>
<?php render_app_page_end('admin'); ?>
