<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layouts/head.php';
require_once __DIR__ . '/includes/layouts/header.php';
require_once __DIR__ . '/includes/layouts/footer.php';

require_http_methods(['GET', 'HEAD']);
if (($_GET['verify'] ?? null) === '1'
    && (!is_array($_SESSION['pending_auth'] ?? null)
        || ($_SESSION['pending_auth']['challenge_ready'] ?? false) !== true)) {
    set_flash('warning', 'Start sign-in again to request a new code.');
    safe_redirect('/login');
}

render_page_head('Parent & Student Fees Management');
$parentFeatures = [
    ['bi-phone-vibrate', 'Secure parent access', 'A clear account journey designed for families, with protected access and simple profile navigation.'],
    ['bi-people-fill', 'Multiple student profiles', 'Keep linked student information together while maintaining a distinct view for each learner.'],
    ['bi-calendar2-check', 'Fee schedules', 'Present fee plans, instalments and due dates in a consistent, easy-to-follow format.'],
    ['bi-receipt-cutoff', 'Payment records', 'Make payment history and issued receipts easy to find and understand.'],
    ['bi-megaphone', 'Academic updates', 'Bring schedules, examinations, announcements and notifications into one portal.'],
    ['bi-shield-check', 'Institution controls', 'Support institution-scoped records, permissions and auditability throughout the system.'],
];
$adminModules = [
    'Admissions and student records',
    'Courses, classes and batches',
    'Fee structures and instalments',
    'Payments, refunds and reconciliation',
    'Receipts, reports and notifications',
    'Institution settings and access controls',
];
?>
<body class="landing-page">
<?php render_site_header('public'); ?>
<header class="hero" id="top">
    <div class="hero-grid" aria-hidden="true"></div>
    <div class="container hero-content">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="hero-badge"><i class="bi bi-patch-check-fill" aria-hidden="true"></i> Prepared for Blue Eyes Infosoft Pvt. Ltd.</span>
                <h1>Parent &amp; Student <em>Fees Management</em> System</h1>
                <p class="hero-lead">A secure, mobile-responsive portal for admissions, student profiles, fee schedules, payments, receipts and academic updates — for schools, coaching institutes, sports academies and training centres.</p>
                <div class="d-flex flex-wrap gap-3 mt-4">
                    <a href="#contact" class="btn btn-gold">Explore the platform <i class="bi bi-arrow-right ms-2" aria-hidden="true"></i></a>
                    <a href="#panels" class="btn btn-ghost">View platform panels</a>
                </div>
                <div class="hero-points">
                    <span><i class="bi bi-people" aria-hidden="true"></i>Family-centred experience</span>
                    <span><i class="bi bi-shield-lock" aria-hidden="true"></i>Institution-scoped access</span>
                    <span><i class="bi bi-receipt" aria-hidden="true"></i>Clear payment records</span>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="product-preview">
                    <div class="preview-label"><i class="bi bi-info-circle" aria-hidden="true"></i> Illustrative interface preview · sample content</div>
                    <div class="preview-window">
                        <div class="preview-window-bar"><span class="preview-dots"><i></i><i></i><i></i></span><span><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>FeesPortal parent space</span></div>
                        <div class="preview-students">
                            <div class="preview-student active"><span class="preview-avatar">S</span><span><b>Student profile</b><small>Class and admission details</small></span></div>
                            <div class="preview-student"><span class="preview-avatar gold">+</span><span><b>Linked profiles</b><small>Switch between students</small></span></div>
                        </div>
                        <div class="preview-balance">
                            <small>Fee account overview</small>
                            <strong>Fee details</strong>
                            <div class="preview-progress"><span></span></div>
                            <div class="preview-balance-foot"><span>Instalment schedule</span><span>View details <i class="bi bi-arrow-up-right" aria-hidden="true"></i></span></div>
                        </div>
                        <div class="preview-list">
                            <div><span><i class="bi bi-check-circle-fill text-success me-2" aria-hidden="true"></i>Payment history</span><span class="preview-pill">Records</span></div>
                            <div><span><i class="bi bi-calendar-event me-2 text-primary" aria-hidden="true"></i>Academic updates</span><span class="preview-pill">Schedule</span></div>
                            <div><span><i class="bi bi-receipt me-2 text-primary" aria-hidden="true"></i>Receipts</span><span class="preview-pill">Documents</span></div>
                        </div>
                    </div>
                    <div class="preview-float"><i class="bi bi-bell-fill" aria-hidden="true"></i><span><b>Family updates</b><small>Notifications in one place</small></span></div>
                </div>
            </div>
        </div>
    </div>
</header>

<div class="trust-strip" aria-label="Platform principles">
    <div class="container"><div class="row g-3">
        <div class="col-6 col-lg-3"><div><i class="bi bi-diagram-3-fill" aria-hidden="true"></i> Institution separation</div></div>
        <div class="col-6 col-lg-3"><div><i class="bi bi-people-fill" aria-hidden="true"></i> Parent-linked students</div></div>
        <div class="col-6 col-lg-3"><div><i class="bi bi-journal-check" aria-hidden="true"></i> Fee records</div></div>
        <div class="col-6 col-lg-3"><div><i class="bi bi-shield-check" aria-hidden="true"></i> Auditable workflows</div></div>
    </div></div>
