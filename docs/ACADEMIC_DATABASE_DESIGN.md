# Flexible Academic Database Design

**Status:** Approved product rules and proposed technical design — documentation only<br>
**Scope:** Academic structure, enrolment history, class-based fees, attendance compatibility<br>
**Database baseline:** Existing MySQL 8.4 schema in `database/schema.sql`

## 1. Design goals and constraints

- Keep the existing `institution_type` descriptive field separate from a new
  `academic_model` configuration. Institution type answers what an institution
  is; the model determines which academic entities and workflows it uses.
- Reuse `courses`, `classes`, `batches`, `academic_years`, students, fee
  categories, fee structures, and the existing payment lifecycle.
- Make academic enrolment historical and immutable by academic year. Promotion
  inserts a new enrolment; it does not rewrite the prior enrolment.
- Enforce exactly one enrolment row per student, institution, and academic
  year, regardless of lifecycle status. Reactivation reuses that row.
- For a school, classes are the principal fee scope. A course must not be a
  prerequisite for a school fee structure or class enrolment.
- Keep tenant identity in each tenant-owned row and use composite foreign keys
  for same-institution references. Validate semantic relationships in
  transactions where MySQL constraints cannot express them.
- Do not modify payment, payment-order, attempt, webhook, refund, receipt, OTP,
  authentication, parent, staff, or RBAC lifecycles as part of this design.

The SQL below is illustrative MySQL-style DDL for review, not a migration or
instruction to apply it. Types should match the existing `BIGINT UNSIGNED`
identifiers, collation, and timestamp conventions. Existing table definitions
are described as additive deltas; the full current schema remains authoritative
until an approved implementation phase.

## 2. Institution configuration

`institutions.institution_type VARCHAR(40) NULL` already exists. Keep it; do
not add a duplicate `institution_type` column.

Proposed additive fields:

```sql
ALTER TABLE institutions
  ADD COLUMN academic_model ENUM(
    'school', 'college', 'university', 'coaching'
  ) NULL,
  ADD COLUMN active_academic_year_id BIGINT UNSIGNED NULL,
  ADD COLUMN academic_configuration_status ENUM(
    'unconfigured', 'in_progress', 'configured'
  ) NOT NULL DEFAULT 'unconfigured',
  ADD KEY idx_institutions_active_academic_year
    (id, active_academic_year_id);

-- Add after academic_years(institution_id,id) is available as a referenced key:
ALTER TABLE institutions
  ADD CONSTRAINT fk_institutions_active_academic_year
  FOREIGN KEY (id, active_academic_year_id)
  REFERENCES academic_years(institution_id, id)
  ON DELETE RESTRICT;
```

`active_academic_year_id` is nullable for institutions that have not completed
setup. The composite FK ensures an institution can select only its own year.
The year remains independently owned by `academic_years`; this FK does not
replace its institution FK.

### Model values

| `academic_model` | Intended structure |
|---|---|
| `school` | Year-specific class offerings, sections, class/section subjects, enrolments |
| `college` | Existing courses reused as programmes, year-specific programme offerings, terms/semesters, subjects |
| `university` | Departments, programmes linked to departments, programme offerings, terms/semesters, subjects |
| `coaching` | Existing courses and batches, optional year-scoped offerings, subjects |

`institution_type` may retain current values such as `school`, `college`,
`university`, and `coaching_institute`. It is not a foreign key or implicit
instruction to rewrite data. A system administrator chooses `academic_model`
explicitly. Do not infer it from historic labels in application code at every
request.

For legacy rows, keep `academic_model = NULL` until the institution is reviewed
and configured. Use an application-level compatibility profile (the legacy
course/class/batch flow) for unset models during rollout. Do not classify every
institution whose `institution_type` is NULL as a school. Record model changes
in the existing audit log. Once configured, changes that would invalidate
current enrolments or fees require a preflight and an explicit conversion
workflow.

## 3. Existing academic-year table

`academic_years` already has an institution FK, composite unique key
`(institution_id,id)`, tenant-unique name, date range, status, and audit
timestamps. Keep this table as the single source for academic years.

Do not delete or repurpose rows. Add new FK references to
`(institution_id,id)` from offerings, enrolments, fees, and attendance as
required. The institution active-year pointer is a convenience pointer, not a
replacement for historical year references.

Separate the two concepts: `academic_years.status` is the lifecycle/eligibility
of a year (`draft`, `active`, `closed`); `institutions.active_academic_year_id`
is the institution's one selected year for normal current enrolment operations.
The pointer is authoritative for selection, not the status column. A selected
year must have status `active`; enforce that cross-table invariant in the same
service transaction that changes the pointer because a foreign key cannot test
the referenced row's status. An `active` year is not implicitly selected, and
multiple years may retain `active` status during transition. Do not silently
close them or rewrite enrolments when setting the pointer. Existing active
statuses do not populate the pointer automatically.

## 4. Shared offerings, classes, and sections

### 4.1 Class catalog and year offerings

Reuse `classes` as the institution-level class/grade catalog. Add the requested
display order, but do not put a year on the catalog row: “Class 5” is reused
from year to year. A separate offering binds a catalog class to one academic
year.

```sql
-- Additive class-catalog field:
ALTER TABLE classes
  ADD COLUMN display_order INT UNSIGNED NOT NULL DEFAULT 0;

CREATE TABLE academic_year_classes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  institution_id BIGINT UNSIGNED NOT NULL,
  academic_year_id BIGINT UNSIGNED NOT NULL,
  class_id BIGINT UNSIGNED NOT NULL,
  status ENUM('planned','active','closed') NOT NULL DEFAULT 'planned',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_year_classes_id (institution_id, id),
  UNIQUE KEY uq_year_classes_class (institution_id, academic_year_id, class_id),
  UNIQUE KEY uq_year_classes_scope_id
    (institution_id, academic_year_id, class_id, id),
  KEY idx_year_classes_class (institution_id, class_id, academic_year_id),
  CONSTRAINT fk_year_classes_year FOREIGN KEY (institution_id, academic_year_id)
    REFERENCES academic_years(institution_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_year_classes_class FOREIGN KEY (institution_id, class_id)
    REFERENCES classes(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB;
```

`classes` retains its existing PK, `uq_classes_institution_id`,
institution-scoped unique code, status index, and institution FK. Duplicate
class-name enforcement should be decided with collation and existing records
reviewed; where required, add a unique `(institution_id,name)` constraint only
after a duplicate preflight.

### 4.2 Sections

Sections are offerings within a class and academic year. Storing
`academic_year_id` on a section makes section identity and capacity year-specific
and lets a composite FK ensure it matches its parent class offering.

```sql
CREATE TABLE sections (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  institution_id BIGINT UNSIGNED NOT NULL,
  academic_year_id BIGINT UNSIGNED NOT NULL,
  class_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  code VARCHAR(50) NULL,
  capacity INT UNSIGNED NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sections_institution_id (institution_id, id),
  UNIQUE KEY uq_sections_offering_id
    (institution_id, academic_year_id, class_id, id),
  UNIQUE KEY uq_sections_year_class_name
    (institution_id, academic_year_id, class_id, name),
  UNIQUE KEY uq_sections_year_class_code
    (institution_id, academic_year_id, class_id, code),
  KEY idx_sections_class_status
    (institution_id, academic_year_id, class_id, status),
  CONSTRAINT fk_sections_class_offering
    FOREIGN KEY (institution_id, academic_year_id, class_id)
    REFERENCES academic_year_classes(institution_id, academic_year_id, class_id)
    ON DELETE RESTRICT
) ENGINE=InnoDB;
```

