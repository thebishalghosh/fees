<?php
declare(strict_types=1);

require_once __DIR__ . '/login-form.php';

$loginModalState = is_array($loginModalState ?? null) ? $loginModalState : [];
$parentLoginState = is_array($loginModalState['parent'] ?? null) ? $loginModalState['parent'] : [];
$adminLoginState = is_array($loginModalState['admin'] ?? null) ? $loginModalState['admin'] : [];
$staffLoginState = is_array($loginModalState['staff'] ?? null) ? $loginModalState['staff'] : [];
$openLoginModal = isset($_GET['login']) && $_GET['login'] === '1';
$pendingAuth = is_array($_SESSION['pending_auth'] ?? null) ? $_SESSION['pending_auth'] : [];
$isOtpVerification = isset($_GET['verify']) && $_GET['verify'] === '1'
    && ($pendingAuth['challenge_ready'] ?? false) === true;
$requestedTab = $_GET['account'] ?? null;
$isPlatformAdminLogin = $requestedTab === 'platform_admin';
$activeLoginTab = isset($staffLoginState['errors'])
    && is_array($staffLoginState['errors'])
    && array_filter($staffLoginState['errors'], static fn(mixed $error): bool => is_string($error) && $error !== '') !== []
    ? 'staff'
    : ($requestedTab === 'admin'
        ? 'admin'
        : ($requestedTab === 'staff' || ($pendingAuth['login_type'] ?? null) === 'staff_email'
            ? 'staff'
            : 'parent'));
$activeTeamLoginTab = $activeLoginTab === 'staff' ? 'staff' : 'admin';
$loginFlash = $isOtpVerification
    ? ''
    : render_flash_messages($isPlatformAdminLogin ? 'platform_admin_auth' : $activeLoginTab . '_auth');
