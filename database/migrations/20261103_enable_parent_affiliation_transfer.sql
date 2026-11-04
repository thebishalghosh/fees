-- Apply after the tenant identity integrity migrations on MySQL 8.4.
-- Take and verify a full backup first; ALTER TABLE implicitly commits.
DELIMITER //
CREATE PROCEDURE migration_20261103_assert_parent_affiliation_transfer()
BEGIN
    IF EXISTS (
        SELECT 1
          FROM parents p
          LEFT JOIN users u ON u.id = p.user_id
          LEFT JOIN roles r ON r.id = u.role_id
         WHERE u.id IS NULL OR u.institution_id <> p.institution_id
            OR u.user_type <> 'parent' OR r.id IS NULL OR r.is_system <> 1
            OR r.institution_id IS NOT NULL OR BINARY r.name <> BINARY 'parent'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Parent affiliation preflight failed; resolve parent/account institution or role mismatches first.';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME = 'parents'
           AND CONSTRAINT_NAME = 'fk_parents_user_institution'
    ) OR NOT EXISTS (
        SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME = 'user_sessions'
           AND CONSTRAINT_NAME = 'fk_user_sessions_user_institution'
    ) OR NOT EXISTS (
        SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME = 'user_otp_verifications'
           AND CONSTRAINT_NAME = 'fk_user_otp_user_institution'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Expected tenant identity constraints were not found; inspect the schema before continuing.';
    END IF;

    IF EXISTS (
        SELECT 1 FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE()
           AND TRIGGER_NAME IN (
               'trg_users_parent_affiliation_bu',
               'trg_parents_affiliation_bi',
               'trg_parents_affiliation_bu'
           )
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Parent affiliation triggers already exist; inspect the schema before continuing.';
    END IF;
END//
DELIMITER ;

CALL migration_20261103_assert_parent_affiliation_transfer();
DROP PROCEDURE migration_20261103_assert_parent_affiliation_transfer;

ALTER TABLE user_sessions
    DROP FOREIGN KEY fk_user_sessions_user_institution;

ALTER TABLE user_sessions
    ADD CONSTRAINT fk_user_sessions_user_institution
        FOREIGN KEY (institution_id, user_id)
        REFERENCES users(institution_id, id) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE user_otp_verifications
    DROP FOREIGN KEY fk_user_otp_user_institution;

ALTER TABLE user_otp_verifications
    ADD CONSTRAINT fk_user_otp_user_institution
        FOREIGN KEY (institution_id, user_id)
        REFERENCES users(institution_id, id) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE parents
    DROP FOREIGN KEY fk_parents_user_institution,
    ADD CONSTRAINT fk_parents_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

DELIMITER //
CREATE TRIGGER trg_users_parent_affiliation_bu
BEFORE UPDATE ON users
FOR EACH ROW
BEGIN
    IF EXISTS (SELECT 1 FROM parents p WHERE p.user_id = OLD.id)
       AND (
           NEW.user_type <> 'parent'
           OR NOT EXISTS (
               SELECT 1 FROM roles r
                WHERE r.id = NEW.role_id AND r.is_system = 1
                  AND r.institution_id IS NULL
                  AND BINARY r.name = BINARY 'parent'
           )
       ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'A parent profile must remain associated with a parent account.';
    END IF;

    IF OLD.institution_id <> NEW.institution_id
       AND OLD.user_type = 'parent'
       AND EXISTS (SELECT 1 FROM parents p WHERE p.user_id = OLD.id)
       AND COALESCE(@fees_parent_affiliation_transfer_user_id, 0) <> OLD.id THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Parent account institutions can only change through the affiliation workflow.';
    END IF;
END//

CREATE TRIGGER trg_parents_affiliation_bi
BEFORE INSERT ON parents
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM users u
        JOIN roles r ON r.id = u.role_id
         WHERE u.id = NEW.user_id AND u.institution_id = NEW.institution_id
           AND u.user_type = 'parent'
           AND r.is_system = 1 AND r.institution_id IS NULL
           AND BINARY r.name = BINARY 'parent'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'The parent and account must belong to the same institution.';
    END IF;
END//

CREATE TRIGGER trg_parents_affiliation_bu
BEFORE UPDATE ON parents
FOR EACH ROW
BEGIN
    IF NEW.institution_id <> OLD.institution_id
       AND COALESCE(@fees_parent_affiliation_transfer_user_id, 0) <> NEW.user_id THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Parent institutions can only change through the affiliation workflow.';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM users u
        JOIN roles r ON r.id = u.role_id
         WHERE u.id = NEW.user_id AND u.institution_id = NEW.institution_id
           AND u.user_type = 'parent'
           AND r.is_system = 1 AND r.institution_id IS NULL
           AND BINARY r.name = BINARY 'parent'
    ) AND COALESCE(@fees_parent_affiliation_transfer_user_id, 0) <> NEW.user_id THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'The parent and account must belong to the same institution.';
    END IF;
END//
DELIMITER ;
