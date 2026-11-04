# Academic Schema Migration Plan

**Status:** Proposed staged migration plan — documentation only<br>
**Target:** Existing FeesPortal MySQL 8.4 installations<br>
**Prohibited in this phase:** Running migrations, changing schema, rewriting
application code or records, or modifying production data.

## 1. Migration principles

1. Take a verified full backup and restore it into a disposable database before
   every migration rehearsal.
2. Inspect and record the actual schema, migration ledger, foreign keys,
   triggers, table engines, SQL modes, collation, and row counts. Do not assume
   all deployed databases match `database/schema.sql`.
3. Preflight every proposed unique key and FK. Report duplicate, orphaned,
   cross-tenant, and semantically inconsistent records; resolve them only under
   an approved data decision.
4. Use additive columns and tables first. Do not drop tables, rename existing
   columns, rewrite IDs, or remove legacy student placement.
5. Backfill by deterministic, idempotent scripts with checkpointable batches
   and before/after counts. Never guess a class, section, year, model, or
   component where source data is ambiguous.
6. Keep application compatibility through a dual-read/controlled dual-write
   period. One service transaction must own synchronization between enrolments
   and legacy student placement fields.
7. Preserve every fee assignment, installment, payment, order, attempt,
   webhook, refund, receipt, and settlement row and identifier.
8. Use expand → backfill → verify → switch readers/writers → observe → contract.
   The contract phase (legacy column removal) requires separate approval and
   must wait until dependent routes, reports, integrations, and tests are
   migrated.

MySQL DDL implicitly commits. A `.down.sql` cannot be assumed to provide a
transactional rollback. Prefer forward-compatible repairs and verified backup
restore for failed DDL; do not promise that dropping newly added tables safely
restores application behavior after data has been written.

## 2. Phase sequence

### Stage 0 — Baseline and preflight

- Confirm schema version and exact migration history.
- Validate the current tenant identity integrity and parent-affiliation
  migration invariants. Do not reverse or weaken these constraints.
- Capture row counts, table checksums or key aggregates, FK definitions, and
  duplicate/orphan reports for every affected table.
- Confirm each institution's intended `institution_type` and academic model
  with an administrator. Keep unknown institutions unconfigured.
- Identify every code consumer before any column becomes nullable or changes
  semantics.

### Stage 1 — Institution model and shared offerings

- Add nullable `institutions.academic_model`, nullable
  `active_academic_year_id`, and non-null configuration status with an
  `unconfigured` default.
- Add the tenant-safe active-year composite FK after verifying
  `academic_years(institution_id,id)`.
- Treat `academic_years.status` as lifecycle/eligibility and the institution
  pointer as the selected year for normal current operations. A selected year
  must be active, enforced transactionally; existing active statuses do not
  select a pointer or trigger automatic status changes.
- Add class display order and create year-class offerings.
- Create department, year-course offering, academic term, and section tables
  with composite tenant FKs and indexes; create `academic_groups` after its
  target offerings are present.
- Do not populate `academic_model` automatically from `institution_type`.
- Keep all current classes, courses, batches, and academic years intact.

### Stage 2 — Subjects and enrolments

- Create institution-owned subjects and year/class subject mappings. Create
  section-subject mappings only after approval of whether section assignments
  supplement/exclude class subjects or replace the class subject set.
- Create `student_enrolments` with the database unique key
  `(institution_id, academic_year_id, student_id)`. This includes active,
  withdrawn, cancelled, completed, and promoted statuses; do not use a
  generated current-student key or make currentness unique across academic
  years.
- Add `academic_groups`, append-only enrolment placements, and status events.
  The current placement is the most recent effective placement for the
  institution's selected year.
- For configured schools, require placement in a section group; that group
  identifies both class offering and section. Validate model/group-type
  consistency in the placement transaction because the group FK alone cannot
  enforce the institution's model-dependent rule.
- Add all parent candidate keys before their FKs, including the complete
  student/year/group/placement candidates used by session attendance.
- Preflight existing student placement references against the same
  institution/year/course/class/batch.
- Backfill one enrolment for each unambiguous student/year placement and one
  initial placement-history row. Keep students with all-null placements on the
  legacy/unplaced path and report them for review; do not invent a group,
  enrolment placement, or historical movement.
