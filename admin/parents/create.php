<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../includes/components/parent-profile-form.php';
require_once __DIR__ . '/../../services/parent-management-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$user = current_user();
if ($user === null) {
    http_response_code(403);
    exit('Forbidden.');
}
$db = db_connect();
$old = $_SESSION['parent_management_old'] ?? [];
if (!is_array($old) || ($old['form'] ?? null) !== 'create') {
    $old = [];
}
$isSuperAdmin = parent_management_is_super_admin();
$institutions = $isSuperAdmin ? parent_management_get_active_institutions($db) : [];
$institutionId = null;
if ($isSuperAdmin) {
    $requestedInstitutionId = $_GET['institution_id']
        ?? $old['institution_id']
        ?? admin_selected_institution_id();
    if ($requestedInstitutionId !== null && $requestedInstitutionId !== '') {
        try {
            $institutionId = parent_management_resolve_create_institution(
                $db,
                $user,
                $requestedInstitutionId
            );
        } catch (InvalidArgumentException $exception) {
            set_flash('danger', $exception->getMessage());
        }
    }
} else {
    $institutionId = parent_management_resolve_create_institution($db, $user, null);
}
$students = $institutionId === null
    ? []
    : parent_management_get_student_options($db, $institutionId);
if (!$isSuperAdmin || $institutionId !== null) {
    unset($_SESSION['parent_management_old']);
}
render_app_page_start(
    'Add Parent',
    'Create a parent profile and optionally link eligible students.',
    'admin',
    ['admin-parents.css']
);
?>
<?php echo render_flash_messages(); ?>
<nav aria-label="Breadcrumb">
    <ol class="breadcrumb parents-breadcrumb">
        <li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/parents/index')); ?>">Parents</a></li>
        <li class="breadcrumb-item active" aria-current="page">Add Parent</li>
    </ol>
</nav>
<section class="parents-form-panel" aria-labelledby="parent-form-title">
    <div class="parents-form-heading">
        <div>
            <span class="parents-demo-banner"><i class="bi bi-database" aria-hidden="true"></i> Live institution records</span>
            <h2 id="parent-form-title">Parent details</h2>
            <p>New accounts remain unverified until the approved email verification process is completed.</p>
        </div>
    </div>
    <?php if ($isSuperAdmin): ?>
        <form action="<?php echo e(route_url('/admin/parents/create')); ?>" method="get" class="parents-form-wide">
            <label class="form-label" for="parent-institution-id">Institution <span aria-hidden="true">*</span></label>
            <div class="input-group">
                <select class="form-select" id="parent-institution-id" name="institution_id" required>
                    <option value="">Choose an active institution</option>
                    <?php foreach ($institutions as $institution): ?>
                        <option value="<?php echo e((string) $institution['id']); ?>"<?php echo $institutionId === $institution['id'] ? ' selected' : ''; ?>>
                            <?php echo e($institution['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-outline-primary" type="submit">Load institution</button>
            </div>
            <small class="parents-field-help">Only active institutions are listed. Load the selected institution to see only its eligible students.</small>
        </form>
    <?php endif; ?>
    <?php if ($institutionId !== null): ?>
        <?php if ($isSuperAdmin): ?>
            <p class="parents-form-note" role="note">
                Creating this parent in institution
                <strong><?php echo e((string) $institutionId); ?></strong>.
                The institution selection is revalidated when the form is submitted.
            </p>
        <?php endif; ?>
        <?php render_parent_profile_form(
            '/admin/parents/store',
            [],
            $students,
            $old,
            false,
            null,
            $isSuperAdmin ? $institutionId : null
        ); ?>
    <?php else: ?>
        <p class="parents-form-note" role="note">Choose an active institution before entering a new parent profile.</p>
    <?php endif; ?>
</section>
<?php render_app_page_end('admin'); ?>
