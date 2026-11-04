# Academic Relationship Diagrams

**Status:** Approved product rules and proposed technical design — documentation only.

## 1. Shared academic model

```mermaid
erDiagram
    INSTITUTIONS ||--o{ ACADEMIC_YEARS : owns
    INSTITUTIONS o|--|| ACADEMIC_YEARS : selects_active
    INSTITUTIONS ||--o{ CLASSES : catalogs
    INSTITUTIONS ||--o{ COURSES : catalogs
    INSTITUTIONS ||--o{ DEPARTMENTS : catalogs
    INSTITUTIONS ||--o{ SUBJECTS : catalogs

    ACADEMIC_YEARS ||--o{ ACADEMIC_YEAR_CLASSES : offers
    CLASSES ||--o{ ACADEMIC_YEAR_CLASSES : offered_as
    ACADEMIC_YEAR_CLASSES ||--o{ SECTIONS : groups

    ACADEMIC_YEARS ||--o{ ACADEMIC_YEAR_COURSES : offers
    COURSES ||--o{ ACADEMIC_YEAR_COURSES : offered_as
    DEPARTMENTS o|--o{ COURSES : organizes
    ACADEMIC_YEAR_COURSES ||--o{ ACADEMIC_TERMS : contains

    ACADEMIC_YEAR_CLASSES ||--o{ ACADEMIC_GROUPS : class_or_section_scope
    SECTIONS o|--o{ ACADEMIC_GROUPS : section_scope
    ACADEMIC_YEAR_COURSES ||--o{ ACADEMIC_GROUPS : course_or_term_scope
    ACADEMIC_TERMS o|--o{ ACADEMIC_GROUPS : term_scope
    BATCHES o|--o{ ACADEMIC_GROUPS : batch_scope

    ACADEMIC_YEAR_CLASSES ||--o{ CLASS_SUBJECTS : assigns
    SUBJECTS ||--o{ CLASS_SUBJECTS : appears_in
    CLASS_SUBJECTS ||--o{ SECTION_SUBJECTS : customized_by
    SECTIONS ||--o{ SECTION_SUBJECTS : assigns

    STUDENTS ||--o{ STUDENT_ENROLMENTS : has_history
    ACADEMIC_YEARS ||--o{ STUDENT_ENROLMENTS : scopes
    STUDENT_ENROLMENTS ||--|{ STUDENT_ENROLMENT_PLACEMENTS : placement_history
    ACADEMIC_GROUPS ||--o{ STUDENT_ENROLMENT_PLACEMENTS : placement_scope
    STUDENT_ENROLMENTS ||--o{ STUDENT_ENROLMENT_STATUS_EVENTS : status_audit
    STUDENT_ENROLMENTS o|--o{ STUDENT_ENROLMENTS : promoted_from
```

`ACADEMIC_YEAR_CLASSES` and `ACADEMIC_YEAR_COURSES` are offerings: they bind
reusable catalog records to a particular year. Sections belong to a class
offering. The unique enrolment key is
`(institution_id, academic_year_id, student_id)` and is not generated; it
applies to every status. Placement rows append the successive class/section or
course/term/batch scope for the year and retain a predecessor link, effective
time, reason, and actor. The diagram omits the repeated `institution_id` tenant
key present in every proposed tenant-owned relation. Composite tenant FKs are specified in
[ACADEMIC_DATABASE_DESIGN.md](./ACADEMIC_DATABASE_DESIGN.md).

## 2. Model-specific paths

```mermaid
flowchart LR
    I[Institution] --> Y[Academic Year]

    Y -->|School| YC[Year Class Offering]
    YC --> C[Class Catalog]
    YC --> S[Section]
    YC --> CS[Class Subjects]
    CS --> SS[Optional Section Subject Changes]
    S --> SE[Student Enrolment]
    YC --> SE

    Y -->|College| YP[Year Programme Offering]
    Y -->|University| YP
    D[Department] -. university programme grouping .-> P[Course / Programme]
    P --> YP
    YP --> T[Semester / Academic Term]
    YP --> PS[Programme Subjects]
    T --> SE
    YP --> SE

    Y -->|Coaching| YP2[Optional Year Programme Offering]
    P --> YP2
    YP2 --> B[Batch]
    B --> SE
```

## 3. Fee and settlement relationships

