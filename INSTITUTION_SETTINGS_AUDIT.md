# FeesPortal Institution Profile & Basic Settings Audit

**Audit date:** 2026-11-03  
**Scope:** Read-only source, route, schema, seed, and migration inspection of the current worktree.

## Audit basis and limits

- This report describes the code currently present in the worktree, including pre-existing uncommitted changes. The initial Git status showed numerous modified and untracked files, including `database/schema.sql`, authentication, admin pages, and services. None were changed for this audit.
- No HTTP pages were opened, no PHP test suite was run, no database connection was made, and no SQL, migrations, seeds, uploads, or database writes were executed. Claims below are code-inspection findings, not runtime verification.
- `config/database.php` and `.env.example` were inspected. The local `.env` was not opened, so the effective local database target and current database contents are unknown. No production database was accessed.
- Whole-application reference searches were performed for institution IDs/profile values, settings, currency/timezone, IDs, receipts, and related routes. The file inventory at the end lists the files directly inspected or targeted in detail; it does not imply that every search hit was read line-by-line.

## A. Executive summary

1. **The institution profile exists and is writable.** The actual page is `/admin/settings/general`, implemented by `admin/settings/general.php`. It reads and updates the currently authenticated user's institution record and supports logo upload/removal. The requested `/admin/institution/profile` path has no corresponding PHP handler. `/admin/settings` has no `index.php` or explicit rewrite and is not the profile page.
2. **This is not a general settings editor.** The profile form is presented as “Institution Profile.” Most requested settings (currency, timezone, formats, ID generation, attendance preferences, notification preferences, portal appearance, security, and receipt configuration) have no settings UI or application persistence. `system_settings` exists in the schema, but a PHP search found no code that reads or writes it and no seeded default settings.
3. **Academic years are separately functional.** They are managed at `/admin/academics/years`; this is not part of Basic Settings. Academic-year rows are institution-scoped.
4. **Institution-level data is consumed by other modules.** Institution name/logo appear in authenticated headers; address/contact and branding are used in parent help and generated PDFs. Institution code is used for staff login and parent email verification. Profile edits therefore can affect those outputs and sign-in flows.
5. **The application has real, but not complete, multi-institution support.** User sessions carry an institution ID, application services commonly filter by it, and most operational tables have an institution ID. System roles/permissions are shared and custom roles are tenant-specific. There is no institution creation/selection UI in the inspected admin routes, and some database foreign keys do not enforce same-institution relationships.
6. **No runtime/database verification was performed.** Whether the deployed database matches the checked-in schema, what institutions/settings currently exist, and actual HTTP responses remain unverified.

## B. Page and route mapping

The `.htaccess` clean-path rewrite resolves `/admin/<path>` only when the corresponding `admin/<path>.php` file exists.

| URL / route | Mapping and behavior |
|---|---|
| `/admin/institution/profile` | No `admin/institution/profile.php` or matching route was found. There is no rewrite to the profile page. Actual HTTP status was not tested. |
| `/admin/settings` | No `admin/settings.php` or `admin/settings/index.php` and no explicit rewrite were found. Since it is a directory with no app index, its response depends on Apache directory handling; it is not the settings form. Not runtime-tested. |
| `/admin/settings/general` | Rewrites to [`admin/settings/general.php`](admin/settings/general.php). This is the implemented institution profile form and its POST handler. |
| `/admin/settings/institution` | Rewrites to [`admin/settings/institution.php`](admin/settings/institution.php), which checks route access and redirects to `/admin/settings/general`. |
| `/admin/settings/fee-settings` | Rewrites to [`admin/settings/fee-settings.php`](admin/settings/fee-settings.php), which redirects to `/admin/fees/categories`; it is not a fee-settings editor. |
| `/admin/settings/payment-settings` | Rewrites to [`admin/settings/payment-settings.php`](admin/settings/payment-settings.php). This is a read-only payment configuration/status page. It reports environment credential readiness and institution-filtered order counts; it does not edit credentials or settings. |
| `/admin/institution-logo` | Rewrites to [`admin/institution-logo.php`](admin/institution-logo.php); authenticated admin-side endpoint retrieves the current institution logo. |
| `/parent/institution-logo` | Rewrites to [`parent/institution-logo.php`](parent/institution-logo.php); parent-side logo endpoint uses the current parent's institution ID. |
| `/admin/academics/years` | Rewrites to [`admin/academics/years.php`](admin/academics/years.php); the separate academic-year create/status workflow. |

### Profile workflow and handlers

- `admin/settings/general.php` loads [`services/admin-panel-service.php`](services/admin-panel-service.php) and [`services/institution-branding-service.php`](services/institution-branding-service.php).
- GET calls `admin_panel_institution_id()` and selects the institution by that ID. A missing row returns HTTP 404.
- Profile POST requires a CSRF token, validates fields with `admin_panel_validate_institution()`, calls `admin_panel_update_institution()`, writes an audit log, sets a flash message, and redirects with HTTP 303.
- Logo upload/removal is a separate POST action. On upload, the file is validated/stored, the institution's `logo_path` is updated transactionally and audited; the previous unreferenced logo is cleaned up.
- `admin/settings/general.php` repopulates submitted values after validation or update errors and displays escaped error/flash messages.
- No institution-create form/handler was found in the application routes. The only institution insert found in SQL files is the explicitly development/test-gated insert in `database/seed-development.sql`.

