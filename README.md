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