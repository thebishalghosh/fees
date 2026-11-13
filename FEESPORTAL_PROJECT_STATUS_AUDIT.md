# FeesPortal Project Status Audit

**Audit date:** 2026-11-13  
**Project root:** `C:\laragon\www\fees`  
**Review type:** Read-only code, schema, migration, and test audit  
**Changes made:** This report only. No application code, configuration, migration, or database record was changed.

## Audit method and limits

- Inspected the institution, staff/RBAC, platform-admin, academic, student, admission, and parent route/service implementations; the canonical schema; relevant migration scripts; and existing audit/design notes.
- Ran PHP syntax validation for 241 project PHP files outside `vendor/`, JavaScript syntax validation for all 6 project JavaScript files, and 31 non-receipt PHP test scripts.
- Did not connect to a database, run a migration, exercise an HTTP route, send email, call a payment gateway, upload a file, or perform manual browser verification. Existing DB integration tests are opt-in and require a named disposable database. The receipt PDF test connects to the configured database and was deliberately not run.
- This is a code-based audit, not a penetration test or a proof of production readiness. The live schema, data, web-server configuration, database grants, scheduled jobs, and deployment settings remain unverified.
- Some existing audit documents predate the current implementation. In particular, `PROJECT_AUDIT.md` describes attendance, results, RBAC, and academic functions as missing or list-only; current source includes these features. Treat that document and the older isolation audit as historical context, not the current status.

## 1. Executive Summary

FeesPortal has a substantial procedural PHP foundation: institution-scoped student and staff management, role/permission checks, academic-year configuration, model-specific academic setup, enrolment and individual promotion, admissions review/conversion, attendance and examination-result administration, a separate Platform Admin identity/session system, institution branding, and audit logging.

**Overall maturity: partially implemented, with important runtime and workflow validation still outstanding.** Module 1 has a broad working code foundation but lacks branch/campus management, a complete account-recovery flow, full institution lifecycle actions, and comprehensive runtime verification. Module 2 has student CRUD and academic enrolment history plus an admission review/conversion path, but lacks an end-to-end application intake, document handling, generated student IDs, student leave, ID cards, and certificate/document management.

The strongest evidence from this audit is source implementation and passing focused CLI tests. Most tests are lightweight checks of pure helpers, source structure, or validation rules; they do not prove MySQL persistence, full HTTP authorization, browser behavior, or deployed multi-tenant isolation. Five tests explicitly skipped their database-backed integration portions, and no DB migration or live data state was inspected.

No confirmed cross-tenant exploit was established in this code review. However, the schema still has several ID-only user/actor references, and the academic migration can intentionally retain legacy batch constraints when it finds inconsistent rows. These are integrity and defense-in-depth concerns requiring disposable-database verification and data review.

## 2. Module 1 Status — Institution Administration and System Foundation

