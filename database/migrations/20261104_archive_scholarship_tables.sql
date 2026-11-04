-- Remove scholarship tables from the active product schema without deleting
-- any records. The migration is safe when the academic tables are not present.

DELIMITER //
CREATE PROCEDURE archive_scholarship_tables()
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

    IF active_table_count = 2 AND archived_table_count = 0 THEN
        RENAME TABLE scholarships TO scholarships_archived_20261104,
                     student_scholarship_awards TO student_scholarship_awards_archived_20261104;
    ELSEIF active_table_count = 0 AND archived_table_count = 2 THEN
        DO 0;
    ELSEIF active_table_count <> 0 OR archived_table_count <> 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Scholarship archive conflict: inspect both active and archived tables before proceeding.';
    END IF;
END//
DELIMITER ;

CALL archive_scholarship_tables();
DROP PROCEDURE archive_scholarship_tables;