`uq_sections_offering_id` is required for downstream composite FKs that
reference `(institution_id,academic_year_id,class_id,id)`. `sections` is
tenant-owned through the offering FK. Nullable `code` permits multiple sections
without a code under MySQL's unique-NULL behavior. Capacity is nullable and
positive when present; enforce positivity in the service or a supported CHECK
constraint.

### 4.3 Courses/programmes and year offerings

Reuse `courses` for courses or programmes. It already has tenant ownership,
name/code, description, duration, and status. For university use, add an
optional department reference. The course catalog remains institution-level;
offerings state which programme runs in a year.

```sql
CREATE TABLE departments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  institution_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  code VARCHAR(50) NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_departments_institution_id (institution_id, id),
  UNIQUE KEY uq_departments_name (institution_id, name),
  UNIQUE KEY uq_departments_code (institution_id, code),
  KEY idx_departments_status (institution_id, status),
  CONSTRAINT fk_departments_institution FOREIGN KEY (institution_id)
    REFERENCES institutions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE courses
  ADD COLUMN department_id BIGINT UNSIGNED NULL,
  ADD KEY idx_courses_department (institution_id, department_id),
  ADD CONSTRAINT fk_courses_department
    FOREIGN KEY (institution_id, department_id)
    REFERENCES departments(institution_id, id) ON DELETE RESTRICT;

CREATE TABLE academic_year_courses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  institution_id BIGINT UNSIGNED NOT NULL,
  academic_year_id BIGINT UNSIGNED NOT NULL,
  course_id BIGINT UNSIGNED NOT NULL,
  status ENUM('planned','active','closed') NOT NULL DEFAULT 'planned',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_year_courses_id (institution_id, id),
  UNIQUE KEY uq_year_courses_course (institution_id, academic_year_id, course_id),
  UNIQUE KEY uq_year_courses_scope_id
    (institution_id, academic_year_id, course_id, id),
  KEY idx_year_courses_course (institution_id, course_id, academic_year_id),
  CONSTRAINT fk_year_courses_year FOREIGN KEY (institution_id, academic_year_id)
    REFERENCES academic_years(institution_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_year_courses_course FOREIGN KEY (institution_id, course_id)
    REFERENCES courses(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB;
```

Departments are optional for college programmes and required by application
policy for a university programme once the university model is configured.
Adding a nullable FK does not force changes to existing course rows.

### 4.4 Academic terms / semesters

One reusable table represents a semester, term, trimester, or year-level within
a course offering. It avoids separate college and university term tables.

```sql
CREATE TABLE academic_terms (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  institution_id BIGINT UNSIGNED NOT NULL,
  academic_year_id BIGINT UNSIGNED NOT NULL,
  course_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  code VARCHAR(50) NULL,
  term_type ENUM('semester','term','trimester','year') NOT NULL,
  sequence_number SMALLINT UNSIGNED NOT NULL,
  start_date DATE NULL,
  end_date DATE NULL,
  status ENUM('planned','active','closed') NOT NULL DEFAULT 'planned',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_academic_terms_institution_id (institution_id, id),
  UNIQUE KEY uq_academic_terms_scope_id
    (institution_id, academic_year_id, course_id, id),
  UNIQUE KEY uq_academic_terms_name
    (institution_id, academic_year_id, course_id, name),
  UNIQUE KEY uq_academic_terms_sequence
    (institution_id, academic_year_id, course_id, sequence_number),
  KEY idx_academic_terms_course_status
    (institution_id, course_id, academic_year_id, status),
  CONSTRAINT fk_academic_terms_year_course
    FOREIGN KEY (institution_id, academic_year_id, course_id)
    REFERENCES academic_year_courses(institution_id, academic_year_id, course_id)
    ON DELETE RESTRICT
) ENGINE=InnoDB;
```

Term dates may be NULL when the institution does not maintain term dates.
Validate date ordering when both dates are supplied. A separate term table
could be warranted if future requirements need nested calendar periods or
cross-listed terms; that is not needed for this phase.

### 4.5 Coaching batches

Reuse `batches` and its tenant identity. Today it requires both `course_id` and
`class_id`, and its individual course/class FKs do not include
`institution_id`. Before changing those FKs, audit and repair all rows, add or
verify tenant-scoped unique keys on the parent tables, then replace the
ID-only references with composite `(institution_id, course_id)` and
`(institution_id, class_id)` FKs. Keep existing batch IDs and data.

For a coaching model, allow `class_id` to be NULL so a course can have batches
without a school-style class. Keep `course_id` required. Batches are reusable
course-bound groups, not year offerings; the enrolment's year-course FK
establishes that the course is offered in that year. Retain `start_time`,
`end_time`, `capacity`, status, and institution-scoped code.

The required parent candidate keys for enrolment's course/batch and
class/batch consistency FKs do not exist yet, so add them before those FKs.
The actual current schema has only `uq_batches_institution_id(institution_id,id)`
and ID-only batch FKs; `courses` and `classes` each have
`uq_*_institution_id(institution_id,id)`.

```sql
ALTER TABLE batches
  MODIFY class_id BIGINT UNSIGNED NULL,
  ADD UNIQUE KEY uq_batches_course_id (institution_id, course_id, id),
  ADD UNIQUE KEY uq_batches_class_id (institution_id, class_id, id),
  ADD KEY idx_batches_course_tenant (institution_id, course_id),
  ADD KEY idx_batches_class_tenant (institution_id, class_id),
  DROP FOREIGN KEY fk_batches_course,
  DROP FOREIGN KEY fk_batches_class,
  ADD CONSTRAINT fk_batches_course_tenant
    FOREIGN KEY (institution_id, course_id)
    REFERENCES courses(institution_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_batches_class_tenant
    FOREIGN KEY (institution_id, class_id)
    REFERENCES classes(institution_id, id) ON DELETE RESTRICT;
```

Because `class_id` becomes nullable, existing batch rows must be checked before
the `MODIFY`; the shown type matches the existing `BIGINT UNSIGNED` column.
The nullable class composite FK is skipped when class is NULL, while the
required course composite FK still enforces tenant ownership. Enrolments with a
batch must also name its course; if both enrolment class and batch are present,
the composite FK requires the batch's class to match.

### 4.6 Normalized academic groups

`academic_groups` gives each year-specific class, section, course, term, or
batch scope one tenant-safe identity. Enrolment placement rows and attendance
sessions reference this identity rather than relying on loosely related IDs.
Schools use class/section groups directly; no course or batch is required.

