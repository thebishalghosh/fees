<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/page-placeholder.php';
require_once __DIR__ . '/../services/institution-onboarding-service.php';
require_once __DIR__ . '/../services/institution-branding-service.php';

require_http_methods(['GET', 'POST']);
require_user_types(['super_admin']);
$db = db_connect();
$errors = [];
$formValues = [];
$brandingFormValues = [];
$hasRequestedId = array_key_exists('id', $_GET);
$requestedId = $hasRequestedId ? $_GET['id'] : null;
$parsedInstitutionId = $hasRequestedId ? filter_var($requestedId, FILTER_VALIDATE_INT) : null;
$invalidInstitutionId = $hasRequestedId
    && ($parsedInstitutionId === false || $parsedInstitutionId === null || $parsedInstitutionId < 1);
$institutionId = !$hasRequestedId || $invalidInstitutionId ? null : (int) $parsedInstitutionId;
$formStateToken = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST'
    && is_string($_GET['form_state'] ?? null)
    && preg_match('/^[a-f0-9]{32}$/D', $_GET['form_state']) === 1
        ? $_GET['form_state']
        : null;
if ($formStateToken !== null
    && is_array($_SESSION['super_admin_institution_form_states'][$formStateToken] ?? null)) {
    $formState = $_SESSION['super_admin_institution_form_states'][$formStateToken];
    unset($_SESSION['super_admin_institution_form_states'][$formStateToken]);
    $errors = is_array($formState['errors'] ?? null)
        ? array_values(array_filter($formState['errors'], 'is_string'))
        : [];
    $formValues = is_array($formState['form_values'] ?? null) ? $formState['form_values'] : [];
    $brandingFormValues = is_array($formState['branding_values'] ?? null)
        ? $formState['branding_values']
        : [];
    if ($institutionId === null
        && is_int($formState['institution_id'] ?? null)
        && $formState['institution_id'] > 0) {
        $institutionId = $formState['institution_id'];
    }
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    require_valid_csrf_token();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $rawInstitutionId = filter_var($_POST['institution_id'] ?? null, FILTER_VALIDATE_INT);
    try {
        if ($action === 'create') {
            $institutionId = institution_onboarding_create($db, $_POST);
            set_flash('success', 'Institution and initial administrator created. The administrator can request an email OTP when signing in.');
            safe_redirect('/super-admin/institutions?id=' . $institutionId, 303);
        }
        if ($rawInstitutionId === false || $rawInstitutionId === null || $rawInstitutionId < 1) {
            throw new InvalidArgumentException('Choose a valid institution.');
        }
        $institutionId = (int) $rawInstitutionId;
        if ($action === 'update') {
            institution_onboarding_update($db, $institutionId, $_POST);
            set_flash('success', 'Institution details updated.');
            safe_redirect('/super-admin/institutions?id=' . $institutionId, 303);
        }
        if ($action === 'status') {
            $status = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
            institution_onboarding_set_status($db, $institutionId, $status);
            set_flash('success', $status === 'active' ? 'Institution activated.' : 'Institution deactivated.');
            safe_redirect('/super-admin/institutions?id=' . $institutionId, 303);
        }
        if ($action === 'save_branding') {
            institution_branding_save($db, $institutionId, $_POST);
            set_flash('success', 'Institution branding saved.');
            safe_redirect('/super-admin/institutions?id=' . $institutionId . '#branding', 303);
        }
        if ($action === 'upload_logo' || $action === 'remove_logo') {
            $newLogoPath = null;
            if ($action === 'upload_logo') {
                if (!is_array($_FILES['institution_logo'] ?? null)) {
                    throw new InvalidArgumentException('Choose a logo image to upload.');
                }
                $newLogoPath = institution_branding_store_logo($_FILES['institution_logo']);
            }
            try {
                $oldLogoPath = admin_panel_update_institution_logo(
                    $db,
                    $institutionId,
                    (int) current_user()['id'],
                    $newLogoPath
                );
            } catch (Throwable $exception) {
                if ($newLogoPath !== null) {
                    $currentInstitution = admin_panel_institution($db, $institutionId);
                    if (($currentInstitution['logo_path'] ?? null) !== $newLogoPath) {
                        institution_branding_delete_logo_file($newLogoPath);
                    }
                }
                throw $exception;
            }
            try {
                admin_panel_cleanup_replaced_logo($db, $oldLogoPath);
            } catch (Throwable $exception) {
                log_error('super_admin_institution_logo_cleanup_failed', [
                    'error_type' => get_class($exception),
                    'error_code' => $exception->getCode(),
                ]);
                set_flash('warning', 'The logo was updated, but the previous image could not be removed.');
                safe_redirect('/super-admin/institutions?id=' . $institutionId . '#branding', 303);
            }
            set_flash('success', $action === 'upload_logo' ? 'Institution logo updated.' : 'Institution logo removed.');
            safe_redirect('/super-admin/institutions?id=' . $institutionId . '#branding', 303);
        }
        throw new InvalidArgumentException('The requested institution action is invalid.');
    } catch (mysqli_sql_exception $exception) {
        if ($exception->getCode() === 1062
            && str_contains($exception->getMessage(), 'uq_institutions_code')) {
            $errors[] = 'That institution code is already in use.';
        } else {
            log_error('super_admin_institution_save_failed', [
                'error_type' => get_class($exception),
                'error_code' => $exception->getCode(),
            ]);
            $errors[] = 'The institution could not be saved. Please try again.';
        }
    } catch (InvalidArgumentException | OutOfBoundsException $exception) {
        $errors[] = $exception->getMessage();
    } catch (Throwable $exception) {
        log_error('super_admin_institution_save_failed', [
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
        ]);
        $errors[] = 'The institution could not be saved. Please try again.';
    }
    if (in_array($action, ['create', 'update'], true)) {
        foreach ([
            'name', 'short_name', 'code', 'institution_type', 'email', 'phone',
            'address_line1', 'address_line2', 'city', 'state', 'postal_code',
            'country', 'website', 'principal_name', 'principal_email', 'principal_phone',
            'registration_number', 'affiliation_body', 'affiliation_number',
            'established_year', 'currency_code', 'status', 'administrator_name',
            'administrator_email', 'administrator_phone',
        ] as $field) {
            $formValues[$field] = is_string($_POST[$field] ?? null) ? $_POST[$field] : '';
        }
    } elseif ($action === 'save_branding') {
        foreach (array_keys(institution_branding_default_settings()) as $key) {
            $brandingFormValues[$key] = is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
        }
    }

    $formStates = is_array($_SESSION['super_admin_institution_form_states'] ?? null)
        ? $_SESSION['super_admin_institution_form_states']
        : [];
    foreach (array_keys($formStates) as $existingToken) {
        if (!is_string($existingToken)
            || !is_array($formStates[$existingToken])
            || (int) ($formStates[$existingToken]['created_at'] ?? 0) < time() - 1800) {
            unset($formStates[$existingToken]);
        }
    }
    while (count($formStates) >= 5) {
        array_shift($formStates);
    }
    $formStateToken = bin2hex(random_bytes(16));
    $formStates[$formStateToken] = [
        'created_at' => time(),
        'errors' => $errors,
        'form_values' => $formValues,
        'branding_values' => $brandingFormValues,
        'institution_id' => $institutionId,
    ];
    $_SESSION['super_admin_institution_form_states'] = $formStates;
    $redirect = '/super-admin/institutions';
    $fragment = '';
    if ($institutionId !== null) {
        $redirect .= '?id=' . $institutionId;
        if ($action === 'save_branding' || $action === 'upload_logo' || $action === 'remove_logo') {
            $fragment = '#branding';
        } elseif ($action === 'update' || $action === 'status') {
            $fragment = '#profile';
        }
    }
    $separator = str_contains($redirect, '?') ? '&' : '?';
    safe_redirect($redirect . $separator . 'form_state=' . $formStateToken . $fragment, 303);
}

