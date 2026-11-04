# FeesPortal Multi-Tenant Isolation Audit

**Review type:** Read-only static security review  
**Conclusion:** **PARTIALLY ISOLATED**  
**Scope:** Authentication and tenant context; roles; schema; direct SQL usage; admin and parent portals; fees/payments/receipts; reports/exports; files; announcements/notifications; API routing; scheduled tasks.  
**Verification limits:** No application runtime, live database, gateway, email, or deployment was exercised. No tests or migrations were run and no database records were read or changed. The worktree was clean when inspected; no source files were changed.

## Executive summary

Most reviewed request paths derive `institution_id` from the revalidated authenticated user, scope records by that tenant, and validate associated records (such as a student's parent relationship or a payment's student/installment). Parent access additionally checks active relationship and relationship permissions. I found no verified cross-institution data disclosure or cross-tenant IDOR in the reviewed application paths.

Isolation is not fully guaranteed at the database boundary. Session and OTP rows have independent user and institution foreign keys rather than a composite same-tenant constraint; the user-to-role foreign key is likewise not tenant-composite. Application checks mitigate these gaps on the reviewed paths, but inconsistent rows can be stored outside those paths. Two role-management user-count queries also omit `institution_id`; they do not return user data and the role is first validated, but can count assignments from another tenant if inconsistent role assignments exist.

The conclusion is therefore **PARTIALLY ISOLATED**, not FULLY ISOLATED. The findings below are static evidence, not proof of runtime behavior.

## Audit method and boundaries

- Reviewed the database definitions in [`database/schema.sql`](./database/schema.sql), authentication and authorization in [`includes/auth.php`](./includes/auth.php), service/query layers, routed PHP pages, gateway handlers, cron tasks, and `.htaccess`.
- Reviewed SQL read/write sites for tenant predicates and checks of related records. The unscoped-query section lists the exceptions and global/system lookups identified; it does not treat every SQL statement as a finding.
- Checked the worktree without modifying or reverting anything.
- Did not execute tests because their database side effects were not established as safely read-only. No runtime or deployment-level conclusion is claimed.

## 1. Institution-owned table inventory

The schema defines **45 tables**: the `institutions` tenant-root table, **41 tables with an `institution_id` column**, and three shared/global tables. “Tenant-keyed” means the table stores institution identity; it does not by itself prove every application query is correctly scoped.

| Tables | Schema line(s) | Notes |
|---|---:|---|
| `institutions` | 7 | Tenant root and institution profile/branding/settings identity; Super Admin manages institutions globally. |
| `roles` | 39 | Tenant roles use `institution_id`; system roles are intentionally global (`institution_id` nullable). |
| `users`, `user_sessions`, `user_otp_verifications` | 75, 104, 125 | User identity and authentication/session/challenge records. Session and OTP user/tenant pairing has a schema-level gap (see findings). |
| `parents`, `staff_profiles`, `instructors` | 146, 930, 246 | Tenant-linked people/profiles. Parent and staff profile user links use composite institution/user references; instructor user links are also composite. |
| `academic_years`, `courses`, `classes`, `batches`, `course_instructors`, `students` | 175, 191, 208, 224, 265, 278 | Academic structure, staff/course assignments, and student records. |
| `attendance_records`, `parent_student_relationships`, `admissions`, `admission_documents` | 307, 332, 354, 392 | Student attendance, parent access relationships, admission records, and uploaded admission document metadata. |
| `fee_categories`, `fee_structures`, `fee_structure_items`, `payment_plans`, `student_fee_assignments`, `installments`, `fee_discounts`, `fee_adjustments` | 409, 426, 447, 464, 482, 509, 533, 551 | Fee configuration, student liabilities, installments, discounts, and adjustments. |
| `payment_orders`, `payment_attempts`, `payments`, `refunds`, `receipts`, `payment_webhook_events` | 569, 600, 620, 653, 675, 700 | Financial transactions and gateway events. |
| `timetables`, `examinations`, `examination_results`, `holidays` | 718, 741, 766, 783 | Academic schedules, results, and holidays. |
| `announcements`, `announcement_batches`, `notifications` | 799, 818, 829 | Tenant announcements, batch targeting, and recipient notifications. |
| `audit_logs`, `system_settings`, `scheduled_jobs` | 873, 896, 911 | Audit/settings/queued work. `audit_logs.institution_id` and `scheduled_jobs.institution_id` are nullable for system/global records. |

