# Academic Database Design Validation Report

**Status:** Updated for approved academic design decisions; documentation only<br>
**Schema baseline:** `database/schema.sql` (MySQL 8.4.3)<br>
**Scope:** Four academic design documents, current schema, tenant-integrity
migrations, and relevant student, fee-assignment, attendance, and admin
services.

## 1. Executive result

The design now agrees across the database design, relationship diagram,
migration plan, and this report on:

- one enrolment per institution/student/academic year, enforced regardless of
  lifecycle status by a non-generated unique key;
- append-only placement and status history, with re-admission reusing the
  existing enrolment and promotion creating a new year's enrolment;
- fee-purpose groups, effective periods, section overrides, and active-period
  overlap prevention;
- session attendance tied by composite FKs to the exact enrolment placement
  and academic group; and
- staged additive migration with legacy records preserved.

School enrolment placements and attendance use section groups that identify
both the class and section. A single whole-class attendance session spanning
multiple sections is not supported by the exact-scope FK design and remains an
open scope decision.

No schema or application changes were made. Read-only preflight SQL is included
in the migration plan but was not executed under the documentation-only
boundary; its results are unknown.

## 2. Findings and implementation gates

Line references below are to the current versions of the named files.

### Critical — Must resolve before implementation

| # | Severity | Document section and exact line(s) | Existing schema evidence | Consequence | Correction / gate | Approval required? |
|---|---|---|---|---|---|---|
| C1 | Critical | Design §7.4, lines 1021–1066; migration Stage 4, lines 121–171 and preflight lines 254–262 | `database/schema.sql` lines 678–706 has separate structure and academic-year FKs, but no combined year/structure FK. | Adding the proposed composite FK fails if any stored assignment year differs from its referenced structure year. | Add `uq_fee_structures_year_id` and the assignment composite FK only after running the mismatch preflight. Resolve mismatches only with an approved financial-data decision. | Any mismatch remediation requires explicit data approval. |
| C2 | Critical | Design §4.5, lines 296–339; migration Stage 1, lines 52–68 and preflight lines 264–273 | `database/schema.sql` lines 420–439 shows required batch course/class IDs and ID-only course/class FKs. | Cross-institution relationships may be represented, and replacing these FKs can fail on existing cross-tenant references. | Run the batch parent-tenant preflight, reconcile under approval, add candidate keys, then replace the ID-only FKs additively. | Only if remediation is needed. |

### High — Must resolve before the relevant migration stage

| # | Severity | Document section and exact line(s) | Existing schema evidence | Consequence | Correction in design | Approval required? |
|---|---|---|---|---|---|---|
| H1 | High | Design §6, lines 562–615; diagram §1, lines 1–39 | The existing schema has no enrolment table. | A generated key on only student ID could accidentally prevent enrolments in later academic years. | The mandatory key is exactly `UNIQUE (institution_id, academic_year_id, student_id)`, with no current-student generated uniqueness. All statuses reserve the slot. | No; approved product rule. |
| H2 | High | Design §6, lines 680–691 and §8, lines 1180–1285; diagram §4, lines 130–151 | `database/schema.sql` lines 503–520 is batch/student/date keyed and has no class/section group. | A simple enrolment FK would not prove that attendance session and placement share the same academic scope. | Year-owned academic groups; session FK matches tenant/year/group, and placement FK matches tenant/year/group/student/enrolment. Validate placement effective time transactionally. | No; approved product rule. |
| H3 | High | Design §7.1–§7.5, lines 748–1179; migration Stage 4, lines 121–171 | `database/schema.sql` lines 643–660 has amount and optionality but no frequency or schedule. | Historical cadence cannot be inferred. A simple unique key cannot exclude overlapping arbitrary date ranges. | Keep unknown frequency NULL; support `custom` with explicit schedules and assignment snapshots. Prevent overlap for the exact scope/group through a locked guard row and transactional interval check. | No; approved rules. |
| H4 | High | Design §7.4, lines 1067–1120 | `database/schema.sql` lines 643–660 has unique item identity by institution/structure/category, but no `(institution_id,id,category_id)` candidate key. | FKs on item ID and category separately do not prove that a snapshot category is the source item's category. | Add `(institution_id,id,category_id)` as a candidate key and reference it as a composite FK. Do not use composite `ON DELETE SET NULL` when the tenant column is non-null. | No. |
| H5 | High | Design §6, lines 686–691 and §8, lines 1266–1272; migration Stages 2 and 5, lines 83–89 and 181–186; diagram §4, lines 140–151 | The current schema has no section-group relationship; the approved school model requires class and section placement. | A class-only group could otherwise be used for a school enrolment, bypassing the required section. A single whole-class session cannot match multiple exact section groups. | Require section groups for school enrolment placements and attendance. A cross-section whole-class session remains unsupported unless an approved scope extension is designed. | Section-placement rule is approved; whole-class session scope remains open. |
| H6 | High | Design §6, lines 562–747; migration Stage 2, lines 70–103 | `database/schema.sql` lines 474–499 stores only mutable current year/course/class/batch placement; no enrollment history exists. | Prior-year promotions and within-year placement changes cannot be truthfully reconstructed from current values alone. | Backfill only verifiable current placement. Do not invent historical placements; mark the limitation and retain source columns. | No; this is a migration data limitation. |
| H7 | High | Design §7.4–§7.5, lines 1021–1179; migration Stage 4, lines 121–171 | The existing assignment schema has no enrolment reference (`database/schema.sql` lines 678–706). | MySQL skips the composite enrolment FK when nullable `student_enrolment_id` is NULL, so it cannot by itself require a link on new assignments or validate enrolment status. | Keep NULL only for legacy assignments. Require a non-NULL link and product-approved eligible status in each new assignment transaction; the composite FK validates tenant/student/year identity. | Link required is approved; status mapping remains open. |

