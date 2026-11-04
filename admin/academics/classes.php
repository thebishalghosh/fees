<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/academic-management-service.php';
require_once __DIR__ . '/../../services/admin-panel-service.php';

require_http_methods(['GET', 'POST']);
require_placeholder_route_access();
$db = db_connect();
$user = current_user();
if ($user === null) {
    http_response_code(403);
    exit('Institution access is unavailable for this account.');
}
$errors = [];
$isSuperAdmin = $user['user_type'] === 'super_admin';
try {
    $institutionId = academic_management_resolve_institution_id(
        $db,
        $user,
        $_SERVER['REQUEST_METHOD'] === 'POST'
            ? ($_POST['institution_id'] ?? null)
            : ($_GET['institution_id'] ?? null)
    );
} catch (InvalidArgumentException | OutOfBoundsException $exception) {
    $institutionId = null;
    $errors[] = $exception->getMessage();
}

if ($institutionId !== null && !academic_management_schema_ready($db)) {
    academic_management_render_schema_required_page();
    exit;
}

if ($institutionId !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    try {
        if (($_POST['action'] ?? '') === 'offer_class') {
            $yearId = academic_management_id($_POST['academic_year_id'] ?? null, 'academic year');
            $classId = academic_management_id($_POST['class_id'] ?? null, 'class');
            academic_management_create_class_offering($db, $institutionId, (int) $user['id'], $yearId, $classId);
            set_flash('success', 'Class added for this academic year.');
        } elseif (($_POST['action'] ?? '') === 'create_section') {
            $offering = $_POST['offering'] ?? null;
            if (!is_string($offering)
                || preg_match('/^([1-9][0-9]*):([1-9][0-9]*)$/D', $offering, $matches) !== 1) {
                throw new InvalidArgumentException('Choose a valid class offering.');
            }
            $yearId = academic_management_id($matches[1], 'academic year');
            $classId = academic_management_id($matches[2], 'class');
            $name = academic_management_text($_POST['name'] ?? null, 'section name', 120);
            $code = is_string($_POST['code'] ?? null) ? trim($_POST['code']) : '';
            $capacityInput = $_POST['capacity'] ?? '';
            if (!is_string($capacityInput)) {
                throw new InvalidArgumentException('Enter a valid section capacity.');
            }
            $capacityInput = trim($capacityInput);
            $capacity = $capacityInput === '' ? null : filter_var($capacityInput, FILTER_VALIDATE_INT);
            if ($capacity === false) {
                throw new InvalidArgumentException('Enter a positive whole-number section capacity.');
            }
            academic_management_create_section(
                $db,
                $institutionId,
                (int) $user['id'],
                $yearId,
                $classId,
                $name,
                $code === '' ? null : $code,
                $capacity
            );
            set_flash('success', 'Section added to the class.');
        } elseif (($_POST['action'] ?? '') === 'update_class') {
            $classId = academic_management_id($_POST['class_id'] ?? null, 'class');
            $values = admin_panel_validate_class($_POST);
            admin_panel_update_class($db, $institutionId, (int) $user['id'], $classId, $values);
            set_flash('success', 'Class details updated.');
        } elseif (($_POST['action'] ?? '') === 'update_section') {
            $offering = $_POST['offering'] ?? null;
            if (!is_string($offering)
                || preg_match('/^([1-9][0-9]*):([1-9][0-9]*)$/D', $offering, $matches) !== 1) {
                throw new InvalidArgumentException('Choose a valid class offering.');
            }
            $yearId = academic_management_id($matches[1], 'academic year');
            $classId = academic_management_id($matches[2], 'class');
            $sectionId = academic_management_id($_POST['section_id'] ?? null, 'section');
            $name = academic_management_text($_POST['name'] ?? null, 'section name', 120);
            $code = is_string($_POST['code'] ?? null) ? trim($_POST['code']) : '';
            $capacityInput = $_POST['capacity'] ?? '';
            $status = $_POST['status'] ?? null;
            if (!is_string($capacityInput) || !is_string($status)) {
                throw new InvalidArgumentException('Enter valid section details.');
            }
            $capacityInput = trim($capacityInput);
            $capacity = $capacityInput === '' ? null : filter_var($capacityInput, FILTER_VALIDATE_INT);
            if ($capacity === false) {
                throw new InvalidArgumentException('Enter a positive whole-number section capacity.');
            }
            academic_management_update_section(
                $db,
                $institutionId,
                (int) $user['id'],
                $yearId,
                $classId,
                $sectionId,
                $name,
                $code === '' ? null : $code,
                $capacity,
                $status
            );
            set_flash('success', $status === 'active' ? 'Section details updated.' : 'Section archived; existing student records were preserved.');
        } else {
            throw new InvalidArgumentException('Choose a supported class-management action.');
        }
        safe_redirect(
            '/admin/academics/classes?institution_id=' . $institutionId . '&year_id=' . $yearId,
            303
        );
    } catch (InvalidArgumentException | DomainException | OutOfBoundsException $exception) {
        $errors[] = $exception->getMessage();
    } catch (mysqli_sql_exception $exception) {
        if ((int) $exception->getCode() === 1062) {
            $errors[] = 'This class or section is already set up for the selected academic year.';
        } else {
            log_error('academic_class_save_failed', [
                'error_type' => get_class($exception),
                'error_code' => $exception->getCode(),
            ]);
            $errors[] = 'The academic class record could not be saved.';
        }
    } catch (Throwable $exception) {
        log_error('academic_class_save_failed', [
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
        ]);
        $errors[] = 'The academic class record could not be saved.';
    }
}

