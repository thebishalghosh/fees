<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/academic-management-service.php';

require_http_methods(['GET', 'POST']);
require_placeholder_route_access();
$db = db_connect();
$institutionId = authenticated_institution_id(['super_admin', 'admin', 'staff']);
if ($institutionId === null) {
    http_response_code(403);
    exit('Institution access is unavailable for this account.');
}
if (!academic_management_schema_ready($db)) {
    academic_management_render_schema_required_page();
    exit;
}
$user = current_user();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    try {
        $action = $_POST['action'] ?? null;
        if ($action === 'create_department') {
            $name = academic_management_text($_POST['name'] ?? null, 'department name', 150);
            $code = is_string($_POST['code'] ?? null) && trim($_POST['code']) !== '' ? trim($_POST['code']) : null;
            academic_management_create_department($db, $institutionId, (int) $user['id'], $name, $code);
            set_flash('success', 'Department created.');
        } elseif ($action === 'assign_department') {
            $courseId = academic_management_id($_POST['course_id'] ?? null, 'programme');
            $departmentId = academic_management_id($_POST['department_id'] ?? null, 'department');
            academic_management_assign_course_department(
                $db,
                $institutionId,
                (int) $user['id'],
                $courseId,
                $departmentId
            );
            set_flash('success', 'Programme department updated.');
        } elseif ($action === 'offer_course') {
            $yearId = academic_management_id($_POST['academic_year_id'] ?? null, 'academic year');
            $courseId = academic_management_id($_POST['course_id'] ?? null, 'course');
            academic_management_create_course_offering(
                $db,
                $institutionId,
                (int) $user['id'],
                $yearId,
                $courseId
            );
            set_flash('success', 'Course or programme offered for the academic year.');
        } elseif ($action === 'create_term') {
            $yearId = academic_management_id($_POST['academic_year_id'] ?? null, 'academic year');
            $courseId = academic_management_id($_POST['course_id'] ?? null, 'course');
            $name = academic_management_text($_POST['name'] ?? null, 'term name', 120);
            $type = is_string($_POST['term_type'] ?? null) ? $_POST['term_type'] : '';
            $sequence = academic_management_id($_POST['sequence_number'] ?? null, 'term sequence');
            $startDate = is_string($_POST['start_date'] ?? null) && $_POST['start_date'] !== ''
                ? $_POST['start_date']
                : null;
            $endDate = is_string($_POST['end_date'] ?? null) && $_POST['end_date'] !== ''
                ? $_POST['end_date']
                : null;
            academic_management_create_term(
                $db,
                $institutionId,
                (int) $user['id'],
                $yearId,
                $courseId,
                $name,
                $type,
                $sequence,
                $startDate,
                $endDate
            );
            set_flash('success', 'Academic term or semester created.');
        } elseif ($action === 'add_batch_group') {
            $yearId = academic_management_id($_POST['academic_year_id'] ?? null, 'academic year');
            $batchId = academic_management_id($_POST['batch_id'] ?? null, 'batch');
            academic_management_create_batch_group($db, $institutionId, $yearId, $batchId);
            set_flash('success', 'Batch is available for this academic year’s enrolments.');
        } else {
            throw new InvalidArgumentException('Choose a supported programme-management action.');
        }
        safe_redirect('/admin/academics/programmes', 303);
    } catch (InvalidArgumentException | DomainException | OutOfBoundsException $exception) {
        $errors[] = $exception->getMessage();
    } catch (mysqli_sql_exception $exception) {
        if ((int) $exception->getCode() === 1062) {
            $errors[] = 'That department, year offering, or term already exists.';
        } else {
            log_error('academic_programme_save_failed', [
                'error_type' => get_class($exception),
                'error_code' => $exception->getCode(),
            ]);
            $errors[] = 'The programme record could not be saved.';
        }
    } catch (Throwable $exception) {
        log_error('academic_programme_save_failed', [
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
        ]);
        $errors[] = 'The programme record could not be saved.';
    }
}

$configuration = academic_management_configuration($db, $institutionId);
$setup = academic_management_programme_setup_data($db, $institutionId);
$canManage = user_has_compatible_permission('courses.create', 'academics.manage')
    || user_has_compatible_permission('courses.edit', 'academics.manage');

