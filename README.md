# Student registration PDFs and institution branding

Admission form PDFs are generated from saved student and linked parent records with
Dompdf. Install the locked dependencies with `composer install`.
Parents can view or download a linked child's saved form from the child details page;
the PDF route requires an active same-institution relationship with academic access.
The document is laid out for one portrait A4 page and wraps long field values.

Institution logos can be uploaded from **Admin → Settings → General** as PNG or JPEG
images up to 2 MB. They are stored under the non-public `storage/institution-logos/`
directory with randomized filenames and an institution-scoped relative path in
`institutions.logo_path`. The authenticated logo endpoints serve only the current
user's institution image; the same saved image is used in the admin and parent headers
and admission PDFs. PHP needs the `fileinfo` and `gd` extensions to validate uploads.

The application requires PHP 8.x with the `dom` and `mbstring` extensions enabled.

## Razorpay server configuration

Production uses Razorpay live keys by default. To run sandbox transactions from a
production-hosted site, set `RAZORPAY_ALLOW_TEST_KEYS_IN_PRODUCTION=true` in the
server environment and configure matching Razorpay test API credentials. This
only enables sandbox/test-key mode; payments are not live transactions. Keep the
setting false or unset for live payments, and use the matching test webhook
secret if webhooks are configured.

Run the focused checks with:

```text
php tests/student_management_test.php
php tests/admission_form_pdf_test.php
```

## Examination results

Admins can manage examinations from **Admin → Academics → Examinations**, enter
or update marks per student, and publish or unpublish result sets. Parents can
view only published results for linked children whose relationship grants
academic access. Percentages are calculated from marks earned and maximum marks.
The project has no configured pass/fail threshold or grading scale, so results do
not label students as passed or failed.

For an existing database, back it up and inspect the `examinations` table before
applying `database/migrations/20261014_add_examination_results.sql`. The migration
adds optional academic-session, maximum-mark, and publication fields; existing
examinations remain unpublished and keep NULL values for the new fields until an
admin edits them. It also creates the institution-scoped `examination_results`
table with a unique result per student and examination. Do not run the migration
against a database already initialized from the updated `database/schema.sql`.

Run the focused checks with:

```text
php tests/examination_results_test.php
php tests/parent_portal_test.php
php tests/admin_panel_test.php
```

## Multi-tenant identity integrity

Fresh installations created from `database/schema.sql` enforce same-institution
session and OTP ownership with composite foreign keys. Database triggers also
ensure user roles are either the matching global system role or a custom role
owned by the user's institution; custom roles are only assignable to staff.
Role assignment counts are institution-scoped in the role-management service.

For an existing MySQL 8.4 database, first create and verify a full backup, then
run the read-only integrity preflight for sessions, OTPs, role assignments, and
role definitions. Resolve any reported rows through an approved data decision
before applying `database/migrations/20261103_enforce_tenant_identity_integrity.sql`.
The migration has blocking preflight checks and MySQL DDL implicit commits. Then
apply `database/migrations/20261103_enforce_case_sensitive_system_roles.sql` to
align global role comparisons with the application's exact role-name checks.
Apply both, in order, once to a local development/test database, not production.
Keep the verified backup until post-migration checks and authentication/RBAC
tests pass. Restore the backup if a partial DDL failure cannot be safely repaired.

The database-backed constraint test must run only against a disposable database
created from a verified backup:

```text
FEES_ISOLATION_TEST_DB=fees_backup_verify_YYYYMMDD_HHMMSS php tests/multitenant_integrity_test.php
```

The test inserts two temporary institutions and their user/role fixtures; drop
the disposable database after the test. The payment and receipt code is outside
the scope of this migration.

### Parent institution affiliation corrections

The parent edit page exposes **Institution Affiliation** only to an active
system Super Admin. Before enabling the cross-institution correction workflow
on an existing MySQL database, take and verify a full backup, then apply
`database/migrations/20261103_enable_parent_affiliation_transfer.sql` after
the tenant identity integrity migrations. Fresh installations receive the
matching constraints and triggers from `database/schema.sql`.

The workflow requires explicit confirmation and selection of every linked
student. It blocks transfers when a parent or student has institution-bound
financial, attendance, admissions, notification, or academic records, or when
a student has an academic-year/course/class/batch assignment or another parent
link. No payment, receipt, refund, or academic history is transferred. Parent
relationship rows are retained with their IDs and permissions, re-scoped in
the same transaction, and the previous affiliation is recorded in the audit
log. Existing parent sessions are revoked and pending OTP challenges
invalidated; the parent must sign in again after a successful correction.

Run the integration test only against a disposable database initialized from
the updated schema or a verified backup:

```text
FEES_AFFILIATION_TEST_DB=fees_affiliation_verify_YYYYMMDD_HHMMSS php tests/parent_affiliation_test.php
```

The migration rollback is
`database/migrations/20261103_enable_parent_affiliation_transfer.down.sql`.
It refuses rollback while parent/account, session, or OTP tenant associations
are inconsistent. Review all transfers and take a new backup before rollback.

### Parent creation institution assignment

Parent creation resolves institution ownership on the server. Super Admins must
select an active institution before the form is shown; Institution Admins and
authorized Staff are restricted to the active institution assigned to their
authenticated database account. Staff must have `parents.create` (or the
existing system-role permission fallback). The submitted institution is
revalidated, parent accounts always use the canonical system Parent role, and
student links are checked against the selected institution inside the creation
transaction.

Run the integration test only against a disposable database initialized from
the current schema and seed:

```text
FEES_PARENT_CREATION_TEST_DB=fees_parent_creation_verify_YYYYMMDD_HHMMSS php tests/parent_creation_test.php
```

## Attendance management

Admins with academic-management permission can mark attendance at
**Admin → Academics → Attendance**, review history, and generate class and
student attendance summaries. The register uses each student's existing
institution, academic year, class, and batch assignment; only admitted or active
students in an eligible batch and date range are included. Late is counted as
attended in percentage calculations. Each student/batch/date has one record;
corrections update that record and are written to the audit log.

For an existing database, back it up and apply
`database/migrations/20261014_add_attendance_records.sql` once. The migration
creates the attendance table and institution-scoped uniqueness/foreign-key
constraints; it does not alter existing records. Rollback is destructive to
new attendance entries and is documented in the migration itself. Do not run it
against a database already initialized from the updated `database/schema.sql`.

Run the focused check with:

```text
php tests/attendance_management_test.php
```

The parent attendance page remains unavailable; this release adds admin
attendance management only.

## Staff roles and permissions

The Staff → Role Permissions page manages institution-scoped custom roles using
the existing `roles`, `permissions`, `role_permissions`, and `users.role_id`
tables. Canonical Super Admin, Admin, Staff, and Parent roles remain protected;
custom roles can only be assigned to staff accounts in the same institution.
Changes are audited, take effect on the staff member's next request, and cannot
delete or deactivate an assigned role.

For an existing development or test database, back it up and review existing
role records before applying `database/migrations/20261102_add_rbac_management.sql`.
The migration adds institution scope and active status to roles and seeds the
granular permission catalog. The matching `.down.sql` refuses to remove custom
roles. Do not apply either migration to a production database from this change.
Fresh installations should use the updated `database/schema.sql` and
`database/seed.sql` instead of applying the migration on top.

Run the focused checks with:

```text
php tests/authentication_helpers_test.php
php tests/staff_email_authentication_test.php
php tests/admin_password_authentication_test.php
php tests/parent_email_authentication_test.php
php tests/admin_panel_test.php
```