$configuration = $institutionId === null
    ? null
    : academic_management_configuration($db, $institutionId);
$setup = $institutionId === null
    ? ['years' => [], 'classes' => [], 'offerings' => []]
    : academic_management_school_setup_data($db, $institutionId);
$years = $setup['years'];
$requestedYearId = filter_var($_GET['year_id'] ?? null, FILTER_VALIDATE_INT);
$selectedYear = null;
if (is_int($requestedYearId) && $requestedYearId > 0) {
    foreach ($years as $year) {
        if ((int) $year['id'] === $requestedYearId) {
            $selectedYear = $year;
            break;
        }
    }
}
if ($selectedYear === null) {
    foreach ($years as $year) {
        if ((int) $year['id'] === $configuration['active_academic_year_id']) {
            $selectedYear = $year;
            break;
        }
    }
}
if ($selectedYear === null && $years !== []) {
    $selectedYear = $years[0];
}
$selectedYearId = is_array($selectedYear) ? (int) $selectedYear['id'] : null;
$selectedYearIsOpen = is_array($selectedYear) && $selectedYear['status'] !== 'closed';
$catalogClasses = array_values(array_filter(
    $setup['classes'],
    static fn(array $class): bool => $class['status'] === 'active'
));
$offeringGroups = [];
$offeredClassIds = [];
$sectionCount = 0;
foreach ($setup['offerings'] as $offering) {
    if ($selectedYearId === null || (int) $offering['academic_year_id'] !== $selectedYearId) {
        continue;
    }
    $key = $selectedYearId . ':' . $offering['class_id'];
    if (!isset($offeringGroups[$key])) {
        $offeringGroups[$key] = [
            'key' => $key,
            'class_id' => (int) $offering['class_id'],
            'class_name' => (string) $offering['class_name'],
            'class_code' => $offering['class_code'],
            'class_description' => $offering['class_description'],
            'class_status' => (string) $offering['class_status'],
            'status' => (string) $offering['offering_status'],
            'sections' => [],
        ];
        $offeredClassIds[] = (int) $offering['class_id'];
    }
    if ($offering['section_id'] !== null) {
        $offeringGroups[$key]['sections'][] = [
            'id' => (int) $offering['section_id'],
            'name' => (string) $offering['section_name'],
            'code' => $offering['section_code'],
            'capacity' => $offering['section_capacity'],
            'status' => (string) $offering['section_status'],
        ];
        $sectionCount++;
    }
}
$availableClasses = array_values(array_filter(
    $catalogClasses,
    static fn(array $class): bool => !in_array((int) $class['id'], $offeredClassIds, true)
));
$institutionQuery = $isSuperAdmin && $institutionId !== null
    ? 'institution_id=' . $institutionId
    : '';
$createClassQuery = array_filter([
    $institutionQuery,
    $selectedYearId === null ? '' : 'year_id=' . $selectedYearId,
]);
$createClassUrl = route_url('/admin/academics/classes/create')
    . ($createClassQuery === [] ? '' : '?' . implode('&', $createClassQuery));
$classesPageUrl = route_url('/admin/academics/classes')
    . ($institutionQuery === '' ? '' : '?' . $institutionQuery)
    . ($selectedYearId === null ? '' : ($institutionQuery === '' ? '?' : '&') . 'year_id=' . $selectedYearId);
