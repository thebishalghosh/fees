# FeesPortal Phase 1 Database Integration Report

**Updated:** 2026-11-13  
**Status:** Disposable fresh-schema integration baseline completed  
**Scope:** Academic management, admission conversion, tenant integrity, and database setup

## 1. Executive Summary

The database integration preflight was completed using only these disposable databases:

- `fees_schema_verify_20261113_112833`
- `fees_admission_verify_20261113_112833`

Both began empty, received the canonical `database/schema.sql` and `database/seed.sql`, and were tested without using the application database `fees` as a test target. The configured `fees` connection was verified read-only; its data and the project `.env` were not changed. The disposable databases and the temporary grants were removed after testing. `fees` remains present.

Final executed checks: **50 database integration assertions passed**, plus **48 static checks passed**. No assertion failed in the final test runs. The Platform Admin database-integration harness and manual browser tests were not run. No dated upgrade migration was executed, so this report verifies a fresh-install baseline—not migration compatibility against a legacy database.

## 2. Provisioning and Database Isolation

- MySQL was running locally (MySQL 8.4.3); the application database connection succeeded with a read-only `SELECT 1`.
- The two exact test database names were absent before provisioning and were created separately.
- `fees_app` connected to each target after provisioning. New grants were limited to those two databases and included only `SELECT`, `INSERT`, `UPDATE`, `CREATE`, `ALTER`, `INDEX`, `REFERENCES`, `CREATE ROUTINE`, `ALTER ROUTINE`, `EXECUTE`, and `TRIGGER`.
- Inspection found **no non-`USAGE` global privileges** for `fees_app`. Existing table-scoped grants on `fees` were observed but not changed.
- The first canonical schema import as `fees_app` failed at trigger creation with MySQL error 1419 under the server's binary-logging settings. The schema was then loaded locally as root for provisioning; the seed was loaded as `fees_app`. No `SUPER` grant or server-setting change was made.
- Both databases were initialized from the canonical schema and seed. The test scripts ran against the disposable schemas, not `fees`.
- After testing, the two exact database grants were revoked and only the two exact disposable databases were dropped. A post-cleanup schema check returned `fees` and neither test database.

### Reproducing the disposable setup

Only recreate these targets if they are absent and explicitly approved for another run:

```sql
CREATE DATABASE fees_schema_verify_20261113_112833
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE fees_admission_verify_20261113_112833
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

GRANT SELECT, INSERT, UPDATE, CREATE, ALTER, INDEX, REFERENCES,
      CREATE ROUTINE, ALTER ROUTINE, EXECUTE, TRIGGER
ON fees_schema_verify_20261113_112833.* TO 'fees_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, CREATE, ALTER, INDEX, REFERENCES,
      CREATE ROUTINE, ALTER ROUTINE, EXECUTE, TRIGGER
ON fees_admission_verify_20261113_112833.* TO 'fees_app'@'127.0.0.1';
```

The DBA must initialize each target with `database/schema.sql` and `database/seed.sql` before tests. Because trigger creation failed for the restricted account on this MySQL configuration, schema initialization requires an approved local DBA/provisioning path. Do not add global privileges, grant `SUPER`, or alter server settings for this test.

Verify database names, grant scope, and application-account access before loading:

```sql
SELECT SCHEMA_NAME
FROM information_schema.schemata
WHERE SCHEMA_NAME IN (
  'fees_schema_verify_20261113_112833',
  'fees_admission_verify_20261113_112833'
);
SHOW GRANTS FOR 'fees_app'@'127.0.0.1';
```

Then connect as `fees_app` to each exact schema and verify `SELECT DATABASE(), CURRENT_USER()` and `SHOW TABLES` before initialization. Do not print or store database passwords in test output.

Safe cleanup, after confirming testing is complete, is restricted to these names:

```sql
REVOKE SELECT, INSERT, UPDATE, CREATE, ALTER, INDEX, REFERENCES,
       CREATE ROUTINE, ALTER ROUTINE, EXECUTE, TRIGGER
ON fees_schema_verify_20261113_112833.* FROM 'fees_app'@'127.0.0.1';
REVOKE SELECT, INSERT, UPDATE, CREATE, ALTER, INDEX, REFERENCES,
       CREATE ROUTINE, ALTER ROUTINE, EXECUTE, TRIGGER
ON fees_admission_verify_20261113_112833.* FROM 'fees_app'@'127.0.0.1';
DROP DATABASE fees_schema_verify_20261113_112833;
DROP DATABASE fees_admission_verify_20261113_112833;
```

These cleanup statements were executed successfully. `fees` was not included in any cleanup command.

## 3. Schema, Relationships, and Migrations

- The schema target contained all **20 of 20 checked academic, enrolment, admission, and relationship tables**.
- `academic_migration_exceptions` contained **0 rows** in the fresh schema target. This does not establish that a legacy database has no exceptions.
- Metadata inspection confirmed tenant-scoped foreign keys across academic years, class/course offerings, sections, academic groups, enrolments, placements, admissions, and parent/student relationships.
- Batch composite relationships were present for institution/class and institution/course ownership. Academic groups and admissions reference tenant-scoped batch/class/course records.
- Tenant-scoped unique keys were present for student/year enrolments, academic offerings and subject mappings, parent/student relationships, and admission conversion enrolment/placement references.
- Admission conversion foreign keys link the admission to an enrolment and placement using institution and academic-year identity columns, not only global row IDs.

**Migrations:** No upgrade migrations were run. The two targets were fresh installs using the canonical schema and seed. Therefore:

- Executed migrations: **0**
- Migration errors: **0 (none attempted)**
- Upgrade-migration dependency compatibility: **not runtime-tested**
- Legacy-data migration exceptions: **not assessed**

