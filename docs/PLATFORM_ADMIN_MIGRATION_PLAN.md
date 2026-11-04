# Platform Admin Migration and Rollback Plan

## Goal

Provide a safe design-only migration path for introducing standalone platform administration while preserving the current institution system. This plan does not modify existing production or test databases.

## Migration principles

1. No automatic promotion of existing institution users into platform admin status.
2. Existing institution users remain intact.
3. Existing institution session and OTP logic remains unchanged unless explicitly separated in a future implementation.
4. Platform admin creation is a manual approval process and must be audited.
5. Ambiguous or inactive accounts are not migrated automatically.
6. Any migration must operate on a verified backup or disposable environment only.

## Proposed migration steps

### Step 1: inventory existing super-admin accounts

Before creating platform admin records, the operator must inventory current accounts that may map to platform administration.

Review:
- `users` rows with `user_type = 'super_admin'`
- `roles` rows with `name = 'super_admin'` and `is_system = 1`
- `user_sessions` entries for those users
- `user_otp_verifications` entries
- any institution membership or institutional staff/profile rows associated with those users

Decision rule:
- existing institution super-admin rows are treated as candidates only, not automatic platform admins
- ambiguous records must be reviewed manually and left unchanged until approved
- if a system currently uses `super_admin` as an institution-bound role, it must not be silently upgraded

### Step 2: create platform admin table

Add the `platform_admins` table and any required supporting indexes and constraints as defined in the database design document.

### Step 3: create platform session table

Add `platform_sessions` with foreign keys and expiry/revocation support.

### Step 4: create platform audit table

Add `platform_audit_logs` with actor and target metadata fields and safe JSON payloads.

### Step 5: perform manual mapping

Approvals required:
- account owner identity
- verified email address
- status and lifecycle state
- account creator
- audit metadata

Only a verified and approved platform admin may be inserted into `platform_admins`.

### Step 6: invalidate old platform-style sessions

Because the current application does not have a truly separate platform session domain, any old institutional session or stale platform-like session should be invalidated during migration preparation.

This should be handled carefully so normal institution users are not logged out unexpectedly.

### Step 7: do not modify current `users` rows automatically

The migration must not:
- remove `user_type = 'super_admin'`
- change `users.institution_id`
- convert institution users into a platform table record
- change roles or permissions automatically

### Step 8: validate fresh installation consistency

The graduate schema should include:
- platform admin table
- platform session table
- platform audit table
- platform-specific auth logic
- no implicit platform admin record creation during institution user creation

## Ambiguous records handling

Ambiguous cases must be left unchanged and flagged for review. Examples:
- a user who has a `super_admin` role but also belongs to an institution
- a user with a matching email or role but no verified identity
- a user with multiple institutional memberships
- stale sessions with invalid expiry data
- duplicate or partially missing email addresses

The migration must document each such case and require an explicit decision before any platform record is created.

## Rollback strategy

A rollback should be possible without deleting current institution users or roles.

Rollback steps:
1. disable platform admin logins
2. revoke active platform sessions
3. disable any new platform-only routes or checks
4. remove newly created platform admin table records only if they are verified as unapproved or not yet in use
5. preserve audit logs for review
6. restore the previous session state for institution flows if necessary

Important: rollback must not delete institution user data or current historical institution records.

## Recovery and verification checklist

Before go-live, review:
- the new platform admin table is populated only with approved platform accounts
- no institutional user was changed to a platform record automatically
- all institution sessions remain valid and scoped correctly
- all platform session records are separate and independent
- platform audit logging is active
- a backup exists before any live rollout

## Execution restriction

This Phase 2 design phase explicitly does not create executable migration files or run SQL against any database. The migration plan is a design artifact only.
