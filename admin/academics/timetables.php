<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/timetable-management-service.php';

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
if (!timetable_management_schema_ready($db)) {
    http_response_code(503);
    exit('Timetable infrastructure is unavailable. Apply the additive timetable migration first.');
}
$user = current_user();
$actorUserId = (int) $user['id'];
$canManage = user_has_compatible_permission('academic_years.edit', 'academics.manage');
$errors = [];
$yearId = filter_var($_POST['academic_year_id'] ?? $_GET['academic_year_id'] ?? null, FILTER_VALIDATE_INT);
$yearId = $yearId !== false && $yearId !== null && $yearId > 0 ? (int) $yearId : 0;
$groupId = filter_var($_POST['academic_group_id'] ?? $_GET['academic_group_id'] ?? null, FILTER_VALIDATE_INT);
$groupId = $groupId !== false && $groupId !== null && $groupId > 0 ? (int) $groupId : 0;
$versionId = filter_var($_POST['version_id'] ?? $_GET['version_id'] ?? null, FILTER_VALIDATE_INT);
$versionId = $versionId !== false && $versionId !== null && $versionId > 0 ? (int) $versionId : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    if (!$canManage) {
        http_response_code(403);
        exit('You do not have permission to change timetable records.');
    }
    try {
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        if ($action === 'create_draft') {
            $versionId = timetable_management_create_timetable_draft(
                $db,
                $institutionId,
                $actorUserId,
                $yearId,
                $groupId
            );
            set_flash('success', 'A new timetable draft was created.');
        } elseif ($action === 'add_entry') {
            $periodId = academic_management_id($_POST['period_id'] ?? null, 'period');
            $assignmentId = academic_management_id(
                $_POST['teacher_subject_assignment_id'] ?? null,
                'teacher and subject assignment'
            );
            $classroomId = ($_POST['classroom_id'] ?? '') === ''
                ? null
                : academic_management_id($_POST['classroom_id'], 'classroom');
            timetable_management_add_entry(
                $db,
                $institutionId,
                $versionId,
                $periodId,
                $assignmentId,
                $classroomId,
                is_string($_POST['notes'] ?? null) ? $_POST['notes'] : null
            );
            set_flash('success', 'The timetable entry was added to the draft.');
        } elseif ($action === 'delete_entry') {
            timetable_management_delete_entry(
                $db,
                $institutionId,
                $versionId,
                academic_management_id($_POST['entry_id'] ?? null, 'timetable entry')
            );
            set_flash('success', 'The draft timetable entry was removed.');
        } elseif ($action === 'delete_draft') {
            timetable_management_delete_draft($db, $institutionId, $versionId);
            $versionId = 0;
            set_flash('success', 'The timetable draft was deleted.');
        } elseif ($action === 'validate') {
            $validationErrors = timetable_management_validate_draft($db, $institutionId, $versionId);
            set_flash(
                $validationErrors === [] ? 'success' : 'warning',
                $validationErrors === []
                    ? 'The timetable passed validation.'
                    : implode(' ', $validationErrors)
            );
        } elseif ($action === 'publish') {
            $validationErrors = timetable_management_publish_draft(
                $db,
                $institutionId,
                $actorUserId,
                $versionId
            );
            set_flash(
                $validationErrors === [] ? 'success' : 'warning',
                $validationErrors === []
                    ? 'The timetable was published. Its previous version remains in history.'
                    : 'Publishing was blocked: ' . implode(' ', $validationErrors)
            );
        } elseif ($action === 'clone') {
            $versionId = timetable_management_clone_published(
                $db,
                $institutionId,
                $actorUserId,
                $versionId
            );
            set_flash('success', 'A new editable draft was cloned from the published timetable.');
        } else {
            throw new InvalidArgumentException('Choose a supported timetable action.');
        }
        safe_redirect('/admin/academics/timetables?' . http_build_query([
            'academic_year_id' => $yearId,
            'academic_group_id' => $groupId,
            'version_id' => $versionId,
        ]), 303);
    } catch (InvalidArgumentException | DomainException | OutOfBoundsException $exception) {
        $errors[] = $exception->getMessage();
    } catch (mysqli_sql_exception $exception) {
        if ((int) $exception->getCode() === 1062) {
            $errors[] = 'A timetable slot or assignment already exists with those details.';
        } else {
            log_error('timetable_save_failed', [
                'error_type' => get_class($exception),
                'error_code' => $exception->getCode(),
            ]);
            $errors[] = 'The timetable change could not be saved.';
        }
    } catch (Throwable $exception) {
        log_error('timetable_save_failed', [
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
        ]);
        $errors[] = 'The timetable change could not be saved.';
    }
}