```sql
CREATE TABLE academic_groups (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  institution_id BIGINT UNSIGNED NOT NULL,
  academic_year_id BIGINT UNSIGNED NOT NULL,
  group_type ENUM('class','section','course','term','batch') NOT NULL,
  class_id BIGINT UNSIGNED NULL,
  section_id BIGINT UNSIGNED NULL,
  course_id BIGINT UNSIGNED NULL,
  academic_term_id BIGINT UNSIGNED NULL,
  batch_id BIGINT UNSIGNED NULL,
  scope_id BIGINT UNSIGNED
    GENERATED ALWAYS AS (
      CASE group_type
        WHEN 'class' THEN class_id
        WHEN 'section' THEN section_id
        WHEN 'course' THEN course_id
        WHEN 'term' THEN academic_term_id
        WHEN 'batch' THEN batch_id
      END
    ) STORED,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_academic_groups_id (institution_id, academic_year_id, id),
  UNIQUE KEY uq_academic_groups_scope
    (institution_id, academic_year_id, group_type, scope_id),
  KEY idx_academic_groups_class (institution_id, academic_year_id, class_id),
  KEY idx_academic_groups_course (institution_id, academic_year_id, course_id),
  KEY idx_academic_groups_batch (institution_id, course_id, batch_id),
  CONSTRAINT chk_academic_groups_target CHECK (
    (group_type = 'class' AND class_id IS NOT NULL AND section_id IS NULL
      AND course_id IS NULL AND academic_term_id IS NULL AND batch_id IS NULL)
    OR (group_type = 'section' AND class_id IS NOT NULL AND section_id IS NOT NULL
      AND course_id IS NULL AND academic_term_id IS NULL AND batch_id IS NULL)
    OR (group_type = 'course' AND class_id IS NULL AND section_id IS NULL
      AND course_id IS NOT NULL AND academic_term_id IS NULL AND batch_id IS NULL)
    OR (group_type = 'term' AND class_id IS NULL AND section_id IS NULL
      AND course_id IS NOT NULL AND academic_term_id IS NOT NULL AND batch_id IS NULL)
    OR (group_type = 'batch' AND class_id IS NULL AND section_id IS NULL
      AND course_id IS NOT NULL AND academic_term_id IS NULL AND batch_id IS NOT NULL)
  ),
  CONSTRAINT fk_academic_groups_year
    FOREIGN KEY (institution_id, academic_year_id)
    REFERENCES academic_years(institution_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_academic_groups_class
    FOREIGN KEY (institution_id, academic_year_id, class_id)
    REFERENCES academic_year_classes(institution_id, academic_year_id, class_id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_academic_groups_section
    FOREIGN KEY (institution_id, academic_year_id, class_id, section_id)
    REFERENCES sections(institution_id, academic_year_id, class_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_academic_groups_course
    FOREIGN KEY (institution_id, academic_year_id, course_id)
    REFERENCES academic_year_courses(institution_id, academic_year_id, course_id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_academic_groups_term
    FOREIGN KEY (institution_id, academic_year_id, course_id, academic_term_id)
    REFERENCES academic_terms(institution_id, academic_year_id, course_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_academic_groups_batch
    FOREIGN KEY (institution_id, course_id, batch_id)
    REFERENCES batches(institution_id, course_id, id)
    ON DELETE RESTRICT
) ENGINE=InnoDB;
```

The CHECK permits only one shape for each group type; nullable unrelated
columns do not weaken the selected target FK. A batch group also has a
same-year course-offering FK. `scope_id` is an indexed generated column, not
an FK column; `group_type` keeps otherwise overlapping numeric IDs in distinct
namespaces.

## 5. Subjects and subject placement

Subjects are institution-level catalog entities reusable across years and
classes. Keep the existing permission keys if they are used for subject UI;
this design does not create or alter RBAC.

```sql
CREATE TABLE subjects (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  institution_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  code VARCHAR(50) NULL,
  subject_type ENUM('core','elective','optional') NOT NULL DEFAULT 'core',
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_subjects_institution_id (institution_id, id),
  UNIQUE KEY uq_subjects_name (institution_id, name),
  UNIQUE KEY uq_subjects_code (institution_id, code),
  KEY idx_subjects_status (institution_id, status),
  CONSTRAINT fk_subjects_institution FOREIGN KEY (institution_id)
    REFERENCES institutions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE class_subjects (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  institution_id BIGINT UNSIGNED NOT NULL,
  academic_year_id BIGINT UNSIGNED NOT NULL,
  class_id BIGINT UNSIGNED NOT NULL,
  subject_id BIGINT UNSIGNED NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_class_subjects_institution_id (institution_id, id),
  UNIQUE KEY uq_class_subjects_assignment
    (institution_id, academic_year_id, class_id, subject_id),
  KEY idx_class_subjects_subject (institution_id, subject_id, academic_year_id),
  CONSTRAINT fk_class_subjects_class_offering
    FOREIGN KEY (institution_id, academic_year_id, class_id)
    REFERENCES academic_year_classes(institution_id, academic_year_id, class_id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_class_subjects_subject
    FOREIGN KEY (institution_id, subject_id)
    REFERENCES subjects(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB;
```

For section-specific assignments, use a separate `section_subjects` table, not a
nullable `section_id` on `class_subjects`. A separate table has clear semantics:
class assignments are defaults, section assignments are explicit overrides or
exceptions. At read time, section assignments replace or supplement the class
set according to a policy chosen by the institution. A composite FK enforces
that the section belongs to the same year and class as its default assignment.

```sql
CREATE TABLE section_subjects (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  institution_id BIGINT UNSIGNED NOT NULL,
  academic_year_id BIGINT UNSIGNED NOT NULL,
  class_id BIGINT UNSIGNED NOT NULL,
  section_id BIGINT UNSIGNED NOT NULL,
  subject_id BIGINT UNSIGNED NOT NULL,
  assignment_mode ENUM('include','exclude') NOT NULL DEFAULT 'include',
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_section_subjects_institution_id (institution_id, id),
  UNIQUE KEY uq_section_subjects_assignment
    (institution_id, academic_year_id, section_id, subject_id),
  KEY idx_section_subjects_class_subject
    (institution_id, academic_year_id, class_id, subject_id),
  KEY idx_section_subjects_section
    (institution_id, academic_year_id, class_id, section_id),
  CONSTRAINT fk_section_subjects_section
    FOREIGN KEY (institution_id, academic_year_id, class_id, section_id)
    REFERENCES sections(institution_id, academic_year_id, class_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_section_subjects_class_subject
    FOREIGN KEY (institution_id, academic_year_id, class_id, subject_id)
    REFERENCES class_subjects(institution_id, academic_year_id, class_id, subject_id)
    ON DELETE RESTRICT
) ENGINE=InnoDB;
```

The `include`/`exclude` pair represents a class subject list with explicit
section changes. If product policy instead wants section lists to be wholly
independent, remove `assignment_mode` and define replacement semantics. This is
an approval decision, not an implicit implementation choice.

For college/university subjects, the same catalog can be attached to a
programme offering and optional term using a separate `course_subjects`
mapping. `academic_term_id` may be nullable for programme-wide subjects.
`uq_academic_terms_scope_id` supplies the exact term parent key, and the
generated normalized term key avoids MySQL's multiple-NULL unique behavior.

```sql
CREATE TABLE course_subjects (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  institution_id BIGINT UNSIGNED NOT NULL,
  academic_year_id BIGINT UNSIGNED NOT NULL,
  course_id BIGINT UNSIGNED NOT NULL,
  academic_term_id BIGINT UNSIGNED NULL,
  subject_id BIGINT UNSIGNED NOT NULL,
  term_scope_id BIGINT UNSIGNED
    GENERATED ALWAYS AS (COALESCE(academic_term_id, 0)) STORED,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_course_subjects_institution_id (institution_id, id),
  UNIQUE KEY uq_course_subjects_assignment
    (institution_id, academic_year_id, course_id, term_scope_id, subject_id),
  KEY idx_course_subjects_term
    (institution_id, academic_year_id, course_id, academic_term_id),
  KEY idx_course_subjects_subject (institution_id, subject_id, academic_year_id),
  CONSTRAINT fk_course_subjects_offering
    FOREIGN KEY (institution_id, academic_year_id, course_id)
    REFERENCES academic_year_courses(institution_id, academic_year_id, course_id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_course_subjects_term
    FOREIGN KEY (institution_id, academic_year_id, course_id, academic_term_id)
    REFERENCES academic_terms(institution_id, academic_year_id, course_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_course_subjects_subject
    FOREIGN KEY (institution_id, subject_id)
    REFERENCES subjects(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB;
```

Reserve term ID `0` as invalid at the service boundary. The generated
programme-wide scope uses zero as a sentinel; the enrolment and subject
services must reject term ID zero and the academic-term creation service must
not permit an explicit zero ID.

## 6. Student enrolment and placement history

The approved rule is exactly one enrolment row per student, institution, and
academic year. This database unique key applies to every status, so withdrawn,
cancelled, completed, and active rows all reserve the student's one yearly
enrolment. Re-admission in that year reactivates the existing row; it never
inserts a duplicate. There is deliberately no generated global-current-student
key and no unique key spanning a student across academic years.

