<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/admin-panel-service.php';
require_once __DIR__ . '/../../services/institution-branding-service.php';

require_http_methods(['GET', 'POST']);
require_user_types(['admin', 'staff']);
require_placeholder_route_access();
$db = db_connect();
$institutionId = admin_panel_institution_id();
$user = current_user();
$errors = [];
$institution = admin_panel_institution($db, $institutionId);
$canManageBranding = ($user['user_type'] ?? null) === 'admin';
if ($institution === null) {
    http_response_code(404);
    exit('Institution not found.');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : 'save_profile';
    if ($action === 'upload_logo' || $action === 'remove_logo') {
        try {
            if ($action === 'upload_logo') {
                if (!is_array($_FILES['institution_logo'] ?? null)) {
                    throw new InvalidArgumentException('Choose a logo image to upload.');
                }
                $newLogoPath = institution_branding_store_logo($_FILES['institution_logo']);
                try {
                    $oldLogoPath = admin_panel_update_institution_logo(
                        $db,
                        $institutionId,
                        (int) $user['id'],
                        $newLogoPath
                    );
                } catch (Throwable $exception) {
                    $currentInstitution = admin_panel_institution($db, $institutionId);
                    if (($currentInstitution['logo_path'] ?? null) !== $newLogoPath) {
                        institution_branding_delete_logo_file($newLogoPath);
                    }
                    throw $exception;
                }
                $message = 'Institution logo updated.';
            } else {
                $oldLogoPath = admin_panel_update_institution_logo(
                    $db,
                    $institutionId,
                    (int) $user['id'],
                    null
                );
                $message = 'Institution logo removed.';
            }
            try {
                admin_panel_cleanup_replaced_logo($db, $oldLogoPath);
            } catch (Throwable $exception) {
                log_error('admin_institution_logo_cleanup_failed', [
                    'error_type' => get_class($exception),
                    'error_code' => $exception->getCode(),
                ]);
                set_flash('warning', $message . ' The previous image could not be removed.');
                safe_redirect('/admin/settings/institution', 303);
            }
            set_flash('success', $message);
            safe_redirect('/admin/settings/institution', 303);
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        } catch (Throwable $exception) {
            log_error('admin_institution_logo_update_failed', [
                'error_type' => get_class($exception),
                'error_code' => $exception->getCode(),
            ]);
            $errors[] = 'The institution logo could not be updated. Please try again.';
        }
    } elseif ($action === 'save_branding') {
        try {
            institution_branding_save($db, $institutionId, $_POST);
            set_flash('success', 'Institution branding saved.');
            safe_redirect('/admin/settings/institution#branding', 303);
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        } catch (Throwable $exception) {
            log_error('admin_institution_branding_update_failed', [
                'error_type' => get_class($exception),
                'error_code' => $exception->getCode(),
            ]);
            $errors[] = 'The institution branding could not be saved. Please try again.';
        }
    } elseif ($action === 'save_profile') {
        try {
            $values = admin_panel_validate_institution($_POST);
            admin_panel_update_institution($db, $institutionId, (int) $user['id'], $values);
            set_flash('success', 'Institution profile saved.');
            safe_redirect('/admin/settings/institution', 303);
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        } catch (Throwable $exception) {
            log_error('admin_institution_profile_update_failed', [
                'error_type' => get_class($exception),
                'error_code' => $exception->getCode(),
            ]);
            $errors[] = 'The institution profile could not be saved. Please try again.';
        }
    } else {
        $errors[] = 'The requested settings action is invalid.';
    }
    $institution = admin_panel_institution($db, $institutionId) ?? $institution;
}
$brandingDefaults = institution_branding_default_settings();
$branding = institution_branding_settings($db, $institutionId);
$brandingFormValues = $branding;
if (($_POST['action'] ?? null) === 'save_branding') {
    foreach (array_keys($brandingDefaults) as $key) {
        if (is_string($_POST[$key] ?? null)) {
            $brandingFormValues[$key] = $_POST[$key];
        }
    }
}
$values = [];
foreach ([
    'name', 'short_name', 'code', 'institution_type', 'email', 'phone',
    'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country',
    'website', 'principal_name', 'principal_email', 'principal_phone',
    'registration_number', 'affiliation_body', 'affiliation_number',
    'established_year', 'currency_code',
] as $field) {
    $values[$field] = is_string($_POST[$field] ?? null)
        ? $_POST[$field]
        : (string) ($institution[$field] ?? ($field === 'currency_code' ? 'INR' : ''));
}
$logoPreviewVersion = institution_branding_logo_version(
    is_string($institution['logo_path'] ?? null) ? $institution['logo_path'] : null
);