### Medium — Data and service validation

| # | Severity | Document section and exact line(s) | Existing schema evidence | Consequence | Correction / gate | Approval required? |
|---|---|---|---|---|---|---|
| M1 | Medium | Design §3, lines 87–103; migration Stage 1, lines 47–68 | `database/schema.sql` lines 7–30 and 371–386 show no institution active-year pointer and independent year status. | A pointer could select a draft or closed year if lifecycle is not validated. | Keep pointer selection separate from year lifecycle; enforce selected year status in the same service transaction. Do not infer or close years during migration. | Whether changing the pointer auto-closes a former year remains open. |
| M2 | Medium | Design §§7.1 and 7.3, lines 750–1020; migration Stage 4, lines 121–171 | `database/schema.sql` lines 622–660 has nullable class/course dimensions and no typed target, period, or purpose group. | Historical rows cannot be assigned a model or overlap semantics by inference. | Leave them `legacy`; type only after deterministic review. Different fee groups may coexist; overlap is checked for exact target/group. | No. |
| M3 | Medium | Design §8, lines 1180–1285; migration Stage 5, lines 172–186 | `database/schema.sql` lines 503–520 requires `batch_id` and keys attendance by batch/student/date. | The old table cannot safely represent school or course/term sessions without changing legacy behavior. | New session/record tables serve new models; legacy batch rows stay intact and are backfilled only when uniquely verifiable. | No. |
| M4 | Medium | Migration preflight, lines 245–247 and 286–304 | `database/schema.sql` lines 474–499 makes student placement fields a mutable current projection, while attendance rows retain their own academic year (`schema.sql` lines 503–520). | Comparing historic attendance directly with the student's current placement would falsely flag valid prior-year attendance. | The query is explicitly a conservative manual-review queue, not proof of invalid data; historical rows are never repaired from current fields alone. | No. |

## 3. Composite FK and candidate-key audit

| Relationship | Child key → parent key | Referenced candidate key |
|---|---|---|
| Institution active year | `(id, active_academic_year_id)` → year `(institution_id,id)` | Existing `uq_academic_years_institution_id` |
| Year class/course offering | Tenant/year/catalog IDs | Existing tenant keys on years, classes, and courses |
| Section | `(institution_id, academic_year_id, class_id)` → year class | `uq_year_classes_class` |
| Academic group | Tenant/year plus class/section/course/term/batch columns | Year-offering, section, term, and proposed batch composite keys |
| Enrolment | Student, year, and promotion source `(institution_id, student_id, promoted_from_enrolment_id)` | Existing student/year tenant keys; `uq_enrolment_student_identity` |
| Placement | `(institution_id, student_id, academic_year_id, student_enrolment_id)`; group/year; predecessor identity | `uq_enrolment_student_year_id`; `uq_academic_groups_id`; `uq_enrolment_placement_identity` |
| Fee structure | Same-tenant/year class/course offering, section, term, and batch/course | Offering scope keys, `uq_sections_offering_id`, `uq_academic_terms_scope_id`, `uq_batches_course_id` |
| Fee assignment | `(institution_id, academic_year_id, fee_structure_id)` and `(institution_id, student_id, academic_year_id, student_enrolment_id)` | `uq_fee_structures_year_id`; `uq_enrolment_student_year_id` |
| Fee snapshot | Source item plus category; assignment, category, section | Proposed `(institution_id,id,category_id)` and tenant identity keys |
| Attendance session | `(institution_id, academic_year_id, academic_group_id)` → group | `uq_academic_groups_id` |
| Attendance record/session | `(institution_id, academic_year_id, academic_group_id, attendance_session_id)` | `uq_attendance_sessions_id` |
| Attendance record/placement | `(institution_id, academic_year_id, academic_group_id, student_id, student_enrolment_id, placement_id)` | `uq_enrolment_placement_scope` |