```sql
CREATE TABLE student_enrolments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  institution_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  academic_year_id BIGINT UNSIGNED NOT NULL,
  admission_number VARCHAR(50) NULL,
  enrolment_status ENUM(
    'enrolled','active','withdrawn','cancelled','completed','promoted'
  ) NOT NULL DEFAULT 'enrolled',
  enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  promoted_from_enrolment_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_student_enrolments_institution_id (institution_id, id),
  UNIQUE KEY uq_enrolment_student_year
    (institution_id, academic_year_id, student_id),
  UNIQUE KEY uq_enrolment_student_identity
    (institution_id, student_id, id),
  UNIQUE KEY uq_enrolment_student_year_id
    (institution_id, student_id, academic_year_id, id),
  UNIQUE KEY uq_enrolment_admission_year
    (institution_id, academic_year_id, admission_number),
  KEY idx_enrolments_student_status
    (institution_id, student_id, enrolment_status),
  KEY idx_enrolments_promoted_from
    (institution_id, student_id, promoted_from_enrolment_id),
  CONSTRAINT fk_enrolments_student FOREIGN KEY (institution_id, student_id)
    REFERENCES students(institution_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_enrolments_year FOREIGN KEY (institution_id, academic_year_id)
    REFERENCES academic_years(institution_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_enrolments_promotion_source FOREIGN KEY
    (institution_id, student_id, promoted_from_enrolment_id)
    REFERENCES student_enrolments(institution_id, student_id, id)
    ON DELETE RESTRICT
) ENGINE=InnoDB;
```

`uq_enrolment_student_year` is the mandatory database-level invariant. Its
column order also makes the existing `students(institution_id,id)` and
`academic_years(institution_id,id)` relationships explicit in their own FKs.
The nullable admission-number unique key permits multiple NULLs under MySQL
8.4, but prevents duplicate non-NULL admission numbers within an institution
and year. `promoted_from` must identify the same student and institution; a
service transaction additionally verifies that the source is the immediately
preceding academic year for that institution by year start date. If year order
is ambiguous, promotion requires review rather than silently skipping a year.

Represent each class/section/course/term/batch placement as an effective
placement row. Placement history is append-only: a change inserts the new
placement with a pointer to the previous placement; the previous row is not
overwritten. This records the old and new placement, effective time, reason,
and actor while making past attendance scope resolvable.
Serialize placement writes by locking the enrolment row. Allow one initial
placement with no predecessor; each later row must point to the latest
placement and have a later effective time. Reject stale predecessors and
unapproved retroactive corrections.

```sql
CREATE TABLE student_enrolment_placements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  institution_id BIGINT UNSIGNED NOT NULL,
  academic_year_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  student_enrolment_id BIGINT UNSIGNED NOT NULL,
  academic_group_id BIGINT UNSIGNED NOT NULL,
  previous_placement_id BIGINT UNSIGNED NULL,
  roll_number VARCHAR(50) NULL,
  effective_at DATETIME(6) NOT NULL,
  reason VARCHAR(500) NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_enrolment_placements_id (institution_id, id),
  UNIQUE KEY uq_enrolment_placement_time
    (institution_id, student_enrolment_id, effective_at),
  UNIQUE KEY uq_enrolment_placement_identity
    (institution_id, student_enrolment_id, id),
  UNIQUE KEY uq_enrolment_placement_scope
    (institution_id, academic_year_id, academic_group_id,
     student_id, student_enrolment_id, id),
  KEY idx_enrolment_placement_history
    (institution_id, student_enrolment_id, effective_at),
  CONSTRAINT fk_placement_enrolment
    FOREIGN KEY
      (institution_id, student_id, academic_year_id, student_enrolment_id)
    REFERENCES student_enrolments
      (institution_id, student_id, academic_year_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_placement_group
    FOREIGN KEY (institution_id, academic_year_id, academic_group_id)
    REFERENCES academic_groups(institution_id, academic_year_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_placement_previous
    FOREIGN KEY
      (institution_id, student_enrolment_id, previous_placement_id)
    REFERENCES student_enrolment_placements
      (institution_id, student_enrolment_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_placement_actor
    FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
```

The self-FK uses the placement identity candidate key, not the scope-specific
key, so a change can point from one academic group to a different group.

`academic_groups` is the normalized, year-owned scope used by enrolment
placements and attendance sessions. It represents a class offering, optional
section within that offering, course offering, optional term, or coaching
batch. The table definition and its composite target FKs are in §8. Every
placement belongs to exactly one academic group; a school section change
creates a placement row in the same class offering with a different section
group, while a class change creates one in a different class-offering group.
For a configured school, an enrolment placement must use a `section` group;
the group carries both the class offering and section, so the placement
identifies exactly one class and section. A `class` group is not sufficient as
a school enrolment placement. Validate the institution model and group type
in the placement transaction; the group FK alone cannot enforce that
model-dependent rule.

Promotion inserts a new `student_enrolments` row for the next academic year,
linked through `promoted_from_enrolment_id`; it never moves or rewrites the
prior placement. `enrolment_status` is lifecycle state, not uniqueness scope:
withdrawal preserves the row and its placements, cancellation does not free
the unique slot, and re-admission changes the existing row back to `active`
with an audited status event. Record status changes in
`student_enrolment_status_events` with previous/new status, effective time,
reason, and actor. Restrict changes to prior-year enrolments to these explicit
lifecycle transitions or approved corrections.

```sql
CREATE TABLE student_enrolment_status_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  institution_id BIGINT UNSIGNED NOT NULL,
  student_enrolment_id BIGINT UNSIGNED NOT NULL,
  previous_status ENUM(
    'enrolled','active','withdrawn','cancelled','completed','promoted'
  ) NULL,
  new_status ENUM(
    'enrolled','active','withdrawn','cancelled','completed','promoted'
  ) NOT NULL,
  effective_at DATETIME(6) NOT NULL,
  reason VARCHAR(500) NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_enrolment_status_event_id (institution_id, id),
  UNIQUE KEY uq_enrolment_status_event_time
    (institution_id, student_enrolment_id, effective_at),
  KEY idx_enrolment_status_history
    (institution_id, student_enrolment_id, effective_at),
  CONSTRAINT chk_enrolment_status_change
    CHECK (previous_status IS NULL OR previous_status <> new_status),
  CONSTRAINT fk_enrolment_status_event
    FOREIGN KEY (institution_id, student_enrolment_id)
    REFERENCES student_enrolments(institution_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_enrolment_status_actor
    FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
```

Initial admission may record a NULL previous status. Withdrawal, cancellation,
and reactivation each append a status event; they never delete enrolments,
placements, attendance, or fee assignments.

### Current-placement compatibility

During migration, legacy `students.academic_year_id`, `course_id`, `class_id`,
and `batch_id` remain a compatibility projection of the latest effective
placement for the institution's selected active year. Update the projection
and append the placement/event row in one transaction. Reconcile projection
drift; do not add independent writers. Remove legacy columns only after all
consumers are migrated, verified, and separately approved.

## 7. Fee structures and component snapshots

### 7.1 Existing fee tables

Reuse `fee_structures`, `fee_structure_items`, `fee_categories`,
`student_fee_assignments`, `installments`, `fee_discounts`, and
`fee_adjustments`. Existing fees require an academic year; course and class are
nullable. Existing assignments preserve totals and link student, structure,
payment plan, and year.

Add nullable academic-target columns to `fee_structures`, keeping existing IDs
and columns:

