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
        if ($action === 'create_subject') {
            $name = academic_management_text($_POST['name'] ?? null, 'subject name', 150);
            $code = is_string($_POST['code'] ?? null) && trim($_POST['code']) !== ''
                ? trim($_POST['code'])
                : null;
            $type = is_string($_POST['subject_type'] ?? null) ? $_POST['subject_type'] : '';
            academic_management_create_subject($db, $institutionId, (int) $user['id'], $name, $code, $type);
            set_flash('success', 'Subject added to the institution catalog.');
        } elseif ($action === 'map_class' || $action === 'map_section') {
            $subjectId = academic_management_id($_POST['subject_id'] ?? null, 'subject');
            $scope = $_POST[$action === 'map_class' ? 'class_offering' : 'section_offering'] ?? null;
            $expectedPattern = $action === 'map_class'
                ? '/^([1-9][0-9]*):([1-9][0-9]*)$/D'
                : '/^([1-9][0-9]*):([1-9][0-9]*):([1-9][0-9]*)$/D';
            if (!is_string($scope) || preg_match($expectedPattern, $scope, $matches) !== 1) {
                throw new InvalidArgumentException('Choose a valid class or section offering.');
            }
            $yearId = academic_management_id($matches[1], 'academic year');
            $classId = academic_management_id($matches[2], 'class');
            $sectionId = $action === 'map_section'
                ? academic_management_id($matches[3], 'section')
                : null;
            $mode = is_string($_POST['assignment_mode'] ?? null) ? $_POST['assignment_mode'] : 'include';
            academic_management_map_subject(
                $db,
                $institutionId,
                (int) $user['id'],
                $yearId,
                $classId,
                $subjectId,
                $sectionId,
                $mode
            );
            set_flash('success', $sectionId === null ? 'Subject mapped to the class.' : 'Section subject rule saved.');
        } elseif ($action === 'map_course') {
            $subjectId = academic_management_id($_POST['subject_id'] ?? null, 'subject');
            $scope = $_POST['course_offering'] ?? null;
            if (!is_string($scope)
                || preg_match('/^([1-9][0-9]*):([1-9][0-9]*):(0|[1-9][0-9]*)$/D', $scope, $matches) !== 1) {
                throw new InvalidArgumentException('Choose a valid programme or term offering.');
            }
            $yearId = academic_management_id($matches[1], 'academic year');
            $courseId = academic_management_id($matches[2], 'programme');
            $termId = $matches[3] === '0' ? null : academic_management_id($matches[3], 'academic term');
            academic_management_map_course_subject(
                $db,
                $institutionId,
                (int) $user['id'],
                $yearId,
                $courseId,
                $subjectId,
                $termId
            );
            set_flash('success', 'Subject mapped to the programme or term.');
        } else {
            throw new InvalidArgumentException('Choose a supported subject action.');
        }
        safe_redirect('/admin/academics/subjects', 303);
    } catch (InvalidArgumentException | DomainException | OutOfBoundsException $exception) {
        $errors[] = $exception->getMessage();
    } catch (mysqli_sql_exception $exception) {
        if ((int) $exception->getCode() === 1062) {
            $errors[] = 'That subject or mapping already exists.';
        } else {
            log_error('academic_subject_save_failed', [
                'error_type' => get_class($exception),
                'error_code' => $exception->getCode(),
            ]);
            $errors[] = 'The subject record could not be saved.';
        }
    } catch (Throwable $exception) {
        log_error('academic_subject_save_failed', [
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
        ]);
        $errors[] = 'The subject record could not be saved.';
    }
}

$configuration = academic_management_configuration($db, $institutionId);
$setup = academic_management_subject_setup_data($db, $institutionId);
$canManage = user_has_compatible_permission('academic_years.edit', 'academics.manage');
$isSchool = $configuration['academic_model'] === 'school';
$hasSubjectOptions = $setup['subjects'] !== [];
$hasMappingOptions = $isSchool
    ? $setup['class_offerings'] !== []
    : $setup['course_offerings'] !== [];
