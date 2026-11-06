<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layouts/page.php';
require_once __DIR__ . '/../services/institution-onboarding-service.php';

require_http_methods(['GET', 'POST']);
require_platform_admin();

$db = db_connect();
$errors = [];
$showInstitutionSelectionToast = ($_GET['select_institution'] ?? null) === '1';
$institutionId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$institutionId = $institutionId !== false && $institutionId !== null && $institutionId > 0
    ? (int) $institutionId
    : null;

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    require_valid_csrf_token();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $postedId = filter_var($_POST['institution_id'] ?? null, FILTER_VALIDATE_INT);
    try {
        if ($action === 'create') {
            $createdId = institution_onboarding_create($db, $_POST);
            set_flash('success', 'Institution and initial administrator created.');
            safe_redirect('/platform-admin/dashboard?id=' . $createdId, 303);
        }
        if ($postedId === false || $postedId === null || $postedId < 1) {
            throw new InvalidArgumentException('Choose a valid institution.');
        }
        $institutionId = (int) $postedId;
        if ($action === 'update') {
            institution_onboarding_update($db, $institutionId, $_POST);
            set_flash('success', 'Institution details updated.');
            safe_redirect('/platform-admin/dashboard?id=' . $institutionId, 303);
        }
        if ($action === 'status') {
            $status = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
            institution_onboarding_set_status($db, $institutionId, $status);
            set_flash('success', $status === 'active' ? 'Institution activated.' : 'Institution deactivated.');
            safe_redirect('/platform-admin/dashboard?id=' . $institutionId, 303);
        }
        throw new InvalidArgumentException('The requested institution action is invalid.');
    } catch (mysqli_sql_exception $exception) {
        if ($exception->getCode() === 1062 && str_contains($exception->getMessage(), 'uq_institutions_code')) {
            $errors[] = 'That institution code is already in use.';
        } else {
            log_error('platform_admin_institution_save_failed', [
                'error_type' => get_class($exception),
                'error_code' => $exception->getCode(),
            ]);
            $errors[] = 'The institution could not be saved. Please try again.';
        }
    } catch (InvalidArgumentException | OutOfBoundsException $exception) {
        $errors[] = $exception->getMessage();
    } catch (Throwable $exception) {
        log_error('platform_admin_institution_save_failed', [
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
        ]);
        $errors[] = 'The institution could not be saved. Please try again.';
    }
}

$search = is_string($_GET['search'] ?? null) ? trim($_GET['search']) : '';
$statusFilter = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
try {
    $institutions = institution_onboarding_list($db, $search, $statusFilter);
    $institution = $institutionId === null ? null : institution_onboarding_get($db, $institutionId);
} catch (InvalidArgumentException $exception) {
    $errors[] = $exception->getMessage();
    $institutions = institution_onboarding_list($db);
    $institution = $institutionId === null ? null : institution_onboarding_get($db, $institutionId);
    $search = '';
    $statusFilter = '';
}

$canCreate = platform_admin_session_has_permission('institutions.create');
$canUpdate = platform_admin_session_has_permission('institutions.update');
$canSuspend = platform_admin_session_has_permission('institutions.suspend');
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
    'affiliation_body' => ['Affiliation body', 255, 'text'],
    'affiliation_number' => ['Affiliation number', 120, 'text'],
];
$renderProfileFields = static function (array $values) use ($profileFields): void {
    foreach ($profileFields as $field => [$label, $maximum, $type]) {
        $value = $values[$field] ?? '';
        ?>
        <div class="col-md-6">
            <label class="form-label" for="institution-<?php echo e($field); ?>"><?php echo e($label); ?></label>
            <input class="form-control" id="institution-<?php echo e($field); ?>" type="<?php echo e($type); ?>"
                name="<?php echo e($field); ?>" maxlength="<?php echo e((string) $maximum); ?>"
                value="<?php echo e($value); ?>"<?php echo in_array($field, ['name', 'code', 'email', 'phone', 'address_line1', 'country'], true) ? ' required' : ''; ?>>
        </div>
        <?php
    }
};

