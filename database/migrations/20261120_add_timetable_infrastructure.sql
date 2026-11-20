-- Additive teacher-allocation and timetable infrastructure for MySQL 8.4.
-- Legacy timetables are deliberately preserved and not backfilled.

CREATE TABLE IF NOT EXISTS teacher_group_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    academic_group_id BIGINT UNSIGNED NOT NULL,
    instructor_id BIGINT UNSIGNED NOT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    assigned_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_teacher_group_assignments_id (institution_id, id),
    UNIQUE KEY uq_teacher_group_assignments_scope
        (institution_id, academic_year_id, academic_group_id, instructor_id),
    UNIQUE KEY uq_teacher_group_assignments_scope_id
        (institution_id, academic_year_id, academic_group_id, id),
    KEY idx_teacher_group_assignments_instructor
        (institution_id, academic_year_id, instructor_id, status),
    KEY idx_teacher_group_assignments_class
        (institution_id, academic_year_id, class_id, status),
    CONSTRAINT fk_teacher_group_assignments_year
        FOREIGN KEY (institution_id, academic_year_id)
        REFERENCES academic_years(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_teacher_group_assignments_class
        FOREIGN KEY (institution_id, academic_year_id, class_id)
        REFERENCES academic_year_classes(institution_id, academic_year_id, class_id) ON DELETE RESTRICT,
    CONSTRAINT fk_teacher_group_assignments_group
        FOREIGN KEY (institution_id, academic_year_id, academic_group_id)
        REFERENCES academic_groups(institution_id, academic_year_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_teacher_group_assignments_instructor
        FOREIGN KEY (institution_id, instructor_id)
        REFERENCES instructors(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_teacher_group_assignments_actor
        FOREIGN KEY (institution_id, assigned_by)
        REFERENCES users(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teacher_subject_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    academic_group_id BIGINT UNSIGNED NOT NULL,
    teacher_group_assignment_id BIGINT UNSIGNED NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    assigned_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_teacher_subject_assignments_id (institution_id, id),
    UNIQUE KEY uq_teacher_subject_assignments_scope
        (institution_id, academic_year_id, academic_group_id, teacher_group_assignment_id, subject_id),
    UNIQUE KEY uq_teacher_subject_assignments_group_id
        (institution_id, academic_year_id, academic_group_id, id),
    KEY idx_teacher_subject_assignments_teacher
        (institution_id, academic_year_id, teacher_group_assignment_id, status),
    KEY idx_teacher_subject_assignments_subject
        (institution_id, academic_year_id, subject_id, status),
    CONSTRAINT fk_teacher_subject_assignments_teacher
        FOREIGN KEY (institution_id, academic_year_id, academic_group_id, teacher_group_assignment_id)
        REFERENCES teacher_group_assignments(institution_id, academic_year_id, academic_group_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_teacher_subject_assignments_class_subject
        FOREIGN KEY (institution_id, academic_year_id, class_id, subject_id)
        REFERENCES class_subjects(institution_id, academic_year_id, class_id, subject_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_teacher_subject_assignments_actor
        FOREIGN KEY (institution_id, assigned_by)
        REFERENCES users(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS classrooms (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    room_code VARCHAR(50) NOT NULL,
    name VARCHAR(120) NOT NULL,
    building VARCHAR(120) NULL,
    capacity INT UNSIGNED NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_classrooms_institution_id (institution_id, id),
    UNIQUE KEY uq_classrooms_code (institution_id, room_code),
    KEY idx_classrooms_status (institution_id, status),
    CONSTRAINT chk_classrooms_capacity CHECK (capacity IS NULL OR capacity > 0),
    CONSTRAINT fk_classrooms_institution
        FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS timetable_period_schedules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    status ENUM('draft','active','retired') NOT NULL DEFAULT 'draft',
    active_year_id BIGINT UNSIGNED GENERATED ALWAYS AS (
        CASE WHEN status = 'active' THEN academic_year_id ELSE NULL END
    ) STORED,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_period_schedules_id (institution_id, academic_year_id, id),
    UNIQUE KEY uq_period_schedules_version (institution_id, academic_year_id, version_number),
    UNIQUE KEY uq_period_schedules_active_year (institution_id, active_year_id),
    KEY idx_period_schedules_status (institution_id, academic_year_id, status),
    CONSTRAINT fk_period_schedules_year
        FOREIGN KEY (institution_id, academic_year_id)
        REFERENCES academic_years(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_period_schedules_actor
        FOREIGN KEY (institution_id, created_by)
        REFERENCES users(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS timetable_periods (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    period_schedule_id BIGINT UNSIGNED NOT NULL,
    day_of_week ENUM('monday','tuesday','wednesday','thursday','friday','saturday','sunday') NOT NULL,
    period_number SMALLINT UNSIGNED NOT NULL,
    label VARCHAR(80) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    period_type ENUM('teaching','break') NOT NULL DEFAULT 'teaching',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_timetable_periods_id (institution_id, academic_year_id, period_schedule_id, id),
    UNIQUE KEY uq_timetable_periods_number
        (institution_id, academic_year_id, period_schedule_id, day_of_week, period_number),
    KEY idx_timetable_periods_time
        (institution_id, academic_year_id, period_schedule_id, day_of_week, start_time, end_time),
    CONSTRAINT chk_timetable_period_order CHECK (start_time < end_time),
    CONSTRAINT chk_timetable_period_number CHECK (period_number > 0),
    CONSTRAINT fk_timetable_periods_schedule
        FOREIGN KEY (institution_id, academic_year_id, period_schedule_id)
        REFERENCES timetable_period_schedules(institution_id, academic_year_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS academic_timetables (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    scope_mode ENUM('class','sections') NOT NULL,
    status ENUM('active','archived') NOT NULL DEFAULT 'active',
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_academic_timetables_id (institution_id, academic_year_id, id),
    UNIQUE KEY uq_academic_timetables_cohort (institution_id, academic_year_id, class_id),
    UNIQUE KEY uq_academic_timetables_cohort_id (institution_id, academic_year_id, class_id, id),
    KEY idx_academic_timetables_status (institution_id, academic_year_id, status),
    CONSTRAINT fk_academic_timetables_year_class
        FOREIGN KEY (institution_id, academic_year_id, class_id)
        REFERENCES academic_year_classes(institution_id, academic_year_id, class_id) ON DELETE RESTRICT,
    CONSTRAINT fk_academic_timetables_actor
        FOREIGN KEY (institution_id, created_by)
        REFERENCES users(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS timetable_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    academic_timetable_id BIGINT UNSIGNED NOT NULL,
    academic_group_id BIGINT UNSIGNED NOT NULL,
    period_schedule_id BIGINT UNSIGNED NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    status ENUM('draft','published','superseded') NOT NULL DEFAULT 'draft',
    published_group_id BIGINT UNSIGNED GENERATED ALWAYS AS (
        CASE WHEN status = 'published' THEN academic_group_id ELSE NULL END
    ) STORED,
    validation_errors JSON NULL,
    validated_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    published_by BIGINT UNSIGNED NULL,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_timetable_versions_id (institution_id, academic_year_id, id),
    UNIQUE KEY uq_timetable_versions_group_id
        (institution_id, academic_year_id, academic_group_id, id),
    UNIQUE KEY uq_timetable_versions_number
        (institution_id, academic_timetable_id, academic_group_id, version_number),
    UNIQUE KEY uq_timetable_versions_published_group
        (institution_id, academic_timetable_id, published_group_id),
    UNIQUE KEY uq_timetable_versions_entry_scope
        (institution_id, academic_year_id, academic_timetable_id, class_id,
         academic_group_id, id, period_schedule_id),
    KEY idx_timetable_versions_status (institution_id, academic_year_id, status, created_at),
    KEY idx_timetable_versions_group (institution_id, academic_year_id, academic_group_id, status),
    CONSTRAINT chk_timetable_version_publication CHECK (
        (status IN ('published','superseded') AND published_at IS NOT NULL)
        OR (status = 'draft' AND published_at IS NULL AND published_by IS NULL)
    ),
    CONSTRAINT fk_timetable_versions_timetable
        FOREIGN KEY (institution_id, academic_year_id, class_id, academic_timetable_id)
        REFERENCES academic_timetables(institution_id, academic_year_id, class_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_timetable_versions_group
        FOREIGN KEY (institution_id, academic_year_id, academic_group_id)
        REFERENCES academic_groups(institution_id, academic_year_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_timetable_versions_period_schedule
        FOREIGN KEY (institution_id, academic_year_id, period_schedule_id)
        REFERENCES timetable_period_schedules(institution_id, academic_year_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_timetable_versions_creator
        FOREIGN KEY (institution_id, created_by)
        REFERENCES users(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_timetable_versions_publisher
        FOREIGN KEY (institution_id, published_by)
        REFERENCES users(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS timetable_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    academic_timetable_id BIGINT UNSIGNED NOT NULL,
    academic_group_id BIGINT UNSIGNED NOT NULL,
    timetable_version_id BIGINT UNSIGNED NOT NULL,
    period_schedule_id BIGINT UNSIGNED NOT NULL,
    period_id BIGINT UNSIGNED NOT NULL,
    teacher_subject_assignment_id BIGINT UNSIGNED NOT NULL,
    classroom_id BIGINT UNSIGNED NULL,
    notes VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_timetable_entries_id (institution_id, id),
    UNIQUE KEY uq_timetable_entries_slot (institution_id, timetable_version_id, period_id),
    KEY idx_timetable_entries_teacher_subject
        (institution_id, academic_year_id, teacher_subject_assignment_id),
    KEY idx_timetable_entries_classroom (institution_id, classroom_id, period_id),
    KEY idx_timetable_entries_group
        (institution_id, academic_year_id, academic_group_id, timetable_version_id),
    CONSTRAINT fk_timetable_entries_version
        FOREIGN KEY (institution_id, academic_year_id, academic_timetable_id, class_id,
                     academic_group_id, timetable_version_id, period_schedule_id)
        REFERENCES timetable_versions(institution_id, academic_year_id, academic_timetable_id,
                                      class_id, academic_group_id, id, period_schedule_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_timetable_entries_period
        FOREIGN KEY (institution_id, academic_year_id, period_schedule_id, period_id)
        REFERENCES timetable_periods(institution_id, academic_year_id, period_schedule_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_timetable_entries_teacher_subject
        FOREIGN KEY (institution_id, academic_year_id, academic_group_id, teacher_subject_assignment_id)
        REFERENCES teacher_subject_assignments(institution_id, academic_year_id, academic_group_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_timetable_entries_classroom
        FOREIGN KEY (institution_id, classroom_id)
        REFERENCES classrooms(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
