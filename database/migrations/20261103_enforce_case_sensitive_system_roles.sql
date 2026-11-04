-- Align database system-role comparisons with the application's exact role-name checks.
-- Apply after 20261103_enforce_tenant_identity_integrity.sql.

DROP TRIGGER IF EXISTS trg_users_role_tenant_bi;
DROP TRIGGER IF EXISTS trg_users_role_tenant_bu;
DROP TRIGGER IF EXISTS trg_roles_tenant_bi;
DROP TRIGGER IF EXISTS trg_roles_tenant_bu;

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