```mermaid
erDiagram
    INSTITUTIONS ||--o{ FEE_STRUCTURES : owns
    ACADEMIC_YEARS ||--o{ FEE_STRUCTURES : scopes
    ACADEMIC_YEAR_CLASSES o|--o{ FEE_STRUCTURES : class_default
    ACADEMIC_YEAR_COURSES o|--o{ FEE_STRUCTURES : course_year_target
    SECTIONS o|--o{ FEE_STRUCTURES : section_override
    COURSES o|--o{ FEE_STRUCTURES : programme_scope
    ACADEMIC_TERMS o|--o{ FEE_STRUCTURES : term_scope
    BATCHES o|--o{ FEE_STRUCTURES : batch_scope

    FEE_STRUCTURES ||--|{ FEE_STRUCTURE_ITEMS : defines
    FEE_CATEGORIES ||--o{ FEE_STRUCTURE_ITEMS : categorizes
    FEE_STRUCTURE_ITEMS ||--o{ FEE_COMPONENT_SCHEDULE : defines_schedule
    FEE_CATEGORIES ||--o{ STUDENT_FEE_ASSIGNMENT_ITEMS : snapshot_identity

    STUDENTS ||--o{ STUDENT_ENROLMENTS : enrolls
    STUDENT_ENROLMENTS o|--o{ STUDENT_FEE_ASSIGNMENTS : qualifies
    STUDENTS ||--o{ STUDENT_FEE_ASSIGNMENTS : receives
    FEE_STRUCTURES ||--o{ STUDENT_FEE_ASSIGNMENTS : source
    PAYMENT_PLANS o|--o{ STUDENT_FEE_ASSIGNMENTS : schedules

    STUDENT_FEE_ASSIGNMENTS ||--o{ STUDENT_FEE_ASSIGNMENT_ITEMS : snapshots
    FEE_STRUCTURE_ITEMS o|--o{ STUDENT_FEE_ASSIGNMENT_ITEMS : source_item
    STUDENT_FEE_ASSIGNMENT_ITEMS ||--o{ ASSIGNMENT_ITEM_SCHEDULES : snapshots_schedule

    STUDENT_FEE_ASSIGNMENTS ||--o{ INSTALLMENTS : has
    INSTALLMENTS o|--o{ PAYMENTS : settled_by
    PAYMENT_ORDERS ||--o| PAYMENTS : fulfills
    PAYMENT_ORDERS ||--o{ PAYMENT_ATTEMPTS : attempts
    PAYMENTS ||--o| RECEIPTS : documents
    PAYMENTS ||--o{ REFUNDS : may_be_refunded
```

The final chain is existing payment and receipt architecture. Academic design
changes do not change these relationships. Existing assignments may retain a
NULL enrolment link for compatibility; every new assignment requires a
non-NULL link and the product-approved fee-eligible status. Composite FKs
enforce matching institution/student/year and matching institution/year/fee
structure. Whether `enrolled` as well as `active` is fee-eligible remains open.
Component snapshot identity is the existing fee category; source item/category
and source section references are tenant-scoped. The diagram does not show the
repeated composite-key columns. Historical fee components have unknown
frequency until explicitly configured; their cadence is not inferred.

## 4. Session attendance and compatibility

```mermaid
erDiagram
    ACADEMIC_GROUPS ||--o{ ATTENDANCE_SESSIONS : scopes
    ATTENDANCE_SESSIONS ||--o{ ATTENDANCE_SESSION_RECORDS : records
    STUDENT_ENROLMENTS ||--|{ STUDENT_ENROLMENT_PLACEMENTS : owns
    ACADEMIC_GROUPS ||--o{ STUDENT_ENROLMENT_PLACEMENTS : places
    STUDENT_ENROLMENT_PLACEMENTS ||--o{ ATTENDANCE_SESSION_RECORDS : validates_placement
    STUDENTS ||--o{ ATTENDANCE_SESSION_RECORDS : attends
```

Each new attendance record references a session and a dated enrolment
placement. Composite FKs require the same institution, academic year, and
academic group on both references, plus the same student and enrolment on the
placement reference. School enrolments and sessions use a section group that
identifies both class and section, without requiring batches. College uses
course/term groups and coaching uses batch groups. A whole-class session
spanning multiple sections is not represented by one session under the exact
scope FKs; it requires an approved scope extension.

The existing `attendance_records` table remains the batch-based legacy source
and is not replaced or weakened during migration. It has no assumed FK to the
new session records; a verified backfill maps only unambiguous rows and leaves
the original record intact.

Fee structures include a target group code and effective period. Different
fee-purpose groups may coexist. For the same exact target/group, an indexed
guard row serializes writes while a transaction rejects overlapping active
periods; the range-exclusion rule is not represented as a simple unique key.

## 5. Example school fee inheritance

```mermaid
flowchart LR
    Y[2026-2027] --> C[Class 5 offering]
    C --> A[Section A]
    C --> B[Section B]
    C --> D[Section C]
    C --> CF[Class default fee components]
    B --> BF[Optional section override]
    CF --> SA[Section A assignment snapshot]
    CF --> SC[Section C assignment snapshot]
    CF --> SB0[Section B inherited components]
    BF --> SB[Section B matching components override]
    SB0 --> SB
    SA --> PAYA[Existing installments / payments / receipts]
    SB --> PAYB[Existing installments / payments / receipts]
    SC --> PAYC[Existing installments / payments / receipts]
```

An override changes future snapshots only. No existing assignment or payment
history is recalculated.