| Feature | Status | Evidence and remaining gap |
|---|---|---|
| Institution profile and configuration | **Implemented; automated checks passed, runtime unverified** | Institution profile read/update and logo operations exist in `admin/settings/` and `services/admin-panel-service.php`. Profile fields are institution-scoped. The profile/settings surface is not a general settings editor. |
| Multi-campus and branch management | **Not implemented** | No campus/branch schema, service, or route was found. |
| Institution-wise branding and settings | **Implemented; automated checks passed, visual/runtime test outstanding** | `institution-branding-service.php` validates and stores institution-specific color/login settings in `system_settings`; logo storage/serving is institution-scoped. The layout emits validated CSS theme variables. Broader tenant-configurable settings (timezone, currency policy, formats, notification preferences) are not established. |
| Academic year creation and lifecycle | **Partially implemented** | `admin/academics/years.php` supports draft creation, activation, selection of the active year, and closing. No bulk rollover or year-copy workflow is present. Individual student promotion exists separately. |
| Class, section, subject, course/programme management | **Implemented in code; database integration unverified** | Academic-management pages and services cover year offerings, school sections, programmes/departments/terms, subject catalog/mappings, and coaching batch groups. Legacy course/batch paths coexist with the newer academic model and need integration testing. |
| Role-based access control and granular permissions | **Implemented; focused static checks passed, end-to-end matrix outstanding** | `includes/auth.php` maps routes/actions to permission keys; `role-management-service.php` and `admin/staff/permissions.php` implement tenant custom roles and granular assignments. System roles are protected. A comprehensive direct-request permission matrix was not run. |
| Admin and staff account management | **Partially implemented** | Staff create/edit/status/role assignment exists. Institution onboarding creates an initial Admin. A full Admin-account directory/provisioning lifecycle is not evident; account recovery is also incomplete. |
| Audit logs for administrative activities | **Partially implemented** | Institution and Platform Admin audit writers and an admin audit page exist; many important mutation services record changes. Coverage for every administrative mutation, retention policy, and database-enforced immutability are not established. |
| Password recovery and account security | **Partially implemented** | Password hashing, OTP flows, rate limits, session validation and an authenticated admin password update exist. `auth/forgot-account.php` is a placeholder; no complete unauthenticated account recovery/reset journey was found. Platform Admin password recovery was not found. |
| Platform Admin authentication and dashboard | **Implemented; static tests passed, DB integration skipped** | Separate `platform_admins`, `platform_sessions`, permission grants, bootstrap state, rate limits, and platform audit data exist. Bootstrap/login/logout and institution directory routes are present. No DB-backed test was run. |
| Institution directory and lifecycle management | **Partially implemented** | Platform dashboard supports create/update and active/inactive status. The schema supports `trial`/`archived`, but these lifecycle transitions are not exposed by the current status action; the directory is capped at 500 without pagination. |
| Selected-institution workspace integration | **Implemented in code; runtime authorization unverified** | Platform Admin selects an institution into session context and admin routes resolve tenant identity from that selection. Platform workspace access requires the `institutions.admin` grant. No HTTP/browser or DB-backed cross-tenant test was run. |
| Platform Admin permission gates and audit logging | **Implemented in code; DB/runtime unverified** | Platform permissions are separate from institution RBAC; onboarding actions require explicit permissions and are audited. The initial bootstrap grant set is limited and subsequent grants require platform permission management. DB-level audit immutability was not verified. |
| Dynamic institution-specific themes | **Implemented; source/branding tests passed, visual verification outstanding** | The branding page saves validated color tokens and the shared layout applies them. No browser rendering test was performed. |

### Module 1 interpretation

Core institution profile, platform identity, tenant context, staff RBAC, and academic setup exist in code. This is not a fully finished institution-administration product: branch/campus operations and password recovery are absent, lifecycle actions are narrower than the schema, and many implementation claims remain unverified against a disposable MySQL database or browser.

## 3. Module 2 Status — Student Management and Admission System

| Feature | Status | Evidence and remaining gap |
|---|---|---|
| Student registration and profile CRUD | **Partially implemented** | Institution-scoped student create, edit, list, status, view, parent linking, and admission-form PDF routes exist. The profile is basic; the `photo_path` column has no upload workflow. |
| Admission enquiry/application intake and tracking | **Partially implemented** | Admissions schema and admin list/detail/review routes exist. The admin create/store pages and parent admission create/store surfaces are placeholders, so no complete applicant intake flow was found. Existing records can be tracked/reviewed. |
| Admission review and approval | **Implemented in code; end-to-end database test skipped** | Review status/notes, approval/rejection and conversion to an enrolment are implemented in `admission-conversion-service.php` and admin review pages. Conversion requires the current academic schema and has an opt-in DB integration test that was not run. |
| Student document upload and verification | **Not implemented** | `admission_documents` stores document metadata, but no admission/student document upload, retrieval, verification, or file authorization workflow was found. |
| Automatic student ID/code generation | **Not implemented** | Student code is required as user input; admission conversion also asks an administrator to enter it. Database uniqueness prevents duplicates within an institution, but there is no generator. |
| Student academic history | **Partially implemented** | The schema and service model contain enrolments, placement history, and status history; an institution-wide enrolment page exists. The student detail page does not present a complete per-student history view. |
| Student promotion between academic years | **Implemented in code; not database-tested** | The enrolment page and service support individual promotion to the immediately following unambiguous active year and preserve old/new status and placement history. No bulk promotion/rollover is present; no academic-management DB integration test was run. |
| Student transfer between classes and sections | **Partially implemented** | A school student can change section within the year with placement history. A general class, course, term, batch, or institution transfer workflow was not found. |
| Student leave applications | **Not implemented** | No leave application schema, route, or service was found. |
| Digital student ID cards | **Not implemented** | No ID-card generator, template, or route was found. |
| Student document and certificate management | **Not implemented** | No student-document/certificate management workflow was found. The admission document metadata table alone is not an implemented file-management feature. |

