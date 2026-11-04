-- Additive academic-management foundation for MySQL 8.4.
-- Run against a verified disposable restore first. This migration does not
-- backfill mutable student placement or touch payment/receipt records.

CREATE TABLE academic_migration_exceptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NULL,
    source_table VARCHAR(64) NOT NULL,
    source_record_id BIGINT UNSIGNED NOT NULL,
    constraint_name VARCHAR(128) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_academic_migration_exception
        (source_table, source_record_id, constraint_name),
    KEY idx_academic_migration_exception_institution
        (institution_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE institutions
    ADD COLUMN academic_model ENUM('school','college','university','coaching') NULL,
    ADD COLUMN active_academic_year_id BIGINT UNSIGNED NULL,
    ADD COLUMN academic_configuration_status
        ENUM('unconfigured','in_progress','configured') NOT NULL DEFAULT 'unconfigured',
    ADD KEY idx_institutions_active_academic_year (id, active_academic_year_id);

ALTER TABLE institutions
    ADD CONSTRAINT fk_institutions_active_academic_year
    FOREIGN KEY (id, active_academic_year_id)
    REFERENCES academic_years(institution_id, id) ON DELETE RESTRICT;

ALTER TABLE classes
    ADD COLUMN display_order INT UNSIGNED NOT NULL DEFAULT 0;

CREATE TABLE academic_year_classes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    status ENUM('planned','active','closed') NOT NULL DEFAULT 'planned',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_year_classes_id (institution_id, id),
    UNIQUE KEY uq_year_classes_class (institution_id, academic_year_id, class_id),
    UNIQUE KEY uq_year_classes_scope_id (institution_id, academic_year_id, class_id, id),
    KEY idx_year_classes_class (institution_id, class_id, academic_year_id),
    CONSTRAINT fk_year_classes_year FOREIGN KEY (institution_id, academic_year_id)
        REFERENCES academic_years(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_year_classes_class FOREIGN KEY (institution_id, class_id)
        REFERENCES classes(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sections (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    code VARCHAR(50) NULL,
    capacity INT UNSIGNED NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sections_institution_id (institution_id, id),
    UNIQUE KEY uq_sections_offering_id (institution_id, academic_year_id, class_id, id),
    UNIQUE KEY uq_sections_year_class_name (institution_id, academic_year_id, class_id, name),
    UNIQUE KEY uq_sections_year_class_code (institution_id, academic_year_id, class_id, code),
    KEY idx_sections_class_status (institution_id, academic_year_id, class_id, status),
    CONSTRAINT chk_sections_capacity CHECK (capacity IS NULL OR capacity > 0),
    CONSTRAINT fk_sections_class_offering
        FOREIGN KEY (institution_id, academic_year_id, class_id)
        REFERENCES academic_year_classes(institution_id, academic_year_id, class_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE departments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    code VARCHAR(50) NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_departments_institution_id (institution_id, id),
    UNIQUE KEY uq_departments_name (institution_id, name),
    UNIQUE KEY uq_departments_code (institution_id, code),
    KEY idx_departments_status (institution_id, status),
    CONSTRAINT fk_departments_institution FOREIGN KEY (institution_id)
        REFERENCES institutions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE courses
    ADD COLUMN department_id BIGINT UNSIGNED NULL,
    ADD KEY idx_courses_department (institution_id, department_id),
    ADD CONSTRAINT fk_courses_department
        FOREIGN KEY (institution_id, department_id)
        REFERENCES departments(institution_id, id) ON DELETE RESTRICT;

CREATE TABLE academic_year_courses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NOT NULL,
    status ENUM('planned','active','closed') NOT NULL DEFAULT 'planned',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_year_courses_id (institution_id, id),
    UNIQUE KEY uq_year_courses_course (institution_id, academic_year_id, course_id),
    UNIQUE KEY uq_year_courses_scope_id (institution_id, academic_year_id, course_id, id),
    KEY idx_year_courses_course (institution_id, course_id, academic_year_id),
    CONSTRAINT fk_year_courses_year FOREIGN KEY (institution_id, academic_year_id)
        REFERENCES academic_years(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_year_courses_course FOREIGN KEY (institution_id, course_id)
        REFERENCES courses(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE academic_terms (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    code VARCHAR(50) NULL,
    term_type ENUM('semester','term','trimester','year') NOT NULL,
    sequence_number SMALLINT UNSIGNED NOT NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    status ENUM('planned','active','closed') NOT NULL DEFAULT 'planned',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_academic_terms_institution_id (institution_id, id),
    UNIQUE KEY uq_academic_terms_scope_id (institution_id, academic_year_id, course_id, id),
    UNIQUE KEY uq_academic_terms_name (institution_id, academic_year_id, course_id, name),
    UNIQUE KEY uq_academic_terms_sequence (institution_id, academic_year_id, course_id, sequence_number),
    KEY idx_academic_terms_course_status (institution_id, course_id, academic_year_id, status),
    CONSTRAINT chk_academic_terms_dates CHECK
        (start_date IS NULL OR end_date IS NULL OR end_date >= start_date),
    CONSTRAINT fk_academic_terms_year_course
        FOREIGN KEY (institution_id, academic_year_id, course_id)
        REFERENCES academic_year_courses(institution_id, academic_year_id, course_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Affected legacy batches are retained and explicitly reported. Their existing
-- ID-only relationships remain until each reported row is reviewed.
INSERT INTO academic_migration_exceptions
    (institution_id, source_table, source_record_id, constraint_name, reason)
SELECT b.institution_id, 'batches', b.id, 'fk_batches_course_tenant',
       'Batch course belongs to another institution or is missing.'
FROM batches b
LEFT JOIN courses c ON c.id = b.course_id
WHERE c.id IS NULL OR c.institution_id <> b.institution_id
ON DUPLICATE KEY UPDATE reason = VALUES(reason);

INSERT INTO academic_migration_exceptions
    (institution_id, source_table, source_record_id, constraint_name, reason)
SELECT b.institution_id, 'batches', b.id, 'fk_batches_class_tenant',
       'Batch class belongs to another institution or is missing.'
FROM batches b
LEFT JOIN classes c ON c.id = b.class_id
WHERE c.id IS NULL OR c.institution_id <> b.institution_id
ON DUPLICATE KEY UPDATE reason = VALUES(reason);

ALTER TABLE batches
    MODIFY class_id BIGINT UNSIGNED NULL,
    ADD UNIQUE KEY uq_batches_course_id (institution_id, course_id, id),
    ADD UNIQUE KEY uq_batches_class_id (institution_id, class_id, id),
    ADD KEY idx_batches_course_tenant (institution_id, course_id),
    ADD KEY idx_batches_class_tenant (institution_id, class_id);

DELIMITER //
CREATE PROCEDURE apply_academic_batch_tenant_constraints()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM academic_migration_exceptions
        WHERE source_table = 'batches'
          AND constraint_name IN ('fk_batches_course_tenant','fk_batches_class_tenant')
    ) THEN
        ALTER TABLE batches
            DROP FOREIGN KEY fk_batches_course,
            DROP FOREIGN KEY fk_batches_class,
            ADD CONSTRAINT fk_batches_course_tenant
                FOREIGN KEY (institution_id, course_id)
                REFERENCES courses(institution_id, id) ON DELETE RESTRICT,
            ADD CONSTRAINT fk_batches_class_tenant
                FOREIGN KEY (institution_id, class_id)
                REFERENCES classes(institution_id, id) ON DELETE RESTRICT;
    END IF;
END//
DELIMITER ;
CALL apply_academic_batch_tenant_constraints();
DROP PROCEDURE apply_academic_batch_tenant_constraints;

CREATE TABLE academic_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    group_type ENUM('class','section','course','term','batch') NOT NULL,
    class_id BIGINT UNSIGNED NULL,
    section_id BIGINT UNSIGNED NULL,
    course_id BIGINT UNSIGNED NULL,
    academic_term_id BIGINT UNSIGNED NULL,
    batch_id BIGINT UNSIGNED NULL,
    scope_id BIGINT UNSIGNED GENERATED ALWAYS AS (
        CASE group_type
            WHEN 'class' THEN class_id
            WHEN 'section' THEN section_id
            WHEN 'course' THEN course_id
            WHEN 'term' THEN academic_term_id
            WHEN 'batch' THEN batch_id
        END
    ) STORED,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_academic_groups_id (institution_id, academic_year_id, id),
    UNIQUE KEY uq_academic_groups_scope (institution_id, academic_year_id, group_type, scope_id),
    KEY idx_academic_groups_class (institution_id, academic_year_id, class_id),
    KEY idx_academic_groups_course (institution_id, academic_year_id, course_id),
    KEY idx_academic_groups_batch (institution_id, course_id, batch_id),
    CONSTRAINT chk_academic_groups_target CHECK (
        (group_type = 'class' AND class_id IS NOT NULL AND section_id IS NULL
          AND course_id IS NULL AND academic_term_id IS NULL AND batch_id IS NULL)
        OR (group_type = 'section' AND class_id IS NOT NULL AND section_id IS NOT NULL
          AND course_id IS NULL AND academic_term_id IS NULL AND batch_id IS NULL)
        OR (group_type = 'course' AND class_id IS NULL AND section_id IS NULL
          AND course_id IS NOT NULL AND academic_term_id IS NULL AND batch_id IS NULL)
        OR (group_type = 'term' AND class_id IS NULL AND section_id IS NULL
          AND course_id IS NOT NULL AND academic_term_id IS NOT NULL AND batch_id IS NULL)
        OR (group_type = 'batch' AND class_id IS NULL AND section_id IS NULL
          AND course_id IS NOT NULL AND academic_term_id IS NULL AND batch_id IS NOT NULL)
    ),
    CONSTRAINT fk_academic_groups_year FOREIGN KEY (institution_id, academic_year_id)
        REFERENCES academic_years(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_academic_groups_class
        FOREIGN KEY (institution_id, academic_year_id, class_id)
        REFERENCES academic_year_classes(institution_id, academic_year_id, class_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_academic_groups_section
        FOREIGN KEY (institution_id, academic_year_id, class_id, section_id)
        REFERENCES sections(institution_id, academic_year_id, class_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_academic_groups_course
        FOREIGN KEY (institution_id, academic_year_id, course_id)
        REFERENCES academic_year_courses(institution_id, academic_year_id, course_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_academic_groups_term
        FOREIGN KEY (institution_id, academic_year_id, course_id, academic_term_id)
        REFERENCES academic_terms(institution_id, academic_year_id, course_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_academic_groups_batch
        FOREIGN KEY (institution_id, course_id, batch_id)
        REFERENCES batches(institution_id, course_id, id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subjects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    code VARCHAR(50) NULL,
    subject_type ENUM('core','elective','optional') NOT NULL DEFAULT 'core',
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_subjects_institution_id (institution_id, id),
    UNIQUE KEY uq_subjects_name (institution_id, name),
    UNIQUE KEY uq_subjects_code (institution_id, code),
    KEY idx_subjects_status (institution_id, status),
    CONSTRAINT fk_subjects_institution FOREIGN KEY (institution_id)
        REFERENCES institutions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE class_subjects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_class_subjects_institution_id (institution_id, id),
    UNIQUE KEY uq_class_subjects_assignment (institution_id, academic_year_id, class_id, subject_id),
    KEY idx_class_subjects_subject (institution_id, subject_id, academic_year_id),
    CONSTRAINT fk_class_subjects_class_offering
        FOREIGN KEY (institution_id, academic_year_id, class_id)
        REFERENCES academic_year_classes(institution_id, academic_year_id, class_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_class_subjects_subject FOREIGN KEY (institution_id, subject_id)
        REFERENCES subjects(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE section_subjects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    section_id BIGINT UNSIGNED NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    assignment_mode ENUM('include','exclude') NOT NULL DEFAULT 'include',
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_section_subjects_institution_id (institution_id, id),
    UNIQUE KEY uq_section_subjects_assignment
        (institution_id, academic_year_id, section_id, subject_id),
    KEY idx_section_subjects_class_subject
        (institution_id, academic_year_id, class_id, subject_id),
    CONSTRAINT fk_section_subjects_section
        FOREIGN KEY (institution_id, academic_year_id, class_id, section_id)
        REFERENCES sections(institution_id, academic_year_id, class_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_section_subjects_class_subject
        FOREIGN KEY (institution_id, academic_year_id, class_id, subject_id)
        REFERENCES class_subjects(institution_id, academic_year_id, class_id, subject_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE course_subjects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NOT NULL,
    academic_term_id BIGINT UNSIGNED NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    term_scope_id BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(academic_term_id, 0)) STORED,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_course_subjects_institution_id (institution_id, id),
    UNIQUE KEY uq_course_subjects_assignment
        (institution_id, academic_year_id, course_id, term_scope_id, subject_id),
    KEY idx_course_subjects_term (institution_id, academic_year_id, course_id, academic_term_id),
    KEY idx_course_subjects_subject (institution_id, subject_id, academic_year_id),
    CONSTRAINT chk_course_subjects_term CHECK (academic_term_id IS NULL OR academic_term_id > 0),
    CONSTRAINT fk_course_subjects_offering
        FOREIGN KEY (institution_id, academic_year_id, course_id)
        REFERENCES academic_year_courses(institution_id, academic_year_id, course_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_course_subjects_term
        FOREIGN KEY (institution_id, academic_year_id, course_id, academic_term_id)
        REFERENCES academic_terms(institution_id, academic_year_id, course_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_course_subjects_subject FOREIGN KEY (institution_id, subject_id)
        REFERENCES subjects(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE student_enrolments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    admission_number VARCHAR(50) NULL,
    enrolment_status ENUM('enrolled','active','withdrawn','cancelled','completed','promoted')
        NOT NULL DEFAULT 'enrolled',
    enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    promoted_from_enrolment_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_student_enrolments_institution_id (institution_id, id),
    UNIQUE KEY uq_enrolment_student_year (institution_id, academic_year_id, student_id),
    UNIQUE KEY uq_enrolment_student_identity (institution_id, student_id, id),
    UNIQUE KEY uq_enrolment_student_year_id (institution_id, student_id, academic_year_id, id),
    UNIQUE KEY uq_enrolment_admission_year (institution_id, academic_year_id, admission_number),
    KEY idx_enrolments_student_status (institution_id, student_id, enrolment_status),
    KEY idx_enrolments_promoted_from (institution_id, student_id, promoted_from_enrolment_id),
    CONSTRAINT fk_enrolments_student FOREIGN KEY (institution_id, student_id)
        REFERENCES students(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_enrolments_year FOREIGN KEY (institution_id, academic_year_id)
        REFERENCES academic_years(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_enrolments_promotion_source
        FOREIGN KEY (institution_id, student_id, promoted_from_enrolment_id)
        REFERENCES student_enrolments(institution_id, student_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE student_enrolment_placements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    student_enrolment_id BIGINT UNSIGNED NOT NULL,
    academic_group_id BIGINT UNSIGNED NOT NULL,
    previous_placement_id BIGINT UNSIGNED NULL,
    roll_number VARCHAR(50) NULL,
    effective_at DATETIME(6) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_enrolment_placements_id (institution_id, id),
    UNIQUE KEY uq_enrolment_placement_time (institution_id, student_enrolment_id, effective_at),
    UNIQUE KEY uq_enrolment_placement_identity (institution_id, student_enrolment_id, id),
    UNIQUE KEY uq_enrolment_placement_scope
        (institution_id, academic_year_id, academic_group_id, student_id, student_enrolment_id, id),
    KEY idx_enrolment_placement_history (institution_id, student_enrolment_id, effective_at),
    CONSTRAINT fk_placement_enrolment
        FOREIGN KEY (institution_id, student_id, academic_year_id, student_enrolment_id)
        REFERENCES student_enrolments(institution_id, student_id, academic_year_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_placement_group
        FOREIGN KEY (institution_id, academic_year_id, academic_group_id)
        REFERENCES academic_groups(institution_id, academic_year_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_placement_previous
        FOREIGN KEY (institution_id, student_enrolment_id, previous_placement_id)
        REFERENCES student_enrolment_placements(institution_id, student_enrolment_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_placement_actor FOREIGN KEY (actor_user_id)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE student_enrolment_status_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    student_enrolment_id BIGINT UNSIGNED NOT NULL,
    previous_status ENUM('enrolled','active','withdrawn','cancelled','completed','promoted') NULL,
    new_status ENUM('enrolled','active','withdrawn','cancelled','completed','promoted') NOT NULL,
    effective_at DATETIME(6) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_enrolment_status_event_id (institution_id, id),
    UNIQUE KEY uq_enrolment_status_event_time (institution_id, student_enrolment_id, effective_at),
    KEY idx_enrolment_status_history (institution_id, student_enrolment_id, effective_at),
    CONSTRAINT chk_enrolment_status_change CHECK
        (previous_status IS NULL OR previous_status <> new_status),
    CONSTRAINT fk_enrolment_status_event FOREIGN KEY (institution_id, student_enrolment_id)
        REFERENCES student_enrolments(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_enrolment_status_actor FOREIGN KEY (actor_user_id)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE fee_structures
    ADD COLUMN section_id BIGINT UNSIGNED NULL,
    ADD COLUMN academic_term_id BIGINT UNSIGNED NULL,
    ADD COLUMN batch_id BIGINT UNSIGNED NULL,
    ADD COLUMN year_class_id BIGINT UNSIGNED NULL,
    ADD COLUMN year_course_id BIGINT UNSIGNED NULL,
    ADD COLUMN fee_group_code VARCHAR(40) NULL,
    ADD COLUMN effective_from DATE NULL,
    ADD COLUMN effective_until DATE NULL,
    ADD COLUMN target_model ENUM('legacy','class','course','term','batch') NOT NULL DEFAULT 'legacy',
    ADD COLUMN target_scope_kind VARCHAR(10) GENERATED ALWAYS AS (
        CASE
            WHEN target_model = 'class' AND section_id IS NOT NULL THEN 'section'
            WHEN target_model = 'class' THEN 'class'
            WHEN target_model = 'course' THEN 'course'
            WHEN target_model = 'term' THEN 'term'
            WHEN target_model = 'batch' THEN 'batch'
            ELSE NULL
        END
    ) STORED,
    ADD COLUMN target_scope_id BIGINT UNSIGNED GENERATED ALWAYS AS (
        CASE
            WHEN target_model = 'class' AND section_id IS NOT NULL THEN section_id
            WHEN target_model = 'class' THEN class_id
            WHEN target_model = 'course' THEN course_id
            WHEN target_model = 'term' THEN academic_term_id
            WHEN target_model = 'batch' THEN batch_id
            ELSE NULL
        END
    ) STORED,
    ADD COLUMN active_scope_slot TINYINT UNSIGNED GENERATED ALWAYS AS (
        CASE WHEN status = 'active' AND target_model <> 'legacy' THEN 1 ELSE NULL END
    ) STORED,
    ADD UNIQUE KEY uq_fee_structures_year_id (institution_id, academic_year_id, id),
    ADD UNIQUE KEY uq_fee_structures_active_start
        (institution_id, academic_year_id, target_scope_kind, target_scope_id,
         fee_group_code, effective_from, active_scope_slot),
    ADD KEY idx_fee_structures_class_scope
        (institution_id, academic_year_id, class_id, section_id, status),
    ADD KEY idx_fee_structures_class_offering
        (institution_id, academic_year_id, class_id, year_class_id),
    ADD KEY idx_fee_structures_course_scope
        (institution_id, academic_year_id, course_id, academic_term_id, status),
    ADD KEY idx_fee_structures_course_offering
        (institution_id, academic_year_id, course_id, year_course_id),
    ADD KEY idx_fee_structures_batch_scope (institution_id, academic_year_id, batch_id, status),
    ADD KEY idx_fee_structures_batch_course (institution_id, course_id, batch_id),
    ADD CONSTRAINT chk_fee_structure_target_shape CHECK (
        target_model = 'legacy'
        OR (target_model = 'class' AND class_id IS NOT NULL
            AND course_id IS NULL AND academic_term_id IS NULL AND batch_id IS NULL
            AND year_class_id IS NOT NULL AND year_course_id IS NULL
            AND fee_group_code IS NOT NULL AND effective_from IS NOT NULL
            AND (effective_until IS NULL OR effective_until >= effective_from))
        OR (target_model = 'course' AND course_id IS NOT NULL
            AND class_id IS NULL AND section_id IS NULL
            AND academic_term_id IS NULL AND batch_id IS NULL
            AND year_class_id IS NULL AND year_course_id IS NOT NULL
            AND fee_group_code IS NOT NULL AND effective_from IS NOT NULL
            AND (effective_until IS NULL OR effective_until >= effective_from))
        OR (target_model = 'term' AND course_id IS NOT NULL AND academic_term_id IS NOT NULL
            AND class_id IS NULL AND section_id IS NULL AND batch_id IS NULL
            AND year_class_id IS NULL AND year_course_id IS NOT NULL
            AND fee_group_code IS NOT NULL AND effective_from IS NOT NULL
            AND (effective_until IS NULL OR effective_until >= effective_from))
        OR (target_model = 'batch' AND batch_id IS NOT NULL AND course_id IS NOT NULL
            AND year_course_id IS NOT NULL AND class_id IS NULL AND section_id IS NULL
            AND academic_term_id IS NULL AND year_class_id IS NULL
            AND fee_group_code IS NOT NULL AND effective_from IS NOT NULL
            AND (effective_until IS NULL OR effective_until >= effective_from))
    ),
    ADD CONSTRAINT fk_fee_structure_class_offering
        FOREIGN KEY (institution_id, academic_year_id, class_id, year_class_id)
        REFERENCES academic_year_classes(institution_id, academic_year_id, class_id, id)
        ON DELETE RESTRICT,
    ADD CONSTRAINT fk_fee_structure_course_offering
        FOREIGN KEY (institution_id, academic_year_id, course_id, year_course_id)
        REFERENCES academic_year_courses(institution_id, academic_year_id, course_id, id)
        ON DELETE RESTRICT,
    ADD CONSTRAINT fk_fee_structure_section
        FOREIGN KEY (institution_id, academic_year_id, class_id, section_id)
        REFERENCES sections(institution_id, academic_year_id, class_id, id)
        ON DELETE RESTRICT,
    ADD CONSTRAINT fk_fee_structure_term
        FOREIGN KEY (institution_id, academic_year_id, course_id, academic_term_id)
        REFERENCES academic_terms(institution_id, academic_year_id, course_id, id)
        ON DELETE RESTRICT,
    ADD CONSTRAINT fk_fee_structure_batch_course
        FOREIGN KEY (institution_id, course_id, batch_id)
        REFERENCES batches(institution_id, course_id, id) ON DELETE RESTRICT;

ALTER TABLE fee_structure_items
    ADD UNIQUE KEY uq_fee_structure_items_institution_id (institution_id, id),
    ADD UNIQUE KEY uq_fee_structure_items_identity (institution_id, id, category_id),
    ADD COLUMN frequency ENUM('one_time','monthly','quarterly','half_yearly','yearly','custom')
        NULL DEFAULT NULL,
    ADD COLUMN description VARCHAR(500) NULL,
    ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    ADD KEY idx_fee_items_component_status (institution_id, fee_structure_id, status),
    ADD KEY idx_fee_items_category_tenant (institution_id, category_id);

CREATE TABLE fee_component_schedule (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    fee_structure_item_id BIGINT UNSIGNED NOT NULL,
    installment_number SMALLINT UNSIGNED NOT NULL,
    due_date DATE NOT NULL,
    amount DECIMAL(12,2) NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fee_component_schedule_id (institution_id, id),
    UNIQUE KEY uq_fee_component_schedule_number
        (institution_id, fee_structure_item_id, installment_number),
    KEY idx_fee_component_schedule_due (institution_id, due_date, status),
    CONSTRAINT chk_fee_component_schedule_amount CHECK (amount IS NULL OR amount >= 0),
    CONSTRAINT fk_fee_component_schedule_item
        FOREIGN KEY (institution_id, fee_structure_item_id)
        REFERENCES fee_structure_items(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fee_structure_scope_guards (
    institution_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    target_scope_kind VARCHAR(10) NOT NULL,
    target_scope_id BIGINT UNSIGNED NOT NULL,
    fee_group_code VARCHAR(40) NOT NULL,
    PRIMARY KEY (institution_id, academic_year_id, target_scope_kind, target_scope_id, fee_group_code),
    CONSTRAINT fk_fee_scope_guard_year FOREIGN KEY (institution_id, academic_year_id)
        REFERENCES academic_years(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE student_fee_assignments
    ADD COLUMN student_enrolment_id BIGINT UNSIGNED NULL,
    ADD KEY idx_student_fee_assignment_structure_year
        (institution_id, academic_year_id, fee_structure_id),
    ADD KEY idx_student_fee_assignment_enrolment
        (institution_id, student_id, academic_year_id, student_enrolment_id),
    ADD CONSTRAINT fk_student_fee_assignment_enrolment
        FOREIGN KEY (institution_id, student_id, academic_year_id, student_enrolment_id)
        REFERENCES student_enrolments(institution_id, student_id, academic_year_id, id)
        ON DELETE RESTRICT;

-- Preserve mismatched historic assignments and omit only the blocked composite
-- structure/year FK. A later repair migration can add it after review.
INSERT INTO academic_migration_exceptions
    (institution_id, source_table, source_record_id, constraint_name, reason)
SELECT sfa.institution_id, 'student_fee_assignments', sfa.id,
       'fk_student_fee_assignment_structure_year',
       'Assignment academic year differs from its referenced fee structure.'
FROM student_fee_assignments sfa
JOIN fee_structures fs
  ON fs.institution_id = sfa.institution_id AND fs.id = sfa.fee_structure_id
WHERE sfa.academic_year_id <> fs.academic_year_id
ON DUPLICATE KEY UPDATE reason = VALUES(reason);

DELIMITER //
CREATE PROCEDURE apply_academic_fee_assignment_year_constraint()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM academic_migration_exceptions
        WHERE source_table = 'student_fee_assignments'
          AND constraint_name = 'fk_student_fee_assignment_structure_year'
    ) THEN
        ALTER TABLE student_fee_assignments
            ADD CONSTRAINT fk_student_fee_assignment_structure_year
            FOREIGN KEY (institution_id, academic_year_id, fee_structure_id)
            REFERENCES fee_structures(institution_id, academic_year_id, id)
            ON DELETE RESTRICT;
    END IF;
END//
DELIMITER ;
CALL apply_academic_fee_assignment_year_constraint();
DROP PROCEDURE apply_academic_fee_assignment_year_constraint;

CREATE TABLE student_fee_assignment_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    student_fee_assignment_id BIGINT UNSIGNED NOT NULL,
    fee_category_id BIGINT UNSIGNED NOT NULL,
    source_fee_structure_item_id BIGINT UNSIGNED NULL,
    source_section_id BIGINT UNSIGNED NULL,
    component_name VARCHAR(150) NOT NULL,
    component_description VARCHAR(500) NULL,
    frequency ENUM('one_time','monthly','quarterly','half_yearly','yearly','custom') NULL,
    amount DECIMAL(12,2) NOT NULL,
    is_optional TINYINT(1) NOT NULL DEFAULT 0,
    source_version VARCHAR(40) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_assignment_items_institution_id (institution_id, id),
    UNIQUE KEY uq_assignment_items_category (institution_id, student_fee_assignment_id, fee_category_id),
    KEY idx_assignment_items_source_identity
        (institution_id, source_fee_structure_item_id, fee_category_id),
    KEY idx_assignment_items_category (institution_id, fee_category_id),
    KEY idx_assignment_items_section (institution_id, source_section_id),
    KEY idx_assignment_items_assignment (institution_id, student_fee_assignment_id),
    CONSTRAINT chk_assignment_item_amount CHECK (amount >= 0),
    CONSTRAINT fk_assignment_items_assignment
        FOREIGN KEY (institution_id, student_fee_assignment_id)
        REFERENCES student_fee_assignments(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_assignment_items_source
        FOREIGN KEY (institution_id, source_fee_structure_item_id, fee_category_id)
        REFERENCES fee_structure_items(institution_id, id, category_id) ON DELETE RESTRICT,
    CONSTRAINT fk_assignment_items_category FOREIGN KEY (institution_id, fee_category_id)
        REFERENCES fee_categories(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_assignment_items_section FOREIGN KEY (institution_id, source_section_id)
        REFERENCES sections(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE student_fee_assignment_item_schedules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    assignment_item_id BIGINT UNSIGNED NOT NULL,
    installment_number SMALLINT UNSIGNED NOT NULL,
    due_date DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_assignment_item_schedules_id (institution_id, id),
    UNIQUE KEY uq_assignment_item_schedule_number (institution_id, assignment_item_id, installment_number),
    KEY idx_assignment_item_schedules_due (institution_id, due_date),
    CONSTRAINT chk_assignment_item_schedule_amount CHECK (amount >= 0),
    CONSTRAINT fk_assignment_item_schedules_item
        FOREIGN KEY (institution_id, assignment_item_id)
        REFERENCES student_fee_assignment_items(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attendance_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    academic_group_id BIGINT UNSIGNED NOT NULL,
    session_at DATETIME(6) NOT NULL,
    session_code VARCHAR(50) NOT NULL,
    status ENUM('scheduled','open','closed','cancelled') NOT NULL DEFAULT 'scheduled',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_sessions_id (institution_id, academic_year_id, academic_group_id, id),
    UNIQUE KEY uq_attendance_session_code
        (institution_id, academic_year_id, academic_group_id, session_at, session_code),
    KEY idx_attendance_sessions_group_time
        (institution_id, academic_year_id, academic_group_id, session_at, status),
    CONSTRAINT fk_attendance_session_group
        FOREIGN KEY (institution_id, academic_year_id, academic_group_id)
        REFERENCES academic_groups(institution_id, academic_year_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attendance_session_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    academic_group_id BIGINT UNSIGNED NOT NULL,
    attendance_session_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    student_enrolment_id BIGINT UNSIGNED NOT NULL,
    placement_id BIGINT UNSIGNED NOT NULL,
    status ENUM('present','absent','late','excused') NOT NULL,
    recorded_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_session_records_id (institution_id, id),
    UNIQUE KEY uq_attendance_session_student (institution_id, attendance_session_id, student_id),
    KEY idx_attendance_session_record_placement
        (institution_id, academic_year_id, academic_group_id, student_enrolment_id, placement_id),
    CONSTRAINT fk_attendance_record_session
        FOREIGN KEY (institution_id, academic_year_id, academic_group_id, attendance_session_id)
        REFERENCES attendance_sessions(institution_id, academic_year_id, academic_group_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_attendance_record_placement
        FOREIGN KEY (institution_id, academic_year_id, academic_group_id, student_id,
                     student_enrolment_id, placement_id)
        REFERENCES student_enrolment_placements(institution_id, academic_year_id, academic_group_id,
                                                student_id, student_enrolment_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_attendance_record_actor FOREIGN KEY (recorded_by)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rollback is intentionally not automated: MySQL DDL commits implicitly and
-- this migration may write review exceptions or new academic records.