render_app_page_start(
    'Programmes, Departments and Terms',
    'Configure academic-year programme offerings, departments, terms, and coaching batch groups.',
    'admin',
    ['admin-dashboard.css']
);
?>
<?php echo render_flash_messages(); ?>
<?php foreach ($errors as $error): ?><div class="alert alert-danger" role="alert"><?php echo e($error); ?></div><?php endforeach; ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item active" aria-current="page">Programmes and terms</li></ol></nav>
<?php if (!in_array($configuration['academic_model'], ['college', 'university', 'coaching'], true)): ?>
    <section class="dashboard-panel"><h2>Programme management unavailable</h2><p>Configure this institution as a college, university, or coaching centre to manage academic-year programmes.</p><a class="btn btn-cobalt" href="<?php echo e(route_url('/admin/academics/years')); ?>">Academic model and years</a></section>
<?php else: ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h2 class="h4 mb-0"><?php echo e(ucfirst((string) $configuration['academic_model'])); ?> academic structure</h2>
        <?php if ($canManage): ?><a class="btn btn-cobalt" href="<?php echo e(route_url('/admin/courses/create')); ?>">Create course or programme</a><?php endif; ?>
        <a class="btn btn-outline-primary" href="<?php echo e(route_url('/admin/academics/subjects')); ?>">Manage subjects</a>
    </div>
    <?php if ($canManage && in_array($configuration['academic_model'], ['college', 'university'], true)): ?>
        <section class="dashboard-panel mb-4" aria-labelledby="create-department-title">
            <h2 id="create-department-title">Departments</h2>
            <form method="post" action="<?php echo e(route_url('/admin/academics/programmes')); ?>" class="row g-3 align-items-end">
                <?php echo csrf_field(); ?><input type="hidden" name="action" value="create_department">
                <label class="col-md-5">Department name<input class="form-control" name="name" maxlength="150" required></label>
                <label class="col-md-4">Code (optional)<input class="form-control" name="code" maxlength="50"></label>
                <div class="col-md-3"><button class="btn btn-primary" type="submit">Add department</button></div>
            </form>
            <?php if ($setup['departments'] !== []): ?><div class="d-flex flex-wrap gap-2 mt-3"><?php foreach ($setup['departments'] as $department): ?><span class="badge text-bg-light"><?php echo e($department['name']); ?></span><?php endforeach; ?></div><?php endif; ?>
        </section>
    <?php endif; ?>
    <?php if ($canManage): ?>
        <section class="dashboard-panel mb-4" aria-labelledby="offer-course-title">
            <h2 id="offer-course-title">Offer course or programme in a year</h2>
            <form method="post" action="<?php echo e(route_url('/admin/academics/programmes')); ?>" class="row g-3 align-items-end">
                <?php echo csrf_field(); ?><input type="hidden" name="action" value="offer_course">
                <label class="col-md-4">Academic year<select class="form-select" name="academic_year_id" required><option value="">Select a year</option><?php foreach ($setup['years'] as $year): ?><option value="<?php echo e((string) $year['id']); ?>"><?php echo e($year['name'] . ' (' . $year['status'] . ')'); ?></option><?php endforeach; ?></select></label>
                <label class="col-md-5">Course / programme<select class="form-select" name="course_id" required><option value="">Select a course</option><?php foreach ($setup['courses'] as $course): ?><option value="<?php echo e((string) $course['id']); ?>"><?php echo e($course['name'] . ($course['department_id'] === null ? '' : ' — Department #' . $course['department_id'])); ?></option><?php endforeach; ?></select></label>
                <div class="col-md-3"><button class="btn btn-primary" type="submit">Offer course</button></div>
            </form>
        </section>
        <?php if ($configuration['academic_model'] === 'university' && $setup['departments'] !== []): ?>
            <section class="dashboard-panel mb-4" aria-labelledby="assign-department-title">
                <h2 id="assign-department-title">Assign a programme to a department</h2>
                <form method="post" action="<?php echo e(route_url('/admin/academics/programmes')); ?>" class="row g-3 align-items-end">
                    <?php echo csrf_field(); ?><input type="hidden" name="action" value="assign_department">
                    <label class="col-md-5">Programme<select class="form-select" name="course_id" required><option value="">Select a programme</option><?php foreach ($setup['courses'] as $course): ?><option value="<?php echo e((string) $course['id']); ?>"><?php echo e($course['name']); ?></option><?php endforeach; ?></select></label>
                    <label class="col-md-4">Department<select class="form-select" name="department_id" required><option value="">Select a department</option><?php foreach ($setup['departments'] as $department): ?><option value="<?php echo e((string) $department['id']); ?>"><?php echo e($department['name']); ?></option><?php endforeach; ?></select></label>
                    <div class="col-md-3"><button class="btn btn-primary" type="submit">Save department</button></div>
                </form>
            </section>
        <?php endif; ?>
        <?php if (in_array($configuration['academic_model'], ['college', 'university'], true)): ?>
            <section class="dashboard-panel mb-4" aria-labelledby="create-term-title">
                <h2 id="create-term-title">Add a term or semester</h2>
                <form method="post" action="<?php echo e(route_url('/admin/academics/programmes')); ?>" class="row g-3 align-items-end">
                    <?php echo csrf_field(); ?><input type="hidden" name="action" value="create_term">
                    <label class="col-md-3">Academic year<select class="form-select" name="academic_year_id" required><option value="">Select a year</option><?php foreach ($setup['years'] as $year): ?><option value="<?php echo e((string) $year['id']); ?>"><?php echo e($year['name']); ?></option><?php endforeach; ?></select></label>
                    <label class="col-md-3">Programme<select class="form-select" name="course_id" required><option value="">Select an offered programme</option><?php $seen = []; foreach ($setup['offerings'] as $offering): $key = $offering['academic_year_id'] . ':' . $offering['course_id']; if (isset($seen[$key])) { continue; } $seen[$key] = true; ?><option value="<?php echo e((string) $offering['course_id']); ?>"><?php echo e($offering['year_name'] . ' / ' . $offering['course_name']); ?></option><?php endforeach; ?></select></label>
                    <label class="col-md-2">Name<input class="form-control" name="name" maxlength="120" required></label>
                    <label class="col-md-2">Type<select class="form-select" name="term_type" required><option value="semester">Semester</option><option value="term">Term</option><option value="trimester">Trimester</option><option value="year">Year</option></select></label>
                    <label class="col-md-1">Order<input class="form-control" type="number" min="1" max="65535" name="sequence_number" required></label>
                    <div class="col-md-1"><button class="btn btn-primary" type="submit">Add</button></div>
                    <label class="col-md-3">Starts (optional)<input class="form-control" type="date" name="start_date"></label>
                    <label class="col-md-3">Ends (optional)<input class="form-control" type="date" name="end_date"></label>
                </form>
            </section>
        <?php endif; ?>
        <?php if ($configuration['academic_model'] === 'coaching'): ?>
            <section class="dashboard-panel mb-4" aria-labelledby="batch-year-title">
                <h2 id="batch-year-title">Make a batch available in an academic year</h2>
                <form method="post" action="<?php echo e(route_url('/admin/academics/programmes')); ?>" class="row g-3 align-items-end">
                    <?php echo csrf_field(); ?><input type="hidden" name="action" value="add_batch_group">
                    <label class="col-md-4">Academic year<select class="form-select" name="academic_year_id" required><option value="">Select a year</option><?php foreach ($setup['years'] as $year): ?><option value="<?php echo e((string) $year['id']); ?>"><?php echo e($year['name']); ?></option><?php endforeach; ?></select></label>
                    <label class="col-md-5">Batch<select class="form-select" name="batch_id" required><option value="">Select a batch</option><?php foreach ($setup['batches'] as $batch): ?><option value="<?php echo e((string) $batch['id']); ?>"><?php echo e($batch['course_name'] . ' / ' . $batch['name']); ?></option><?php endforeach; ?></select></label>
                    <div class="col-md-3"><button class="btn btn-primary" type="submit">Enable batch</button></div>
                </form>
            </section>
        <?php endif; ?>
    <?php endif; ?>
    <section class="dashboard-panel">
        <h2>Academic-year programme offerings</h2>
        <?php if ($setup['offerings'] === []): ?><p class="text-muted mb-0">No course offerings have been created.</p><?php else: ?>
            <div class="table-responsive"><table class="table admin-panel-table align-middle"><thead><tr><th>Academic year</th><th>Programme</th><th>Offering status</th><th>Term</th></tr></thead><tbody>
                <?php foreach ($setup['offerings'] as $offering): ?><tr><td><?php echo e($offering['year_name']); ?></td><td><?php echo e($offering['course_name']); ?></td><td><?php echo e(ucfirst($offering['offering_status'])); ?></td><td><?php echo e($offering['term_name'] === null ? '—' : $offering['term_name'] . ' (' . $offering['term_type'] . ' ' . $offering['sequence_number'] . ')'); ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php render_app_page_end('admin'); ?>