?>
<div class="modal fade login-modal" id="login-modal" tabindex="-1"
    aria-labelledby="login-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <div>
                    <span class="eyebrow">Secure sign-in</span>
                    <h2 class="modal-title fs-4" id="login-modal-title">Welcome to FeesPortal</h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close sign-in dialog"></button>
            </div>
            <div class="modal-body pt-3">
                <?php if ($loginFlash !== ''): ?>
                    <?php echo $loginFlash; ?>
                <?php endif; ?>
                <?php if (!$isPlatformAdminLogin): ?>
                    <ul class="nav nav-pills nav-fill login-modal-tabs mb-4" role="tablist" aria-label="Choose who is signing in">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link<?php echo $activeLoginTab === 'parent' ? ' active' : ''; ?>" id="parent-login-tab" data-bs-toggle="tab"
                                data-bs-target="#parent-login-panel" type="button" role="tab"
                                aria-controls="parent-login-panel" aria-selected="<?php echo $activeLoginTab === 'parent' ? 'true' : 'false'; ?>">Parent / guardian</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link<?php echo $activeLoginTab !== 'parent' ? ' active' : ''; ?>" id="team-login-tab" data-bs-toggle="tab"
                                data-bs-target="#team-login-panel" type="button" role="tab"
                                aria-controls="team-login-panel" aria-selected="<?php echo $activeLoginTab !== 'parent' ? 'true' : 'false'; ?>">School team</button>
                        </li>
                    </ul>
                <?php endif; ?>
                <div class="tab-content">
                    <?php if ($isPlatformAdminLogin): ?>
                        <section class="tab-pane active show" id="platform-admin-login-panel" role="tabpanel"
                            aria-labelledby="login-modal-title" tabindex="0">
                            <p class="login-modal-help">This sign-in is for FeesPortal platform administrators.</p>
                            <form method="post" action="<?php echo e(route_url('/platform-admin/login/submit')); ?>">
                                <?php echo csrf_field(); ?>
                                <div class="mb-3">
                                    <label class="form-label" for="modal-platform-admin-email">Email address</label>
                                    <input class="form-control" id="modal-platform-admin-email" name="email" type="email"
                                        required maxlength="255" autocomplete="username">
                                </div>
                                <div class="mb-4">
                                    <label class="form-label" for="modal-platform-admin-password">Password</label>
                                    <input class="form-control" id="modal-platform-admin-password" name="password" type="password"
                                        required maxlength="1024" autocomplete="current-password">
                                </div>
                                <button class="btn btn-cobalt w-100" type="submit">Sign in to platform</button>
                            </form>
                        </section>
                    <?php else: ?>
                    <section class="tab-pane fade<?php echo $activeLoginTab === 'parent' ? ' show active' : ''; ?>" id="parent-login-panel" role="tabpanel"
                        aria-labelledby="parent-login-tab" tabindex="0">
                        <p class="login-modal-help">Use the verified email address registered with your institution.</p>
                        <?php render_login_form(
                            'parent',
                            route_url('/login/parent'),
                            'modal-parent',
                            $parentLoginState['values'] ?? [],
                            $parentLoginState['errors'] ?? []
                        ); ?>
                    </section>
                    <section class="tab-pane fade<?php echo $activeLoginTab !== 'parent' ? ' show active' : ''; ?>" id="team-login-panel" role="tabpanel"
                        aria-labelledby="team-login-tab" tabindex="0">
                        <p class="login-modal-help">Choose your school account type.</p>
                        <ul class="nav nav-pills nav-fill login-team-tabs mb-3" role="tablist" aria-label="Choose school account type">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link<?php echo $activeTeamLoginTab === 'admin' ? ' active' : ''; ?>" id="admin-login-tab" data-bs-toggle="tab"
                                    data-bs-target="#admin-login-panel" type="button" role="tab"
                                    aria-controls="admin-login-panel" aria-selected="<?php echo $activeTeamLoginTab === 'admin' ? 'true' : 'false'; ?>">Administrator</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link<?php echo $activeTeamLoginTab === 'staff' ? ' active' : ''; ?>" id="staff-login-tab" data-bs-toggle="tab"
                                    data-bs-target="#staff-login-panel" type="button" role="tab"
                                    aria-controls="staff-login-panel" aria-selected="<?php echo $activeTeamLoginTab === 'staff' ? 'true' : 'false'; ?>">Staff member</button>
                            </li>
                        </ul>
                        <div class="tab-content">
                            <section class="tab-pane fade<?php echo $activeTeamLoginTab === 'admin' ? ' show active' : ''; ?>" id="admin-login-panel" role="tabpanel"
                                aria-labelledby="admin-login-tab" tabindex="0">
                                <p class="login-modal-help">Use your administrator username or email and password.</p>
                                <?php render_login_form(
                                    'admin',
                                    route_url('/login/admin'),
                                    'modal-admin',
                                    $adminLoginState['values'] ?? [],
                                    $adminLoginState['errors'] ?? []
                                ); ?>
                            </section>
                            <section class="tab-pane fade<?php echo $activeTeamLoginTab === 'staff' ? ' show active' : ''; ?>" id="staff-login-panel" role="tabpanel"
                                aria-labelledby="staff-login-tab" tabindex="0">
                                <p class="login-modal-help">Use your institution code and verified email address.</p>
                                <?php render_login_form(
                                    'staff',
                                    route_url('/login/staff'),
                                    'modal-staff',
                                    $staffLoginState['values'] ?? [],
                                    $staffLoginState['errors'] ?? []
                                ); ?>
                            </section>
                        </div>
                    </section>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <div class="login-modal-footer-content">
                    <?php if ($isPlatformAdminLogin): ?>
                        <a class="login-modal-platform-link" href="<?php echo e(route_url('/login')); ?>">
                            Return to parent and school sign in
                        </a>
                    <?php else: ?>
                        <small class="text-secondary">Need help? Contact your institution.</small>
                        <a class="login-modal-platform-link" href="<?php echo e(route_url('/platform-admin/login')); ?>">
                            Platform administrator sign in
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const modalElement = document.getElementById('login-modal');
    const shouldOpen = <?php echo !$isOtpVerification && ($openLoginModal || $loginFlash !== '') ? 'true' : 'false'; ?>;
    if (shouldOpen && modalElement && window.bootstrap) {
        window.bootstrap.Modal.getOrCreateInstance(modalElement).show();
    }
});
</script>
