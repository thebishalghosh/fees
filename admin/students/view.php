<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/student-management-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$institutionId = student_management_institution_id();
$studentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!is_int($studentId) || $studentId < 1) {
    http_response_code(404);
    exit('Student not found.');
}
$student = student_management_get_student(db_connect(), $institutionId, $studentId);
if ($student === null) {
    http_response_code(404);
    exit('Student not found.');
}
$canManage = user_has_permission('students.manage');
$statusClass = in_array($student['status'], ['active', 'admitted', 'prospect'], true)
    ? $student['status']
    : 'inactive';

render_app_page_start('Student Details', 'View saved student, academic placement and parent information.', 'admin', ['admin-students.css']);
?>
<?php echo render_flash_messages(); ?>
<nav aria-label="Breadcrumb">
    <ol class="breadcrumb students-breadcrumb">
        <li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/students/index')); ?>">Students</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?php echo e($student['full_name']); ?></li>
    </ol>
</nav>

<section class="students-detail-panel" aria-labelledby="student-detail-title">
    <div class="students-detail-header">
        <div class="students-detail-identity">
            <?php
            $initials = implode('', array_map(
                static fn (string $part): string => strtoupper(substr($part, 0, 1)),
                array_filter(explode(' ', trim((string) $student['full_name'])))
            ));
            ?>
            <span class="students-avatar students-avatar-large" aria-hidden="true"><?php echo e($initials); ?></span>
            <div>
                <span class="students-record-reference">Student #<?php echo e((string) $student['id']); ?> · <?php echo e($student['student_code']); ?></span>
                <h2 id="student-detail-title"><?php echo e($student['full_name']); ?></h2>
                <span class="students-status students-status-<?php echo e($statusClass); ?>"><i aria-hidden="true"></i><?php echo e(ucfirst((string) $student['status'])); ?></span>
            </div>
        </div>
        <div class="students-detail-actions">
            <?php if ($canManage): ?>
                <a class="students-primary-button" href="<?php echo e(route_url('/admin/students/edit') . '?id=' . $studentId); ?>"><i class="bi bi-pencil" aria-hidden="true"></i>Edit Student</a>
                <?php if (in_array($student['status'], ['prospect', 'admitted', 'active', 'inactive'], true)): ?>
                    <form action="<?php echo e(route_url('/admin/students/toggle-status')); ?>" method="post" onsubmit="return confirm('Change this student status? All student and financial history will be preserved.');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="student_id" value="<?php echo e((string) $studentId); ?>">
                        <button class="students-secondary-button" type="submit"><?php echo $student['status'] === 'active' ? 'Deactivate' : 'Activate'; ?></button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (user_has_permission('students.view')): ?>
                <a class="students-secondary-button" href="<?php echo e(route_url('/admin/students/admission-form') . '?id=' . $studentId); ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>View Registration Form PDF</a>
                <a class="students-secondary-button" href="<?php echo e(route_url('/admin/students/admission-form') . '?id=' . $studentId . '&download=1'); ?>"><i class="bi bi-download" aria-hidden="true"></i>Download Registration Form PDF</a>
            <?php endif; ?>
            <a class="students-secondary-button" href="<?php echo e(route_url('/admin/students/index')); ?>">Back to Students</a>
        </div>
    </div>

    <div class="students-detail-grid">
        <article class="students-detail-card">
            <h3><i class="bi bi-person-vcard" aria-hidden="true"></i>Personal and admission details</h3>
            <dl class="students-detail-list">
                <div><dt>Student ID</dt><dd><?php echo e($student['student_code']); ?></dd></div>
                <div><dt>Date of birth</dt><dd><?php echo e($student['date_of_birth'] ? date('j M Y', strtotime((string) $student['date_of_birth'])) : 'Not provided'); ?></dd></div>
                <div><dt>Gender</dt><dd><?php echo e($student['gender'] !== null ? ucwords(str_replace('_', ' ', (string) $student['gender'])) : 'Not specified'); ?></dd></div>
                <div><dt>Admission date</dt><dd><?php echo e(date('j M Y', strtotime((string) $student['admission_date']))); ?></dd></div>
                <div><dt>Record created</dt><dd><time datetime="<?php echo e(date('c', strtotime((string) $student['created_at']))); ?>"><?php echo e(date('j M Y, g:i a', strtotime((string) $student['created_at']))); ?></time></dd></div>
            </dl>
        </article>
        <article class="students-detail-card">
            <h3><i class="bi bi-mortarboard" aria-hidden="true"></i>Institution and academic placement</h3>
            <dl class="students-detail-list">
                <div><dt>Institution</dt><dd><?php echo e((string) $student['institution_name']); ?></dd></div>
                <div><dt>Academic year</dt><dd><?php echo e($student['academic_year_name'] ?? 'Not assigned'); ?></dd></div>
                <div><dt>Course</dt><dd><?php echo e($student['course_name'] ?? 'Not assigned'); ?></dd></div>
                <div><dt>Class</dt><dd><?php echo e($student['class_name'] ?? 'Not assigned'); ?></dd></div>
                <div><dt>Batch</dt><dd><?php echo e($student['batch_name'] ?? 'Not assigned'); ?></dd></div>
            </dl>
        </article>
    </div>
</section>

<section class="students-detail-panel students-related-panel" aria-labelledby="student-parents-title">
    <div class="students-related-heading">
        <div><h2 id="student-parents-title">Linked parents and guardians</h2><p>Current active relationships recorded for this student.</p></div>
        <span class="students-linked-count"><i class="bi bi-people" aria-hidden="true"></i><?php echo e((string) count($student['parents'])); ?></span>
    </div>
    <?php if ($student['parents'] === []): ?>
        <div class="students-empty-state students-inline-empty"><strong>No active parent links</strong><p>Use Edit Student to link an eligible parent profile from this institution.</p></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table students-table students-linked-table align-middle">
                <thead><tr><th scope="col">Parent / Guardian</th><th scope="col">Email</th><th scope="col">Mobile</th><th scope="col">Relationship</th><th scope="col">Account</th></tr></thead>
                <tbody>
                    <?php foreach ($student['parents'] as $parent): ?>
                        <tr>
                            <td><?php echo e($parent['full_name']); ?><?php if ((bool) $parent['is_primary']): ?> <span class="students-primary-tag">Primary</span><?php endif; ?></td>
                            <td><?php echo e($parent['email'] ?? 'Not provided'); ?></td>
                            <td><?php echo e($parent['mobile']); ?></td>
                            <td><?php echo e(ucfirst((string) $parent['relationship_type'])); ?></td>
                            <td><span class="students-status students-status-<?php echo $parent['parent_status'] === 'active' ? 'active' : 'inactive'; ?>"><i aria-hidden="true"></i><?php echo e(ucwords(str_replace('_', ' ', (string) $parent['parent_status']))); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="students-detail-panel students-fee-summary" aria-labelledby="student-fees-title">
    <div class="students-related-heading">
        <div><h2 id="student-fees-title">Fee assignment summary</h2><p>Read-only totals from existing fee assignments; no fee operations are performed here.</p></div>
    </div>
    <div class="students-fee-metrics">
        <div><span>Assignments</span><strong><?php echo e(number_format((int) $student['fee_summary']['assignment_count'])); ?></strong></div>
        <div><span>Total paid</span><strong><?php echo e(number_format((float) $student['fee_summary']['total_paid'], 2)); ?></strong></div>
        <div><span>Outstanding</span><strong><?php echo e(number_format((float) $student['fee_summary']['outstanding_amount'], 2)); ?></strong></div>
    </div>
</section>
<?php render_app_page_end('admin'); ?>
