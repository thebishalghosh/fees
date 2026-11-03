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

render_app_page_start('Institution Profile', 'Update the profile details for your currently authorized institution.', 'admin', ['admin-dashboard.css']);
?>
<?php echo render_flash_messages(); ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item active" aria-current="page">Institution Profile</li></ol></nav>
<?php foreach ($errors as $error): ?><div class="alert alert-danger" role="alert"><?php echo e($error); ?></div><?php endforeach; ?>
<section class="dashboard-panel admin-form-panel">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
            <h2 class="h5 mb-1">Institution Logo</h2>
            <p class="text-muted mb-0">Upload a PNG or JPEG image, up to 2 MB.</p>
        </div>
        <?php if (institution_branding_logo_file(is_string($institution['logo_path'] ?? null) ? $institution['logo_path'] : null) !== null): ?>
            <img class="institution-logo-preview" src="<?php echo e(route_url('/admin/institution-logo?v=' . $logoPreviewVersion)); ?>" alt="Current institution logo">
        <?php endif; ?>
    </div>
    <?php if (institution_branding_logo_file(is_string($institution['logo_path'] ?? null) ? $institution['logo_path'] : null) === null): ?>
        <p class="text-muted mt-3 mb-0">No institution logo has been uploaded.</p>
    <?php endif; ?>
    <div class="d-flex flex-wrap align-items-end gap-3 mt-3">
        <form method="post" enctype="multipart/form-data" action="<?php echo e(route_url('/admin/settings/institution')); ?>" class="d-flex flex-wrap align-items-end gap-2">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="upload_logo">
            <label class="admin-form-field">Replace Logo<input class="form-control" type="file" name="institution_logo" accept="image/png,image/jpeg" required></label>
            <button class="btn btn-cobalt" type="submit"><?php echo $institution['logo_path'] === null ? 'Upload Logo' : 'Replace Logo'; ?></button>
        </form>
        <?php if ($institution['logo_path'] !== null): ?>
            <form method="post" action="<?php echo e(route_url('/admin/settings/institution')); ?>" onsubmit="return confirm('Remove the institution logo?');">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="remove_logo">
                <button class="btn btn-outline-danger" type="submit">Remove Logo</button>
            </form>
        <?php endif; ?>
    </div>
</section>
<section class="dashboard-panel admin-form-panel">
    <form method="post" action="<?php echo e(route_url('/admin/settings/institution')); ?>" class="admin-form-grid">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save_profile">
        <fieldset class="border rounded-3 p-3">
            <legend class="float-none w-auto px-2 h6">Institution identity</legend>
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
        <fieldset class="border rounded-3 p-3">
            <legend class="float-none w-auto px-2 h6">Contact information</legend>
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
        <fieldset class="border rounded-3 p-3">
            <legend class="float-none w-auto px-2 h6">Institution administration</legend>
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
        <fieldset class="border rounded-3 p-3">
            <legend class="float-none w-auto px-2 h6">Registration and affiliation</legend>
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
        <div class="admin-form-actions mt-4"><button class="btn btn-cobalt" type="submit">Save profile</button></div>
    </form>
</section>
<?php render_app_page_end('admin'); ?>
