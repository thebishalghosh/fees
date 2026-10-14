<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/page-placeholder.php';
require_once __DIR__ . '/../services/parent-portal-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$context = parent_portal_require_current_parent(db_connect());
$institution = $context['parent'];
$address = array_filter([
    $institution['institution_address_line1'],
    $institution['institution_address_line2'],
    $institution['institution_city'],
    $institution['institution_state'],
    $institution['institution_postal_code'],
    $institution['institution_country'],
], static fn (mixed $part): bool => is_string($part) && trim($part) !== '');

render_app_page_start('Help & Contact', 'Find the right contact for questions about your parent account and children.', 'parent', ['parent-children.css']);
?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb parent-children-breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/parent/dashboard')); ?>">Overview</a></li><li class="breadcrumb-item active" aria-current="page">Help &amp; Contact</li></ol></nav>
<div class="parent-student-info-grid">
    <section class="parent-children-panel parent-student-info-card"><h2>Contact your institution</h2><h3><?php echo e($institution['institution_name']); ?></h3>
        <?php if (is_string($institution['institution_email']) && $institution['institution_email'] !== ''): ?><p><i class="bi bi-envelope me-2" aria-hidden="true"></i><a href="mailto:<?php echo e($institution['institution_email']); ?>"><?php echo e($institution['institution_email']); ?></a></p><?php endif; ?>
        <?php if (is_string($institution['institution_phone']) && $institution['institution_phone'] !== ''): ?><p><i class="bi bi-telephone me-2" aria-hidden="true"></i><a href="tel:<?php echo e($institution['institution_phone']); ?>"><?php echo e($institution['institution_phone']); ?></a></p><?php endif; ?>
        <?php if ($address !== []): ?><p><i class="bi bi-geo-alt me-2" aria-hidden="true"></i><?php echo e(implode(', ', $address)); ?></p><?php endif; ?>
        <?php if ((!is_string($institution['institution_email']) || $institution['institution_email'] === '') && (!is_string($institution['institution_phone']) || $institution['institution_phone'] === '') && $address === []): ?><p class="text-secondary">Institution contact details are not available here. Please contact the institution administrator through your usual channel.</p><?php endif; ?>
    </section>
    <section class="parent-children-panel parent-student-info-card"><h2>Common questions</h2>
        <dl class="parent-help-list">
            <div><dt>Missing linked children</dt><dd>Ask your institution administrator to review the parent-student link.</dd></div>
            <div><dt>Incorrect student information</dt><dd>Contact the institution administrator to request a correction.</dd></div>
            <div><dt>Fee discrepancy</dt><dd>Share the student admission number and fee assignment details with the institution’s fee office.</dd></div>
            <div><dt>Payment or receipt issue</dt><dd>Keep the payment reference and contact the institution’s accounts office.</dd></div>
            <div><dt>Email verification or sign-in</dt><dd>Use the <a href="<?php echo e(route_url('/parent/account')); ?>">Parent Account</a> page to review verification or request another verification email.</dd></div>
        </dl>
    </section>
</div>
<?php render_app_page_end('parent'); ?>