### Module 2 interpretation

Student CRUD and individual academic enrolment actions are meaningful implementations, but the broader admission and student-record lifecycle is incomplete. In particular, review/conversion code is not an end-to-end admissions system while application submission and document handling remain placeholders/missing.

## 4. Database & Multi-Tenant Audit

### Schema and migration findings

- `database/schema.sql` contains tenant-root institution records, users and roles, student/parent/admission data, financial and communication tables, audit data, and the academic-management additions (year-specific offerings, sections, departments/terms, subjects, enrolments, placements, and session-based attendance).
- Many tenant relationships use composite institution keys and scoped unique indexes. Student codes, academic-year names, parent/student links, enrolments, and numerous fee/payment records have duplicate-prevention constraints.
- The schema includes same-institution composite foreign keys for `user_sessions` and `user_otp_verifications`; role triggers enforce canonical system-role/user-type matching and institution ownership for custom staff roles. This reflects the prior remediation report, but the disposable-DB integrity test was not run in this audit.
- `database/migrations/` contains dated SQL migrations, including RBAC, tenant identity hardening, academic management, admission conversion, and Platform Admin security. No migration runner or applied-migration/version ledger was found. Whether the configured/live database has all required migrations is unknown.
- Academic pages explicitly check schema readiness and return an upgrade-required response when required tables/columns are missing. Thus code presence does not establish that a specific installation can use the workflows.

### Tenant isolation and integrity risks

- Reviewed student, staff, parent, academic, admission, and platform operations generally derive tenant identity from the authenticated user or an explicitly permission-gated Platform Admin selection. Representative list/detail/conversion services include institution predicates and same-tenant joins.
- The legacy `batches.course_id` and `batches.class_id` foreign keys in the base schema are ID-only. The academic migration records inconsistent rows in `academic_migration_exceptions` and adds composite batch constraints only when there are no such exceptions. If mismatches exist, legacy constraints can remain; resolve/review those rows and verify final constraints before claiming full database-enforced isolation.
- Other ID-only associations include parent-to-user, admission approver, admission-document uploader, audit actor, and several academic actor references. Application authorization and tenant-scoped lookups mitigate reviewed paths, but the schema does not enforce matching institution for each of these associations.
- No confirmed cross-tenant data disclosure was reproduced or tested. No database rows were read. Cross-tenant negative tests remain necessary, including IDOR checks for student, admission, documents, enrolments, reports, and platform workspace access.
- Core create/update, enrolment, promotion, admission conversion, payment and institutional onboarding paths use transactions in source. No database concurrency or rollback behavior was exercised here.

### Platform/institution separation and recovery

- Platform Admins have distinct tables, sessions, permission grants, bootstrap state, and audit logs; institution users remain in `users` with tenant IDs.
- A legacy `users.user_type = 'super_admin'` and `super-admin/institutions.php` path remain alongside Platform Admin. This is a compatibility/ownership boundary that should be documented and tested rather than assumed to be retired.
- Backup and migration reports describe a prior local backup/import verification. This audit did not verify current backup scheduling, current backup freshness, or restore procedures for the present schema. No database reset or migration was attempted.

## 5. Security Audit

### Positive controls found in source

- Session initialization configures strict-mode, cookie-only sessions with `HttpOnly`, `SameSite=Lax`, and secure cookies when production/HTTPS is detected. Identity changes regenerate the session ID; authenticated DB sessions are revalidated for status, tenant, role, idle and absolute limits.
- Passwords use PHP `password_hash`/`password_verify`. Admin password updates enforce a minimum length and CSRF. Email OTP flows use rate limits and keyed challenge hashes; Platform Admin login has separate account/IP throttling.
- Platform Admin bootstrap is token-gated and transactionally initializes the first administrator and grant set. Platform and institution sessions are separated in storage and authorization.
- State-changing routes inspected use POST and CSRF checks. Service SQL is predominantly prepared-statement based; output uses the shared escaping helper. Dynamic branding values are restricted to six-digit HEX colors before being emitted as CSS.
- Logo upload code validates upload status, MIME/image type, size and dimensions, uses randomized filenames, stores outside public content, constrains paths, and serves through authenticated institution-aware endpoints.

