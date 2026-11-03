<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/staff-management-service.php';

$checks = 0;
$failures = [];
$check = static function (bool $condition, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $label;
    }
};

$validInput = [
    'first_name' => 'Asha',
    'last_name' => 'Sharma',
    'email' => ' Asha.Sharma@example.test ',
    'phone' => '+91 98765-43210',
    'employee_code' => 'EMP-102',
    'status' => 'active',
    'role_id' => '3',
    'email_confirmed' => '1',
];
$valid = staff_management_validate_input($validInput);
$check($valid['errors'] === [], 'Accept valid staff creation details');
$check(
    $valid['data']['full_name'] === 'Asha Sharma'
        && $valid['data']['email'] === 'asha.sharma@example.test'
        && $valid['data']['employee_code'] === 'EMP-102',
    'Normalize staff name, email, and employee ID'
);

$invalidEmail = $validInput;
$invalidEmail['email'] = 'not-an-email';
$check(staff_management_validate_input($invalidEmail)['data'] === null, 'Reject invalid email addresses');

$missingConfirmation = $validInput;
$missingConfirmation['email_confirmed'] = '';
$check(
    in_array('Confirm that the staff member can receive email at this address.', staff_management_validate_input($missingConfirmation)['errors'], true),
    'Require explicit email confirmation for newly provisioned accounts'
);

$badEmployeeCode = $validInput;
$badEmployeeCode['employee_code'] = str_repeat('E', 51);
$check(staff_management_validate_input($badEmployeeCode)['data'] === null, 'Reject employee IDs beyond the schema limit');

$badPhone = $validInput;
$badPhone['phone'] = 'call-me';
$check(staff_management_validate_input($badPhone)['data'] === null, 'Reject malformed phone numbers');

$editWithoutRole = $validInput;
$editWithoutRole['role_id'] = '';
$editWithoutRole['email_confirmed'] = '';
$check(
    staff_management_validate_input($editWithoutRole, false, false, false)['errors'] === [],
    'Allow editing details without changing role or reconfirming an unchanged email'
);

$singleNameEdit = $editWithoutRole;
$singleNameEdit['first_name'] = 'Cher';
$singleNameEdit['last_name'] = '';
$singleName = staff_management_validate_input($singleNameEdit, false, false, false);
$check(
    $singleName['errors'] === [] && $singleName['data']['full_name'] === 'Cher',
    'Preserve valid single-part names when editing existing accounts'
);

$arrayInput = $validInput;
$arrayInput['first_name'] = ['unexpected'];
$check(staff_management_validate_input($arrayInput)['data'] === null, 'Reject non-scalar form values');

if ($failures !== []) {
    fwrite(STDERR, "Staff management tests failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Staff management tests passed ({$checks} checks).\n";