```sql
ALTER TABLE fee_structures
  ADD COLUMN section_id BIGINT UNSIGNED NULL,
  ADD COLUMN academic_term_id BIGINT UNSIGNED NULL,
  ADD COLUMN batch_id BIGINT UNSIGNED NULL,
  ADD COLUMN year_class_id BIGINT UNSIGNED NULL,
  ADD COLUMN year_course_id BIGINT UNSIGNED NULL,
  ADD COLUMN fee_group_code VARCHAR(40) NULL,
  ADD COLUMN effective_from DATE NULL,
  ADD COLUMN effective_until DATE NULL,
  ADD COLUMN target_model ENUM('legacy','class','course','term','batch')
    NOT NULL DEFAULT 'legacy',
  ADD COLUMN target_scope_kind VARCHAR(10)
    GENERATED ALWAYS AS (
      CASE
        WHEN target_model = 'class' AND section_id IS NOT NULL THEN 'section'
        WHEN target_model = 'class' THEN 'class'
        WHEN target_model = 'course' THEN 'course'
        WHEN target_model = 'term' THEN 'term'
        WHEN target_model = 'batch' THEN 'batch'
        ELSE NULL
      END
    ) STORED,
  ADD COLUMN target_scope_id BIGINT UNSIGNED
    GENERATED ALWAYS AS (
      CASE
        WHEN target_model = 'class' AND section_id IS NOT NULL THEN section_id
        WHEN target_model = 'class' THEN class_id
        WHEN target_model = 'course' THEN course_id
        WHEN target_model = 'term' THEN academic_term_id
        WHEN target_model = 'batch' THEN batch_id
        ELSE NULL
      END
    ) STORED,
  ADD COLUMN active_scope_slot TINYINT UNSIGNED
    GENERATED ALWAYS AS (
      CASE WHEN status = 'active' AND target_model <> 'legacy' THEN 1 ELSE NULL END
    ) STORED,
  ADD KEY idx_fee_structures_class_scope
    (institution_id, academic_year_id, class_id, section_id, status),
  ADD KEY idx_fee_structures_class_offering
    (institution_id, academic_year_id, class_id, year_class_id),
  ADD KEY idx_fee_structures_course_scope
    (institution_id, academic_year_id, course_id, academic_term_id, status),
  ADD KEY idx_fee_structures_course_offering
    (institution_id, academic_year_id, course_id, year_course_id),
  ADD KEY idx_fee_structures_batch_scope
    (institution_id, academic_year_id, batch_id, status),
  ADD KEY idx_fee_structures_batch_course
    (institution_id, course_id, batch_id),
  ADD UNIQUE KEY uq_fee_structures_year_id
    (institution_id, academic_year_id, id),
  ADD UNIQUE KEY uq_fee_structures_active_start
    (institution_id, academic_year_id, target_scope_kind, target_scope_id,
     fee_group_code, effective_from, active_scope_slot),
  ADD CONSTRAINT chk_fee_structure_target_shape CHECK (
    target_model = 'legacy'
    OR (target_model = 'class' AND class_id IS NOT NULL
      AND course_id IS NULL AND academic_term_id IS NULL AND batch_id IS NULL
      AND year_class_id IS NOT NULL AND year_course_id IS NULL
      AND fee_group_code IS NOT NULL AND effective_from IS NOT NULL
      AND (effective_until IS NULL OR effective_until >= effective_from))
    OR (target_model = 'course' AND course_id IS NOT NULL
      AND class_id IS NULL AND section_id IS NULL
      AND academic_term_id IS NULL AND batch_id IS NULL
      AND year_class_id IS NULL AND year_course_id IS NOT NULL
      AND fee_group_code IS NOT NULL AND effective_from IS NOT NULL
      AND (effective_until IS NULL OR effective_until >= effective_from))
    OR (target_model = 'term' AND course_id IS NOT NULL
      AND academic_term_id IS NOT NULL
      AND class_id IS NULL AND section_id IS NULL AND batch_id IS NULL
      AND year_class_id IS NULL AND year_course_id IS NOT NULL
      AND fee_group_code IS NOT NULL AND effective_from IS NOT NULL
      AND (effective_until IS NULL OR effective_until >= effective_from))
    OR (target_model = 'batch' AND batch_id IS NOT NULL
      AND course_id IS NOT NULL AND year_course_id IS NOT NULL
      AND class_id IS NULL AND section_id IS NULL
      AND academic_term_id IS NULL AND year_class_id IS NULL
      AND fee_group_code IS NOT NULL AND effective_from IS NOT NULL
      AND (effective_until IS NULL OR effective_until >= effective_from))
  ),
  ADD CONSTRAINT fk_fee_structure_class_offering
    FOREIGN KEY (institution_id, academic_year_id, class_id, year_class_id)
    REFERENCES academic_year_classes(institution_id, academic_year_id, class_id, id)
    ON DELETE RESTRICT,
  ADD CONSTRAINT fk_fee_structure_course_offering
    FOREIGN KEY (institution_id, academic_year_id, course_id, year_course_id)
    REFERENCES academic_year_courses(institution_id, academic_year_id, course_id, id)
    ON DELETE RESTRICT,
  ADD CONSTRAINT fk_fee_structure_section
    FOREIGN KEY (institution_id, academic_year_id, class_id, section_id)
    REFERENCES sections(institution_id, academic_year_id, class_id, id)
    ON DELETE RESTRICT,
  ADD CONSTRAINT fk_fee_structure_term
    FOREIGN KEY (institution_id, academic_year_id, course_id, academic_term_id)
    REFERENCES academic_terms(institution_id, academic_year_id, course_id, id)
    ON DELETE RESTRICT,
  ADD CONSTRAINT fk_fee_structure_batch_course
    FOREIGN KEY (institution_id, course_id, batch_id)
    REFERENCES batches(institution_id, course_id, id)
    ON DELETE RESTRICT;
```

The nullable offering IDs are required for new typed targets and NULL for
legacy rows. Their four-column FKs prove that class/course and year match the
referenced offering; all existing structure rows can remain legacy with these
new columns NULL. The section and term FKs prove exact year/class/section and
year/course/term matches. The existing tenant FKs on course and class remain.
The `CHECK` has an explicit `legacy` branch so current row shapes are retained;
new target rows cannot mix conflicting dimensions.

The nullable dates/group on legacy rows remain unknown and are not backfilled.
For typed structures, `fee_group_code` identifies an applicable purpose/group
(for example `tuition` or `transport`). Different groups may coexist. A unique
active-start key prevents duplicate active structures with the same exact
scope, group, and start date, but does not by itself prohibit overlapping date
ranges.

Prevent overlap transactionally. A `fee_structure_scope_guards` row is the
serialization lock for `(institution_id, academic_year_id, target_scope_kind,
target_scope_id, fee_group_code)`. Before creating, activating, or changing the
period of a typed structure, insert the guard if absent, select it `FOR UPDATE`,
then reject any other active same-scope/group row whose inclusive date range
overlaps the requested range. Treat NULL `effective_until` as unbounded.
All writers must use this transaction; a unique index cannot express arbitrary
date-range exclusion in MySQL. A class default and a section override are
different exact scopes and may intentionally overlap; assignment applies the
section layer over the class default.

The guard table has tenant/year ownership and one row per exact scope/group:

```sql
CREATE TABLE fee_structure_scope_guards (
  institution_id BIGINT UNSIGNED NOT NULL,
  academic_year_id BIGINT UNSIGNED NOT NULL,
  target_scope_kind VARCHAR(10) NOT NULL,
  target_scope_id BIGINT UNSIGNED NOT NULL,
  fee_group_code VARCHAR(40) NOT NULL,
  PRIMARY KEY
    (institution_id, academic_year_id, target_scope_kind,
     target_scope_id, fee_group_code),
  CONSTRAINT fk_fee_scope_guard_year
    FOREIGN KEY (institution_id, academic_year_id)
    REFERENCES academic_years(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB;
```

Allowed target combinations:

