# Project Requirements Gap Audit

**Project:** Parent & Student Fees Management System  
**Audit basis:** `C:\Users\Bishal Ghosh\Downloads\Parent_Student_Fees_Management_System_Developer_Task.pdf`, the current project source/schema/documentation, and the safe tests listed below.  
**Scope:** Read-only application audit. The only project file created for this task is this report.

## Executive summary

The application has substantial working foundations: parent and staff email-OTP authentication, server-side session controls, institution-scoped student/parent and fee services, monthly/quarterly fee assignments, Razorpay capture verification and webhook handling, administrative collection views, and audit logging. The project also contains registration/admission PDFs, payment-receipt PDFs, and saved institution branding; those features are treated as complete based on the project owner's explicit verification and were not re-tested or reviewed in this audit. Mobile OTP is not required and is not counted as a gap.

The most important gaps are incomplete academic workflows (parent timetable/examination pages are placeholders, the admin schedule/examination pages are list-only, and no results or attendance implementation was found), incomplete fee adjustment/refund workflows, incomplete notification delivery, and a lack of evidenced backup/restore procedures. Financial processing has useful locking, server-side verification, and uniqueness constraints, but its tests are largely isolated/static and do not establish database-level concurrency or delayed-gateway behavior. Checkout retry logic cancels a locally stale attempt and reuses its gateway order without first querying that order's current gateway state; this is a recovery risk to resolve with an integration test and appropriate reconciliation behavior, not evidence that an existing payment is wrong.

No source code, application configuration, schema, or financial records were modified. No live database records, real email transport, payment gateway, refund endpoint, or browser session were exercised.

## Status counts

| Status | Count |
|---|---:|
| COMPLETE | 14 |
| PARTIAL | 25 |
| MISSING | 3 |
| UNVERIFIED | 0 |
| **Total requirements assessed** | **42** |

The two PDF features and saved institution branding are marked COMPLETE solely from the owner's stated verification and are expressly excluded from further review/testing here.

## Requirement-by-requirement checklist

### A. Authentication, authorization, and data access

