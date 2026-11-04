-- Add one-time bootstrap state, explicit platform permission grants, and atomic login rate limits.

ALTER TABLE platform_admins
    ADD COLUMN is_initial_bootstrap TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN bootstrap_slot TINYINT(1) GENERATED ALWAYS AS (
        CASE WHEN is_initial_bootstrap = 1 THEN 1 ELSE NULL END
    ) STORED,
    ADD UNIQUE KEY uq_platform_admins_bootstrap_slot (bootstrap_slot),
    ADD CONSTRAINT chk_platform_admins_initial_bootstrap CHECK (is_initial_bootstrap IN (0, 1));

UPDATE platform_admins
SET is_initial_bootstrap = 1
WHERE id = (
    SELECT initial_admin_id
    FROM (SELECT MIN(id) AS initial_admin_id FROM platform_admins) AS existing_admin
);

CREATE TABLE platform_admin_permission_grants (
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

CREATE TABLE platform_bootstrap_state (
    id TINYINT UNSIGNED NOT NULL,
    initialized_at DATETIME NULL,
    initialized_by_platform_admin_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    CONSTRAINT chk_platform_bootstrap_state_singleton CHECK (id = 1),
    CONSTRAINT fk_platform_bootstrap_state_admin FOREIGN KEY (initialized_by_platform_admin_id)
        REFERENCES platform_admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO platform_bootstrap_state (id, initialized_at, initialized_by_platform_admin_id)
SELECT 1,
       CASE WHEN COUNT(*) > 0 THEN CURRENT_TIMESTAMP ELSE NULL END,
       MIN(id)
FROM platform_admins;

INSERT INTO platform_admin_permission_grants (platform_admin_id, permission_key)
SELECT id, 'platform_admins.manage' FROM platform_admins WHERE is_initial_bootstrap = 1;

INSERT INTO platform_admin_permission_grants (platform_admin_id, permission_key)
SELECT id, 'platform_permissions.manage' FROM platform_admins WHERE is_initial_bootstrap = 1;

INSERT INTO platform_admin_permission_grants (platform_admin_id, permission_key)
SELECT id, 'platform_audit.view' FROM platform_admins WHERE is_initial_bootstrap = 1;

CREATE TABLE platform_login_rate_limits (
    scope_type ENUM('account','ip') NOT NULL,
    scope_hash CHAR(64) NOT NULL,
    window_started_at DATETIME NOT NULL,
    failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (scope_type, scope_hash),
    KEY idx_platform_login_rate_limits_window (window_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE platform_audit_logs
    ADD KEY idx_platform_audit_created (created_at, id);