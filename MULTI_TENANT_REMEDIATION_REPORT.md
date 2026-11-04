# FeesPortal Multi-Tenant Isolation Remediation Report

**Date:** 2026-11-03  
**Scope:** The three findings in [`MULTI_TENANT_ISOLATION_AUDIT.md`](./MULTI_TENANT_ISOLATION_AUDIT.md)  
**Database target:** Local MySQL 8.4.3 database `fees_management_test`  
**Production:** Not accessed or changed

## Summary

All three scoped findings were addressed:

1. Session and OTP rows now reference the user through the composite
   `(institution_id, user_id)` relationship.
2. User-role tenant compatibility is enforced by database triggers. Canonical
   global roles remain usable only with the matching `user_type`; a custom role
   must belong to the same institution and can only be assigned to staff.
3. Role deactivation/deletion counts now include the authenticated
   institution predicate.

The migration was tested on a disposable database restored from the verified
backup, then applied to the configured local test database after the clean
preflight and verified backup. No user, session, OTP, role, payment, or other
application records were changed by the migration. Test fixtures were confined
to disposable verification databases.

## Findings and changes

| Finding | Root cause | Correction |
|---|---|---|
| Medium: session user/institution pairing not enforced | Separate foreign keys allowed a session's user and institution to refer to different tenants. | Replaced the user-only foreign key with `(institution_id, user_id) -> users(institution_id, id)` while retaining the institution foreign key and cascade behavior. |
| Medium: OTP user/institution pairing not enforced | Same independent-reference issue as sessions. | Replaced the user-only foreign key with the corresponding composite foreign key, retaining the institution foreign key and cascade behavior. |
| Medium: user-role institution pairing not enforced | `users.role_id` can reference any globally unique role ID; a composite FK cannot express a shared global role that is valid for any institution. | Added insert/update triggers on `users` and `roles` to validate tenant custom-role ownership, allowed user type, and canonical global-role pairing, including role changes that would invalidate existing assignments. |
| Low: role assignment counts omitted institution | Deactivation/deletion counts used only `role_id`. | Both count queries now bind `role_id` and the authenticated `institution_id`. |

### Global role behavior

The triggers permit a global system role only when its `institution_id` is NULL,
its name is one of the canonical role names, and that name matches the
associated user's `user_type`. Custom roles require a non-NULL owner and are
restricted to staff users in that same institution. `BEFORE UPDATE` validation
on roles prevents moving or converting an assigned role into a cross-tenant or
incompatible assignment. This avoids relying on NULL semantics in a composite
foreign key.

## Files changed or created

- [`database/schema.sql`](./database/schema.sql): fresh-install composite
  session/OTP foreign keys and four role integrity triggers.
- [`database/migrations/20261103_enforce_tenant_identity_integrity.sql`](./database/migrations/20261103_enforce_tenant_identity_integrity.sql):
  guarded existing-database migration and post-migration checks.
- [`database/migrations/20261103_enforce_case_sensitive_system_roles.sql`](./database/migrations/20261103_enforce_case_sensitive_system_roles.sql):
  follow-up trigger update using exact case-sensitive role-name comparisons.
- [`database/migrations/20261103_enforce_tenant_identity_integrity.down.sql`](./database/migrations/20261103_enforce_tenant_identity_integrity.down.sql):
  optional reverse DDL.
- [`services/role-management-service.php`](./services/role-management-service.php):
  tenant-scoped role assignment counts.
- [`tests/multitenant_integrity_test.php`](./tests/multitenant_integrity_test.php):
  focused static and disposable-database integrity tests.
- [`README.md`](./README.md): rollout, test, and migration safety guidance.
- [`MULTI_TENANT_PREFLIGHT_REPORT.md`](./MULTI_TENANT_PREFLIGHT_REPORT.md):
  record-integrity preflight.
- [`MULTI_TENANT_BACKUP_REPORT.md`](./MULTI_TENANT_BACKUP_REPORT.md):
  backup and import verification details.

Payment implementation and payment-related schema were not changed.

## Before/after constraint inventory

| Object | Before | After |
|---|---|---|
| Session-to-user | `user_sessions.user_id -> users.id` | `user_sessions(institution_id,user_id) -> users(institution_id,id)`; the direct institution FK, existing session indexes, token uniqueness, and cascade behavior remain. A non-unique composite child index supports the FK. |
| OTP-to-user | `user_otp_verifications.user_id -> users.id` | `user_otp_verifications(institution_id,user_id) -> users(institution_id,id)`; the direct institution FK, OTP uniqueness, status indexes, and cascade behavior remain. Existing `uq_user_otp_request` supports the FK. |
| User-to-role | `users.role_id -> roles.id` and application-level validation | Existing FK retained; four triggers enforce role system/tenant/type compatibility on role/user insert and update. |
| Role assignment counts | `WHERE role_id = ?` | `WHERE role_id = ? AND institution_id = ?` with both parameters bound. |

