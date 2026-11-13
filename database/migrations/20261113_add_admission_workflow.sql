-- Add admission enquiries, complete application details, status history,
-- private document metadata, and institution/year student code sequences.
-- Existing admission and student records are preserved.

DELIMITER //
CREATE PROCEDURE apply_admission_workflow()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND column_name = 'application_reference'
    ) THEN
        ALTER TABLE admissions ADD COLUMN application_reference VARCHAR(40) NULL AFTER id;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND column_name = 'requested_academic_group_id'
    ) THEN
        ALTER TABLE admissions ADD COLUMN requested_academic_group_id BIGINT UNSIGNED NULL AFTER academic_year_id;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND column_name = 'first_name'
    ) THEN
        ALTER TABLE admissions ADD COLUMN first_name VARCHAR(80) NULL AFTER student_name;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND column_name = 'middle_name'
    ) THEN
        ALTER TABLE admissions ADD COLUMN middle_name VARCHAR(80) NULL AFTER first_name;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND column_name = 'last_name'
    ) THEN
        ALTER TABLE admissions ADD COLUMN last_name VARCHAR(80) NULL AFTER middle_name;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND column_name = 'gender'
    ) THEN
        ALTER TABLE admissions ADD COLUMN gender ENUM('male','female','other','prefer_not_to_say') NULL AFTER date_of_birth;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND column_name = 'student_mobile'
    ) THEN
        ALTER TABLE admissions ADD COLUMN student_mobile VARCHAR(20) NULL AFTER gender;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND column_name = 'student_email'
    ) THEN
        ALTER TABLE admissions ADD COLUMN student_email VARCHAR(255) NULL AFTER student_mobile;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND column_name = 'address_line1'
    ) THEN
        ALTER TABLE admissions ADD COLUMN address_line1 VARCHAR(255) NULL AFTER student_email;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND column_name = 'address_line2'
    ) THEN
        ALTER TABLE admissions ADD COLUMN address_line2 VARCHAR(255) NULL AFTER address_line1;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND column_name = 'city'
    ) THEN
        ALTER TABLE admissions ADD COLUMN city VARCHAR(120) NULL AFTER address_line2;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND column_name = 'state'
    ) THEN
        ALTER TABLE admissions ADD COLUMN state VARCHAR(120) NULL AFTER city;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND column_name = 'postal_code'
    ) THEN
        ALTER TABLE admissions ADD COLUMN postal_code VARCHAR(20) NULL AFTER state;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND column_name = 'country'
    ) THEN
        ALTER TABLE admissions ADD COLUMN country VARCHAR(120) NULL AFTER postal_code;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND column_name = 'previous_school'
    ) THEN
        ALTER TABLE admissions ADD COLUMN previous_school VARCHAR(200) NULL AFTER country;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND column_name = 'admission_category'
    ) THEN
        ALTER TABLE admissions ADD COLUMN admission_category VARCHAR(100) NULL AFTER previous_school;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND column_name = 'father_name'
    ) THEN
        ALTER TABLE admissions ADD COLUMN father_name VARCHAR(150) NULL AFTER admission_category;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND column_name = 'father_mobile'
    ) THEN
        ALTER TABLE admissions ADD COLUMN father_mobile VARCHAR(20) NULL AFTER father_name;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND column_name = 'father_email'
    ) THEN
        ALTER TABLE admissions ADD COLUMN father_email VARCHAR(255) NULL AFTER father_mobile;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND column_name = 'mother_name'
    ) THEN
        ALTER TABLE admissions ADD COLUMN mother_name VARCHAR(150) NULL AFTER father_email;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND column_name = 'mother_mobile'
    ) THEN
        ALTER TABLE admissions ADD COLUMN mother_mobile VARCHAR(20) NULL AFTER mother_name;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND column_name = 'mother_email'
    ) THEN
        ALTER TABLE admissions ADD COLUMN mother_email VARCHAR(255) NULL AFTER mother_mobile;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND column_name = 'guardian_name'
    ) THEN
        ALTER TABLE admissions ADD COLUMN guardian_name VARCHAR(150) NULL AFTER mother_email;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND column_name = 'guardian_mobile'
    ) THEN
        ALTER TABLE admissions ADD COLUMN guardian_mobile VARCHAR(20) NULL AFTER guardian_name;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND column_name = 'guardian_email'
    ) THEN
        ALTER TABLE admissions ADD COLUMN guardian_email VARCHAR(255) NULL AFTER guardian_mobile;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND column_name = 'enquiry_source'
    ) THEN
        ALTER TABLE admissions ADD COLUMN enquiry_source VARCHAR(80) NULL AFTER guardian_email;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND column_name = 'submitted_at'
    ) THEN
        ALTER TABLE admissions ADD COLUMN submitted_at DATETIME NULL AFTER submission_notes;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND column_name = 'status'
          AND LOCATE('information_requested', column_type) > 0
    ) THEN
        ALTER TABLE admissions
            MODIFY COLUMN status ENUM('draft','submitted','under_review','information_requested','approved','rejected','enrolled')
            NOT NULL DEFAULT 'draft';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'students'
          AND column_name = 'mobile'
    ) THEN
        ALTER TABLE students ADD COLUMN mobile VARCHAR(20) NULL AFTER gender;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'students'
          AND column_name = 'email'
    ) THEN
        ALTER TABLE students ADD COLUMN email VARCHAR(255) NULL AFTER mobile;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'students'
          AND column_name = 'address_line1'
    ) THEN
        ALTER TABLE students ADD COLUMN address_line1 VARCHAR(255) NULL AFTER photo_path;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'students'
          AND column_name = 'address_line2'
    ) THEN
        ALTER TABLE students ADD COLUMN address_line2 VARCHAR(255) NULL AFTER address_line1;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'students'
          AND column_name = 'city'
    ) THEN
        ALTER TABLE students ADD COLUMN city VARCHAR(120) NULL AFTER address_line2;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'students'
          AND column_name = 'state'
    ) THEN
        ALTER TABLE students ADD COLUMN state VARCHAR(120) NULL AFTER city;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'students'
          AND column_name = 'postal_code'
    ) THEN
        ALTER TABLE students ADD COLUMN postal_code VARCHAR(20) NULL AFTER state;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'students'
          AND column_name = 'country'
    ) THEN
        ALTER TABLE students ADD COLUMN country VARCHAR(120) NULL AFTER postal_code;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'students'
          AND column_name = 'previous_school'
    ) THEN
        ALTER TABLE students ADD COLUMN previous_school VARCHAR(200) NULL AFTER country;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND index_name = 'uq_admissions_reference'
    ) THEN
        ALTER TABLE admissions
            ADD UNIQUE KEY uq_admissions_reference (institution_id, application_reference);
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = 'admissions'
          AND index_name = 'idx_admissions_year_class'
    ) THEN
        ALTER TABLE admissions
            ADD KEY idx_admissions_year_class (institution_id, academic_year_id, class_id, status);
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.referential_constraints
        WHERE constraint_schema = DATABASE()
          AND table_name = 'admissions'
          AND constraint_name = 'fk_admissions_requested_group'
    ) THEN
        ALTER TABLE admissions
            ADD CONSTRAINT fk_admissions_requested_group
            FOREIGN KEY (institution_id, academic_year_id, requested_academic_group_id)
            REFERENCES academic_groups(institution_id, academic_year_id, id)
            ON DELETE RESTRICT;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admission_documents'
          AND column_name = 'verification_status'
    ) THEN
        ALTER TABLE admission_documents
            ADD COLUMN verification_status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admission_documents'
          AND column_name = 'verification_remarks'
    ) THEN
        ALTER TABLE admission_documents ADD COLUMN verification_remarks VARCHAR(1000) NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admission_documents'
          AND column_name = 'verified_by'
    ) THEN
        ALTER TABLE admission_documents ADD COLUMN verified_by BIGINT UNSIGNED NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admission_documents'
          AND column_name = 'verified_at'
    ) THEN
        ALTER TABLE admission_documents ADD COLUMN verified_at DATETIME NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admission_documents'
          AND column_name = 'storage_key'
    ) THEN
        ALTER TABLE admission_documents ADD COLUMN storage_key CHAR(64) NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admission_documents'
          AND column_name = 'file_size'
    ) THEN
        ALTER TABLE admission_documents ADD COLUMN file_size BIGINT UNSIGNED NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admission_documents'
          AND column_name = 'original_extension'
    ) THEN
        ALTER TABLE admission_documents ADD COLUMN original_extension VARCHAR(10) NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = 'admission_documents'
          AND index_name = 'idx_admission_documents_verification'
    ) THEN
        ALTER TABLE admission_documents
            ADD KEY idx_admission_documents_verification (institution_id, admission_id, verification_status);
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = 'admission_documents'
          AND index_name = 'uq_admission_documents_storage_key'
    ) THEN
        ALTER TABLE admission_documents
            ADD UNIQUE KEY uq_admission_documents_storage_key (storage_key);
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.referential_constraints
        WHERE constraint_schema = DATABASE()
          AND table_name = 'admission_documents'
          AND constraint_name = 'fk_admission_documents_verifier'
    ) THEN
        ALTER TABLE admission_documents
            ADD CONSTRAINT fk_admission_documents_verifier
            FOREIGN KEY (institution_id, verified_by) REFERENCES users(institution_id, id)
            ON DELETE RESTRICT;
    END IF;