## C. Existing database structure

### Institution and settings tables

`institutions` in [`database/schema.sql`](database/schema.sql) contains:

`id`, `name`, `short_name`, `code`, `email`, `phone`, `address_line1`, `address_line2`, `city`, `state`, `postal_code`, `country`, `website`, `logo_path`, `status`, `created_at`, and `updated_at`.

- `id` is the primary key.
- `code` has a global unique key (`uq_institutions_code`); it is nullable. `status` has an index.
- `country` is non-null and defaults to `India`; `status` defaults to `active`.
- There are no institution columns for type, principal/director, registration number, or affiliation/accreditation.

`system_settings` contains `id`, required `institution_id`, `setting_key`, nullable text `setting_value`, `is_secret`, `updated_by`, and `updated_at`.

- Unique key: `(institution_id, setting_key)`. There is also an index on `setting_key`.
- Institution deletion cascades to its settings; `updated_by` references `users(id)` with `ON DELETE SET NULL`.
- `is_secret` is a boolean-like flag only; the schema does not define encryption for `setting_value`.
- No `system_settings` insert was found in the seeds, and no PHP code was found that reads or writes this table. Therefore no default application settings are established there.

### Tenant identifiers, relationships, and constraints

- `users.institution_id` is required; `user_sessions` also stores `institution_id`. Parent, academic, student, admission, fee, payment, academic activity, communications, audit, scheduled-job, and staff-profile data generally carry an institution ID.
- Core operational relationships frequently use composite foreign keys such as `(institution_id, student_id)` or `(institution_id, academic_year_id)`. This is a meaningful database-level tenant consistency measure, not just an application convention.
- `institutions.code` uniqueness is global, while user email/username/mobile and many business codes are unique per institution.
- The `permissions` catalog is global. `roles.institution_id` is nullable: system roles use the global/null scope, and custom roles can be institution-scoped. `role_permissions` is role/permission based and global to the referenced role.
- `notification_generation_cursors` has no institution ID and uses global cursor keys. It is processing state, not an institution preference store.
- `audit_logs.institution_id` and `scheduled_jobs.institution_id` are nullable.
- Some relationships are not tenant-composite in the schema:
  - `users.role_id` references `roles(id)` alone, not `(institution_id, role_id)`.
  - `batches.course_id` and `batches.class_id` reference their IDs alone despite `batches` also having `institution_id`.
  - Several actor/updater fields (for example audit actors and `system_settings.updated_by`) reference `users(id)` without a matching institution constraint.
  Application code checks institution scope in several of these workflows, but the schema itself does not prevent every mismatched cross-institution association.

### Defaults and migrations

- The only institution seed found is `FeesPortal Development Institution` with code `1234` in `database/seed-development.sql`; the script explicitly blocks databases whose names do not end in a development/test suffix. It is source code only and was not executed.
- `database/seed.sql` seeds global permissions and system-role permissions, including `institution_settings.view` / `institution_settings.manage`; it does not seed `system_settings` values.
- The searched migrations add attendance, examination results, notification generation, and RBAC/role fields and permissions. No institution-profile or `system_settings` migration was found in that search.
- Config is loaded from environment and `.env` by [`config/app.php`](config/app.php) and [`config/database.php`](config/database.php). The source has a global timezone default of `Asia/Kolkata`, global session timeout constants, and a database fallback name of `fees_management_test`; `.env.example` shows development values. The effective local `.env` and live schema were not inspected.

## D. Feature implementation matrix

Status definitions here describe the field's profile/settings path, not whether the live deployment has been tested.

### Institution profile fields