- A withdrawn or cancelled enrolment retains its unique slot. Re-admission
  reactivates that exact row and appends a status event; it does not insert a
  second row. Promotion inserts a new enrolment for the immediately following
  academic year by institution year order; ambiguous ordering requires review.
- Where a student has inconsistent placement values, report and stop that
  student's backfill pending review. Do not relax tenant FKs to get the
  migration through.
- Do not change existing student identifiers or relationships.

### Stage 3 — Compatibility reads and writes

- Update readers to resolve the one enrolment for the selected academic year,
  then its latest effective placement. Fall back to legacy fields only for
  not-yet-migrated institutions/records.
- Route admissions, student create/edit, attendance, examinations, parent
  academic views, reports, and fee assignment through tenant-scoped services.
- Lock the enrolment row and write each placement-history row plus legacy
  student projection in one transaction. Require one root placement and a
  predecessor equal to the latest placement; reject stale or retroactive
  changes unless they use the audited correction workflow. Add reconciliation
  queries and alerts for projection mismatch.
- Promotion inserts a new year enrolment and placement; never update the old
  enrolment's year or placement. Any lifecycle status transition on the old
  record is separately event-audited.
- Keep admission/student code and parent links unchanged.

### Stage 4 — Class-first fees

- Add target-model/scope columns and nullable section/term/batch FKs to fee
  structures; retain `course_id`, `class_id`, year, IDs, and all existing
  structure rows.
- Add the year-scoped offering candidate keys and FKs so typed class/course
  targets are proven to match the structure year; a term target must match the
  same year and course, a section target the same year and class, and a batch
  target's course must be offered in the structure year.
- Keep legacy target rows unclassified and preserve their current course/class
  shapes. New typed rows must use exactly one allowed target combination.
- Add component frequency/status and schedule children. Preserve current
  item amounts and `is_optional`; leave historical frequency NULL/unknown and
  never backfill an invented yearly cadence.
- Use `fee_category_id` as stable component identity. Section overrides match
  by category, replace matching components, inherit missing categories, and
  do not suppress inherited categories in this design. Adding an exclusion
  rule requires separate approval.
- Add fee purpose/group code and effective period to typed structures.
  Different fee groups may coexist; class default and section override are
  separate scopes.
- Enforce no overlapping active date ranges for the same institution, year,
  exact target, and fee group by locking a stable guard row and checking
  inclusive range overlap in the same transaction. The unique start key is
  only a duplicate guard; MySQL unique indexes do not prevent arbitrary
  interval overlap.
- Add nullable enrolment reference to new assignments and component snapshots
  to new assignments. Composite FKs must enforce assignment/enrolment
  institution, student and year consistency, and assignment/fee-structure
  institution and year consistency. Preflight existing structure/year pairs
  before adding the latter constraint. Require a non-NULL enrolment link and
  the product-approved fee-eligible status when creating each new assignment;
  NULL remains only for legacy rows. Existing assignments retain their current
  IDs, student, structure, year and totals.
- Add component descriptions and `custom` frequency. Copy component name,
  description, frequency, amount, optionality, and schedule into new
  assignment snapshots. Custom frequency uses explicit schedule rows; no
  generated recurrence is assumed.
- Classify existing fee structures only after a deterministic target mapping
  and approved model configuration exist. Existing rows initially retain
  `target_model='legacy'`; do not turn a course scope into a class scope by
  inference.
- For configured schools, require class-target fee structures for new
  assignments and derive applicability from the active enrolment. Support
  section override as an explicit new structure scope.
- Rehearse duplicate-assignment handling against the existing unique key before
  any constraint change. Do not modify assignments to fit a new uniqueness
  scheme.
- Maintain the current rule that structures with assignments are immutable.
  New structure edits affect only future assignments.

### Stage 5 — Session attendance, UI policy, and model enforcement

- Add `attendance_sessions` and `attendance_session_records`; each session
  belongs to an academic group, and each record links the session to the
  student's effective enrolment placement.
- Composite FKs enforce that the session and placement share institution,
  academic year, and group, and that the placement has the same student and
  enrolment. Validate that the placement was effective at session time in the
  attendance transaction; this date-order rule is cross-table.
- School sessions use the enrolment's section group, which identifies both
  class and section without requiring a batch; college/university use
  course/term groups; coaching uses batch groups. A whole-class session
  spanning multiple sections is not represented by one session under the
  exact-scope FKs and requires an approved scope extension.