Applying dated migrations on top of the canonical schema was intentionally avoided because they are upgrade paths and may duplicate schema changes.

## 4. Academic Management and Admission Integration

### Academic management

The retained disposable-database test is [academic_management_integration_test.php](./tests/academic_management_integration_test.php). It passed **16 checks**, including:

- Creating/activating/selecting academic years and rejecting selection of a draft year.
- Creating and persisting class, section, subject, and subject mappings.
- Completing school academic configuration.
- Creating student enrolments and verifying placement records.
- Promoting to the immediate next year while preserving source and destination placement history.
- Rejecting skipped or ambiguous year promotion.
- Rolling back promotion writes after a foreign-key error.
- Denying cross-institution student enrolment, enrolment modification/promotion, and admission read/review.
- Rejecting a cross-institution parent/student relationship.
- Confirming institution context on academic audit events.

### Admission conversion

`tests/admissions/admission_conversion_integration_test.php` passed **11 checks**. Together with the academic harness's conversion checks, observed behavior included:

- Approving and rejecting applications with persisted review decisions.
- Creating a student, enrolment, placement, and parent link during conversion.
- Denying repeat conversion and duplicate student/year enrolment.
- Rejecting a cross-tenant placement and a placement inconsistent with the institution's academic model.
- Verifying that conversion assigns the same institution, academic year, group, student, enrolment, and placement.
- Rejecting a duplicate student code without creating a second student.
- Forcing a duplicate admission number after the conversion had inserted the student and audit event, then verifying the student, audit event, and admission conversion state all rolled back.
- Denying cross-institution admission reads and review changes.

These are service/database integration assertions, not end-to-end HTTP or browser workflow tests.

## 5. Multi-Tenant Security Results

The existing `tests/multitenant_integrity_test.php` passed **23 checks**. The academic and admission integration tests added another **16 tenant-related checks**. No tested cross-tenant access or mutation succeeded.

Verified cases included institution-scoped student lookup and enrolment, denial of cross-tenant enrolment creation/modification/promotion, denial of cross-tenant admission read/review, rejection of a cross-tenant parent/student link, and preservation of institution context in academic audit rows.

**Not verified:** Platform Admin selected-institution workspace permissions and Platform Admin audit-context behavior were not exercised end-to-end against the database. The existing opt-in Platform Admin DB harness was deliberately skipped because it creates a third randomly named database, outside the two approved targets and scoped grants. A static permission/authentication test is not a substitute for this integration test.

## 6. Test Results and Validation Limits

| Executed test | Result |
|---|---:|
| Multi-tenant integrity DB test | 23 passed |
| Admission conversion DB integration | 11 passed |
| Academic/enrolment/admission tenant DB integration | 16 passed |
| Admission conversion static checks | 6 passed |
| Platform Admin auth static checks | 42 passed |
| **Total final-run checks** | **50 DB + 48 static = 98 passed** |

- Final-run failed assertions: **0**
- Skipped: Platform Admin DB integration; manual browser verification
- PHP/JavaScript syntax sweeps: not repeated for this database phase
- Initial harness-only attempts: one fixture omitted the required class-subject mapping; another caught the wrong exception class for duplicate student code. The harness was corrected and the final retained 16-check test passed. These were test-harness errors, not application assertion failures.

To rerun the academic test after recreating and initializing the disposable schema:

```powershell
$env:FEES_ACADEMIC_TEST_DB = 'fees_schema_verify_20261113_112833'
& 'C:\laragon\bin\php\php-8.3.36-Win32-vs16-x64\php.exe' `
  'tests\academic_management_integration_test.php'
Remove-Item Env:\FEES_ACADEMIC_TEST_DB
```

The other scripts use `FEES_ISOLATION_TEST_DB` and `FEES_ADMISSION_TEST_DB`, respectively. Recreate only disposable schemas; the test scripts add randomized fixture records and do not clean them individually.

## 7. Required Follow-up and Priorities

1. **High — Platform Admin selected-institution integration test (1–2 days).** Verify permission gates, tenant-scoped reads/writes, selected-institution switching, and platform audit context using only an explicitly approved disposable database. Do not use the random-database harness until its target can be safely constrained.
2. **High — Upgrade migration compatibility test (1–3 days after identifying a baseline).** Identify the exact legacy schema/version and migration order, then exercise the upgrade path on a separate disposable copy. The fresh-install test here does not validate migrations.
3. **Medium — Route/UI and browser verification (1–2 days).** Confirm authorization and persistence through the real admin/admission screens; no manual browser tests were done.
4. **Medium — Broaden failure-path coverage (1–2 days).** Add integration assertions for remaining admission failure paths and other tenant-scoped operations before treating these flows as production-ready.
5. **Low — Provisioning ergonomics (0.5–1 day).** Document the DBA schema-load step for environments where binary logging blocks trigger creation by the restricted app user; do not broaden application grants to work around it.

## 8. Critical Issues and Recommended Next Task

- No tested tenant-isolation assertion failed.
- No migration compatibility conclusion can be made because no migration was run.
- Restricted-account schema initialization is blocked by MySQL error 1419 for trigger creation under the current binary-logging configuration; the local DBA import path succeeded without a server-setting change or `SUPER` grant.
- Platform Admin selected-institution DB authorization/audit behavior remains the most important unverified tenant-security area.
- No manual UI/browser, production-readiness, backup/restore, or full regression conclusion is claimed.

**Recommended next task:** Complete the Platform Admin selected-institution integration test on an approved disposable target. It closes the principal remaining tenant-security evidence gap without beginning a new application module. After that, establish the exact legacy migration baseline and test upgrade scripts separately.
