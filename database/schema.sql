-- Parent & Student Fees Management System
-- MySQL schema for procedural PHP / MySQLi application
-- Laragon runtime: MySQL 8.4.3-winx64 (verified from C:/laragon/bin/mysql/mysql-8.4.3-winx64)

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS institutions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    short_name VARCHAR(80) NULL,
    code VARCHAR(50) NULL,
    institution_type VARCHAR(40) NULL,
    email VARCHAR(255) NULL,
    phone VARCHAR(30) NULL,
    address_line1 VARCHAR(255) NULL,
    address_line2 VARCHAR(255) NULL,
    city VARCHAR(120) NULL,
    state VARCHAR(120) NULL,
    postal_code VARCHAR(20) NULL,
    country VARCHAR(120) NOT NULL DEFAULT 'India',
    website VARCHAR(255) NULL,
    logo_path VARCHAR(255) NULL,
    principal_name VARCHAR(150) NULL,
    principal_email VARCHAR(255) NULL,
    principal_phone VARCHAR(30) NULL,
    registration_number VARCHAR(120) NULL,
    affiliation_body VARCHAR(255) NULL,
    affiliation_number VARCHAR(120) NULL,
    established_year SMALLINT UNSIGNED NULL,
    currency_code CHAR(3) NOT NULL DEFAULT 'INR',
    status ENUM('active','inactive','trial','archived') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_institutions_code (code),
    KEY idx_institutions_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_admins (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('active','pending','suspended','disabled') NOT NULL DEFAULT 'pending',
    last_login_at DATETIME NULL,
    created_by_platform_admin_id BIGINT UNSIGNED NULL,
    is_initial_bootstrap TINYINT(1) NOT NULL DEFAULT 0,
    bootstrap_slot TINYINT(1) GENERATED ALWAYS AS (
        CASE WHEN is_initial_bootstrap = 1 THEN 1 ELSE NULL END
    ) STORED,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_platform_admins_email (email),
    UNIQUE KEY uq_platform_admins_bootstrap_slot (bootstrap_slot),
    KEY idx_platform_admins_status (status),
    KEY idx_platform_admins_created_by (created_by_platform_admin_id),
    CONSTRAINT chk_platform_admins_initial_bootstrap CHECK (is_initial_bootstrap IN (0, 1)),
    CONSTRAINT fk_platform_admins_creator FOREIGN KEY (created_by_platform_admin_id)
        REFERENCES platform_admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_admin_permission_grants (
    platform_admin_id BIGINT UNSIGNED NOT NULL,
    permission_key VARCHAR(80) NOT NULL,
    granted_by_platform_admin_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (platform_admin_id, permission_key),
    KEY idx_platform_permission_grants_permission (permission_key, platform_admin_id),
    CONSTRAINT fk_platform_permission_grants_admin FOREIGN KEY (platform_admin_id)
        REFERENCES platform_admins(id) ON DELETE CASCADE,
    CONSTRAINT fk_platform_permission_grants_grantor FOREIGN KEY (granted_by_platform_admin_id)
        REFERENCES platform_admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_bootstrap_state (
    id TINYINT UNSIGNED NOT NULL,
    initialized_at DATETIME NULL,
    initialized_by_platform_admin_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    CONSTRAINT chk_platform_bootstrap_state_singleton CHECK (id = 1),
    CONSTRAINT fk_platform_bootstrap_state_admin FOREIGN KEY (initialized_by_platform_admin_id)
        REFERENCES platform_admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO platform_bootstrap_state (id) VALUES (1)
ON DUPLICATE KEY UPDATE id = VALUES(id);

CREATE TABLE IF NOT EXISTS platform_login_rate_limits (
    scope_type ENUM('account','ip') NOT NULL,
    scope_hash CHAR(64) NOT NULL,
    window_started_at DATETIME NOT NULL,
    failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (scope_type, scope_hash),
    KEY idx_platform_login_rate_limits_window (window_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    platform_admin_id BIGINT UNSIGNED NOT NULL,
    session_identifier CHAR(64) NOT NULL,
    session_token_hash CHAR(64) NOT NULL,
    user_agent VARCHAR(512) NULL,
    ip_address VARCHAR(45) NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_platform_sessions_identifier (session_identifier),
    UNIQUE KEY uq_platform_sessions_token_hash (session_token_hash),
    KEY idx_platform_sessions_admin (platform_admin_id, expires_at),
    KEY idx_platform_sessions_expiry (expires_at),
    CONSTRAINT fk_platform_sessions_admin FOREIGN KEY (platform_admin_id)
        REFERENCES platform_admins(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_platform_admin_id BIGINT UNSIGNED NULL,
    actor_name VARCHAR(150) NULL,
    action VARCHAR(100) NOT NULL,
    target_type VARCHAR(80) NULL,
    target_id BIGINT UNSIGNED NULL,
    target_institution_id BIGINT UNSIGNED NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(512) NULL,
    correlation_id CHAR(36) NULL,
    metadata JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_platform_audit_actor (actor_platform_admin_id, created_at),
    KEY idx_platform_audit_target_institution (target_institution_id, created_at),
    KEY idx_platform_audit_action (action, created_at),
    KEY idx_platform_audit_created (created_at, id),
    CONSTRAINT fk_platform_audit_actor FOREIGN KEY (actor_platform_admin_id)
        REFERENCES platform_admins(id) ON DELETE SET NULL,
    CONSTRAINT fk_platform_audit_target_institution FOREIGN KEY (target_institution_id)
        REFERENCES institutions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(80) NOT NULL,
    description VARCHAR(255) NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    institution_id BIGINT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    system_name VARCHAR(80) GENERATED ALWAYS AS (
        CASE WHEN is_system = 1 THEN name ELSE NULL END
    ) STORED,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_institution_name (institution_id, name),
    UNIQUE KEY uq_roles_system_name (system_name),
    KEY idx_roles_institution_active (institution_id, is_active),
    CONSTRAINT fk_roles_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    permission_key VARCHAR(125) NOT NULL,
    description VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_permissions_key (permission_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NULL,
    user_type ENUM('super_admin','admin','staff','parent') NOT NULL DEFAULT 'parent',
    full_name VARCHAR(150) NOT NULL,
    username VARCHAR(100) NULL,
    email VARCHAR(255) NULL,
    mobile VARCHAR(20) NULL,
    password_hash VARCHAR(255) NULL,
    otp_secret VARCHAR(255) NULL,
    otp_last_sent_at DATETIME NULL,
    mobile_verified_at DATETIME NULL,
    email_verified_at DATETIME NULL,
    status ENUM('pending','active','inactive','suspended','blocked') NOT NULL DEFAULT 'pending',
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_institution_id (institution_id, id),
    UNIQUE KEY uq_users_username_institution (institution_id, username),
    UNIQUE KEY uq_users_email_institution (institution_id, email),
    UNIQUE KEY uq_users_mobile_institution (institution_id, mobile),
    KEY idx_users_institution_status (institution_id, status),
    KEY idx_users_role (role_id),
    CONSTRAINT fk_users_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS user_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    institution_id BIGINT UNSIGNED NOT NULL,
    session_identifier CHAR(32) NOT NULL,
    session_token_hash CHAR(64) NOT NULL,
    user_agent VARCHAR(512) NULL,
    ip_address VARCHAR(45) NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_sessions_identifier (session_identifier),
    UNIQUE KEY uq_user_sessions_token_hash (session_token_hash),
    KEY idx_user_sessions_user_expiry (user_id, expires_at),
    KEY idx_user_sessions_institution_user (institution_id, user_id),
    KEY idx_user_sessions_institution (institution_id, expires_at),
    CONSTRAINT fk_user_sessions_user_institution FOREIGN KEY (institution_id, user_id)
        REFERENCES users(institution_id, id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_user_sessions_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_otp_verifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    purpose ENUM('mobile_verification','email_verification','login','password_reset','payment_verification') NOT NULL,
    request_id CHAR(36) NOT NULL,
    otp_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    verified_at DATETIME NULL,
    attempts_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
    status ENUM('pending','verified','expired','invalidated','failed') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_otp_request (institution_id, user_id, purpose, request_id),
    KEY idx_user_otp_user_status (user_id, purpose, status, expires_at),
    CONSTRAINT fk_user_otp_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_otp_user_institution FOREIGN KEY (institution_id, user_id)
        REFERENCES users(institution_id, id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS parents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    mobile VARCHAR(20) NOT NULL,
    alternate_mobile VARCHAR(20) NULL,
    email VARCHAR(255) NULL,
    relationship_to_student VARCHAR(80) NULL,
    address_line1 VARCHAR(255) NULL,
    address_line2 VARCHAR(255) NULL,
    city VARCHAR(120) NULL,
    state VARCHAR(120) NULL,
    postal_code VARCHAR(20) NULL,
    country VARCHAR(120) NOT NULL DEFAULT 'India',
    emergency_contact VARCHAR(30) NULL,
    status ENUM('pending_verification','active','inactive','suspended') NOT NULL DEFAULT 'pending_verification',
    mobile_verified_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_parents_institution_id (institution_id, id),
    UNIQUE KEY uq_parents_user (user_id),
    UNIQUE KEY uq_parents_institution_mobile (institution_id, mobile),
    KEY idx_parents_status (institution_id, status),
    CONSTRAINT fk_parents_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_parents_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS academic_years (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status ENUM('draft','active','closed') NOT NULL DEFAULT 'draft',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_academic_years_institution_id (institution_id, id),
    UNIQUE KEY uq_academic_years_institution_name (institution_id, name),
    KEY idx_academic_years_institution_status (institution_id, status),
    CONSTRAINT fk_academic_years_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS courses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    code VARCHAR(50) NULL,
    description TEXT NULL,
    duration_months INT UNSIGNED NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_courses_institution_id (institution_id, id),
    UNIQUE KEY uq_courses_institution_code (institution_id, code),
    KEY idx_courses_institution_status (institution_id, status),
    CONSTRAINT fk_courses_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS classes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    code VARCHAR(50) NULL,
    description TEXT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_classes_institution_id (institution_id, id),
    UNIQUE KEY uq_classes_institution_code (institution_id, code),
    KEY idx_classes_institution_status (institution_id, status),
    CONSTRAINT fk_classes_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    code VARCHAR(50) NULL,
    start_time TIME NULL,
    end_time TIME NULL,
    capacity INT UNSIGNED NULL,
    status ENUM('active','inactive','full') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_batches_institution_id (institution_id, id),
    UNIQUE KEY uq_batches_institution_code (institution_id, code),
    KEY idx_batches_course_class (course_id, class_id),
    CONSTRAINT fk_batches_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_batches_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE RESTRICT,
    CONSTRAINT fk_batches_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS instructors (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    full_name VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NULL,
    email VARCHAR(255) NULL,
    specialization VARCHAR(255) NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_instructors_institution_id (institution_id, id),
    UNIQUE KEY uq_instructors_institution_user (institution_id, user_id),
    KEY idx_instructors_institution_status (institution_id, status),
    CONSTRAINT fk_instructors_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_instructors_user FOREIGN KEY (institution_id, user_id) REFERENCES users(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS course_instructors (
    course_id BIGINT UNSIGNED NOT NULL,
    instructor_id BIGINT UNSIGNED NOT NULL,
    institution_id BIGINT UNSIGNED NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (course_id, instructor_id),
    KEY idx_course_instructors_institution (institution_id, course_id),
    CONSTRAINT fk_course_instructors_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_course_instructors_course FOREIGN KEY (institution_id, course_id) REFERENCES courses(institution_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_course_instructors_instructor FOREIGN KEY (institution_id, instructor_id) REFERENCES instructors(institution_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS students (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NULL,
    course_id BIGINT UNSIGNED NULL,
    class_id BIGINT UNSIGNED NULL,
    batch_id BIGINT UNSIGNED NULL,
    student_code VARCHAR(50) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    date_of_birth DATE NULL,
    gender ENUM('male','female','other','prefer_not_to_say') NULL,
    photo_path VARCHAR(255) NULL,
    admission_date DATE NOT NULL,
    status ENUM('prospect','admitted','active','inactive','graduated','withdrawn') NOT NULL DEFAULT 'prospect',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_students_institution_id (institution_id, id),
    UNIQUE KEY uq_students_institution_code (institution_id, student_code),
    KEY idx_students_institution_status (institution_id, status),
    KEY idx_students_academic_year (academic_year_id),
    KEY idx_students_batch (batch_id),
    CONSTRAINT fk_students_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_students_academic_year FOREIGN KEY (institution_id, academic_year_id) REFERENCES academic_years(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_students_course FOREIGN KEY (institution_id, course_id) REFERENCES courses(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_students_class FOREIGN KEY (institution_id, class_id) REFERENCES classes(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_students_batch FOREIGN KEY (institution_id, batch_id) REFERENCES batches(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_records (
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

CREATE TABLE IF NOT EXISTS parent_student_relationships (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    parent_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    relationship_type ENUM('father','mother','guardian','other') NOT NULL DEFAULT 'guardian',
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    can_view_academics TINYINT(1) NOT NULL DEFAULT 1,
    can_view_fees TINYINT(1) NOT NULL DEFAULT 1,
    can_make_payments TINYINT(1) NOT NULL DEFAULT 1,
    status ENUM('active','inactive','revoked') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_parent_student_relationship (institution_id, parent_id, student_id),
    KEY idx_parent_student_parent (parent_id, status),
    KEY idx_parent_student_student (student_id, status),
    CONSTRAINT fk_parent_student_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_parent_student_parent FOREIGN KEY (institution_id, parent_id) REFERENCES parents(institution_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_parent_student_student FOREIGN KEY (institution_id, student_id) REFERENCES students(institution_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NULL,
    parent_id BIGINT UNSIGNED NULL,
    applicant_name VARCHAR(150) NOT NULL,
    applicant_mobile VARCHAR(20) NOT NULL,
    applicant_email VARCHAR(255) NULL,
    relationship_to_student VARCHAR(80) NULL,
    student_name VARCHAR(150) NOT NULL,
    date_of_birth DATE NULL,
    admission_date DATE NULL,
    course_id BIGINT UNSIGNED NULL,
    class_id BIGINT UNSIGNED NULL,
    batch_id BIGINT UNSIGNED NULL,
    academic_year_id BIGINT UNSIGNED NULL,
    student_enrolment_id BIGINT UNSIGNED NULL,
    academic_group_id BIGINT UNSIGNED NULL,
    student_enrolment_placement_id BIGINT UNSIGNED NULL,
    converted_at DATETIME NULL,
    converted_by BIGINT UNSIGNED NULL,
    status ENUM('draft','submitted','under_review','approved','rejected','enrolled') NOT NULL DEFAULT 'draft',
    submission_notes TEXT NULL,
    review_notes TEXT NULL,
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admissions_institution_id (institution_id, id),
    UNIQUE KEY uq_admissions_enrolment_conversion (institution_id, student_enrolment_id),
    UNIQUE KEY uq_admissions_placement_conversion (institution_id, student_enrolment_placement_id),
    KEY idx_admissions_institution_status (institution_id, status),
    KEY idx_admissions_parent (parent_id),
    KEY idx_admissions_student (student_id),
    CONSTRAINT fk_admissions_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_admissions_student FOREIGN KEY (institution_id, student_id) REFERENCES students(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_admissions_parent FOREIGN KEY (institution_id, parent_id) REFERENCES parents(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_admissions_course FOREIGN KEY (institution_id, course_id) REFERENCES courses(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_admissions_class FOREIGN KEY (institution_id, class_id) REFERENCES classes(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_admissions_batch FOREIGN KEY (institution_id, batch_id) REFERENCES batches(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_admissions_academic_year FOREIGN KEY (institution_id, academic_year_id) REFERENCES academic_years(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_admissions_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admission_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    admission_id BIGINT UNSIGNED NOT NULL,
    document_type VARCHAR(80) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    mime_type VARCHAR(120) NULL,
    uploaded_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_admission_documents_admission (admission_id),
    CONSTRAINT fk_admission_documents_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_admission_documents_admission FOREIGN KEY (institution_id, admission_id) REFERENCES admissions(institution_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_admission_documents_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fee_categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    code VARCHAR(50) NULL,
    description TEXT NULL,
    is_mandatory TINYINT(1) NOT NULL DEFAULT 1,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fee_categories_institution_id (institution_id, id),
    UNIQUE KEY uq_fee_categories_institution_code (institution_id, code),
    KEY idx_fee_categories_institution_status (institution_id, status),
    CONSTRAINT fk_fee_categories_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fee_structures (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NULL,
    class_id BIGINT UNSIGNED NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('draft','active','inactive') NOT NULL DEFAULT 'draft',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fee_structures_institution_id (institution_id, id),
    KEY idx_fee_structures_institution_status (institution_id, status),
    CONSTRAINT fk_fee_structures_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_fee_structures_academic_year FOREIGN KEY (institution_id, academic_year_id) REFERENCES academic_years(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_fee_structures_course FOREIGN KEY (institution_id, course_id) REFERENCES courses(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_fee_structures_class FOREIGN KEY (institution_id, class_id) REFERENCES classes(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fee_structure_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    fee_structure_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    is_optional TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fee_structure_items (institution_id, fee_structure_id, category_id),
    KEY idx_fee_structure_items_structure (fee_structure_id),
    CONSTRAINT fk_fee_structure_items_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_fee_structure_items_structure FOREIGN KEY (institution_id, fee_structure_id) REFERENCES fee_structures(institution_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_fee_structure_items_category FOREIGN KEY (institution_id, category_id) REFERENCES fee_categories(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_plans (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(120) NOT NULL,
    plan_type ENUM('monthly','quarterly','half_yearly','annual','custom') NOT NULL,
    installment_count INT UNSIGNED NOT NULL DEFAULT 1,
    description TEXT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payment_plans_institution_id (institution_id, id),
    UNIQUE KEY uq_payment_plans_institution_code (institution_id, code),
    KEY idx_payment_plans_institution_status (institution_id, status),
    CONSTRAINT fk_payment_plans_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_fee_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    fee_structure_id BIGINT UNSIGNED NOT NULL,
    payment_plan_id BIGINT UNSIGNED NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    total_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_adjustments DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    outstanding_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('draft','active','completed','suspended') NOT NULL DEFAULT 'draft',
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_student_fee_assignments_institution_id (institution_id, id),
    UNIQUE KEY uq_student_fee_assignment (institution_id, student_id, academic_year_id, fee_structure_id),
    KEY idx_student_fee_assignment_student (student_id, status),
    CONSTRAINT fk_student_fee_assignment_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_student_fee_assignment_student FOREIGN KEY (institution_id, student_id) REFERENCES students(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_student_fee_assignment_fee_structure FOREIGN KEY (institution_id, fee_structure_id) REFERENCES fee_structures(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_student_fee_assignment_payment_plan FOREIGN KEY (institution_id, payment_plan_id) REFERENCES payment_plans(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_student_fee_assignment_academic_year FOREIGN KEY (institution_id, academic_year_id) REFERENCES academic_years(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS installments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    student_fee_assignment_id BIGINT UNSIGNED NOT NULL,
    installment_number INT UNSIGNED NOT NULL,
    due_date DATE NOT NULL,
    due_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    penalty_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    adjustment_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('pending','partial','paid','overdue','cancelled') NOT NULL DEFAULT 'pending',
    paid_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_installments_institution_id (institution_id, id),
    UNIQUE KEY uq_installments_assignment_number (student_fee_assignment_id, installment_number),
    KEY idx_installments_due_date (due_date, status),
    KEY idx_installments_student_assignment (institution_id, student_fee_assignment_id),
    CONSTRAINT fk_installments_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_installments_assignment FOREIGN KEY (institution_id, student_fee_assignment_id) REFERENCES student_fee_assignments(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fee_discounts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    student_fee_assignment_id BIGINT UNSIGNED NOT NULL,
    installment_id BIGINT UNSIGNED NULL,
    discount_type VARCHAR(80) NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    reason VARCHAR(255) NULL,
    approved_by BIGINT UNSIGNED NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_fee_discounts_assignment (student_fee_assignment_id),
    CONSTRAINT fk_fee_discounts_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_fee_discounts_assignment FOREIGN KEY (institution_id, student_fee_assignment_id) REFERENCES student_fee_assignments(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_fee_discounts_installment FOREIGN KEY (institution_id, installment_id) REFERENCES installments(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_fee_discounts_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fee_adjustments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    student_fee_assignment_id BIGINT UNSIGNED NOT NULL,
    installment_id BIGINT UNSIGNED NULL,
    adjustment_type ENUM('waiver','penalty','credit','debit','correction') NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    reason VARCHAR(255) NOT NULL,
    approved_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_fee_adjustments_assignment (student_fee_assignment_id),
    CONSTRAINT fk_fee_adjustments_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_fee_adjustments_assignment FOREIGN KEY (institution_id, student_fee_assignment_id) REFERENCES student_fee_assignments(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_fee_adjustments_installment FOREIGN KEY (institution_id, installment_id) REFERENCES installments(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_fee_adjustments_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    parent_id BIGINT UNSIGNED NOT NULL,
    installment_id BIGINT UNSIGNED NULL,
    order_number VARCHAR(80) NOT NULL,
    gateway ENUM('manual','razorpay','payu','cashfree','stripe') NOT NULL DEFAULT 'manual',
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    currency CHAR(3) NOT NULL DEFAULT 'INR',
    status ENUM('pending','created','authorized','paid','failed','cancelled','expired','refunded') NOT NULL DEFAULT 'pending',
    idempotency_key VARCHAR(128) NOT NULL,
    gateway_order_id VARCHAR(120) NULL,
    expires_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payment_orders_institution_id (institution_id, id),
    UNIQUE KEY uq_payment_orders_number (institution_id, order_number),
    UNIQUE KEY uq_payment_orders_gateway_order (gateway, gateway_order_id),
    UNIQUE KEY uq_payment_orders_idempotency (institution_id, idempotency_key),
    KEY idx_payment_orders_student_status (student_id, status),
    KEY idx_payment_orders_parent (parent_id, status),
    CONSTRAINT fk_payment_orders_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE RESTRICT,
    CONSTRAINT fk_payment_orders_student FOREIGN KEY (institution_id, student_id) REFERENCES students(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_payment_orders_parent FOREIGN KEY (institution_id, parent_id) REFERENCES parents(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_payment_orders_installment FOREIGN KEY (institution_id, installment_id) REFERENCES installments(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_payment_orders_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    payment_order_id BIGINT UNSIGNED NOT NULL,
    attempt_number INT UNSIGNED NOT NULL DEFAULT 1,
    gateway ENUM('manual','razorpay','payu','cashfree','stripe') NOT NULL,
    gateway_reference VARCHAR(200) NULL,
    status ENUM('initiated','pending','success','failed','cancelled') NOT NULL DEFAULT 'initiated',
    request_payload JSON NULL,
    response_payload JSON NULL,
    error_message VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payment_attempt_order_number (payment_order_id, attempt_number),
    KEY idx_payment_attempts_order_status (payment_order_id, status),
    CONSTRAINT fk_payment_attempts_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE RESTRICT,
    CONSTRAINT fk_payment_attempts_order FOREIGN KEY (institution_id, payment_order_id) REFERENCES payment_orders(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    payment_order_id BIGINT UNSIGNED NOT NULL,
    installment_id BIGINT UNSIGNED NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    parent_id BIGINT UNSIGNED NOT NULL,
    gateway ENUM('manual','razorpay','payu','cashfree','stripe') NOT NULL,
    gateway_transaction_id VARCHAR(200) NULL,
    gateway_reference VARCHAR(200) NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    currency CHAR(3) NOT NULL DEFAULT 'INR',
    status ENUM('pending','success','failed','refunded','partial_refund') NOT NULL DEFAULT 'pending',
    verified_at DATETIME NULL,
    captured_at DATETIME NULL,
    idempotency_key VARCHAR(128) NOT NULL,
    metadata JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payments_institution_id (institution_id, id),
    UNIQUE KEY uq_payments_gateway_txn (institution_id, gateway, gateway_transaction_id),
    UNIQUE KEY uq_payments_order (payment_order_id),
    UNIQUE KEY uq_payments_idempotency (institution_id, idempotency_key),
    KEY idx_payments_student_status (student_id, status),
    KEY idx_payments_installment (installment_id, status),
    CONSTRAINT fk_payments_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE RESTRICT,
    CONSTRAINT fk_payments_order FOREIGN KEY (institution_id, payment_order_id) REFERENCES payment_orders(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_payments_installment FOREIGN KEY (institution_id, installment_id) REFERENCES installments(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_payments_student FOREIGN KEY (institution_id, student_id) REFERENCES students(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_payments_parent FOREIGN KEY (institution_id, parent_id) REFERENCES parents(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS refunds (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    payment_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    parent_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    reason VARCHAR(255) NOT NULL,
    status ENUM('requested','approved','processed','rejected') NOT NULL DEFAULT 'requested',
    processed_by BIGINT UNSIGNED NULL,
    processed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_refunds_institution_id (institution_id, id),
    KEY idx_refunds_payment (payment_id),
    CONSTRAINT fk_refunds_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE RESTRICT,
    CONSTRAINT fk_refunds_payment FOREIGN KEY (institution_id, payment_id) REFERENCES payments(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_refunds_student FOREIGN KEY (institution_id, student_id) REFERENCES students(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_refunds_parent FOREIGN KEY (institution_id, parent_id) REFERENCES parents(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_refunds_processor FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS receipts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    payment_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    parent_id BIGINT UNSIGNED NOT NULL,
    receipt_number VARCHAR(80) NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    file_path VARCHAR(500) NULL,
    status ENUM('draft','issued','downloaded','voided') NOT NULL DEFAULT 'draft',
    issued_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_receipts_institution_id (institution_id, id),
    UNIQUE KEY uq_receipts_number (institution_id, receipt_number),
    UNIQUE KEY uq_receipts_payment (payment_id),
    KEY idx_receipts_student (student_id, created_at),
    CONSTRAINT fk_receipts_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE RESTRICT,
    CONSTRAINT fk_receipts_payment FOREIGN KEY (institution_id, payment_id) REFERENCES payments(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_receipts_student FOREIGN KEY (institution_id, student_id) REFERENCES students(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_receipts_parent FOREIGN KEY (institution_id, parent_id) REFERENCES parents(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_receipts_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_webhook_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    payment_id BIGINT UNSIGNED NULL,
    gateway ENUM('razorpay','payu','cashfree','stripe') NOT NULL,
    event_type VARCHAR(120) NOT NULL,
    event_identifier VARCHAR(200) NOT NULL,
    payload JSON NULL,
    processing_status ENUM('received','processed','failed') NOT NULL DEFAULT 'received',
    processed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_webhook_identifier (gateway, event_identifier),
    KEY idx_webhook_status (processing_status, created_at),
    CONSTRAINT fk_webhook_events_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE RESTRICT,
    CONSTRAINT fk_webhook_events_payment FOREIGN KEY (institution_id, payment_id) REFERENCES payments(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS timetables (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    batch_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NULL,
    class_id BIGINT UNSIGNED NULL,
    day_of_week ENUM('monday','tuesday','wednesday','thursday','friday','saturday','sunday') NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    room_number VARCHAR(80) NULL,
    subject_name VARCHAR(120) NULL,
    instructor_name VARCHAR(150) NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_timetables_batch_day (batch_id, day_of_week),
    CONSTRAINT fk_timetables_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_timetables_batch FOREIGN KEY (institution_id, batch_id) REFERENCES batches(institution_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_timetables_course FOREIGN KEY (institution_id, course_id) REFERENCES courses(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_timetables_class FOREIGN KEY (institution_id, class_id) REFERENCES classes(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS examinations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    batch_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NULL,
    academic_year_id BIGINT UNSIGNED NULL,
    name VARCHAR(150) NOT NULL,
    exam_date DATE NOT NULL,
    max_marks DECIMAL(8,2) NULL,
    results_published_at DATETIME NULL,
    start_time TIME NULL,
    end_time TIME NULL,
    description TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_examinations_institution_id (institution_id, id),
    KEY idx_examinations_batch_date (batch_id, exam_date),
    KEY idx_examinations_institution_year_date (institution_id, academic_year_id, exam_date),
    CONSTRAINT fk_examinations_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_examinations_batch FOREIGN KEY (institution_id, batch_id) REFERENCES batches(institution_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_examinations_course FOREIGN KEY (institution_id, course_id) REFERENCES courses(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_examinations_academic_year FOREIGN KEY (institution_id, academic_year_id) REFERENCES academic_years(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS examination_results (
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
    CONSTRAINT fk_examination_results_exam FOREIGN KEY (institution_id, examination_id) REFERENCES examinations(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_examination_results_student FOREIGN KEY (institution_id, student_id) REFERENCES students(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_examination_results_recorder FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS holidays (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    description TEXT NULL,
    is_recurring TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_holidays_institution_date (institution_id, start_date),
    CONSTRAINT fk_holidays_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    title VARCHAR(220) NOT NULL,
    message TEXT NOT NULL,
    publish_at DATETIME NULL,
    expires_at DATETIME NULL,
    status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_announcements_institution_id (institution_id, id),
    KEY idx_announcements_institution_publish (institution_id, status, publish_at),
    CONSTRAINT fk_announcements_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_announcements_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CHECK (expires_at IS NULL OR publish_at IS NULL OR expires_at >= publish_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcement_batches (
    institution_id BIGINT UNSIGNED NOT NULL,
    announcement_id BIGINT UNSIGNED NOT NULL,
    batch_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (institution_id, announcement_id, batch_id),
    KEY idx_announcement_batches_batch (institution_id, batch_id),
    CONSTRAINT fk_announcement_batches_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_announcement_batches_announcement FOREIGN KEY (institution_id, announcement_id) REFERENCES announcements(institution_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_announcement_batches_batch FOREIGN KEY (institution_id, batch_id) REFERENCES batches(institution_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    recipient_user_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NULL,
    notification_type ENUM('fee_reminder','payment_success','payment_failed','payment_pending','class_update','exam_reminder','holiday_update','announcement','admission_update','refund_update','system','other') NOT NULL DEFAULT 'other',
    title VARCHAR(220) NOT NULL,
    message TEXT NOT NULL,
    channel ENUM('portal','sms','email','whatsapp') NOT NULL DEFAULT 'portal',
    status ENUM('queued','sent','delivered','read','failed') NOT NULL DEFAULT 'queued',
    related_entity_type VARCHAR(80) NULL,
    related_entity_id BIGINT UNSIGNED NULL,
    dedupe_key VARCHAR(191) NULL,
    scheduled_at DATETIME NULL,
    sent_at DATETIME NULL,
    delivered_at DATETIME NULL,
    read_at DATETIME NULL,
    failure_reason VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notifications_recipient_dedupe (institution_id, recipient_user_id, dedupe_key),
    KEY idx_notifications_recipient_status (recipient_user_id, status, created_at),
    KEY idx_notifications_schedule (status, scheduled_at),
    KEY idx_notifications_student (student_id, created_at),
    CONSTRAINT fk_notifications_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_notifications_recipient FOREIGN KEY (institution_id, recipient_user_id) REFERENCES users(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_notifications_student FOREIGN KEY (institution_id, student_id) REFERENCES students(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_generation_cursors (
    cursor_key VARCHAR(80) NOT NULL,
    last_processed_at DATETIME NOT NULL,
    PRIMARY KEY (cursor_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO notification_generation_cursors (cursor_key, last_processed_at) VALUES
    ('payment_events', CURRENT_TIMESTAMP),
    ('payment_attempt_events', CURRENT_TIMESTAMP),
    ('admission_events', CURRENT_TIMESTAMP),
    ('refund_events', CURRENT_TIMESTAMP),
    ('timetable_events', CURRENT_TIMESTAMP),
    ('examination_events', CURRENT_TIMESTAMP),
    ('holiday_events', CURRENT_TIMESTAMP);

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    actor_type VARCHAR(60) NULL,
    action VARCHAR(120) NOT NULL,
    entity_type VARCHAR(120) NOT NULL,
    entity_id VARCHAR(100) NULL,
    request_id CHAR(36) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    old_values JSON NULL,
    new_values JSON NULL,
    metadata JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_actor_date (actor_user_id, created_at),
    KEY idx_audit_entity (entity_type, entity_id, created_at),
    KEY idx_audit_institution_date (institution_id, created_at),
    CONSTRAINT fk_audit_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE SET NULL,
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    setting_key VARCHAR(150) NOT NULL,
    setting_value TEXT NULL,
    is_secret TINYINT(1) NOT NULL DEFAULT 0,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_system_setting (institution_id, setting_key),
    KEY idx_system_settings_key (setting_key),
    CONSTRAINT fk_system_settings_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_system_settings_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scheduled_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NULL,
    job_type VARCHAR(120) NOT NULL,
    unique_key VARCHAR(190) NOT NULL,
    payload JSON NULL,
    status ENUM('queued','processing','completed','failed','cancelled') NOT NULL DEFAULT 'queued',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at DATETIME NULL,
    completed_at DATETIME NULL,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_scheduled_job_key (job_type, unique_key),
    KEY idx_scheduled_jobs_queue (status, available_at),
    CONSTRAINT fk_scheduled_jobs_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_profiles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    employee_code VARCHAR(50) NULL,
    designation VARCHAR(120) NULL,
    department VARCHAR(120) NULL,
    phone VARCHAR(30) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_staff_profiles_user (user_id),
    UNIQUE KEY uq_staff_profiles_employee_code (institution_id, employee_code),
    CONSTRAINT fk_staff_profiles_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_staff_profiles_user FOREIGN KEY (institution_id, user_id) REFERENCES users(institution_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Academic management foundation (kept in sync with the versioned migration).
-- Additive academic-management foundation for MySQL 8.4.
-- Run against a verified disposable restore first. This migration does not
-- backfill mutable student placement or touch payment/receipt records.

CREATE TABLE academic_migration_exceptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NULL,
    source_table VARCHAR(64) NOT NULL,
    source_record_id BIGINT UNSIGNED NOT NULL,
    constraint_name VARCHAR(128) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_academic_migration_exception
        (source_table, source_record_id, constraint_name),
    KEY idx_academic_migration_exception_institution
        (institution_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE institutions
    ADD COLUMN academic_model ENUM('school','college','university','coaching') NULL,
    ADD COLUMN active_academic_year_id BIGINT UNSIGNED NULL,
    ADD COLUMN academic_configuration_status
        ENUM('unconfigured','in_progress','configured') NOT NULL DEFAULT 'unconfigured',
    ADD KEY idx_institutions_active_academic_year (id, active_academic_year_id);

ALTER TABLE institutions
    ADD CONSTRAINT fk_institutions_active_academic_year
    FOREIGN KEY (id, active_academic_year_id)
    REFERENCES academic_years(institution_id, id) ON DELETE RESTRICT;

ALTER TABLE classes
    ADD COLUMN display_order INT UNSIGNED NOT NULL DEFAULT 0;

CREATE TABLE academic_year_classes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    status ENUM('planned','active','closed') NOT NULL DEFAULT 'planned',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_year_classes_id (institution_id, id),
    UNIQUE KEY uq_year_classes_class (institution_id, academic_year_id, class_id),
    UNIQUE KEY uq_year_classes_scope_id (institution_id, academic_year_id, class_id, id),
    KEY idx_year_classes_class (institution_id, class_id, academic_year_id),
    CONSTRAINT fk_year_classes_year FOREIGN KEY (institution_id, academic_year_id)
        REFERENCES academic_years(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_year_classes_class FOREIGN KEY (institution_id, class_id)
        REFERENCES classes(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sections (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    code VARCHAR(50) NULL,
    capacity INT UNSIGNED NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sections_institution_id (institution_id, id),
    UNIQUE KEY uq_sections_offering_id (institution_id, academic_year_id, class_id, id),
    UNIQUE KEY uq_sections_year_class_name (institution_id, academic_year_id, class_id, name),
    UNIQUE KEY uq_sections_year_class_code (institution_id, academic_year_id, class_id, code),
    KEY idx_sections_class_status (institution_id, academic_year_id, class_id, status),
    CONSTRAINT chk_sections_capacity CHECK (capacity IS NULL OR capacity > 0),
    CONSTRAINT fk_sections_class_offering
        FOREIGN KEY (institution_id, academic_year_id, class_id)
        REFERENCES academic_year_classes(institution_id, academic_year_id, class_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE departments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    code VARCHAR(50) NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_departments_institution_id (institution_id, id),
    UNIQUE KEY uq_departments_name (institution_id, name),
    UNIQUE KEY uq_departments_code (institution_id, code),
    KEY idx_departments_status (institution_id, status),
    CONSTRAINT fk_departments_institution FOREIGN KEY (institution_id)
        REFERENCES institutions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE courses
    ADD COLUMN department_id BIGINT UNSIGNED NULL,
    ADD KEY idx_courses_department (institution_id, department_id),
    ADD CONSTRAINT fk_courses_department
        FOREIGN KEY (institution_id, department_id)
        REFERENCES departments(institution_id, id) ON DELETE RESTRICT;

CREATE TABLE academic_year_courses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NOT NULL,
    status ENUM('planned','active','closed') NOT NULL DEFAULT 'planned',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_year_courses_id (institution_id, id),
    UNIQUE KEY uq_year_courses_course (institution_id, academic_year_id, course_id),
    UNIQUE KEY uq_year_courses_scope_id (institution_id, academic_year_id, course_id, id),
    KEY idx_year_courses_course (institution_id, course_id, academic_year_id),
    CONSTRAINT fk_year_courses_year FOREIGN KEY (institution_id, academic_year_id)
        REFERENCES academic_years(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_year_courses_course FOREIGN KEY (institution_id, course_id)
        REFERENCES courses(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE academic_terms (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    code VARCHAR(50) NULL,
    term_type ENUM('semester','term','trimester','year') NOT NULL,
    sequence_number SMALLINT UNSIGNED NOT NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    status ENUM('planned','active','closed') NOT NULL DEFAULT 'planned',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_academic_terms_institution_id (institution_id, id),
    UNIQUE KEY uq_academic_terms_scope_id (institution_id, academic_year_id, course_id, id),
    UNIQUE KEY uq_academic_terms_name (institution_id, academic_year_id, course_id, name),
    UNIQUE KEY uq_academic_terms_sequence (institution_id, academic_year_id, course_id, sequence_number),
    KEY idx_academic_terms_course_status (institution_id, course_id, academic_year_id, status),
    CONSTRAINT chk_academic_terms_dates CHECK
        (start_date IS NULL OR end_date IS NULL OR end_date >= start_date),
    CONSTRAINT fk_academic_terms_year_course
        FOREIGN KEY (institution_id, academic_year_id, course_id)
        REFERENCES academic_year_courses(institution_id, academic_year_id, course_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Affected legacy batches are retained and explicitly reported. Their existing
-- ID-only relationships remain until each reported row is reviewed.
INSERT INTO academic_migration_exceptions
    (institution_id, source_table, source_record_id, constraint_name, reason)
SELECT b.institution_id, 'batches', b.id, 'fk_batches_course_tenant',
       'Batch course belongs to another institution or is missing.'
FROM batches b
LEFT JOIN courses c ON c.id = b.course_id
WHERE c.id IS NULL OR c.institution_id <> b.institution_id
ON DUPLICATE KEY UPDATE reason = VALUES(reason);

INSERT INTO academic_migration_exceptions
    (institution_id, source_table, source_record_id, constraint_name, reason)
SELECT b.institution_id, 'batches', b.id, 'fk_batches_class_tenant',
       'Batch class belongs to another institution or is missing.'
FROM batches b
LEFT JOIN classes c ON c.id = b.class_id
WHERE c.id IS NULL OR c.institution_id <> b.institution_id
ON DUPLICATE KEY UPDATE reason = VALUES(reason);

ALTER TABLE batches
    MODIFY class_id BIGINT UNSIGNED NULL,
    ADD UNIQUE KEY uq_batches_course_id (institution_id, course_id, id),
    ADD UNIQUE KEY uq_batches_class_id (institution_id, class_id, id),
    ADD KEY idx_batches_course_tenant (institution_id, course_id),
    ADD KEY idx_batches_class_tenant (institution_id, class_id);

DELIMITER //
CREATE PROCEDURE apply_academic_batch_tenant_constraints()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM academic_migration_exceptions
        WHERE source_table = 'batches'
          AND constraint_name IN ('fk_batches_course_tenant','fk_batches_class_tenant')
    ) THEN
        ALTER TABLE batches
            DROP FOREIGN KEY fk_batches_course,
            DROP FOREIGN KEY fk_batches_class,
            ADD CONSTRAINT fk_batches_course_tenant
                FOREIGN KEY (institution_id, course_id)
                REFERENCES courses(institution_id, id) ON DELETE RESTRICT,
            ADD CONSTRAINT fk_batches_class_tenant
                FOREIGN KEY (institution_id, class_id)
                REFERENCES classes(institution_id, id) ON DELETE RESTRICT;
    END IF;
END//
DELIMITER ;
CALL apply_academic_batch_tenant_constraints();
DROP PROCEDURE apply_academic_batch_tenant_constraints;

CREATE TABLE academic_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    group_type ENUM('class','section','course','term','batch') NOT NULL,
    class_id BIGINT UNSIGNED NULL,
    section_id BIGINT UNSIGNED NULL,
    course_id BIGINT UNSIGNED NULL,
    academic_term_id BIGINT UNSIGNED NULL,
    batch_id BIGINT UNSIGNED NULL,
    scope_id BIGINT UNSIGNED GENERATED ALWAYS AS (
        CASE group_type
            WHEN 'class' THEN class_id
            WHEN 'section' THEN section_id
            WHEN 'course' THEN course_id
            WHEN 'term' THEN academic_term_id
            WHEN 'batch' THEN batch_id
        END
    ) STORED,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_academic_groups_id (institution_id, academic_year_id, id),
    UNIQUE KEY uq_academic_groups_scope (institution_id, academic_year_id, group_type, scope_id),
    KEY idx_academic_groups_class (institution_id, academic_year_id, class_id),
    KEY idx_academic_groups_course (institution_id, academic_year_id, course_id),
    KEY idx_academic_groups_batch (institution_id, course_id, batch_id),
    CONSTRAINT chk_academic_groups_target CHECK (
        (group_type = 'class' AND class_id IS NOT NULL AND section_id IS NULL
          AND course_id IS NULL AND academic_term_id IS NULL AND batch_id IS NULL)
        OR (group_type = 'section' AND class_id IS NOT NULL AND section_id IS NOT NULL
          AND course_id IS NULL AND academic_term_id IS NULL AND batch_id IS NULL)
        OR (group_type = 'course' AND class_id IS NULL AND section_id IS NULL
          AND course_id IS NOT NULL AND academic_term_id IS NULL AND batch_id IS NULL)
        OR (group_type = 'term' AND class_id IS NULL AND section_id IS NULL
          AND course_id IS NOT NULL AND academic_term_id IS NOT NULL AND batch_id IS NULL)
        OR (group_type = 'batch' AND class_id IS NULL AND section_id IS NULL
          AND course_id IS NOT NULL AND academic_term_id IS NULL AND batch_id IS NOT NULL)
    ),
    CONSTRAINT fk_academic_groups_year FOREIGN KEY (institution_id, academic_year_id)
        REFERENCES academic_years(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_academic_groups_class
        FOREIGN KEY (institution_id, academic_year_id, class_id)
        REFERENCES academic_year_classes(institution_id, academic_year_id, class_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_academic_groups_section
        FOREIGN KEY (institution_id, academic_year_id, class_id, section_id)
        REFERENCES sections(institution_id, academic_year_id, class_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_academic_groups_course
        FOREIGN KEY (institution_id, academic_year_id, course_id)
        REFERENCES academic_year_courses(institution_id, academic_year_id, course_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_academic_groups_term
        FOREIGN KEY (institution_id, academic_year_id, course_id, academic_term_id)
        REFERENCES academic_terms(institution_id, academic_year_id, course_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_academic_groups_batch
        FOREIGN KEY (institution_id, course_id, batch_id)
        REFERENCES batches(institution_id, course_id, id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subjects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    code VARCHAR(50) NULL,
    subject_type ENUM('core','elective','optional') NOT NULL DEFAULT 'core',
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_subjects_institution_id (institution_id, id),
    UNIQUE KEY uq_subjects_name (institution_id, name),
    UNIQUE KEY uq_subjects_code (institution_id, code),
    KEY idx_subjects_status (institution_id, status),
    CONSTRAINT fk_subjects_institution FOREIGN KEY (institution_id)
        REFERENCES institutions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE class_subjects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_class_subjects_institution_id (institution_id, id),
    UNIQUE KEY uq_class_subjects_assignment (institution_id, academic_year_id, class_id, subject_id),
    KEY idx_class_subjects_subject (institution_id, subject_id, academic_year_id),
    CONSTRAINT fk_class_subjects_class_offering
        FOREIGN KEY (institution_id, academic_year_id, class_id)
        REFERENCES academic_year_classes(institution_id, academic_year_id, class_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_class_subjects_subject FOREIGN KEY (institution_id, subject_id)
        REFERENCES subjects(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE section_subjects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    section_id BIGINT UNSIGNED NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    assignment_mode ENUM('include','exclude') NOT NULL DEFAULT 'include',
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_section_subjects_institution_id (institution_id, id),
    UNIQUE KEY uq_section_subjects_assignment
        (institution_id, academic_year_id, section_id, subject_id),
    KEY idx_section_subjects_class_subject
        (institution_id, academic_year_id, class_id, subject_id),
    CONSTRAINT fk_section_subjects_section
        FOREIGN KEY (institution_id, academic_year_id, class_id, section_id)
        REFERENCES sections(institution_id, academic_year_id, class_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_section_subjects_class_subject
        FOREIGN KEY (institution_id, academic_year_id, class_id, subject_id)
        REFERENCES class_subjects(institution_id, academic_year_id, class_id, subject_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE course_subjects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NOT NULL,
    academic_term_id BIGINT UNSIGNED NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    term_scope_id BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(academic_term_id, 0)) STORED,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_course_subjects_institution_id (institution_id, id),
    UNIQUE KEY uq_course_subjects_assignment
        (institution_id, academic_year_id, course_id, term_scope_id, subject_id),
    KEY idx_course_subjects_term (institution_id, academic_year_id, course_id, academic_term_id),
    KEY idx_course_subjects_subject (institution_id, subject_id, academic_year_id),
    CONSTRAINT chk_course_subjects_term CHECK (academic_term_id IS NULL OR academic_term_id > 0),
    CONSTRAINT fk_course_subjects_offering
        FOREIGN KEY (institution_id, academic_year_id, course_id)
        REFERENCES academic_year_courses(institution_id, academic_year_id, course_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_course_subjects_term
        FOREIGN KEY (institution_id, academic_year_id, course_id, academic_term_id)
        REFERENCES academic_terms(institution_id, academic_year_id, course_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_course_subjects_subject FOREIGN KEY (institution_id, subject_id)
        REFERENCES subjects(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE student_enrolments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    admission_number VARCHAR(50) NULL,
    enrolment_status ENUM('enrolled','active','withdrawn','cancelled','completed','promoted')
        NOT NULL DEFAULT 'enrolled',
    enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    promoted_from_enrolment_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_student_enrolments_institution_id (institution_id, id),
    UNIQUE KEY uq_enrolment_student_year (institution_id, academic_year_id, student_id),
    UNIQUE KEY uq_enrolment_student_identity (institution_id, student_id, id),
    UNIQUE KEY uq_enrolment_student_year_id (institution_id, student_id, academic_year_id, id),
    UNIQUE KEY uq_enrolment_admission_year (institution_id, academic_year_id, admission_number),
    KEY idx_enrolments_student_status (institution_id, student_id, enrolment_status),
    KEY idx_enrolments_promoted_from (institution_id, student_id, promoted_from_enrolment_id),
    CONSTRAINT fk_enrolments_student FOREIGN KEY (institution_id, student_id)
        REFERENCES students(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_enrolments_year FOREIGN KEY (institution_id, academic_year_id)
        REFERENCES academic_years(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_enrolments_promotion_source
        FOREIGN KEY (institution_id, student_id, promoted_from_enrolment_id)
        REFERENCES student_enrolments(institution_id, student_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE student_enrolment_placements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    student_enrolment_id BIGINT UNSIGNED NOT NULL,
    academic_group_id BIGINT UNSIGNED NOT NULL,
    previous_placement_id BIGINT UNSIGNED NULL,
    roll_number VARCHAR(50) NULL,
    effective_at DATETIME(6) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_enrolment_placements_id (institution_id, id),
    UNIQUE KEY uq_enrolment_placement_time (institution_id, student_enrolment_id, effective_at),
    UNIQUE KEY uq_enrolment_placement_identity (institution_id, student_enrolment_id, id),
    UNIQUE KEY uq_enrolment_placement_scope
        (institution_id, academic_year_id, academic_group_id, student_id, student_enrolment_id, id),
    KEY idx_enrolment_placement_history (institution_id, student_enrolment_id, effective_at),
    CONSTRAINT fk_placement_enrolment
        FOREIGN KEY (institution_id, student_id, academic_year_id, student_enrolment_id)
        REFERENCES student_enrolments(institution_id, student_id, academic_year_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_placement_group
        FOREIGN KEY (institution_id, academic_year_id, academic_group_id)
        REFERENCES academic_groups(institution_id, academic_year_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_placement_previous
        FOREIGN KEY (institution_id, student_enrolment_id, previous_placement_id)
        REFERENCES student_enrolment_placements(institution_id, student_enrolment_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_placement_actor FOREIGN KEY (actor_user_id)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE admissions
    ADD CONSTRAINT fk_admissions_conversion_enrolment
        FOREIGN KEY (institution_id, student_id, academic_year_id, student_enrolment_id)
        REFERENCES student_enrolments(institution_id, student_id, academic_year_id, id)
        ON DELETE RESTRICT,
    ADD CONSTRAINT fk_admissions_conversion_placement
        FOREIGN KEY (institution_id, academic_year_id, academic_group_id, student_id,
                     student_enrolment_id, student_enrolment_placement_id)
        REFERENCES student_enrolment_placements(institution_id, academic_year_id,
                     academic_group_id, student_id, student_enrolment_id, id)
        ON DELETE RESTRICT,
    ADD CONSTRAINT fk_admissions_conversion_actor
        FOREIGN KEY (institution_id, converted_by)
        REFERENCES users(institution_id, id) ON DELETE RESTRICT;

CREATE TABLE student_enrolment_status_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    student_enrolment_id BIGINT UNSIGNED NOT NULL,
    previous_status ENUM('enrolled','active','withdrawn','cancelled','completed','promoted') NULL,
    new_status ENUM('enrolled','active','withdrawn','cancelled','completed','promoted') NOT NULL,
    effective_at DATETIME(6) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_enrolment_status_event_id (institution_id, id),
    UNIQUE KEY uq_enrolment_status_event_time (institution_id, student_enrolment_id, effective_at),
    KEY idx_enrolment_status_history (institution_id, student_enrolment_id, effective_at),
    CONSTRAINT chk_enrolment_status_change CHECK
        (previous_status IS NULL OR previous_status <> new_status),
    CONSTRAINT fk_enrolment_status_event FOREIGN KEY (institution_id, student_enrolment_id)
        REFERENCES student_enrolments(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_enrolment_status_actor FOREIGN KEY (actor_user_id)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE fee_structures
    ADD COLUMN section_id BIGINT UNSIGNED NULL,
    ADD COLUMN academic_term_id BIGINT UNSIGNED NULL,
    ADD COLUMN batch_id BIGINT UNSIGNED NULL,
    ADD COLUMN year_class_id BIGINT UNSIGNED NULL,
    ADD COLUMN year_course_id BIGINT UNSIGNED NULL,
    ADD COLUMN fee_group_code VARCHAR(40) NULL,
    ADD COLUMN effective_from DATE NULL,
    ADD COLUMN effective_until DATE NULL,
    ADD COLUMN target_model ENUM('legacy','class','course','term','batch') NOT NULL DEFAULT 'legacy',
    ADD COLUMN target_scope_kind VARCHAR(10) GENERATED ALWAYS AS (
        CASE
            WHEN target_model = 'class' AND section_id IS NOT NULL THEN 'section'
            WHEN target_model = 'class' THEN 'class'
            WHEN target_model = 'course' THEN 'course'
            WHEN target_model = 'term' THEN 'term'
            WHEN target_model = 'batch' THEN 'batch'
            ELSE NULL
        END
    ) STORED,
    ADD COLUMN target_scope_id BIGINT UNSIGNED GENERATED ALWAYS AS (
        CASE
            WHEN target_model = 'class' AND section_id IS NOT NULL THEN section_id
            WHEN target_model = 'class' THEN class_id
            WHEN target_model = 'course' THEN course_id
            WHEN target_model = 'term' THEN academic_term_id
            WHEN target_model = 'batch' THEN batch_id
            ELSE NULL
        END
    ) STORED,
    ADD COLUMN active_scope_slot TINYINT UNSIGNED GENERATED ALWAYS AS (
        CASE WHEN status = 'active' AND target_model <> 'legacy' THEN 1 ELSE NULL END
    ) STORED,
    ADD UNIQUE KEY uq_fee_structures_year_id (institution_id, academic_year_id, id),
    ADD UNIQUE KEY uq_fee_structures_active_start
        (institution_id, academic_year_id, target_scope_kind, target_scope_id,
         fee_group_code, effective_from, active_scope_slot),
    ADD KEY idx_fee_structures_class_scope
        (institution_id, academic_year_id, class_id, section_id, status),
    ADD KEY idx_fee_structures_class_offering
        (institution_id, academic_year_id, class_id, year_class_id),
    ADD KEY idx_fee_structures_course_scope
        (institution_id, academic_year_id, course_id, academic_term_id, status),
    ADD KEY idx_fee_structures_course_offering
        (institution_id, academic_year_id, course_id, year_course_id),
    ADD KEY idx_fee_structures_batch_scope (institution_id, academic_year_id, batch_id, status),
    ADD KEY idx_fee_structures_batch_course (institution_id, course_id, batch_id),
    ADD CONSTRAINT chk_fee_structure_target_shape CHECK (
        target_model = 'legacy'
        OR (target_model = 'class' AND class_id IS NOT NULL
            AND course_id IS NULL AND academic_term_id IS NULL AND batch_id IS NULL
            AND year_class_id IS NOT NULL AND year_course_id IS NULL
            AND fee_group_code IS NOT NULL AND effective_from IS NOT NULL
            AND (effective_until IS NULL OR effective_until >= effective_from))
        OR (target_model = 'course' AND course_id IS NOT NULL
            AND class_id IS NULL AND section_id IS NULL
            AND academic_term_id IS NULL AND batch_id IS NULL
            AND year_class_id IS NULL AND year_course_id IS NOT NULL
            AND fee_group_code IS NOT NULL AND effective_from IS NOT NULL
            AND (effective_until IS NULL OR effective_until >= effective_from))
        OR (target_model = 'term' AND course_id IS NOT NULL AND academic_term_id IS NOT NULL
            AND class_id IS NULL AND section_id IS NULL AND batch_id IS NULL
            AND year_class_id IS NULL AND year_course_id IS NOT NULL
            AND fee_group_code IS NOT NULL AND effective_from IS NOT NULL
            AND (effective_until IS NULL OR effective_until >= effective_from))
        OR (target_model = 'batch' AND batch_id IS NOT NULL AND course_id IS NOT NULL
            AND year_course_id IS NOT NULL AND class_id IS NULL AND section_id IS NULL
            AND academic_term_id IS NULL AND year_class_id IS NULL
            AND fee_group_code IS NOT NULL AND effective_from IS NOT NULL
            AND (effective_until IS NULL OR effective_until >= effective_from))
    ),
    ADD CONSTRAINT fk_fee_structure_class_offering
        FOREIGN KEY (institution_id, academic_year_id, class_id, year_class_id)
        REFERENCES academic_year_classes(institution_id, academic_year_id, class_id, id)
        ON DELETE RESTRICT,
    ADD CONSTRAINT fk_fee_structure_course_offering
        FOREIGN KEY (institution_id, academic_year_id, course_id, year_course_id)
        REFERENCES academic_year_courses(institution_id, academic_year_id, course_id, id)
        ON DELETE RESTRICT,
    ADD CONSTRAINT fk_fee_structure_section
        FOREIGN KEY (institution_id, academic_year_id, class_id, section_id)
        REFERENCES sections(institution_id, academic_year_id, class_id, id)
        ON DELETE RESTRICT,
    ADD CONSTRAINT fk_fee_structure_term
        FOREIGN KEY (institution_id, academic_year_id, course_id, academic_term_id)
        REFERENCES academic_terms(institution_id, academic_year_id, course_id, id)
        ON DELETE RESTRICT,
    ADD CONSTRAINT fk_fee_structure_batch_course
        FOREIGN KEY (institution_id, course_id, batch_id)
        REFERENCES batches(institution_id, course_id, id) ON DELETE RESTRICT;

ALTER TABLE fee_structure_items
    ADD UNIQUE KEY uq_fee_structure_items_institution_id (institution_id, id),
    ADD UNIQUE KEY uq_fee_structure_items_identity (institution_id, id, category_id),
    ADD COLUMN frequency ENUM('one_time','monthly','quarterly','half_yearly','yearly','custom')
        NULL DEFAULT NULL,
    ADD COLUMN description VARCHAR(500) NULL,
    ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    ADD KEY idx_fee_items_component_status (institution_id, fee_structure_id, status),
    ADD KEY idx_fee_items_category_tenant (institution_id, category_id);

CREATE TABLE fee_component_schedule (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    fee_structure_item_id BIGINT UNSIGNED NOT NULL,
    installment_number SMALLINT UNSIGNED NOT NULL,
    due_date DATE NOT NULL,
    amount DECIMAL(12,2) NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fee_component_schedule_id (institution_id, id),
    UNIQUE KEY uq_fee_component_schedule_number
        (institution_id, fee_structure_item_id, installment_number),
    KEY idx_fee_component_schedule_due (institution_id, due_date, status),
    CONSTRAINT chk_fee_component_schedule_amount CHECK (amount IS NULL OR amount >= 0),
    CONSTRAINT fk_fee_component_schedule_item
        FOREIGN KEY (institution_id, fee_structure_item_id)
        REFERENCES fee_structure_items(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fee_structure_scope_guards (
    institution_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    target_scope_kind VARCHAR(10) NOT NULL,
    target_scope_id BIGINT UNSIGNED NOT NULL,
    fee_group_code VARCHAR(40) NOT NULL,
    PRIMARY KEY (institution_id, academic_year_id, target_scope_kind, target_scope_id, fee_group_code),
    CONSTRAINT fk_fee_scope_guard_year FOREIGN KEY (institution_id, academic_year_id)
        REFERENCES academic_years(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE student_fee_assignments
    ADD COLUMN student_enrolment_id BIGINT UNSIGNED NULL,
    ADD KEY idx_student_fee_assignment_structure_year
        (institution_id, academic_year_id, fee_structure_id),
    ADD KEY idx_student_fee_assignment_enrolment
        (institution_id, student_id, academic_year_id, student_enrolment_id),
    ADD CONSTRAINT fk_student_fee_assignment_enrolment
        FOREIGN KEY (institution_id, student_id, academic_year_id, student_enrolment_id)
        REFERENCES student_enrolments(institution_id, student_id, academic_year_id, id)
        ON DELETE RESTRICT;

-- Preserve mismatched historic assignments and omit only the blocked composite
-- structure/year FK. A later repair migration can add it after review.
INSERT INTO academic_migration_exceptions
    (institution_id, source_table, source_record_id, constraint_name, reason)
SELECT sfa.institution_id, 'student_fee_assignments', sfa.id,
       'fk_student_fee_assignment_structure_year',
       'Assignment academic year differs from its referenced fee structure.'
FROM student_fee_assignments sfa
JOIN fee_structures fs
  ON fs.institution_id = sfa.institution_id AND fs.id = sfa.fee_structure_id
WHERE sfa.academic_year_id <> fs.academic_year_id
ON DUPLICATE KEY UPDATE reason = VALUES(reason);

DELIMITER //
CREATE PROCEDURE apply_academic_fee_assignment_year_constraint()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM academic_migration_exceptions
        WHERE source_table = 'student_fee_assignments'
          AND constraint_name = 'fk_student_fee_assignment_structure_year'
    ) THEN
        ALTER TABLE student_fee_assignments
            ADD CONSTRAINT fk_student_fee_assignment_structure_year
            FOREIGN KEY (institution_id, academic_year_id, fee_structure_id)
            REFERENCES fee_structures(institution_id, academic_year_id, id)
            ON DELETE RESTRICT;
    END IF;
END//
DELIMITER ;
CALL apply_academic_fee_assignment_year_constraint();
DROP PROCEDURE apply_academic_fee_assignment_year_constraint;

CREATE TABLE student_fee_assignment_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    student_fee_assignment_id BIGINT UNSIGNED NOT NULL,
    fee_category_id BIGINT UNSIGNED NOT NULL,
    source_fee_structure_item_id BIGINT UNSIGNED NULL,
    source_section_id BIGINT UNSIGNED NULL,
    component_name VARCHAR(150) NOT NULL,
    component_description VARCHAR(500) NULL,
    frequency ENUM('one_time','monthly','quarterly','half_yearly','yearly','custom') NULL,
    amount DECIMAL(12,2) NOT NULL,
    is_optional TINYINT(1) NOT NULL DEFAULT 0,
    source_version VARCHAR(40) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_assignment_items_institution_id (institution_id, id),
    UNIQUE KEY uq_assignment_items_category (institution_id, student_fee_assignment_id, fee_category_id),
    KEY idx_assignment_items_source_identity
        (institution_id, source_fee_structure_item_id, fee_category_id),
    KEY idx_assignment_items_category (institution_id, fee_category_id),
    KEY idx_assignment_items_section (institution_id, source_section_id),
    KEY idx_assignment_items_assignment (institution_id, student_fee_assignment_id),
    CONSTRAINT chk_assignment_item_amount CHECK (amount >= 0),
    CONSTRAINT fk_assignment_items_assignment
        FOREIGN KEY (institution_id, student_fee_assignment_id)
        REFERENCES student_fee_assignments(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_assignment_items_source
        FOREIGN KEY (institution_id, source_fee_structure_item_id, fee_category_id)
        REFERENCES fee_structure_items(institution_id, id, category_id) ON DELETE RESTRICT,
    CONSTRAINT fk_assignment_items_category FOREIGN KEY (institution_id, fee_category_id)
        REFERENCES fee_categories(institution_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_assignment_items_section FOREIGN KEY (institution_id, source_section_id)
        REFERENCES sections(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE student_fee_assignment_item_schedules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    assignment_item_id BIGINT UNSIGNED NOT NULL,
    installment_number SMALLINT UNSIGNED NOT NULL,
    due_date DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_assignment_item_schedules_id (institution_id, id),
    UNIQUE KEY uq_assignment_item_schedule_number (institution_id, assignment_item_id, installment_number),
    KEY idx_assignment_item_schedules_due (institution_id, due_date),
    CONSTRAINT chk_assignment_item_schedule_amount CHECK (amount >= 0),
    CONSTRAINT fk_assignment_item_schedules_item
        FOREIGN KEY (institution_id, assignment_item_id)
        REFERENCES student_fee_assignment_items(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attendance_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    academic_group_id BIGINT UNSIGNED NOT NULL,
    session_at DATETIME(6) NOT NULL,
    session_code VARCHAR(50) NOT NULL,
    status ENUM('scheduled','open','closed','cancelled') NOT NULL DEFAULT 'scheduled',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_sessions_id (institution_id, academic_year_id, academic_group_id, id),
    UNIQUE KEY uq_attendance_session_code
        (institution_id, academic_year_id, academic_group_id, session_at, session_code),
    KEY idx_attendance_sessions_group_time
        (institution_id, academic_year_id, academic_group_id, session_at, status),
    CONSTRAINT fk_attendance_session_group
        FOREIGN KEY (institution_id, academic_year_id, academic_group_id)
        REFERENCES academic_groups(institution_id, academic_year_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attendance_session_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    academic_group_id BIGINT UNSIGNED NOT NULL,
    attendance_session_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    student_enrolment_id BIGINT UNSIGNED NOT NULL,
    placement_id BIGINT UNSIGNED NOT NULL,
    status ENUM('present','absent','late','excused') NOT NULL,
    recorded_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_session_records_id (institution_id, id),
    UNIQUE KEY uq_attendance_session_student (institution_id, attendance_session_id, student_id),
    KEY idx_attendance_session_record_placement
        (institution_id, academic_year_id, academic_group_id, student_enrolment_id, placement_id),
    CONSTRAINT fk_attendance_record_session
        FOREIGN KEY (institution_id, academic_year_id, academic_group_id, attendance_session_id)
        REFERENCES attendance_sessions(institution_id, academic_year_id, academic_group_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_attendance_record_placement
        FOREIGN KEY (institution_id, academic_year_id, academic_group_id, student_id,
                     student_enrolment_id, placement_id)
        REFERENCES student_enrolment_placements(institution_id, academic_year_id, academic_group_id,
                                                student_id, student_enrolment_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_attendance_record_actor FOREIGN KEY (recorded_by)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rollback is intentionally not automated: MySQL DDL commits implicitly and
-- this migration may write review exceptions or new academic records.

SET FOREIGN_KEY_CHECKS = 1;
