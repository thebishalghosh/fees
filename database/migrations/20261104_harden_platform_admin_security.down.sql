-- Revert platform bootstrap, permission-grant, and login-rate-limit structures.
-- Historical platform_audit_logs rows are intentionally preserved.

DROP TABLE IF EXISTS platform_login_rate_limits;
DROP TABLE IF EXISTS platform_bootstrap_state;
DROP TABLE IF EXISTS platform_admin_permission_grants;

ALTER TABLE platform_audit_logs
    DROP INDEX idx_platform_audit_created;

ALTER TABLE platform_admins
    DROP INDEX uq_platform_admins_bootstrap_slot,
    DROP CHECK chk_platform_admins_initial_bootstrap,
    DROP COLUMN bootstrap_slot,
    DROP COLUMN is_initial_bootstrap;