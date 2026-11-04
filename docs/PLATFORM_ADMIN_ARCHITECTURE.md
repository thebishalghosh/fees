# Platform Super Admin Architecture

## Purpose

This document defines the Phase 2 target architecture for separating Platform Super Admin access from ordinary institution-affiliated identities in FeesPortal. The design is intentionally conservative: it keeps the current institution-backed user model intact and introduces a distinct platform identity domain for platform-wide administration.

## Core principle

The application must no longer rely on a special `users.user_type = 'super_admin'` record as the platform authority model. The platform authority will be represented by a dedicated identity domain, while institution users remain institution-bound and cannot gain platform authority through role names, request parameters, selected-institution state, or session manipulation.

## Architecture summary

### Identity domains

1. Institutional identity domain
   - Users, staff, admins, parents, teachers, students, and institution-scoped roles remain under the current `users` and related tables.
   - Every institution account remains tied to a valid `institution_id`.
   - All ordinary institution operations remain institution-scoped.

2. Platform identity domain
   - Platform administrators are represented by a separate `platform_admins` table.
   - Platform administrators are not institution users.
   - Platform admin access is validated by a separate platform session and a separate authorization layer.

3. Institution context selection
   - Super Admin access to an institution is an explicit, authorized platform operation.
   - Institutional context is never treated as implicit authentication.
   - The app must not silently grant access because a request includes an `institution_id` parameter or a selected context.

## Domain model

### A. Platform Admin identity

The platform admin identity is intentionally independent from institution users.

Required properties:
- `id`: platform admin record identifier
- `name`: display name
- `email`: normalized email used for platform login
- `password_hash`: locked password hash using PHP password hashing functions
- `status`: lifecycle state such as `active`, `suspended`, `pending`, `disabled`
- `last_login_at`: last successful platform login
- `created_at`: account creation timestamp
- `updated_at`: last update timestamp
- `created_by_platform_admin_id`: optional trace of the originating platform admin

Constraints:
- `email` must be unique after normalization
- `institution_id` is explicitly absent
- `password_hash` must be stored only as a password hash
- `status` must be enforced server-side
- no automatic promotion from an institution `users` row
- no role name may grant platform authority automatically

### B. Platform session identity

The platform session is separate from institution user sessions.

Required properties:
- `id`
- `platform_admin_id`
- `session_identifier` or equivalent secure session handle
- `session_token_hash`
- `user_agent`
- `ip_address`
- `expires_at`
- `revoked_at`
- `last_activity_at`
- `created_at`

Constraints:
- platform sessions must not reuse institution user session validation logic
- platform sessions must be scoped to the platform admin identity
- failed revalidation must terminate platform context and clear any platform-specific session state

### C. Platform audit log

Platform audit records must be separate from institution audit logs. The log should capture:
- `platform_admin_id` or actor identity
- `actor_name` or a safe alias
- `action`
- `entity_type`
- `entity_id` (nullable)
- `target_institution_id` (nullable)
- `ip_address`
- `user_agent`
- `metadata` as JSON-safe fields
- `created_at`

This log must exclude secrets such as raw passwords, OTPs, session tokens, and raw auth challenge values.

## Institutional model retained

The current institution model stays intact:
- `users`
- `roles`
- `permissions`
- `role_permissions`
- `user_sessions`
- `user_otp_verifications`
- `institutions`
- `staff_profiles`
- parent, student, admissions, fee, and academic records remain institution-scoped

Rules:
- ordinary institution accounts remain tied to a valid `institution_id`
- platform authority is never derived from an institution role or a user role name
- role names such as `admin`, `staff`, `teacher`, `parent` remain institution-scoped and must not imply platform permissions
- institution context is only authorized through explicit, validated institution membership checks

## Operational rules

### Institution admin
- Must belong to a valid institution.
- Can manage institution users and staff only within that institution.
- Cannot access platform-level functionality.
- Cannot create or promote platform administrators.

### Institution staff
- Must belong to a valid institution.
- Permissions must be scoped to their institution.
- Institutional queries must enforce `institution_id = ?` for all data access.
- Cannot change institution context via request parameter, query string, or manipulated session data.

### Teachers and other staff roles
- Must remain institution roles, not platform grants.
- Permissions are checked against the current institution and actor identity.

### Parents and students
- Remain institution-scoped and independent from platform admin access.
- Parent and student routes must reject platform sessions and platform identities.

## Security boundary

The platform identity domain and institutional identity domain are deliberately separate. No actor is considered authorized in both domains unless there is an explicit dual-ownership record or specific platform admin mapping. In this design, a platform admin is not also an institution admin unless a separately approved mapping exists.

## Required behavior

1. A platform admin can authenticate at a platform route only.
2. An institution user cannot authenticate at a platform route without explicit platform authorization.
3. A platform admin cannot act as an institution user without explicit institution-context selection and authorization.
4. An institution user cannot access data across institutions by changing IDs or request parameters.
5. Platform actions must be logged separately and must include target institution metadata when relevant.

## Final recommendation

The correct architecture is not to overload the existing `users` table with a platform identity. Instead, a dedicated `platform_admins` table plus a dedicated platform session model is the least risky design and best matches the project’s existing institution-bound data model.

## Phase 3.2 permission boundary

Platform permissions are explicit rows in `platform_admin_permission_grants`; they do not come from `roles`, `permissions`, `role_permissions`, or a session-supplied role name. Session revalidation reloads the grant set from the database, and unknown permission keys are ignored. The initial bootstrap grants only `platform_admins.manage`, `platform_permissions.manage`, and `platform_audit.view`. No institution-data permission is granted by default.

Any future operation that reads or changes institution data must require its own platform permission and write a target-specific platform audit event. No platform dashboard or institution-management route is defined by this hardening phase.