- Keep existing `attendance_records` unchanged as the legacy batch register.
  Backfill only unambiguous legacy rows, then compare counts and reports.
- Apply academic-model route checks at the server, not only in sidebar
  rendering. Do not change the existing permission catalog or role architecture.
- Hide incompatible model navigation only after direct route access is rejected
  safely and appropriately.
- Observe query and reconciliation results before any legacy fallback is
  disabled.

### Stage 6 — Contract (separate approval required)

- Only after all application consumers and downstream integrations have been
  migrated and verified may legacy placement columns or redundant batch/course
  dependencies be considered for removal.
- This phase is explicitly outside the present design authorization. Do not
  include destructive DDL in earlier migrations.

## 3. Existing-data mapping

| Existing data | Proposed destination / treatment | Safety rule |
|---|---|---|
| `institutions.institution_type` | Keep unchanged; set `academic_model` only after review | Never infer unconfigured schools from NULL or legacy labels |
| `institutions` active year | Populate new pointer only after selecting one valid same-institution year | No year records are created or closed automatically |
| `academic_years` | Reuse existing rows as historical/current years | Preserve IDs, names, dates, status |
| `classes` | Reuse as class catalog; create year offerings only where an institution actually used that class in the year | Do not assume every current class existed in every historic year |
| `courses` | Reuse as programme/course catalog; add optional department relation | Do not duplicate or rename courses during initial migration |
| `batches` | Reuse coaching batches and current attendance grouping | Audit ID-only course/class FKs before replacing with composite FKs |
| `students.academic_year_id/course_id/class_id/batch_id` | Backfill a current enrolment for fully validated rows | Do not update or remove source fields; stop on inconsistent rows |
| `admissions` placement IDs | Keep as application snapshot/history; optionally link an enrolled result to the resulting student enrolment in a later approved change | Do not rewrite approved or submitted applications in this design phase |
| `fee_structures` | Keep all rows as legacy-scoped, then explicitly classify new/verified structures | No automatic course-to-class reassignment |
| `fee_structure_items` | Preserve category, amount, optionality, IDs; add frequency/schedules for future use | Existing frequency is unknown; do not invent a recurring cadence |
| `student_fee_assignments` | Preserve totals, status, year, student, structure, payment plan, IDs | Do not recreate, recalculate, or reassign |
| `installments` | Preserve rows and existing assignment FK | No schedule regeneration or due-date rewrite |
| `attendance_records` | Preserve batch-based history; backfill only to new sessions/records on a unique verified match | Ambiguous rows remain legacy and reported |
| payments, orders, attempts, webhooks, refunds | No mapping; remain exactly as stored | Do not modify lifecycle, identifiers, or status |
| receipts and receipt files | No mapping; remain exactly as stored | Do not regenerate, rename, void, or relink |

## 4. Data preflight checklist

Run read-only checks in a disposable restored copy before writing any
migration. At minimum, retain the query text, database snapshot identifier,
execution time, and result count. The following templates must be reviewed
against the exact deployed schema. They are not to be run as part of this
documentation task.

- Institutions with unset or unsupported `institution_type`, duplicate codes,
  no academic year, multiple active years, or active-year/status disagreement.
- Duplicate class/course/batch names or codes under the proposed uniqueness
  collation.
- Batches whose course/class is missing or belongs to another institution.
- Students whose year/course/class/batch is missing or cross-tenant; batches
  whose course/class do not match the student's selected placement.
- Admissions whose year/course/class/batch references conflict.
- Fee structures whose class/course/year references are inconsistent,
  ambiguous, or both scoped without documented intent.
- Fee assignments whose year differs from their structure, whose enrolment
  differs by institution/student/year, or whose student placement does not
  match the structure scope.
- Existing duplicate assignments under any proposed enrolment-based key.
- Attendance rows that cannot be matched to a unique verifiable enrolment
  placement. Do not treat differences from the student's mutable current
  placement as proof that historical attendance is invalid.
- Fee components with no reliable frequency; all such rows remain unknown/
  legacy until an administrator assigns a frequency for future billing.
- Any FK, index, trigger, or schema differences between the target database and
  current repository schema.