### Weaknesses, risks, and unverified controls

- **Account recovery is incomplete:** the generic forgot-account page is a placeholder and a full password-reset lifecycle was not found. The OTP schema's `password_reset` purpose does not by itself implement recovery.
- **Admission/student file security is absent as a workflow:** metadata exists without upload validation, storage policy, review/verification, or authorized download routes. Do not treat the table as a secure document feature.
- **Database tenant enforcement is uneven:** see the ID-only foreign keys and conditional batch-constraint migration above. This is an integrity/defense-in-depth concern, not a confirmed exploit.
- **Audit-log immutability is unverified:** application code writes audit records, but database grants and external append-only retention were not checked.
- **Deployment controls are unverified:** no production TLS, Apache rewrite/storage restrictions, environment secrets, database privileges, logs, or cron configuration were inspected. No real secrets or credentials are included in this report.
- **XSS/SQLi/authorization coverage is not exhaustive:** the reviewed patterns are positive, but no route-wide fuzzing, malicious-input exercise, penetration test, or cross-tenant HTTP matrix was performed.

**Security conclusion:** useful controls are present in code, but production security and complete tenant isolation are **not verified**. No confirmed exploitable vulnerability is claimed or ruled out by this static audit.

## 6. Testing Report

### Executed validation

- **PHP syntax:** 241/241 project PHP files passed `php -l` (excluding `vendor/`).
- **JavaScript syntax:** 6/6 project JavaScript files passed `node --check`.
- **CLI test scripts:** 30/31 scripts run in the safe, non-receipt set exited successfully; one failed. The skipped integration portions below count as successful script exits, not passed DB assertions.

Successful checks: `admin_panel_test.php` (48), `admin_password_authentication_test.php` (22), `admission_form_pdf_test.php` (20), `attendance_management_test.php` (25), `authentication_helpers_test.php` (84), `email_otp_delivery_test.php` (18), `examination_results_test.php` (16), `fee_assignment_test.php` (22), `institution_branding_test.php` (13), `institution_onboarding_test.php` (16), `institution_profile_access_test.php` (7), `multitenant_integrity_test.php` static checks (3), `notification_service_test.php` (12), `otp_verification_modal_test.php` (18), `parent_affiliation_test.php` static checks (5), `parent_creation_test.php` static checks (3), `parent_email_authentication_test.php` (74), `parent_email_verification_test.php` (40), `parent_management_test.php` (41), `parent_portal_test.php` (36), `platform_admin_auth_test.php` static checks (42), `production_email_delivery_configuration_test.php`, `staff_email_authentication_test.php` (51), `staff_management_test.php` (9), `student_management_test.php` (50), `super_admin_institutions_prg_test.php`, `system_preferences_page_test.php` (4), `admission_conversion_test.php` (6), and `payments/razorpay_payment_test.php` (54).

### Failure

- **`staff_institution_context_test.php` failed.** Its combined branding assertion searches for `authenticated_institution_id(['admin', 'staff', 'parent'])`. The current branding helper also permits the `super_admin` context, so the exact-string assertion no longer matches. The test failure is consistent with assertion drift; it does not demonstrate a runtime failure. The test should be updated to assert intended supported contexts.

### Not run or skipped

- `receipt_pdf_test.php` was not run because it connects to the configured database and requires an existing receipt fixture. The active database target and fixture state were not inspected.
- DB portions of `multitenant_integrity_test.php`, `parent_affiliation_test.php`, and `parent_creation_test.php` were skipped; each requires an explicitly named disposable verification database.
- `admissions/admission_conversion_integration_test.php` skipped because no disposable DB was configured.
- `platform_admin_auth_test.php` ran its static checks; its isolated-MySQL integration was explicitly disabled.
- No academic enrolment/promotion database integration test was found or run.
- No migrations, full DB-backed regression suite, browser/manual test, real email, real gateway, or production-like deployment test was run.
- `tests/README.md` is empty; `composer.json` does not define a test runner/script. Tests are individual PHP CLI scripts.

