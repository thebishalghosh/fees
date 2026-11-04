-- Apply once to an existing MySQL 8.4 database after reviewing the data preflight.
-- DDL statements implicitly commit. Take and verify a full backup first.

DELIMITER //
CREATE PROCEDURE migration_20261103_assert_tenant_identity_integrity()
BEGIN
    IF EXISTS (
        SELECT 1
          FROM user_sessions s
          LEFT JOIN users u ON u.id = s.user_id
         WHERE u.id IS NULL OR u.institution_id <> s.institution_id
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Session tenant preflight failed; investigate mismatched or orphaned rows.';
    END IF;

    IF EXISTS (
        SELECT 1
          FROM user_otp_verifications o
          LEFT JOIN users u ON u.id = o.user_id
         WHERE u.id IS NULL OR u.institution_id <> o.institution_id
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'OTP tenant preflight failed; investigate mismatched or orphaned rows.';
    END IF;

    IF EXISTS (
        SELECT 1
          FROM users u
          LEFT JOIN roles r ON r.id = u.role_id
         WHERE (u.role_id IS NOT NULL AND r.id IS NULL)
            OR (r.id IS NOT NULL AND (
                r.is_system NOT IN (0, 1)
                OR (r.is_system = 1 AND (
                    r.institution_id IS NOT NULL
                    OR BINARY r.name <> BINARY u.user_type
                ))
                OR (r.is_system = 0 AND (
                    r.institution_id IS NULL
                    OR r.institution_id <> u.institution_id
                    OR u.user_type <> 'staff'
                ))
            ))
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'User-role preflight failed; investigate missing, cross-tenant, or incompatible role assignments.';
    END IF;

    IF EXISTS (
        SELECT 1
          FROM roles
         WHERE is_system NOT IN (0, 1)
            OR (is_system = 1 AND (
                institution_id IS NOT NULL
                OR BINARY name NOT IN ('super_admin', 'admin', 'staff', 'parent')
            ))
            OR (is_system = 0 AND institution_id IS NULL)
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Role definition preflight failed; investigate invalid system or institution roles.';
    END IF;

    IF (
        SELECT COUNT(*)
          FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'users'
           AND INDEX_NAME = 'uq_users_institution_id'
           AND ((SEQ_IN_INDEX = 1 AND COLUMN_NAME = 'institution_id')
             OR (SEQ_IN_INDEX = 2 AND COLUMN_NAME = 'id'))
    ) <> 2 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Required users(institution_id,id) unique key is missing or incompatible.';
    END IF;

    IF EXISTS (
        SELECT 1
          FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE()
           AND TRIGGER_NAME IN (
               'trg_users_role_tenant_bi',
               'trg_users_role_tenant_bu',
               'trg_roles_tenant_bi',
               'trg_roles_tenant_bu'
           )
    ) OR EXISTS (
        SELECT 1
          FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND CONSTRAINT_NAME IN (
               'fk_user_sessions_user_institution',
               'fk_user_otp_user_institution'
           )
    ) OR EXISTS (
        SELECT 1
          FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'user_sessions'
           AND INDEX_NAME = 'idx_user_sessions_institution_user'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'A tenant-integrity object already exists; inspect the schema before continuing.';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME = 'user_sessions'
           AND CONSTRAINT_NAME = 'fk_user_sessions_user'
    ) OR NOT EXISTS (
        SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME = 'user_otp_verifications'
           AND CONSTRAINT_NAME = 'fk_user_otp_user'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Expected original user foreign keys were not found; inspect the schema.';
    END IF;
END//
DELIMITER ;

CALL migration_20261103_assert_tenant_identity_integrity();
DROP PROCEDURE migration_20261103_assert_tenant_identity_integrity;

ALTER TABLE user_sessions
    DROP FOREIGN KEY fk_user_sessions_user,
    ADD KEY idx_user_sessions_institution_user (institution_id, user_id),
    ADD CONSTRAINT fk_user_sessions_user_institution
        FOREIGN KEY (institution_id, user_id)
        REFERENCES users(institution_id, id) ON DELETE CASCADE;

ALTER TABLE user_otp_verifications
    DROP FOREIGN KEY fk_user_otp_user,
    ADD CONSTRAINT fk_user_otp_user_institution
        FOREIGN KEY (institution_id, user_id)
        REFERENCES users(institution_id, id) ON DELETE CASCADE;

DELIMITER //
CREATE TRIGGER trg_users_role_tenant_bi
BEFORE INSERT ON users
FOR EACH ROW
BEGIN
    DECLARE role_found TINYINT DEFAULT 1;
    DECLARE role_is_system TINYINT DEFAULT NULL;
    DECLARE role_institution_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE role_name VARCHAR(80) DEFAULT NULL;

    IF NEW.role_id IS NOT NULL THEN
        BEGIN
            DECLARE CONTINUE HANDLER FOR NOT FOUND SET role_found = 0;
            SELECT is_system, institution_id, name
              INTO role_is_system, role_institution_id, role_name
              FROM roles
             WHERE id = NEW.role_id;
        END;

        IF role_found = 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The assigned role does not exist.';
        ELSEIF role_is_system = 1 THEN
            IF role_institution_id IS NOT NULL
               OR BINARY role_name <> BINARY NEW.user_type THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The global system role is incompatible with this user.';
            END IF;
        ELSEIF role_is_system = 0 THEN
            IF role_institution_id IS NULL
               OR role_institution_id <> NEW.institution_id
               OR NEW.user_type <> 'staff' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Custom roles may only be assigned to staff in the same institution.';
            END IF;
        ELSE
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The assigned role has an invalid system-role state.';
        END IF;
    END IF;
END//

CREATE TRIGGER trg_users_role_tenant_bu
BEFORE UPDATE ON users
FOR EACH ROW
BEGIN
    DECLARE role_found TINYINT DEFAULT 1;
    DECLARE role_is_system TINYINT DEFAULT NULL;
    DECLARE role_institution_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE role_name VARCHAR(80) DEFAULT NULL;

    IF NEW.role_id IS NOT NULL THEN
        BEGIN
            DECLARE CONTINUE HANDLER FOR NOT FOUND SET role_found = 0;
            SELECT is_system, institution_id, name
              INTO role_is_system, role_institution_id, role_name
              FROM roles
             WHERE id = NEW.role_id;
        END;

        IF role_found = 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The assigned role does not exist.';
        ELSEIF role_is_system = 1 THEN
            IF role_institution_id IS NOT NULL
               OR BINARY role_name <> BINARY NEW.user_type THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The global system role is incompatible with this user.';
            END IF;
        ELSEIF role_is_system = 0 THEN
            IF role_institution_id IS NULL
               OR role_institution_id <> NEW.institution_id
               OR NEW.user_type <> 'staff' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Custom roles may only be assigned to staff in the same institution.';
            END IF;
        ELSE
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The assigned role has an invalid system-role state.';
        END IF;
    END IF;
END//

CREATE TRIGGER trg_roles_tenant_bi
BEFORE INSERT ON roles
FOR EACH ROW
BEGIN
    IF NEW.is_system NOT IN (0, 1)
       OR (NEW.is_system = 1 AND (
            NEW.institution_id IS NOT NULL
            OR BINARY NEW.name NOT IN ('super_admin', 'admin', 'staff', 'parent')
       ))
       OR (NEW.is_system = 0 AND NEW.institution_id IS NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The role has an invalid system or institution association.';
    END IF;
END//

CREATE TRIGGER trg_roles_tenant_bu
BEFORE UPDATE ON roles
FOR EACH ROW
BEGIN
    IF NEW.is_system NOT IN (0, 1)
       OR (NEW.is_system = 1 AND (
            NEW.institution_id IS NOT NULL
            OR BINARY NEW.name NOT IN ('super_admin', 'admin', 'staff', 'parent')
       ))
       OR (NEW.is_system = 0 AND NEW.institution_id IS NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The role has an invalid system or institution association.';
    END IF;

    IF EXISTS (
        SELECT 1
          FROM users u
         WHERE u.role_id = OLD.id
           AND (
                (NEW.is_system = 1 AND (
                    NEW.institution_id IS NOT NULL
                    OR BINARY u.user_type <> BINARY NEW.name
                ))
                OR
                (NEW.is_system = 0 AND (
                    NEW.institution_id IS NULL
                    OR u.institution_id <> NEW.institution_id
                    OR u.user_type <> 'staff'
                ))
           )
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The role change would invalidate an assigned user.';
    END IF;
END//
DELIMITER ;

-- Post-migration checks: both queries must return zero.
SELECT COUNT(*) AS mismatched_sessions
  FROM user_sessions s
  JOIN users u ON u.id = s.user_id
 WHERE u.institution_id <> s.institution_id;
SELECT COUNT(*) AS mismatched_otps
  FROM user_otp_verifications o
  JOIN users u ON u.id = o.user_id
 WHERE u.institution_id <> o.institution_id;