$configuration = academic_management_configuration($db, $institutionId);
if ($yearId < 1) {
    $yearId = (int) ($configuration['active_academic_year_id'] ?? 0);
}
$setup = timetable_management_list_setup_options($db, $institutionId, $yearId);
if ($groupId < 1 && $setup['groups'] !== []) {
    $groupId = (int) $setup['groups'][0]['id'];
}
$builder = null;
$entries = [];
$periods = [];
$assignmentData = ['teachers' => [], 'subjects' => [], 'subject_assignments' => []];
$selectedVersion = null;
if ($yearId > 0 && $groupId > 0) {
    try {
        $builder = timetable_management_get_builder_data($db, $institutionId, $yearId, $groupId);
        if ($versionId > 0) {
            foreach ($builder['versions'] as $candidate) {
                if ((int) $candidate['id'] === $versionId) {
                    $selectedVersion = $candidate;
                    break;
                }
            }
        }
        if ($selectedVersion === null) {
            foreach ($builder['versions'] as $candidate) {
                if ($candidate['status'] === 'draft') {
                    $selectedVersion = $candidate;
                    break;
                }
            }
        }
        if ($selectedVersion === null && $builder['published_version_id'] !== null) {
            foreach ($builder['versions'] as $candidate) {
                if ((int) $candidate['id'] === $builder['published_version_id']) {
                    $selectedVersion = $candidate;
                    break;
                }
            }
        }
        if ($selectedVersion !== null) {
            $versionId = (int) $selectedVersion['id'];
            $entries = timetable_management_get_version_entries($db, $institutionId, $versionId);
            $versionStatement = $db->prepare(
                'SELECT period_schedule_id FROM timetable_versions
                 WHERE institution_id = ? AND id = ?'
            );
            $versionStatement->bind_param('ii', $institutionId, $versionId);
            $versionStatement->execute();
            $scheduleRow = $versionStatement->get_result()->fetch_assoc();
            $versionStatement->close();
            if (is_array($scheduleRow)) {
                $periods = timetable_management_list_periods(
                    $db,
                    $institutionId,
                    $yearId,
                    (int) $scheduleRow['period_schedule_id']
                );
            }
        }
        $assignmentData = timetable_management_list_group_assignments(
            $db,
            $institutionId,
            $yearId,
            $groupId
        );
    } catch (DomainException $exception) {
        $errors[] = $exception->getMessage();
    }
}
$weekDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
$periodsByNumber = [];
$periodsByDay = [];
$entriesByPeriodId = [];
foreach ($periods as $period) {
    $day = (string) $period['day_of_week'];
    $periodNumber = (int) $period['period_number'];
    $periodsByNumber[$periodNumber][$day] = $period;
    $periodsByDay[$day][] = $period;
    if ($day === 'sunday' && !in_array('sunday', $weekDays, true)) {
        $weekDays[] = 'sunday';
    }
}
foreach ($entries as $entry) {
    $entriesByPeriodId[(int) $entry['period_id']] = $entry;
}
ksort($periodsByNumber, SORT_NUMERIC);
$entryDays = [];
foreach ($weekDays as $day) {
    foreach ($periodsByDay[$day] ?? [] as $period) {
        if ($period['period_type'] === 'teaching') {
            $entryDays[] = $day;
            break;
        }
    }
}
$activeTeacherAssignments = [];
$activeTeacherOptions = [];
foreach ($assignmentData['teachers'] as $teacher) {
    if ($teacher['status'] === 'active'
        && $teacher['instructor_status'] === 'active'
        && ($teacher['user_id'] === null || $teacher['account_status'] === 'active')) {
        $activeTeacherOptions[(int) $teacher['id']] = (string) $teacher['full_name'];
    }
}
foreach ($assignmentData['subject_assignments'] as $assignment) {
    $teacherGroupId = (int) $assignment['teacher_group_assignment_id'];
    if ($assignment['status'] === 'active' && isset($activeTeacherOptions[$teacherGroupId])) {
        $activeTeacherAssignments[] = $assignment;
    }
}

render_app_page_start(
    'Timetable builder',
    'Create, validate, publish, and retain versioned class or section schedules.',
    'admin',
    ['admin-dashboard.css', 'admin-timetables.css']
);
?>
<div class="timetable-builder-page">
<?php echo render_flash_messages(); ?>
<?php if ($errors !== []): ?>
    <div class="alert alert-danger timetable-builder-alert" role="alert">
        <?php foreach ($errors as $error): ?><p class="mb-1"><?php echo e($error); ?></p><?php endforeach; ?>
    </div>