END//
DELIMITER ;

CALL apply_admission_workflow();
DROP PROCEDURE apply_admission_workflow;

CREATE TABLE IF NOT EXISTS admission_enquiries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    reference_number VARCHAR(40) NOT NULL,
    applicant_name VARCHAR(150) NOT NULL,
    student_name VARCHAR(150) NOT NULL,
    mobile VARCHAR(20) NOT NULL,
    email VARCHAR(255) NULL,
    relationship_to_student VARCHAR(80) NULL,
    academic_year_id BIGINT UNSIGNED NULL,
    course_id BIGINT UNSIGNED NULL,
    class_id BIGINT UNSIGNED NULL,
    source VARCHAR(80) NULL,
    status ENUM('new','contacted','follow_up','converted','closed') NOT NULL DEFAULT 'new',
    follow_up_date DATE NULL,
    notes TEXT NULL,
    admission_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admission_enquiries_institution_id (institution_id, id),
    UNIQUE KEY uq_admission_enquiries_reference (institution_id, reference_number),
    UNIQUE KEY uq_admission_enquiries_application (institution_id, admission_id),
    KEY idx_admission_enquiries_status_followup (institution_id, status, follow_up_date),
    KEY idx_admission_enquiries_mobile (institution_id, mobile),
    CONSTRAINT fk_admission_enquiries_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_admission_enquiries_year FOREIGN KEY (institution_id, academic_year_id) REFERENCES academic_years(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_admission_enquiries_course FOREIGN KEY (institution_id, course_id) REFERENCES courses(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_admission_enquiries_class FOREIGN KEY (institution_id, class_id) REFERENCES classes(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_admission_enquiries_admission FOREIGN KEY (institution_id, admission_id) REFERENCES admissions(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_admission_enquiries_created_by FOREIGN KEY (institution_id, created_by) REFERENCES users(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_admission_enquiries_updated_by FOREIGN KEY (institution_id, updated_by) REFERENCES users(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admission_status_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    admission_id BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(30) NULL,
    to_status VARCHAR(30) NOT NULL,
    remarks TEXT NULL,
    changed_by BIGINT UNSIGNED NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_admission_status_history_application (institution_id, admission_id, changed_at, id),
    CONSTRAINT fk_admission_status_history_admission FOREIGN KEY (institution_id, admission_id) REFERENCES admissions(institution_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_admission_status_history_actor FOREIGN KEY (institution_id, changed_by) REFERENCES users(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_code_settings (
    institution_id BIGINT UNSIGNED NOT NULL,
    prefix VARCHAR(20) NOT NULL DEFAULT 'STU',
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (institution_id),
    CONSTRAINT fk_student_code_settings_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_student_code_settings_actor FOREIGN KEY (institution_id, updated_by) REFERENCES users(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_code_sequences (
    institution_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    next_number BIGINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (institution_id, academic_year_id),
    CONSTRAINT fk_student_code_sequences_year FOREIGN KEY (institution_id, academic_year_id) REFERENCES academic_years(institution_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

