-- Optional rollback to the pre-migration schema. This removes tenant-integrity
-- protection and must not be used as a substitute for restoring a verified backup.
-- DDL statements implicitly commit.

DROP TRIGGER IF EXISTS trg_users_role_tenant_bi;
DROP TRIGGER IF EXISTS trg_users_role_tenant_bu;
DROP TRIGGER IF EXISTS trg_roles_tenant_bi;
DROP TRIGGER IF EXISTS trg_roles_tenant_bu;

ALTER TABLE user_sessions
    DROP FOREIGN KEY fk_user_sessions_user_institution,
    DROP INDEX idx_user_sessions_institution_user,
    ADD CONSTRAINT fk_user_sessions_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE user_otp_verifications
    DROP FOREIGN KEY fk_user_otp_user_institution,
    ADD CONSTRAINT fk_user_otp_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
