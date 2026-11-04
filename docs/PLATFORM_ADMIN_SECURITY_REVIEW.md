# Platform Admin Security Review

## Threat model

This review covers the critical cross-domain security issues for separating platform admin identity from the institution user system.

## 1. Institution A to Institution B access

Risk: A user from Institution A fabricates an institution ID or manipulates a request to access Institution B data.

Design response:
- All institution queries must enforce `institution_id = ?` on the current actor’s approved institution.
- No request parameter or session state may override this check.
- Institution-specific routes must validate the actor’s institution membership server-side.
- Any cross-institution data access attempt must return 403 or equivalent denial.

## 2. Institution user accessing platform routes

Risk: An institution user attempts to access platform-only functionality by URL or direct request.

Design response:
- Platform routes require a valid platform session and platform permission model.
- Institution sessions are explicitly rejected on platform routes.
- Platform authorization checks do not rely on hidden UI elements or menu visibility.

## 3. Platform user accessing institution routes without authorization

Risk: A platform admin tries to access institution data without explicit institution authorization.

Design response:
- Institution routes require institution identity + institution authorization.
- Platform admin sessions are not automatically institution-authorized.
- Any institution operation requires an explicit institution context and permission check.

## 4. Session fixation and session confusion

Risk: A valid platform session is reused to gain institution access, or an institution session is confused with platform authentication.

Design response:
- Use separate session tables and separate session identifiers for platform and institution sessions.
- Ensure platform session validation does not read institution session rows.
- Ensure institution validation does not read platform session rows.
- Regenerate session identifiers during login and privilege changes.
- Revoke stale or old sessions when a user changes role or account status.

## 5. Account enumeration and brute-force protection

Risk: Attackers can determine whether an account exists or which institution a user belongs to.

Design response:
- Use generic authentication failures for invalid credentials.
- Rate-limit login attempts by account and IP.
- Apply throttling to platform login and institution login separately.
- Track login failures in logs but avoid leaking internal differences in the response.

## 6. Password recovery abuse

Risk: Password reset or recovery flows can become a platform privilege escalation tool or institution data leak.

Design response:
- Platform password reset must use a separate flow from institution user recovery.
- Recovery tokens must be single-use and time-limited.
- Recovery events must be logged with audit metadata.
- Recovery flows must not reveal whether a platform admin or institutional user exists in the system.

## 7. Privilege escalation

Risk: Lower privileges escalate to platform authority.

Design response:
- Platform authority is not inferred from role names, session values, or institution selection.
- A platform admin must be a separately verified account.
- Institution RBAC remains separate from platform permissions.
- Server-side permission checks apply to every sensitive route and API endpoint.

## 8. Unauthorized institution context switching

Risk: A request manipulates an institution ID to access a different institution’s data.

Design response:
- Every data access query must authorize against the actor’s valid institution relation.
- `institution_id` from a request parameter is never trusted without additional validation.
- Platform-specific institution switching must be explicit, permission-checked, and audited.

## 9. Audit log tampering

Risk: An attacker modifies audit records or deletes logs to hide malicious actions.

Design response:
- Audit log writes should be append-only where practical.
- Sensitive logs should be protected from easy editing by application logic.
- Only trusted system actions should write audit records.
- Platform audit logs must be separate from institution audit records.

## 10. Direct object reference attacks

Risk: Attackers manipulate IDs to access other users, institutions, or records directly.

Design response:
- Use server-side validation for every object and relationship access.
- Enforce institution scope in all queries.
- Validate that the current actor is allowed to modify or view the specific entity.
- Keep direct lookup endpoints server-authorized and never rely on UI visibility.

## Summary

The separation between platform identity and institution identity is the main security boundary. If implemented correctly, institution-bound data remains isolated and platform authority remains separate. The biggest risk is session confusion and silent institution context selection, so the design explicitly avoids implicit authorization.

## Phase 3.2 controls and operational limits

Bootstrap uses a locked singleton state row plus a unique initial-admin slot. The admin row, initial grants, state transition, and success audit event commit or roll back together. Invalid-token and failed bootstrap attempts use fixed error-log reason codes; no email, password, or bootstrap token is logged.

Platform login applies five-failure account and thirty-failure IP limits per fifteen-minute window. Both scope rows are locked in a stable order while credentials are checked, so concurrent requests serialize. Only failed credentials increment counters; successful logins do not. Rate-limit keys are SHA-256 digests, and expired rows older than one day are cleaned up during login processing.

Logout requires POST, a current CSRF token, and a live platform session. It revokes the exact platform session and destroys the PHP session after the audit write commits. Institution logout remains on its existing handler.

Platform permission grants are stored separately from institution RBAC and reloaded on each authorization check. Bootstrap receives only `platform_admins.manage`, `platform_permissions.manage`, and `platform_audit.view`; institution and support-data permissions require a separate explicit grant. Unknown permissions are denied. No protected platform business routes are implemented in this phase.

`write_platform_audit_log()` is insert-only in the application code, validates metadata against a small safe-field allowlist, and the schema indexes actor, target institution, action, and creation time. There is no application `UPDATE` or `DELETE` operation for platform audit rows. However, `db_connect()` uses the application’s shared `DB_USERNAME`; its actual database grants were not queried. Therefore the logs are **not verified immutable**. A deployment must use a restricted database principal that cannot update/delete audit rows (and cannot alter the protecting schema), or ship audit events to an independently controlled append-only service. An application account with broad DDL privileges can bypass application-level restrictions.

There is no automatic audit deletion. The operator must approve a retention schedule, preserve encrypted off-site backups, restrict backup access, and periodically test restore and integrity procedures. Migration rollback preserves audit rows. Audit metadata is restricted to fixed status/boolean/permission fields and must never contain passwords, tokens, cookies, OTPs, or bootstrap secrets.

The platform integration test requires explicit opt-in, a generated unique `fees_platform_test_<random>` database name, a loopback MySQL host, and a non-production environment. It creates a separate database connection, applies the migrations only there, and drops only the database created by that test run. The configured database is never selected by the integration test.