The attendance record's session and placement references share non-null tenant,
year, and group columns, so their two FKs establish that both parents have the
same exact scope. The placement FK additionally includes student and
enrolment. Placement effective-time versus session-time is a cross-row
temporal rule and remains a transactional service check.

The fee-assignment enrolment FK includes institution, student, year, and
enrolment; the separate structure FK includes institution, year, and structure.
The source-item snapshot FK includes the stable fee-category identity. The
assignment transaction separately checks that the source item and section
belong to the selected class-default/section-override scope.

## 4. MySQL 8.4 and DDL validity

- The enrolment uniqueness rule uses ordinary stored columns, not generated
  cross-year currentness. Generated columns are only indexed normalized scope
  values and are not FK columns.
- MySQL unique indexes permit multiple NULLs. This is relied on for optional
  admission numbers and nullable legacy fee normalization. Typed target shape
  is guarded by CHECK constraints.
- A composite FK with any nullable child component can be skipped. CHECK
  constraints require the dimensions of each selected target; nullable links
  remain only where legacy compatibility is intentional.
- CHECK constraints validate row-local target shapes and date ordering.
  Active-year status, period overlap, and effective placement time need
  transaction-level validation.
- No composite FK uses `ON DELETE SET NULL`. `RESTRICT` preserves historical
  references. Nullable actor references are single-column FKs and do not null
  tenant identity.
- The proposed child FKs have explicit candidate keys with matching column
  order; the design does not rely on partial key prefixes as semantic checks.

## 5. Genuine open decisions and technical gates

The one-enrolment-per-student/year rule, withdrawal/cancellation slot
behavior, reactivation rule, placement event fields, fee grouping and overlap
prevention, section-based school placement, and session-based attendance are
approved and are not open questions.

The following items are classified as **Open decision** and are mirrored in
the design's decision register (lines 1400–1454) and the migration plan's
decision section (lines 400–426):

1. Academic-model changes after configuration and conversion workflow.
2. Whether section subject mappings supplement/exclude or replace the class
   subject set.
3. Whether admission numbers may be year-specific and the roll-number
   uniqueness scope.
4. Whether changing the selected active year closes the previous year.
5. Scholarship award lifecycle and use of existing discounts/adjustments.
6. How custom fee schedules map to installment generation and payment plans.
7. Whether a school needs one whole-class attendance session spanning multiple
   sections, rather than section-scoped sessions.
8. Whether section fee overrides may suppress an inherited fee component.
9. Whether `enrolled`, `active`, or both enrolment statuses are eligible for
   fee assignment.

Operational preflight results are also pending. Assignment/year mismatches,
cross-tenant batch links, invalid student placements, and ambiguous attendance
backfills must be resolved or deliberately left on the legacy path before the
corresponding FK/cutover stage. This is a technical data gate, not a reason to
reopen approved rules.

## 6. Scope and verification

The four updated documentation files are:

- `docs/ACADEMIC_DATABASE_DESIGN.md`
- `docs/ACADEMIC_RELATIONSHIP_DIAGRAM.md`
- `docs/ACADEMIC_MIGRATION_PLAN.md`
- `docs/ACADEMIC_DESIGN_VALIDATION_REPORT.md`

No application code, schema, SQL migration, database record, payment,
authentication, OTP, RBAC, institution onboarding, or receipt behavior was
changed. No SQL or migrations were executed. Stop pending explicit approval
before implementation.