$institution = $institutionId === null ? null : institution_onboarding_get($db, $institutionId);
if ($invalidInstitutionId) {
    http_response_code(400);
} elseif ($institutionId !== null && $institution === null) {
    http_response_code(404);
} elseif ($institution !== null) {
    $brandingInstitution = institution_branding_get($db, $institutionId);
    if ($brandingInstitution !== null) {
        $institution['branding'] = $brandingInstitution['branding'];
    }
}
$search = is_string($_GET['search'] ?? null) ? trim($_GET['search']) : '';
$statusFilter = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
try {
    $institutions = institution_onboarding_list($db, $search, $statusFilter);
} catch (InvalidArgumentException $exception) {
    $errors[] = $exception->getMessage();
    $institutions = institution_onboarding_list($db);
    $search = '';
    $statusFilter = '';
}

$profileFields = [
    'name' => ['Institution name', 255, 'text'],
    'short_name' => ['Short name', 80, 'text'],
    'code' => ['Institution code', 50, 'text'],
    'email' => ['Official email', 255, 'email'],
    'phone' => ['Phone', 30, 'tel'],
    'address_line1' => ['Address line 1', 255, 'text'],
    'address_line2' => ['Address line 2', 255, 'text'],
    'city' => ['City', 120, 'text'],
    'state' => ['State', 120, 'text'],
    'postal_code' => ['Postal code', 20, 'text'],
    'country' => ['Country', 120, 'text'],
    'website' => ['Website', 255, 'url'],
    'principal_name' => ['Principal / director name', 150, 'text'],
    'principal_email' => ['Principal / director email', 255, 'email'],
    'principal_phone' => ['Principal / director phone', 30, 'tel'],
    'registration_number' => ['Registration number', 120, 'text'],
    'affiliation_body' => ['Affiliation / accreditation body', 255, 'text'],
    'affiliation_number' => ['Affiliation / accreditation number', 120, 'text'],
];
$renderProfileFields = static function (array $values) use ($profileFields): void {
    foreach ($profileFields as $field => [$label, $maximum, $type]) {
        $value = $values[$field] ?? '';
        ?>
        <label class="admin-form-field"><?php echo e($label); ?><input class="form-control" type="<?php echo e($type); ?>" name="<?php echo e($field); ?>" maxlength="<?php echo e((string) $maximum); ?>" value="<?php echo e($value); ?>"<?php echo in_array($field, ['name', 'email', 'phone', 'address_line1', 'country'], true) ? ' required' : ''; ?>></label>
        <?php
    }
};

