<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/page-placeholder.php';
require_once __DIR__ . '/../services/parent-portal-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();

$studentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!is_int($studentId) || $studentId < 1) {
    http_response_code(404);
    exit('Student not found.');
}
$user = current_user();
if ($user === null || $user['user_type'] !== 'parent') {
    http_response_code(403);
    exit('Forbidden.');
}
$db = db_connect();
$parent = parent_portal_get_parent_context(
    $db,
    (int) $user['id'],
    (int) $user['institution_id']
);
if ($parent === null) {
    http_response_code(403);
    exit('Forbidden.');
}
$student = parent_portal_get_student(
    $db,
    (int) $parent['parent_id'],
    (int) $user['institution_id'],
    $studentId
);
if ($student === null) {
    http_response_code(404);
    exit('Student not found.');
}
$statusClass = in_array($student['student_status'], ['active', 'admitted'], true)
    ? 'is-active'
    : 'is-inactive';

render_app_page_start(
    'Student Details',
    'Review your linked child’s academic and fee information.',
    'parent',
    ['parent-children.css']
);
?>
<nav aria-label="Breadcrumb">
    <ol class="breadcrumb parent-children-breadcrumb">
        <li class="breadcrumb-item"><a href="<?php echo e(route_url('/parent/dashboard')); ?>">Overview</a></li>
        <li class="breadcrumb-item"><a href="<?php echo e(route_url('/parent/children')); ?>">My Children</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?php echo e($student['full_name']); ?></li>
    </ol>
</nav>

<section class="parent-children-panel parent-student-detail" aria-labelledby="student-detail-title">
    <div class="parent-student-detail-heading">
        <div class="parent-student-detail-identity">
            <span class="parent-child-avatar is-large" aria-hidden="true"><?php echo e(parent_portal_initials((string) $student['full_name'])); ?></span>
            <div>
                <span class="parent-child-code">Admission no. <?php echo e($student['student_code']); ?></span>
                <h2 id="student-detail-title"><?php echo e($student['full_name']); ?></h2>
                <span class="parent-child-status <?php echo e($statusClass); ?>"><?php echo e(ucwords(str_replace('_', ' ', (string) $student['student_status']))); ?></span>
            </div>
        </div>
        <div class="parent-student-detail-actions">
            <a class="parent-registration-link" href="<?php echo e(route_url('/parent/admissions/download-pdf') . '?id=' . (int) $student['id']); ?>" target="_blank" rel="noopener">
                <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>View Registration Form
            </a>
            <a class="parent-registration-link" href="<?php echo e(route_url('/parent/admissions/download-pdf') . '?id=' . (int) $student['id'] . '&download=1'); ?>">
                <i class="bi bi-download" aria-hidden="true"></i>Download PDF
            </a>
            <a class="parent-child-back-link" href="<?php echo e(route_url('/parent/children')); ?>">
                <i class="bi bi-arrow-left" aria-hidden="true"></i>Back to My Children
            </a>
        </div>
    </div>

    <div class="parent-student-info-grid">
        <article class="parent-student-info-card">
            <h3><i class="bi bi-mortarboard" aria-hidden="true"></i>Academic information</h3>
            <dl class="parent-child-facts">
                <div><dt>Class</dt><dd><?php echo e($student['class_name'] ?? 'Not assigned'); ?></dd></div>
                <div><dt>Batch</dt><dd><?php echo e($student['batch_name'] ?? 'Not assigned'); ?></dd></div>
                <div><dt>Academic year</dt><dd><?php echo e($student['academic_year_name'] ?? 'Not assigned'); ?></dd></div>
                <div><dt>Admission date</dt><dd><?php echo e(date('j M Y', strtotime((string) $student['admission_date']))); ?></dd></div>
                <div><dt>Date of birth</dt><dd><?php echo $student['date_of_birth'] !== null ? e(date('j M Y', strtotime((string) $student['date_of_birth']))) : 'Not provided'; ?></dd></div>
                <div><dt>Gender</dt><dd><?php echo e($student['gender'] !== null ? ucwords(str_replace('_', ' ', (string) $student['gender'])) : 'Not provided'); ?></dd></div>
                <div><dt>Your relationship</dt><dd><?php echo e(ucfirst((string) $student['relationship_type'])); ?></dd></div>
            </dl>
        </article>

        <?php if ($student['can_view_fees']): ?>
            <article class="parent-student-info-card">
                <h3><i class="bi bi-cash-stack" aria-hidden="true"></i>Fee summary <span class="parent-fee-note">INR</span></h3>
                <dl class="parent-fee-summary-list">
                    <div><dt>Assigned fees</dt><dd>₹<?php echo e(number_format((float) $student['assigned_fee_total'], 2)); ?></dd></div>
                    <div><dt>Paid</dt><dd>₹<?php echo e(number_format((float) $student['total_paid'], 2)); ?></dd></div>
                    <div class="is-outstanding"><dt>Outstanding</dt><dd>₹<?php echo e(number_format((float) $student['outstanding_amount'], 2)); ?></dd></div>
                </dl>
                <p class="parent-fee-footnote">Totals use active, completed, and suspended fee assignments recorded by the institution.</p>
            </article>
        <?php endif; ?>
    </div>

    <?php if ($student['can_view_fees']): ?>
        <section class="parent-payments-panel" aria-labelledby="student-payments-title">
            <div class="parent-payments-heading">
                <div>
                    <h3 id="student-payments-title">Recent payments</h3>
                    <p>Verified payments recorded for this student. Refunds are reflected in the net amount.</p>
                </div>
            </div>
            <?php if ($student['payments'] === []): ?>
                <div class="parent-payments-empty">No verified payments are recorded for this student.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table parent-payments-table align-middle">
                        <thead><tr><th scope="col">Date</th><th scope="col">Receipt</th><th scope="col">Status</th><th scope="col" class="text-end">Net amount</th></tr></thead>
                        <tbody>
                            <?php foreach ($student['payments'] as $payment): ?>
                                <tr>
                                    <td><time datetime="<?php echo e(date('c', strtotime((string) $payment['paid_at']))); ?>"><?php echo e(date('j M Y', strtotime((string) $payment['paid_at']))); ?></time></td>
                                    <td><?php echo e($payment['receipt_number'] ?? '—'); ?></td>
                                    <td><?php echo e(ucwords(str_replace('_', ' ', (string) $payment['status']))); ?></td>
                                    <td class="text-end"><?php echo e((string) $payment['currency']); ?> <?php echo e(number_format((float) $payment['net_amount'], 2)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</section>
<?php render_app_page_end('parent'); ?>