$canCreateClass = $isSuperAdmin
    || user_has_compatible_permission('classes.create', 'academics.manage');
$canEditClasses = $isSuperAdmin
    || user_has_compatible_permission('classes.edit', 'academics.manage');
$canManage = $canCreateClass || $canEditClasses;

render_app_page_start(
    'Classes and Sections',
    'Set up the classes and sections students will be assigned to.',
    'admin',
    ['admin-dashboard.css', 'admin-academic-classes.css']
);
?>
<?php echo render_flash_messages(); ?>
<?php foreach ($errors as $error): ?><div class="alert alert-danger" role="alert"><?php echo e($error); ?></div><?php endforeach; ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item active" aria-current="page">Classes and sections</li></ol></nav>
<?php if ($institutionId === null): ?>
    <section class="dashboard-panel academic-classes-empty"><span aria-hidden="true"><i class="bi bi-buildings"></i></span><h2>Select an institution to continue</h2><p>Academic classes and sections belong to an institution. Choose one above to view or manage its records.</p></section>
<?php elseif ($configuration['academic_model'] !== 'school'): ?>
    <section class="dashboard-panel academic-classes-notice">
        <span class="academic-classes-notice-icon" aria-hidden="true"><i class="bi bi-info-circle"></i></span>
        <div><h2>Classes are for school setups</h2><p>This institution uses a different academic model. Review the model and year settings to continue with the right setup.</p><a class="btn btn-cobalt" href="<?php echo e(route_url('/admin/academics/years')); ?>">Go to academic setup</a></div>
    </section>
