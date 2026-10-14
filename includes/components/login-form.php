<?php
declare(strict_types=1);

function render_login_form(
    string $type,
    string $action,
    string $idPrefix,
    array $values = [],
    array $errors = []
): void {
    if ($type === 'parent') {
        $email = is_string($values['email'] ?? null) ? $values['email'] : '';
        $emailError = is_string($errors['email'] ?? null) ? $errors['email'] : '';
        ?>
        <form method="post" action="<?php echo e($action); ?>" novalidate>
            <?php echo csrf_field(); ?>
            <div class="mb-4">
                <label class="form-label" for="<?php echo e($idPrefix); ?>-email">Email address</label>
                <input class="form-control<?php echo $emailError !== '' ? ' is-invalid' : ''; ?>"
                    id="<?php echo e($idPrefix); ?>-email" name="email" type="email" required maxlength="255"
                    autocomplete="email" value="<?php echo e($email); ?>">
                <?php if ($emailError !== ''): ?>
                    <div class="invalid-feedback"><?php echo e($emailError); ?></div>
                <?php endif; ?>
            </div>
            <button class="btn btn-cobalt w-100" type="submit">Continue with email</button>
        </form>
        <?php
        return;
    }

    if ($type !== 'staff') {
        throw new InvalidArgumentException('The requested login form is not supported.');
    }

    $email = is_string($values['email'] ?? null) ? $values['email'] : '';
    $institutionCode = is_string($values['institution_code'] ?? null) ? $values['institution_code'] : '';
    $emailError = is_string($errors['email'] ?? null) ? $errors['email'] : '';
    $institutionError = is_string($errors['institution_code'] ?? null) ? $errors['institution_code'] : '';
    ?>
    <form method="post" action="<?php echo e($action); ?>" novalidate>
        <?php echo csrf_field(); ?>
        <div class="mb-3">
            <label class="form-label" for="<?php echo e($idPrefix); ?>-institution-code">Institution code</label>
            <input class="form-control<?php echo $institutionError !== '' ? ' is-invalid' : ''; ?>"
                id="<?php echo e($idPrefix); ?>-institution-code" name="institution_code" type="text"
                required maxlength="50" autocomplete="organization"
                value="<?php echo e($institutionCode); ?>">
            <?php if ($institutionError !== ''): ?>
                <div class="invalid-feedback"><?php echo e($institutionError); ?></div>
            <?php endif; ?>
        </div>
        <div class="mb-4">
            <label class="form-label" for="<?php echo e($idPrefix); ?>-email">Registered email address</label>
            <input class="form-control<?php echo $emailError !== '' ? ' is-invalid' : ''; ?>"
                id="<?php echo e($idPrefix); ?>-email" name="email" type="email" required maxlength="255"
                autocomplete="email" value="<?php echo e($email); ?>">
            <?php if ($emailError !== ''): ?>
                <div class="invalid-feedback"><?php echo e($emailError); ?></div>
            <?php endif; ?>
        </div>
        <button class="btn btn-cobalt w-100" type="submit">Continue with email</button>
    </form>
    <?php
}
