<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/admission-workflow-service.php';

require_http_methods(['GET', 'POST']);
require_placeholder_route_access();
if (!user_has_compatible_permission('admissions.edit', 'admissions.manage')) {
    http_response_code(403);
    exit('Forbidden.');
}
$institutionId = authenticated_institution_id(['super_admin', 'admin', 'staff']);
$actor = current_user();
if ($institutionId === null || $actor === null) {
    http_response_code(403);
    exit('Institution access is unavailable for this account.');
}
$db = db_connect();
if (!admission_workflow_schema_ready($db)) {
    http_response_code(503);
    exit('Apply the admission workflow and operational-settings migrations before configuring admissions.');
}

$errors = [];
$posted = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    $posted = $_POST;
    try {
        $action = $_POST['action'] ?? null;
        if ($action === 'save_prefix') {
            admission_workflow_set_student_code_prefix(
                $db,
                $institutionId,
                (int) $actor['id'],
                (string) $actor['user_type'],
                is_string($_POST['prefix'] ?? null) ? $_POST['prefix'] : ''
            );
            set_flash('success', 'Student ID prefix saved.');
            safe_redirect('/admin/admissions/settings', 303);
        }
        if ($action === 'save_sequence') {
            $yearId = filter_var($_POST['academic_year_id'] ?? null, FILTER_VALIDATE_INT);
            $startNumber = filter_var($_POST['start_number'] ?? null, FILTER_VALIDATE_INT);
            if ($yearId === false || $startNumber === false) {
                throw new InvalidArgumentException('Choose an academic year and a valid starting number.');
            }
            admission_workflow_set_student_code_start(
                $db,
                $institutionId,
                (int) $yearId,
                (int) $actor['id'],
                (string) $actor['user_type'],
                (int) $startNumber
            );
            set_flash('success', 'Academic-year student ID sequence saved. The next number never moves backward.');
            safe_redirect('/admin/admissions/settings?year_id=' . (int) $yearId, 303);
        }
        if ($action === 'save_notifications') {
            admission_workflow_save_notification_settings(
                $db,
                $institutionId,
                (int) $actor['id'],
                (string) $actor['user_type'],
                $_POST['enabled_events'] ?? []
            );
            set_flash('success', 'Admission email notification preferences saved.');
            safe_redirect('/admin/admissions/settings', 303);
        }
        throw new InvalidArgumentException('Choose a supported settings action.');
    } catch (InvalidArgumentException | OutOfBoundsException $exception) {
        $errors[] = $exception->getMessage();
    } catch (Throwable $exception) {
        log_error('admission_settings_save_failed', [
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
        ]);
        $errors[] = 'Admission settings could not be saved.';
    }
}

$years = admission_workflow_student_code_years($db, $institutionId);
$selectedYearId = filter_var($_GET['year_id'] ?? ($posted['academic_year_id'] ?? null), FILTER_VALIDATE_INT);
if ($selectedYearId === false || !in_array((int) $selectedYearId, array_map(
    static fn (array $year): int => (int) $year['id'],
    $years
), true)) {
    $selectedYearId = 0;
    foreach ($years as $year) {
        if ($year['status'] === 'active') {
            $selectedYearId = (int) $year['id'];
            break;
        }
    }
    if ($selectedYearId === 0 && $years !== []) {
        $selectedYearId = (int) $years[0]['id'];
    }
}
$selectedYear = null;
foreach ($years as $year) {
    if ((int) $year['id'] === (int) $selectedYearId) {
        $selectedYear = $year;
        break;
    }
}

$statement = $db->prepare('SELECT prefix FROM student_code_settings WHERE institution_id = ? LIMIT 1');
$statement->bind_param('i', $institutionId);
$statement->execute();
$prefix = $statement->get_result()->fetch_column();
$statement->close();
$prefix = is_string($prefix) ? $prefix : 'STU';
$notificationSettings = admission_workflow_notification_settings($db, $institutionId);
if (isset($posted['action']) && $posted['action'] === 'save_notifications'
    && is_array($posted['enabled_events'] ?? null)) {
    $selectedEvents = [];
    foreach ($posted['enabled_events'] as $event) {
        if (is_string($event) && array_key_exists($event, ADMISSION_NOTIFICATION_EVENTS)) {
            $selectedEvents[$event] = true;
        }
    }
    foreach ($notificationSettings as $event => $_enabled) {
        $notificationSettings[$event] = isset($selectedEvents[$event]);
    }
}