render_app_page_start(
    'Institution Profile',
    'Update the profile details for your currently authorized institution.',
    'admin',
    ['admin-dashboard.css', 'admin-institution-profile.css']
);
?>
<?php echo render_flash_messages(); ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item active" aria-current="page">Institution Profile</li></ol></nav>
<?php foreach ($errors as $error): ?><div class="alert alert-danger" role="alert"><?php echo e($error); ?></div><?php endforeach; ?>
<div class="institution-profile-layout">
    <div class="institution-profile-sidebar">
    <section class="dashboard-panel institution-logo-card">
        <div class="institution-profile-card-heading">
            <span class="institution-profile-icon" aria-hidden="true"><i class="bi bi-image"></i></span>
            <div>
                <span class="institution-profile-kicker">Visual identity</span>
                <h2>Institution logo</h2>
                <p>Use your institution’s logo across its portal.</p>
            </div>
        </div>
        <div class="institution-logo-preview-wrap">
            <?php if (institution_branding_logo_file(is_string($institution['logo_path'] ?? null) ? $institution['logo_path'] : null) !== null): ?>
                <img class="institution-logo-preview" src="<?php echo e(route_url('/admin/institution-logo?v=' . $logoPreviewVersion)); ?>" alt="Current institution logo">
            <?php else: ?>
                <span class="institution-logo-empty" aria-hidden="true"><i class="bi bi-mortarboard"></i></span>
                <p>No logo uploaded yet</p>
            <?php endif; ?>
        </div>
        <p class="institution-logo-help">PNG or JPEG · Maximum file size 2 MB</p>
        <div class="institution-logo-actions">
            <form method="post" enctype="multipart/form-data" action="<?php echo e(route_url('/admin/settings/institution')); ?>" class="institution-logo-upload">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="upload_logo">
                <label class="admin-form-field">Choose image<input class="form-control" type="file" name="institution_logo" accept="image/png,image/jpeg" required></label>
                <button class="btn btn-cobalt" type="submit"><?php echo $institution['logo_path'] === null ? 'Upload logo' : 'Replace logo'; ?></button>
            </form>
            <?php if ($institution['logo_path'] !== null): ?>
                <form method="post" action="<?php echo e(route_url('/admin/settings/institution')); ?>" onsubmit="return confirm('Remove the institution logo?');">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="remove_logo">
                    <button class="btn btn-outline-danger" type="submit"><i class="bi bi-trash3 me-1" aria-hidden="true"></i>Remove logo</button>
                </form>
            <?php endif; ?>
        </div>
    </section>
    <?php if ($canManageBranding): ?>
        <section id="branding" class="dashboard-panel institution-branding-card">
            <div class="institution-profile-card-heading">
                <span class="institution-profile-icon" aria-hidden="true"><i class="bi bi-palette"></i></span>
                <div>
                    <span class="institution-profile-kicker">Visual identity</span>
                    <h2>Branding and appearance</h2>
                    <p>Choose the colors used across your institution’s portal and sign-in page.</p>
                </div>
            </div>
            <form method="post" action="<?php echo e(route_url('/admin/settings/institution')); ?>#branding" class="institution-branding-form" data-branding-form>
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_branding">
                <div class="institution-branding-color-grid">
                    <?php foreach ([
                        'brand_primary_color' => 'Primary color',
                        'brand_secondary_color' => 'Secondary color',
                        'brand_accent_color' => 'Accent color',
                        'brand_sidebar_background' => 'Sidebar background',
                        'brand_sidebar_text' => 'Sidebar text',
                        'brand_header_background' => 'Header background',
                        'brand_header_text' => 'Header text',
                        'brand_page_background' => 'Page background',
                        'brand_surface_background' => 'Card / surface background',
                        'brand_button_background' => 'Button background',
                        'brand_button_text' => 'Button text',
                        'login_background_color' => 'Sign-in page background',
                        'login_primary_color' => 'Sign-in primary color',
                    ] as $key => $label): ?>
                        <div class="institution-branding-color-field">
                            <label for="profile-<?php echo e($key); ?>"><?php echo e($label); ?></label>
                            <div class="institution-branding-color-inputs">
                                <input type="color" class="form-control form-control-color" value="<?php echo e(preg_match('/^#[0-9A-Fa-f]{6}$/D', $brandingFormValues[$key]) === 1 ? $brandingFormValues[$key] : $brandingDefaults[$key]); ?>" data-color-picker="<?php echo e($key); ?>" aria-label="<?php echo e($label); ?> color picker">
                                <input type="text" class="form-control font-monospace" id="profile-<?php echo e($key); ?>" name="<?php echo e($key); ?>" value="<?php echo e($brandingFormValues[$key]); ?>" maxlength="7" pattern="#[0-9A-Fa-f]{6}" data-color-value="<?php echo e($key); ?>" data-default-value="<?php echo e($brandingDefaults[$key]); ?>" data-initial-value="<?php echo e($branding[$key]); ?>" required>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <aside class="institution-branding-preview" data-branding-preview
                       style="--preview-primary:<?php echo e($branding['brand_primary_color']); ?>;--preview-secondary:<?php echo e($branding['brand_secondary_color']); ?>;--preview-accent:<?php echo e($branding['brand_accent_color']); ?>;--preview-sidebar:<?php echo e($branding['brand_sidebar_background']); ?>;--preview-sidebar-text:<?php echo e($branding['brand_sidebar_text']); ?>;--preview-header:<?php echo e($branding['brand_header_background']); ?>;--preview-header-text:<?php echo e($branding['brand_header_text']); ?>;--preview-page:<?php echo e($branding['brand_page_background']); ?>;--preview-surface:<?php echo e($branding['brand_surface_background']); ?>;--preview-button:<?php echo e($branding['brand_button_background']); ?>;--preview-button-text:<?php echo e($branding['brand_button_text']); ?>;--preview-login-background:<?php echo e($branding['login_background_color']); ?>;--preview-login-primary:<?php echo e($branding['login_primary_color']); ?>;">
                    <div class="institution-branding-preview-heading"><i class="bi bi-eye" aria-hidden="true"></i> Live appearance preview</div>
                    <div class="institution-branding-preview-portal">
                        <aside><strong><i class="bi bi-mortarboard-fill" aria-hidden="true"></i> Institution</strong><span>Overview</span><span>Students</span></aside>
                        <div>
                            <header>Institution workspace <i class="bi bi-bell" aria-hidden="true"></i></header>
                            <section><small>OVERVIEW</small><strong>Welcome back</strong><div><span>Collected this month</span><b>₹ 2,40,000</b></div><button type="button">Primary action</button></section>
                        </div>
                    </div>
                    <div class="institution-branding-preview-login">
                        <div class="preview-login-card">
                            <span class="preview-login-mark"><i class="bi bi-mortarboard-fill" aria-hidden="true"></i></span>
                            <small>SECURE SIGN-IN</small>
                            <strong><?php echo e($institution['name']); ?></strong>
                            <span><?php echo e($branding['login_welcome_text'] ?: 'Welcome to your institution portal'); ?></span>
                            <button type="button">Continue</button>
                        </div>
                    </div>
                </aside>
                <label class="admin-form-field institution-branding-welcome">Sign-in welcome text
                    <input class="form-control" type="text" name="login_welcome_text" maxlength="120" value="<?php echo e($brandingFormValues['login_welcome_text']); ?>" data-default-value="" data-initial-value="<?php echo e($branding['login_welcome_text']); ?>">
                    <small class="form-text">Optional. Leave blank to use the default welcome text.</small>
                </label>
                <div class="institution-branding-form-footer">
                    <span class="institution-branding-unsaved" data-unsaved-message role="status" hidden><i class="bi bi-exclamation-circle me-1" aria-hidden="true"></i>You have unsaved appearance changes.</span>
                    <div class="institution-branding-actions">
                        <button class="btn btn-outline-secondary" type="button" data-reset-branding>Reset to defaults</button>
                        <button class="btn btn-cobalt" type="submit"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save branding</button>
                    </div>
                </div>
            </form>
        </section>
        <script src="<?php echo e(APP_URL); ?>/public/assets/js/institution-branding.js" defer></script>
    <?php endif; ?>
    </div>
    <section class="dashboard-panel institution-details-card">
        <div class="institution-profile-card-heading">
            <span class="institution-profile-icon" aria-hidden="true"><i class="bi bi-building"></i></span>
            <div>
                <span class="institution-profile-kicker">Organization details</span>
                <h2>Profile information</h2>
                <p>Keep contact, leadership, and registration details up to date.</p>
            </div>
        </div>
        <form method="post" action="<?php echo e(route_url('/admin/settings/institution')); ?>" class="institution-details-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_profile">
            <fieldset class="institution-profile-section">
                <legend>Identity and preferences</legend>
                <p class="institution-profile-section-help">Name, public identity, and regional settings.</p>
                <div class="admin-form-grid">
                <?php foreach ([
                    'name' => ['Institution name', 255, true],
                    'short_name' => ['Short name', 80, false],
                    'code' => ['Institution code', 50, false],
                    'website' => ['Institution website', 255, false],
                ] as $field => [$label, $maximum, $required]): ?>
                    <label class="admin-form-field"><?php echo e($label); ?><input class="form-control" name="<?php echo e($field); ?>" maxlength="<?php echo e((string) $maximum); ?>" value="<?php echo e($values[$field]); ?>"<?php echo $field === 'website' ? ' type="url"' : ''; ?><?php echo $required ? ' required' : ''; ?>></label>
                <?php endforeach; ?>
                <label class="admin-form-field">Institution type
                    <select class="form-select" name="institution_type" required>
                        <option value="">Choose type</option>
                        <?php foreach (admin_panel_institution_types() as $type => $label): ?>
                            <option value="<?php echo e($type); ?>"<?php echo $values['institution_type'] === $type ? ' selected' : ''; ?>><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="admin-form-field">Institution currency
                    <select class="form-select" name="currency_code" required>
                        <?php foreach (admin_panel_institution_currency_options() as $currency => $label): ?>
                            <option value="<?php echo e($currency); ?>"<?php echo $values['currency_code'] === $currency ? ' selected' : ''; ?>><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">This stores an institution preference only; existing payment and receipt currency behavior is unchanged.</small>
                </label>
                <p class="form-text mb-0">Warning: changing the institution code may require staff login and parent verification to use the new code.</p>
                </div>
            </fieldset>
            <fieldset class="institution-profile-section">
                <legend>Contact information</legend>
                <p class="institution-profile-section-help">Official contact details and institution address.</p>
                <div class="admin-form-grid">
                <?php foreach ([
                    'email' => ['Official email', 255, 'email'],
                    'phone' => ['Phone number', 30, 'tel'],
                    'address_line1' => ['Address line 1', 255, 'text'],
                    'address_line2' => ['Address line 2', 255, 'text'],
                    'city' => ['City', 120, 'text'],
                    'state' => ['State', 120, 'text'],
                    'postal_code' => ['Postal code', 20, 'text'],
                    'country' => ['Country', 120, 'text'],
                ] as $field => [$label, $maximum, $type]): ?>
                    <label class="admin-form-field"><?php echo e($label); ?><input class="form-control" type="<?php echo e($type); ?>" name="<?php echo e($field); ?>" maxlength="<?php echo e((string) $maximum); ?>" value="<?php echo e($values[$field]); ?>"<?php echo $field === 'country' ? ' required' : ''; ?>></label>
                <?php endforeach; ?>
                </div>
            </fieldset>
            <fieldset class="institution-profile-section">
                <legend>Institution administration</legend>
                <p class="institution-profile-section-help">Primary leadership contact for this institution.</p>
                <div class="admin-form-grid">
                <?php foreach ([
                    'principal_name' => ['Principal / director name', 150, 'text'],
                    'principal_email' => ['Principal / director email', 255, 'email'],
                    'principal_phone' => ['Principal / director phone', 30, 'tel'],
                ] as $field => [$label, $maximum, $type]): ?>
                    <label class="admin-form-field"><?php echo e($label); ?><input class="form-control" type="<?php echo e($type); ?>" name="<?php echo e($field); ?>" maxlength="<?php echo e((string) $maximum); ?>" value="<?php echo e($values[$field]); ?>"></label>
                <?php endforeach; ?>
                </div>
            </fieldset>
            <fieldset class="institution-profile-section">
                <legend>Registration and affiliation</legend>
                <p class="institution-profile-section-help">Accreditation details and year of establishment.</p>
                <div class="admin-form-grid">
                <?php foreach ([
                    'registration_number' => ['Registration number', 120],
                    'affiliation_body' => ['Affiliation / accreditation body', 255],
                    'affiliation_number' => ['Affiliation / accreditation number', 120],
                ] as $field => [$label, $maximum]): ?>
                    <label class="admin-form-field"><?php echo e($label); ?><input class="form-control" name="<?php echo e($field); ?>" maxlength="<?php echo e((string) $maximum); ?>" value="<?php echo e($values[$field]); ?>"></label>
                <?php endforeach; ?>
                <label class="admin-form-field">Established year<input class="form-control" type="number" name="established_year" min="1000" max="<?php echo e(date('Y')); ?>" value="<?php echo e($values['established_year']); ?>"></label>
                </div>
            </fieldset>
            <div class="institution-profile-form-actions">
                <span><i class="bi bi-shield-check me-1" aria-hidden="true"></i>Only authorized institution users can update this profile.</span>
                <button class="btn btn-cobalt" type="submit"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save profile</button>
            </div>
        </form>
    </section>
</div>
<?php render_app_page_end('admin'); ?>
