<?php
declare(strict_types=1);

require_once __DIR__ . '/page-placeholder.php';
require_once __DIR__ . '/../services/admin-panel-service.php';

if (!array_key_exists('adminCourseId', $GLOBALS)
    || ($adminCourseId !== null && !is_int($adminCourseId))) {
    throw new LogicException('The course form is not configured.');
}
require_http_methods(['GET', 'POST']);
require_placeholder_route_access();
$db = db_connect();
$institutionId = admin_panel_institution_id();
$user = current_user();
$course = $adminCourseId === null ? null : admin_panel_course($db, $institutionId, $adminCourseId);
if ($adminCourseId !== null && $course === null) {
    http_response_code(404);
    exit('Course not found.');
}
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    try {
        $values = admin_panel_validate_course($_POST);
        admin_panel_save_course($db, $institutionId, (int) $user['id'], $values, $adminCourseId);
        set_flash('success', $adminCourseId === null ? 'Course created.' : 'Course updated.');
        safe_redirect('/admin/courses/index', 303);
    } catch (InvalidArgumentException $exception) {
        $errors[] = $exception->getMessage();
    } catch (Throwable $exception) {
        log_error('admin_course_save_failed', [
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
        ]);
        $errors[] = 'The course could not be saved. Check that its code is not already in use.';
    }
}
$formValues = [];
foreach (['name', 'code', 'description', 'duration_months', 'status'] as $field) {
    $formValues[$field] = is_string($_POST[$field] ?? null)
        ? $_POST[$field]
        : (string) ($course[$field] ?? ($field === 'status' ? 'active' : ''));
}
$title = $adminCourseId === null ? 'Create Course' : 'Edit Course';
render_app_page_start($title, 'Maintain course details for this institution.', 'admin', ['admin-dashboard.css', 'admin-course-form.css']);
?>
<?php echo render_flash_messages(); ?>
<nav class="course-form-breadcrumb" aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/courses/index')); ?>">Courses</a></li><li class="breadcrumb-item active" aria-current="page"><?php echo e($title); ?></li></ol></nav>
<?php foreach ($errors as $error): ?><div class="alert alert-danger" role="alert"><?php echo e($error); ?></div><?php endforeach; ?>
<section class="course-form-card" aria-labelledby="course-form-title">
    <header class="course-form-header">
        <span class="course-form-icon" aria-hidden="true"><i class="bi bi-journal-bookmark"></i></span>
        <div>
            <span class="course-form-eyebrow">Academic setup</span>
            <h2 id="course-form-title"><?php echo $adminCourseId === null ? 'Course details' : 'Update course details'; ?></h2>
            <p>Add the course information used to organize classes, batches, and student records.</p>
        </div>
    </header>
    <form method="post" action="<?php echo e(route_url($adminCourseId === null ? '/admin/courses/create' : '/admin/courses/edit') . ($adminCourseId === null ? '' : '?id=' . $adminCourseId)); ?>" class="course-form">
        <?php echo csrf_field(); ?>
        <div class="course-form-section-title"><span>01</span><div><h3>Course information</h3><p>Set the name and optional reference details for this course.</p></div></div>
        <label class="course-form-field course-form-field-wide" for="course-name">
            <span>Course name <b>Required</b></span>
            <input class="form-control" id="course-name" name="name" maxlength="150" required placeholder="e.g. Bachelor of Computer Applications" value="<?php echo e($formValues['name']); ?>">
        </label>
        <label class="course-form-field" for="course-code">
            <span>Course code <small>Optional</small></span>
            <input class="form-control" id="course-code" name="code" maxlength="50" placeholder="e.g. BCA" value="<?php echo e($formValues['code']); ?>">
            <small>A short internal reference to help identify the course.</small>
        </label>
        <label class="course-form-field" for="course-duration">
            <span>Duration <small>Optional</small></span>
            <div class="course-duration-input"><input class="form-control" id="course-duration" type="number" min="1" max="1200" name="duration_months" placeholder="e.g. 36" value="<?php echo e($formValues['duration_months']); ?>"><span>months</span></div>
            <small>Enter a duration between 1 and 1200 months.</small>
        </label>
        <div class="course-form-section-title course-form-section-spaced"><span>02</span><div><h3>Visibility and description</h3><p>Control whether the course is available for new academic setup.</p></div></div>
        <label class="course-form-field" for="course-status">
            <span>Status</span>
            <select class="form-select" id="course-status" name="status"><option value="active"<?php echo $formValues['status'] === 'active' ? ' selected' : ''; ?>>Active</option><option value="inactive"<?php echo $formValues['status'] === 'inactive' ? ' selected' : ''; ?>>Inactive</option></select>
            <small>Inactive courses remain in existing records but are unavailable for new selection.</small>
        </label>
        <label class="course-form-field course-form-field-wide" for="course-description">
            <span>Description <small>Optional</small></span>
            <textarea class="form-control" id="course-description" name="description" rows="5" maxlength="20000" placeholder="Add a short overview or any useful course details..."><?php echo e($formValues['description']); ?></textarea>
        </label>
        <footer class="course-form-footer">
            <a class="course-form-cancel" href="<?php echo e(route_url('/admin/courses/index')); ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Cancel</a>
            <button class="btn btn-cobalt course-form-submit" type="submit"><i class="bi bi-check2-circle" aria-hidden="true"></i><?php echo e($adminCourseId === null ? 'Create course' : 'Save changes'); ?></button>
        </footer>
    </form>
</section>
<?php render_app_page_end('admin'); ?>