| Feature / field | Current status | Files involved | Database storage | Notes |
|---|---|---|---|---|
| Institution name | Fully implemented | `admin/settings/general.php`; `services/admin-panel-service.php`; `includes/layouts/header.php`; PDF/help services | `institutions.name` | Saved and reloaded. Appears in authenticated workspace branding, parent contact page, and generated PDFs. |
| Short name | Fully implemented in profile | `admin/settings/general.php`; `services/admin-panel-service.php` | `institutions.short_name` | Saved/reloaded, but no other consumer was found. |
| Institution code | Partially implemented | Profile page/service; `auth/login.php`; `services/otp-service.php`; `services/parent-email-verification-service.php` | `institutions.code` | Saved/reloaded; globally unique. Used to identify the institution for staff login and parent verification. Profile validation does not apply the stricter login code format, and the field is optional; a saved value can therefore be unusable for those flows. |
| Institution type | Not implemented | No profile UI/service/schema field found | None | No type or institution-category column. |
| Email | Fully implemented | Profile page/service; `parent/help.php`; PDF services | `institutions.email` | Saved/reloaded and used for contact display and document headers; not used as the system's SMTP sender. |
| Phone | Fully implemented | Profile page/service; `parent/help.php`; PDF services | `institutions.phone` | Saved/reloaded and shown as institution contact information. |
| Address line 1/2, city, state, postal code | Fully implemented | Profile page/service; parent help; PDF services | Same-named institution columns | Saved/reloaded; used in parent help and generated admission/receipt PDFs. |
| Country | Fully implemented as profile text | Profile page/service; PDF services | `institutions.country` | Required and saved. No country selector or country-to-timezone/currency behavior was found. |
| Website | Fully implemented in profile | Profile page/service | `institutions.website` | Validated as HTTP(S), saved/reloaded; no other application consumer was found. |
| Logo | Fully implemented | Profile page; `services/institution-branding-service.php`; admin/parent logo endpoints; header and PDF services | `institutions.logo_path`; file under `storage/institution-logos/` | Upload, replace, remove, preview, scoped retrieval, and cleanup logic exist. File validation/storage details are in section G. |
| Principal/director name, email, phone | Not implemented | No profile UI/service/schema field found | None | No principal/director entity or contact fields found. |
| Registration number; affiliation/accreditation body/number | Not implemented | No profile UI/service/schema field found | None | No corresponding profile fields or storage found. |
| Institution status | Partially implemented (schema/auth only) | `database/schema.sql`; authentication/session services | `institutions.status` | Status controls active-account/session eligibility, but it is not displayed or editable in the profile form. |
| Institution creation workflow | Not implemented in app routes | Only development/test SQL seed found | `institutions` | Existing rows can be updated by the profile page; no institution onboarding/creation route was found. |

### Basic settings inventory

| Setting | Where displayed | Stored | Retrieved / used | Effect if changed | Actually functional? |
|---|---|---|---|---|---|
| General institution settings | `/admin/settings/general` | Profile fields in `institutions`, not `system_settings` | Profile service and the cross-module readers listed in section E | Name/code/contact/address/logo effects described above | Profile subset is functional; it is not a general settings key/value editor. |
| Academic year | `/admin/academics/years` | `academic_years` with `institution_id`, name, date range, status | Admin-panel service and institution-scoped academic/student/fee workflows | Year creation/status affects records and selection in academic/fee workflows; not a timezone/date-format preference | Separate functional module; not part of settings page. |
| Country | Profile's `country` text input | `institutions.country` | Admission/receipt PDF output and related institution context | Changes displayed country/address only; no locale or currency conversion found | Functional profile field, not a country/locale setting. |
| Timezone | Not displayed | Environment config (`APP_TIMEZONE`), not tenant DB | `includes/init.php`, notification cron and PHP date operations | Changes application-wide date/time behavior when deployment config changes; cannot vary by institution | Global deployment configuration only. |
| Currency | Not displayed | Payment currency is stored per transaction; schema defaults payment currency to `INR` | Payment/receipt views use stored currency; dashboard/report displays include hardcoded `₹`/INR | No institution setting can change currency; changing country has no currency effect | No configurable institution currency. |
| Date/time formats | Not displayed | Hardcoded PHP format strings | Admin pages and PDF date-format helpers | Not user- or institution-configurable | Not implemented. |
| Student ID generation | Not displayed | `students.student_code`; unique per institution | Student create/update requires a submitted code | Changing a nonexistent format/prefix setting cannot affect records; IDs are entered, not generated by a settings rule | Manual ID field exists; generation setting/function not found. |
| Admission number generation | Not displayed | No admission-number field found; admissions use row IDs and student code fields | Admission workflow/PDFs use existing identifiers | No prefix/counter/format behavior to configure | Not implemented as a configurable number generator. |
| Attendance configuration | Not displayed | Attendance rows and enum/status values in `attendance_records` | Attendance page/service | No per-institution attendance rules/preferences available | Attendance functionality exists separately; configuration settings do not. |
| Notification preferences | Not displayed | Notifications are stored per recipient/channel; no preference settings found | Notification service and scheduled processing | No preference change can be made from settings; notification generation uses app/system logic | Preferences/configuration not implemented. |
| Branding / portal appearance | Name/logo in profile and authenticated header | Institution name/logo; static CSS and hardcoded public brand elsewhere | Header, PDFs, and help page read institution data | Name/logo updates affect authenticated workspace and generated documents; colors, portal theme, and public landing brand do not follow profile values | Basic logo/name branding works; appearance settings are not implemented. |
| Security / session settings | Not displayed | `config/app.php` constants/config only | Session/auth code | Timeout changes require deployment/code configuration and affect the whole application | No per-institution security/session settings. |
| Email settings/templates | Not displayed | SMTP provider/sender config from environment | `services/email-delivery.php`, OTP services | Changes are deployment-wide; institution email is not the sender identity | No institution-managed email preferences/template editor. |
| Documents / receipt configuration | No configuration UI | Receipt rows and generated PDF code; `receipts.file_path` optional | Parent downloads render a PDF; receipt code uses fixed layout and numbering logic | Profile branding changes affect newly rendered parent PDF downloads, including historical receipt records | No configurable document/receipt template, numbering prefix, or layout settings. |
| Payment configuration | `/admin/settings/payment-settings` | Razorpay secrets in environment; payment orders in DB | Payment readiness checker and institution-filtered order counts | Deployment credentials affect checkout globally; page itself is read-only | Read-only status/summary, not an editable settings module. |

