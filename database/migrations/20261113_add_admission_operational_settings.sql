-- Add configurable admission sequence starts, typed application timeline events,
-- and institution-level admission email notification preferences.

DELIMITER //
CREATE PROCEDURE apply_admission_operational_settings()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'student_code_sequences'
          AND column_name = 'start_number'
    ) THEN
        ALTER TABLE student_code_sequences
            ADD COLUMN start_number BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER academic_year_id;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admission_status_history'
          AND column_name = 'event_type'
    ) THEN
        ALTER TABLE admission_status_history
            ADD COLUMN event_type VARCHAR(40) NOT NULL DEFAULT 'status_changed' AFTER to_status;
    END IF;
END//
DELIMITER ;

CALL apply_admission_operational_settings();
DROP PROCEDURE apply_admission_operational_settings;

CREATE TABLE IF NOT EXISTS admission_notification_settings (
    institution_id BIGINT UNSIGNED NOT NULL,
    event_type ENUM(
        'application_submitted',
        'information_requested',
        'application_approved',
        'application_rejected',
        'student_registered'
    ) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (institution_id, event_type),
    CONSTRAINT fk_admission_notification_settings_institution
        FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_admission_notification_settings_actor
        FOREIGN KEY (institution_id, updated_by) REFERENCES users(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_admission_notification_settings_enabled CHECK (enabled IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