```sql
-- Assignment year differs from its referenced fee structure.
SELECT sfa.id, sfa.institution_id, sfa.student_id,
       sfa.academic_year_id AS assignment_year_id,
       fs.academic_year_id AS structure_year_id, sfa.fee_structure_id
FROM student_fee_assignments AS sfa
JOIN fee_structures AS fs
  ON fs.institution_id = sfa.institution_id
 AND fs.id = sfa.fee_structure_id
WHERE sfa.academic_year_id <> fs.academic_year_id;

-- Existing batch IDs resolve outside their tenant (the current batch FKs
-- reference courses/classes by ID only).
SELECT b.id AS batch_id, b.institution_id AS batch_institution_id,
       b.course_id, c.institution_id AS course_institution_id,
       b.class_id, cl.institution_id AS class_institution_id
FROM batches AS b
LEFT JOIN courses AS c ON c.id = b.course_id
LEFT JOIN classes AS cl ON cl.id = b.class_id
WHERE c.id IS NULL OR c.institution_id <> b.institution_id
   OR cl.id IS NULL OR cl.institution_id <> b.institution_id;

-- Student placement's batch must agree with the student's course/class.
SELECT s.id AS student_id, s.institution_id, s.academic_year_id,
       s.course_id, s.class_id, s.batch_id, b.course_id AS batch_course_id,
       b.class_id AS batch_class_id
FROM students AS s
JOIN batches AS b
  ON b.institution_id = s.institution_id AND b.id = s.batch_id
WHERE s.batch_id IS NOT NULL
  AND (NOT (b.course_id <=> s.course_id)
       OR NOT (b.class_id <=> s.class_id));

-- Conservative review queue only: the student's academic fields are a
-- mutable current-placement projection, not historical placement evidence.
-- Differences for prior-year attendance are expected and must not be "fixed"
-- by rewriting attendance or student records.
SELECT ar.id AS attendance_id, ar.institution_id,
       ar.academic_year_id AS attendance_year_id,
       s.academic_year_id AS student_year_id,
       ar.batch_id AS attendance_batch_id, s.batch_id AS student_batch_id,
       ar.student_id,
       CASE
         WHEN NOT (ar.academic_year_id <=> s.academic_year_id)
           THEN 'attendance_year_not_in_current_projection'
         ELSE 'attendance_batch_differs_from_current_projection'
       END AS review_reason
FROM attendance_records AS ar
JOIN students AS s
  ON s.institution_id = ar.institution_id AND s.id = ar.student_id
WHERE NOT (ar.academic_year_id <=> s.academic_year_id)
   OR NOT (ar.batch_id <=> s.batch_id);
```

After additive fee-scope columns are installed in an approved migration
environment, also run a period-overlap preflight before activating typed
structures:

```sql
SELECT a.id AS first_structure_id, b.id AS second_structure_id,
       a.institution_id, a.academic_year_id, a.target_scope_kind,
       a.target_scope_id, a.fee_group_code
FROM fee_structures AS a
JOIN fee_structures AS b
  ON b.institution_id = a.institution_id
 AND b.academic_year_id = a.academic_year_id
 AND b.target_scope_kind = a.target_scope_kind
 AND b.target_scope_id = a.target_scope_id
 AND b.fee_group_code = a.fee_group_code
 AND b.id > a.id
 AND a.effective_from <= COALESCE(b.effective_until, '9999-12-31')
 AND b.effective_from <= COALESCE(a.effective_until, '9999-12-31')
WHERE a.target_model <> 'legacy'
  AND b.target_model <> 'legacy'
  AND a.status = 'active'
  AND b.status = 'active';
```

For each query, store its SQL, execution time, database snapshot identifier,
and row count in the change record. Export disputed record identifiers to a
restricted review artifact; do not place personal data in public migration
logs.

## 5. Validation and rollback

### Rehearsal gates

1. Restore a verified backup into a disposable MySQL 8.4 database.
2. Run preflight; require zero unresolved tenant-integrity violations before
   FK work.
3. Apply each additive DDL stage and inspect `SHOW CREATE TABLE`,
   `information_schema` FK/index metadata, and query plans.
4. Backfill in small repeat-safe batches. Verify source-to-target counts,
   uniqueness, tenant ownership, and field-by-field placement equality.
5. Run academic, student/admission, attendance, fee assignment, parent portal,
   payment, refund, receipt, and tenant-isolation regression tests.
6. Test failed mid-stage recovery from backup; do not assume `.down.sql` undoes
   implicitly committed DDL or safely removes newly written data.
7. Obtain explicit approval before applying any migration to a shared
   environment. Production execution is not authorized by these documents.

### Rollback posture

