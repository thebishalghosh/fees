<?php
declare(strict_types=1);

function render_student_profile_form(
    string $action,
    array $student,
    array $options,
    array $old,
    bool $editing
): void {
    $value = static function (string $field, mixed $default = '') use ($student, $old): string {
        $selected = array_key_exists($field, $old) ? $old[$field] : ($student[$field] ?? $default);
        return is_string($selected) || is_int($selected) ? (string) $selected : '';
    };
    $selectedParentIds = $old['parent_ids'] ?? ($student['parent_ids'] ?? []);
    if (!is_array($selectedParentIds)) {
        $selectedParentIds = [];
    }
    $selectedParentIds = array_map('strval', $selectedParentIds);
    $fieldErrors = is_array($old['field_errors'] ?? null) ? $old['field_errors'] : [];
    $errorForField = static fn (string $field): ?string =>
        is_string($fieldErrors[$field] ?? null) ? $fieldErrors[$field] : null;
    ?>
    <form action="<?php echo e(route_url($action)); ?>" method="post" class="students-profile-form">
        <?php echo csrf_field(); ?>
        <?php if ($editing): ?>
            <input type="hidden" name="manage_parent_links" value="1">
        <?php endif; ?>

        <section class="students-form-section" aria-labelledby="student-basic-details">
            <div class="students-form-section-heading">
                <h3 id="student-basic-details">Student details</h3>
                <p>Only fields supported by the current database schema are shown.</p>
            </div>
            <div class="students-form-grid">
                <label class="students-form-field">
                    <span>Full name <b aria-hidden="true">*</b></span>
                    <input class="form-control" type="text" name="full_name" maxlength="150" required value="<?php echo e($value('full_name')); ?>" autocomplete="name"<?php echo $errorForField('full_name') !== null ? ' aria-invalid="true" aria-describedby="student-full-name-error"' : ''; ?>>
                    <?php if ($errorForField('full_name') !== null): ?><small class="students-field-error" id="student-full-name-error" role="alert"><?php echo e($errorForField('full_name')); ?></small><?php endif; ?>
                </label>
                <label class="students-form-field">
                    <span>Student ID / admission number <b aria-hidden="true">*</b></span>
                    <input class="form-control" type="text" name="student_code" maxlength="50" required value="<?php echo e($value('student_code')); ?>"<?php echo $errorForField('student_code') !== null ? ' aria-invalid="true" aria-describedby="student-code-error"' : ''; ?>>
                    <small>Unique within this institution.</small>
                    <?php if ($errorForField('student_code') !== null): ?><small class="students-field-error" id="student-code-error" role="alert"><?php echo e($errorForField('student_code')); ?></small><?php endif; ?>
                </label>
                <label class="students-form-field">
                    <span>Date of birth</span>
                    <input class="form-control" type="date" name="date_of_birth" value="<?php echo e($value('date_of_birth')); ?>"<?php echo $errorForField('date_of_birth') !== null ? ' aria-invalid="true" aria-describedby="date-of-birth-error"' : ''; ?>>
                    <?php if ($errorForField('date_of_birth') !== null): ?><small class="students-field-error" id="date-of-birth-error" role="alert"><?php echo e($errorForField('date_of_birth')); ?></small><?php endif; ?>
                </label>
                <label class="students-form-field">
                    <span>Gender</span>
                    <select class="form-select" name="gender"<?php echo $errorForField('gender') !== null ? ' aria-invalid="true" aria-describedby="student-gender-error"' : ''; ?>>
                        <option value="">Not specified</option>
                        <?php foreach (['male' => 'Male', 'female' => 'Female', 'other' => 'Other', 'prefer_not_to_say' => 'Prefer not to say'] as $genderValue => $genderLabel): ?>
                            <option value="<?php echo e($genderValue); ?>"<?php echo $value('gender') === $genderValue ? ' selected' : ''; ?>><?php echo e($genderLabel); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($errorForField('gender') !== null): ?><small class="students-field-error" id="student-gender-error" role="alert"><?php echo e($errorForField('gender')); ?></small><?php endif; ?>
                </label>
                <label class="students-form-field">
                    <span>Admission date <b aria-hidden="true">*</b></span>
                    <input class="form-control" type="date" name="admission_date" required value="<?php echo e($value('admission_date', date('Y-m-d'))); ?>"<?php echo $errorForField('admission_date') !== null ? ' aria-invalid="true" aria-describedby="admission-date-error"' : ''; ?>>
                    <?php if ($errorForField('admission_date') !== null): ?><small class="students-field-error" id="admission-date-error" role="alert"><?php echo e($errorForField('admission_date')); ?></small><?php endif; ?>
                </label>
                <?php if (!$editing): ?>
                    <label class="students-form-field">
                        <span>Initial status</span>
                        <select class="form-select" name="status"<?php echo $errorForField('status') !== null ? ' aria-invalid="true" aria-describedby="student-status-error"' : ''; ?>>
                            <?php foreach (['prospect' => 'Prospect', 'admitted' => 'Admitted', 'active' => 'Active'] as $statusValue => $statusLabel): ?>
                                <option value="<?php echo e($statusValue); ?>"<?php echo $value('status', 'prospect') === $statusValue ? ' selected' : ''; ?>><?php echo e($statusLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($errorForField('status') !== null): ?><small class="students-field-error" id="student-status-error" role="alert"><?php echo e($errorForField('status')); ?></small><?php endif; ?>
                    </label>
                <?php else: ?>
                    <div class="students-form-field students-current-status">
                        <span>Current status</span>
                        <strong><?php echo e(ucfirst((string) ($student['status'] ?? ''))); ?></strong>
                        <small>Use the status action on the student page to activate or deactivate.</small>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="students-form-section" aria-labelledby="student-academic-details">
            <div class="students-form-section-heading">
                <h3 id="student-academic-details">Academic placement</h3>
                <p>This schema provides courses, classes and batches; it has no separate sections table.</p>
            </div>
            <div class="students-form-grid">
                <label class="students-form-field">
                    <span>Academic year</span>
                    <select class="form-select" name="academic_year_id"<?php echo $errorForField('academic_year_id') !== null ? ' aria-invalid="true" aria-describedby="academic-year-error"' : ''; ?>>
                        <option value="">Not assigned</option>
                        <?php foreach ($options['academic_years'] as $year): ?>
                            <option value="<?php echo e((string) $year['id']); ?>"<?php echo $value('academic_year_id') === (string) $year['id'] ? ' selected' : ''; ?>>
                                <?php echo e($year['name'] . ($year['status'] === 'active' ? ' (Active)' : '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($errorForField('academic_year_id') !== null): ?><small class="students-field-error" id="academic-year-error" role="alert"><?php echo e($errorForField('academic_year_id')); ?></small><?php endif; ?>
                </label>
                <label class="students-form-field">
                    <span>Course</span>
                    <select class="form-select" name="course_id"<?php echo $errorForField('course_id') !== null ? ' aria-invalid="true" aria-describedby="course-error"' : ''; ?>>
                        <option value="">Not assigned</option>
                        <?php foreach ($options['courses'] as $course): ?>
                            <option value="<?php echo e((string) $course['id']); ?>"<?php echo $value('course_id') === (string) $course['id'] ? ' selected' : ''; ?>>
                                <?php echo e($course['name'] . ($course['status'] === 'active' ? '' : ' (Inactive)')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($errorForField('course_id') !== null): ?><small class="students-field-error" id="course-error" role="alert"><?php echo e($errorForField('course_id')); ?></small><?php endif; ?>
                </label>
                <label class="students-form-field">
                    <span>Class</span>
                    <select class="form-select" name="class_id"<?php echo $errorForField('class_id') !== null ? ' aria-invalid="true" aria-describedby="class-error"' : ''; ?>>
                        <option value="">Not assigned</option>
                        <?php foreach ($options['classes'] as $class): ?>
                            <option value="<?php echo e((string) $class['id']); ?>"<?php echo $value('class_id') === (string) $class['id'] ? ' selected' : ''; ?>>
                                <?php echo e($class['name'] . ($class['status'] === 'active' ? '' : ' (Inactive)')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($errorForField('class_id') !== null): ?><small class="students-field-error" id="class-error" role="alert"><?php echo e($errorForField('class_id')); ?></small><?php endif; ?>
                </label>
                <label class="students-form-field">
                    <span>Batch</span>
                    <select class="form-select" name="batch_id"<?php echo $errorForField('batch_id') !== null ? ' aria-invalid="true" aria-describedby="batch-error"' : ''; ?>>
                        <option value="">Not assigned</option>
                        <?php foreach ($options['batches'] as $batch): ?>
                            <option value="<?php echo e((string) $batch['id']); ?>"<?php echo $value('batch_id') === (string) $batch['id'] ? ' selected' : ''; ?>>
                                <?php echo e($batch['name'] . ' — ' . $batch['course_name'] . ' / ' . $batch['class_name'] . ($batch['status'] === 'active' ? '' : ' (Not active)')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($errorForField('batch_id') !== null): ?><small class="students-field-error" id="batch-error" role="alert"><?php echo e($errorForField('batch_id')); ?></small><?php endif; ?>
                    <small>A selected batch must match the course and class above.</small>
                </label>
            </div>
        </section>

        <section class="students-form-section" aria-labelledby="student-parents-heading">
            <div class="students-form-section-heading">
                <h3 id="student-parents-heading">Parents and guardians</h3>
                <p>Select only the existing parent or guardian profile for this student. Their saved contact details and relationship are shown on the registration form PDF; existing link history is retained when unlinked.</p>
            </div>
            <label class="students-form-field students-parent-picker">
                <span>Linked parents or guardians</span>
                <select class="form-select" name="parent_ids[]" multiple size="6" aria-describedby="student-parent-help<?php echo $errorForField('parent_ids') !== null ? ' student-parent-error' : ''; ?>"<?php echo $errorForField('parent_ids') !== null ? ' aria-invalid="true"' : ''; ?>>
                    <?php foreach ($options['parents'] as $parent): ?>
                        <option value="<?php echo e((string) $parent['id']); ?>"<?php echo in_array((string) $parent['id'], $selectedParentIds, true) ? ' selected' : ''; ?>>
                            <?php echo e($parent['full_name'] . ' — ' . $parent['mobile']
                                . ($parent['email'] !== null ? ' — ' . $parent['email'] : '')
                                . ($parent['relationship_to_student'] !== null ? ' — ' . ucfirst((string) $parent['relationship_to_student']) : '')
                                . ($parent['status'] === 'active' ? '' : ' (' . ucwords(str_replace('_', ' ', (string) $parent['status'])) . ')')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small id="student-parent-help">Use Ctrl (Windows) or Command (Mac) to select multiple parent profiles. Create the parent's profile in Parents before linking it here. No account or relationship is created from a phone number.</small>
                <?php if ($errorForField('parent_ids') !== null): ?><small class="students-field-error" id="student-parent-error" role="alert"><?php echo e($errorForField('parent_ids')); ?></small><?php endif; ?>
            </label>
            <?php if ($options['parents'] === []): ?>
                <p class="students-no-options">No eligible parent profiles are currently available in this institution.</p>
            <?php endif; ?>
        </section>

        <div class="students-form-actions">
            <button class="students-primary-button" type="submit"><i class="bi bi-check2" aria-hidden="true"></i><?php echo $editing ? 'Save Student' : 'Create Student'; ?></button>
            <a class="students-secondary-button" href="<?php echo e(route_url($editing ? '/admin/students/view?id=' . (int) $student['id'] : '/admin/students/index')); ?>">Cancel</a>
        </div>
    </form>
    <?php
}
