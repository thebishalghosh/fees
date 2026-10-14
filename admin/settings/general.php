<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/admin-panel-service.php';
require_once __DIR__ . '/../../services/institution-branding-service.php';

require_http_methods(['GET', 'POST']);
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
                safe_redirect('/admin/settings/general', 303);
            }
            set_flash('success', $message);
            safe_redirect('/admin/settings/general', 303);
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
            safe_redirect('/admin/settings/general', 303);
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
foreach (['name', 'short_name', 'code', 'email', 'phone', 'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country', 'website'] as $field) {
    $values[$field] = is_string($_POST[$field] ?? null)
        ? $_POST[$field]
        : (string) ($institution[$field] ?? '');
}

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
            <img class="institution-logo-preview" src="<?php echo e(route_url('/admin/institution-logo')); ?>" alt="Current institution logo">
        <?php endif; ?>
    </div>
    <?php if (institution_branding_logo_file(is_string($institution['logo_path'] ?? null) ? $institution['logo_path'] : null) === null): ?>
        <p class="text-muted mt-3 mb-0">No institution logo has been uploaded.</p>
    <?php endif; ?>
    <div class="d-flex flex-wrap align-items-end gap-3 mt-3">
        <form method="post" enctype="multipart/form-data" action="<?php echo e(route_url('/admin/settings/general')); ?>" class="d-flex flex-wrap align-items-end gap-2">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="upload_logo">
            <label class="admin-form-field">Replace Logo<input class="form-control" type="file" name="institution_logo" accept="image/png,image/jpeg" required></label>
            <button class="btn btn-cobalt" type="submit"><?php echo $institution['logo_path'] === null ? 'Upload Logo' : 'Replace Logo'; ?></button>
        </form>
        <?php if ($institution['logo_path'] !== null): ?>
            <form method="post" action="<?php echo e(route_url('/admin/settings/general')); ?>" onsubmit="return confirm('Remove the institution logo?');">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="remove_logo">
                <button class="btn btn-outline-danger" type="submit">Remove Logo</button>
            </form>
        <?php endif; ?>
    </div>
</section>
<section class="dashboard-panel admin-form-panel">
    <form method="post" action="<?php echo e(route_url('/admin/settings/general')); ?>" class="admin-form-grid">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save_profile">
        <?php foreach ([
            'name' => ['Institution name', true],
            'short_name' => ['Short name', false],
            'code' => ['Institution code', false],
            'email' => ['Email', false],
            'phone' => ['Phone', false],
            'address_line1' => ['Address line 1', false],
            'address_line2' => ['Address line 2', false],
            'city' => ['City', false],
            'state' => ['State', false],
            'postal_code' => ['Postal code', false],
            'country' => ['Country', true],
            'website' => ['Website', false],
        ] as $field => [$label, $required]): ?>
            <label class="admin-form-field"><?php echo e($label); ?><input class="form-control" name="<?php echo e($field); ?>" maxlength="<?php echo e((string) ['name'=>255,'short_name'=>80,'code'=>50,'email'=>255,'phone'=>30,'address_line1'=>255,'address_line2'=>255,'city'=>120,'state'=>120,'postal_code'=>20,'country'=>120,'website'=>255][$field]); ?>" value="<?php echo e($values[$field]); ?>"<?php echo $required ? ' required' : ''; ?>></label>
        <?php endforeach; ?>
        <div class="admin-form-actions mt-4"><button class="btn btn-cobalt" type="submit">Save profile</button></div>
    </form>
</section>
<?php render_app_page_end('admin'); ?>
