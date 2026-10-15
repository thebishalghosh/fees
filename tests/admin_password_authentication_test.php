<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/admin-password-auth-service.php';
require_once __DIR__ . '/../includes/components/login-form.php';

$checks = 0;
$failures = [];
$check = static function (bool $condition, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $label;
    }
};

$account = [
    'id' => 71,
    'institution_id' => 12,
    'role_id' => 2,
    'user_type' => 'admin',
    'role_name' => 'admin',
    'status' => 'active',
    'institution_status' => 'active',
];
$check(admin_auth_unique_account([$account]) === $account, 'Accept one active, role-matched administrator');
$parentAccount = $account;
$parentAccount['user_type'] = 'parent';
$parentAccount['role_name'] = 'parent';
$check(admin_auth_unique_account([$parentAccount]) === null, 'Reject parent accounts from admin authentication');
$mismatchedRole = $account;
$mismatchedRole['role_name'] = 'staff';
$check(admin_auth_unique_account([$mismatchedRole]) === null, 'Reject inconsistent administrator roles');
$inactiveAccount = $account;
$inactiveAccount['institution_status'] = 'inactive';
$check(admin_auth_unique_account([$inactiveAccount]) === null, 'Reject accounts in inactive institutions');
$check(admin_auth_unique_account([$account, $account]) === null, 'Reject ambiguous identifier matches');

$password = 'valid-test-password-7';
$hash = password_hash($password, PASSWORD_DEFAULT);
$check(admin_auth_password_matches($password, $hash), 'Verify the correct password against its stored hash');
$check(!admin_auth_password_matches('incorrect-password', $hash), 'Reject an incorrect password');
$check(!admin_auth_password_matches($password, null), 'Reject an account with no password hash');
$check(!admin_auth_password_matches($password, 'not-a-password-hash'), 'Reject an invalid password hash');
$check(!admin_auth_password_matches('', $hash), 'Reject an empty password');
$check(admin_auth_password_is_valid('long-enough-12'), 'Accept a strong-length password for initialization');
$check(!admin_auth_password_is_valid('short'), 'Reject passwords below the minimum length');
$check(!admin_auth_password_is_valid(str_repeat('a', 73)), 'Reject passwords exceeding the hash algorithm byte limit');

ob_start();
render_login_form('admin', route_url('/login/admin'), 'admin-test');
$adminForm = (string) ob_get_clean();
$check(str_contains($adminForm, 'Username or Email'), 'Label the admin identifier field');
$check(str_contains($adminForm, 'name="identifier"'), 'Accept one admin username-or-email field');
$check(str_contains($adminForm, 'name="password"') && str_contains($adminForm, 'type="password"'), 'Render a password input');
$check(str_contains($adminForm, '>Login</button>'), 'Use the requested admin login action');
$check(!str_contains($adminForm, 'institution_code') && !str_contains($adminForm, 'otp'), 'Keep institution and OTP fields off the admin form');

$adminHandler = file_get_contents(__DIR__ . '/../auth/admin-login.php');
$parentHandler = file_get_contents(__DIR__ . '/../auth/parent-login.php');
$passwordPage = file_get_contents(__DIR__ . '/../auth/admin-password.php');
$check(
    is_string($adminHandler)
        && str_contains($adminHandler, 'admin_auth_password_login(')
        && str_contains($adminHandler, 'Invalid username/email or password.'),
    'Use password authentication and a generic credential error'
);
$check(
    is_string($adminHandler)
        && is_string($parentHandler)
        && str_contains($parentHandler, 'begin_parent_email_challenge(')
        && !str_contains($adminHandler, 'begin_parent_email_challenge('),
    'Keep parent OTP on its dedicated route'
);
$check(
    is_string($passwordPage)
        && str_contains($passwordPage, 'require_login()')
        && str_contains($passwordPage, 'admin_auth_update_own_password(')
        && str_contains($passwordPage, 'require_valid_csrf_token()'),
    'Restrict password initialization/reset to an authenticated administrator'
);

if ($failures !== []) {
    fwrite(STDERR, 'FAIL: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'PASS: ' . $checks . ' administrator password authentication checks.' . PHP_EOL);
