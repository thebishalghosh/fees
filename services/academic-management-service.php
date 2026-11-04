<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/audit-service.php';

const ACADEMIC_MANAGEMENT_MODELS = ['school', 'college', 'university', 'coaching'];
const ACADEMIC_ENROLMENT_STATUSES = ['enrolled', 'active', 'withdrawn', 'cancelled', 'completed', 'promoted'];

function academic_management_id(mixed $value, string $label): int
{
    $id = is_string($value) || is_int($value) ? filter_var((string) $value, FILTER_VALIDATE_INT) : false;
    if ($id === false || $id < 1) {
        throw new InvalidArgumentException('Choose a valid ' . $label . '.');
    }

    return (int) $id;
}

function academic_management_text(mixed $value, string $label, int $maxLength): string
{
    if (!is_string($value)) {
        throw new InvalidArgumentException('Enter a valid ' . $label . '.');
    }
    $value = trim($value);
    if ($value === '' || mb_strlen($value, 'UTF-8') > $maxLength
        || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
        throw new InvalidArgumentException('Enter a valid ' . $label . '.');
    }

    return $value;
}

function academic_management_configuration(mysqli $db, int $institutionId): array
{
    $statement = $db->prepare(
        'SELECT name AS institution_name, academic_model,
                academic_configuration_status, active_academic_year_id
         FROM institutions WHERE id = ? LIMIT 1'
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $configuration = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_array($configuration)) {
        throw new OutOfBoundsException('The institution configuration was not found.');
    }

    return [
        'institution_name' => (string) $configuration['institution_name'],
        'academic_model' => $configuration['academic_model'],
        'academic_configuration_status' => (string) $configuration['academic_configuration_status'],
        'active_academic_year_id' => $configuration['active_academic_year_id'] === null
            ? null
            : (int) $configuration['active_academic_year_id'],
    ];
}

function academic_management_institution_options(mysqli $db): array
{
    $result = $db->query(
        "SELECT id, name, status
         FROM institutions
         WHERE status <> 'archived'
         ORDER BY name, id"
    );
    return $result->fetch_all(MYSQLI_ASSOC);
}

function academic_management_resolve_institution_id(
    mysqli $db,
    array $user,
    mixed $requestedInstitutionId
): ?int {
    if (($user['user_type'] ?? null) !== 'super_admin') {
        return authenticated_institution_id(['admin', 'staff']);
    }

    if ($requestedInstitutionId === null || $requestedInstitutionId === '') {
        $requestedInstitutionId = admin_selected_institution_id();
        if ($requestedInstitutionId === null) {
            return null;
        }
    }
    $institutionId = filter_var($requestedInstitutionId, FILTER_VALIDATE_INT);
    if ($institutionId === false || $institutionId < 1) {
        throw new InvalidArgumentException('Choose a valid institution.');
    }
    $statement = $db->prepare(
        "SELECT id FROM institutions WHERE id = ? AND status <> 'archived' LIMIT 1"
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $exists = $statement->get_result()->fetch_column();
    $statement->close();
    if ($exists === false) {
        throw new OutOfBoundsException('The selected institution is unavailable.');
    }

    return (int) $institutionId;
}

function academic_management_schema_ready(mysqli $db): bool
{
    $statement = $db->prepare(
        "SELECT
            (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'institutions' AND column_name = 'academic_model') AS has_model_column,
            (SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN (
                   'academic_year_classes', 'sections', 'academic_year_courses',
                   'academic_terms', 'academic_groups', 'subjects', 'class_subjects',
                   'course_subjects', 'student_enrolments', 'attendance_sessions',
                   'attendance_session_records'
               )) AS academic_table_count"
    );
    $statement->execute();
    $schema = $statement->get_result()->fetch_assoc();
    $statement->close();

    return is_array($schema)
        && (int) $schema['has_model_column'] === 1
        && (int) $schema['academic_table_count'] === 11;
}

function academic_management_render_schema_required_page(): void
{
    http_response_code(503);
    render_app_page_start(
        'Academic database update required',
        'Academic management is unavailable until this installation is upgraded.',
        'admin',
        ['admin-dashboard.css']
    );
    ?>
    <section class="dashboard-panel" aria-labelledby="academic-schema-required-title">
        <h2 id="academic-schema-required-title">Database update required</h2>
        <p>The academic-management database migration has not been applied completely, so this page cannot load its academic records yet.</p>
        <p>Back up the database, apply <code>database/migrations/20261104_add_academic_management.sql</code> using the project’s migration procedure, then reload this page.</p>
        <a class="btn btn-cobalt" href="<?php echo e(route_url('/admin/dashboard')); ?>">Return to dashboard</a>
    </section>
    <?php
    render_app_page_end('admin');
}

function academic_management_configure_model(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    string $model
): void {
    if (!in_array($model, ACADEMIC_MANAGEMENT_MODELS, true)) {
        throw new InvalidArgumentException('Choose a supported academic model.');
    }

    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            'SELECT academic_model, academic_configuration_status
             FROM institutions WHERE id = ? FOR UPDATE'
        );
        $statement->bind_param('i', $institutionId);
        $statement->execute();
        $before = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($before)) {
            throw new OutOfBoundsException('The institution configuration was not found.');
        }

        if ($before['academic_model'] !== null && $before['academic_model'] !== $model) {
            $statement = $db->prepare(
                'SELECT
                    (SELECT COUNT(*) FROM student_enrolments WHERE institution_id = ?) +
                    (SELECT COUNT(*) FROM attendance_sessions WHERE institution_id = ?) +
                    (SELECT COUNT(*) FROM fee_structures WHERE institution_id = ? AND target_model <> \'legacy\') +
                    (SELECT COUNT(*) FROM academic_year_classes WHERE institution_id = ?) +
                    (SELECT COUNT(*) FROM academic_year_courses WHERE institution_id = ?) +
                    (SELECT COUNT(*) FROM sections WHERE institution_id = ?)
                 AS academic_record_count'
            );
            $statement->bind_param(
                'iiiiii',
                $institutionId,
                $institutionId,
                $institutionId,
                $institutionId,
                $institutionId,
                $institutionId
            );
            $statement->execute();
            $hasRecords = (int) $statement->get_result()->fetch_assoc()['academic_record_count'] > 0;
            $statement->close();
            if ($hasRecords) {
                throw new DomainException('The academic model cannot change after academic records exist.');
            }
        }

        $status = $before['academic_model'] === $model
            && $before['academic_configuration_status'] === 'configured'
                ? 'configured'
                : 'in_progress';
        $statement = $db->prepare(
            'UPDATE institutions
             SET academic_model = ?, academic_configuration_status = ?
             WHERE id = ?'
        );
        $statement->bind_param('ssi', $model, $status, $institutionId);
        $statement->execute();
        $statement->close();
        $actor = current_user();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            is_array($actor) ? (string) $actor['user_type'] : 'admin',
            'academic_model.configured',
            'institution',
            (string) $institutionId,
            [
                'academic_model' => $before['academic_model'],
                'academic_configuration_status' => $before['academic_configuration_status'],
            ],
            ['academic_model' => $model, 'academic_configuration_status' => $status]
        );
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function academic_management_complete_configuration(
    mysqli $db,
    int $institutionId,
    int $actorUserId
): void {
    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            'SELECT academic_model, active_academic_year_id
             FROM institutions WHERE id = ? FOR UPDATE'
        );
        $statement->bind_param('i', $institutionId);
        $statement->execute();
        $institution = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($institution) || !in_array($institution['academic_model'], ACADEMIC_MANAGEMENT_MODELS, true)) {
            throw new DomainException('Select an academic model before completing setup.');
        }
        if ($institution['active_academic_year_id'] === null) {
            throw new DomainException('Select an active academic year before completing setup.');
        }

        $yearId = (int) $institution['active_academic_year_id'];
        $requirements = match ($institution['academic_model']) {
            'school' => [
                'SELECT COUNT(*) AS count
                 FROM academic_year_classes yc
                 JOIN sections s ON s.institution_id = yc.institution_id
                   AND s.academic_year_id = yc.academic_year_id AND s.class_id = yc.class_id
                 WHERE yc.institution_id = ? AND yc.academic_year_id = ?',
            ],
            'college', 'university' => [
                'SELECT COUNT(*) AS count
                 FROM academic_year_courses yc
                 JOIN academic_terms t ON t.institution_id = yc.institution_id
                   AND t.academic_year_id = yc.academic_year_id AND t.course_id = yc.course_id
                 JOIN courses c ON c.institution_id = yc.institution_id AND c.id = yc.course_id
                 WHERE yc.institution_id = ? AND yc.academic_year_id = ?'
                    . ($institution['academic_model'] === 'university' ? ' AND c.department_id IS NOT NULL' : ''),
            ],
            'coaching' => [
                'SELECT COUNT(*) AS count
                 FROM academic_year_courses yc
                 JOIN batches b ON b.institution_id = yc.institution_id AND b.course_id = yc.course_id
                 WHERE yc.institution_id = ? AND yc.academic_year_id = ?',
            ],
        };
        $statement = $db->prepare($requirements[0]);
        $statement->bind_param('ii', $institutionId, $yearId);
        $statement->execute();
        $configured = (int) $statement->get_result()->fetch_assoc()['count'] > 0;
        $statement->close();
        if (!$configured) {
            throw new DomainException('Add the required class/section or course/term records before completing setup.');
        }

        $status = 'configured';
        $statement = $db->prepare(
            'UPDATE institutions SET academic_configuration_status = ? WHERE id = ?'
        );
        $statement->bind_param('si', $status, $institutionId);
        $statement->execute();
        $statement->close();
        $actor = current_user();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            is_array($actor) ? (string) $actor['user_type'] : 'admin',
            'academic_model.setup_completed',
            'institution',
            (string) $institutionId,
            ['academic_configuration_status' => 'in_progress'],
            ['academic_configuration_status' => $status]
        );
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function academic_management_set_active_year(
    mysqli $db,
    int $institutionId,
    int $yearId,
    int $actorUserId
): void {
    $db->begin_transaction();
    try {
        $statement = $db->prepare('SELECT id FROM institutions WHERE id = ? FOR UPDATE');
        $statement->bind_param('i', $institutionId);
        $statement->execute();
        $exists = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($exists)) {
            throw new OutOfBoundsException('The institution was not found.');
        }
        $statement = $db->prepare(
            'SELECT status FROM academic_years
             WHERE institution_id = ? AND id = ? FOR UPDATE'
        );
        $statement->bind_param('ii', $institutionId, $yearId);
        $statement->execute();
        $year = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($year)) {
            throw new OutOfBoundsException('Academic year not found.');
        }
        if ($year['status'] !== 'active') {
            throw new DomainException('Only an active academic year can be selected.');
        }
        $statement = $db->prepare(
            'SELECT active_academic_year_id FROM institutions WHERE id = ?'
        );
        $statement->bind_param('i', $institutionId);
        $statement->execute();
        $before = $statement->get_result()->fetch_assoc();
        $statement->close();
        $oldYearId = $before['active_academic_year_id'] === null ? null : (int) $before['active_academic_year_id'];
        if ($oldYearId !== $yearId) {
            $statement = $db->prepare(
                'UPDATE institutions SET active_academic_year_id = ? WHERE id = ?'
            );
            $statement->bind_param('ii', $yearId, $institutionId);
            $statement->execute();
            $statement->close();
            $actor = current_user();
            write_audit_log(
                $db,
                $institutionId,
                $actorUserId,
                is_array($actor) ? (string) $actor['user_type'] : 'admin',
                'academic_year.selected',
                'academic_year',
                (string) $yearId,
                ['active_academic_year_id' => $oldYearId],
                ['active_academic_year_id' => $yearId]
            );
        }
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function academic_management_require_active_year(mysqli $db, int $institutionId, int $yearId): array
{
    $statement = $db->prepare(
        "SELECT i.academic_model, i.active_academic_year_id, ay.status, ay.start_date, ay.end_date
         FROM institutions i
         JOIN academic_years ay ON ay.institution_id = i.id AND ay.id = ?
         WHERE i.id = ?"
    );
    $statement->bind_param('ii', $yearId, $institutionId);
    $statement->execute();
    $year = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_array($year)) {
        throw new OutOfBoundsException('The academic year does not belong to this institution.');
    }
    if ($year['status'] !== 'active' || (int) $year['active_academic_year_id'] !== $yearId) {
        throw new DomainException('Select the institution’s active academic year for this operation.');
    }

    return $year;
}

