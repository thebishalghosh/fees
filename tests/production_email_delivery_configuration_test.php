<?php
declare(strict_types=1);

$environment = [
    'APP_ENV' => 'production',
    'MAIL_PROVIDER' => 'smtp',
    'MAIL_HOST' => 'smtp.example.test',
    'MAIL_PORT' => '587',
    'MAIL_USERNAME' => 'smtp-user',
    'MAIL_PASSWORD' => 'smtp-password',
    'MAIL_ENCRYPTION' => 'tls',
    'MAIL_FROM_ADDRESS' => 'fees@example.test',
    'MAIL_FROM_NAME' => 'Fees Portal',
    'MAIL_TIMEOUT' => '10',
];
foreach ($environment as $key => $value) {
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../services/email-delivery.php';

if (!otp_email_delivery_available()) {
    fwrite(STDERR, "FAIL: Accept valid production SMTP configuration for verification email.\n");
    exit(1);
}

fwrite(STDOUT, "PASS: Production SMTP configuration is available for verification email.\n");