<?php endif; ?>
<section class="card-panel timetable-builder-context">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 timetable-builder-context-heading">
        <div>
            <span class="timetable-builder-kicker"><i class="bi bi-calendar2-week" aria-hidden="true"></i> Academic management</span>
            <h2>Select a cohort</h2>
            <p class="text-muted mb-0">Classes without sections use a whole-class timetable; classes with sections are scheduled section by section.</p>
        </div>
        <a class="btn btn-outline-primary" href="<?php echo e(route_url('/admin/academics/timetable-setup')); ?>">
            <i class="bi bi-sliders2" aria-hidden="true"></i> Teacher assignments &amp; periods
        </a>
    </div>
    <form method="get" class="row g-3 align-items-end timetable-builder-filter">
        <div class="col-md-5">
            <label class="form-label" for="academic_year_id">Academic year</label>
            <select class="form-select" name="academic_year_id" id="academic_year_id" required>
                <?php foreach ($setup['years'] as $year): ?>
                    <option value="<?php echo (int) $year['id']; ?>" <?php echo (int) $year['id'] === $yearId ? 'selected' : ''; ?>>
                        <?php echo e($year['name']); ?> (<?php echo e($year['status']); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-5">
            <label class="form-label" for="academic_group_id">Class / section</label>
            <select class="form-select" name="academic_group_id" id="academic_group_id" required>
                <?php foreach ($setup['groups'] as $group): ?>
                    <option value="<?php echo (int) $group['id']; ?>" <?php echo (int) $group['id'] === $groupId ? 'selected' : ''; ?>>
                        <?php echo e($group['label']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Load</button></div>
    </form>
</section>

<?php if ($builder === null || $builder['group'] === null): ?>
<section class="card-panel timetable-builder-empty-state" role="status">
    <span class="timetable-builder-empty-icon"><i class="bi bi-calendar2-x" aria-hidden="true"></i></span>
    <div>
        <h2><?php echo $setup['groups'] === [] ? 'No cohorts available yet' : 'Choose a cohort to continue'; ?></h2>
        <p>
            <?php echo $setup['groups'] === []
                ? 'Add an academic year, class, or section before building a timetable.'
                : 'Select an academic year and class or section above to view its timetable versions.'; ?>
        </p>
    </div>
    <a class="btn btn-outline-primary" href="<?php echo e(route_url($setup['years'] === [] ? '/admin/academics/years' : '/admin/academics/timetable-setup')); ?>">
        <?php echo $setup['years'] === [] ? 'Set up academic year' : 'Manage timetable setup'; ?>
    </a>
</section>
<?php endif; ?>

<?php if ($builder !== null && $builder['group'] !== null): ?>
<section class="card-panel timetable-builder-cohort">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 timetable-builder-cohort-heading">
        <div>
            <span class="timetable-builder-kicker"><i class="bi bi-mortarboard" aria-hidden="true"></i> Academic timetable</span>
            <h2><?php echo e($builder['group']['class_name']); ?><?php if ($builder['group']['section_name'] !== null): ?><span class="timetable-builder-cohort-divider">/</span><?php echo e($builder['group']['section_name']); ?><?php endif; ?></h2>
            <p>
                <?php echo e($builder['group']['group_type'] === 'section' ? 'Section timetable' : 'Class timetable'); ?>
            </p>
        </div>
        <?php if ($canManage): ?>
        <form method="post" class="timetable-builder-create-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create_draft">
            <input type="hidden" name="academic_year_id" value="<?php echo $yearId; ?>">
            <input type="hidden" name="academic_group_id" value="<?php echo $groupId; ?>">
            <button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg" aria-hidden="true"></i> Create draft</button>
        </form>
        <?php endif; ?>
    </div>
    <?php if ($builder['versions'] !== []): ?>
    <form method="get" class="row g-2 align-items-end timetable-builder-version-filter">
        <input type="hidden" name="academic_year_id" value="<?php echo $yearId; ?>">
        <input type="hidden" name="academic_group_id" value="<?php echo $groupId; ?>">
        <div class="col-md-6">
            <label class="form-label" for="version_id">Timetable version</label>
            <select class="form-select" name="version_id" id="version_id">
                <?php foreach ($builder['versions'] as $version): ?>
                    <option value="<?php echo (int) $version['id']; ?>" <?php echo $selectedVersion !== null && (int) $selectedVersion['id'] === (int) $version['id'] ? 'selected' : ''; ?>>
                        Version <?php echo (int) $version['version_number']; ?> — <?php echo e(ucfirst($version['status'])); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><button class="btn btn-outline-primary" type="submit">View</button></div>
    </form>
    <?php endif; ?>
</section>

<?php if ($selectedVersion !== null): ?>
<section class="card-panel timetable-builder-version">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 timetable-builder-version-toolbar">
        <div class="timetable-builder-version-info">
            <span class="timetable-builder-kicker">Selected version</span>
            <h2 class="h5 mb-1">Version <?php echo (int) $selectedVersion['version_number']; ?></h2>
            <span class="badge text-bg-<?php echo $selectedVersion['status'] === 'published' ? 'success' : ($selectedVersion['status'] === 'draft' ? 'warning' : 'secondary'); ?>">
                <?php echo e(ucfirst($selectedVersion['status'])); ?>
            </span>
        </div>
        <div class="d-flex flex-wrap gap-2 timetable-builder-actions">
            <?php if ($canManage && $selectedVersion['status'] === 'draft'): ?>
            <?php foreach (['validate' => 'Validate', 'publish' => 'Publish', 'delete_draft' => 'Delete draft'] as $action => $label): ?>
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="<?php echo e($action); ?>">
                    <input type="hidden" name="academic_year_id" value="<?php echo $yearId; ?>">
                    <input type="hidden" name="academic_group_id" value="<?php echo $groupId; ?>">
                    <input type="hidden" name="version_id" value="<?php echo $versionId; ?>">
                    <button class="btn btn-<?php echo $action === 'publish' ? 'success' : ($action === 'delete_draft' ? 'outline-danger' : 'outline-primary'); ?>"
                            type="submit">
                        <?php echo e($label); ?>
                    </button>
                </form>
            <?php endforeach; ?>
            <?php elseif ($canManage && $selectedVersion['status'] === 'published'): ?>
            <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="clone">
                <input type="hidden" name="academic_year_id" value="<?php echo $yearId; ?>">
                <input type="hidden" name="academic_group_id" value="<?php echo $groupId; ?>">
                <input type="hidden" name="version_id" value="<?php echo $versionId; ?>">
                <button class="btn btn-outline-primary" type="submit">Clone to draft</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($selectedVersion['status'] === 'draft' && $canManage && $periods !== []): ?>
    <form method="post" class="row g-3 align-items-end timetable-builder-entry-form" data-timetable-entry-form>
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="add_entry">
        <input type="hidden" name="academic_year_id" value="<?php echo $yearId; ?>">
        <input type="hidden" name="academic_group_id" value="<?php echo $groupId; ?>">
        <input type="hidden" name="version_id" value="<?php echo $versionId; ?>">
        <div class="col-md-3">
            <label class="form-label" for="entry_day">Day</label>
            <select class="form-select" id="entry_day" data-entry-day required>
                <option value="">Choose a day</option>
                <?php foreach ($entryDays as $day): ?>
                    <option value="<?php echo e($day); ?>"><?php echo e(ucfirst($day)); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="period_id">Period</label>
            <select class="form-select" name="period_id" id="period_id" data-entry-period required>
                <option value="">Choose a day first</option>
                <?php foreach ($periods as $period): if ($period['period_type'] !== 'teaching') { continue; } ?>
                    <option value="<?php echo (int) $period['id']; ?>" data-day="<?php echo e($period['day_of_week']); ?>">
                        <?php echo e('Period ' . $period['period_number'] . ' · ' . $period['label'] . ' · ' . substr($period['start_time'], 0, 5) . '–' . substr($period['end_time'], 0, 5)); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="entry_teacher">Teacher</label>
            <select class="form-select" id="entry_teacher" data-entry-teacher required>
                <option value="">Choose a teacher</option>
                <?php foreach ($activeTeacherOptions as $teacherId => $teacherName): ?>
                    <option value="<?php echo $teacherId; ?>"><?php echo e($teacherName); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="entry_subject">Subject</label>
            <select class="form-select" id="entry_subject" data-entry-subject required>
                <option value="">Choose a subject</option>
                <?php foreach ($activeTeacherAssignments as $assignment): ?>
                    <option value="<?php echo (int) $assignment['subject_id']; ?>"
                        data-teacher-group-id="<?php echo (int) $assignment['teacher_group_assignment_id']; ?>"
                        data-assignment-id="<?php echo (int) $assignment['id']; ?>">
                        <?php echo e($assignment['subject_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <input type="hidden" name="teacher_subject_assignment_id" data-entry-assignment>
        <div class="col-md-4">
            <label class="form-label" for="classroom_id">Physical room <span class="text-muted fw-normal">(optional)</span></label>
            <select class="form-select" name="classroom_id" id="classroom_id">
                <option value="">No room assigned</option>
                <?php foreach ($setup['classrooms'] as $room): if ($room['status'] !== 'active') { continue; } ?>
                    <option value="<?php echo (int) $room['id']; ?>"><?php echo e($room['room_code'] . ' — ' . $room['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-5"><label class="form-label" for="notes">Notes</label><input class="form-control" id="notes" name="notes" maxlength="500"></div>
        <div class="col-md-3"><button class="btn btn-primary w-100" type="submit" data-entry-submit disabled>Add lesson to draft</button></div>
        <?php if ($activeTeacherAssignments === []): ?>
            <div class="col-12"><small class="text-warning">Assign an active teacher and subject in timetable setup before adding lessons.</small></div>
        <?php endif; ?>
    </form>
    <?php endif; ?>

    <?php if ($periods === []): ?>
        <div class="timetable-builder-empty">
            <i class="bi bi-calendar2-x" aria-hidden="true"></i>
            <strong>No weekly periods are set up yet</strong>
            <span>Set period times for each school day, then assign teachers and subjects to the timetable slots.</span>
            <a class="btn btn-outline-primary mt-2" href="<?php echo e(route_url('/admin/academics/timetable-setup?' . http_build_query(['academic_year_id' => $yearId, 'academic_group_id' => $groupId]))); ?>">Set up weekly periods</a>
        </div>
    <?php else: ?>
    <div class="timetable-week-view-heading">
        <div>
            <h3>Weekly timetable</h3>
            <p>Each day can have several periods. Select the day and period, then choose its teacher and subject.</p>
        </div>
        <span class="badge rounded-pill text-bg-light"><?php echo count($weekDays); ?>-day week</span>
    </div>
    <div class="table-responsive timetable-builder-table timetable-week-view">
        <table class="table align-middle">
            <thead><tr><th>Period</th><?php foreach ($weekDays as $day): ?><th><?php echo e(ucfirst($day)); ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach ($periodsByNumber as $periodNumber => $dayPeriods): ?>
                <tr>
                    <th scope="row">Period <?php echo (int) $periodNumber; ?></th>
                    <?php foreach ($weekDays as $day): ?>
                    <td>
                        <?php if (!isset($dayPeriods[$day])): ?>
                            <span class="timetable-week-empty">No slot</span>
                        <?php else:
                            $period = $dayPeriods[$day];
                            $entry = $entriesByPeriodId[(int) $period['id']] ?? null;
                        ?>
                            <div class="timetable-week-slot">
                                <span class="timetable-week-time"><?php echo e(substr($period['start_time'], 0, 5) . '–' . substr($period['end_time'], 0, 5)); ?></span>
                                <span class="timetable-week-label"><?php echo e($period['label']); ?></span>
                                <?php if ($period['period_type'] !== 'teaching'): ?>
                                    <span class="badge text-bg-light">Break</span>
                                <?php elseif ($entry !== null): ?>
                                    <strong><?php echo e($entry['subject_name']); ?></strong>
                                    <span><?php echo e($entry['teacher_name']); ?></span>
                                    <?php if (trim((string) $entry['room_code'] . ' ' . (string) $entry['room_name']) !== ''): ?>
                                        <small><?php echo e(trim((string) $entry['room_code'] . ' ' . (string) $entry['room_name'])); ?></small>
                                    <?php endif; ?>
                                    <?php if (!empty($entry['notes'])): ?><small><?php echo e($entry['notes']); ?></small><?php endif; ?>
                                    <?php if ($canManage && $selectedVersion['status'] === 'draft'): ?>
                                        <form method="post" class="timetable-week-remove">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete_entry">
                                            <input type="hidden" name="academic_year_id" value="<?php echo $yearId; ?>">
                                            <input type="hidden" name="academic_group_id" value="<?php echo $groupId; ?>">
                                            <input type="hidden" name="version_id" value="<?php echo $versionId; ?>">
                                            <input type="hidden" name="entry_id" value="<?php echo (int) $entry['id']; ?>">
                                            <button class="btn btn-sm btn-outline-danger" type="submit">Remove</button>
                                        </form>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="timetable-week-unassigned">Not assigned</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php endif; ?>
</div>
<script src="<?php echo e(APP_URL); ?>/public/assets/js/timetable-builder.js" defer></script>
<?php
render_app_page_end('admin');