## E. Cross-module dependencies

| Module | Verified dependency | Effect of profile/settings change |
|---|---|---|
| Admin dashboard | Dashboard aggregates are filtered by `institution_id`; shared header loads current institution name/logo. Amount labels and formats include hardcoded INR/₹. | Name/logo changes affect header; totals remain institution-scoped. No currency setting changes the displayed dashboard currency. |
| Staff management | User/staff lists and updates use the current institution. Role management permits system roles and institution-scoped custom roles. | Profile contact fields do not update staff account email/phone or permissions. Institution code does affect staff email-login lookup. |
| Role permissions | Permission definitions and system roles are shared; custom roles are institution-scoped and permission changes attach to a role. | Institution profile values do not change permissions. A role/data mismatch is possible at schema level as described above, although service/auth checks validate role scope. |
| Parent portal | Header reads current institution name/logo. `parent/help.php` reads institution name, email, phone, and address. Parent and receipt lookups are institution-filtered. | Name/logo/contact/address edits appear in the portal/contact page on subsequent requests. |
| Parent/staff login and parent verification | Staff login requires an institution code; parent verification/resend also looks up `institutions.code`. | Changing or clearing code changes the identifier users must enter and can break old-code login/verification requests. Profile save accepts a wider syntax than `validate_institution_code()`, creating a source-level compatibility risk. |
| Student management | Student reads/writes are scoped by institution and student code is required/manual. Student detail shows institution name; admission-form PDF loads institution name/logo/address/contact. | Institution name and PDF branding/contact changes affect future rendered forms. There is no student-code generation setting. |
| Fees and fee structures | Academic years, fee structures, assignments, and related records are institution-scoped. | Existing fee/academic records are not recalculated by profile changes. No institution currency setting affects fee amounts. |
| Payment processing and receipts | Payment/order/receipt records carry institution IDs; receipt currency is persisted per payment (schema default `INR`). Parent receipt lookup joins the current institution row for name/logo/address/contact and renders its PDF on download. | A profile branding/contact edit changes the branding shown on subsequently generated/downloaded parent PDFs, including older receipts; transaction amount/currency remain payment data. The PDF layout/numbering is not settings-driven. |
| Admin receipt downloads | Admin receipt list/download uses `receipts.file_path`; known receipt issuance inserts do not supply a file path, and no PHP writer for this field was found. | For receipts created through the inspected issuance path, the admin list omits a download link while parent downloads render PDFs dynamically. This is a source-level workflow gap; actual DB contents were not tested. |
| Announcements/notifications | Records and recipient queries are institution-scoped. Notification channel types exist; no preference setting reader/writer was found. Cron date processing uses app timezone. | Profile changes do not configure notification behavior. Timezone is deployment-wide; recipient preferences are not configurable here. |
| Academic years, classes, courses | These are separately stored/queried by institution ID; academic years have a separate CRUD/status page. | Profile settings do not create or alter them. Year status changes are managed through the academic-year route. |
| Reports | Report pages/services use institution-scoped data; financial UI includes fixed INR/₹ formatting. | Profile changes do not change report currency or formatting. |
| Email templates and delivery | SMTP sender/provider are read from app environment config; OTP content uses configured app name. Institution email is used as contact data, not as the configured sender. | Editing institution email does not change delivery sender or templates. `APP_NAME`/mail settings require deployment config changes. |
| Public/login branding | Shared/public header and landing content contain static FeesPortal / Blue Eyes Infosoft branding. | Institution profile branding does not change the public landing brand. Authenticated application header does use institution name/logo. |

## F. Multi-institution readiness

- **Multiple institutions:** The schema permits multiple institution rows, and operational data generally has an `institution_id`. Application workflows commonly pass an institution ID from the authenticated user into queries. This is implemented multi-tenant structure, not merely a planned `system_settings` concept.
- **Institution identity:** `users.institution_id` and `user_sessions.institution_id` identify the tenant. Session revalidation joins the user/session/institution and checks that their IDs match. `current_user()` reads the authenticated session user; admin services obtain the tenant ID from that user.
- **Institution lifecycle and selection:** No institution creation UI or cross-tenant institution selector was found. The profile page only edits the logged-in user's current institution. The development seed inserts one test institution; production row counts are unknown because no DB connection was made.
- **Roles:** Both models exist: global system roles (`institution_id` null) and institution-scoped custom roles. Permission definitions are global and associated with role IDs.
- **Settings:** The profile is per-institution. `system_settings` is designed per-institution in schema, but no application setting service or consumers were found. Deployment config such as timezone, SMTP, session timeouts, and payment secrets is global.
- **Institution-specific values:** Separate academic years are supported; name/logo/contact/address are institution data. Different institution timezones, currencies, date formats, student-ID generators, and notification preferences are not supported by the inspected settings implementation.
- **Isolation assessment:** Many application queries and composite foreign keys enforce institution scope. Isolation should not be described as complete: `users.role_id` and batch-to-course/class relationships are not tenant-composite foreign keys; some actor links also omit a tenant composite. These allow inconsistent associations to be stored if application checks are bypassed or regress. This is a schema-level risk, not evidence that a specific cross-tenant exploit was exercised.

