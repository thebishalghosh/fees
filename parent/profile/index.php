<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/parent-portal-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$db = db_connect();
$context = parent_portal_require_current_parent($db);
$profile = parent_portal_get_profile(
    $db,
    (int) $context['parent']['parent_id'],
    (int) $context['user']['institution_id'],
    (int) $context['user']['id']
);
if ($profile === null) {
    http_response_code(404);
    exit('Parent profile not found.');
}

render_app_page_start('My Profile', 'Review your contact details and update the editable parts of your profile.', 'parent', ['parent-children.css']);
?>
<?php echo render_flash_messages(); ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb parent-children-breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/parent/dashboard')); ?>">Overview</a></li><li class="breadcrumb-item active" aria-current="page">My Profile</li></ol></nav>
<section class="parent-children-panel parent-student-detail">
    <div class="parent-student-detail-heading"><div><h2>Contact information</h2><p class="mb-0 text-secondary">Your account email and verified mobile are managed by the institution.</p></div><a class="parent-child-back-link" href="<?php echo e(route_url('/parent/account')); ?>">Account security</a></div>
    <form method="post" action="<?php echo e(route_url('/parent/profile/update')); ?>" class="parent-profile-form">
        <?php echo csrf_field(); ?>
        <div class="parent-student-info-grid">
            <div class="parent-profile-field"><label for="full_name" class="form-label">Full name</label><input class="form-control" id="full_name" name="full_name" maxlength="150" required value="<?php echo e($profile['full_name']); ?>"></div>
            <div class="parent-profile-field"><label for="relationship_to_student" class="form-label">Relationship</label><input class="form-control" id="relationship_to_student" value="<?php echo e($profile['relationship_to_student']); ?>" disabled></div>
            <div class="parent-profile-field"><label for="account_email" class="form-label">Account email</label><input class="form-control" id="account_email" value="<?php echo e($profile['account_email']); ?>" disabled></div>
            <div class="parent-profile-field"><label for="mobile" class="form-label">Registered mobile</label><input class="form-control" id="mobile" value="<?php echo e($profile['mobile']); ?>" disabled></div>
            <div class="parent-profile-field"><label for="alternate_mobile" class="form-label">Alternate mobile</label><input class="form-control" id="alternate_mobile" name="alternate_mobile" maxlength="20" value="<?php echo e($profile['alternate_mobile']); ?>"></div>
            <div class="parent-profile-field"><label for="emergency_contact" class="form-label">Emergency contact</label><input class="form-control" id="emergency_contact" name="emergency_contact" maxlength="30" value="<?php echo e($profile['emergency_contact']); ?>"></div>
            <div class="parent-profile-field"><label for="address_line1" class="form-label">Address line 1</label><input class="form-control" id="address_line1" name="address_line1" maxlength="255" value="<?php echo e($profile['address_line1']); ?>"></div>
            <div class="parent-profile-field"><label for="address_line2" class="form-label">Address line 2</label><input class="form-control" id="address_line2" name="address_line2" maxlength="255" value="<?php echo e($profile['address_line2']); ?>"></div>
            <div class="parent-profile-field"><label for="city" class="form-label">City</label><input class="form-control" id="city" name="city" maxlength="120" value="<?php echo e($profile['city']); ?>"></div>
            <div class="parent-profile-field"><label for="state" class="form-label">State</label><input class="form-control" id="state" name="state" maxlength="120" value="<?php echo e($profile['state']); ?>"></div>
            <div class="parent-profile-field"><label for="postal_code" class="form-label">Postal code</label><input class="form-control" id="postal_code" name="postal_code" maxlength="20" value="<?php echo e($profile['postal_code']); ?>"></div>
            <div class="parent-profile-field"><label for="country" class="form-label">Country</label><input class="form-control" id="country" name="country" maxlength="120" value="<?php echo e($profile['country']); ?>"></div>
        </div>
        <button class="btn btn-cobalt mt-4" type="submit">Save profile</button>
    </form>
</section>
<?php render_app_page_end('parent'); ?>