$hasSectionOptions = $isSchool && array_filter(
    $setup['class_offerings'],
    static fn(array $offering): bool => $offering['section_id'] !== null
) !== [];

render_app_page_start(
    'Subjects',
    'Add subjects once, then assign them to the classes or programmes where they are taught.',
    'admin',
    ['admin-dashboard.css', 'admin-academic-subjects.css']
);
?>
<?php echo render_flash_messages(); ?>
<?php foreach ($errors as $error): ?><div class="alert alert-danger" role="alert"><?php echo e($error); ?></div><?php endforeach; ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/academics/classes')); ?>">Academics</a></li><li class="breadcrumb-item active" aria-current="page">Subjects</li></ol></nav>
<?php if (!in_array($configuration['academic_model'], ['school', 'college', 'university', 'coaching'], true)): ?>
    <section class="dashboard-panel"><h2>Academic model required</h2><p>Configure this institution's academic model before managing academic subjects.</p><a class="btn btn-cobalt" href="<?php echo e(route_url('/admin/academics/years')); ?>">Configure academic model</a></section>
<?php else: ?>
    <div class="academic-subjects-page">
        <section class="academic-subjects-intro" aria-label="How subject setup works">
            <span class="academic-subjects-intro-icon" aria-hidden="true"><i class="bi bi-journal-bookmark"></i></span>
            <div><h2>Set up subjects in two steps</h2><p>First add each subject to your catalog. Then assign it to the classes or programmes where it is taught.</p></div>
            <div class="academic-subjects-count"><strong><?php echo count($setup['subjects']); ?></strong><span><?php echo count($setup['subjects']) === 1 ? 'subject' : 'subjects'; ?> in catalog</span></div>
        </section>

        <?php if ($canManage): ?>
            <section class="dashboard-panel academic-subjects-step" aria-labelledby="create-subject-title">
                <div class="academic-subjects-step-number">1</div>
                <div class="academic-subjects-step-content">
                    <span class="academic-subjects-kicker">Subject catalog</span>
                    <h2 id="create-subject-title">Add a subject</h2>
                    <p>Add it once here; you can assign it to multiple classes or programmes afterward.</p>
                    <form method="post" action="<?php echo e(route_url('/admin/academics/subjects')); ?>" class="academic-subjects-form academic-subjects-create-form">
                        <?php echo csrf_field(); ?><input type="hidden" name="action" value="create_subject">
                        <label>Subject name<input class="form-control" name="name" maxlength="150" required placeholder="e.g. Mathematics"></label>
                        <label>Code <span>Optional</span><input class="form-control" name="code" maxlength="50" placeholder="e.g. MATH"></label>
                        <label>Type<select class="form-select" name="subject_type" required><option value="core">Core</option><option value="elective">Elective</option><option value="optional">Optional</option></select></label>
                        <button class="btn btn-cobalt" type="submit"><i class="bi bi-plus-circle" aria-hidden="true"></i> Add subject</button>
                    </form>
                </div>
            </section>

            <section class="dashboard-panel academic-subjects-step" aria-labelledby="map-subject-title">
                <div class="academic-subjects-step-number">2</div>
                <div class="academic-subjects-step-content">
                    <span class="academic-subjects-kicker">Assign to your academic setup</span>
                    <h2 id="map-subject-title"><?php echo $isSchool ? 'Assign subjects to a class' : 'Assign subjects to a programme'; ?></h2>
                    <p><?php echo $isSchool ? 'Choose an academic year and class, then select the subject to teach there.' : 'Choose an offered programme or term, then select the subject to teach there.'; ?></p>
                    <?php if (!$hasSubjectOptions): ?>
                        <div class="academic-subjects-empty"><i class="bi bi-info-circle" aria-hidden="true"></i><span>Add at least one subject in Step 1 before assigning it.</span></div>
                    <?php elseif (!$hasMappingOptions): ?>
                        <div class="academic-subjects-empty">
                            <i class="bi bi-info-circle" aria-hidden="true"></i>
                            <span><?php echo $isSchool ? 'Set up an academic year and add classes before assigning subjects.' : 'Offer a programme in an academic year before assigning subjects.'; ?></span>
                            <a class="btn btn-outline-primary btn-sm" href="<?php echo e(route_url($isSchool ? '/admin/academics/classes' : '/admin/academics/programmes')); ?>"><?php echo $isSchool ? 'Set up classes' : 'Set up programmes'; ?></a>
                        </div>
                    <?php elseif ($isSchool): ?>
                        <form method="post" action="<?php echo e(route_url('/admin/academics/subjects')); ?>" class="academic-subjects-form academic-subjects-map-form">
                            <?php echo csrf_field(); ?><input type="hidden" name="action" value="map_class">
                            <label>Academic year and class<select class="form-select" name="class_offering" required><option value="">Select a class</option><?php $seenClassOfferings = []; foreach ($setup['class_offerings'] as $offering): $scopeKey = $offering['academic_year_id'] . ':' . $offering['class_id']; if (isset($seenClassOfferings[$scopeKey])) { continue; } $seenClassOfferings[$scopeKey] = true; ?><option value="<?php echo e($scopeKey); ?>"><?php echo e($offering['year_name'] . ' / ' . $offering['class_name']); ?></option><?php endforeach; ?></select></label>
                            <label>Subject<select class="form-select" name="subject_id" required><option value="">Select a subject</option><?php foreach ($setup['subjects'] as $subject): ?><option value="<?php echo e((string) $subject['id']); ?>"><?php echo e($subject['name']); ?></option><?php endforeach; ?></select></label>
                            <button class="btn btn-cobalt" type="submit">Assign subject</button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="<?php echo e(route_url('/admin/academics/subjects')); ?>" class="academic-subjects-form academic-subjects-map-form">
                            <?php echo csrf_field(); ?><input type="hidden" name="action" value="map_course">
                            <label>Programme or term<select class="form-select" name="course_offering" required><option value="">Select a programme or term</option><?php $seenCourseOfferings = []; foreach ($setup['course_offerings'] as $offering): $termValue = $offering['term_id'] === null ? 0 : (int) $offering['term_id']; $scopeKey = $offering['academic_year_id'] . ':' . $offering['course_id'] . ':' . $termValue; if (isset($seenCourseOfferings[$scopeKey])) { continue; } $seenCourseOfferings[$scopeKey] = true; ?><option value="<?php echo e($scopeKey); ?>"><?php echo e($offering['year_name'] . ' / ' . $offering['course_name'] . ($offering['term_name'] === null ? '' : ' / ' . $offering['term_name'])); ?></option><?php endforeach; ?></select></label>
                            <label>Subject<select class="form-select" name="subject_id" required><option value="">Select a subject</option><?php foreach ($setup['subjects'] as $subject): ?><option value="<?php echo e((string) $subject['id']); ?>"><?php echo e($subject['name']); ?></option><?php endforeach; ?></select></label>
                            <button class="btn btn-cobalt" type="submit">Assign subject</button>
                        </form>
                    <?php endif; ?>
                </div>
            </section>

            <?php if ($isSchool): ?>
                <details class="dashboard-panel academic-subjects-advanced">
                    <summary><span><i class="bi bi-sliders" aria-hidden="true"></i> Optional: customize subjects for a section</span><i class="bi bi-chevron-down" aria-hidden="true"></i></summary>
                    <div class="academic-subjects-advanced-content">
                        <p>Use this only when a section needs an extra subject or should exclude a subject assigned to its class.</p>
                        <?php if (!$hasSubjectOptions || !$hasMappingOptions || !$hasSectionOptions): ?>
                            <div class="academic-subjects-empty">
                                <i class="bi bi-info-circle" aria-hidden="true"></i>
                                <span><?php echo !$hasSubjectOptions ? 'Add subjects first. Then you can make section-specific rules.' : (!$hasMappingOptions ? 'Set up an academic year and class first.' : 'Add sections to a class before creating section-specific rules.'); ?></span>
                                <?php if ($hasSubjectOptions && (!$hasMappingOptions || !$hasSectionOptions)): ?><a class="btn btn-outline-primary btn-sm" href="<?php echo e(route_url('/admin/academics/classes')); ?>"><?php echo $hasMappingOptions ? 'Manage sections' : 'Set up classes'; ?></a><?php endif; ?>
                            </div>
                        <?php else: ?>
                            <form method="post" action="<?php echo e(route_url('/admin/academics/subjects')); ?>" class="academic-subjects-form academic-subjects-section-form">
                                <?php echo csrf_field(); ?><input type="hidden" name="action" value="map_section">
                                <label>Section<select class="form-select" name="section_offering" required><option value="">Select a section</option><?php foreach ($setup['class_offerings'] as $offering): if ($offering['section_id'] === null) { continue; } $scopeKey = $offering['academic_year_id'] . ':' . $offering['class_id'] . ':' . $offering['section_id']; ?><option value="<?php echo e($scopeKey); ?>"><?php echo e($offering['year_name'] . ' / ' . $offering['class_name'] . ' / ' . $offering['section_name']); ?></option><?php endforeach; ?></select></label>
                                <label>Subject<select class="form-select" name="subject_id" required><option value="">Select a subject</option><?php foreach ($setup['subjects'] as $subject): ?><option value="<?php echo e((string) $subject['id']); ?>"><?php echo e($subject['name']); ?></option><?php endforeach; ?></select></label>
                                <label>Rule<select class="form-select" name="assignment_mode"><option value="include">Include subject</option><option value="exclude">Exclude class subject</option></select></label>
                                <button class="btn btn-outline-primary" type="submit">Save section rule</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endif; ?>
        <?php endif; ?>

        <section class="dashboard-panel academic-subjects-catalog" aria-labelledby="subject-catalog-title">
            <div class="academic-subjects-catalog-heading"><div><span class="academic-subjects-kicker">Your subjects</span><h2 id="subject-catalog-title">Subject catalog</h2></div><span class="academic-subjects-catalog-count"><?php echo count($setup['subjects']); ?> total</span></div>
            <?php if ($setup['subjects'] === []): ?>
                <div class="academic-subjects-catalog-empty"><i class="bi bi-journal-plus" aria-hidden="true"></i><p>No subjects yet. Add your first subject above to get started.</p></div>
            <?php else: ?>
                <div class="table-responsive"><table class="table admin-panel-table align-middle"><thead><tr><th>Subject</th><th>Code</th><th>Type</th><th>Status</th></tr></thead><tbody>
                    <?php foreach ($setup['subjects'] as $subject): ?><tr><td><strong><?php echo e($subject['name']); ?></strong></td><td><?php echo e($subject['code'] ?? '—'); ?></td><td><?php echo e(ucfirst($subject['subject_type'])); ?></td><td><span class="academic-subjects-status"><?php echo e(ucfirst($subject['status'])); ?></span></td></tr><?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
            <?php if ($isSchool && $setup['class_mappings'] !== []): ?>
                <details class="academic-subjects-mappings">
                    <summary>View class subject assignments (<?php echo count($setup['class_mappings']); ?>)</summary>
                    <div class="table-responsive"><table class="table admin-panel-table align-middle"><thead><tr><th>Academic year</th><th>Class</th><th>Subject</th></tr></thead><tbody><?php foreach ($setup['class_mappings'] as $mapping): ?><tr><td><?php echo e($mapping['year_name']); ?></td><td><?php echo e($mapping['class_name']); ?></td><td><?php echo e($mapping['subject_name']); ?></td></tr><?php endforeach; ?></tbody></table></div>
                </details>
            <?php endif; ?>
        </section>
    </div>
<?php endif; ?>
<?php render_app_page_end('admin'); ?>
