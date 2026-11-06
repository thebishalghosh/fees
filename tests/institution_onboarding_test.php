<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/institution-onboarding-service.php';
require_once __DIR__ . '/../services/otp-service.php';

$checks = 0;
$failures = [];
$check = static function (bool $condition, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $label;
    }
};

$validInput = [
    'name' => 'Northside College',
    'short_name' => 'Northside',
    'code' => 'NORTH-01',
    'institution_type' => 'college',
    'currency_code' => 'USD',
    'email' => 'office@northside.example',
    'phone' => '+1 555 123 4567',
    'address_line1' => '1 College Road',
    'address_line2' => '',
    'city' => 'Example City',
    'state' => 'Example State',
    'postal_code' => '12345',
    'country' => 'United States',
    'website' => 'https://northside.example',
    'principal_name' => 'Taylor Example',
    'principal_email' => 'principal@northside.example',
    'principal_phone' => '+1 555 123 4568',
    'registration_number' => 'REG-1',
    'affiliation_body' => 'Accreditation Board',
    'affiliation_number' => 'ACC-1',
    'established_year' => '1998',
    'administrator_name' => 'Alex Admin',
    'administrator_email' => 'admin@northside.example',
    'administrator_phone' => '+1 555 123 4569',
    'administrator_email_confirmed' => '1',
    'status' => 'active',
];

$validated = institution_onboarding_validate_input($validInput, true);
$check(
    $validated['institution_type'] === 'college'
        && $validated['currency_code'] === 'USD'
        && $validated['status'] === 'active'
        && $validated['administrator_email'] === 'admin@northside.example',
    'Validate and normalize institution and initial administrator details'
);
$check(
    validate_institution_code($validInput['code'])
        && !validate_institution_code('CODE WITH SPACES')
        && !validate_institution_code(''),
    'Use a profile institution-code format accepted by staff login and parent verification'
);

foreach ([
    ['administrator_email_confirmed' => '0'],
    ['administrator_email' => 'not-an-email'],
    ['administrator_phone' => 'no phone'],
    ['administrator_phone' => '123456789012345678901'],
    ['status' => 'archived'],
    ['code' => 'INVALID CODE'],
    ['institution_type' => 'institute'],
    ['currency_code' => 'XYZ'],
    ['address_line1' => ''],
] as $overrides) {
    $invalidInput = array_replace($validInput, $overrides);
    $rejected = false;
    try {
        institution_onboarding_validate_input($invalidInput, true);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    $check($rejected, 'Reject invalid institution onboarding values');
}

$route = file_get_contents(__DIR__ . '/../super-admin/institutions.php');
$service = file_get_contents(__DIR__ . '/../services/institution-onboarding-service.php');
$rewriteRules = file_get_contents(__DIR__ . '/../.htaccess');
$sidebar = file_get_contents(__DIR__ . '/../includes/layouts/sidebar.php');
$platformAdminRoute = file_get_contents(__DIR__ . '/../platform-admin/dashboard.php');
$schema = file_get_contents(__DIR__ . '/../database/schema.sql');
$migration = file_get_contents(__DIR__ . '/../database/migrations/20261103_add_institution_profile_fields.sql');
$check(
    is_string($route)
        && str_contains($route, "require_user_types(['super_admin'])")
        && str_contains($route, 'require_valid_csrf_token()')
        && str_contains($route, 'institution_onboarding_create')
        && str_contains($route, 'institution_onboarding_set_status'),
    'Enforce Super Admin authorization and CSRF on onboarding routes'
);
$check(
    is_string($service)
        && str_contains($service, "user['user_type'] !== 'super_admin'")
        && str_contains($service, "WHERE name = 'admin' AND is_system = 1")
        && str_contains($service, "VALUES (?, ?, 'admin'")
        && str_contains($service, '$db->begin_transaction()')
        && str_contains($service, "'institution.created'")
        && str_contains($service, "'institution.activated'")
        && str_contains($service, "'institution.deactivated'")
        && !str_contains($service, 'password_hash('),
    'Create only institution-scoped system Admin accounts transactionally without passwords'
);
$check(
    is_string($rewriteRules)
        && str_contains($rewriteRules, '(auth|admin|parent|super-admin|platform-admin)')
        && is_string($sidebar)
        && str_contains($sidebar, "current_user()['user_type'] ?? null) === 'super_admin'"),
    'Add a clean route and preserve institution-management navigation'
);
$check(
    is_string($service)
        && str_contains($service, "institution_onboarding_authorize('institutions.view')")
        && str_contains($service, "institution_onboarding_authorize('institutions.create')")
        && str_contains($service, "institution_onboarding_authorize('institutions.update')")
        && str_contains($service, "institution_onboarding_authorize('institutions.suspend')")
        && str_contains($service, 'write_platform_audit_log(')
        && is_string($platformAdminRoute)
        && str_contains($platformAdminRoute, 'require_platform_admin()')
        && str_contains($platformAdminRoute, 'institution_onboarding_list('),
    'Authorize platform institution management by permission and record platform audit events'
);
$check(
    is_string($schema)
        && str_contains($schema, 'institution_type VARCHAR(40) NULL')
        && str_contains($schema, 'principal_name VARCHAR(150) NULL')
        && str_contains($schema, "currency_code CHAR(3) NOT NULL DEFAULT 'INR'")
        && is_string($migration)
        && str_contains($migration, 'ALTER TABLE institutions')
        && !preg_match('/\b(DROP|TRUNCATE|DELETE)\b/i', $migration),
    'Add only forward institution profile/currency columns and preserve existing tables/data'
);

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'Passed ' . $checks . ' institution onboarding checks.' . PHP_EOL);