| # | Requirement | Status | Evidence and finding | Remaining gap / next action |
|---:|---|---|---|---|
| 1 | Parent email OTP login | **COMPLETE** | `auth/parent-login.php`, `services/otp-service.php`, and `tests/parent_email_authentication_test.php` implement and test email-only challenge creation, masked destinations, attempt limits, cooldowns, and non-disclosure. | None for the documented email flow. Mobile OTP is explicitly not required and is not a gap. |
| 2 | Staff/admin authentication | **COMPLETE** | `auth/login.php`, `includes/auth.php`, and `tests/staff_email_authentication_test.php` separate staff and parent login, resolve institution/account roles, and reject inactive, unverified, ambiguous, or mismatched accounts. | No password-login requirement was inferred beyond the source brief; staff login is email OTP. |
| 3 | Session security and logout | **COMPLETE** | `includes/init.php` configures strict, cookie-only sessions, HTTP-only/SameSite cookies, secure cookies in production/HTTPS, and session regeneration; `includes/auth.php` revalidates and revokes database sessions on logout. `tests/authentication_helpers_test.php` covers core helpers. | Production deployment must still terminate TLS correctly so HTTPS is visible to PHP. |
| 4 | Role/permission authorization on protected routes | **PARTIAL** | `includes/auth.php` maps admin and parent routes to permission keys and checks the authenticated institution; the role/permission schema and read-only view exist. | The permission administration UI is read-only, and route-specific coverage is not comprehensive. Build an explicit permission matrix and test direct access to every sensitive route/action. |
| 5 | CSRF, input validation, output escaping, and prepared SQL | **PARTIAL** | `includes/csrf.php`, mutation handlers, `services/student-management-service.php`, `services/parent-management-service.php`, and payment handlers use CSRF checks, server-side validation, prepared statements, and output escaping. The safe tests cover representative paths. | Evidence is not an exhaustive route audit or integration test. Add systematic tests for all state-changing handlers, including denial cases and malformed/cross-tenant IDs. |
| 6 | Parent access limited to linked children and relationship permissions | **COMPLETE** | `services/parent-portal-service.php` scopes student, fee, payment, announcement, and notification queries by authenticated parent, institution, active relationship, and relevant access flags. `includes/auth.php` also supports `require_student_access()`. | Academic pages are incomplete (see #25–28), but the existing data access rules are relationship-scoped. |
| 7 | Institution-level tenant isolation | **COMPLETE** | `database/schema.sql` uses institution-scoped keys/foreign keys; the parent/admin services use the authenticated institution in queries and validations; `includes/auth.php` exposes institution access checks. | Live multi-institution integration testing was not performed; retain cross-tenant negative tests as the application grows. |

### B. Admissions and student/parent management

| # | Requirement | Status | Evidence and finding | Remaining gap / next action |
|---:|---|---|---|---|
| 8 | Admin student registration, editing, status management, and institution-scoped academic selections | **COMPLETE** | `services/student-management-service.php` provides validated create/update/list/detail operations; admin student routes and the schema cover student profile, course/class/batch/year, admission date, and status. The existing workflow is institution-scoped. | No additional feature action identified from the inspected paths. |
| 9 | Unique student/admission ID generation and duplicate prevention | **PARTIAL** | The student service requires a supplied `student_code`; `database/schema.sql` enforces institution-scoped uniqueness, so duplicate inserts are rejected even under a race. | IDs are not generated by the service: document or implement the intended assignment mechanism. Add a database-backed concurrent duplicate test if generation is introduced. |
| 10 | Parent/guardian records and student relationships | **COMPLETE** | `services/parent-management-service.php` validates contact/relationship fields, checks institutional ownership, synchronizes links, records revocation rather than deleting relationship history, and invalidates sessions/challenges when appropriate. `tests/parent_management_test.php` exercises validation and source-level safeguards. | No material gap identified in the inspected parent-management workflow. |
| 11 | Student photo upload/storage, if supported | **PARTIAL** | `students.photo_path` is present in `database/schema.sql` and parent/student reads can return it. | No photo-upload workflow was established from the inspected student-management surface. Either implement secure upload/display or document that the field is reserved and unused. |
| 12 | Admission/registration PDF | **COMPLETE (owner-verified; excluded)** | The project owner explicitly states this PDF is implemented and working. The audit did not inspect, execute, or revalidate its renderer or endpoints. | None in this audit; no further PDF work should be inferred from this report. |

### C. Fees and financial integrity

| # | Requirement | Status | Evidence and finding | Remaining gap / next action |
|---:|---|---|---|---|
| 13 | Fee structures, assignments, and monthly/quarterly plans | **COMPLETE** | `services/admin-panel-service.php` validates institution-owned references and saves fee structures transactionally; `services/fee-assignment-service.php` handles scoped assignment and schedule creation; `tests/fee_assignment_test.php` covers monthly/quarterly date and amount arithmetic. | Annual cadence is assessed separately in #14. |
| 14 | Annual payment plan, where required | **COMPLETE** | The original brief treats annual cadence as optional; monthly and quarterly are implemented, and `fee_assignment_test.php` confirms unsupported annual plans are rejected rather than silently accepted. | No action required unless the institution adopts annual plans as a business requirement. |
| 15 | Installment generation, tracking, and overdue indicators | **PARTIAL** | `services/fee-assignment-service.php` generates schedules transactionally and returns safely when a schedule already exists; the schema stores payment, adjustment, discount, and status fields. Admin lists and reports show installment data. | `cron/generate-installments.php` and `cron/send-fee-reminders.php` are empty files. Generation is manual; automatic overdue/status/reminder operation is not evidenced. |
| 16 | Partial payments | **PARTIAL** | Integer-paise balance helpers and settlement logic support an installment with a prior partial payment (`tests/payments/razorpay_payment_test.php`). The checkout route does not accept a browser-supplied amount and creates an order for the authoritative remaining installment balance. | The UI does not establish a parent-selected partial amount or a complete partial-payment workflow. Confirm the intended policy and test an actual partial settlement against a database-backed ledger. |
| 17 | Discounts, concessions, late fees, and fee adjustments | **PARTIAL** | `database/schema.sql` contains discount/adjustment/penalty fields and tables; balance/report queries account for those fields. `admin/fees/discounts.php` and `admin/fees/adjustments.php` are history/list views through `includes/admin-simple-list.php`. | No create/approve/apply workflow was found for discounts or adjustments, and no automated late-fee policy was established. Add authorized transactional actions and approval/audit rules if required. |
| 18 | Outstanding balances, collection totals, and financial reporting | **PARTIAL** | `services/admin-panel-service.php` provides collection, outstanding, student-wise, and parent-wise reports. Collection totals use verified payments minus processed refunds; assignment reports use saved totals. | Several reports are capped (typically 250–300 rows), student/parent reports lack comparable date filters, and the code explicitly relies on stored assignment aggregates. Add export/pagination and ledger-versus-aggregate reconciliation evidence. |
| 19 | Razorpay order creation, capture verification, and webhook validation | **COMPLETE** | `services/payment-service.php`, `services/payment-verification-service.php`, and `integrations/payment-gateway/webhook.php` scope order creation, lock financial rows, fetch/verify captured gateway data, validate signatures, and update settlement state. `tests/payments/razorpay_payment_test.php` covers fixture signatures, amount/currency/order matching, and route/source safeguards. | The test is not a live gateway or full database transaction test; no live payment was attempted. |
| 20 | Idempotency and duplicate callback/payment protection | **PARTIAL** | `database/schema.sql` has uniqueness constraints for order idempotency, gateway order/payment transaction, webhook event, and receipt-per-payment records. Settlement code uses transactions/locks; the payment test asserts these source/schema safeguards. | No concurrent database integration test was run. Verify duplicate and out-of-order callbacks against MySQL, including behavior when two different captured transactions arrive for one order. |
| 21 | Pending, failed, delayed payments and recovery | **PARTIAL** | `services/reconciliation-service.php` checks Razorpay orders in `created`/`authorized` states; the CLI endpoint `cron/reconcile-pending-payments.php` is available, and parent status checks can re-query the gateway. | Checkout retry marks stale local attempts cancelled after 15 minutes and may reuse the same gateway order without first fetching its current state. Reconciler excludes `pending` orders. Test and harden delayed capture/retry and orphan-pending recovery; verify actual scheduler configuration. |
| 22 | Refund processing and financial adjustments | **PARTIAL** | Refund records/statuses are represented in `database/schema.sql`; `services/admin-panel-service.php` and `admin/payments/refunds.php` provide institution-scoped review/history, and reports subtract processed refunds. | The page says it does not trigger or record a gateway refund; no refund request/approval/processing service was found. Implement only if required, with gateway idempotency, balance locks, explicit statuses, and audit history. |
| 23 | Payment history and downloadable payment-receipt PDFs | **COMPLETE (owner-verified; excluded)** | The project owner explicitly states parent receipt listing and downloadable receipt PDFs are implemented and manually verified. The audit did not inspect, run, or revalidate those PDF workflows. | None in this audit; no receipt download testing or PDF recommendations are included. |
| 24 | Financial auditability | **PARTIAL** | `services/audit-service.php` writes structured actor/action/entity and before/after data; fee, payment, parent, and announcement paths call it. `admin/audit` is institution-scoped. | Coverage is not demonstrated for every financial mutation, and refund/discount/adjustment mutation workflows are absent. Define retention/access policy and test audit creation atomically with each financial change. |

### D. Academic information and communication

| # | Requirement | Status | Evidence and finding | Remaining gap / next action |
|---:|---|---|---|---|
| 25 | Timetable creation/management and parent access | **PARTIAL** | `database/schema.sql` has `timetables`; `admin/academics/timetables.php` displays the generic institution-scoped list. `parent/academics/timetable.php` calls `render_page_placeholder()`. | No timetable create/edit workflow or parent timetable view was found. Implement scoped CRUD and parent reads gated by `can_view_academics`. |
| 26 | Examination scheduling and parent access | **PARTIAL** | `database/schema.sql` contains `examinations`; `admin/academics/examinations.php` is a generic list. `parent/academics/examinations.php` renders a placeholder. | Add admin scheduling/editing and parent-scoped exam views if required. |
| 27 | Examination results/grades | **MISSING** | No examination-results/grades table or parent/admin result route was found in the inspected schema and academic routes. | Define the result model, permissions, entry workflow, and parent visibility rules before implementation. |
| 28 | Attendance | **MISSING** | `parent/attendance.php` explicitly indicates attendance is not supported by the current database schema; no attendance table or attendance workflow was identified. | Confirm attendance requirements, then design institution/class/batch-scoped storage and access rules. |
| 29 | Announcements and parent notifications | **PARTIAL** | `admin/notifications/create.php` supports draft/published announcements, optional scheduling, and batch targeting; the admin list supports publish/unpublish. `services/parent-portal-service.php` returns institution/target-scoped announcements and recipient-scoped notifications. | No notification sender/processor was found; `admin/notifications/send.php`, `cron/process-notifications.php`, and `cron/send-fee-reminders.php` are empty. Existing announcements can be read, but delivery/notification creation is not evidenced. |

### E. Administration, reporting, and operations

| # | Requirement | Status | Evidence and finding | Remaining gap / next action |
|---:|---|---|---|---|
| 30 | Admin dashboard and management pages | **PARTIAL** | Admin dashboard/report/service routes exist; students, parents, fee structures, courses/batches, academic years, and announcements have management paths. `includes/admin-simple-list.php` makes several other sections list-only. | Classes, timetable, examination, discount/adjustment, and some other listed sections lack comparable create/edit actions. Complete the required CRUD flows rather than treating a list page as management. |
| 31 | User/staff and role management | **PARTIAL** | `admin/staff/index.php` lists accounts and can activate/deactivate staff; `admin/staff/create.php` and `admin/staff/edit.php` render placeholders; `admin/staff/permissions.php` is read-only. | Staff creation/editing and permission/role assignment are not available through these routes. Implement controlled account/role management or document the external provisioning process and test least privilege. |
| 32 | Fee collection, dues, student-wise, parent-wise, and institution reports | **PARTIAL** | `includes/admin-report-page.php` and `services/admin-panel-service.php` provide four institution-scoped financial reports. | Results have fixed upper bounds and limited filtering/export; no comprehensive report test or reconciliation against the underlying ledger was run. |
| 33 | Institution-specific report/data separation | **COMPLETE** | Report queries in `services/admin-panel-service.php` bind the active `institution_id`; related joins use matching institution IDs. | Live cross-institution requests were not exercised; preserve this invariant in future report endpoints. |
| 34 | Search, filtering, and pagination | **PARTIAL** | Parent management provides pagination and escaped search; payment listing provides status/search/page controls. Several simple lists/reports instead use fixed limits (e.g., 200–500) without pagination. | Add consistent pagination/filtering to large admin lists and reports; test boundary values and cross-tenant searches. |
| 35 | Database backup and restoration procedures | **MISSING** | No backup-named project script or restore procedure was found in the reviewed project tree/documentation. This is evidence about repository artifacts only, not external hosting backups. | Document backup frequency, encrypted/off-site retention, owner, and restore drills; verify actual hosting backups separately. |
| 36 | Error handling and operational logging | **PARTIAL** | `includes/init.php` suppresses browser error display and installs a safe uncaught-exception handler; `includes/functions.php`/`services/audit-service.php` provide logging/audit patterns, and sensitive diagnostic fields are sanitized in tested OTP paths. | No central operational monitoring, alerting, retention policy, or broad log-redaction review was established. Add monitoring/runbook evidence and verify production log permissions/rotation. |
| 37 | Scheduled jobs and reconciliation tasks | **PARTIAL** | `cron/reconcile-pending-payments.php` is a CLI-only job with structured summary/error handling. | No scheduler configuration is in the repository; installment generation, fee reminders, and notification processing scripts are empty. Confirm host scheduling and implement required jobs with safe retry/idempotency. |
| 38 | Database schema and migration consistency | **PARTIAL** | `database/schema.sql` defines the current schema; one manual migration exists at `database/migrations/20261013_add_email_verification_otp_purpose.sql`. The schema already contains the email-verification purpose. | No migration runner/version table or evidence that the manual migration has been applied was found. Establish reproducible schema-version tracking and compare a disposable database to the canonical schema. |
| 39 | Automated test and integration coverage | **PARTIAL** | Fourteen PHP CLI test scripts exist. Ten safe non-PDF scripts passed in this audit (details below), including fee arithmetic, parent/staff OTP helpers, admin validation, and Razorpay fixtures. | The suite relies heavily on pure-function/source-string assertions; database concurrency, full HTTP authorization, scheduled jobs, real gateway integration, restore procedures, and end-to-end workflows remain unverified. |
| 40 | Production configuration, secret management, and HTTPS | **PARTIAL** | `config/app.php` reads process/`.env` values and limits debug mode to non-production; `config/database.php` restricts this checkout to `fees_management_test`; `.htaccess` blocks internal directories and hidden/config artifacts; `includes/init.php` marks production cookies Secure. | No deployment evidence proves TLS termination/HTTPS enforcement, production secret provisioning/rotation, database privilege isolation, or web-server rules outside Apache. Complete and verify a production hardening checklist. |
| 41 | Installation/deployment documentation | **PARTIAL** | `README.md`, `tests/README.md`, `docs/mailpit-development.md`, and `composer.json` describe dependencies and local email testing. | Documentation is development-focused and does not provide a production install, migration, scheduled-job, backup/restore, monitoring, or HTTPS runbook. |
| 42 | Institution logo and branding | **COMPLETE (owner-verified)** | The project owner states institution logo upload/branding is implemented. `README.md` documents saved `institutions.logo_path`, private `storage/institution-logos/` storage, allowed PNG/JPEG formats and authenticated institution-specific display; `tests/admin_panel_test.php` includes upload-validation/path checks. | Branding was not independently revalidated in this audit beyond reading the existing README and safe admin test. |

## Five highest-priority remaining tasks

Ranked by combined financial, security, operational, and functional risk:

1. **Harden delayed-payment recovery and retries.** Reconcile the gateway order before treating a local attempt as stale/cancelled or reusing it; cover orders left in `pending`; add MySQL integration tests for delayed capture, duplicate callbacks, concurrent attempts, and conflicting transactions. No payment was initiated during this audit.
2. **Complete and test account/permission administration.** Replace staff create/edit placeholders with authorized workflows or document external provisioning; test the permission matrix and direct requests for every admin mutation and sensitive record route.
3. **Implement the required academic workflows.** Add timetable and examination management/parent views, then model results and attendance if required by the original brief; enforce institution, relationship, and `can_view_academics` scopes.
4. **Establish recovery operations.** Document and test database backups/restores, configure monitored scheduled execution, and implement the required installment/reminder/notification jobs with idempotent behavior.
5. **Complete financial adjustments and reporting controls.** If the brief requires them, add audited approval/application workflows for discounts, penalties, adjustments, and refunds; reconcile cached assignment totals against payment/installment ledgers and provide scalable report pagination/export.

## Test execution and limitations

PHP CLI used: `C:\laragon\bin\php\php-8.3.26-Win32-vs16-x64\php.exe`.

| Command | Result |
|---|---|
| `php tests\admin_panel_test.php` | Passed: 27 admin panel checks. |
| `php tests\authentication_helpers_test.php` | Passed: 42 authentication helper checks. |
| `php tests\email_otp_delivery_test.php` | Passed: 18 mocked email/OTP checks; no email was sent. |
| `php tests\fee_assignment_test.php` | Passed: 21 fee assignment checks. |
| `php tests\otp_verification_modal_test.php` | Passed: 18 OTP modal checks. |
| `php tests\parent_email_authentication_test.php` | Passed: 57 parent email authentication checks. |
| `php tests\parent_email_verification_test.php` | Passed: 40 parent email verification checks; delivery callbacks are mocked. |
| `php tests\parent_management_test.php` | Passed: 34 parent management checks. |
| `php tests\staff_email_authentication_test.php` | Passed: 49 staff authentication checks. |
| `php tests\payments\razorpay_payment_test.php` | Passed: 34 Razorpay fixture/source checks; no gateway request was made. |
| **Total** | **10/10 scripts passed; 340 checks reported.** |

Deliberately not run:

- `tests\admission_form_pdf_test.php` and `tests\receipt_pdf_test.php`: excluded by instruction not to test or revalidate either PDF feature.
- `tests\parent_portal_test.php`: includes assertions for both PDF download surfaces, so it was not run.
- `tests\student_management_test.php`: includes admission-PDF assertions, so it was not run.

The full suite was therefore not run. No live database query, destructive migration, seed, reset, test payment, refund, real OTP email, PDF rendering, or browser/visual check was performed. Test outcomes above establish only the behavior covered by those isolated scripts; they do not establish production readiness or end-to-end integration.

## Potential data consistency and integration risks

- **Payment attempt state versus gateway state:** checkout marks a stale `initiated`/`pending` attempt cancelled based on local `updated_at` and reuses the existing gateway order. Reconciliation queries gateway-backed orders in `created`/`authorized` states, not local `pending` orders. Delayed callbacks, browser retries, and partially completed order creation need transactional integration tests.
- **Stored aggregates versus ledger:** parent/student dashboards and several reports read `student_fee_assignments.total_paid` and `outstanding_amount`; installment/payment records are also maintained. The settlement code updates these records, but this audit did not query live balances or verify historical aggregate consistency.
- **Refund/adjustment completeness:** collection reporting subtracts refunds marked `processed`, but the repository exposes refund history rather than a gateway refund workflow. Discount and adjustment screens are also history/list views. Do not interpret schema fields or report arithmetic as proof that users can safely perform those operations.
- **Manual identity assignment:** student-code uniqueness is database-enforced per institution, but the application requires staff to supply the code. The system's intended code issuance process is not documented in the inspected service.
- **Migration drift:** there is one manual SQL migration and no visible migration runner/version ledger; the migration's OTP enum purpose is present in the canonical schema, but actual deployed database state was not queried.
- **Operational automation:** the reconciliation CLI exists, but scheduler configuration is not in the repository; other named cron files are empty. No evidence establishes that reconciliation or reminders are running in the deployment.

These are source/schema-based risks, not confirmed defects in live financial records.

## Recommended implementation sequence

1. Agree on intended business policies for annual plans, partial payments, adjustments/discounts, refund processing, and academic modules.
2. Add isolated database integration tests around payment creation, delayed capture/retry, settlement, duplicate webhook delivery, and aggregate reconciliation before changing financial code.
3. Complete account/role administration and route-level authorization tests.
4. Implement missing academic CRUD/read workflows with tenant and parent-relationship permissions.
5. Implement only the required adjustment/refund and notification processes, with transaction boundaries, idempotency, audit events, and safe retries.
6. Add migration version tracking and a repeatable disposable-database schema test.
7. Configure/verify scheduled jobs, monitoring, backup retention, and restore drills.
8. Publish production deployment documentation, including TLS, secrets, database privileges, and log handling.

## Explicit exclusions

- **Student registration/admission PDF:** treated as COMPLETE based on the owner's statement; not reviewed or tested.
- **Payment receipt PDF/download:** treated as COMPLETE based on the owner's statement; not reviewed or tested.
- **Mobile OTP:** not required; absence is not a gap.
- No production/payment data, payment/refund actions, schema changes, configuration changes, or application-code changes were made.
