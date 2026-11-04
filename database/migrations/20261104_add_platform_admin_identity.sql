-- Platform Super Admin identity, sessions, and audit tables.
-- Safe additive migration for existing installations.

CREATE TABLE IF NOT EXISTS platform_admins (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('active','pending','suspended','disabled') NOT NULL DEFAULT 'pending',
    last_login_at DATETIME NULL,
    created_by_platform_admin_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_platform_admins_email (email),
    KEY idx_platform_admins_status (status),
    KEY idx_platform_admins_created_by (created_by_platform_admin_id),
    CONSTRAINT fk_platform_admins_creator FOREIGN KEY (created_by_platform_admin_id)
        REFERENCES platform_admins(id) ON DELETE SET NULL
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
    CONSTRAINT fk_platform_audit_actor FOREIGN KEY (actor_platform_admin_id)
        REFERENCES platform_admins(id) ON DELETE SET NULL,
    CONSTRAINT fk_platform_audit_target_institution FOREIGN KEY (target_institution_id)
        REFERENCES institutions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
