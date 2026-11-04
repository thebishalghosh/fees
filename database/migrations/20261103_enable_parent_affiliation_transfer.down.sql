-- Roll back only after reviewing all transfers performed since migration.
-- The original same-institution constraints must be satisfiable before rollback.
DELIMITER //
CREATE PROCEDURE migration_20261103_assert_parent_affiliation_transfer_rollback()
BEGIN
    IF EXISTS (
        SELECT 1
          FROM parents p
          LEFT JOIN users u ON u.id = p.user_id
         WHERE u.id IS NULL OR u.institution_id <> p.institution_id
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Rollback refused: parent and user institutions do not match.';
    END IF;

    IF EXISTS (
        SELECT 1
          FROM user_sessions s
          LEFT JOIN users u ON u.id = s.user_id
         WHERE u.id IS NULL OR u.institution_id <> s.institution_id
    ) OR EXISTS (
        SELECT 1
          FROM user_otp_verifications o
          LEFT JOIN users u ON u.id = o.user_id
         WHERE u.id IS NULL OR u.institution_id <> o.institution_id
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Rollback refused: session or OTP institution mismatches exist.';
    END IF;
END//
DELIMITER ;

CALL migration_20261103_assert_parent_affiliation_transfer_rollback();
DROP PROCEDURE migration_20261103_assert_parent_affiliation_transfer_rollback;

DROP TRIGGER trg_users_parent_affiliation_bu;
DROP TRIGGER trg_parents_affiliation_bi;
DROP TRIGGER trg_parents_affiliation_bu;

ALTER TABLE parents
    DROP FOREIGN KEY fk_parents_user,
    ADD CONSTRAINT fk_parents_user_institution
        FOREIGN KEY (institution_id, user_id)
        REFERENCES users(institution_id, id) ON DELETE CASCADE;

ALTER TABLE user_sessions
    DROP FOREIGN KEY fk_user_sessions_user_institution;

ALTER TABLE user_sessions
    ADD CONSTRAINT fk_user_sessions_user_institution
        FOREIGN KEY (institution_id, user_id)
        REFERENCES users(institution_id, id) ON DELETE CASCADE;

ALTER TABLE user_otp_verifications
    DROP FOREIGN KEY fk_user_otp_user_institution;

ALTER TABLE user_otp_verifications
    ADD CONSTRAINT fk_user_otp_user_institution
        FOREIGN KEY (institution_id, user_id)
        REFERENCES users(institution_id, id) ON DELETE CASCADE;
