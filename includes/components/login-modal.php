<?php
declare(strict_types=1);

require_once __DIR__ . '/login-form.php';

$loginModalState = is_array($loginModalState ?? null) ? $loginModalState : [];
$parentLoginState = is_array($loginModalState['parent'] ?? null) ? $loginModalState['parent'] : [];
$staffLoginState = is_array($loginModalState['staff'] ?? null) ? $loginModalState['staff'] : [];
$openLoginModal = isset($_GET['login']) && $_GET['login'] === '1';
$pendingAuth = is_array($_SESSION['pending_auth'] ?? null) ? $_SESSION['pending_auth'] : [];
$isOtpVerification = isset($_GET['verify']) && $_GET['verify'] === '1'
    && ($pendingAuth['challenge_ready'] ?? false) === true;
$requestedTab = $_GET['account'] ?? null;
$activeLoginTab = isset($staffLoginState['errors'])
    && is_array($staffLoginState['errors'])
    && array_filter($staffLoginState['errors'], static fn(mixed $error): bool => is_string($error) && $error !== '') !== []
    ? 'staff'
    : ($requestedTab === 'staff' || ($pendingAuth['login_type'] ?? null) === 'staff_email' ? 'staff' : 'parent');
$loginFlash = $isOtpVerification ? '' : render_flash_messages($activeLoginTab . '_auth');
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
                <ul class="nav nav-pills nav-fill login-modal-tabs mb-4" role="tablist" aria-label="Choose account type">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link<?php echo $activeLoginTab === 'parent' ? ' active' : ''; ?>" id="parent-login-tab" data-bs-toggle="tab"
                            data-bs-target="#parent-login-panel" type="button" role="tab"
                            aria-controls="parent-login-panel" aria-selected="<?php echo $activeLoginTab === 'parent' ? 'true' : 'false'; ?>">Parent</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link<?php echo $activeLoginTab === 'staff' ? ' active' : ''; ?>" id="staff-login-tab" data-bs-toggle="tab"
                            data-bs-target="#staff-login-panel" type="button" role="tab"
                            aria-controls="staff-login-panel" aria-selected="<?php echo $activeLoginTab === 'staff' ? 'true' : 'false'; ?>">Staff / Admin</button>
                    </li>
                </ul>
                <div class="tab-content">
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
                    <section class="tab-pane fade<?php echo $activeLoginTab === 'staff' ? ' show active' : ''; ?>" id="staff-login-panel" role="tabpanel"
                        aria-labelledby="staff-login-tab" tabindex="0">
                        <p class="login-modal-help">Enter your institution code and verified email address.</p>
                        <?php render_login_form(
                            'staff',
                            route_url('/login/staff'),
                            'modal-staff',
                            $staffLoginState['values'] ?? [],
                            $staffLoginState['errors'] ?? []
                        ); ?>
                    </section>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <small class="text-secondary">Your account type determines which sign-in method is used.</small>
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