render_app_page_start(
    $institution !== null ? 'Institution Details' : 'Institution Onboarding',
    $institution !== null
        ? 'Manage this institution’s profile, visual identity, and access status.'
        : 'Create institutions and manage their initial administrator and access status.',
    'admin',
    ['admin-dashboard.css', 'super-admin-institutions.css']
);
?>
<?php echo render_flash_messages(); ?>
<?php foreach ($errors as $error): ?><div class="alert alert-danger" role="alert"><?php echo e($error); ?></div><?php endforeach; ?>

<?php if ($invalidInstitutionId): ?>
    <div class="alert alert-warning" role="alert">The institution ID must be a positive whole number.</div>
<?php elseif ($institutionId !== null && $institution === null): ?>
    <div class="alert alert-warning" role="status">The requested institution was not found.</div>
<?php elseif ($institution !== null): ?>
    <?php
    $editValues = array_merge($institution, $formValues);
    $brandingValues = array_merge(
        $institution['branding'] ?? institution_branding_default_settings(),
        $brandingFormValues
    );
    $brandingDefaults = institution_branding_default_settings();
    $institutionTypeLabel = admin_panel_institution_types()[$institution['institution_type'] ?? ''] ?? 'Institution';
    $branding = $institution['branding'] ?? $brandingDefaults;
    $institutionLogoUrl = route_url(
        '/super-admin/institution-logo?id=' . (int) $institution['id']
        . '&v=' . institution_branding_logo_version(
            is_string($institution['logo_path']) ? $institution['logo_path'] : null
        )
    );
    ?>
    <nav aria-label="Breadcrumb" class="institution-detail-breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?php echo e(route_url('/super-admin/institutions')); ?>">Institutions</a></li>
            <li class="breadcrumb-item active" aria-current="page">Institution details</li>
        </ol>
    </nav>
    <header class="institution-detail-hero">
        <div class="institution-detail-identity">
            <span class="institution-detail-icon" aria-hidden="true"><i class="bi bi-buildings"></i></span>
            <div>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <h2><?php echo e($institution['name']); ?></h2>
                    <span class="badge institution-type-badge"><?php echo e($institutionTypeLabel); ?></span>
                    <span class="badge text-bg-<?php echo $institution['status'] === 'active' ? 'success' : 'secondary'; ?>"><?php echo e(ucfirst((string) $institution['status'])); ?></span>
                </div>
                <p><?php echo e($institution['short_name'] ?: $institution['code']); ?> <span aria-hidden="true">·</span> Institution #<?php echo e((string) $institution['id']); ?></p>
            </div>
        </div>
        <a class="btn btn-outline-secondary" href="<?php echo e(route_url('/super-admin/institutions')); ?>"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to institutions</a>
    </header>

    <nav class="institution-section-nav" aria-label="Institution sections">
        <a href="#overview"><i class="bi bi-grid" aria-hidden="true"></i>Overview</a>
        <a href="#profile"><i class="bi bi-building" aria-hidden="true"></i>Institution profile</a>
        <a href="#branding"><i class="bi bi-palette" aria-hidden="true"></i>Branding &amp; appearance</a>
        <a href="#administrator"><i class="bi bi-person-badge" aria-hidden="true"></i>Administrator</a>
        <a href="#security"><i class="bi bi-shield-check" aria-hidden="true"></i>Security &amp; status</a>
    </nav>

    <section id="overview" class="institution-section">
        <div class="institution-section-heading"><div><span class="eyebrow">At a glance</span><h2>Institution overview</h2></div></div>
        <div class="institution-overview-grid">
            <article class="institution-overview-card"><span class="institution-overview-icon"><i class="bi bi-hash" aria-hidden="true"></i></span><span class="institution-overview-label">Institution ID</span><strong><?php echo e((string) $institution['id']); ?></strong><small>Created <?php echo e(date('M j, Y', strtotime((string) $institution['created_at']))); ?></small></article>
            <article class="institution-overview-card"><span class="institution-overview-icon"><i class="bi bi-mortarboard" aria-hidden="true"></i></span><span class="institution-overview-label">Institution type</span><strong><?php echo e($institutionTypeLabel); ?></strong><small>Code: <?php echo e($institution['code']); ?></small></article>
            <article class="institution-overview-card"><span class="institution-overview-icon"><i class="bi bi-person-badge" aria-hidden="true"></i></span><span class="institution-overview-label">Administrator</span><strong><?php echo e(is_array($institution['administrator']) ? $institution['administrator']['full_name'] : 'Not assigned'); ?></strong><small><?php echo e(is_array($institution['administrator']) ? $institution['administrator']['email'] : 'No administrator account'); ?></small></article>
            <article class="institution-overview-card"><span class="institution-overview-icon"><i class="bi bi-people" aria-hidden="true"></i></span><span class="institution-overview-label">Students</span><strong><?php echo e((string) $institution['student_count']); ?></strong><small>Institution records</small></article>
            <article class="institution-overview-card"><span class="institution-overview-icon"><i class="bi bi-person-workspace" aria-hidden="true"></i></span><span class="institution-overview-label">Staff</span><strong><?php echo e((string) $institution['staff_count']); ?></strong><small>Staff accounts</small></article>
            <article class="institution-overview-card"><span class="institution-overview-icon"><i class="bi bi-shield-check" aria-hidden="true"></i></span><span class="institution-overview-label">Institution status</span><strong><?php echo e(ucfirst((string) $institution['status'])); ?></strong><small><?php echo $institution['status'] === 'active' ? 'Authentication enabled' : 'Authentication blocked'; ?></small></article>
        </div>
    </section>

    <section id="profile" class="institution-section institution-panel">
        <div class="institution-section-heading"><div><span class="eyebrow">Institution details</span><h2>Institution profile</h2><p>Keep this institution’s contact and registration information current.</p></div></div>
        <form method="post" action="<?php echo e(route_url('/super-admin/institutions')); ?>" class="institution-profile-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="institution_id" value="<?php echo e((string) $institution['id']); ?>">
            <?php $renderProfileFields($editValues); ?>
            <label class="admin-form-field">Institution type
                <select class="form-select" name="institution_type" required>
                    <option value="">Choose type</option>
                    <?php foreach (admin_panel_institution_types() as $type => $label): ?>
                        <option value="<?php echo e($type); ?>"<?php echo ($editValues['institution_type'] ?? '') === $type ? ' selected' : ''; ?>><?php echo e($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <p class="form-text institution-code-warning"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Changing the institution code may require staff login and parent verification to use the new code.</p>
            <label class="admin-form-field">Institution currency
                <select class="form-select" name="currency_code" required>
                    <?php foreach (admin_panel_institution_currency_options() as $currency => $label): ?>
                        <option value="<?php echo e($currency); ?>"<?php echo ($editValues['currency_code'] ?? 'INR') === $currency ? ' selected' : ''; ?>><?php echo e($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">This preference does not change existing payment or receipt currencies.</small>
            </label>
            <label class="admin-form-field">Established year<input class="form-control" type="number" name="established_year" min="1000" max="<?php echo e(date('Y')); ?>" value="<?php echo e($editValues['established_year'] ?? ''); ?>"></label>
            <div class="institution-form-actions"><button class="btn btn-cobalt" type="submit"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save profile</button></div>
        </form>
    </section>

    <section id="branding" class="institution-section">
        <div class="institution-section-heading"><div><span class="eyebrow">Visual identity</span><h2>Branding &amp; appearance</h2><p>Preview changes here first. Saved branding applies only to this institution’s portals.</p></div></div>
        <div class="institution-branding-layout">
            <div class="institution-branding-editor">
                <section class="institution-panel institution-logo-panel">
                    <div class="institution-section-heading"><div><h3>Institution identity</h3><p>Logo and name are shared across this institution’s portal and sign-in branding.</p></div></div>
                    <div class="institution-logo-row">
                        <div class="institution-logo-frame">
                            <?php if (institution_branding_logo_file(is_string($institution['logo_path']) ? $institution['logo_path'] : null) !== null): ?>
                                <img src="<?php echo e($institutionLogoUrl); ?>" alt="<?php echo e($institution['name']); ?> logo">
                            <?php else: ?>
                                <span class="institution-logo-placeholder"><i class="bi bi-image" aria-hidden="true"></i></span>
                            <?php endif; ?>
                        </div>
                        <div class="institution-logo-copy"><strong><?php echo e($institution['name']); ?></strong><span><?php echo e($institution['short_name'] ?: 'No short name set'); ?></span><small>PNG or JPEG, up to 2 MB. Images are stored outside the public web directory.</small></div>
                    </div>
                    <div class="institution-logo-actions">
                        <form method="post" enctype="multipart/form-data" action="<?php echo e(route_url('/super-admin/institutions')); ?>" class="d-flex flex-wrap align-items-end gap-2">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="upload_logo">
                            <input type="hidden" name="institution_id" value="<?php echo e((string) $institution['id']); ?>">
                            <label class="admin-form-field">Upload or replace logo<input class="form-control" type="file" name="institution_logo" accept="image/png,image/jpeg" required></label>
                            <button class="btn btn-outline-primary" type="submit"><i class="bi bi-upload me-1" aria-hidden="true"></i><?php echo $institution['logo_path'] === null ? 'Upload logo' : 'Replace logo'; ?></button>
                        </form>
                        <?php if ($institution['logo_path'] !== null): ?>
                            <form method="post" action="<?php echo e(route_url('/super-admin/institutions')); ?>" onsubmit="return confirm('Remove this institution logo?');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="remove_logo">
                                <input type="hidden" name="institution_id" value="<?php echo e((string) $institution['id']); ?>">
                                <button class="btn btn-outline-danger" type="submit"><i class="bi bi-trash3 me-1" aria-hidden="true"></i>Remove logo</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </section>
                <form method="post" action="<?php echo e(route_url('/super-admin/institutions')); ?>" class="institution-panel institution-branding-form" data-branding-form>
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="save_branding">
                    <input type="hidden" name="institution_id" value="<?php echo e((string) $institution['id']); ?>">
                    <div class="institution-section-heading"><div><h3>Portal colors</h3><p>Select a swatch or enter a six-digit HEX color.</p></div></div>
                    <div class="institution-color-grid">
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
                            'login_background_color' => 'Login background',
                            'login_primary_color' => 'Login primary color',
                        ] as $key => $label): ?>
                            <div class="institution-color-field">
                                <label for="<?php echo e($key); ?>"><?php echo e($label); ?></label>
                                <div class="institution-color-inputs">
                                    <input type="color" class="form-control form-control-color" value="<?php echo e($brandingValues[$key]); ?>" data-color-picker="<?php echo e($key); ?>" aria-label="<?php echo e($label); ?> color picker">
                                    <input type="text" class="form-control font-monospace" id="<?php echo e($key); ?>" name="<?php echo e($key); ?>" value="<?php echo e($brandingValues[$key]); ?>" maxlength="7" pattern="#[0-9A-Fa-f]{6}" data-color-value="<?php echo e($key); ?>" data-default-value="<?php echo e($brandingDefaults[$key]); ?>" data-initial-value="<?php echo e($branding[$key]); ?>" required>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <label class="admin-form-field mt-3">Login welcome text
                        <input class="form-control" type="text" name="login_welcome_text" maxlength="120" value="<?php echo e($brandingValues['login_welcome_text']); ?>" data-default-value="" data-initial-value="<?php echo e($branding['login_welcome_text']); ?>">
                        <small class="form-text">Optional. The institution name remains the display name on login screens.</small>
                    </label>
                    <div class="institution-branding-form-footer">
                        <span class="institution-unsaved-message" data-unsaved-message role="status" hidden><i class="bi bi-exclamation-circle me-1" aria-hidden="true"></i>You have unsaved appearance changes.</span>
                        <div class="institution-form-actions">
                            <button class="btn btn-outline-secondary" type="button" data-reset-branding>Reset to defaults</button>
                            <button class="btn btn-cobalt" type="submit"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save branding</button>
                        </div>
                    </div>
                </form>
            </div>
            <aside class="institution-preview-column" aria-label="Live branding preview">
                <div class="institution-preview-heading"><span class="eyebrow">Live preview</span><span class="institution-preview-dot"><i class="bi bi-circle-fill" aria-hidden="true"></i>Unsaved</span></div>
                <div class="institution-live-preview" data-branding-preview
                     style="--preview-primary:<?php echo e($branding['brand_primary_color']); ?>;--preview-secondary:<?php echo e($branding['brand_secondary_color']); ?>;--preview-accent:<?php echo e($branding['brand_accent_color']); ?>;--preview-sidebar:<?php echo e($branding['brand_sidebar_background']); ?>;--preview-sidebar-text:<?php echo e($branding['brand_sidebar_text']); ?>;--preview-header:<?php echo e($branding['brand_header_background']); ?>;--preview-header-text:<?php echo e($branding['brand_header_text']); ?>;--preview-page:<?php echo e($branding['brand_page_background']); ?>;--preview-surface:<?php echo e($branding['brand_surface_background']); ?>;--preview-button:<?php echo e($branding['brand_button_background']); ?>;--preview-button-text:<?php echo e($branding['brand_button_text']); ?>;--preview-login-background:<?php echo e($branding['login_background_color']); ?>;--preview-login-primary:<?php echo e($branding['login_primary_color']); ?>;">
                    <div class="preview-app-shell">
                        <div class="preview-sidebar">
                            <strong><?php if (institution_branding_logo_file(is_string($institution['logo_path']) ? $institution['logo_path'] : null) !== null): ?><img src="<?php echo e($institutionLogoUrl); ?>" alt=""><?php endif; ?><?php echo e($institution['short_name'] ?: $institution['name']); ?></strong>
                            <span class="preview-nav-active"><i class="bi bi-grid" aria-hidden="true"></i>Dashboard</span><span><i class="bi bi-people" aria-hidden="true"></i>Students</span><span><i class="bi bi-cash-stack" aria-hidden="true"></i>Fees</span>
                        </div>
                        <div class="preview-app-main"><div class="preview-header"><span>Institution workspace</span><i class="bi bi-bell" aria-hidden="true"></i></div><div class="preview-content"><span class="preview-kicker">Overview</span><strong>Welcome back</strong><div class="preview-card"><span>Collected this month</span><b>₹ 2,40,000</b><i class="preview-chart" aria-hidden="true"></i></div><div class="preview-actions"><button type="button">Primary action</button><button type="button" class="preview-secondary-button">Secondary</button></div><a href="#branding">View details <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a><input class="form-control" aria-label="Preview form field" placeholder="Form control"></div></div>
                    </div>
                    <div class="preview-login"><div class="preview-login-card"><span class="preview-login-mark"><?php if (institution_branding_logo_file(is_string($institution['logo_path']) ? $institution['logo_path'] : null) !== null): ?><img src="<?php echo e($institutionLogoUrl); ?>" alt=""><?php else: ?><i class="bi bi-mortarboard-fill" aria-hidden="true"></i><?php endif; ?></span><small>SECURE SIGN-IN</small><strong><?php echo e($institution['name']); ?></strong><span><?php echo e($branding['login_welcome_text'] ?: 'Welcome to your institution portal'); ?></span><button type="button">Continue</button></div></div>
                </div>
                <p class="institution-preview-note"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Preview changes are local to this page until you save.</p>
            </aside>
        </div>
    </section>

    <section id="administrator" class="institution-section institution-panel">
        <div class="institution-section-heading"><div><span class="eyebrow">Account access</span><h2>Administrator</h2><p>Initial institution administrator associated with this institution.</p></div></div>
        <?php if (is_array($institution['administrator'])): ?>
            <div class="institution-admin-card">
                <span class="institution-admin-avatar"><i class="bi bi-person-fill" aria-hidden="true"></i></span>
                <div><strong><?php echo e($institution['administrator']['full_name']); ?></strong><span><?php echo e($institution['administrator']['email']); ?></span><small><?php echo e($institution['administrator']['mobile']); ?></small></div>
                <span class="badge text-bg-<?php echo $institution['administrator']['status'] === 'active' ? 'success' : 'secondary'; ?>"><?php echo e(ucfirst((string) $institution['administrator']['status'])); ?></span>
                <p><?php echo $institution['administrator']['email_verified_at'] !== null ? 'Verified email; sign-in uses the existing email OTP workflow.' : 'Email is not verified; sign-in remains subject to existing account verification.'; ?></p>
            </div>
        <?php else: ?>
            <div class="institution-empty-admin"><i class="bi bi-person-exclamation" aria-hidden="true"></i><span>No administrator account is associated with this institution.</span></div>
        <?php endif; ?>
    </section>

    <section id="security" class="institution-section institution-panel">
        <div class="institution-section-heading"><div><span class="eyebrow">Access controls</span><h2>Security &amp; status</h2><p>Deactivation preserves historical records and blocks authentication through the existing institution status checks.</p></div></div>
        <div class="institution-status-row">
            <div><span class="institution-overview-label">Current institution status</span><strong><span class="badge text-bg-<?php echo $institution['status'] === 'active' ? 'success' : 'secondary'; ?>"><?php echo e(ucfirst((string) $institution['status'])); ?></span></strong></div>
            <form method="post" action="<?php echo e(route_url('/super-admin/institutions')); ?>" onsubmit="return confirm('<?php echo $institution['status'] === 'active' ? 'Deactivate' : 'Activate'; ?> this institution?');">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="status">
                <input type="hidden" name="institution_id" value="<?php echo e((string) $institution['id']); ?>">
                <input type="hidden" name="status" value="<?php echo $institution['status'] === 'active' ? 'inactive' : 'active'; ?>">
                <button class="btn btn-outline-<?php echo $institution['status'] === 'active' ? 'danger' : 'success'; ?>" type="submit"><i class="bi bi-<?php echo $institution['status'] === 'active' ? 'pause-circle' : 'play-circle'; ?> me-1" aria-hidden="true"></i><?php echo $institution['status'] === 'active' ? 'Deactivate institution' : 'Activate institution'; ?></button>
            </form>
        </div>
    </section>
