-- Add tenant-scoped, idempotent links from approved applications to enrolments.
-- Existing admissions and academic records are preserved.

DELIMITER //
CREATE PROCEDURE apply_admission_enrolment_conversion()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND column_name = 'student_enrolment_id'
    ) THEN
        ALTER TABLE admissions ADD COLUMN student_enrolment_id BIGINT UNSIGNED NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND column_name = 'academic_group_id'
    ) THEN
        ALTER TABLE admissions ADD COLUMN academic_group_id BIGINT UNSIGNED NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND column_name = 'student_enrolment_placement_id'
    ) THEN
        ALTER TABLE admissions ADD COLUMN student_enrolment_placement_id BIGINT UNSIGNED NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND column_name = 'converted_at'
    ) THEN
        ALTER TABLE admissions ADD COLUMN converted_at DATETIME NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND column_name = 'converted_by'
    ) THEN
        ALTER TABLE admissions ADD COLUMN converted_by BIGINT UNSIGNED NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND index_name = 'uq_admissions_enrolment_conversion'
    ) THEN
        ALTER TABLE admissions
            ADD UNIQUE KEY uq_admissions_enrolment_conversion
                (institution_id, student_enrolment_id);
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND index_name = 'uq_admissions_placement_conversion'
    ) THEN
        ALTER TABLE admissions
            ADD UNIQUE KEY uq_admissions_placement_conversion
                (institution_id, student_enrolment_placement_id);
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.referential_constraints
        WHERE constraint_schema = DATABASE()
          AND table_name = 'admissions'
          AND constraint_name = 'fk_admissions_conversion_enrolment'
    ) THEN
        ALTER TABLE admissions
            ADD CONSTRAINT fk_admissions_conversion_enrolment
            FOREIGN KEY (institution_id, student_id, academic_year_id, student_enrolment_id)
            REFERENCES student_enrolments(institution_id, student_id, academic_year_id, id)
            ON DELETE RESTRICT;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.referential_constraints
        WHERE constraint_schema = DATABASE()
          AND table_name = 'admissions'
          AND constraint_name = 'fk_admissions_conversion_placement'
    ) THEN
        ALTER TABLE admissions
            ADD CONSTRAINT fk_admissions_conversion_placement
            FOREIGN KEY (institution_id, academic_year_id, academic_group_id, student_id,
                         student_enrolment_id, student_enrolment_placement_id)
            REFERENCES student_enrolment_placements(institution_id, academic_year_id,
                         academic_group_id, student_id, student_enrolment_id, id)
            ON DELETE RESTRICT;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.referential_constraints
        WHERE constraint_schema = DATABASE()
          AND table_name = 'admissions'
          AND constraint_name = 'fk_admissions_conversion_actor'
    ) THEN
        ALTER TABLE admissions
            ADD CONSTRAINT fk_admissions_conversion_actor
            FOREIGN KEY (institution_id, converted_by)
            REFERENCES users(institution_id, id)
            ON DELETE RESTRICT;
    END IF;
END//
DELIMITER ;

CALL apply_admission_enrolment_conversion();
DROP PROCEDURE apply_admission_enrolment_conversion;
