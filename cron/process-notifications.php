<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/notification-service.php';

try {
    $db = db_connect();
    $timezone = new DateTimeZone((string) app_config()['timezone']);
    $today = new DateTimeImmutable('today', $timezone);

    $created = [
        'payments' => notification_service_generate_payment_notifications($db),
        'installments' => notification_service_generate_due_reminders($db, $today),
        'academic' => notification_service_generate_academic_notifications($db),
        'announcements' => notification_service_generate_announcement_notifications($db),
        'admissions' => notification_service_generate_admission_notifications($db),
        'refunds' => notification_service_generate_refund_notifications($db),
    ];
    echo 'Notifications created: ' . json_encode($created, JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    log_error('notification_generation_failed', [
        'error_type' => get_class($exception),
        'error_code' => $exception->getCode(),
    ]);
    fwrite(STDERR, 'Notification generation failed: ' . get_class($exception) . PHP_EOL);
    exit(1);
}