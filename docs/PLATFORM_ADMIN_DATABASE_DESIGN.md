# Platform Super Admin Database Design

## Goal

Document the intended database design for a standalone platform administrator identity and separate authorization domain. This design is documentation only and does not modify the current schema or execute SQL.

## Proposed platform tables

### 1. `platform_admins`

```sql
CREATE TABLE platform_admins (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('active','pending','suspended','disabled') NOT NULL DEFAULT 'pending',
    last_login_at DATETIME NULL,
    created_by_platform_admin_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_platform_admins_email (email),
    KEY idx_platform_admins_status (status),
    KEY idx_platform_admins_created_by (created_by_platform_admin_id),
    CONSTRAINT fk_platform_admins_creator
        FOREIGN KEY (created_by_platform_admin_id)
        REFERENCES platform_admins(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Notes:
- No `institution_id` column.
- `email` should be normalized before storage, typically lowercase ASCII and trimmed.
- Password storage must use PHP `password_hash()`/`password_verify()` style practices.
- Status should be enforced server-side and used for login blocking and recovery flows.

### 2. `platform_sessions`

```sql
CREATE TABLE platform_sessions (
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
    CONSTRAINT fk_platform_sessions_admin
        FOREIGN KEY (platform_admin_id)
        REFERENCES platform_admins(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Notes:
- This table is independent from `user_sessions` and is not a partial reuse of the institution session pattern.
- It should not be used to establish institution authorization.
- It should support explicit revocation and revalidation.

### 3. `platform_audit_logs`

```sql
CREATE TABLE platform_audit_logs (
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
    CONSTRAINT fk_platform_audit_actor
        FOREIGN KEY (actor_platform_admin_id)
        REFERENCES platform_admins(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_platform_audit_target_institution
        FOREIGN KEY (target_institution_id)
        REFERENCES institutions(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Notes:
- `metadata` stores safe summary fields only.
- Nothing sensitive such as OTPs, tokens, raw passwords, or session values should be stored.
- `correlation_id` can be used to link request traces without exposing secrets.

## Existing institution model

The current institution identity model remains unchanged as the default operating domain:

- `institutions`
- `users`
- `roles`
- `permissions`
- `role_permissions`
- `user_sessions`
- `user_otp_verifications`
- `staff_profiles`
- parent and student records

### Institution authorization rules

- `users.institution_id` remains required for institution accounts.
- `roles.is_system` rules remain valid for global role names that are institution-bound to a matching institution.
- Institution admin and staff roles do not confer platform access.
- A `user_type` of `admin`, `staff`, or `parent` still maps only to institution-scoped behavior.

### Explicit access rules

- A platform admin may inspect an institution only through explicit platform permission and explicit institution target selection.
- An institution admin may manage only their institution’s records and staff.
- A platform admin may not automatically act as an institution user unless the platform flow explicitly authorizes a context switch.
- A context switch must never be accepted merely because the request contains an `institution_id` or a selected institution cookie.

## Relationships and constraints

### Relationship map

- `platform_admins` has zero or more `platform_sessions`
- `platform_admins` has zero or more `platform_audit_logs`
- `institutions` can have many `platform_audit_logs` where `target_institution_id` is set
- institution users remain completely independent of `platform_admins`

### Constraint strategy

- `platform_admins.email` must be unique after normalization
- `platform_sessions` should enforce that a session belongs to an existing platform admin
- `platform_audit_logs` should link to actor and target institution only when valid
- no foreign key may allow a platform admin row to be treated as an institution user row

## Fresh installation consistency

New installations should create the platform tables as part of schema initialization. The tables should be added alongside the existing institution and auth tables, but no platform admin record should be created implicitly.

## Backward compatibility

This design is additive and does not modify current institution auth flow or standard user record behavior. Existing application data remains relevant and must remain intact while the platform identity layer is introduced.

## Observed compatibility risks

- Existing `users.user_type = 'super_admin'` does not automatically map to a platform admin record.
- Existing code that expects `super_admin` as an institution-bound role may need explicit review before platform flow is implemented.
- Any hard-coded assumptions that “all super admin = institution admin with selected institution” must be removed in the implementation phase.

## Design note

This database design deliberately separates the platform authorization domain from institutional authorization. It is the least disruptive design for the current application while still providing real platform-level isolation.

## Phase 3.2 security structures

The fresh-install schema and `20261104_harden_platform_admin_security.sql` migration add:

- `platform_bootstrap_state`: a single row locked with `SELECT ... FOR UPDATE` during bootstrap. `initialized_at` remains set after successful initialization, so deleting an admin does not reopen bootstrap.
- `platform_admins.is_initial_bootstrap` and a unique generated bootstrap slot: at most one account can occupy the initial-admin slot.
- `platform_admin_permission_grants`: an explicit platform-only grant set keyed by `(platform_admin_id, permission_key)`. The application allowlist, not a row or institution role name alone, determines which keys can authorize an operation.
- `platform_login_rate_limits`: account/IP failure counters protected by transactional row locks, with a fifteen-minute window and cleanup of rows older than one day.
- `idx_platform_audit_created`: supports recent audit queries in addition to actor, target-institution, and action indexes.

The security migration marks the lowest existing platform-admin ID as the initial account, locks bootstrap as already complete when platform admins already exist, and gives that existing initial account only the three documented platform-control grants. It does not grant institution-data permissions. The down migration removes the added grant/rate/bootstrap structures and indexes but intentionally preserves `platform_audit_logs` and all audit rows. Permission-grant data is removed when rolling back this migration and must be exported if it is needed for review.