## G. Security and file-upload review

### Controls observed

- The profile route calls `require_placeholder_route_access()`. Admin route authorization maps settings to institution-settings permissions, with compatible legacy permission handling. Admin/parent logo endpoints also check login/type/route access.
- Every profile/logo POST renders and verifies a CSRF token.
- Profile SQL uses prepared statements; updates are scoped by the authorized institution ID. Profile writes and audit logging use a transaction. Output fields are escaped.
- Server-side profile validation checks required name/country, max lengths/control characters, email format, and HTTP(S) website URL. Institution code uniqueness is ultimately protected by the database's unique key.
- Logo upload checks upload error/size, detected MIME, image metadata/type, dimensions and pixel count, and decodes the image. Only PNG/JPEG uploads are accepted, capped at 2 MiB, 4000 pixels per dimension, and 10 million pixels. Storage uses a random 32-hex filename, `storage/institution-logos`, and restrictive directory/file modes in code.
- The logo reader validates allowed paths, resolves real paths beneath approved storage/public roots, checks MIME/image metadata, and sends logos through authenticated endpoints with `nosniff`. `.htaccess` denies direct requests to `storage/`.
- Failures are surfaced as field/action error messages; unexpected exceptions are logged with type/code and a generic message, rather than returning raw database details.

### Concerns / limits

- Institution-code validation in profile is weaker than the format required by login and verification (`validate_institution_code`). It also permits blank code, although staff login/parent verification require it. A profile edit can therefore produce an institution record whose code cannot be used in those flows.
- Email/website use plain text inputs in the profile form; server validation is present, but browser-native email/URL validation is not.
- Phone has length/control-character validation, but no phone-number syntax check.
- Duplicate institution code is protected by a global unique index, but the profile handler catches the resulting database exception as a generic save failure; it does not provide a specific duplicate-code message.
- `system_settings.is_secret` is only a flag in the schema. Since no service exists, current secret-handling behavior cannot be validated; if later used for credentials, the text value must not be assumed encrypted.
- File-system permissions and Apache protections were assessed from source only. They were not verified on the current Windows/Laragon filesystem or a deployed server.
- No upload was performed and no active endpoint was tested.

## H. Existing bugs and risks (verified from source)

1. **Target route mismatch:** `/admin/institution/profile` does not map to the implemented profile, and `/admin/settings` has no settings index. The sidebar points to `/admin/settings/general`.
2. **Settings persistence is dormant:** `system_settings` has schema but no PHP accessor/writer, and no seeded key/default values were found. Settings-looking permissions do not imply a functioning settings module.
3. **Institution-code compatibility:** Profile validation allows formats that staff login and parent verification reject, and allows an empty code. Code changes also require users to use the new code; the form does not warn about that dependency.
4. **Institution lifecycle is incomplete:** The profile page supports editing an existing authorized institution only; no app-level institution creation workflow was found.
5. **Receipt branding is live, not snapshotted for parent PDFs:** Parent receipt downloads render at request time using current institution name/logo/contact/address. Changing profile data can change the appearance/content of previously issued receipts when they are downloaded again.
6. **Admin receipt-file path gap:** The inspected receipt issuance insert leaves `file_path` unset, no PHP writer was found, and the admin download route requires a stored path. The admin list therefore withholds a download link for a null/empty path; this contrasts with the parent dynamic PDF endpoint.
7. **Hardcoded/global values:** Public brand strings, INR/₹ display, date formatting, timezone, SMTP identity, and session timeouts are not driven by institution-level settings.
8. **Database tenant constraints are uneven:** Some foreign keys do not enforce same-institution relationships (notably role assignment and batch course/class references), leaving consistency dependent on application validation.
9. **Some profile fields have no consumers:** `short_name` and `website` are editable/persisted but no other PHP consumer was found. Institution email is contact data, not the email delivery sender.

## I. Recommended development sequence

1. Confirm the intended user-facing routes and whether `/admin/settings` should be an index or alias; keep the existing `/admin/settings/general` contract working.
2. Define the institution profile data contract (type, principal/director, registration/affiliation, required/optional semantics) and institution-creation/onboarding workflow before adding fields.
3. Align institution-code validation with all authentication/verification consumers and decide how code changes, missing codes, and existing users are handled.
4. Define typed, documented setting keys and defaults before using `system_settings`; distinguish true per-institution preferences from deployment-wide secrets/configuration.
5. Implement setting service access with tenant ID passed from authenticated context, server-side validation, permission checks, auditing, and migrations/default data only after schema/version strategy is agreed.
6. Implement settings by dependency/risk order: timezone/date behavior; currency/display and persisted transaction compatibility; student/admission numbering; attendance and notification preferences; portal appearance; document/receipt configuration.
7. Resolve receipt archival policy before branding work: snapshot institution identity at issue time or explicitly accept that historical receipt re-renders use current profile values. Also reconcile the admin stored-file download path with the parent generated-PDF path.
8. Review database composite constraints and add institution-paired foreign keys where supported by existing keys/data; audit query scoping and role assignment during tenant-boundary tests.
9. Add tests using disposable test databases and fixtures for at least two institutions: profile CRUD, code/login compatibility, setting isolation, tenant references, academic-year variations, different currencies/timezones, PDF history, logo validation/authorization, and responsive form behavior. Run them only in a test environment after implementation, not as part of this audit.