</div>

<main>
    <section class="section-pad overview-section" id="panels">
        <div class="container">
            <div class="section-intro text-center">
                <span class="eyebrow">System panels</span>
                <h2 class="section-title">One centralized platform. <span class="text-gold">Two focused experiences.</span></h2>
                <p class="section-lead mx-auto">A parent account can connect to linked students, while institution staff manage academic and fee records in a separate workspace.</p>
            </div>
            <div class="row g-4">
                <div class="col-lg-6"><article class="panel-card panel-parent">
                    <span class="panel-icon"><i class="bi bi-person-heart" aria-hidden="true"></i></span>
                    <span class="eyebrow">Family experience</span><h3>Parent portal</h3>
                    <p>Student profiles, fee schedules, payment history, receipts and academic updates in a responsive parent-facing workspace.</p>
                    <a href="<?php echo e(route_url('/parent/')); ?>" class="text-link">Explore parent workspace <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                </article></div>
                <div class="col-lg-6"><article class="panel-card panel-admin">
                    <span class="panel-icon"><i class="bi bi-building-gear" aria-hidden="true"></i></span>
                    <span class="eyebrow">Institution tools</span><h3>Administration</h3>
                    <p>Institution teams can organise admissions, students, courses, fee structures, payments and operational reporting.</p>
                    <a href="<?php echo e(route_url('/admin/')); ?>" class="text-link">Explore admin workspace <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                </article></div>
            </div>
        </div>
    </section>

    <section class="section-pad" id="features">
        <div class="container">
            <div class="section-intro text-center">
                <span class="eyebrow">Parent experience</span><h2 class="section-title">Designed around the family</h2>
                <p class="section-lead mx-auto">A consistent interface for the core information parents need to find and understand.</p>
            </div>
            <div class="row g-4">
                <?php foreach ($parentFeatures as [$icon, $title, $description]): ?>
                    <div class="col-md-6 col-lg-4"><article class="feature-card">
                        <span class="feature-icon"><i class="bi <?php echo e($icon); ?>" aria-hidden="true"></i></span>
                        <h3><?php echo e($title); ?></h3><p><?php echo e($description); ?></p>
                    </article></div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="section-pad plans-section" id="plans">
        <div class="container">
            <div class="section-intro text-center">
                <span class="eyebrow">Fee plans</span><h2 class="section-title">Clear schedules, fewer surprises</h2>
                <p class="section-lead mx-auto">Fee structures and instalments can be presented with their due dates and status in one predictable view.</p>
            </div>
            <div class="row justify-content-center g-4">
                <div class="col-md-6 col-lg-4"><article class="plan-card">
                    <span class="plan-kicker">Flexible setup</span><h3>Institution-defined</h3>
                    <p>Configure categories and schedules to fit the institution’s programmes and academic calendar.</p>
                    <ul><li><i class="bi bi-check2" aria-hidden="true"></i>Fee categories</li><li><i class="bi bi-check2" aria-hidden="true"></i>Instalment schedules</li><li><i class="bi bi-check2" aria-hidden="true"></i>Discounts and adjustments</li></ul>
                </article></div>
                <div class="col-md-6 col-lg-4"><article class="plan-card plan-featured">
                    <span class="plan-kicker">Parent visibility</span><h3>At-a-glance status</h3>
                    <p>Help families understand what has been paid, what is due, and what is coming next.</p>
                    <ul><li><i class="bi bi-check2" aria-hidden="true"></i>Due dates and status</li><li><i class="bi bi-check2" aria-hidden="true"></i>Payment records</li><li><i class="bi bi-check2" aria-hidden="true"></i>Receipt access</li></ul>
                </article></div>
                <div class="col-md-6 col-lg-4"><article class="plan-card">
                    <span class="plan-kicker">Administrative control</span><h3>Trackable changes</h3>
                    <p>Keep fee plans understandable and changes traceable to authorised staff activity.</p>
                    <ul><li><i class="bi bi-check2" aria-hidden="true"></i>Scoped assignment</li><li><i class="bi bi-check2" aria-hidden="true"></i>Audit-friendly records</li><li><i class="bi bi-check2" aria-hidden="true"></i>Reconciliation support</li></ul>
                </article></div>
            </div>
        </div>
    </section>

    <section class="section-pad protection-section" id="payments">
        <div class="container"><div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="eyebrow">Payment experience</span>
                <h2 class="section-title">A payment journey built for clarity and control</h2>
                <p class="section-lead">The interface is designed to keep order status, verified payments, receipts and follow-up actions understandable. Payment processing and gateway behavior are not enabled by this UI foundation.</p>
                <div class="protection-points">
                    <div><i class="bi bi-shield-lock-fill" aria-hidden="true"></i><span><b>Protected records</b><small>Keep important transaction history visible and traceable.</small></span></div>
                    <div><i class="bi bi-arrow-repeat" aria-hidden="true"></i><span><b>Reconciliation-ready</b><small>Provide staff with a clear place to review pending payment states.</small></span></div>
                    <div><i class="bi bi-receipt" aria-hidden="true"></i><span><b>Receipt access</b><small>Keep issued payment documents easy to locate.</small></span></div>
                </div>
            </div>
            <div class="col-lg-6"><div class="payment-card">
                <span class="payment-card-icon"><i class="bi bi-shield-check" aria-hidden="true"></i></span>
                <span class="eyebrow light">Payment record preview</span><h3>Clear status at each step</h3>
                <div class="payment-step"><span class="step-number">01</span><div><b>Order created</b><small>Request details are recorded</small></div><span class="step-mark"><i class="bi bi-check2" aria-hidden="true"></i></span></div>
                <div class="payment-step"><span class="step-number">02</span><div><b>Payment verified</b><small>Verification is an application workflow</small></div><span class="step-mark"><i class="bi bi-arrow-repeat" aria-hidden="true"></i></span></div>
                <div class="payment-step"><span class="step-number">03</span><div><b>Receipt issued</b><small>Available after confirmed payment</small></div><span class="step-mark"><i class="bi bi-receipt" aria-hidden="true"></i></span></div>
                <div class="payment-disclaimer">Illustrative flow only — no payment is initiated or confirmed here.</div>
            </div></div>
        </div></div>
    </section>

    <section class="section-pad" id="admission">
        <div class="container"><div class="row align-items-center g-5">
            <div class="col-lg-5"><span class="eyebrow">Admissions</span><h2 class="section-title">A more organised start for every student</h2>
                <p class="section-lead">Bring admission details into a consistent process and keep important student information connected to the correct institution.</p>
            </div>
            <div class="col-lg-7"><div class="admission-flow">
                <div><span>01</span><i class="bi bi-file-earmark-person" aria-hidden="true"></i><b>Capture application</b><small>Collect the required student and guardian details.</small></div>
                <div><span>02</span><i class="bi bi-clipboard-check" aria-hidden="true"></i><b>Review and approve</b><small>Staff review the record before confirming admission.</small></div>
                <div><span>03</span><i class="bi bi-person-vcard" aria-hidden="true"></i><b>Connect student record</b><small>Link academic and fee records to the right institution.</small></div>
            </div></div>
        </div></div>
    </section>

    <section class="section-pad admin-section" id="admin">
        <div class="container">
            <div class="section-intro text-center"><span class="eyebrow">Administration</span><h2 class="section-title">Tools for day-to-day operations</h2><p class="section-lead mx-auto">A shared visual language across the main areas of institution administration.</p></div>
            <div class="admin-module-grid">
                <?php foreach ($adminModules as $module): ?><div class="admin-module"><i class="bi bi-check2-circle" aria-hidden="true"></i><?php echo e($module); ?></div><?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="section-pad security-section" id="security">
        <div class="container"><div class="security-banner">
            <div><span class="eyebrow light">Privacy by design</span><h2>Security belongs in the workflow, not just the menu</h2><p>Tenant boundaries, permission checks, CSRF protection and careful payment handling must be enforced by server-side application logic.</p></div>
            <i class="bi bi-shield-lock" aria-hidden="true"></i>
        </div></div>
    </section>

    <section class="section-pad deliverables-section" id="delivery">
        <div class="container">
            <div class="section-intro text-center"><span class="eyebrow">Platform scope</span><h2 class="section-title">A cohesive set of institution tools</h2><p class="section-lead mx-auto">The design language extends across parent and admin workspaces while each feature is implemented and verified separately.</p></div>
            <div class="row g-3">
                <?php foreach (['Responsive parent and admin shells', 'Admissions and student records', 'Fee schedules and instalments', 'Payments and reconciliation', 'Receipts and reporting', 'Academic and family notifications', 'Institution settings and permissions', 'Audit-friendly workflows'] as $deliverable): ?>
                    <div class="col-md-6 col-lg-3"><div class="deliverable-card"><i class="bi bi-check2-circle" aria-hidden="true"></i><span><?php echo e($deliverable); ?></span></div></div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
</main>

<section class="cta-section" id="contact">
    <div class="container text-center">
        <span class="eyebrow light justify-content-center">FeesPortal</span>
        <h2>Bring clarity and confidence to institution fee management</h2>
        <p>This project foundation presents the intended design. Business workflows remain separate and are not represented as live features.</p>
        <a href="#panels" class="btn btn-gold">Explore the platform <i class="bi bi-arrow-up-right ms-2" aria-hidden="true"></i></a>
    </div>
</section>
<?php
$loginModalState = $_SESSION['login_modal_state'] ?? [];
unset($_SESSION['login_modal_state']);
require __DIR__ . '/includes/components/otp-verification-modal.php';
require __DIR__ . '/includes/components/login-modal.php';
?>
<?php render_page_footer('public'); ?>