## 7. Remaining Development Tasks

Effort estimates are rough engineering ranges, not commitments. Database tasks must use an explicitly disposable, verified MySQL database and should start with a backup/preflight.

| Order | Feature / task | Current status and missing functionality | Dependencies | Priority | Estimate |
|---:|---|---|---|---|---:|
| 1 | Verify current academic and tenant schema on disposable MySQL | New schema/migrations and guarded UI exist, but actual DB state is unknown; exercise academic readiness, batch constraints, promotion, admission conversion, rollback and tenant-negative cases. | Disposable DB initialized from canonical schema/seed; no production DB | **Critical** | 1–2 days |
| 2 | End-to-end admission intake and document workflow | Submission surfaces are placeholders; implement applicant submission/tracking, secure document upload/retrieval, verification, and review integration. Reuse existing approval/conversion service; do not duplicate it. | Task 1; product decisions for applicant identity, document types, retention and storage | **High** | 5–8 days |
| 3 | Resolve migration exception and tenant-key gaps | Review `academic_migration_exceptions`; do not silently repair existing rows. Add/verify same-tenant constraints where valid and document unavoidable legacy exceptions. | Verified backup, preflight, approved data decisions | **High** | 2–4 days |
| 4 | Complete account recovery | Replace placeholder forgot-account route with a separate expiring, single-use, rate-limited reset journey for supported identities; revoke existing sessions and audit it. | Email delivery, token lifecycle and account-policy decisions | **High** | 2–4 days |
| 5 | Institution lifecycle completeness | Define and implement trial/archive transitions, restrictions, audit events, and directory pagination; currently UI only activates/deactivates and caps results. | Lifecycle policy and permission mapping | **High** | 2–4 days |
| 6 | Multi-campus/branch management | Add tenant-owned campus/branch data and scoped administration; no current model exists. | Product definition; decide which records are campus-scoped and migration defaults | **High** | 5–8 days |
| 7 | Student academic history and transfer | Show enrolment/placement/status history on student detail; add the required class/course/batch transfer cases beyond same-year school section changes. | Task 1; academic transfer rules and audit semantics | **High** | 3–6 days |
| 8 | Academic-year bulk rollover | Add an explicit reviewable batch workflow for the next year; current system supports individual promotion only. Define exclusions, repeaters, graduates and rollback before coding. | Task 1; next-year offerings and promotion rules | **Medium** | 3–5 days |
| 9 | Student ID/admission number generation | Implement collision-safe institution-scoped generation and format/prefix configuration; currently operators enter values manually. | Numbering policy and concurrency-safe sequence strategy | **Medium** | 1–3 days |
| 10 | Leave applications | Add student/parent submission, admin decision, status history and tenant-scoped access. | Leave policy, eligible submitters and approval rules | **Medium** | 3–5 days |
| 11 | Digital student ID cards | Add card template, authorized preview/download, and optional photo support. `students.photo_path` is not currently backed by an upload workflow. | Task 2/file-storage policy; identity fields and design approval | **Medium** | 2–4 days |
| 12 | Student documents/certificates | Add secure document inventory, upload validation, authorized downloads, certificate issue/revoke history and retention. | Storage/retention/access policy; avoid reusing admission metadata without review | **Medium** | 3–6 days |
| 13 | Admin-account lifecycle | Decide whether institutions need multiple Admins and provide scoped create/edit/disable/reset controls; current onboarding creates one initial Admin and staff CRUD is separate. | Account policy and recovery task | **Medium** | 3–5 days |
| 14 | Audit coverage and operations | Inventory mutation routes for missing audit events; define retention/monitoring and verify DB grants prevent unauthorized alteration/deletion. | Operations and database privilege owners | **Medium** | 2–4 days |
| 15 | Test suite maintenance and coverage | Fix the stale staff-context assertion; add DB integration coverage for admissions, academic enrolment/promotion, RBAC, tenant IDORs, migrations, and transaction rollback. | Disposable MySQL test harness; stable fixtures | **High** | 3–6 days |

## 8. Critical Issues

