<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/parent-management-service.php';
require_once __DIR__ . '/../../services/parent-email-verification-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$institutionId = parent_management_institution_id();
$parentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!is_int($parentId) || $parentId < 1) {
    http_response_code(404);
    exit('Parent not found.');
}
$parent = parent_management_get_parent(db_connect(), $institutionId, $parentId);
if ($parent === null) {
    http_response_code(404);
    exit('Parent not found.');
}
$parentStatusClass = match ($parent['status']) {
    'active', 'inactive' => $parent['status'],
    'suspended' => 'suspended',
    default => 'pending',
};

render_app_page_start(
    'Parent Details',
    'Review this parent profile and its linked students.',
    'admin',
    ['admin-parents.css']
);
?>
<?php echo render_flash_messages(); ?>
<nav aria-label="Breadcrumb">
    <ol class="breadcrumb parents-breadcrumb">
        <li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/parents/index')); ?>">Parents</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?php echo e($parent['full_name']); ?></li>
    </ol>
</nav>
<section class="parents-detail-panel" aria-labelledby="parent-detail-title">
    <div class="parents-detail-heading">
        <div class="parent-identity">
            <span class="parent-avatar parent-avatar-large" aria-hidden="true"><?php
                $initials = implode('', array_map(
                    static fn (string $part): string => strtoupper(substr($part, 0, 1)),
                    explode(' ', (string) $parent['full_name'])
                ));
                echo e($initials);
            ?></span>
            <div>
                <span class="parents-record-reference">Parent #<?php echo e((string) $parent['id']); ?></span>
                <h2 id="parent-detail-title"><?php echo e($parent['full_name']); ?></h2>
                <span class="parent-status parent-status-<?php echo e($parentStatusClass); ?>"><i aria-hidden="true"></i><?php echo e(ucwords(str_replace('_', ' ', $parent['status']))); ?></span>
            </div>
        </div>
        <div class="parents-detail-actions">
            <?php if (user_has_permission('parents.manage')): ?>
                <a class="parents-submit-button" href="<?php echo e(route_url('/admin/parents/edit') . '?id=' . $parentId); ?>"><i class="bi bi-pencil" aria-hidden="true"></i>Edit Parent</a>
                <?php if (!$parent['email_verified']): ?>
                    <form action="<?php echo e(route_url('/admin/parents/send-verification')); ?>" method="post">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="parent_id" value="<?php echo e((string) $parentId); ?>">
                        <button class="parents-cancel-button" type="submit">
                            <i class="bi bi-envelope-check" aria-hidden="true"></i>Send Verification Link
                        </button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
            <a class="parents-cancel-button" href="<?php echo e(route_url('/admin/parents/index')); ?>">Back to Parents</a>
        </div>
    </div>

    <div class="parents-detail-grid">
        <article class="parents-detail-card">
            <h3><i class="bi bi-person-vcard" aria-hidden="true"></i>Contact &amp; account</h3>
            <dl class="parents-detail-list">
                <div><dt>Email address</dt><dd><?php echo e($parent['email']); ?></dd></div>
                <div><dt>Email verification</dt><dd><?php echo $parent['email_verified'] ? 'Verified' : 'Not verified'; ?></dd></div>
                <div><dt>Mobile number</dt><dd><?php echo e($parent['mobile']); ?></dd></div>
                <div><dt>Mobile verification</dt><dd><?php echo $parent['mobile_verified_at'] !== null ? 'Verified' : 'Not verified'; ?></dd></div>
                <div><dt>Alternate mobile</dt><dd><?php echo e($parent['alternate_mobile'] ?? 'Not provided'); ?></dd></div>
                <div><dt>Relationship</dt><dd><?php echo e(ucfirst((string) $parent['relationship_to_student'])); ?></dd></div>
                <div><dt>Account status</dt><dd><?php echo e(ucwords(str_replace('_', ' ', (string) $parent['account_status']))); ?></dd></div>
                <div><dt>Created</dt><dd><time datetime="<?php echo e(date('c', strtotime((string) $parent['created_at']))); ?>"><?php echo e(date('j M Y, g:i a', strtotime((string) $parent['created_at']))); ?></time></dd></div>
            </dl>
        </article>
        <article class="parents-detail-card">
            <h3><i class="bi bi-geo-alt" aria-hidden="true"></i>Address &amp; emergency contact</h3>
            <dl class="parents-detail-list">
                <div><dt>Address</dt><dd><?php
                    $address = array_filter([
                        $parent['address_line1'],
                        $parent['address_line2'],
                        $parent['city'],
                        $parent['state'],
                        $parent['postal_code'],
                        $parent['country'],
                    ], static fn (mixed $part): bool => is_string($part) && trim($part) !== '');
                    echo $address !== [] ? e(implode(', ', $address)) : 'Not provided';
                ?></dd></div>
                <div><dt>Emergency contact</dt><dd><?php echo e($parent['emergency_contact'] ?? 'Not provided'); ?></dd></div>
            </dl>
        </article>
    </div>

    <section class="parents-linked-panel" aria-labelledby="linked-students-title">
        <div class="parents-linked-heading">
            <div>
                <h3 id="linked-students-title">Linked students</h3>
                <p>Active parent-student relationships in this institution.</p>
            </div>
            <span class="parent-student-count"><i class="bi bi-mortarboard" aria-hidden="true"></i><?php echo e((string) count($parent['linked_students'])); ?><span><?php echo count($parent['linked_students']) === 1 ? 'student' : 'students'; ?></span></span>
        </div>
        <?php if ($parent['linked_students'] === []): ?>
            <div class="parents-no-links">No active student links are recorded for this parent.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table parents-linked-table align-middle">
                    <thead><tr><th scope="col">Student</th><th scope="col">Student code</th><th scope="col">Student status</th><th scope="col">Relationship</th><th scope="col">Access</th></tr></thead>
                    <tbody>
                        <?php foreach ($parent['linked_students'] as $student): ?>
                            <tr>
                                <td><?php echo e($student['full_name']); ?></td>
                                <td><?php echo e($student['student_code']); ?></td>
                                <td><?php echo e(ucfirst($student['student_status'])); ?></td>
                                <td><?php echo e(ucfirst($student['relationship_type'])); ?><?php echo (int) $student['is_primary'] === 1 ? ' · Primary' : ''; ?></td>
                                <td>
                                    <?php
                                    $access = [];
                                    if ((int) $student['can_view_academics'] === 1) $access[] = 'Academics';
                                    if ((int) $student['can_view_fees'] === 1) $access[] = 'Fees';
                                    if ((int) $student['can_make_payments'] === 1) $access[] = 'Payments';
                                    echo e($access === [] ? 'No access' : implode(', ', $access));
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <?php if (user_has_permission('parents.manage')): ?>
        <div class="parents-status-control">
            <div>
                <strong>Parent access</strong>
                <p>Deactivation preserves historical records. Activating a profile never verifies email; email OTP sign-in remains unavailable until the approved verification process is complete.</p>
            </div>
            <?php if (in_array($parent['status'], ['active', 'inactive', 'pending_verification'], true)): ?>
                <form action="<?php echo e(route_url('/admin/parents/toggle-status')); ?>" method="post" onsubmit="return confirm('Change this parent’s access? Historical payments and student relationships will be preserved.');">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="parent_id" value="<?php echo e((string) $parentId); ?>">
                    <button class="parents-status-button<?php echo $parent['status'] === 'active' ? ' is-danger' : ''; ?>" type="submit">
                        <i class="bi <?php echo $parent['status'] === 'active' ? 'bi-person-dash' : 'bi-person-check'; ?>" aria-hidden="true"></i>
                        <?php echo $parent['status'] === 'active' ? 'Deactivate Parent' : ($parent['status'] === 'pending_verification' ? 'Activate Parent Profile' : 'Reactivate Parent'); ?>
                    </button>
                </form>
            <?php else: ?>
                <span class="parents-status-note">Suspended records require separate review.</span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>
<?php render_app_page_end('admin'); ?>