| Target | Required | Must be NULL | Offering / integrity rule |
|---|---|---|---|
| `legacy` | Existing academic year | No new target assumptions | Preserve existing course/class shape and FKs; no automatic conversion |
| `class` | `class_id`, `year_class_id` | course, term, batch | Year-class FK; optional section must match same year/class |
| `course` | `course_id`, `year_course_id` | class, section, term, batch | Year-course FK |
| `term` | `course_id`, `year_course_id`, `academic_term_id` | class, section, batch | Year-course and exact year/course/term FKs |
| `batch` | `batch_id`, `course_id`, `year_course_id` | class, section, term | Batch/course FK plus year-course offering FK |

Model policy determines which targets an institution may create. A batch
target names its course and year-course offering as well as the batch, so the
batch's course must be offered in the structure's year. Enforce model policy
server-side. The class-based target is the school default; section is an
optional, explicit override target.

### 7.2 Components and billing schedule

Existing `fee_structure_items` is the appropriate home for component amount,
frequency, mandatory/optional, status, and stable component identity. It already
stores amount and optionality. Preserve existing `category_id` and its tenant
FK. Proposed additions:

```sql
ALTER TABLE fee_structure_items
  ADD UNIQUE KEY uq_fee_structure_items_institution_id (institution_id, id),
  ADD UNIQUE KEY uq_fee_structure_items_identity
    (institution_id, id, category_id),
  ADD COLUMN frequency ENUM(
    'one_time','monthly','quarterly','half_yearly','yearly','custom'
  ) NULL DEFAULT NULL,
  ADD COLUMN description VARCHAR(500) NULL,
  ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  ADD KEY idx_fee_items_component_status
    (institution_id, fee_structure_id, status),
  ADD KEY idx_fee_items_category_tenant (institution_id, category_id),
  ADD CONSTRAINT chk_fee_item_frequency CHECK (
    frequency IS NULL OR frequency IN
      ('one_time','monthly','quarterly','half_yearly','yearly','custom')
  );
```

`frequency` is nullable with no invented default. Existing rows remain NULL
(unknown/legacy cadence); never backfill them to yearly. New explicitly typed
fee structures require an administrator-selected frequency before assignment.
`custom` requires explicit schedule rows; it does not imply a generated
recurrence rule.
Keep existing `is_optional`; mandatory is its inverse, so do not add a second
flag. The existing unique key `(institution_id,fee_structure_id,category_id)`
defines stable component identity: one fee category may occur once per
structure. Repeating a category at multiple frequencies is not supported in
this design. A future requirement for that needs an approved stable component
key and uniqueness change.

For section overrides, `category_id` is the match key. A matching section item
replaces the class amount/frequency/schedule; missing categories inherit from
the class. This design does not suppress an inherited category; adding an
exclusion rule requires separate approval. These rules apply only to
explicitly typed structures; legacy rows retain their current interpretation.

Use the existing institution's fee categories for Admission, Tuition,
Examination, Computer, Laboratory, Library, Transport, Development, and Other
Charges. Category names are institution data; do not seed duplicates where
equivalent categories already exist. Frequency belongs on the item because it
describes that component's billing cadence. Keep explicit due dates and
multiple billing occurrences in a child schedule table:

```sql
CREATE TABLE fee_component_schedule (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  institution_id BIGINT UNSIGNED NOT NULL,
  fee_structure_item_id BIGINT UNSIGNED NOT NULL,
  installment_number SMALLINT UNSIGNED NOT NULL,
  due_date DATE NOT NULL,
  amount DECIMAL(12,2) NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fee_component_schedule_id (institution_id, id),
  UNIQUE KEY uq_fee_component_schedule_number
    (institution_id, fee_structure_item_id, installment_number),
  KEY idx_fee_component_schedule_due (institution_id, due_date, status),
  CONSTRAINT fk_fee_component_schedule_item
    FOREIGN KEY (institution_id, fee_structure_item_id)
    REFERENCES fee_structure_items(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB;
```

`amount` is nullable when the item amount is divided by a documented schedule
rule. When explicit amounts are provided, validate their sum against the item
amount before activation. A separate schedule table avoids unbounded
date/amount columns or opaque schedule JSON and supports per-date indexing.
One-time charges can have one schedule row; recurring frequencies can have
multiple rows.

### 7.3 Section overrides

Prefer explicit section-specific fee structures, not automatic mutation or
duplication of the class structure. A class-target structure is the default.
A section-target structure is an override layer whose component key is the
fee category/component identity. For each matching component, section values
replace class values; components absent from the section layer inherit the
class amount and schedule. This design does not suppress inherited components.

This layered design reuses `fee_structures` and `fee_structure_items`, and keeps
the section override explicit. Different `fee_group_code` values allow distinct
fee purposes for one academic target. Within each exact target and group, the
guard-row transaction prevents overlapping active inclusive date ranges.
Section and class scopes are separate so a section override may intentionally
overlap the class default.

### 7.4 Assignments, snapshots, and discounts

Add nullable `student_enrolment_id` and a composite FK that enforces the same
institution, student, and academic year. `uq_enrolment_student_year_id` is the
referenced parent candidate key, so the relationship is database-enforced,
not merely a service check. Add a second composite FK from the assignment's
institution/year/structure to ensure its academic year matches the fee
structure. Existing assignments leave only the new enrolment column NULL;
preflight the existing year/structure pairs before adding the second FK.
Because legacy assignments may retain a NULL link, assignment creation for a
configured institution must require a non-NULL enrolment ID in the same
transaction and verify that the enrolment has a fee-eligible status. Whether
`enrolled` as well as `active` is fee-eligible remains an open product decision.
The nullable column is a compatibility allowance for existing rows, not an
optional link for new assignments.
Keep existing payment plan, totals, discounts, paid amounts, and outstanding
amount.

```sql
ALTER TABLE student_fee_assignments
  ADD COLUMN student_enrolment_id BIGINT UNSIGNED NULL,
  ADD KEY idx_student_fee_assignment_structure_year
    (institution_id, academic_year_id, fee_structure_id),
  ADD KEY idx_student_fee_assignment_enrolment
    (institution_id, student_id, academic_year_id, student_enrolment_id),
  ADD CONSTRAINT fk_student_fee_assignment_structure_year
    FOREIGN KEY (institution_id, academic_year_id, fee_structure_id)
    REFERENCES fee_structures(institution_id, academic_year_id, id)
    ON DELETE RESTRICT,
  ADD CONSTRAINT fk_student_fee_assignment_enrolment
    FOREIGN KEY
      (institution_id, student_id, academic_year_id, student_enrolment_id)
    REFERENCES student_enrolments
      (institution_id, student_id, academic_year_id, id)
    ON DELETE RESTRICT;
```

Because `student_enrolment_id` is nullable for legacy assignments, MySQL skips
the composite FK for those rows; the existing required student/year columns
remain intact. A non-NULL enrolment ID requires an exact institution/student/
year match. Do not change the existing assignment unique key until duplicate
data and retry behavior have been audited.

The assignment's component snapshot is a separate immutable child table:

```sql
CREATE TABLE student_fee_assignment_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  institution_id BIGINT UNSIGNED NOT NULL,
  student_fee_assignment_id BIGINT UNSIGNED NOT NULL,
  fee_category_id BIGINT UNSIGNED NOT NULL,
  source_fee_structure_item_id BIGINT UNSIGNED NULL,
  source_section_id BIGINT UNSIGNED NULL,
  component_name VARCHAR(150) NOT NULL,
  component_description VARCHAR(500) NULL,
  frequency ENUM(
    'one_time','monthly','quarterly','half_yearly','yearly','custom'
  ) NULL,
  amount DECIMAL(12,2) NOT NULL,
  is_optional TINYINT(1) NOT NULL DEFAULT 0,
  source_version VARCHAR(40) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_assignment_items_institution_id (institution_id, id),
  UNIQUE KEY uq_assignment_items_category
    (institution_id, student_fee_assignment_id, fee_category_id),
  KEY idx_assignment_items_source_identity
    (institution_id, source_fee_structure_item_id, fee_category_id),
  KEY idx_assignment_items_category (institution_id, fee_category_id),
  KEY idx_assignment_items_section (institution_id, source_section_id),
  KEY idx_assignment_items_assignment
    (institution_id, student_fee_assignment_id),
  CONSTRAINT fk_assignment_items_assignment
    FOREIGN KEY (institution_id, student_fee_assignment_id)
    REFERENCES student_fee_assignments(institution_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_assignment_items_source
    FOREIGN KEY
      (institution_id, source_fee_structure_item_id, fee_category_id)
    REFERENCES fee_structure_items(institution_id, id, category_id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_assignment_items_category
    FOREIGN KEY (institution_id, fee_category_id)
    REFERENCES fee_categories(institution_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_assignment_items_section
    FOREIGN KEY (institution_id, source_section_id)
    REFERENCES sections(institution_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB;
```

`fee_category_id` is the immutable stable component identity copied into the
assignment snapshot; component name, description, amount, optionality, and
frequency are copied values. Historical NULL frequency stays NULL. `custom`
frequency snapshots the explicit schedule rows. `source_section_id` is NULL
for class defaults.
The assignment transaction must also verify that each source item belongs to
the selected class-default structure or the exact section override, and that
the section matches the current enrolment's year/class/section. These
cross-table snapshot checks cannot be expressed by the shown local FKs.
Every composite source/category FK uses `ON DELETE RESTRICT`; never use
`ON DELETE SET NULL` for `(institution_id,nullable_id)` because MySQL applies
SET NULL to every child column in that FK, including the non-null tenant key.
Snapshot fields remain authoritative even if a source structure is later
inactivated.

Snapshot scheduled dates and amounts in a child
`student_fee_assignment_item_schedules` table. Keep it as a fee-definition
snapshot; existing `installments` remain the settlement-facing schedule used
by current payment workflows.

```sql
CREATE TABLE student_fee_assignment_item_schedules (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  institution_id BIGINT UNSIGNED NOT NULL,
  assignment_item_id BIGINT UNSIGNED NOT NULL,
  installment_number SMALLINT UNSIGNED NOT NULL,
  due_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_assignment_item_schedules_id (institution_id, id),
  UNIQUE KEY uq_assignment_item_schedule_number
    (institution_id, assignment_item_id, installment_number),
  KEY idx_assignment_item_schedules_due (institution_id, due_date),
  CONSTRAINT fk_assignment_item_schedules_item
    FOREIGN KEY (institution_id, assignment_item_id)
    REFERENCES student_fee_assignment_items(institution_id, id)
    ON DELETE RESTRICT
) ENGINE=InnoDB;
```

Fee discounts and adjustments remain separate from fee definitions. This
product does not provide a scholarship or concession workflow. Existing
scholarship records are retained in archived tables by the removal migration;
generic fee discounts, adjustments, and all payment or settlement records are
unchanged.

### 7.5 Fee assignment selection

1. Select the student's single `student_enrolments` row for the institution's
   selected active year; require the status approved for fee eligibility.
2. Select active fee structures whose exact target, `fee_group_code`, and
   inclusive effective period match the enrolment and assignment date.
3. For schools, require class scope. Select the class-default structure and
   optionally a section scope for the exact section.
4. Merge section items by component identity, replacing matching class items
   and inheriting the rest. The overlap guard should make same-scope/group
   ambiguity impossible; reject any remaining duplicate as an integrity error,
   never choose one by arbitrary database order.
5. Apply only explicitly authorized generic discounts or adjustments through
   their existing workflows.
6. In one transaction, insert the assignment with immutable totals and
   enrolment reference, component snapshots, and schedule snapshots. Existing
   payment and receipt rows remain connected through existing assignment →
   installment → payment/order/receipt relationships.
7. Editing a fee structure affects only future assignments. Existing
   assignment totals, snapshots, installments, payments, and receipts are
   unchanged.

## 8. Attendance sessions and enrolment scope

Keep the existing `attendance_records` table unchanged as the legacy batch
register. Its `batch_id` is required and its unique key is
`(institution_id,batch_id,student_id,attendance_date)`, so it cannot safely
represent school class/section or college term sessions. Add a new
session-oriented path; do not weaken or repurpose the old key during rollout.

Each `attendance_sessions` row references exactly one `academic_groups` row.
The group itself carries composite FKs to the exact year-class/section,
year-course/term, or course-bound batch. A record includes both the session
group and a placement ID. Composite FKs require the session and placement to
have the same institution, academic year, and group, and require the
placement's student and enrolment to agree.

```sql
CREATE TABLE attendance_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  institution_id BIGINT UNSIGNED NOT NULL,
  academic_year_id BIGINT UNSIGNED NOT NULL,
  academic_group_id BIGINT UNSIGNED NOT NULL,
  session_at DATETIME(6) NOT NULL,
  session_code VARCHAR(50) NOT NULL,
  status ENUM('scheduled','open','closed','cancelled') NOT NULL DEFAULT 'scheduled',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attendance_sessions_id
    (institution_id, academic_year_id, academic_group_id, id),
  UNIQUE KEY uq_attendance_session_code
    (institution_id, academic_year_id, academic_group_id, session_at, session_code),
  KEY idx_attendance_sessions_group_time
    (institution_id, academic_year_id, academic_group_id, session_at, status),
  CONSTRAINT fk_attendance_session_group
    FOREIGN KEY (institution_id, academic_year_id, academic_group_id)
    REFERENCES academic_groups(institution_id, academic_year_id, id)
    ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE attendance_session_records (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  institution_id BIGINT UNSIGNED NOT NULL,
  academic_year_id BIGINT UNSIGNED NOT NULL,
  academic_group_id BIGINT UNSIGNED NOT NULL,
  attendance_session_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  student_enrolment_id BIGINT UNSIGNED NOT NULL,
  placement_id BIGINT UNSIGNED NOT NULL,
  status ENUM('present','absent','late','excused') NOT NULL,
  recorded_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attendance_session_records_id (institution_id, id),
  UNIQUE KEY uq_attendance_session_student
    (institution_id, attendance_session_id, student_id),
  KEY idx_attendance_session_record_placement
    (institution_id, academic_year_id, academic_group_id,
     student_enrolment_id, placement_id),
  CONSTRAINT fk_attendance_record_session
    FOREIGN KEY
      (institution_id, academic_year_id, academic_group_id, attendance_session_id)
    REFERENCES attendance_sessions
      (institution_id, academic_year_id, academic_group_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_attendance_record_placement
    FOREIGN KEY
      (institution_id, academic_year_id, academic_group_id, student_id,
       student_enrolment_id, placement_id)
    REFERENCES student_enrolment_placements
      (institution_id, academic_year_id, academic_group_id, student_id,
       student_enrolment_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_attendance_record_actor
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
```

The attendance-to-session FK enforces the exact session/group/year/tenant.
The attendance-to-placement FK enforces the exact placement/group/year/
tenant/student/enrolment. These are separate constraints, so their shared
non-null group/year/tenant columns prove the full relationship; no unrelated
or omitted nullable dimensions are assumed to be checked.

For configured schools, enrolments use section groups, which include the
parent class. A school attendance session therefore uses the enrolled section
group and cannot be recorded against a different section. A whole-class
session spanning multiple sections is not represented by one session under
these exact-scope FKs; supporting that workflow would require an approved
scope-extension decision. College/university sessions use course/term groups,
and coaching sessions use batch groups.

