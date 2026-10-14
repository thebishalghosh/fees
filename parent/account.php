<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/page-placeholder.php';
require_once __DIR__ . '/../services/parent-portal-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$context = parent_portal_require_current_parent(db_connect());
$parent = $context['parent'];

render_app_page_start('Parent Account', 'Review account status and email verification.', 'parent', ['parent-children.css']);
?>
<?php echo render_flash_messages(); ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb parent-children-breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/parent/dashboard')); ?>">Overview</a></li><li class="breadcrumb-item active" aria-current="page">Parent Account</li></ol></nav>
<section class="parent-children-panel parent-student-detail">
    <h2>Account security</h2>
    <dl class="parent-fee-summary-list parent-payment-detail-facts">
        <div><dt>Account</dt><dd><?php echo e($parent['full_name']); ?></dd></div>
        <div><dt>Email address</dt><dd><?php echo e($parent['email']); ?></dd></div>
        <div><dt>Email verification</dt><dd><?php echo $parent['email_verified'] ? 'Verified' : 'Not verified'; ?></dd></div>
        <div><dt>Mobile verification</dt><dd><?php echo $parent['mobile_verified'] ? 'Verified' : 'Not verified'; ?></dd></div>
        <div><dt>Account status</dt><dd>Active</dd></div>
    </dl>
    <?php if (!$parent['email_verified'] && is_string($parent['institution_code']) && $parent['institution_code'] !== '' && is_string($parent['email']) && $parent['email'] !== ''): ?>
        <form class="parent-account-resend" method="post" action="<?php echo e(route_url('/request-parent-verification')); ?>">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="institution_code" value="<?php echo e($parent['institution_code']); ?>">
            <input type="hidden" name="email" value="<?php echo e($parent['email']); ?>">
            <p>Your email must be verified to use parent email OTP sign-in.</p>
            <button class="btn btn-cobalt" type="submit"><i class="bi bi-envelope-arrow-up me-1" aria-hidden="true"></i>Request verification email</button>
        </form>
    <?php elseif (!$parent['email_verified']): ?>
        <p class="text-secondary">Your email is not verified. Contact the institution administrator to confirm your account email and institution code.</p>
    <?php endif; ?>
    <div class="parent-account-actions"><a class="btn btn-outline-primary" href="<?php echo e(route_url('/parent/profile/index')); ?>">Edit profile</a><form method="post" action="<?php echo e(route_url('/logout')); ?>"><?php echo csrf_field(); ?><button class="btn btn-outline-danger" type="submit">Sign out</button></form></div>
</section>
<?php render_app_page_end('parent'); ?>
