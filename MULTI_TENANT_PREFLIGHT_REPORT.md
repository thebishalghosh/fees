# Multi-Tenant Integrity Preflight Report

**Date:** 2026-11-03  
**Mode:** Read-only database queries; no records changed  
**Configured target:** `fees_management_test`  
**Server:** MySQL 8.4.3  
**Purpose:** Preflight for [`20261103_enforce_tenant_identity_integrity.sql`](./database/migrations/20261103_enforce_tenant_identity_integrity.sql)

## Results

| Check | Records examined | Inconsistent/orphaned | IDs requiring review |
|---|---:|---:|---|
| `user_sessions` user/institution pairing | 29 | 0 | None |
| `user_otp_verifications` user/institution pairing | 25 | 0 | None |
| User-to-role references and tenant/type compatibility | 4 users | 0 | None |
| Role definitions | 5 roles | 0 | None |

The database contained the four canonical global system roles (`super_admin`,
`admin`, `staff`, `parent`) and one institution-specific custom role. Existing
global assignments matched their user types; the custom role assignment was to
staff in its owning institution. No orphaned references or incompatible role
assignments were found.

## Index and duplicate assessment

- `users(institution_id, id)` already has the required unique key
  `uq_users_institution_id`.
- The session migration adds a **non-unique** index on
  `(institution_id, user_id)` to support the new composite foreign key. Multiple
  sessions per user are valid, so duplicate pairs are permitted and do not
  prevent that index.
- OTP already has unique key `uq_user_otp_request` beginning with
  `(institution_id, user_id)`; no additional unique pair index is needed.
- The new constraints add no uniqueness rule to session or OTP rows.

There are no records or business decisions requiring investigation. The
migration's own blocking preflight checks repeat the critical ownership and
role-consistency checks before DDL.

## Result

**Preflight passed.** The inspected configured database was the local
development/test database, not production. Read-only preflight did not insert,
update, or delete records.