### Shared/global tables

| Table | Schema line | Scope |
|---|---:|---|
| `permissions` | 58 | Global permission catalog, not tenant data. |
| `role_permissions` | 67 | Junction keyed by role and global permission; its tenant boundary is inherited from the associated role. |
| `notification_generation_cursors` | 858 | Global cursor state for event-generation jobs; no institution-specific data fields. |

## 2. User types and institution associations

`users.user_type` supports `super_admin`, `admin`, `staff`, and `parent` ([schema](./database/schema.sql#L75)). Every user row has a required `institution_id`; the current authentication context also requires a positive institution ID ([`current_user`](./includes/auth.php#L6), [`authenticated_institution_id`](./includes/auth.php#L25)).

- **Super Admin:** represented as a `users` account with an institution association, but the institution-management surface uses a separate `super_admin` role gate and global institution operations ([`super-admin/institutions.php`](./super-admin/institutions.php#L1), [`includes/auth.php`](./includes/auth.php#L403)). This is distinct from ordinary tenant-scoped service calls. Super Admin access to tenant data should continue to be explicit rather than implicitly disabling tenant predicates.
- **Institution Admin:** associated with one institution; the application validates the system admin role and uses authenticated tenant context for institution services.
- **Staff:** associated with one institution; may have the global system staff role or a custom role belonging to that same institution. Authentication checks custom-role ownership ([`authenticated_role_is_valid`](./includes/auth.php#L168)); role assignment validates both target staff and role against the institution ([`rbac_assign_staff_role`](./services/role-management-service.php#L482)).
- **Parent:** associated with one institution through `users` and `parents`; parent access to students is mediated by an active institution-matched relationship and the relevant `can_view_*`/`can_make_payments` flag ([`user_can_access_student`](./includes/auth.php#L268)).
- **Student:** not a `users.user_type` in this schema; students are tenant-owned records. The reviewed student access gate permits staff/admin access only to a student in their institution and parent access only through an eligible relationship.

## 3. Modules with verified tenant isolation in reviewed paths

“Verified” here means supported by source inspection, not by runtime testing.

| Module | Evidence |
|---|---|
| Authentication/session revalidation | Session lookup joins session and user on both user and institution, checks the session's institution against the session user, and validates institution status and role ownership ([`includes/auth.php`](./includes/auth.php#L64)). |
| Parent and student access | Student lookup includes institution; parent lookup joins parent, relationship, and student on institution and checks active states and requested relationship permission ([`includes/auth.php`](./includes/auth.php#L268)). |
| Admin lists, dashboards, fees, payment details, reports and exports | Reviewed service queries take institution context from the authenticated account and constrain tenant-owned reads/writes. Representative dashboard/payment/report paths: [`services/admin-panel-service.php`](./services/admin-panel-service.php#L32), [`services/admin-panel-service.php`](./services/admin-panel-service.php#L250), [`services/admin-panel-service.php`](./services/admin-panel-service.php#L465). |
| Parent portal, fees and payment history | Queries scope by authenticated institution, parent/student ownership, and active relationship/permissions ([`services/parent-portal-service.php`](./services/parent-portal-service.php#L18), [`services/parent-portal-service.php`](./services/parent-portal-service.php#L553)). |
| Payment creation and settlement | Checkout validates institution, parent, student, relationship, and payment permission ([`services/payment-service.php`](./services/payment-service.php#L86)); webhook order lookup resolves the order's institution before settlement ([`integrations/payment-gateway/webhook.php`](./integrations/payment-gateway/webhook.php#L44)). |
| Receipt and admission PDF downloads | Reviewed receipt download performs an institution-scoped record lookup and constrains the file path; parent admission PDF first authorizes the student/admission through the parent portal ([`admin/receipts/download.php`](./admin/receipts/download.php#L14), [`parent/admissions/download-pdf.php`](./parent/admissions/download-pdf.php#L24)). |
| Announcements/notifications | Parent-facing queries scope institution and parent/student relationships; notification generation uses tenant-aware joins ([`services/parent-portal-service.php`](./services/parent-portal-service.php#L763), [`services/notification-service.php`](./services/notification-service.php#L18)). |
| Reconciliation/background processing | Reconciliation processes an order with its associated institution passed into scoped service operations ([`services/reconciliation-service.php`](./services/reconciliation-service.php#L4)); cron notification/reminder paths use institution-aware records. |
| API routing | The API route is blocked by the Apache rewrite rule ([`.htaccess`](./.htaccess#L8)); no usable application API surface was identified. This depends on the deployed server honoring `.htaccess`. |
| Institution branding | Reviewed logo-serving paths use institution context or a Super Admin check; storage is denied direct web access by the storage rule ([`.htaccess`](./.htaccess#L6)). |

## 4. Missing or inconsistent tenant filtering

### A. Schema does not enforce session/OTP institution pairing — Medium

`user_sessions` has separate foreign keys to `users(id)` and `institutions(id)` rather than a composite `(institution_id, user_id)` reference ([`database/schema.sql`](./database/schema.sql#L104), [`database/schema.sql`](./database/schema.sql#L121)). `user_otp_verifications` has the same issue ([`database/schema.sql`](./database/schema.sql#L125), [`database/schema.sql`](./database/schema.sql#L143)). The reviewed session revalidation query checks both associations, and OTP paths carry institution context, so this is not a verified authentication bypass. The schema nevertheless permits mismatched rows.

### B. User-to-role pairing is not enforced by a composite database key — Medium

`users.role_id` references `roles(id)` alone ([`database/schema.sql`](./database/schema.sql#L75), [`database/schema.sql`](./database/schema.sql#L101)). Application role assignment and authentication validate role type/tenant association, but a database-level or out-of-band write can associate a user with another tenant's role. This is a defense-in-depth/data-integrity gap, not a verified cross-tenant access path in the reviewed code.

### C. Role assignment counts omit the tenant predicate — Low

Two read-only count queries filter by `role_id` but not `institution_id`:

- [`services/role-management-service.php`](./services/role-management-service.php#L333): count users before deactivating a role.
- [`services/role-management-service.php`](./services/role-management-service.php#L380): count users before deleting a role.

The role itself is validated against the current institution before each query and role IDs are globally unique. These queries return only counts, not another tenant's user data. If inconsistent cross-tenant role assignments exist, however, they can affect the decision to deactivate/delete a role.

## 5. Cross-tenant access vulnerabilities

**No confirmed exploitable cross-tenant IDOR or data exposure was identified in the reviewed application paths.** The findings above are schema integrity and query consistency weaknesses. In particular:

- URL/form-supplied tenant identifiers are not treated as the authority for the principal tenant in reviewed service paths; those paths receive authenticated institution context.
- Related-record ownership is checked for parent/student access and for fee/payment/receipt associations in reviewed paths.
- This is not a claim that every runtime state or deployment is safe; no live data, tampered database state, or deployed server configuration was tested.

## 6. Unscoped queries and global lookups

These are the unscoped query exceptions identified in the reviewed paths. The contextual checks are part of the assessment; unscoped SQL is not automatically a vulnerability.

| File and line | Query/operation | Assessment |
|---|---|---|
| [`services/role-management-service.php`](./services/role-management-service.php#L333) | `SELECT COUNT(*) FROM users WHERE role_id = ?` | Missing `institution_id`; role was previously tenant-validated. Count-only effect; Low. |
| [`services/role-management-service.php`](./services/role-management-service.php#L380) | `SELECT COUNT(*) FROM users WHERE role_id = ?` | Same as above; Low. |
| [`includes/auth.php`](./includes/auth.php#L130) | Update session activity by session record `id` | Record ID comes from a session row previously selected with user, token, and institution predicates. Context-bound primary-key update. |
| [`includes/auth.php`](./includes/auth.php#L137) | Load permissions by `role_id` | Role has just been validated against authenticated user type and institution; shared system roles are intentionally global. |
| [`services/role-management-service.php`](./services/role-management-service.php#L162) | Load permission keys by `role_id` | Used after role validation in the role UI and role-save flow; `role_permissions` is a role-owned junction. |
| [`services/role-management-service.php`](./services/role-management-service.php#L439) | Read the global permissions catalog | Intentional global lookup; not institution-owned data. |
| [`admin/staff/permissions.php`](./admin/staff/permissions.php#L110) | Read the global permissions catalog | Intentional global lookup; selected role is tenant-validated before role permissions are loaded. |
| [`includes/auth.php`](./includes/auth.php#L622) | Revoke session rows by user/session token identifiers | Logout operation on the current authenticated session; does not return tenant data. |

All other reviewed tenant-data query families were either institution-predicated or constrained through a validated tenant-owned related-record join. Institution-root reads by globally unique institution ID and system-wide job/cursor operations are intentionally global/system-context operations.

## 7. Risk classification

| Severity | Finding | Exploitability evidence |
|---|---|---|
| **Critical** | None identified. | No confirmed cross-tenant compromise. |
| **High** | None identified. | No verified data disclosure or unauthorized cross-tenant write path. |
| **Medium** | Session and OTP schemas do not enforce user/institution pairing; user-role association is not tenant-composite. | Application checks mitigate reviewed flows; inconsistent rows can still be stored via other write paths. |
| **Low** | Role deactivation/deletion counts omit institution filter. | Count-only behavior; role is validated first; impact requires inconsistent role assignments. |

## 8. Recommended corrections in implementation order

1. **Close the identity-association integrity gaps.** Plan composite unique keys/foreign keys for `(institution_id, user_id)` on session and OTP records and a tenant-valid role association for tenant roles. Handle global system roles explicitly. Before applying any migration, assess/backfill inconsistent rows; no migration was run for this audit.
2. **Scope role assignment counts.** Add the authenticated institution predicate to both user count queries at lines 333 and 380, even though role ownership is already validated.
3. **Keep global privilege explicit.** Continue to separate Super Admin institution-management operations from tenant service queries; avoid treating a supplied institution ID as authorization.
4. **Preserve related-record checks.** Maintain same-institution joins/ownership validation for parent/student relationships, fee assignments, installments, payments, receipts, admissions/documents, and all download paths.
5. **Verify deployment routing/storage controls.** Confirm the production web server enforces the `.htaccess` API/storage restrictions or equivalent server configuration, and that file retrieval remains authorization-gated.

## 9. Tests required to prove isolation

Run these in an isolated test database with at least two institutions and fixtures per user type. Tests should assert both response/exception and that unauthorized writes leave records unchanged.

1. **Tenant identity tampering:** Admin/Staff/Parent requests with another institution ID in URL, query, POST, headers, or client storage must still use the authenticated institution or be denied.
2. **Cross-tenant IDOR matrix:** Try institution B's IDs from institution A for students, parents, classes/batches, admissions/documents, fee structures/assignments/installments, payments/orders/refunds/receipts, announcements, notifications, results, attendance, and staff/roles. Cover read, create, update, delete, export, and report paths.
3. **Parent relationship permissions:** Verify unlinked, inactive, revoked, cross-institution, and permission-disabled relationships cannot read academics/fees or initiate payments.
4. **Role isolation:** A tenant cannot view, edit, delete, deactivate, assign, or change permissions for another tenant's custom role or staff. Include system-role behavior and malformed cross-tenant database associations.
5. **Authentication integrity:** Reject mismatched session and OTP user/institution pairs; reject users linked to another institution's role. Validate revalidation, expiry, revocation, and account/institution status.
6. **Financial ownership:** Use mismatched student/parent/installment/order/payment/receipt IDs across institutions; verify checkout, webhook, retry, reconciliation, refund, and receipt routes cannot cross-settle or disclose data.
7. **File authorization:** Attempt direct and guessed paths/IDs for admission PDFs, receipts, institution logos, and any uploaded documents; verify unauthorized users cannot fetch files and path traversal is rejected.
8. **Reports, searches, filters, exports:** Seed identical names/codes in two institutions and assert dashboard counts, pagination, search, filters, CSV/PDF exports, and totals include only the current tenant.
9. **Notifications and jobs:** Verify queued notifications/reminders and reconciliation for one tenant never select or mutate another tenant's records; verify global cursor processing remains safe across institutions.
10. **Deployment/API checks:** In the production-equivalent web server, confirm `/api` is unavailable and private storage/config/service paths cannot be served directly.

## Conclusion

# PARTIALLY ISOLATED

The reviewed application paths show consistent authenticated tenant scoping and related-record checks, and no confirmed cross-tenant access exploit was found. However, the schema permits mismatched session/OTP tenant ownership and does not enforce tenant pairing for user roles; two role-count queries also omit institution filtering. These concrete gaps mean complete isolation is not yet established. Runtime tests and production deployment checks remain outstanding.
