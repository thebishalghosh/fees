<?php
declare(strict_types=1);

$page = file_get_contents(__DIR__ . '/../super-admin/institutions.php');
if (!is_string($page)
    || !str_contains($page, "'super_admin_institution_form_states'")
    || !str_contains($page, "'created_at' => time()")
    || !str_contains($page, 'unset($_SESSION[\'super_admin_institution_form_states\'][$formStateToken])')
    || !str_contains($page, 'safe_redirect($redirect . $separator . \'form_state=\' . $formStateToken . $fragment, 303)')
    || !str_contains($page, 'form_values')
    || !str_contains($page, 'branding_values')) {
    fwrite(STDERR, "Super Admin institution form failures must use one-time POST/Redirect/GET state.\n");
    exit(1);
}

fwrite(STDOUT, "Passed Super Admin institution POST/Redirect/GET check.\n");