## Preflight, backup, and migration status

- **Preflight:** Passed. 29 sessions, 25 OTP challenges, 4 users, and 5 role
  definitions were examined; no mismatches, orphans, incompatible assignments,
  or invalid role definitions were found. See the [preflight report](./MULTI_TENANT_PREFLIGHT_REPORT.md).
- **Backup:** Created before migration and verified by importing it into a
  disposable database and comparing all 45 table row counts. See the
  [backup report](./MULTI_TENANT_BACKUP_REPORT.md).
- **Migration prepared:** Yes; the guarded migration file is listed above.
- **Migrations executed:** Both ordered migration files were applied on
  `fees_management_test` only.
- **Target schema metadata verified:** Two composite foreign keys and four expected
  triggers are present; session and OTP mismatch counts are zero; role
  compatibility issue count is zero. User/session/OTP counts remain 4/29/25.
- **Behavioral database runtime verified:** On disposable backup/schema copies
  using MySQL 8.4.3, not by inserting test fixtures into the configured target.
  This does not constitute a production or full-application runtime audit.
- **Production touched:** No.

## Tests and actual results

### Disposable database integration

The migrations and `tests/multitenant_integrity_test.php` were run against a
disposable clone restored from the verified backup: **23 checks passed**. A
second run imported the updated `schema.sql` and `seed.sql` into a fresh
disposable database: **23 checks passed**.

The 23 checks include same-institution session/OTP inserts, rejection of
mismatched session/OTP ownership, session revalidation, OTP verification and
expiry, cross-institution custom-role assignments in both directions, role
update protection, incompatible role/user types, compatible global roles
(including Super Admin), exact-case system role names, and institution-scoped
role counts.

Both disposable databases were removed after successful verification.

### Existing focused regression suites

| Test | Result |
|---|---|
| `authentication_helpers_test.php` | 75 checks passed |
| `admin_password_authentication_test.php` | 21 checks passed |
| `staff_email_authentication_test.php` | 51 checks passed |
| `parent_email_authentication_test.php` | 66 checks passed |
| `parent_email_verification_test.php` | 40 checks passed |
| `otp_verification_modal_test.php` | 18 checks passed |
| `staff_management_test.php` | 9 checks passed |
| `admin_panel_test.php` | 42 checks passed |
| `institution_onboarding_test.php` | 15 checks passed |
| `institution_profile_access_test.php` | 5 checks passed |
| `institution_branding_test.php` | 16 checks passed |
| `system_preferences_page_test.php` | 4 checks passed |
| `multitenant_integrity_test.php` without DB env | 3 static checks passed; DB checks intentionally skipped |
| `payments/razorpay_payment_test.php` | 54 checks passed |

`receipt_pdf_test.php` did **not** pass: the configured test database lacks its
required pre-existing issued receipt ID `1405`. The test reported that missing
fixture; no receipt or payment was inserted to work around it. Its receipt
regression is therefore not verified. No dedicated webhook or reconciliation
test file was found. Payment source and schema were unchanged.

## Rollback

The optional reverse DDL is in
[`20261103_enforce_tenant_identity_integrity.down.sql`](./database/migrations/20261103_enforce_tenant_identity_integrity.down.sql).
It removes the four triggers, restores the prior user-only session/OTP foreign
keys, and drops the session composite index. DDL implicitly commits; the
rollback removes the new isolation protections and should only be used after
review and explicit approval.

The verified full backup is at:
`C:\Users\Bishal Ghosh\AppData\Local\FeesPortalBackups\fees_management_test_20261103_121247.sql`

Restore that backup only if schema repair is not safe and after accounting for
any legitimate writes made after its creation, since a restore replaces the
current database state.

## Remaining risks and conclusion

- The receipt PDF regression remains blocked on a missing expected fixture.
- No production deployment or production database was tested.
- The migration uses MySQL triggers and was validated on MySQL 8.4.3; other
  database engines/versions were not tested.
- Passing these focused tests does not establish that every application route,
  query, or deployment is fully isolated.

**Result:** The three scoped audit findings are remediated and verified on the
local database and disposable database copies. Broader tenant isolation should
not be labeled **FULLY ISOLATED** on this remediation evidence alone.