## J. Exact file inventory

These are the files directly inspected or targeted in detail and their audit purpose. Scope-wide `rg` searches additionally scanned application PHP and database SQL for references described above.

### Routes and UI

- [`.htaccess`](.htaccess) — clean URL rewrite and direct storage/internal path protection.
- [`admin/settings/general.php`](admin/settings/general.php) — profile UI, POST actions, validation/error handling, logo actions.
- [`admin/settings/institution.php`](admin/settings/institution.php) — legacy/settings route redirect.
- [`admin/settings/fee-settings.php`](admin/settings/fee-settings.php) — redirect behavior.
- [`admin/settings/payment-settings.php`](admin/settings/payment-settings.php) — read-only payment status/config page.
- [`admin/institution-logo.php`](admin/institution-logo.php) — authenticated admin logo endpoint.
- [`parent/institution-logo.php`](parent/institution-logo.php) — authenticated parent logo endpoint.
- [`admin/academics/years.php`](admin/academics/years.php) — separate academic-year UI/workflow.
- [`admin/dashboard.php`](admin/dashboard.php) — institution dashboard and hardcoded currency display.
- [`admin/students/view.php`](admin/students/view.php) — institution/student UI dependency.
- [`admin/receipts/index.php`](admin/receipts/index.php) — admin receipt list/download-link condition.
- [`admin/receipts/download.php`](admin/receipts/download.php) — stored-file download requirements.
- [`parent/receipts/download.php`](parent/receipts/download.php) — generated parent receipt PDF route.
- [`parent/help.php`](parent/help.php) — institution contact display.
- [`parent/account.php`](parent/account.php) — institution-code based verification resend.
- [`auth/login.php`](auth/login.php) — staff login institution-code input/flow.
- [`auth/request-parent-verification.php`](auth/request-parent-verification.php) — parent verification code input.
- [`index.php`](index.php) — public/static FeesPortal and Blue Eyes brand references (targeted search).

### Application services and shared infrastructure

- [`services/admin-panel-service.php`](services/admin-panel-service.php) — institution selection, profile load/validate/update, audit and logo metadata, academic years.
- [`services/institution-branding-service.php`](services/institution-branding-service.php) — logo validation, storage, retrieval, serving, cleanup.
- [`services/student-management-service.php`](services/student-management-service.php) — tenant-scoped student operations, manual student-code validation, profile institution joins.
- [`services/receipt-service.php`](services/receipt-service.php) — receipt issuance, PDF branding/date/currency output.
- [`services/parent-portal-service.php`](services/parent-portal-service.php) — parent context and institution-scoped receipt data.
- [`services/role-management-service.php`](services/role-management-service.php) — system/custom role scoping and permission assignment.
- [`services/otp-service.php`](services/otp-service.php) — staff login institution-code lookup, validation, OTP session timing.
- [`services/admin-password-auth-service.php`](services/admin-password-auth-service.php) — admin authentication and session institution binding.
- [`services/parent-email-verification-service.php`](services/parent-email-verification-service.php) — code-scoped parent verification lookup.
- [`services/admission-form-pdf-service.php`](services/admission-form-pdf-service.php) — institution branding/contact use in PDF.
- [`services/email-delivery.php`](services/email-delivery.php) — environment-based SMTP sender/provider.
- [`services/notification-service.php`](services/notification-service.php) — notification access/preferences search.
- [`includes/auth.php`](includes/auth.php) — session revalidation, permission routing, institution access.
- [`includes/csrf.php`](includes/csrf.php) — CSRF token generation/verification.
- [`includes/init.php`](includes/init.php) — app setup, timezone/session configuration.
- [`includes/page-placeholder.php`](includes/page-placeholder.php) — route authorization and placeholder routing.
- [`includes/validation.php`](includes/validation.php) — shared validation helpers.
- [`includes/layouts/header.php`](includes/layouts/header.php) — institution logo/name in authenticated header and static public brand.
- [`includes/layouts/sidebar.php`](includes/layouts/sidebar.php) — actual settings/academic routes and permission-gated navigation.
- [`config/app.php`](config/app.php) — global timezone, mail, payment, and session config.
- [`config/database.php`](config/database.php) — environment-based database config source; no connection made.
- [`.env.example`](.env.example) — example deployment configuration only; actual `.env` not opened.

### Database and seed/migration sources

