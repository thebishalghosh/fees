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
$scheduleId = filter_var($_POST['period_schedule_id'] ?? $_GET['period_schedule_id'] ?? null, FILTER_VALIDATE_INT);
$scheduleId = $scheduleId !== false && $scheduleId !== null && $scheduleId > 0 ? (int) $scheduleId : 0;
$defaultPeriodDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
$postedPeriodDays = $_POST['period_days'] ?? $defaultPeriodDays;
$selectedPeriodDays = is_array($postedPeriodDays)
    ? array_values(array_filter(
        $postedPeriodDays,
        static fn(mixed $day): bool => is_string($day)
            && in_array($day, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], true)
    ))
    : $defaultPeriodDays;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    if (!$canManage) {
        http_response_code(403);
        exit('You do not have permission to change timetable setup.');
    }
    try {
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        if ($action === 'assign_teacher_group') {
            $groupId = academic_management_id($_POST['academic_group_id'] ?? null, 'class or section');
            timetable_management_create_teacher_group_assignment(
                $db,
                $institutionId,
                $actorUserId,
                $yearId,
                $groupId,
                academic_management_id($_POST['instructor_id'] ?? null, 'teacher')
            );
            set_flash('success', 'The teacher was assigned to the selected class or section.');
        } elseif ($action === 'assign_teacher_subject') {
            $groupId = academic_management_id($_POST['academic_group_id'] ?? null, 'class or section');
            timetable_management_assign_teacher_subject(
                $db,
                $institutionId,
                $actorUserId,
                $yearId,
                $groupId,
                academic_management_id($_POST['teacher_group_assignment_id'] ?? null, 'teacher assignment'),
                academic_management_id($_POST['subject_id'] ?? null, 'subject')
            );
            set_flash('success', 'The teacher was assigned to the selected subject.');
        } elseif ($action === 'toggle_teacher_group' || $action === 'toggle_teacher_subject') {
            $type = $action === 'toggle_teacher_group'
                ? 'teacher_group_assignments'
                : 'teacher_subject_assignments';
            $nextStatus = ($_POST['next_status'] ?? null) === 'inactive' ? 'inactive' : 'active';
            timetable_management_set_assignment_status(
                $db,
                $institutionId,
                (int) $user['id'],
                academic_management_id($_POST['assignment_id'] ?? null, 'assignment'),
                $type,
                $nextStatus
            );
            set_flash('success', 'The assignment status was updated.');
        } elseif ($action === 'save_classroom') {
            $capacityValue = trim((string) ($_POST['capacity'] ?? ''));
            $capacity = $capacityValue === ''
                ? null
                : academic_management_id($capacityValue, 'classroom capacity');
            timetable_management_save_classroom(
                $db,
                $institutionId,
                $actorUserId,
                (int) ($_POST['classroom_id'] ?? 0),
                is_string($_POST['room_code'] ?? null) ? $_POST['room_code'] : '',
                is_string($_POST['name'] ?? null) ? $_POST['name'] : '',
                is_string($_POST['building'] ?? null) ? $_POST['building'] : null,
                $capacity,
                is_string($_POST['status'] ?? null) ? $_POST['status'] : 'active'
            );
            set_flash('success', 'The classroom was saved.');
        } elseif ($action === 'create_period_schedule') {
            $scheduleId = timetable_management_create_period_schedule(
                $db,
                $institutionId,
                $yearId,
                $actorUserId
            );
            set_flash('success', 'A new draft period schedule was created.');
        } elseif ($action === 'add_period') {
            $periodDays = $_POST['period_days'] ?? null;
            if (!is_array($periodDays)) {
                throw new InvalidArgumentException('Choose at least one weekday for this period.');
            }
            timetable_management_add_periods_for_days(
                $db,
                $institutionId,
                $yearId,
                $scheduleId,
                $periodDays,
                academic_management_id($_POST['period_number'] ?? null, 'period number'),
                is_string($_POST['label'] ?? null) ? $_POST['label'] : '',
                is_string($_POST['start_time'] ?? null) ? $_POST['start_time'] : '',
                is_string($_POST['end_time'] ?? null) ? $_POST['end_time'] : '',
                is_string($_POST['period_type'] ?? null) ? $_POST['period_type'] : ''
            );
            set_flash('success', 'The period was added to each selected weekday.');
        } elseif ($action === 'update_period') {
            timetable_management_update_period(
                $db,
                $institutionId,
                $yearId,
                $scheduleId,
                academic_management_id($_POST['period_id'] ?? null, 'period'),
                is_string($_POST['day_of_week'] ?? null) ? $_POST['day_of_week'] : '',
                academic_management_id($_POST['period_number'] ?? null, 'period number'),
                is_string($_POST['label'] ?? null) ? $_POST['label'] : '',
                is_string($_POST['start_time'] ?? null) ? $_POST['start_time'] : '',
                is_string($_POST['end_time'] ?? null) ? $_POST['end_time'] : '',
                is_string($_POST['period_type'] ?? null) ? $_POST['period_type'] : ''
            );
            set_flash('success', 'The draft period was updated.');
        } elseif ($action === 'delete_period') {
            timetable_management_delete_period(
                $db,
                $institutionId,
                $yearId,
                $scheduleId,
                academic_management_id($_POST['period_id'] ?? null, 'period')
            );
            set_flash('success', 'The draft period was removed.');
        } elseif ($action === 'activate_period_schedule') {
            timetable_management_activate_period_schedule($db, $institutionId, $yearId, $scheduleId);
            set_flash('success', 'The period schedule is now active; the previous schedule was retained as retired.');
        } else {
            throw new InvalidArgumentException('Choose a supported timetable setup action.');
        }
        safe_redirect('/admin/academics/timetable-setup?' . http_build_query([
            'academic_year_id' => $yearId,
            'academic_group_id' => $groupId,
            'period_schedule_id' => $scheduleId,
        ]), 303);
    } catch (InvalidArgumentException | DomainException | OutOfBoundsException $exception) {
        $errors[] = $exception->getMessage();
    } catch (mysqli_sql_exception $exception) {
        if ((int) $exception->getCode() === 1062) {
            $errors[] = 'A record with the same institution-scoped code or assignment already exists.';
        } else {
            log_error('timetable_setup_save_failed', [
                'error_type' => get_class($exception),
                'error_code' => $exception->getCode(),
            ]);
            $errors[] = 'The timetable setup change could not be saved.';
        }
    } catch (Throwable $exception) {
        log_error('timetable_setup_save_failed', [
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
        ]);
        $errors[] = 'The timetable setup change could not be saved.';
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
if ($scheduleId < 1) {
    foreach ($setup['period_schedules'] as $schedule) {
        if ($schedule['status'] === 'draft') {
            $scheduleId = (int) $schedule['id'];
            break;
        }
        if ($schedule['status'] === 'active' && $scheduleId < 1) {
            $scheduleId = (int) $schedule['id'];
        }
    }
}
$assignmentData = ['teachers' => [], 'subjects' => [], 'subject_assignments' => []];
$periods = [];
if ($yearId > 0 && $groupId > 0) {
    try {
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
if ($yearId > 0 && $scheduleId > 0) {
    $periods = timetable_management_list_periods($db, $institutionId, $yearId, $scheduleId);
}

render_app_page_start(
    'Teacher assignments & timetable setup',
    'Allocate teachers and subjects, manage optional physical rooms, and set weekly periods.',
    'admin',
    ['admin-dashboard.css', 'admin-timetable-setup.css']
);
?>
<div class="timetable-setup-page">
<?php echo render_flash_messages(); ?>
<?php if ($errors !== []): ?>
    <div class="alert alert-danger timetable-setup-alert" role="alert">
        <?php foreach ($errors as $error): ?><p class="mb-1"><?php echo e($error); ?></p><?php endforeach; ?>
    </div>
<?php endif; ?>
<section class="timetable-setup-hero" aria-labelledby="timetable-setup-title">
    <div class="timetable-setup-hero-copy">
        <span class="timetable-setup-kicker"><i class="bi bi-calendar2-week" aria-hidden="true"></i> Academic management</span>
        <h2 id="timetable-setup-title">Build your timetable foundation</h2>
        <p>Choose a cohort, assign its teachers and subjects, then prepare rooms and weekly periods.</p>
    </div>
    <a class="btn btn-primary timetable-setup-builder-link" href="<?php echo e(route_url('/admin/academics/timetables')); ?>">
        <i class="bi bi-grid-3x3-gap" aria-hidden="true"></i> Open timetable builder
    </a>
</section>
<section class="card-panel timetable-setup-context" aria-labelledby="timetable-setup-context-title">
    <div class="timetable-setup-section-heading">
        <span class="timetable-setup-section-icon"><i class="bi bi-funnel" aria-hidden="true"></i></span>
        <div>
            <h2 id="timetable-setup-context-title">Setup context</h2>
            <p>Changes below apply to the selected academic year and class or section.</p>
        </div>
    </div>
    <form method="get" class="row g-3 align-items-end timetable-setup-context-form">
        <div class="col-md-5">
            <label class="form-label" for="academic_year_id">Academic year</label>
            <select class="form-select" name="academic_year_id" id="academic_year_id">
                <?php foreach ($setup['years'] as $year): ?>
                    <option value="<?php echo (int) $year['id']; ?>" <?php echo (int) $year['id'] === $yearId ? 'selected' : ''; ?>>
                        <?php echo e($year['name'] . ' (' . $year['status'] . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-5">
            <label class="form-label" for="academic_group_id">Class / section</label>
            <select class="form-select" name="academic_group_id" id="academic_group_id">
                <?php foreach ($setup['groups'] as $group): ?>
                    <option value="<?php echo (int) $group['id']; ?>" <?php echo (int) $group['id'] === $groupId ? 'selected' : ''; ?>><?php echo e($group['label']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Load</button></div>
    </form>
</section>

<div class="row g-4 timetable-setup-grid">
    <div class="col-xl-6">
        <section class="card-panel timetable-setup-card timetable-setup-assignments">
            <div class="timetable-setup-section-heading">
                <span class="timetable-setup-section-icon"><i class="bi bi-person-workspace" aria-hidden="true"></i></span>
                <div>
                    <h2>Teacher and subject assignments</h2>
                    <p>Map active teachers to the selected cohort and its approved subjects.</p>
                </div>
            </div>
            <?php if ($canManage && $setup['groups'] !== []): ?>
            <form method="post" class="row g-2 align-items-end timetable-setup-form mb-3">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="assign_teacher_group">
                <input type="hidden" name="academic_year_id" value="<?php echo $yearId; ?>">
                <input type="hidden" name="academic_group_id" value="<?php echo $groupId; ?>">
                <div class="col-md-8">
                    <label class="form-label" for="instructor_id">Active teacher</label>
                    <select class="form-select" name="instructor_id" id="instructor_id" required>
                        <?php foreach ($setup['instructors'] as $teacher): ?>
                            <option value="<?php echo (int) $teacher['id']; ?>"><?php echo e($teacher['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4"><button class="btn btn-primary w-100" type="submit">Assign to cohort</button></div>
            </form>
            <form method="post" class="row g-2 align-items-end timetable-setup-form mb-3">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="assign_teacher_subject">
                <input type="hidden" name="academic_year_id" value="<?php echo $yearId; ?>">
                <input type="hidden" name="academic_group_id" value="<?php echo $groupId; ?>">
                <div class="col-md-5">
                    <label class="form-label" for="teacher_group_assignment_id">Assigned teacher</label>
                    <select class="form-select" name="teacher_group_assignment_id" id="teacher_group_assignment_id" required>
                        <?php foreach ($assignmentData['teachers'] as $teacher): if ($teacher['status'] !== 'active') { continue; } ?>
                            <option value="<?php echo (int) $teacher['id']; ?>"><?php echo e($teacher['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label" for="subject_id">Mapped subject</label>
                    <select class="form-select" name="subject_id" id="subject_id" required>
                        <?php foreach ($assignmentData['subjects'] as $subject): ?>
                            <option value="<?php echo (int) $subject['subject_id']; ?>"><?php echo e($subject['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Map</button></div>
            </form>
            <?php endif; ?>
            <div class="timetable-setup-table-wrap">
                <h3 class="timetable-setup-subheading">Cohort teachers</h3>
                <table class="table table-sm align-middle">
                    <thead><tr><th>Teacher</th><th>Status</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach ($assignmentData['teachers'] as $teacher): ?>
                        <tr>
                            <td><?php echo e($teacher['full_name']); ?></td>
                            <td><?php echo e(ucfirst($teacher['status'])); ?></td>
                            <?php if ($canManage): ?><td>
                                <form method="post">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="toggle_teacher_group">
                                    <input type="hidden" name="academic_year_id" value="<?php echo $yearId; ?>">
                                    <input type="hidden" name="academic_group_id" value="<?php echo $groupId; ?>">
                                    <input type="hidden" name="assignment_id" value="<?php echo (int) $teacher['id']; ?>">
                                    <input type="hidden" name="next_status" value="<?php echo $teacher['status'] === 'active' ? 'inactive' : 'active'; ?>">
                                    <button class="btn btn-sm btn-outline-secondary" type="submit"><?php echo $teacher['status'] === 'active' ? 'Deactivate' : 'Reactivate'; ?></button>
                                </form>
                            </td><?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($assignmentData['teachers'] === []): ?><tr><td colspan="3" class="text-muted">No teachers assigned yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="timetable-setup-table-wrap">
                <h3 class="timetable-setup-subheading">Subject mappings</h3>
                <table class="table table-sm align-middle">
                    <thead><tr><th>Teacher</th><th>Subject</th><th>Status</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach ($assignmentData['subject_assignments'] as $assignment): ?>
                        <tr>
                            <td><?php echo e($assignment['full_name']); ?></td><td><?php echo e($assignment['subject_name']); ?></td>
                            <td><?php echo e(ucfirst($assignment['status'])); ?></td>
                            <?php if ($canManage): ?><td>
                                <form method="post">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="toggle_teacher_subject">
                                    <input type="hidden" name="academic_year_id" value="<?php echo $yearId; ?>">
                                    <input type="hidden" name="academic_group_id" value="<?php echo $groupId; ?>">
                                    <input type="hidden" name="assignment_id" value="<?php echo (int) $assignment['id']; ?>">
                                    <input type="hidden" name="next_status" value="<?php echo $assignment['status'] === 'active' ? 'inactive' : 'active'; ?>">
                                    <button class="btn btn-sm btn-outline-secondary" type="submit"><?php echo $assignment['status'] === 'active' ? 'Deactivate' : 'Reactivate'; ?></button>
                                </form>
                            </td><?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($assignmentData['subject_assignments'] === []): ?><tr><td colspan="4" class="text-muted">No subject assignments yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div class="col-xl-6">
        <section class="card-panel timetable-setup-card timetable-setup-classrooms mb-4">
            <div class="timetable-setup-section-heading">
                <span class="timetable-setup-section-icon"><i class="bi bi-door-open" aria-hidden="true"></i></span>
                <div>
                    <h2>Physical rooms <span class="timetable-setup-optional">Optional</span></h2>
                    <p>These are real spaces, such as Room 12—not academic classes like Grade 11 / Section A. You can build timetables without adding rooms.</p>
                </div>
            </div>
            <?php if ($canManage): ?>
            <form method="post" class="row g-2 align-items-end timetable-setup-form mb-3">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_classroom">
                <input type="hidden" name="academic_year_id" value="<?php echo $yearId; ?>">
                <div class="col-md-3"><label class="form-label" for="room_code">Room code</label><input class="form-control" id="room_code" name="room_code" maxlength="50" required></div>
                <div class="col-md-4"><label class="form-label" for="room_name">Room name</label><input class="form-control" id="room_name" name="name" maxlength="120" required></div>
                <div class="col-md-3"><label class="form-label" for="building">Building</label><input class="form-control" id="building" name="building" maxlength="120"></div>
                <div class="col-md-2"><label class="form-label" for="capacity">Capacity</label><input class="form-control" id="capacity" name="capacity" type="number" min="1"></div>
                <div class="col-12"><button class="btn btn-primary" type="submit">Add room</button></div>
            </form>
            <?php endif; ?>
            <div class="timetable-setup-table-wrap">
                <table class="table table-sm"><thead><tr><th>Room code</th><th>Room name</th><th>Building</th><th>Capacity</th><th>Status</th></tr></thead>
                    <tbody><?php foreach ($setup['classrooms'] as $room): ?>
                        <tr>
                            <?php if ($canManage): ?>
                            <td colspan="5">
                                <form method="post" class="row g-2 align-items-end">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="save_classroom">
                                    <input type="hidden" name="academic_year_id" value="<?php echo $yearId; ?>">
                                    <input type="hidden" name="classroom_id" value="<?php echo (int) $room['id']; ?>">
                                    <div class="col-md-2"><label class="form-label">Room code</label><input class="form-control" name="room_code" maxlength="50" value="<?php echo e($room['room_code']); ?>" required></div>
                                    <div class="col-md-3"><label class="form-label">Room name</label><input class="form-control" name="name" maxlength="120" value="<?php echo e($room['name']); ?>" required></div>
                                    <div class="col-md-2"><label class="form-label">Building</label><input class="form-control" name="building" maxlength="120" value="<?php echo e($room['building'] ?? ''); ?>"></div>
                                    <div class="col-md-2"><label class="form-label">Capacity</label><input class="form-control" name="capacity" type="number" min="1" value="<?php echo e($room['capacity'] ?? ''); ?>"></div>
                                    <div class="col-md-2"><label class="form-label">Status</label><select class="form-select" name="status"><option value="active" <?php echo $room['status'] === 'active' ? 'selected' : ''; ?>>Active</option><option value="inactive" <?php echo $room['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option></select></div>
                                    <div class="col-md-1"><button class="btn btn-sm btn-outline-primary" type="submit">Save</button></div>
                                </form>
                            </td>
                            <?php else: ?>
                            <td><?php echo e($room['room_code']); ?></td><td><?php echo e($room['name']); ?></td><td><?php echo e($room['building'] ?? '—'); ?></td><td><?php echo e($room['capacity'] ?? '—'); ?></td><td><?php echo e(ucfirst($room['status'])); ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?><?php if ($setup['classrooms'] === []): ?><tr><td colspan="5" class="text-muted">No physical rooms added. Room assignments are optional.</td></tr><?php endif; ?></tbody>
                </table>
            </div>
        </section>

        <section class="card-panel timetable-setup-card timetable-setup-periods">
            <div class="timetable-setup-section-heading">
                <span class="timetable-setup-section-icon"><i class="bi bi-clock-history" aria-hidden="true"></i></span>
                <div>
                    <h2>Weekly periods</h2>
                    <p>Create multiple periods per day. Add a slot once, then apply it to any of the six school days.</p>
                </div>
            </div>
            <?php if ($canManage): ?>
                <form method="post" class="mb-3">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="create_period_schedule">
                    <input type="hidden" name="academic_year_id" value="<?php echo $yearId; ?>">
                    <button class="btn btn-outline-primary" type="submit">Create period schedule draft</button>
                </form>
            <?php endif; ?>
            <form method="get" class="row g-2 align-items-end timetable-setup-schedule-picker mb-3">
                <input type="hidden" name="academic_year_id" value="<?php echo $yearId; ?>">
                <input type="hidden" name="academic_group_id" value="<?php echo $groupId; ?>">
                <div class="col-md-8"><label class="form-label" for="period_schedule_id">Schedule version</label>
                    <select class="form-select" id="period_schedule_id" name="period_schedule_id">
                        <?php foreach ($setup['period_schedules'] as $schedule): ?>
                            <option value="<?php echo (int) $schedule['id']; ?>" <?php echo (int) $schedule['id'] === $scheduleId ? 'selected' : ''; ?>>
                                Version <?php echo (int) $schedule['version_number']; ?> — <?php echo e(ucfirst($schedule['status'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4"><button class="btn btn-outline-primary w-100" type="submit">Load</button></div>
            </form>
            <?php
            $selectedScheduleStatus = '';
            foreach ($setup['period_schedules'] as $schedule) {
                if ((int) $schedule['id'] === $scheduleId) {
                    $selectedScheduleStatus = (string) $schedule['status'];
                    break;
                }
            }
            ?>
            <?php if ($canManage && $selectedScheduleStatus === 'draft'): ?>
            <form method="post" class="row g-2 align-items-end border rounded p-3 mb-3">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="add_period">
                <input type="hidden" name="academic_year_id" value="<?php echo $yearId; ?>">
                <input type="hidden" name="academic_group_id" value="<?php echo $groupId; ?>">
                <input type="hidden" name="period_schedule_id" value="<?php echo $scheduleId; ?>">
                <div class="col-12">
                    <span class="form-label d-block">Days</span>
                    <div class="timetable-weekday-picker" role="group" aria-label="Apply this period to selected days">
                        <?php foreach ([
                            'monday' => 'Mon',
                            'tuesday' => 'Tue',
                            'wednesday' => 'Wed',
                            'thursday' => 'Thu',
                            'friday' => 'Fri',
                            'saturday' => 'Sat',
                            'sunday' => 'Sun',
                        ] as $day => $shortDay): ?>
                            <label class="timetable-weekday-option">
                                <input type="checkbox" name="period_days[]" value="<?php echo e($day); ?>"
                                    <?php echo in_array($day, $selectedPeriodDays, true) ? 'checked' : ''; ?>>
                                <span><?php echo e($shortDay); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <small class="text-muted">Monday–Saturday are selected by default. Choose more than one day to create the same period slot across the week.</small>
                </div>
                <div class="col-md-2"><label class="form-label" for="period_number">Period number</label><input class="form-control" id="period_number" name="period_number" type="number" min="1" required></div>
                <div class="col-md-4"><label class="form-label" for="period_label">Label</label><input class="form-control" id="period_label" name="label" maxlength="80" placeholder="Period 1" required></div>
                <div class="col-md-2"><label class="form-label" for="start_time">Start</label><input class="form-control" id="start_time" name="start_time" type="time" required></div>
                <div class="col-md-2"><label class="form-label" for="end_time">End</label><input class="form-control" id="end_time" name="end_time" type="time" required></div>
                <div class="col-md-2"><label class="form-label" for="period_type">Type</label><select class="form-select" id="period_type" name="period_type"><option value="teaching">Teaching</option><option value="break">Break</option></select></div>
                <div class="col-12"><button class="btn btn-primary" type="submit">Add period to selected days</button></div>
            </form>
            <form method="post" class="mb-3">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="activate_period_schedule">
                <input type="hidden" name="academic_year_id" value="<?php echo $yearId; ?>">
                <input type="hidden" name="period_schedule_id" value="<?php echo $scheduleId; ?>">
                <button class="btn btn-success" type="submit" <?php echo count(array_filter($periods, static fn(array $period): bool => $period['period_type'] === 'teaching')) < 1 ? 'disabled' : ''; ?>>Activate schedule</button>
            </form>
            <?php endif; ?>
            <div class="timetable-setup-table-wrap">
                <table class="table table-sm align-middle"><thead><tr><th>Day</th><th>#</th><th>Period</th><th>Time</th><th>Type</th><?php if ($canManage && $selectedScheduleStatus === 'draft'): ?><th>Actions</th><?php endif; ?></tr></thead>
                    <tbody><?php foreach ($periods as $period): ?>
                        <tr>
                            <td><?php echo e(ucfirst($period['day_of_week'])); ?></td>
                            <td><?php echo (int) $period['period_number']; ?></td>
                            <td><?php echo e($period['label']); ?></td>
                            <td><?php echo e(substr($period['start_time'], 0, 5) . '–' . substr($period['end_time'], 0, 5)); ?></td>
                            <td><?php echo e(ucfirst($period['period_type'])); ?></td>
                            <?php if ($canManage && $selectedScheduleStatus === 'draft'): ?>
                            <td>
                                <details>
                                    <summary class="text-primary">Edit</summary>
                                    <form method="post" class="row g-2 align-items-end mt-2">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="update_period">
                                        <input type="hidden" name="academic_year_id" value="<?php echo $yearId; ?>">
                                        <input type="hidden" name="academic_group_id" value="<?php echo $groupId; ?>">
                                        <input type="hidden" name="period_schedule_id" value="<?php echo $scheduleId; ?>">
                                        <input type="hidden" name="period_id" value="<?php echo (int) $period['id']; ?>">
                                        <div class="col-md-3"><label class="form-label">Day</label><select class="form-select" name="day_of_week"><?php foreach (['monday','tuesday','wednesday','thursday','friday','saturday','sunday'] as $weekday): ?><option value="<?php echo $weekday; ?>" <?php echo $weekday === $period['day_of_week'] ? 'selected' : ''; ?>><?php echo ucfirst($weekday); ?></option><?php endforeach; ?></select></div>
                                        <div class="col-md-2"><label class="form-label">#</label><input class="form-control" name="period_number" type="number" min="1" value="<?php echo (int) $period['period_number']; ?>" required></div>
                                        <div class="col-md-3"><label class="form-label">Label</label><input class="form-control" name="label" maxlength="80" value="<?php echo e($period['label']); ?>" required></div>
                                        <div class="col-md-2"><label class="form-label">Start</label><input class="form-control" name="start_time" type="time" value="<?php echo e(substr($period['start_time'], 0, 5)); ?>" required></div>
                                        <div class="col-md-2"><label class="form-label">End</label><input class="form-control" name="end_time" type="time" value="<?php echo e(substr($period['end_time'], 0, 5)); ?>" required></div>
                                        <div class="col-md-3"><label class="form-label">Type</label><select class="form-select" name="period_type"><option value="teaching" <?php echo $period['period_type'] === 'teaching' ? 'selected' : ''; ?>>Teaching</option><option value="break" <?php echo $period['period_type'] === 'break' ? 'selected' : ''; ?>>Break</option></select></div>
                                        <div class="col-md-9"><button class="btn btn-sm btn-primary" type="submit">Save period</button></div>
                                    </form>
                                    <form method="post" class="mt-2">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_period">
                                        <input type="hidden" name="academic_year_id" value="<?php echo $yearId; ?>">
                                        <input type="hidden" name="academic_group_id" value="<?php echo $groupId; ?>">
                                        <input type="hidden" name="period_schedule_id" value="<?php echo $scheduleId; ?>">
                                        <input type="hidden" name="period_id" value="<?php echo (int) $period['id']; ?>">
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Delete period</button>
                                    </form>
                                </details>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?><?php if ($periods === []): ?><tr><td colspan="<?php echo $canManage && $selectedScheduleStatus === 'draft' ? '6' : '5'; ?>" class="text-muted">No periods in this schedule version.</td></tr><?php endif; ?></tbody>
                </table>
            </div>
        </section>
    </div>
</div>
</div>
<?php
render_app_page_end('admin');