At record time, the transaction must also select the latest placement for the
enrolment whose `effective_at <= attendance_sessions.session_at` and require
that it is the referenced placement. This date-order check spans session and
placement rows and cannot be expressed as a MySQL FK or CHECK. Placement and
attendance services must reject retroactive changes that would invalidate
closed session records, except through an audited correction workflow.

School sessions use section groups that identify both class and section and
never require a batch. College and university sessions use course or term
groups. Coaching sessions use batch groups. Existing batch attendance remains
readable during rollout; only unambiguous rows may be backfilled into session
records. Never delete or rewrite the legacy attendance rows.

## 9. Example rows

### School

```text
institution: type=school, academic_model=school
academic_year: 2026-2027
class catalog: Class 5 (display_order=5)
academic_year_classes: 2026-2027 / Class 5
sections: Class 5-A, Class 5-B, Class 5-C in that offering
subjects: English, Bengali, Mathematics, Science
class_subjects: Class 5 / 2026-2027 / those four subjects
section_subjects: optional Class 5-B exclusion or additional approved subject
student_enrolment: student 501 / 2026-2027 / Class 5 / Section B / roll 18
```

### College

```text
institution: type=college, academic_model=college
academic_year: 2026-2027
course catalog: BCA (reused courses row)
academic_year_courses: BCA offered in 2026-2027
academic_terms: Semester 1, sequence 1, term_type=semester
subjects/course_subjects: Programming, Mathematics, Communication
student_enrolment: student 702 / 2026-2027 / BCA / Semester 1
```

### University

```text
institution: type=university, academic_model=university
department: Computer Science
course catalog: B.Tech Computer Science (department_id = Computer Science)
academic_year_courses: programme offered in 2026-2027
academic_terms: Semester 1
student_enrolment: student 901 / 2026-2027 / B.Tech CS / Semester 1
```

### Coaching centre

```text
institution: type=coaching_institute, academic_model=coaching
course catalog: Python Programming
academic_year_courses: Python course offered in 2026-2027 (optional)
batch: Python Evening, linked to the course, class_id NULL where approved
student_enrolment: student 1201 / 2026-2027 / Python course / Evening batch
attendance: existing batch-based register remains usable
```

### Fee example: school Class 5 with Section B transport override

```text
class default (Class 5, 2026-2027):
  tuition   1500.00 monthly
  computer   500.00 quarterly
  transport  800.00 monthly
section override (Class 5-B, 2026-2027):
  transport 1000.00 monthly
student assignment snapshot:
  tuition 1500.00, computer 500.00, transport 1000.00, each with its schedule
```

Class 5-A and 5-C inherit transport 800.00. The override does not alter any
existing assignment, payment, receipt, or class-default row.

## 10. Tenant integrity rules

Every new tenant table uses `institution_id` and one of:

- Direct FK to `institutions(id)` where the row is a root/catalog entity.
- Composite FK `(institution_id,parent_id)` to a parent's existing
  `(institution_id,id)` unique key.
- Composite FK with academic year and parent IDs where matching-year
  ownership matters.

Use `ON DELETE RESTRICT` for historical academic entities referenced by
enrolments, fee structures, assignments, schedules, and attendance. Use
`CASCADE` only for unreferenced catalog child rows where deleting the parent is
an explicitly approved lifecycle operation. Inactive/closed status is the
normal retention mechanism.

Preserve all constraints and preflight discipline from the tenant identity
migrations in `database/migrations/20261103_enforce_tenant_identity_integrity.sql`
and `20261103_enable_parent_affiliation_transfer.sql`. They concern user/role
and parent ownership; the academic design neither changes nor relaxes them.

## 11. Existing tables reused or changed

| Existing table | Design treatment |
|---|---|
| `institutions` | Reuse `institution_type`; add nullable `academic_model`, active-year pointer, configuration status |
| `academic_years` | Reuse as-is; all new year references use composite tenant FK |
| `classes` | Reuse as class catalog; add display order |
| `courses` | Reuse for course/programme; add optional department reference |
| `batches` | Reuse for coaching; audit and strengthen tenant FKs; allow optional class only after compatibility review |
| `students` | Keep all current fields during transition; current placement becomes a synchronized projection |
| `fee_categories` | Reuse as component catalog |
| `fee_structures` | Add target scope and section/term/batch references; retain legacy columns and rows |
| `fee_structure_items` | Add frequency/status/schedule relationship; preserve amount/category/optionality |
| `student_fee_assignments` | Add optional enrolment FK; preserve financial totals and existing uniqueness; preflight year/structure pairs |
| `installments`, `payments`, `payment_orders`, `payment_attempts`, `refunds`, `receipts`, `payment_webhook_events` | No redesign; preserve all IDs and FKs |
| `attendance_records` | Keep unchanged as the legacy batch register; do not make it represent non-batch groups |

New tables proposed: `academic_year_classes`, `departments`,
`academic_year_courses`, `academic_terms`, `sections`, `academic_groups`,
`subjects`, `class_subjects`, `section_subjects`, optional `course_subjects`,
`student_enrolments`, `student_enrolment_placements`,
`student_enrolment_status_events`, `fee_structure_scope_guards`,
`fee_component_schedule`, `student_fee_assignment_items`,
`student_fee_assignment_item_schedules`, `attendance_sessions`, and
`attendance_session_records`.

## 12. Decision register

### Approved product decisions

- One enrolment per institution/student/academic year, enforced by the
  non-generated unique key `(institution_id, academic_year_id, student_id)`.
  It applies regardless of enrolment status; no second enrolment is permitted
  after withdrawal or cancellation.
- Re-admission in the same year reactivates the existing enrolment and logs a
  status event. Promotion creates a new enrolment in the next year.
- Placement changes are recorded with old/new placement identity, effective
  timestamp, reason, and actor; the student/year enrolment remains the same.
- School class and section management does not require courses or batches.
- Class and section fee scopes may have distinct override layers. Multiple
  fee-purpose groups are supported, and overlapping active periods are
  prohibited for the same exact scope and fee group.
- Historical unknown frequency remains NULL/unknown. Fee descriptions,
  frequencies, schedules, and amounts are snapshotted for each new assignment.
- Attendance is session- and enrolment-based for all academic models; legacy
  batch attendance remains intact during migration.

### Technical implementation decisions

- A normalized year-owned `academic_groups` row is the scope identity for
  class, section, course, term, and batch placements and attendance sessions.
- Placement changes are append-only placement rows linked to their predecessor;
  status changes have a separate append-only event table.
- Date-range exclusion for active fee scopes uses a stable guard row locked
  within a transaction; MySQL unique indexes cannot exclude arbitrary interval
  overlaps. Different fee groups may overlap intentionally.
- New session attendance uses separate session/record tables. Composite FKs
  tie each record to a session and a placement with matching institution,
  academic year, group, student, and enrolment.
- Existing assignment and legacy attendance rows are not rewritten to satisfy
  new keys. Preflight results precede any FK addition.

### Remaining genuine open questions

1. Whether an institution may change its academic model after configuration,
   and what conversion workflow is required.
2. Whether section subject assignments supplement/exclude class subjects or
   replace the class subject set.
3. Whether admission numbers remain `students.student_code` or may be
   year-specific, and the scope for roll-number uniqueness.
4. Whether selecting a new active year should close the previously selected
   year automatically; the current design changes only the pointer.
5. How custom fee schedules map to generated installments and payment-plan
   behavior. Custom schedule dates and amounts themselves are explicit.
6. Whether a school needs one whole-class attendance session spanning multiple
   sections, rather than section-scoped sessions.
8. Whether section fee overrides may suppress an inherited fee component.
9. Whether `enrolled`, `active`, or both enrolment statuses are eligible for
   fee assignment.
