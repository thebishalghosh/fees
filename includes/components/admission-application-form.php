<?php
declare(strict_types=1);

function render_admission_application_form(
    array $application,
    array $options,
    array $groups,
    bool $editing,
    bool $preserveStatus = false
): void {
    $values = $application;
    $studentName = trim((string) ($application['student_name'] ?? ''));
    $nameParts = $studentName === '' ? [] : preg_split('/\s+/', $studentName);
    $defaults = [
        'applicant_name' => '',
        'applicant_mobile' => '',
        'applicant_email' => '',
        'relationship_to_student' => '',
        'first_name' => $nameParts[0] ?? '',
        'middle_name' => '',
        'last_name' => count($nameParts ?? []) > 1 ? implode(' ', array_slice($nameParts, 1)) : '',
        'date_of_birth' => '',
        'admission_date' => '',
        'gender' => '',
        'student_mobile' => '',
        'student_email' => '',
        'address_line1' => '',
        'address_line2' => '',
        'city' => '',
        'state' => '',
        'postal_code' => '',
        'country' => 'India',
        'previous_school' => '',
        'admission_category' => '',
        'father_name' => '',
        'father_mobile' => '',
        'father_email' => '',
        'mother_name' => '',
        'mother_mobile' => '',
        'mother_email' => '',
        'guardian_name' => '',
        'guardian_mobile' => '',
        'guardian_email' => '',
        'enquiry_source' => '',
        'academic_year_id' => '',
        'course_id' => '',
        'class_id' => '',
        'batch_id' => '',
        'requested_academic_group_id' => '',
        'submission_notes' => '',
    ];
    $values = array_replace($defaults, $values);
    ?>
    <form method="post" action="<?php echo e(route_url('/admin/admissions/store')); ?>" class="row g-4">
        <?php echo csrf_field(); ?>
        <?php if ($editing): ?>
            <input type="hidden" name="admission_id" value="<?php echo e((string) $application['id']); ?>">
        <?php endif; ?>
        <section class="col-12">
            <h2 class="h5">Applicant and contact</h2>
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label" for="applicant-name">Applicant / parent name *</label><input class="form-control" id="applicant-name" name="applicant_name" maxlength="150" required value="<?php echo e($values['applicant_name']); ?>"></div>
                <div class="col-md-4"><label class="form-label" for="applicant-mobile">Applicant mobile *</label><input class="form-control" id="applicant-mobile" name="applicant_mobile" type="tel" maxlength="20" required value="<?php echo e($values['applicant_mobile']); ?>"></div>
                <div class="col-md-4"><label class="form-label" for="applicant-email">Applicant email</label><input class="form-control" id="applicant-email" name="applicant_email" type="email" maxlength="255" value="<?php echo e($values['applicant_email']); ?>"></div>
                <div class="col-md-4"><label class="form-label" for="relationship">Relationship to student</label><select class="form-select" id="relationship" name="relationship_to_student"><option value="">Select</option><?php foreach (['father','mother','guardian','other'] as $relationship): ?><option value="<?php echo e($relationship); ?>"<?php echo $values['relationship_to_student'] === $relationship ? ' selected' : ''; ?>><?php echo e(ucfirst($relationship)); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4"><label class="form-label" for="enquiry-source">Enquiry source</label><input class="form-control" id="enquiry-source" name="enquiry_source" maxlength="80" value="<?php echo e($values['enquiry_source']); ?>"></div>
            </div>
        </section>
        <section class="col-12">
            <h2 class="h5">Student information</h2>
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label" for="first-name">First name *</label><input class="form-control" id="first-name" name="first_name" maxlength="80" required value="<?php echo e($values['first_name']); ?>"></div>
                <div class="col-md-4"><label class="form-label" for="middle-name">Middle name</label><input class="form-control" id="middle-name" name="middle_name" maxlength="80" value="<?php echo e($values['middle_name']); ?>"></div>
                <div class="col-md-4"><label class="form-label" for="last-name">Last name</label><input class="form-control" id="last-name" name="last_name" maxlength="80" value="<?php echo e($values['last_name']); ?>"></div>
                <div class="col-md-4"><label class="form-label" for="dob">Date of birth</label><input class="form-control" id="dob" name="date_of_birth" type="date" max="<?php echo e(date('Y-m-d')); ?>" value="<?php echo e($values['date_of_birth']); ?>"></div>
                <div class="col-md-4"><label class="form-label" for="admission-date">Planned admission date</label><input class="form-control" id="admission-date" name="admission_date" type="date" value="<?php echo e($values['admission_date']); ?>"></div>
                <div class="col-md-4"><label class="form-label" for="gender">Gender</label><select class="form-select" id="gender" name="gender"><option value="">Select</option><?php foreach (STUDENT_MANAGEMENT_GENDERS as $gender): ?><option value="<?php echo e($gender); ?>"<?php echo $values['gender'] === $gender ? ' selected' : ''; ?>><?php echo e(ucwords(str_replace('_', ' ', $gender))); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4"><label class="form-label" for="student-mobile">Student mobile</label><input class="form-control" id="student-mobile" name="student_mobile" type="tel" maxlength="20" value="<?php echo e($values['student_mobile']); ?>"></div>
                <div class="col-md-4"><label class="form-label" for="student-email">Student email</label><input class="form-control" id="student-email" name="student_email" type="email" maxlength="255" value="<?php echo e($values['student_email']); ?>"></div>
                <div class="col-md-4"><label class="form-label" for="previous-school">Previous school / institution</label><input class="form-control" id="previous-school" name="previous_school" maxlength="200" value="<?php echo e($values['previous_school']); ?>"></div>
                <div class="col-md-4"><label class="form-label" for="admission-category">Admission category</label><input class="form-control" id="admission-category" name="admission_category" maxlength="100" value="<?php echo e($values['admission_category']); ?>"></div>
            </div>
        </section>
        <section class="col-12">
            <h2 class="h5">Address</h2>
            <div class="row g-3">
                <?php foreach (['address_line1' => 'Address line 1', 'address_line2' => 'Address line 2', 'city' => 'City', 'state' => 'State / province', 'postal_code' => 'Postal code', 'country' => 'Country'] as $field => $label): ?>
                    <div class="col-md-4"><label class="form-label" for="<?php echo e($field); ?>"><?php echo e($label); ?></label><input class="form-control" id="<?php echo e($field); ?>" name="<?php echo e($field); ?>" maxlength="<?php echo $field === 'address_line1' || $field === 'address_line2' ? '255' : ($field === 'postal_code' ? '20' : '120'); ?>" value="<?php echo e($values[$field]); ?>"></div>
                <?php endforeach; ?>
            </div>
        </section>
        <section class="col-12">
            <h2 class="h5">Parent and guardian details</h2>
            <div class="row g-3">
                <?php foreach (['father' => 'Father', 'mother' => 'Mother', 'guardian' => 'Guardian'] as $prefix => $label): ?>
                    <div class="col-md-4"><label class="form-label" for="<?php echo e($prefix); ?>-name"><?php echo e($label); ?> name</label><input class="form-control" id="<?php echo e($prefix); ?>-name" name="<?php echo e($prefix); ?>_name" maxlength="150" value="<?php echo e($values[$prefix . '_name']); ?>"></div>
                    <div class="col-md-4"><label class="form-label" for="<?php echo e($prefix); ?>-mobile"><?php echo e($label); ?> mobile</label><input class="form-control" id="<?php echo e($prefix); ?>-mobile" name="<?php echo e($prefix); ?>_mobile" type="tel" maxlength="20" value="<?php echo e($values[$prefix . '_mobile']); ?>"></div>
                    <div class="col-md-4"><label class="form-label" for="<?php echo e($prefix); ?>-email"><?php echo e($label); ?> email</label><input class="form-control" id="<?php echo e($prefix); ?>-email" name="<?php echo e($prefix); ?>_email" type="email" maxlength="255" value="<?php echo e($values[$prefix . '_email']); ?>"></div>
                <?php endforeach; ?>
            </div>
        </section>
        <section class="col-12">
            <h2 class="h5">Academic selection</h2>
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label" for="academic-year">Academic year</label><select class="form-select" id="academic-year" name="academic_year_id"><option value="">Select year</option><?php foreach ($options['academic_years'] as $year): ?><option value="<?php echo e((string) $year['id']); ?>"<?php echo (string) $values['academic_year_id'] === (string) $year['id'] ? ' selected' : ''; ?>><?php echo e($year['name'] . ' · ' . ucfirst($year['status'])); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4"><label class="form-label" for="course">Course / programme</label><select class="form-select" id="course" name="course_id"><option value="">Select course</option><?php foreach ($options['courses'] as $course): ?><option value="<?php echo e((string) $course['id']); ?>"<?php echo (string) $values['course_id'] === (string) $course['id'] ? ' selected' : ''; ?>><?php echo e($course['name']); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4"><label class="form-label" for="class">Class</label><select class="form-select" id="class" name="class_id"><option value="">Select class</option><?php foreach ($options['classes'] as $class): ?><option value="<?php echo e((string) $class['id']); ?>"<?php echo (string) $values['class_id'] === (string) $class['id'] ? ' selected' : ''; ?>><?php echo e($class['name']); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4"><label class="form-label" for="batch">Batch</label><select class="form-select" id="batch" name="batch_id"><option value="">Select batch</option><?php foreach ($options['batches'] as $batch): ?><option value="<?php echo e((string) $batch['id']); ?>"<?php echo (string) $values['batch_id'] === (string) $batch['id'] ? ' selected' : ''; ?>><?php echo e($batch['name']); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-8"><label class="form-label" for="requested-group">Preferred section / placement</label><select class="form-select" id="requested-group" name="requested_academic_group_id"><option value="">Select placement (optional)</option><?php foreach ($groups as $group): ?><option value="<?php echo e((string) $group['id']); ?>" data-year="<?php echo e((string) $group['academic_year_id']); ?>"<?php echo (string) $values['requested_academic_group_id'] === (string) $group['id'] ? ' selected' : ''; ?>><?php echo e($group['label']); ?></option><?php endforeach; ?></select></div>
                <div class="col-12"><label class="form-label" for="submission-notes">Application notes</label><textarea class="form-control" id="submission-notes" name="submission_notes" rows="3" maxlength="20000"><?php echo e($values['submission_notes']); ?></textarea></div>
            </div>
        </section>
        <div class="col-12 d-flex flex-wrap gap-2">
            <?php if ($preserveStatus): ?>
                <button class="btn btn-primary" type="submit" name="action" value="save_changes">Save changes</button>
                <span class="align-self-center text-muted">The application status will remain unchanged.</span>
            <?php else: ?>
                <button class="btn btn-outline-secondary" type="submit" name="action" value="save_draft">Save draft</button>
                <button class="btn btn-primary" type="submit" name="action" value="submit_application" onclick="return confirm('Submit this application for review? You will not be able to edit it unless more information is requested.')">Submit application</button>
            <?php endif; ?>
            <a class="btn btn-link" href="<?php echo e(route_url('/admin/admissions/index')); ?>">Cancel</a>
        </div>
    </form>
    <script>
        (() => {
            const year = document.getElementById('academic-year');
            const group = document.getElementById('requested-group');
            const syncGroups = () => {
                for (const option of group.options) {
                    if (!option.value) continue;
                    option.hidden = option.dataset.year !== year.value;
                    if (option.hidden && option.selected) group.value = '';
                }
            };
            year.addEventListener('change', syncGroups);
            syncGroups();
        })();
    </script>
    <?php
}