- Before application cutover, additive tables/columns can be left unused; the
  legacy application remains available.
- For a failed DDL stage, stop writers, capture logs and schema state, and
  restore the verified backup or execute a reviewed repair. Do not blindly drop
  partially applied objects.
- After new enrolments or snapshots are written, do not delete them as a
  rollback shortcut. Roll application reads/writes back to the legacy path only
  if a tested compatibility release can safely ignore the new rows.
- Do not roll back by changing or deleting fee assignments, installments,
  attendance, payment or receipt rows.
- A future down migration must refuse if new rows or dependencies would be
  destroyed. It must state data-loss conditions and require explicit backup
  and approval.

## 6. Backward compatibility and risk register

| Risk | Impact | Mitigation / gate |
|---|---|---|
| Institutions have no academic model despite `institution_type` | Wrong menus, validation, or fee target if inferred | Leave unset; explicit admin selection and legacy path |
| Existing students only hold one mutable placement | No truthful historical enrolment can be reconstructed | Backfill only the current row; do not fabricate prior promotions |
| Student edit currently updates year/class/course/batch in place | Could silently rewrite history after enrolment rollout | Append a placement and status event transactionally; retain a synchronized legacy projection |
| Batch FKs are ID-only in current schema | Cross-tenant references may be representable | Data preflight, composite parent keys, then tenant-composite FK replacement |
| Current batch needs course and class | School classes are forced to choose an unrelated course | Make class optional only in a staged schema and application update; preserve old rows |
| Fee structure can be course-only or class-only | Existing scopes may not map to a new model | Preserve as `legacy`; require explicit target classification |
| Fee item has amount/optional flag only | Existing fee cadence cannot be inferred | Leave old cadence unknown; explicit future configuration |
| Active periods can overlap for the same fee scope/group | Student could receive conflicting fee components | Lock a stable scope/group guard row and reject inclusive date overlap transactionally |
| Assignment has total snapshots but not component snapshots | Existing component-level historical reconstruction may be impossible | Keep legacy totals authoritative; snapshots apply to future assignments |
| Attendance is keyed by batch/student/date | Legacy key cannot represent school or term-based sessions | Use new session/record tables; preserve legacy rows and readers until verified |
| Institution active-year pointer and year lifecycle status can disagree | Current operations could select a draft/closed year | Keep pointer as selection and status as eligibility; validate active status transactionally |
| MySQL DDL implicit commits | Partial migration cannot be rolled back transactionally | Small additive stages, backups, rehearsal, guarded repairs |

## 7. Payment and receipt preservation

This academic design does not add or remove any FK in the settlement tables.
Existing `student_fee_assignments` continue to own installments; payments and
orders continue to reference their existing student, parent, installment, and
institution keys; receipts continue to reference the same payments. A new
assignment component snapshot is an additional audit/academic layer only. It
does not replace installments or recalculate outstanding amounts.

Before any implementation, regression tests must prove that IDs, FK counts,
balances, paid/refunded status, receipt numbers/files, and parent-visible
payment history are unchanged across the additive migration.

## 8. Approved decisions and remaining gates

The approved one-enrolment-per-student/year rule, status handling, append-only
placement history, scope-group fee schedules, overlap prevention, and
session-based attendance are implementation requirements, not open questions.
Do not reopen them or substitute application-only uniqueness for the database
constraint.

Remaining product decisions are limited to:

1. Whether an institution may change academic model after configuration and
   the required conversion workflow.
2. Whether section subject assignments supplement/exclude class subjects or
   replace the class subject set.
3. Whether admission numbers remain `students.student_code` or may be
   year-specific, and the roll-number uniqueness scope.
4. Whether changing the selected active year automatically closes the
   previously selected year.
5. How custom fee schedules map to installment generation and payment-plan
   behavior.
6. Whether a school needs one whole-class attendance session spanning multiple
   sections, rather than section-scoped sessions.
7. Whether section fee overrides may suppress an inherited fee component.
8. Whether `enrolled`, `active`, or both enrolment statuses are eligible for
   fee assignment.

Implementation remains blocked pending operational preflight and resolution
of any existing assignment/year mismatches, cross-tenant batch references,
ambiguous student placements, and attendance mappings. These are data-integrity
gates, not changes to the approved product rules.

No phase after this document is authorized to run by its creation. Obtain
explicit approval before Phase 3 or any schema/application implementation.
