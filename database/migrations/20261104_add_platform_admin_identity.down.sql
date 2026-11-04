-- Rollback for platform admin identity tables.
-- The rollback is intentionally conservative and does not delete historical institution data.

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS platform_audit_logs;
DROP TABLE IF EXISTS platform_sessions;
DROP TABLE IF EXISTS platform_admins;

SET FOREIGN_KEY_CHECKS = 1;