- [`database/schema.sql`](database/schema.sql) — institution, settings, user/role, academic, fee, payment, receipt, notification and tenant relationships/indexes.
- [`database/seed.sql`](database/seed.sql) — permission/system-role defaults and absence of settings values.
- [`database/seed-development.sql`](database/seed-development.sql) — development/test-gated institution/admin seed; not executed.
- [`database/migrations/20261014_add_attendance_records.sql`](database/migrations/20261014_add_attendance_records.sql) — institution-scoped attendance table/constraints (targeted search).
- [`database/migrations/20261014_add_examination_results.sql`](database/migrations/20261014_add_examination_results.sql) — institution-scoped result constraints (targeted search).
- [`database/migrations/20261015_add_notification_generation.sql`](database/migrations/20261015_add_notification_generation.sql) — notification-generation schema references (targeted search).
- [`database/migrations/20261102_add_rbac_management.sql`](database/migrations/20261102_add_rbac_management.sql) — role institution scope/settings permissions (targeted search).
- [`database/migrations/20261102_add_rbac_management.down.sql`](database/migrations/20261102_add_rbac_management.down.sql) — RBAC rollback references (targeted search).

## Verification result

This audit created only the requested `INSTITUTION_SETTINGS_AUDIT.md`. No application source/configuration files or database records were changed. No SQL, migration, test, database connection, upload, or runtime page request was performed.

## Implementation update (2026-11-03)

The original audit above is a point-in-time account of the pre-enhancement implementation. The following implementation has since been added; it does not change the audit's original findings or imply that deployment-time database/runtime behavior has been verified.

- The existing `/admin/settings/general` profile now collects the institution type, principal/director, registration, affiliation/accreditation, established year, and institution currency in addition to the prior profile fields. Institution codes retain the format accepted by staff login and parent verification, and the form warns that changing a code may affect both workflows. The existing logo workflow remains in place.
- The new `/super-admin/institutions` route is restricted at the route and service layers to a system Super Admin. It supports institution creation, listing/search/status filtering, details, profile updates, and activation/deactivation without deletion. Creation transactionally adds an institution and its first institution-linked Admin using the existing global Admin system role. No password is set and no OTP or email is sent during onboarding; the Super Admin must confirm the email can receive mail, after which the Admin can request an OTP through the existing staff sign-in flow.
- A forward-only migration, [`database/migrations/20261103_add_institution_profile_fields.sql`](database/migrations/20261103_add_institution_profile_fields.sql), adds only missing institution profile fields and `currency_code CHAR(3) NOT NULL DEFAULT 'INR'`. The checked-in schema snapshot was updated. Existing institutions receive the database default when the migration is applied. The migration has **not** been run.
- Currency is stored as profile preference only. No payment, Razorpay, receipt, or historical transaction behavior was changed; amounts are not converted or reinterpreted.
- Tenant access, status checks, CSRF, field validation, prepared statements, and audit-log calls are used by the new workflow. These were inspected in source; cross-tenant access and deactivation behavior were not exercised against a database or browser.

### Implementation files

Modified:

- [`.htaccess`](.htaccess) — clean URL routing for the Super Admin institutions page.
- [`admin/settings/general.php`](admin/settings/general.php) — extended existing institution profile form.
- [`database/schema.sql`](database/schema.sql) — schema snapshot fields and default currency.
- [`includes/layouts/sidebar.php`](includes/layouts/sidebar.php) — Super Admin-only navigation link.
- [`services/admin-panel-service.php`](services/admin-panel-service.php) — institution profile retrieval, validation, and persistence for added fields.
- [`tests/admin_panel_test.php`](tests/admin_panel_test.php) — profile validation regression checks.
- [`INSTITUTION_SETTINGS_AUDIT.md`](INSTITUTION_SETTINGS_AUDIT.md) — this implementation-status addendum.

Created:

- [`database/migrations/20261103_add_institution_profile_fields.sql`](database/migrations/20261103_add_institution_profile_fields.sql) — forward schema migration; unapplied.
- [`services/institution-onboarding-service.php`](services/institution-onboarding-service.php) — Super Admin authorization, validation, transactional onboarding, tenant-scoped administrator, listing, updates, status, and audit logging.
- [`super-admin/institutions.php`](super-admin/institutions.php) — onboarding and institution management interface.
- [`tests/institution_onboarding_test.php`](tests/institution_onboarding_test.php) — validation and source-contract checks.

### Verification after implementation

PHP 8.3 syntax checks passed for the changed PHP implementation and test files. The focused profile/logo and onboarding tests passed, as did the existing Admin password, staff email OTP, parent email OTP, and parent email verification tests. These tests do not constitute a database migration, end-to-end browser test, or cross-institution runtime test. No database connection, SQL, migration, upload, or payment/receipt test was performed.

## Branding and page redesign update (2026-11-03)

