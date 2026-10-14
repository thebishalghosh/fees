-- Apply after confirming examinations has not already received these columns/indexes.
-- Legacy examinations retain NULL academic year / maximum marks and remain unpublished.
ALTER TABLE examinations
    ADD COLUMN academic_year_id BIGINT UNSIGNED NULL AFTER course_id,
    ADD COLUMN max_marks DECIMAL(8,2) NULL AFTER exam_date,
    ADD COLUMN results_published_at DATETIME NULL AFTER max_marks,
    ADD UNIQUE KEY uq_examinations_institution_id (institution_id, id),
    ADD KEY idx_examinations_institution_year_date (institution_id, academic_year_id, exam_date),
    ADD CONSTRAINT fk_examinations_academic_year
        FOREIGN KEY (institution_id, academic_year_id)
        REFERENCES academic_years(institution_id, id) ON DELETE RESTRICT;

CREATE TABLE examination_results (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    examination_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    marks_obtained DECIMAL(8,2) NOT NULL,
    recorded_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_examination_results_student (institution_id, examination_id, student_id),
    KEY idx_examination_results_student (institution_id, student_id, examination_id),
    CONSTRAINT fk_examination_results_exam
        FOREIGN KEY (institution_id, examination_id)
        REFERENCES examinations(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_examination_results_student
        FOREIGN KEY (institution_id, student_id)
        REFERENCES students(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_examination_results_recorder
        FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rollback only after exporting any entered results:
-- DROP TABLE examination_results;
-- ALTER TABLE examinations
--     DROP FOREIGN KEY fk_examinations_academic_year,
--     DROP INDEX idx_examinations_institution_year_date,
--     DROP INDEX uq_examinations_institution_id,
--     DROP COLUMN results_published_at,
--     DROP COLUMN max_marks,
--     DROP COLUMN academic_year_id;