function academic_management_require_open_year(mysqli $db, int $institutionId, int $yearId): array
{
    $statement = $db->prepare(
        'SELECT status FROM academic_years
         WHERE institution_id = ? AND id = ? FOR UPDATE'
    );
    $statement->bind_param('ii', $institutionId, $yearId);
    $statement->execute();
    $year = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_array($year)) {
        throw new OutOfBoundsException('The academic year does not belong to this institution.');
    }
    if ($year['status'] === 'closed') {
        throw new DomainException('A closed academic year cannot be changed.');
    }

    return $year;
}

function academic_management_create_class_offering(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    int $yearId,
    int $classId
): int {
    $db->begin_transaction();
    try {
        $year = academic_management_require_open_year($db, $institutionId, $yearId);
        $configuration = academic_management_configuration($db, $institutionId);
        if ($configuration['academic_model'] !== 'school') {
            throw new DomainException('Year-specific class offerings are available only for schools.');
        }
        $statement = $db->prepare(
            "SELECT id FROM classes WHERE institution_id = ? AND id = ? AND status = 'active'"
        );
        $statement->bind_param('ii', $institutionId, $classId);
        $statement->execute();
        $class = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($class)) {
            throw new OutOfBoundsException('The selected class is inactive or belongs to another institution.');
        }

        $status = $year['status'] === 'active' ? 'active' : 'planned';
        $statement = $db->prepare(
            'INSERT INTO academic_year_classes (institution_id, academic_year_id, class_id, status)
             VALUES (?, ?, ?, ?)'
        );
        $statement->bind_param('iiis', $institutionId, $yearId, $classId, $status);
        $statement->execute();
        $offeringId = (int) $statement->insert_id;
        $statement->close();
        academic_management_ensure_group($db, $institutionId, $yearId, 'class', ['class_id' => $classId]);
        $actor = current_user();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            is_array($actor) ? (string) $actor['user_type'] : 'admin',
            'academic_year_class.created',
            'academic_year_class',
            (string) $offeringId,
            null,
            ['academic_year_id' => $yearId, 'class_id' => $classId]
        );
        $db->commit();
        return $offeringId;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function academic_management_create_course_offering(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    int $yearId,
    int $courseId
): int {
    $db->begin_transaction();
    try {
        $year = academic_management_require_open_year($db, $institutionId, $yearId);
        $model = $year['academic_model'];
        if (!in_array($model, ['college', 'university', 'coaching'], true)) {
            throw new DomainException('Programme offerings are not available for the configured academic model.');
        }
        $statement = $db->prepare(
            'SELECT id, department_id, status FROM courses
             WHERE institution_id = ? AND id = ? FOR UPDATE'
        );
        $statement->bind_param('ii', $institutionId, $courseId);
        $statement->execute();
        $course = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($course) || $course['status'] !== 'active') {
            throw new OutOfBoundsException('The selected course is inactive or belongs to another institution.');
        }
        if ($model === 'university' && $course['department_id'] === null) {
            throw new DomainException('University programmes must belong to a department.');
        }

        $status = $year['status'] === 'active' ? 'active' : 'planned';
        $statement = $db->prepare(
            'INSERT INTO academic_year_courses (institution_id, academic_year_id, course_id, status)
             VALUES (?, ?, ?, ?)'
        );
        $statement->bind_param('iiis', $institutionId, $yearId, $courseId, $status);
        $statement->execute();
        $offeringId = (int) $statement->insert_id;
        $statement->close();
        academic_management_ensure_group($db, $institutionId, $yearId, 'course', ['course_id' => $courseId]);
        $actor = current_user();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            is_array($actor) ? (string) $actor['user_type'] : 'admin',
            'academic_year_course.created',
            'academic_year_course',
            (string) $offeringId,
            null,
            ['academic_year_id' => $yearId, 'course_id' => $courseId]
        );
        $db->commit();
        return $offeringId;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function academic_management_create_department(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    string $name,
    ?string $code
): int {
    $name = academic_management_text($name, 'department name', 150);
    $code = $code === null || trim($code) === '' ? null : academic_management_text($code, 'department code', 50);
    $db->begin_transaction();
    try {
        $status = 'active';
        $statement = $db->prepare(
            'INSERT INTO departments (institution_id, name, code, status)
             VALUES (?, ?, ?, ?)'
        );
        $statement->bind_param('isss', $institutionId, $name, $code, $status);
        $statement->execute();
        $departmentId = (int) $statement->insert_id;
        $statement->close();
        $actor = current_user();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            is_array($actor) ? (string) $actor['user_type'] : 'admin',
            'department.created',
            'department',
            (string) $departmentId,
            null,
            ['name' => $name, 'code' => $code]
        );
        $db->commit();
        return $departmentId;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function academic_management_assign_course_department(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    int $courseId,
    int $departmentId
): void {
    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            'SELECT department_id FROM courses
             WHERE institution_id = ? AND id = ? FOR UPDATE'
        );
        $statement->bind_param('ii', $institutionId, $courseId);
        $statement->execute();
        $course = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($course)) {
            throw new OutOfBoundsException('The programme does not belong to this institution.');
        }
        $statement = $db->prepare(
            'SELECT id FROM departments WHERE institution_id = ? AND id = ? AND status = \'active\''
        );
        $statement->bind_param('ii', $institutionId, $departmentId);
        $statement->execute();
        $department = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($department)) {
            throw new OutOfBoundsException('The selected department is inactive or belongs to another institution.');
        }
        if ($course['department_id'] !== null && (int) $course['department_id'] !== $departmentId) {
            $statement = $db->prepare(
                'SELECT COUNT(*) AS count FROM student_enrolment_placements p
                 JOIN academic_groups g ON g.institution_id = p.institution_id
                   AND g.academic_year_id = p.academic_year_id AND g.id = p.academic_group_id
                 WHERE p.institution_id = ? AND g.course_id = ?'
            );
            $statement->bind_param('ii', $institutionId, $courseId);
            $statement->execute();
            $hasPlacements = (int) $statement->get_result()->fetch_assoc()['count'] > 0;
            $statement->close();
            if ($hasPlacements) {
                throw new DomainException('A programme department cannot change after student placements exist.');
            }
        }
        $statement = $db->prepare(
            'UPDATE courses SET department_id = ? WHERE institution_id = ? AND id = ?'
        );
        $statement->bind_param('iii', $departmentId, $institutionId, $courseId);
        $statement->execute();
        $statement->close();
        $actor = current_user();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            is_array($actor) ? (string) $actor['user_type'] : 'admin',
            'course.department_assigned',
            'course',
            (string) $courseId,
            ['department_id' => $course['department_id']],
            ['department_id' => $departmentId]
        );
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function academic_management_create_term(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    int $yearId,
    int $courseId,
    string $name,
    string $type,
    int $sequenceNumber,
    ?string $startDate = null,
    ?string $endDate = null
): int {
    $name = academic_management_text($name, 'term name', 120);
    if (!in_array($type, ['semester', 'term', 'trimester', 'year'], true)
        || $sequenceNumber < 1 || $sequenceNumber > 65535) {
        throw new InvalidArgumentException('Choose a valid term type and sequence number.');
    }
    foreach (['startDate' => $startDate, 'endDate' => $endDate] as $label => $date) {
        if ($date !== null && $date !== '') {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                throw new InvalidArgumentException('Enter a valid term date range.');
            }
        } elseif ($date === '') {
            if ($label === 'startDate') {
                $startDate = null;
            } else {
                $endDate = null;
            }
        }
    }
    if ($startDate !== null && $endDate !== null && $endDate < $startDate) {
        throw new InvalidArgumentException('The term end date must not precede its start date.');
    }

    $db->begin_transaction();
    try {
        $year = academic_management_require_open_year($db, $institutionId, $yearId);
        if (!in_array($year['academic_model'], ['college', 'university'], true)) {
            throw new DomainException('Academic terms are available only for colleges and universities.');
        }
        $statement = $db->prepare(
            'SELECT id FROM academic_year_courses
             WHERE institution_id = ? AND academic_year_id = ? AND course_id = ? FOR UPDATE'
        );
        $statement->bind_param('iii', $institutionId, $yearId, $courseId);
        $statement->execute();
        $offering = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($offering)) {
            throw new OutOfBoundsException('Offer the programme in this academic year before adding a term.');
        }
        $status = 'active';
        $statement = $db->prepare(
            'INSERT INTO academic_terms
                (institution_id, academic_year_id, course_id, name, term_type, sequence_number,
                 start_date, end_date, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->bind_param(
            'iiississs',
            $institutionId,
            $yearId,
            $courseId,
            $name,
            $type,
            $sequenceNumber,
            $startDate,
            $endDate,
            $status
        );
        $statement->execute();
        $termId = (int) $statement->insert_id;
        $statement->close();
        academic_management_ensure_group(
            $db,
            $institutionId,
            $yearId,
            'term',
            ['course_id' => $courseId, 'academic_term_id' => $termId]
        );
        $actor = current_user();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            is_array($actor) ? (string) $actor['user_type'] : 'admin',
            'academic_term.created',
            'academic_term',
            (string) $termId,
            null,
            ['academic_year_id' => $yearId, 'course_id' => $courseId, 'name' => $name, 'term_type' => $type]
        );
        $db->commit();
        return $termId;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function academic_management_map_course_subject(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    int $yearId,
    int $courseId,
    int $subjectId,
    ?int $termId
): void {
    $db->begin_transaction();
    try {
        $year = academic_management_require_open_year($db, $institutionId, $yearId);
        if (!in_array($year['academic_model'], ['college', 'university', 'coaching'], true)) {
            throw new DomainException('Programme subjects are not available for this academic model.');
        }
        $statement = $db->prepare(
            'INSERT INTO course_subjects
                (institution_id, academic_year_id, course_id, academic_term_id, subject_id)
             VALUES (?, ?, ?, ?, ?)'
        );
        $statement->bind_param('iiiii', $institutionId, $yearId, $courseId, $termId, $subjectId);
        $statement->execute();
        $mappingId = (int) $statement->insert_id;
        $statement->close();
        $actor = current_user();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            is_array($actor) ? (string) $actor['user_type'] : 'admin',
            'course_subject.mapped',
            'course_subject',
            (string) $mappingId,
            null,
            [
                'academic_year_id' => $yearId,
                'course_id' => $courseId,
                'academic_term_id' => $termId,
                'subject_id' => $subjectId,
            ]
        );
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function academic_management_list_years(mysqli $db, int $institutionId, bool $includeClosed = true): array
{
    $statusFilter = $includeClosed ? '' : "AND status <> 'closed'";
    $statement = $db->prepare(
        "SELECT id, name, start_date, end_date, status
         FROM academic_years
         WHERE institution_id = ? $statusFilter
         ORDER BY start_date DESC, id DESC"
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();

    return $rows;
}

function academic_management_school_setup_data(mysqli $db, int $institutionId): array
{
    $statement = $db->prepare(
        'SELECT id, name, code, status, display_order
         FROM classes WHERE institution_id = ? ORDER BY display_order, name, id'
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $classes = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();

    $statement = $db->prepare(
        "SELECT yc.id AS offering_id, yc.academic_year_id, yc.class_id, yc.status AS offering_status,
                ay.name AS year_name, c.name AS class_name, c.code AS class_code,
                c.description AS class_description, c.status AS class_status,
                s.id AS section_id, s.name AS section_name, s.code AS section_code,
                s.capacity AS section_capacity, s.status AS section_status
         FROM academic_year_classes yc
         JOIN academic_years ay
           ON ay.institution_id = yc.institution_id AND ay.id = yc.academic_year_id
         JOIN classes c ON c.institution_id = yc.institution_id AND c.id = yc.class_id
         LEFT JOIN sections s
           ON s.institution_id = yc.institution_id AND s.academic_year_id = yc.academic_year_id
          AND s.class_id = yc.class_id
         WHERE yc.institution_id = ?
         ORDER BY ay.start_date DESC, c.display_order, c.name, s.name"
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $offeringRows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();

    return [
        'years' => academic_management_list_years($db, $institutionId, false),
        'classes' => $classes,
        'offerings' => $offeringRows,
    ];
}

function academic_management_subject_setup_data(mysqli $db, int $institutionId): array
{
    $years = academic_management_list_years($db, $institutionId, false);
    $statement = $db->prepare(
        "SELECT yc.academic_year_id, yc.class_id, ay.name AS year_name, c.name AS class_name,
                s.id AS section_id, s.name AS section_name
         FROM academic_year_classes yc
         JOIN academic_years ay
           ON ay.institution_id = yc.institution_id AND ay.id = yc.academic_year_id
         JOIN classes c ON c.institution_id = yc.institution_id AND c.id = yc.class_id
         LEFT JOIN sections s
           ON s.institution_id = yc.institution_id AND s.academic_year_id = yc.academic_year_id
          AND s.class_id = yc.class_id AND s.status = 'active'
         WHERE yc.institution_id = ?
         ORDER BY ay.start_date DESC, c.display_order, c.name, s.name"
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $classRows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();

    $statement = $db->prepare(
        'SELECT s.id, s.name, s.code, s.subject_type, s.status,
                (SELECT COUNT(*) FROM class_subjects cs
                 WHERE cs.institution_id = s.institution_id AND cs.subject_id = s.id) AS class_mapping_count
         FROM subjects s WHERE s.institution_id = ? ORDER BY s.name'
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $subjects = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();

    $statement = $db->prepare(
        "SELECT cs.academic_year_id, cs.class_id, cs.subject_id,
                ay.name AS year_name, c.name AS class_name, s.name AS subject_name
         FROM class_subjects cs
         JOIN academic_years ay ON ay.institution_id = cs.institution_id AND ay.id = cs.academic_year_id
         JOIN classes c ON c.institution_id = cs.institution_id AND c.id = cs.class_id
         JOIN subjects s ON s.institution_id = cs.institution_id AND s.id = cs.subject_id
         WHERE cs.institution_id = ? AND cs.status = 'active'
         ORDER BY ay.start_date DESC, c.name, s.name"
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $classMappings = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();

    $statement = $db->prepare(
        "SELECT yc.academic_year_id, yc.course_id, ay.name AS year_name, c.name AS course_name,
                t.id AS term_id, t.name AS term_name
         FROM academic_year_courses yc
         JOIN academic_years ay ON ay.institution_id = yc.institution_id AND ay.id = yc.academic_year_id
         JOIN courses c ON c.institution_id = yc.institution_id AND c.id = yc.course_id
         LEFT JOIN academic_terms t
           ON t.institution_id = yc.institution_id AND t.academic_year_id = yc.academic_year_id
          AND t.course_id = yc.course_id AND t.status <> 'closed'
         WHERE yc.institution_id = ?
         ORDER BY ay.start_date DESC, c.name, t.sequence_number"
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $courseRows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();

    return [
        'years' => $years,
        'class_offerings' => $classRows,
        'subjects' => $subjects,
        'class_mappings' => $classMappings,
        'course_offerings' => $courseRows,
    ];
}

function academic_management_programme_setup_data(mysqli $db, int $institutionId): array
{
    $statement = $db->prepare(
        'SELECT id, name, code, department_id, status FROM courses
         WHERE institution_id = ? ORDER BY name'
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $courses = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();

    $statement = $db->prepare(
        'SELECT id, name, status FROM departments
         WHERE institution_id = ? ORDER BY name'
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $departments = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();

    $statement = $db->prepare(
        'SELECT yc.id AS offering_id, yc.academic_year_id, yc.course_id, yc.status AS offering_status,
                ay.name AS year_name, c.name AS course_name, t.id AS term_id, t.name AS term_name,
                t.term_type, t.sequence_number
         FROM academic_year_courses yc
         JOIN academic_years ay ON ay.institution_id = yc.institution_id AND ay.id = yc.academic_year_id
         JOIN courses c ON c.institution_id = yc.institution_id AND c.id = yc.course_id
         LEFT JOIN academic_terms t
           ON t.institution_id = yc.institution_id AND t.academic_year_id = yc.academic_year_id
          AND t.course_id = yc.course_id
         WHERE yc.institution_id = ?
         ORDER BY ay.start_date DESC, c.name, t.sequence_number'
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $offerings = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();

    $statement = $db->prepare(
        'SELECT b.id, b.name, b.course_id, c.name AS course_name, b.status
         FROM batches b
         JOIN courses c ON c.institution_id = b.institution_id AND c.id = b.course_id
         WHERE b.institution_id = ? ORDER BY c.name, b.name'
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $batches = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();

    return [
        'years' => academic_management_list_years($db, $institutionId, false),
        'courses' => $courses,
        'departments' => $departments,
        'offerings' => $offerings,
        'batches' => $batches,
    ];
}

function academic_management_list_placement_options(mysqli $db, int $institutionId): array
{
    $configuration = academic_management_configuration($db, $institutionId);
    $yearId = $configuration['active_academic_year_id'];
    if ($yearId === null || $configuration['academic_model'] === null) {
        return [];
    }
    $model = $configuration['academic_model'];
    $query = match ($model) {
        'school' => "SELECT g.id, CONCAT(ay.name, ' / ', c.name, ' / ', s.name) AS label
            FROM academic_groups g
            JOIN academic_years ay ON ay.institution_id = g.institution_id AND ay.id = g.academic_year_id
            JOIN sections s ON s.institution_id = g.institution_id AND s.academic_year_id = g.academic_year_id
              AND s.class_id = g.class_id AND s.id = g.section_id
            JOIN classes c ON c.institution_id = g.institution_id AND c.id = g.class_id
            WHERE g.institution_id = ? AND g.academic_year_id = ? AND g.group_type = 'section'
              AND c.status = 'active' AND s.status = 'active'
            ORDER BY c.name, s.name",
        'college', 'university' => "SELECT g.id, CONCAT(ay.name, ' / ', c.name, ' / ', t.name) AS label
            FROM academic_groups g
            JOIN academic_years ay ON ay.institution_id = g.institution_id AND ay.id = g.academic_year_id
            JOIN courses c ON c.institution_id = g.institution_id AND c.id = g.course_id
            JOIN academic_terms t ON t.institution_id = g.institution_id
              AND t.academic_year_id = g.academic_year_id AND t.course_id = g.course_id
              AND t.id = g.academic_term_id
            WHERE g.institution_id = ? AND g.academic_year_id = ? AND g.group_type = 'term'
            ORDER BY c.name, t.sequence_number",
        'coaching' => "SELECT g.id, CONCAT(ay.name, ' / ', c.name, ' / ', b.name) AS label
            FROM academic_groups g
            JOIN academic_years ay ON ay.institution_id = g.institution_id AND ay.id = g.academic_year_id
            JOIN courses c ON c.institution_id = g.institution_id AND c.id = g.course_id
            JOIN batches b ON b.institution_id = g.institution_id AND b.course_id = g.course_id
              AND b.id = g.batch_id
            WHERE g.institution_id = ? AND g.academic_year_id = ? AND g.group_type = 'batch'
            ORDER BY c.name, b.name",
        default => null,
    };
    if ($query === null) {
        return [];
    }
    $statement = $db->prepare($query);
    $statement->bind_param('ii', $institutionId, $yearId);
    $statement->execute();
    $options = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();

    return $options;
}

function academic_management_list_enrolments(mysqli $db, int $institutionId): array
{
    $statement = $db->prepare(
        'SELECT e.id AS enrolment_id, e.student_id, e.academic_year_id, e.enrolment_status,
                e.promoted_from_enrolment_id, s.student_code, s.full_name,
                ay.name AS academic_year_name, g.group_type,
                c.name AS class_name, section.name AS section_name,
                course.name AS course_name, term.name AS term_name, b.name AS batch_name,
                p.id AS placement_id, p.effective_at, p.reason AS placement_reason
         FROM student_enrolments e
         JOIN students s ON s.institution_id = e.institution_id AND s.id = e.student_id
         JOIN academic_years ay ON ay.institution_id = e.institution_id AND ay.id = e.academic_year_id
         LEFT JOIN student_enrolment_placements p
           ON p.institution_id = e.institution_id AND p.student_enrolment_id = e.id
          AND p.id = (
              SELECT p2.id FROM student_enrolment_placements p2
              WHERE p2.institution_id = e.institution_id AND p2.student_enrolment_id = e.id
              ORDER BY p2.effective_at DESC, p2.id DESC LIMIT 1
          )
         LEFT JOIN academic_groups g
           ON g.institution_id = p.institution_id
          AND g.academic_year_id = p.academic_year_id AND g.id = p.academic_group_id
         LEFT JOIN classes c ON c.institution_id = g.institution_id AND c.id = g.class_id
         LEFT JOIN sections section
           ON section.institution_id = g.institution_id AND section.academic_year_id = g.academic_year_id
          AND section.class_id = g.class_id AND section.id = g.section_id
         LEFT JOIN courses course ON course.institution_id = g.institution_id AND course.id = g.course_id
         LEFT JOIN academic_terms term
           ON term.institution_id = g.institution_id AND term.academic_year_id = g.academic_year_id
          AND term.course_id = g.course_id AND term.id = g.academic_term_id
         LEFT JOIN batches b
           ON b.institution_id = g.institution_id AND b.course_id = g.course_id AND b.id = g.batch_id
         WHERE e.institution_id = ?
         ORDER BY ay.start_date DESC, s.full_name, e.id DESC
         LIMIT 500'
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();

    return $rows;
}

function academic_management_student_options(mysqli $db, int $institutionId): array
{
    $statement = $db->prepare(
        "SELECT id, student_code, full_name
         FROM students
         WHERE institution_id = ? AND status IN ('admitted','active')
         ORDER BY full_name, student_code"
    );
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();

    return $rows;
}
function academic_management_create_batch_group(
    mysqli $db,
    int $institutionId,
    int $yearId,
    int $batchId
): int {
    $db->begin_transaction();
    try {
        $year = academic_management_require_open_year($db, $institutionId, $yearId);
        if ($year['academic_model'] !== 'coaching') {
            throw new DomainException('Batch placement groups are available only for coaching institutions.');
        }
        $statement = $db->prepare(
            "SELECT b.course_id FROM batches b
             JOIN academic_year_courses yc
               ON yc.institution_id = b.institution_id AND yc.academic_year_id = ?
              AND yc.course_id = b.course_id
             WHERE b.institution_id = ? AND b.id = ? AND b.status IN ('active','full')
             FOR UPDATE"
        );
        $statement->bind_param('iii', $yearId, $institutionId, $batchId);
        $statement->execute();
        $batch = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($batch)) {
            throw new OutOfBoundsException('Offer the batch course in this year and select a valid batch.');
        }
        $groupId = academic_management_ensure_group(
            $db,
            $institutionId,
            $yearId,
            'batch',
            ['course_id' => (int) $batch['course_id'], 'batch_id' => $batchId]
        );
        $db->commit();
        return $groupId;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function academic_management_ensure_group(
    mysqli $db,
    int $institutionId,
    int $yearId,
    string $type,
    array $target
): int {
    $columns = match ($type) {
        'class' => ['class_id'],
        'section' => ['class_id', 'section_id'],
        'course' => ['course_id'],
        'term' => ['course_id', 'academic_term_id'],
        'batch' => ['course_id', 'batch_id'],
        default => throw new InvalidArgumentException('Choose a supported academic group.'),
    };
    $values = [];
    foreach ($columns as $column) {
        $id = $target[$column] ?? null;
        if (!is_int($id) || $id < 1) {
            throw new InvalidArgumentException('The academic group target is invalid.');
        }
        $values[$column] = $id;
    }
    $allColumns = ['class_id', 'section_id', 'course_id', 'academic_term_id', 'batch_id'];
    $insertColumns = ['institution_id', 'academic_year_id', 'group_type', ...$allColumns];
    $insertValues = [$institutionId, $yearId, $type];
    foreach ($allColumns as $column) {
        $insertValues[] = $values[$column] ?? null;
    }
    $placeholders = implode(', ', array_fill(0, count($insertValues), '?'));
    $types = 'iis' . str_repeat('i', count($allColumns));
    $statement = $db->prepare(
        'INSERT INTO academic_groups (' . implode(', ', $insertColumns) . ')
         VALUES (' . $placeholders . ')
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
    );
    $parameters = [$types];
    foreach ($insertValues as &$value) {
        $parameters[] = &$value;
    }
    unset($value);
    if (!call_user_func_array([$statement, 'bind_param'], $parameters)) {
        throw new RuntimeException('The academic group query could not bind its parameters.');
    }
    $statement->execute();
    $groupId = (int) $statement->insert_id;
    $statement->close();
    if ($groupId < 1) {
        throw new RuntimeException('The academic group could not be created.');
    }

    return $groupId;
}

function academic_management_create_section(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    int $yearId,
    int $classId,
    string $name,
    ?string $code,
    ?int $capacity
): int {
    $name = academic_management_text($name, 'section name', 120);
    $code = $code === null || trim($code) === '' ? null : academic_management_text($code, 'section code', 50);
    if ($capacity !== null && $capacity < 1) {
        throw new InvalidArgumentException('Section capacity must be a positive number.');
    }

    $db->begin_transaction();
    try {
        academic_management_require_open_year($db, $institutionId, $yearId);
        if (academic_management_configuration($db, $institutionId)['academic_model'] !== 'school') {
            throw new DomainException('Sections are available only for schools.');
        }
        $statement = $db->prepare(
            "SELECT yc.id FROM academic_year_classes yc
             JOIN classes c ON c.institution_id = yc.institution_id AND c.id = yc.class_id
             WHERE yc.institution_id = ? AND yc.academic_year_id = ? AND yc.class_id = ?
               AND c.status = 'active'
             FOR UPDATE"
        );
        $statement->bind_param('iii', $institutionId, $yearId, $classId);
        $statement->execute();
        $offering = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($offering)) {
            throw new OutOfBoundsException('Offer the class in this academic year before adding sections.');
        }
        $status = 'active';
        $statement = $db->prepare(
            'INSERT INTO sections
                (institution_id, academic_year_id, class_id, name, code, capacity, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->bind_param('iiissis', $institutionId, $yearId, $classId, $name, $code, $capacity, $status);
        $statement->execute();
        $sectionId = (int) $statement->insert_id;
        $statement->close();
        academic_management_ensure_group(
            $db,
            $institutionId,
            $yearId,
            'section',
            ['class_id' => $classId, 'section_id' => $sectionId]
        );
        $actor = current_user();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            is_array($actor) ? (string) $actor['user_type'] : 'admin',
            'academic_section.created',
            'section',
            (string) $sectionId,
            null,
            ['academic_year_id' => $yearId, 'class_id' => $classId, 'name' => $name, 'code' => $code, 'capacity' => $capacity]
        );
        $db->commit();
        return $sectionId;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function academic_management_update_section(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    int $yearId,
    int $classId,
    int $sectionId,
    string $name,
    ?string $code,
    ?int $capacity,
    string $status
): void {
    $name = academic_management_text($name, 'section name', 120);
    $code = $code === null || trim($code) === ''
        ? null
        : academic_management_text($code, 'section code', 50);
    if ($capacity !== null && $capacity < 1) {
        throw new InvalidArgumentException('Section capacity must be a positive number.');
    }
    if (!in_array($status, ['active', 'inactive'], true)) {
        throw new InvalidArgumentException('Choose an active or inactive section status.');
    }

    $db->begin_transaction();
    try {
        academic_management_require_open_year($db, $institutionId, $yearId);
        if (academic_management_configuration($db, $institutionId)['academic_model'] !== 'school') {
            throw new DomainException('Sections are available only for schools.');
        }
        $statement = $db->prepare(
            'SELECT name, code, capacity, status FROM sections
             WHERE institution_id = ? AND academic_year_id = ?
               AND class_id = ? AND id = ? FOR UPDATE'
        );
        $statement->bind_param('iiii', $institutionId, $yearId, $classId, $sectionId);
        $statement->execute();
        $before = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($before)) {
            throw new OutOfBoundsException('The section is unavailable for this institution, year, and class.');
        }

        $statement = $db->prepare(
            'UPDATE sections SET name = ?, code = ?, capacity = ?, status = ?
             WHERE institution_id = ? AND academic_year_id = ? AND class_id = ? AND id = ?'
        );
        $statement->bind_param(
            'ssisiiii',
            $name,
            $code,
            $capacity,
            $status,
            $institutionId,
            $yearId,
            $classId,
            $sectionId
        );
        $statement->execute();
        $statement->close();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            (string) (current_user()['user_type'] ?? 'admin'),
            'academic_section.updated',
            'section',
            (string) $sectionId,
            $before,
            ['name' => $name, 'code' => $code, 'capacity' => $capacity, 'status' => $status]
        );
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        if ($exception instanceof mysqli_sql_exception && (int) $exception->getCode() === 1062) {
            throw new InvalidArgumentException(
                'A section with this name or code already exists in this class and year.',
                0,
                $exception
            );
        }
        throw $exception;
    }
}

function academic_management_create_subject(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    string $name,
    ?string $code,
    string $type
): int {
    $name = academic_management_text($name, 'subject name', 150);
    $code = $code === null || trim($code) === '' ? null : academic_management_text($code, 'subject code', 50);
    if (!in_array($type, ['core', 'elective', 'optional'], true)) {
        throw new InvalidArgumentException('Choose a supported subject type.');
    }

    $db->begin_transaction();
    try {
        $status = 'active';
        $statement = $db->prepare(
            'INSERT INTO subjects (institution_id, name, code, subject_type, status)
             VALUES (?, ?, ?, ?, ?)'
        );
        $statement->bind_param('issss', $institutionId, $name, $code, $type, $status);
        $statement->execute();
        $subjectId = (int) $statement->insert_id;
        $statement->close();
        $actor = current_user();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            is_array($actor) ? (string) $actor['user_type'] : 'admin',
            'subject.created',
            'subject',
            (string) $subjectId,
            null,
            ['name' => $name, 'code' => $code, 'subject_type' => $type]
        );
        $db->commit();
        return $subjectId;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function academic_management_map_subject(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    int $yearId,
    int $classId,
    int $subjectId,
    ?int $sectionId = null,
    string $sectionMode = 'include'
): void {
    $db->begin_transaction();
    try {
        academic_management_require_open_year($db, $institutionId, $yearId);
        if (academic_management_configuration($db, $institutionId)['academic_model'] !== 'school') {
            throw new DomainException('Class subjects are available only for schools.');
        }
        if ($sectionId === null) {
            $statement = $db->prepare(
                'INSERT INTO class_subjects (institution_id, academic_year_id, class_id, subject_id)
                 VALUES (?, ?, ?, ?)'
            );
            $statement->bind_param('iiii', $institutionId, $yearId, $classId, $subjectId);
            $statement->execute();
            $mappingId = (int) $statement->insert_id;
            $statement->close();
            $table = 'class_subject';
        } else {
            if (!in_array($sectionMode, ['include', 'exclude'], true)) {
                throw new InvalidArgumentException('Choose whether the section includes or excludes this subject.');
            }
            $statement = $db->prepare(
                'INSERT INTO section_subjects
                    (institution_id, academic_year_id, class_id, section_id, subject_id, assignment_mode)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $statement->bind_param(
                'iiiiis',
                $institutionId,
                $yearId,
                $classId,
                $sectionId,
                $subjectId,
                $sectionMode
            );
            $statement->execute();
            $mappingId = (int) $statement->insert_id;
            $statement->close();
            $table = 'section_subject';
        }
        $actor = current_user();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            is_array($actor) ? (string) $actor['user_type'] : 'admin',
            $table . '.mapped',
            $table,
            (string) $mappingId,
            null,
            [
                'academic_year_id' => $yearId,
                'class_id' => $classId,
                'section_id' => $sectionId,
                'subject_id' => $subjectId,
                'assignment_mode' => $sectionId === null ? null : $sectionMode,
            ]
        );
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function academic_management_create_enrolment(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    int $studentId,
    int $yearId,
    int $groupId,
    string $reason,
    ?string $admissionNumber = null
): int {
    $reason = academic_management_text($reason, 'enrolment reason', 500);
    if ($admissionNumber !== null) {
        $admissionNumber = trim($admissionNumber) === ''
            ? null
            : academic_management_text($admissionNumber, 'admission number', 50);
    }

    $db->begin_transaction();
    try {
        $year = academic_management_require_active_year($db, $institutionId, $yearId);
        $statement = $db->prepare(
            'SELECT id, status FROM students WHERE institution_id = ? AND id = ? FOR UPDATE'
        );
        $statement->bind_param('ii', $institutionId, $studentId);
        $statement->execute();
        $student = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($student) || !in_array($student['status'], ['admitted', 'active'], true)) {
            throw new OutOfBoundsException('Only an admitted student in this institution can be enrolled.');
        }

        $group = academic_management_lock_group($db, $institutionId, $yearId, $groupId);
        academic_management_validate_group_for_model($group, $year['academic_model']);
        $statement = $db->prepare(
            'SELECT id, enrolment_status FROM student_enrolments
             WHERE institution_id = ? AND academic_year_id = ? AND student_id = ? FOR UPDATE'
        );
        $statement->bind_param('iii', $institutionId, $yearId, $studentId);
        $statement->execute();
        $existing = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (is_array($existing)) {
            if (!in_array($existing['enrolment_status'], ['withdrawn', 'cancelled'], true)) {
                throw new DomainException('The student already has an enrolment in this academic year.');
            }
            $enrolmentId = (int) $existing['id'];
            $newStatus = 'active';
            $statement = $db->prepare(
                'UPDATE student_enrolments SET enrolment_status = ? WHERE institution_id = ? AND id = ?'
            );
            $statement->bind_param('sii', $newStatus, $institutionId, $enrolmentId);
            $statement->execute();
            $statement->close();
            academic_management_append_status_event(
                $db,
                $institutionId,
                $actorUserId,
                $enrolmentId,
                (string) $existing['enrolment_status'],
                $newStatus,
                $reason
            );
            academic_management_append_placement(
                $db,
                $institutionId,
                $actorUserId,
                $studentId,
                $enrolmentId,
                $yearId,
                $groupId,
                $reason
            );
        } else {
            $newStatus = 'active';
            $statement = $db->prepare(
                'INSERT INTO student_enrolments
                    (institution_id, student_id, academic_year_id, admission_number, enrolment_status)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $statement->bind_param('iiiss', $institutionId, $studentId, $yearId, $admissionNumber, $newStatus);
            $statement->execute();
            $enrolmentId = (int) $statement->insert_id;
            $statement->close();
            academic_management_append_status_event(
                $db,
                $institutionId,
                $actorUserId,
                $enrolmentId,
                null,
                $newStatus,
                $reason
            );
            academic_management_append_placement(
                $db,
                $institutionId,
                $actorUserId,
                $studentId,
                $enrolmentId,
                $yearId,
                $groupId,
                $reason
            );
        }
        academic_management_sync_legacy_placement($db, $institutionId, $studentId, $yearId, $group);
        $actor = current_user();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            is_array($actor) ? (string) $actor['user_type'] : 'admin',
            is_array($existing) ? 'student_enrolment.readmitted' : 'student_enrolment.created',
            'student_enrolment',
            (string) $enrolmentId,
            null,
            ['student_id' => $studentId, 'academic_year_id' => $yearId, 'academic_group_id' => $groupId]
        );
        $db->commit();
        return $enrolmentId;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function academic_management_lock_group(mysqli $db, int $institutionId, int $yearId, int $groupId): array
{
    $statement = $db->prepare(
        'SELECT id, group_type, class_id, section_id, course_id, academic_term_id, batch_id
         FROM academic_groups
         WHERE institution_id = ? AND academic_year_id = ? AND id = ? FOR UPDATE'
    );
    $statement->bind_param('iii', $institutionId, $yearId, $groupId);
    $statement->execute();
    $group = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_array($group)) {
        throw new OutOfBoundsException('The selected academic placement does not belong to this institution and year.');
    }

    return $group;
}

function academic_management_validate_group_for_model(array $group, ?string $model): void
{
    $valid = match ($model) {
        'school' => $group['group_type'] === 'section' && $group['section_id'] !== null,
        'college', 'university' => $group['group_type'] === 'term' && $group['academic_term_id'] !== null,
        'coaching' => $group['group_type'] === 'batch' && $group['batch_id'] !== null,
        default => false,
    };
    if (!$valid) {
        throw new DomainException('The selected placement does not match the institution’s configured academic model.');
    }
}

function academic_management_append_status_event(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    int $enrolmentId,
    ?string $previousStatus,
    string $newStatus,
    string $reason
): void {
    $effectiveAt = (new DateTimeImmutable())->format('Y-m-d H:i:s.u');
    $statement = $db->prepare(
        'INSERT INTO student_enrolment_status_events
            (institution_id, student_enrolment_id, previous_status, new_status, effective_at, reason, actor_user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $statement->bind_param(
        'iissssi',
        $institutionId,
        $enrolmentId,
        $previousStatus,
        $newStatus,
        $effectiveAt,
        $reason,
        $actorUserId
    );
    $statement->execute();
    $statement->close();
}

function academic_management_append_placement(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    int $studentId,
    int $enrolmentId,
    int $yearId,
    int $groupId,
    string $reason
): int {
    $statement = $db->prepare(
        'SELECT id, effective_at FROM student_enrolment_placements
         WHERE institution_id = ? AND student_enrolment_id = ?
         ORDER BY effective_at DESC, id DESC LIMIT 1 FOR UPDATE'
    );
    $statement->bind_param('ii', $institutionId, $enrolmentId);
    $statement->execute();
    $previous = $statement->get_result()->fetch_assoc();
    $statement->close();
    $previousId = is_array($previous) ? (int) $previous['id'] : null;
    $effectiveAt = (new DateTimeImmutable())->format('Y-m-d H:i:s.u');
    if (is_array($previous) && $effectiveAt <= (string) $previous['effective_at']) {
        $effectiveAt = (new DateTimeImmutable((string) $previous['effective_at']))
            ->modify('+1 microsecond')
            ->format('Y-m-d H:i:s.u');
    }
    $statement = $db->prepare(
        'INSERT INTO student_enrolment_placements
            (institution_id, academic_year_id, student_id, student_enrolment_id,
             academic_group_id, previous_placement_id, effective_at, reason, actor_user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $statement->bind_param(
        'iiiiiissi',
        $institutionId,
        $yearId,
        $studentId,
        $enrolmentId,
        $groupId,
        $previousId,
        $effectiveAt,
        $reason,
        $actorUserId
    );
    $statement->execute();
    $placementId = (int) $statement->insert_id;
    $statement->close();
    return $placementId;
}

function academic_management_sync_legacy_placement(
    mysqli $db,
    int $institutionId,
    int $studentId,
    int $yearId,
    array $group
): void {
    $courseId = $group['course_id'] === null ? null : (int) $group['course_id'];
    $classId = $group['class_id'] === null ? null : (int) $group['class_id'];
    $batchId = $group['batch_id'] === null ? null : (int) $group['batch_id'];
    if ($group['group_type'] === 'section') {
        $courseId = null;
    }
    $statement = $db->prepare(
        'UPDATE students SET academic_year_id = ?, course_id = ?, class_id = ?, batch_id = ?
         WHERE institution_id = ? AND id = ?'
    );
    $statement->bind_param('iiiiii', $yearId, $courseId, $classId, $batchId, $institutionId, $studentId);
    $statement->execute();
    $statement->close();
}

function academic_management_change_section(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    int $enrolmentId,
    int $newGroupId,
    string $reason
): int {
    $reason = academic_management_text($reason, 'section-change reason', 500);
    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            "SELECT e.student_id, e.academic_year_id, e.enrolment_status, i.academic_model,
                    i.active_academic_year_id, ay.status AS year_status,
                    p.id AS previous_placement_id, p.academic_group_id AS previous_group_id,
                    old_group.class_id AS previous_class_id
             FROM student_enrolments e
             JOIN institutions i ON i.id = e.institution_id
             JOIN academic_years ay ON ay.institution_id = e.institution_id AND ay.id = e.academic_year_id
             JOIN student_enrolment_placements p
               ON p.institution_id = e.institution_id AND p.student_enrolment_id = e.id
             JOIN academic_groups old_group
               ON old_group.institution_id = p.institution_id
              AND old_group.academic_year_id = p.academic_year_id AND old_group.id = p.academic_group_id
             WHERE e.institution_id = ? AND e.id = ?
               AND p.id = (
                   SELECT p2.id FROM student_enrolment_placements p2
                   WHERE p2.institution_id = e.institution_id AND p2.student_enrolment_id = e.id
                   ORDER BY p2.effective_at DESC, p2.id DESC LIMIT 1
               )
             FOR UPDATE"
        );
        $statement->bind_param('ii', $institutionId, $enrolmentId);
        $statement->execute();
        $current = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($current)) {
            throw new OutOfBoundsException('The active student enrolment was not found.');
        }
        if ($current['academic_model'] !== 'school' || $current['enrolment_status'] !== 'active'
            || $current['year_status'] !== 'active'
            || (int) $current['active_academic_year_id'] !== (int) $current['academic_year_id']) {
            throw new DomainException('A section change requires an active school enrolment in the selected year.');
        }
        $next = academic_management_lock_group(
            $db,
            $institutionId,
            (int) $current['academic_year_id'],
            $newGroupId
        );
        if ($next['group_type'] !== 'section'
            || (int) $next['class_id'] !== (int) $current['previous_class_id']) {
            throw new DomainException('A section change must remain within the same class and academic year.');
        }
        $placementId = academic_management_append_placement(
            $db,
            $institutionId,
            $actorUserId,
            (int) $current['student_id'],
            $enrolmentId,
            (int) $current['academic_year_id'],
            $newGroupId,
            $reason
        );
        academic_management_sync_legacy_placement(
            $db,
            $institutionId,
            (int) $current['student_id'],
            (int) $current['academic_year_id'],
            $next
        );
        $actor = current_user();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            is_array($actor) ? (string) $actor['user_type'] : 'admin',
            'student_enrolment.section_changed',
            'student_enrolment',
            (string) $enrolmentId,
            ['academic_group_id' => (int) $current['previous_group_id']],
            ['academic_group_id' => $newGroupId, 'placement_id' => $placementId, 'reason' => $reason]
        );
        $db->commit();
        return $placementId;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function academic_management_change_enrolment_status(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    int $enrolmentId,
    string $newStatus,
    string $reason
): void {
    $reason = academic_management_text($reason, 'status-change reason', 500);
    if (!in_array($newStatus, ['withdrawn', 'active'], true)) {
        throw new InvalidArgumentException('Choose withdrawal or re-admission.');
    }
    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            'SELECT enrolment_status FROM student_enrolments
             WHERE institution_id = ? AND id = ? FOR UPDATE'
        );
        $statement->bind_param('ii', $institutionId, $enrolmentId);
        $statement->execute();
        $enrolment = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($enrolment)) {
            throw new OutOfBoundsException('The student enrolment was not found.');
        }
        $oldStatus = (string) $enrolment['enrolment_status'];
        $validTransition = ($newStatus === 'withdrawn' && $oldStatus === 'active')
            || ($newStatus === 'active' && in_array($oldStatus, ['withdrawn', 'cancelled'], true));
        if (!$validTransition) {
            throw new DomainException('This enrolment status transition is not allowed.');
        }
        $statement = $db->prepare(
            'UPDATE student_enrolments SET enrolment_status = ? WHERE institution_id = ? AND id = ?'
        );
        $statement->bind_param('sii', $newStatus, $institutionId, $enrolmentId);
        $statement->execute();
        $statement->close();
        academic_management_append_status_event(
            $db,
            $institutionId,
            $actorUserId,
            $enrolmentId,
            $oldStatus,
            $newStatus,
            $reason
        );
        $actor = current_user();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            is_array($actor) ? (string) $actor['user_type'] : 'admin',
            $newStatus === 'withdrawn' ? 'student_enrolment.withdrawn' : 'student_enrolment.readmitted',
            'student_enrolment',
            (string) $enrolmentId,
            ['enrolment_status' => $oldStatus],
            ['enrolment_status' => $newStatus, 'reason' => $reason]
        );
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function academic_management_promote_enrolment(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    int $sourceEnrolmentId,
    int $targetYearId,
    int $targetGroupId,
    string $reason
): int {
    $reason = academic_management_text($reason, 'promotion reason', 500);
    $db->begin_transaction();
    try {
        $statement = $db->prepare(
            'SELECT e.student_id, e.academic_year_id, e.enrolment_status,
                    source_year.start_date AS source_start_date
             FROM student_enrolments e
             JOIN academic_years source_year
               ON source_year.institution_id = e.institution_id AND source_year.id = e.academic_year_id
             WHERE e.institution_id = ? AND e.id = ? FOR UPDATE'
        );
        $statement->bind_param('ii', $institutionId, $sourceEnrolmentId);
        $statement->execute();
        $source = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($source) || $source['enrolment_status'] !== 'active') {
            throw new DomainException('Only an active enrolment can be promoted.');
        }

        $year = academic_management_require_active_year($db, $institutionId, $targetYearId);
        $statement = $db->prepare(
            'SELECT id, start_date FROM academic_years
             WHERE institution_id = ?
               AND start_date = (
                   SELECT MIN(start_date) FROM academic_years
                   WHERE institution_id = ? AND start_date > ?
               )
             ORDER BY id LIMIT 2'
        );
        $statement->bind_param('iis', $institutionId, $institutionId, $source['source_start_date']);
        $statement->execute();
        $nextYears = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
        $statement->close();
        if (count($nextYears) !== 1 || (int) $nextYears[0]['id'] !== $targetYearId) {
            throw new DomainException('Promotion must target the immediately following, unambiguous academic year.');
        }
        $group = academic_management_lock_group($db, $institutionId, $targetYearId, $targetGroupId);
        academic_management_validate_group_for_model($group, $year['academic_model']);

        $statement = $db->prepare(
            'INSERT INTO student_enrolments
                (institution_id, student_id, academic_year_id, enrolment_status, promoted_from_enrolment_id)
             VALUES (?, ?, ?, \'active\', ?)'
        );
        $studentId = (int) $source['student_id'];
        $statement->bind_param('iiii', $institutionId, $studentId, $targetYearId, $sourceEnrolmentId);
        $statement->execute();
        $newEnrolmentId = (int) $statement->insert_id;
        $statement->close();
        academic_management_append_status_event(
            $db,
            $institutionId,
            $actorUserId,
            $newEnrolmentId,
            null,
            'active',
            $reason
        );
        academic_management_append_placement(
            $db,
            $institutionId,
            $actorUserId,
            $studentId,
            $newEnrolmentId,
            $targetYearId,
            $targetGroupId,
            $reason
        );
        $oldStatus = 'active';
        $promotedStatus = 'promoted';
        $statement = $db->prepare(
            'UPDATE student_enrolments SET enrolment_status = ?
             WHERE institution_id = ? AND id = ?'
        );
        $statement->bind_param('sii', $promotedStatus, $institutionId, $sourceEnrolmentId);
        $statement->execute();
        $statement->close();
        academic_management_append_status_event(
            $db,
            $institutionId,
            $actorUserId,
            $sourceEnrolmentId,
            $oldStatus,
            $promotedStatus,
            $reason
        );
        academic_management_sync_legacy_placement($db, $institutionId, $studentId, $targetYearId, $group);
        $actor = current_user();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            is_array($actor) ? (string) $actor['user_type'] : 'admin',
            'student_enrolment.promoted',
            'student_enrolment',
            (string) $newEnrolmentId,
            ['source_enrolment_id' => $sourceEnrolmentId],
            [
                'academic_year_id' => $targetYearId,
                'academic_group_id' => $targetGroupId,
                'source_enrolment_id' => $sourceEnrolmentId,
                'reason' => $reason,
            ]
        );
        $db->commit();
        return $newEnrolmentId;
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}
