CREATE TABLE attendance_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    batch_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    attendance_date DATE NOT NULL,
    status ENUM('present','absent','late') NOT NULL,
    recorded_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_institution_id (institution_id, id),
    UNIQUE KEY uq_attendance_student_batch_date (institution_id, batch_id, student_id, attendance_date),
    KEY idx_attendance_institution_date_batch (institution_id, attendance_date, batch_id),
    KEY idx_attendance_institution_student_date (institution_id, student_id, attendance_date),
    CONSTRAINT fk_attendance_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_attendance_academic_year FOREIGN KEY (institution_id, academic_year_id) REFERENCES academic_years(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_attendance_batch FOREIGN KEY (institution_id, batch_id) REFERENCES batches(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_attendance_student FOREIGN KEY (institution_id, student_id) REFERENCES students(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_attendance_recorder FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_attendance_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rollback only after exporting any recorded attendance:
-- DROP TABLE attendance_records;