- Institution name and logo continue to use `institutions.name`, `short_name`, and `logo_path`; no duplicate identity/logo settings were introduced.
- Theme colors and the optional login welcome text are stored in the existing `system_settings` table, keyed by both the authenticated/selected institution ID and setting key. Its existing unique key on `(institution_id, setting_key)` supports the branding settings. **No migration or schema change is required.**
- The prior profile/onboarding migration [`database/migrations/20261103_add_institution_profile_fields.sql`](database/migrations/20261103_add_institution_profile_fields.sql) is still unapplied and remains a prerequisite for the details page’s profile fields; it was not run as part of this branding change.
- Branding writes require a system Super Admin, validate six-digit HEX colors and readable text/background contrast, use prepared upserts in a transaction, and add an audit-log entry. Logo replacement/removal reuses the established MIME-, size-, and dimensions-checked storage workflow and existing logo audit handling.
- Shared Admin/Staff and Parent layouts resolve the theme using the authenticated session’s institution ID. Super Admin pages are excluded from this resolver and retain platform colors; the institution details page uses an isolated preview. The student portal is not implemented. The institution-scoped admission-form PDF now uses the primary/accent colors; payment/receipt documents were not modified and retain their existing institution name/logo behavior.
- Direct authentication pages can display institution branding when opened with `?institution_code=<code>`. This is presentation-only; the code is looked up server-side, no browser-supplied institution ID is used, and authentication/OTP processing is unchanged. The existing logo endpoints remain in place; new scoped handlers support Super Admin inspection and code-selected login logo display.
- `/super-admin/institutions?id=...` now shows overview/profile/branding/administrator/security sections, actual student and staff account counts, image controls, color swatches/HEX fields, and a live local preview with reset and unsaved-change warnings. Invalid numeric IDs receive HTTP 400; valid but nonexistent IDs receive HTTP 404.

### Branding implementation files

Modified:

- [`.htaccess`](.htaccess) — clean path for public institution logo retrieval by code.
- [`includes/layouts/auth.php`](includes/layouts/auth.php) — optional institution-specific login presentation.
- [`includes/layouts/head.php`](includes/layouts/head.php) — safe design-token output from validated theme values.
- [`includes/layouts/header.php`](includes/layouts/header.php) — current-institution identity/logo and platform-only Super Admin branding.
- [`includes/layouts/page.php`](includes/layouts/page.php) — apply saved theme to authenticated institution pages.
- [`public/assets/css/components.css`](public/assets/css/components.css), [`public/assets/css/layout.css`](public/assets/css/layout.css), [`public/assets/css/responsive.css`](public/assets/css/responsive.css), [`public/assets/css/variables.css`](public/assets/css/variables.css) — shared theme tokens and responsive portal styles.
- [`public/assets/css/pages/admin-dashboard.css`](public/assets/css/pages/admin-dashboard.css), [`public/assets/css/pages/parent-dashboard.css`](public/assets/css/pages/parent-dashboard.css), [`public/assets/css/pages/parent-children.css`](public/assets/css/pages/parent-children.css) — use institution theme tokens in common portal cards and surfaces.
- [`services/institution-branding-service.php`](services/institution-branding-service.php) — setting validation, storage, retrieval, user-scoped resolution, and logo access.
- [`services/admission-form-pdf-service.php`](services/admission-form-pdf-service.php) — use validated institution primary/accent colors in admission PDFs.
- [`services/institution-onboarding-service.php`](services/institution-onboarding-service.php) — real per-institution student/staff counts.
- [`super-admin/institutions.php`](super-admin/institutions.php) — redesigned details view and branding controls.

Created:

- [`public/assets/css/pages/super-admin-institutions.css`](public/assets/css/pages/super-admin-institutions.css) — responsive details and live-preview design.
- [`public/assets/js/institution-branding.js`](public/assets/js/institution-branding.js) — unsaved local color preview and reset behavior.
- [`public/institution-logo.php`](public/institution-logo.php) — public login logo response selected by validated institution code.
- [`super-admin/institution-logo.php`](super-admin/institution-logo.php) — Super Admin-only institution logo preview.
- [`tests/institution_branding_test.php`](tests/institution_branding_test.php) — color, contrast, tenant-storage, and route source checks.

### Branding verification limits

PHP syntax checks and the branding validation/source checks passed, along with the admission-form PDF, profile, onboarding, Admin password, staff email OTP, parent email OTP, and parent email verification tests. JavaScript syntax and `git diff --check` also passed. The browser request to the target details URL redirected to the login page because no authenticated Super Admin browser session was available; the details screen, logo operations, saved persistence, and two-institution isolation were therefore not browser- or database-tested. No SQL, migration, test-record insertion, or payment/receipt code change was made.

## System Preferences route update (2026-11-03)

- `/admin/settings/general` is now the read-only System Preferences overview. It does not expose institution profile or branding fields. No functional institution-editable preferences were present in `system_settings`; that table is used for institution branding. Timezone remains a platform-level `APP_TIMEZONE` value, while academic years, attendance, and announcements remain in their respective modules. The page does not claim that reference values are editable.
- The prior Institution Admin profile/logo editor is preserved at `/admin/settings/institution`, replacing the former alias redirect. All profile and logo POST actions now return there. `/admin/settings/general` remains a valid route/bookmark; sidebar entries for System Preferences and Institution profile are separate.
- This route move does not delete or alter institution fields, branding settings, or saved records. No settings schema, data, payment code, or OTP code was changed.
