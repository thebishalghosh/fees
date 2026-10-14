<?php
declare(strict_types=1);

function render_parent_profile_form(
    string $action,
    array $parent,
    array $students,
    array $old,
    bool $isEdit
): void {
    $values = [
        'full_name' => $old['full_name'] ?? $parent['full_name'] ?? '',
        'email' => $old['email'] ?? $parent['email'] ?? '',
        'mobile' => $old['mobile'] ?? $parent['mobile'] ?? '',
        'alternate_mobile' => $old['alternate_mobile'] ?? $parent['alternate_mobile'] ?? '',
        'relationship_to_student' => $old['relationship_to_student']
            ?? $parent['relationship_to_student'] ?? 'guardian',
        'address_line1' => $old['address_line1'] ?? $parent['address_line1'] ?? '',
        'address_line2' => $old['address_line2'] ?? $parent['address_line2'] ?? '',
        'city' => $old['city'] ?? $parent['city'] ?? '',
        'state' => $old['state'] ?? $parent['state'] ?? '',
        'postal_code' => $old['postal_code'] ?? $parent['postal_code'] ?? '',
        'country' => $old['country'] ?? $parent['country'] ?? 'India',
        'emergency_contact' => $old['emergency_contact'] ?? $parent['emergency_contact'] ?? '',
        'initial_status' => $old['initial_status'] ?? 'pending_verification',
    ];
    $selectedIds = isset($old['student_ids']) && is_array($old['student_ids'])
        ? array_map('intval', $old['student_ids'])
        : ($parent['linked_student_ids'] ?? []);
    ?>
    <form action="<?php echo e(route_url($action)); ?>" method="post" class="parents-profile-form">
        <?php echo csrf_field(); ?>
        <?php if ($isEdit): ?>
            <input type="hidden" name="manage_links" value="1">
        <?php endif; ?>
        <div class="parents-form-grid">
            <div>
                <label class="form-label" for="parent-full-name">Full name <span aria-hidden="true">*</span></label>
                <input class="form-control" id="parent-full-name" name="full_name" type="text" maxlength="150" required autocomplete="name" value="<?php echo e($values['full_name']); ?>">
            </div>
            <div>
                <label class="form-label" for="parent-email">Email address <span aria-hidden="true">*</span></label>
                <input class="form-control" id="parent-email" name="email" type="email" maxlength="255" required autocomplete="email" value="<?php echo e($values['email']); ?>">
                <small class="parents-field-help">Email verification is not sent or marked complete by this form.</small>
            </div>
            <div>
                <label class="form-label" for="parent-mobile">Mobile number <span aria-hidden="true">*</span></label>
                <input class="form-control" id="parent-mobile" name="mobile" type="tel" maxlength="25" required autocomplete="tel" value="<?php echo e($values['mobile']); ?>">
            </div>
            <div>
                <label class="form-label" for="parent-alternate-mobile">Alternate mobile</label>
                <input class="form-control" id="parent-alternate-mobile" name="alternate_mobile" type="tel" maxlength="25" autocomplete="tel" value="<?php echo e($values['alternate_mobile']); ?>">
            </div>
            <div>
                <label class="form-label" for="parent-relationship">Relationship to student <span aria-hidden="true">*</span></label>
                <select class="form-select" id="parent-relationship" name="relationship_to_student" required>
                    <option value="">Choose relationship</option>
                    <?php foreach (['father' => 'Father', 'mother' => 'Mother', 'guardian' => 'Guardian', 'other' => 'Other'] as $value => $label): ?>
                        <option value="<?php echo e($value); ?>"<?php echo $values['relationship_to_student'] === $value ? ' selected' : ''; ?>><?php echo e($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label" for="parent-country">Country <span aria-hidden="true">*</span></label>
                <input class="form-control" id="parent-country" name="country" type="text" maxlength="120" required value="<?php echo e($values['country']); ?>">
            </div>
            <div>
                <label class="form-label" for="parent-address-line1">Address line 1</label>
                <input class="form-control" id="parent-address-line1" name="address_line1" type="text" maxlength="255" autocomplete="address-line1" value="<?php echo e($values['address_line1']); ?>">
            </div>
            <div>
                <label class="form-label" for="parent-address-line2">Address line 2</label>
                <input class="form-control" id="parent-address-line2" name="address_line2" type="text" maxlength="255" autocomplete="address-line2" value="<?php echo e($values['address_line2']); ?>">
            </div>
            <div>
                <label class="form-label" for="parent-city">City</label>
                <input class="form-control" id="parent-city" name="city" type="text" maxlength="120" autocomplete="address-level2" value="<?php echo e($values['city']); ?>">
            </div>
            <div>
                <label class="form-label" for="parent-state">State / Province</label>
                <input class="form-control" id="parent-state" name="state" type="text" maxlength="120" autocomplete="address-level1" value="<?php echo e($values['state']); ?>">
            </div>
            <div>
                <label class="form-label" for="parent-postal-code">Postal code</label>
                <input class="form-control" id="parent-postal-code" name="postal_code" type="text" maxlength="20" autocomplete="postal-code" value="<?php echo e($values['postal_code']); ?>">
            </div>
            <div>
                <label class="form-label" for="parent-emergency-contact">Emergency contact mobile</label>
                <input class="form-control" id="parent-emergency-contact" name="emergency_contact" type="tel" maxlength="30" value="<?php echo e($values['emergency_contact']); ?>">
            </div>
            <?php if (!$isEdit): ?>
                <div>
                    <label class="form-label" for="parent-initial-status">Initial status</label>
                    <select class="form-select" id="parent-initial-status" name="initial_status">
                        <option value="pending_verification"<?php echo $values['initial_status'] === 'pending_verification' ? ' selected' : ''; ?>>Pending verification</option>
                        <option value="inactive"<?php echo $values['initial_status'] === 'inactive' ? ' selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
            <?php endif; ?>
            <div class="parents-form-wide">
                <label class="form-label" for="parent-student-ids">Link students <span class="parents-optional">(optional)</span></label>
                <select class="form-select parents-student-select" id="parent-student-ids" name="student_ids[]" multiple size="6">
                    <?php foreach ($students as $student):
                        $eligible = in_array($student['status'], ['admitted', 'active'], true);
                        if (!$eligible && !($isEdit && $student['currently_linked'])) {
                            continue;
                        }
                        ?>
                        <option value="<?php echo e((string) $student['id']); ?>"<?php echo in_array($student['id'], $selectedIds, true) ? ' selected' : ''; ?>>
                            <?php echo e($student['full_name'] . ' · ' . $student['student_code'] . ' · ' . ucfirst($student['status'])); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="parents-field-help">Only admitted or active students can be newly linked. Existing links to other student statuses can be retained or revoked. Use Ctrl/Command to select multiple students.</small>
            </div>
        </div>
        <?php if ($isEdit): ?>
            <div class="parents-form-note" role="note">
                <i class="bi bi-shield-check" aria-hidden="true"></i>
                <span>
                    Email verification: <strong><?php echo !empty($parent['email_verified']) ? 'Verified' : 'Not verified'; ?></strong>.
                    Changing the email address clears its verification and returns an active parent to pending verification.
                </span>
            </div>
        <?php else: ?>
            <div class="parents-form-note" role="note">
                <i class="bi bi-shield-lock" aria-hidden="true"></i>
                <span>The institution is taken from your authenticated session. The new user account has no password and starts pending; email verification is never assumed.</span>
            </div>
        <?php endif; ?>
        <div class="parents-form-actions">
            <a class="parents-cancel-button" href="<?php echo e(route_url('/admin/parents/index')); ?>">Cancel</a>
            <button class="parents-submit-button" type="submit">
                <i class="bi <?php echo $isEdit ? 'bi-check2' : 'bi-person-plus'; ?>" aria-hidden="true"></i>
                <?php echo $isEdit ? 'Save Parent' : 'Create Parent'; ?>
            </button>
        </div>
    </form>
    <?php
}
