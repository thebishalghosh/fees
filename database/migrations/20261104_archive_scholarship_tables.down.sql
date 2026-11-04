-- Restore the archived scholarship tables with all original rows and keys.
-- Do not restore application routes or fee-assignment scholarship behavior.

DELIMITER //
CREATE PROCEDURE restore_scholarship_tables()
BEGIN
    DECLARE active_table_count INT DEFAULT 0;
    DECLARE archived_table_count INT DEFAULT 0;

    SELECT COUNT(*) INTO active_table_count
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name IN ('scholarships', 'student_scholarship_awards');
    SELECT COUNT(*) INTO archived_table_count
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name IN (
          'scholarships_archived_20261104',
          'student_scholarship_awards_archived_20261104'
      );

    IF active_table_count = 0 AND archived_table_count = 2 THEN
        RENAME TABLE scholarships_archived_20261104 TO scholarships,
                     student_scholarship_awards_archived_20261104 TO student_scholarship_awards;
    ELSEIF active_table_count = 2 AND archived_table_count = 0 THEN
        DO 0;
    ELSEIF active_table_count <> 0 OR archived_table_count <> 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Scholarship restore conflict: inspect both active and archived tables before proceeding.';
    END IF;
END//
DELIMITER ;

CALL restore_scholarship_tables();
DROP PROCEDURE restore_scholarship_tables;