1. **Admissions are not end-to-end.** Review/approval/conversion are implemented, but admission create/store surfaces are placeholders and applicant document flows are absent.
2. **Migration/runtime state is unknown.** Academic pages depend on the expanded schema; no current database schema inspection or migration execution was performed.
3. **Legacy batch integrity can remain weaker after migration.** The academic migration conditionally preserves existing ID-only batch constraints when preflight exceptions exist. Review exceptions before asserting database-level tenant isolation.
4. **Account recovery is incomplete.** The generic forgot-account surface is a placeholder; password hashing and authenticated password changes do not replace account recovery.
5. **No branch/campus model exists.** Institution administration cannot represent multiple campuses or branch-scoped records.
6. **Important Module 2 records lack lifecycle features.** No leave, ID-card, student document, or certificate workflows were found; student code generation is manual.
7. **Automated regression signal is mixed.** One source-level test fails because an assertion is stale; DB-dependent behavior remains untested. Passing static/source checks should not be interpreted as production verification.
8. **Operational readiness is unproven.** Current backup/restore freshness, hosting scheduler, production web-server restrictions, HTTPS termination, database privileges, and audit retention were not verified.

No critical exploitable vulnerability was confirmed from this audit. The items above are implementation blockers, integrity risks, or missing verification—not evidence of a current breach.

## 9. Recommended Next Task

**First run the academic/tenant integrity and admission-conversion integration suite against a newly prepared disposable MySQL database, then use that verified baseline to implement end-to-end admission intake and secure document handling.**

This is the best next step because the existing admission review/conversion code depends on the expanded academic schema, but current tests skip the database-backed assertions. Establishing that base first avoids duplicating or building intake around an unverified conversion contract. The follow-on admission task addresses the largest confirmed Module 2 gap: there is currently no implemented application submission or document-review path.

Do not begin unrelated new modules until this prerequisite and the Module 1/2 gaps above are reviewed and explicitly approved.

## 10. Overall Completion Matrix

| Module | Completed | Partial | Not Started | Needs Testing |
|---|---|---|---|---|
| Module 1 — Institution administration and system foundation | Core profile, branding, platform identity, staff RBAC and academic setup exist in source; focused checks passed | Branch/campus, account recovery, full lifecycle, broader Admin-account management and audit operations | Multi-campus/branch model | MySQL migration/runtime, permission matrix, platform workspace and browser/theme behavior |
| Module 2 — Student management and admission system | Basic student CRUD; individual enrolment, placement history, promotion; admission review/conversion code | Intake/tracking, student academic-history presentation, transfer, admission lifecycle | Leave, ID cards, student documents/certificates, automatic IDs | Admission conversion and academic promotion DB integrations; end-to-end HTTP and browser flows |

### Feature-level checklist

Status definitions: “verified” below means the stated automated checks were actually run; it does not imply production or browser verification.

- **Fully implemented and verified (within the executed test scope):**
  - PHP syntax validation for 241 project files and JavaScript syntax validation for 6 files.
  - Selected pure/helper, validation, PDF-rendering and static route/source checks listed in Section 6.
- **[~] Partially implemented:**
  - Academic-year rollover (individual promotion exists; no bulk rollover).
  - Admin/staff account lifecycle (staff CRUD exists; broader Admin lifecycle and recovery do not).
  - Institution lifecycle (create/update and activate/deactivate exist; archive/trial actions and pagination are absent).
  - Admission tracking/review as a whole (review/conversion exists; intake and documents do not).
  - Student history and transfer (history model and section changes exist; detail integration and broad transfer do not).
- **Not implemented:**
  - Multi-campus/branch management.
  - Applicant submission workflow; admission/student document upload and verification.
  - Automatic student ID/admission number generation.
  - Student leave, digital ID cards, and certificate/document management.
  - Complete unauthenticated account recovery/reset flow.
- **[!] Implemented but requires testing:**
  - Platform Admin database-backed authentication, permissions, institution selection and audit.
  - Academic schema/migrations, enrolment/promotion, admission conversion, and batch tenant constraints.
  - Institution-specific theme rendering and selected-institution workspace in a real browser/HTTP session.
  - End-to-end route authorization and cross-tenant negative cases.
- **[?] Unable to verify:**
  - Installed/live schema and data consistency, applied migration state, actual production environment configuration, database grants, backup/restore freshness, scheduler configuration, deployed `.htaccess`/web-server behavior, and manual browser status.
