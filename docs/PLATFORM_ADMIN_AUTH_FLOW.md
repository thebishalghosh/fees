# Platform Admin Authentication and Authorization Flow

## Objective

Define the proposed authentication flow for platform administrators and keep it distinct from the existing institution login flow. This is documentation only and does not change any live code or database behavior.

## Authentication domains

### 1. Platform authentication domain

Platform authentication is a separate flow with its own identity, session, and authorization layer.

Proposed login flow:
1. User requests platform login route.
2. Server validates that the submitted email is normalized and that the account exists in `platform_admins`.
3. Server loads the platform admin record and verifies `status`.
4. Server checks the supplied password against `password_hash` using a PHP password verification method.
5. Server creates a platform session record in `platform_sessions`.
6. Server stores a platform session identifier or token in the platform-specific session state.
7. Server records `last_login_at` and audit event for successful login.
8. Server redirects to a platform dashboard or platform management route.

### 2. Institution authentication domain

Institution login remains as it exists today.

Proposed retained behavior:
- institution users authenticate through the existing institution-bound user model
- institution login resolves user account and institution context from `users` + `institutions`
- institutions and OTP validation remain unchanged for normal staff, parent, and admin entry
- platform sessions must be rejected on institution-only endpoints
- institution sessions must be rejected on platform-only endpoints

## Authorization model

### Platform authorization

Platform authorization is checked using platform-specific session and platform-specific permissions.

Proposed checks:
- Verify the caller has a valid `platform_admin` session.
- Verify the platform admin is `status = 'active'`.
- Verify the platform session is not expired or revoked.
- Verify the action requires a platform permission such as `institutions.view`, `institutions.create`, `platform_admins.manage`, or `platform_audit.view`.
- Reject unauthorized requests with a 403 response.

### Institution authorization

Institution authorization remains with the existing institution-bound user/session model.

Checks include:
- validate session against institution user record
- verify `institution_id` matches the target record
- verify the actor’s role and permissions for that institution
- reject cross-institution access when the institution context mismatches

## Explicit context selection

The critical design rule is: platform users must not become institution administrators just by selecting an institution ID or by using a session that was created for the wrong domain.

Authorized platform institution access should require:
1. valid platform admin session
2. explicit target institution selection
3. explicit platform permission check
4. platform audit log entry
5. no implicit persistence of selected institution state as a default authorization context

This means an institution context can be chosen for a read-only or write operation only when the tool is designed to do so and the platform permission is valid.

## Login procedure flow

### Platform login

```
Request platform login
  -> validate normalized email
  -> load platform_admin record by email
  -> validate account status
  -> verify password hash
  -> check rate limits / lockout quotas
  -> create platform session record
  -> store platform session identifier in platform session state
  -> set last_login_at
  -> write platform audit log
  -> redirect to platform dashboard
```

### Institution login

```
Request institution login
  -> resolve institution-specific user via current institution auth flow
  -> verify OTP / password / role matching
  -> create institution session
  -> store institution session and user context
  -> redirect to institution dashboard
```

## Session revalidation

### Platform session revalidation

Server-side revalidation flow:
1. Check platform session identifier exists.
2. Load the corresponding `platform_sessions` row.
3. Confirm `expires_at` is still valid and `revoked_at` is null.
4. Confirm the platform admin record still exists and is active.
5. Refresh `last_activity_at`.
6. Recompute session state or return unauthorized if invalid.

### Institution session revalidation

Retain existing current behavior in the institution auth model, including `user_sessions` and OTP verification rules.

## Logout

### Platform logout

- revoke the current platform session
- clear platform session state
- clear any cached platform context
- log a platform logout event if required by policy

### Institution logout

- revoke institution session
- clear institution session state
- redirect to institution login or app login route

## Cross-domain rejection

- Platform session on institution page => reject
- Institution session on platform page => reject
- Platform request with institution `id` parameter but no valid platform authorization => reject
- Institution request with platform action or platform route => reject

## Password recovery and lockdown

Platform administrators should use a separate recovery flow from institution users. This includes:
- separate reset token table or recovered challenge flow
- rate limiting
- no sharing of institution OTP flows
- explicit audit logs for recovery and reset operations

## Summary

The authentication architecture should keep the institutional and platform domains completely independent, each with its own session and authorization checks. The platform identity is not an institution admin with a selected institution context. It is a separate platform actor, and the app must enforce that distinction at every route and permission check.

## Phase 3.2 enforced behavior

- Bootstrap takes a transaction lock on the singleton `platform_bootstrap_state` row and marks it initialized in the same transaction as the first admin, initial grants, and audit record. A unique initial-admin slot is also enforced by the database.
- Platform login serializes account and IP failure counters in the database. The limits are five failures per normalized account and thirty per client IP in a fifteen-minute window. Successful passwords do not increment the counters; responses for unknown accounts and incorrect passwords are identical.
- Platform logout is POST-only, requires the current CSRF token and a revalidated platform session, revokes only the matching platform session, and then destroys the PHP session.
- Platform permissions are loaded from explicit per-admin grants at revalidation time. A grant or revocation is therefore visible on the next authorization check; institution RBAC never supplies a platform grant.
- Invalid and failed bootstrap attempts are written to the application error log using fixed reason codes only. Secrets and submitted email addresses are excluded.