render_app_page_start(
    'Institutions',
    'Manage institutions and choose one to open its full administration workspace.',
    'admin'
);
?>

    <?php if ($showInstitutionSelectionToast): ?>
        <div class="toast-container position-fixed bottom-0 end-0 p-3" aria-live="polite" aria-atomic="true">
            <div class="toast align-items-center text-bg-primary border-0 shadow" role="status" data-auto-show-toast>
                <div class="d-flex">
                    <div class="toast-body">Select an institution to open its admin workspace.</div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <?php echo render_flash_messages(); ?>
    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger" role="alert"><?php echo e($error); ?></div>
    <?php endforeach; ?>

    <?php if ($institutionId !== null && $institution === null): ?>
        <div class="alert alert-warning" role="status">The requested institution was not found.</div>
    <?php endif; ?>

    <?php if ($institution !== null && $canUpdate): ?>
        <section class="card shadow-sm border-0 rounded-4 p-3 p-lg-4 mb-4">
            <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                <div>
                    <span class="eyebrow">Institution #<?php echo e((string) $institution['id']); ?></span>
                    <h2 class="h4 mb-1">Edit <?php echo e($institution['name']); ?></h2>
                </div>
                <a class="btn btn-sm btn-outline-secondary" href="<?php echo e(route_url('/platform-admin/dashboard')); ?>">Close</a>
            </div>
            <form method="post" action="<?php echo e(route_url('/platform-admin/dashboard')); ?>" class="row g-3">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="institution_id" value="<?php echo e((string) $institution['id']); ?>">
                <?php $renderProfileFields($institution); ?>
                <div class="col-md-6">
                    <label class="form-label" for="institution-type">Institution type</label>
                    <select class="form-select" id="institution-type" name="institution_type" required>
                        <?php foreach (admin_panel_institution_types() as $type => $label): ?>
                            <option value="<?php echo e($type); ?>"<?php echo ($institution['institution_type'] ?? '') === $type ? ' selected' : ''; ?>><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="institution-currency">Currency</label>
                    <select class="form-select" id="institution-currency" name="currency_code" required>
                        <?php foreach (admin_panel_institution_currency_options() as $currency => $label): ?>
                            <option value="<?php echo e($currency); ?>"<?php echo ($institution['currency_code'] ?? 'INR') === $currency ? ' selected' : ''; ?>><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12"><button class="btn btn-primary" type="submit">Save institution</button></div>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($canCreate): ?>
        <details class="card shadow-sm border-0 rounded-4 p-3 p-lg-4 mb-4">
            <summary class="h5 mb-0">Create institution</summary>
            <p class="text-secondary mt-3">Create the institution profile and its initial administrator. The administrator signs in using the institution code and email OTP.</p>
            <form method="post" action="<?php echo e(route_url('/platform-admin/dashboard')); ?>" class="row g-3 mt-1">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="create">
                <?php $renderProfileFields([]); ?>
                <div class="col-md-6">
                    <label class="form-label" for="new-institution-type">Institution type</label>
                    <select class="form-select" id="new-institution-type" name="institution_type" required>
                        <option value="">Choose type</option>
                        <?php foreach (admin_panel_institution_types() as $type => $label): ?>
                            <option value="<?php echo e($type); ?>"><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="new-institution-currency">Currency</label>
                    <select class="form-select" id="new-institution-currency" name="currency_code" required>
                        <?php foreach (admin_panel_institution_currency_options() as $currency => $label): ?>
                            <option value="<?php echo e($currency); ?>"<?php echo $currency === 'INR' ? ' selected' : ''; ?>><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="administrator-name">Initial administrator name</label>
                    <input class="form-control" id="administrator-name" name="administrator_name" maxlength="150" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="administrator-email">Initial administrator email</label>
                    <input class="form-control" id="administrator-email" name="administrator_email" type="email" maxlength="255" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="administrator-phone">Initial administrator phone</label>
                    <input class="form-control" id="administrator-phone" name="administrator_phone" type="tel" maxlength="20" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="initial-status">Initial status</label>
                    <select class="form-select" id="initial-status" name="status" required>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div class="col-12 form-check ms-2">
                    <input class="form-check-input" id="administrator-email-confirmed" name="administrator_email_confirmed" type="checkbox" value="1" required>
                    <label class="form-check-label" for="administrator-email-confirmed">I confirm the initial administrator can receive email at this address.</label>
                </div>
                <div class="col-12"><button class="btn btn-primary" type="submit">Create institution</button></div>
            </form>
        </details>
    <?php endif; ?>

    <section class="card shadow-sm border-0 rounded-4 p-3 p-lg-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div><h2 class="h4 mb-1">Institution directory</h2><p class="text-secondary mb-0"><?php echo e((string) count($institutions)); ?> institutions shown, up to 500.</p></div>
        </div>
        <form method="get" action="<?php echo e(route_url('/platform-admin/dashboard')); ?>" class="row g-2 mb-3">
            <div class="col-md-6"><label class="visually-hidden" for="institution-search">Search institutions</label><input class="form-control" id="institution-search" name="search" maxlength="120" value="<?php echo e($search); ?>" placeholder="Search name, code, email, administrator"></div>
            <div class="col-md-3">
                <label class="visually-hidden" for="institution-status-filter">Filter by status</label>
                <select class="form-select" id="institution-status-filter" name="status">
                    <option value="">All statuses</option>
                    <?php foreach (['active', 'inactive', 'trial', 'archived'] as $status): ?>
                        <option value="<?php echo e($status); ?>"<?php echo $statusFilter === $status ? ' selected' : ''; ?>><?php echo e(ucfirst($status)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3"><button class="btn btn-primary w-100" type="submit">Filter institutions</button></div>
        </form>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>Institution</th><th>Code</th><th>Type</th><th>Status</th><th>Administrator</th><th>Created</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($institutions as $row): ?>
                    <tr>
                        <td><?php echo e($row['name']); ?><small class="d-block text-secondary"><?php echo e($row['email']); ?></small></td>
                        <td><?php echo e($row['code']); ?></td>
                        <td><?php echo e(admin_panel_institution_types()[$row['institution_type'] ?? ''] ?? 'Not set'); ?></td>
                        <td><?php echo e(ucfirst((string) $row['status'])); ?></td>
                        <td><?php echo e($row['administrator_name'] ?? 'Not assigned'); ?><small class="d-block text-secondary"><?php echo e($row['administrator_email'] ?? ''); ?></small></td>
                        <td><?php echo e($row['created_at']); ?></td>
                        <td class="text-nowrap">
                            <a class="btn btn-sm btn-outline-primary" href="<?php echo e(route_url('/admin/dashboard?institution_id=' . (int) $row['id'])); ?>">Open workspace</a>
                            <?php if ($canUpdate): ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?php echo e(route_url('/platform-admin/dashboard?id=' . (int) $row['id'])); ?>">Edit</a>
                            <?php endif; ?>
                            <?php if ($canSuspend && in_array($row['status'], ['active', 'inactive'], true)): ?>
                                <form method="post" action="<?php echo e(route_url('/platform-admin/dashboard')); ?>" class="d-inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="status">
                                    <input type="hidden" name="institution_id" value="<?php echo e((string) $row['id']); ?>">
                                    <input type="hidden" name="status" value="<?php echo $row['status'] === 'active' ? 'inactive' : 'active'; ?>">
                                    <button class="btn btn-sm btn-outline-secondary" type="submit"><?php echo $row['status'] === 'active' ? 'Deactivate' : 'Activate'; ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($institutions === []): ?>
                    <tr><td colspan="7" class="text-center text-secondary py-4">No institutions match these filters.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php render_app_page_end('admin'); ?>