<?php else: ?>
    <section class="dashboard-panel admin-form-panel">
        <h2 class="h5">Create institution</h2>
        <p class="text-muted">The initial Admin will use the existing institution-code and email OTP sign-in. No password or OTP is created or sent during onboarding; confirm that the administrator can receive email.</p>
        <form method="post" action="<?php echo e(route_url('/super-admin/institutions')); ?>" class="admin-form-grid">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create">
            <?php $renderProfileFields($formValues); ?>
            <label class="admin-form-field">Institution type
                <select class="form-select" name="institution_type" required>
                    <option value="">Choose type</option>
                    <?php foreach (admin_panel_institution_types() as $type => $label): ?>
                        <option value="<?php echo e($type); ?>"<?php echo ($formValues['institution_type'] ?? '') === $type ? ' selected' : ''; ?>><?php echo e($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="admin-form-field">Institution currency
                <select class="form-select" name="currency_code" required>
                    <?php foreach (admin_panel_institution_currency_options() as $currency => $label): ?>
                        <option value="<?php echo e($currency); ?>"<?php echo ($formValues['currency_code'] ?? 'INR') === $currency ? ' selected' : ''; ?>><?php echo e($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Stored as institution profile data only; payment processing remains unchanged.</small>
            </label>
            <label class="admin-form-field">Established year<input class="form-control" type="number" name="established_year" min="1000" max="<?php echo e(date('Y')); ?>" value="<?php echo e($formValues['established_year'] ?? ''); ?>"></label>
            <label class="admin-form-field">Initial administrator name<input class="form-control" name="administrator_name" maxlength="150" value="<?php echo e($formValues['administrator_name'] ?? ''); ?>" required></label>
            <label class="admin-form-field">Initial administrator email<input class="form-control" type="email" name="administrator_email" maxlength="255" value="<?php echo e($formValues['administrator_email'] ?? ''); ?>" required></label>
            <label class="admin-form-field">Initial administrator phone<input class="form-control" type="tel" name="administrator_phone" maxlength="20" value="<?php echo e($formValues['administrator_phone'] ?? ''); ?>" required></label>
            <label class="admin-form-field">Initial status
                <select class="form-select" name="status" required>
                    <option value="active"<?php echo ($formValues['status'] ?? 'active') === 'active' ? ' selected' : ''; ?>>Active</option>
                    <option value="inactive"<?php echo ($formValues['status'] ?? '') === 'inactive' ? ' selected' : ''; ?>>Inactive</option>
                </select>
            </label>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="administrator_email_confirmed" value="1" id="administrator-email-confirmed"<?php echo ($formValues['administrator_email_confirmed'] ?? '') === '1' ? ' checked' : ''; ?> required>
                <label class="form-check-label" for="administrator-email-confirmed">I confirm that the initial administrator can receive email at this address.</label>
            </div>
            <div class="admin-form-actions mt-4"><button class="btn btn-cobalt" type="submit">Create institution and administrator</button></div>
        </form>
    </section>
<?php endif; ?>

<section class="dashboard-panel admin-recent-payments-panel">
    <div class="dashboard-panel-heading"><div><h2>Institutions</h2><p><?php echo e((string) count($institutions)); ?> institutions shown (maximum 500).</p></div></div>
    <form method="get" action="<?php echo e(route_url('/super-admin/institutions')); ?>" class="d-flex flex-wrap gap-2 mb-3">
        <label class="visually-hidden" for="institution-search">Search institutions</label>
        <input class="form-control" id="institution-search" name="search" maxlength="120" value="<?php echo e($search); ?>" placeholder="Search name, code, email, administrator">
        <label class="visually-hidden" for="institution-status">Filter by status</label>
        <select class="form-select" id="institution-status" name="status">
            <option value="">All statuses</option>
            <?php foreach (['active', 'inactive', 'trial', 'archived'] as $status): ?>
                <option value="<?php echo e($status); ?>"<?php echo $statusFilter === $status ? ' selected' : ''; ?>><?php echo e(ucfirst($status)); ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-outline-primary" type="submit">Filter</button>
    </form>
    <?php if ($institutions === []): ?>
        <div class="admin-panel-empty"><i class="bi bi-buildings" aria-hidden="true"></i><strong>No institutions found</strong><span>Adjust the search or create an institution above.</span></div>
    <?php else: ?>
        <div class="table-responsive"><table class="table admin-panel-table align-middle">
            <thead><tr><th>Institution</th><th>Code</th><th>Type</th><th>Official email</th><th>Status</th><th>Administrator</th><th>Created</th><th></th></tr></thead>
            <tbody><?php foreach ($institutions as $row): ?>
                <tr>
                    <td><?php echo e($row['name']); ?><small><?php echo e((string) $row['currency_code']); ?></small></td>
                    <td><?php echo e($row['code']); ?></td>
                    <td><?php echo e(isset(admin_panel_institution_types()[$row['institution_type'] ?? '']) ? admin_panel_institution_types()[$row['institution_type']] : 'Not set'); ?></td>
                    <td><?php echo e($row['email']); ?></td>
                    <td><?php echo e(ucfirst((string) $row['status'])); ?></td>
                    <td><?php echo e($row['administrator_name'] ?? 'Not assigned'); ?><small><?php echo e($row['administrator_email'] ?? ''); ?></small></td>
                    <td><?php echo e($row['created_at']); ?></td>
                    <td><a class="btn btn-sm btn-outline-primary" href="<?php echo e(route_url('/super-admin/institutions?id=' . (int) $row['id'])); ?>">Details</a></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php if ($institution !== null): ?>
    <script src="<?php echo e(APP_URL); ?>/public/assets/js/institution-branding.js" defer></script>
<?php endif; ?>
<?php render_app_page_end('admin'); ?>