render_app_page_start(
    'Admission Settings',
    'Configure student numbering and admission email notifications.',
    'admin',
    ['admin-dashboard.css']
);
?>
<?php echo render_flash_messages(); ?>
<?php foreach ($errors as $error): ?><div class="alert alert-danger" role="alert"><?php echo e($error); ?></div><?php endforeach; ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/admissions/index')); ?>">Admissions</a></li><li class="breadcrumb-item active" aria-current="page">Settings</li></ol></nav>

<section class="dashboard-panel mb-4">
    <h2 class="h5">Institution student ID prefix</h2>
    <p>Generated IDs include this prefix, the academic year, and the year-specific sequential number.</p>
    <form method="post" class="row g-3">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save_prefix">
        <div class="col-md-5"><label class="form-label" for="student-code-prefix">Prefix</label><input class="form-control" id="student-code-prefix" name="prefix" maxlength="20" pattern="[A-Za-z0-9-]+" required value="<?php echo e(is_string($posted['prefix'] ?? null) ? $posted['prefix'] : $prefix); ?>"></div>
        <div class="col-12"><button class="btn btn-primary" type="submit">Save prefix</button></div>
    </form>
</section>

<section class="dashboard-panel mb-4">
    <h2 class="h5">Academic-year starting number</h2>
    <?php if ($years === []): ?>
        <p class="mb-0">Create an academic year before configuring its student ID sequence.</p>
    <?php else: ?>
        <form method="get" action="<?php echo e(route_url('/admin/admissions/settings')); ?>" class="row g-3 mb-3">
            <div class="col-md-6"><label class="form-label" for="sequence-year-filter">Academic year</label><select class="form-select" id="sequence-year-filter" name="year_id" onchange="this.form.submit()">
                <?php foreach ($years as $year): ?><option value="<?php echo e((string) $year['id']); ?>"<?php echo (int) $selectedYearId === (int) $year['id'] ? ' selected' : ''; ?>><?php echo e($year['name'] . ($year['status'] === 'active' ? ' (Active)' : '')); ?></option><?php endforeach; ?>
            </select></div>
        </form>
        <form method="post" class="row g-3">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_sequence">
            <input type="hidden" name="academic_year_id" value="<?php echo e((string) $selectedYearId); ?>">
            <div class="col-md-5"><label class="form-label" for="start-number">Starting sequence</label><input class="form-control" id="start-number" type="number" name="start_number" min="1" max="999999999" required value="<?php echo e(is_string($posted['start_number'] ?? null) ? $posted['start_number'] : (string) ($selectedYear['start_number'] ?? 1)); ?>"></div>
            <div class="col-md-7 align-self-end"><p class="mb-0 text-muted">Current next number: <?php echo e($selectedYear['next_number'] === null ? (string) ($selectedYear['start_number'] ?? 1) : (string) $selectedYear['next_number']); ?>. Saving a lower start value will not rewind the existing counter.</p></div>
            <div class="col-12"><button class="btn btn-primary" type="submit">Save year sequence</button></div>
        </form>
    <?php endif; ?>
</section>

<section class="dashboard-panel">
    <h2 class="h5">Admission email notifications</h2>
    <p>Enabled events use the existing configured mail provider. No SMTP settings are stored here.</p>
    <form method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save_notifications">
        <div class="row g-3 mb-3">
            <?php foreach (ADMISSION_NOTIFICATION_EVENTS as $event => $label): ?>
                <div class="col-md-6"><label class="form-check">
                    <input class="form-check-input" type="checkbox" name="enabled_events[]" value="<?php echo e($event); ?>"<?php echo $notificationSettings[$event] ? ' checked' : ''; ?>>
                    <span class="form-check-label"><?php echo e($label); ?></span>
                </label></div>
            <?php endforeach; ?>
        </div>
        <button class="btn btn-primary" type="submit">Save notification preferences</button>
    </form>
</section>
<?php render_app_page_end('admin'); ?>