<?php else: ?>
    <div class="academic-classes-page">
        <section class="dashboard-panel academic-classes-year-card" aria-labelledby="academic-classes-year-title">
            <div class="academic-classes-year-copy">
                <span class="academic-classes-kicker">Your school setup</span>
                <h2 id="academic-classes-year-title"><?php echo is_array($selectedYear) ? e($selectedYear['name']) : 'Choose an academic year'; ?></h2>
                <p><?php echo is_array($selectedYear) ? 'Classes and sections below are for this year.' : 'Create an academic year before setting up classes.'; ?></p>
            </div>
            <div class="academic-classes-year-actions">
                <?php if ($years !== []): ?>
                    <form method="get" action="<?php echo e(route_url('/admin/academics/classes')); ?>" class="academic-classes-year-picker">
                        <?php if ($isSuperAdmin): ?><input type="hidden" name="institution_id" value="<?php echo e((string) $institutionId); ?>"><?php endif; ?>
                        <label for="year-picker">Academic year</label>
                        <select class="form-select" id="year-picker" name="year_id" onchange="this.form.submit()">
                            <?php foreach ($years as $year): ?>
                                <option value="<?php echo e((string) $year['id']); ?>"<?php echo (int) $year['id'] === $selectedYearId ? ' selected' : ''; ?>><?php echo e($year['name'] . ((int) $year['id'] === $configuration['active_academic_year_id'] ? ' (Current)' : '')); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <noscript><button class="btn btn-outline-primary mt-2" type="submit">Show year</button></noscript>
                    </form>
                <?php endif; ?>
                <?php if ($canCreateClass): ?>
                    <a class="btn btn-cobalt academic-classes-create-button" href="<?php echo e($createClassUrl); ?>"><i class="bi bi-plus-circle" aria-hidden="true"></i> Create class</a>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($selectedYear === null): ?>
            <section class="dashboard-panel academic-classes-empty">
                <span aria-hidden="true"><i class="bi bi-calendar-plus"></i></span><h2>No academic year yet</h2>
                <p>Add an academic year first. Then you can organize its classes and sections here.</p>
                <a class="btn btn-cobalt" href="<?php echo e(route_url('/admin/academics/years')); ?>">Set up academic year</a>
            </section>
        <?php else: ?>
            <div class="academic-classes-summary" aria-label="Class setup summary">
                <div><span class="academic-classes-summary-icon"><i class="bi bi-building"></i></span><span>Classes this year</span><strong><?php echo count($offeringGroups); ?></strong></div>
                <div><span class="academic-classes-summary-icon"><i class="bi bi-diagram-2"></i></span><span>Sections added</span><strong><?php echo $sectionCount; ?></strong></div>
            </div>

            <?php if ($canCreateClass): ?>
                <section class="dashboard-panel academic-classes-step" aria-labelledby="offer-class-title">
                    <div class="academic-classes-step-number">1</div>
                    <div class="academic-classes-step-content">
                        <span class="academic-classes-kicker">First</span>
                        <h2 id="offer-class-title">Add a class for this year</h2>
                        <p>Choose an existing class, or create one first if it is not in the list.</p>
                        <?php if (!$canManage): ?>
                            <div class="academic-classes-inline-empty"><span>You can view this setup but do not have permission to change classes or sections.</span></div>
                        <?php elseif (!$selectedYearIsOpen): ?>
                            <div class="alert alert-secondary mb-0">This academic year is closed, so it cannot be changed.</div>
                        <?php elseif ($catalogClasses === []): ?>
                            <div class="academic-classes-inline-empty"><span>No active classes are set up yet.</span><?php if ($canCreateClass): ?><a class="btn btn-cobalt" href="<?php echo e($createClassUrl); ?>">Create your first class</a><?php endif; ?></div>
                        <?php elseif ($availableClasses === []): ?>
                            <div class="academic-classes-complete"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> All active classes have been added to this year.</div>
                        <?php else: ?>
                            <form method="post" action="<?php echo e($classesPageUrl); ?>" class="academic-classes-form">
                                <?php echo csrf_field(); ?><input type="hidden" name="action" value="offer_class"><?php if ($isSuperAdmin): ?><input type="hidden" name="institution_id" value="<?php echo e((string) $institutionId); ?>"><?php endif; ?><input type="hidden" name="academic_year_id" value="<?php echo e((string) $selectedYearId); ?>">
                                <label for="class-picker">Choose a class</label>
                                <select class="form-select" id="class-picker" name="class_id" required>
                                    <option value="">Select a class</option>
                                    <?php foreach ($availableClasses as $class): ?><option value="<?php echo e((string) $class['id']); ?>"><?php echo e($class['name']); ?></option><?php endforeach; ?>
                                </select>
                                <button class="btn btn-cobalt" type="submit">Add class</button>
                            </form>
                            <?php if ($canCreateClass): ?><a class="academic-classes-secondary-link" href="<?php echo e($createClassUrl); ?>"><i class="bi bi-plus-circle" aria-hidden="true"></i>Create a new class instead</a><?php endif; ?>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="dashboard-panel academic-classes-offerings" aria-labelledby="class-list-title">
                <div class="academic-classes-list-heading">
                    <div><span class="academic-classes-kicker">Then add sections</span><h2 id="class-list-title">Classes in <?php echo e($selectedYear['name']); ?></h2><p>Sections are groups within a class, such as A, B, or C.</p></div>
                    <a class="btn btn-outline-primary btn-sm" href="<?php echo e(route_url('/admin/academics/subjects')); ?>">Manage subjects</a>
                </div>
                <?php if ($offeringGroups === []): ?>
                    <div class="academic-classes-empty academic-classes-empty-compact">
                        <span aria-hidden="true"><i class="bi bi-building-add"></i></span><strong>No classes added for this year</strong>
                        <p>Use Step 1 above to add a class. It will appear here.</p>
                    </div>
                <?php else: ?>
                    <div class="academic-classes-list">
                        <?php foreach ($offeringGroups as $group): ?>
                            <article class="academic-classes-class">
                                <div class="academic-classes-class-heading">
                                    <span class="academic-classes-class-icon" aria-hidden="true"><i class="bi bi-building"></i></span>
                                    <div><h3><?php echo e($group['class_name']); ?></h3><span><?php echo e(ucfirst($group['class_status'])); ?> · <?php echo count($group['sections']); ?> <?php echo count($group['sections']) === 1 ? 'section' : 'sections'; ?></span></div>
                                </div>
                                <?php if ($canEditClasses): ?>
                                    <details class="academic-classes-add-section">
                                        <summary><i class="bi bi-pencil-square" aria-hidden="true"></i>Edit class</summary>
                                        <form method="post" action="<?php echo e($classesPageUrl); ?>" class="academic-classes-section-form academic-classes-class-form">
                                            <?php echo csrf_field(); ?><input type="hidden" name="action" value="update_class"><?php if ($isSuperAdmin): ?><input type="hidden" name="institution_id" value="<?php echo e((string) $institutionId); ?>"><?php endif; ?><input type="hidden" name="academic_year_id" value="<?php echo e((string) $selectedYearId); ?>"><input type="hidden" name="class_id" value="<?php echo e((string) $group['class_id']); ?>">
                                            <label>Class name<input class="form-control" name="name" maxlength="120" required value="<?php echo e($group['class_name']); ?>"></label>
                                            <label>Code <span>Optional</span><input class="form-control" name="code" maxlength="50" value="<?php echo e((string) ($group['class_code'] ?? '')); ?>"></label>
                                            <label>Status<select class="form-select" name="status"><option value="active"<?php echo $group['class_status'] === 'active' ? ' selected' : ''; ?>>Active</option><option value="inactive"<?php echo $group['class_status'] === 'inactive' ? ' selected' : ''; ?>>Inactive (archive)</option></select></label>
                                            <label>Description <span>Optional</span><input class="form-control" name="description" maxlength="20000" value="<?php echo e((string) ($group['class_description'] ?? '')); ?>"></label>
                                            <button class="btn btn-primary" type="submit">Save class</button>
                                        </form>
                                        <small class="academic-classes-record-note">Archiving keeps historical enrolments and fee records.</small>
                                    </details>
                                <?php endif; ?>
                                <?php if ($group['sections'] !== []): ?>
                                    <ul class="academic-classes-section-list">
                                        <?php foreach ($group['sections'] as $section): ?><li><i class="bi bi-diagram-2" aria-hidden="true"></i><span><?php echo e($section['name']); ?></span><?php if ($section['code'] !== null && $section['code'] !== ''): ?><small><?php echo e($section['code']); ?></small><?php endif; ?><?php if ($section['status'] !== 'active'): ?><small>Archived</small><?php endif; ?></li><?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p class="academic-classes-no-sections">No sections yet. Add one to make student placement easier.</p>
                                <?php endif; ?>
                                <?php if ($canEditClasses && $selectedYearIsOpen): foreach ($group['sections'] as $section): ?>
                                    <details class="academic-classes-section-edit">
                                        <summary>Edit <?php echo e($section['name']); ?></summary>
                                        <form method="post" action="<?php echo e($classesPageUrl); ?>" class="academic-classes-section-form">
                                            <?php echo csrf_field(); ?><input type="hidden" name="action" value="update_section"><?php if ($isSuperAdmin): ?><input type="hidden" name="institution_id" value="<?php echo e((string) $institutionId); ?>"><?php endif; ?><input type="hidden" name="offering" value="<?php echo e($group['key']); ?>"><input type="hidden" name="section_id" value="<?php echo e((string) $section['id']); ?>">
                                            <label>Section name<input class="form-control" name="name" maxlength="120" required value="<?php echo e($section['name']); ?>"></label>
                                            <label>Code <span>Optional</span><input class="form-control" name="code" maxlength="50" value="<?php echo e((string) ($section['code'] ?? '')); ?>"></label>
                                            <label>Student limit <span>Optional</span><input class="form-control" type="number" min="1" name="capacity" value="<?php echo e((string) ($section['capacity'] ?? '')); ?>"></label>
                                            <label>Status<select class="form-select" name="status"><option value="active"<?php echo $section['status'] === 'active' ? ' selected' : ''; ?>>Active</option><option value="inactive"<?php echo $section['status'] === 'inactive' ? ' selected' : ''; ?>>Inactive (archive)</option></select></label>
                                            <button class="btn btn-primary" type="submit">Save section</button>
                                        </form>
                                    </details>
                                <?php endforeach; endif; ?>
                                <?php if ($canCreateClass && $selectedYearIsOpen && $group['class_status'] === 'active'): ?>
                                    <details class="academic-classes-add-section">
                                        <summary><i class="bi bi-plus-circle" aria-hidden="true"></i>Add a section to <?php echo e($group['class_name']); ?></summary>
                                        <form method="post" action="<?php echo e($classesPageUrl); ?>" class="academic-classes-section-form">
                                            <?php echo csrf_field(); ?><input type="hidden" name="action" value="create_section"><?php if ($isSuperAdmin): ?><input type="hidden" name="institution_id" value="<?php echo e((string) $institutionId); ?>"><?php endif; ?><input type="hidden" name="offering" value="<?php echo e($group['key']); ?>">
                                            <label>Section name<input class="form-control" name="name" maxlength="120" required placeholder="e.g. Section A"></label>
                                            <label>Code <span>Optional</span><input class="form-control" name="code" maxlength="50" placeholder="e.g. A"></label>
                                            <label>Student limit <span>Optional</span><input class="form-control" type="number" min="1" name="capacity" placeholder="No limit"></label>
                                            <button class="btn btn-primary" type="submit">Save section</button>
                                        </form>
                                    </details>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php render_app_page_end('admin'); ?>
