<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/notification-service.php';

$checks = 0;
$failures = [];
$check = static function (bool $condition, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $label;
    }
};

$notificationService = file_get_contents(__DIR__ . '/../services/notification-service.php');
$parentPage = file_get_contents(__DIR__ . '/../parent/notifications/index.php');
$header = file_get_contents(__DIR__ . '/../includes/layouts/header.php');
$cron = file_get_contents(__DIR__ . '/../cron/process-notifications.php');
$schema = file_get_contents(__DIR__ . '/../database/schema.sql');
$migration = file_get_contents(__DIR__ . '/../database/migrations/20261015_add_notification_generation.sql');
$adminService = file_get_contents(__DIR__ . '/../services/admin-panel-service.php');
$adminCreate = file_get_contents(__DIR__ . '/../admin/notifications/create.php');
$adminIndex = file_get_contents(__DIR__ . '/../admin/notifications/index.php');
$adminHistory = file_get_contents(__DIR__ . '/../admin/notifications/send.php');
$readable = is_string($notificationService)
    && is_string($parentPage)
    && is_string($header)
    && is_string($cron)
    && is_string($schema)
    && is_string($migration)
    && is_string($adminService)
    && is_string($adminCreate)
    && is_string($adminIndex)
    && is_string($adminHistory);
$check($readable, 'Read notification, announcement, and schema implementation');

$check(
    notification_service_type_label('payment_success') === 'Payment confirmation'
        && notification_service_type_label('holiday_update') === 'Holiday update'
        && notification_service_type_label('unrecognized') === 'Other',
    'Use safe labels for notification categories'
);

if ($readable) {
    $accessSql = notification_service_user_access_sql();
    $check(
        str_contains($accessSql, 'n.institution_id = ?')
            && str_contains($accessSql, 'n.recipient_user_id = ?')
            && str_contains($accessSql, 'recipient_parent.id = ?')
            && str_contains($accessSql, "psr.status = 'active'")
            && str_contains($accessSql, 'psr.can_view_fees = 1')
            && str_contains($accessSql, 'psr.can_view_academics = 1'),
        'Scope notification access to the authenticated parent and the relevant child permissions'
    );
    $check(
        str_contains($notificationService, 'notification_service_bind_parent_scope')
            && str_contains($notificationService, 'notification_service_mark_parent_notification_read')
            && str_contains($notificationService, 'notification_service_mark_all_parent_notifications_read')
            && str_contains($notificationService, 'LIMIT ? OFFSET ?'),
        'Use common ownership checks for notification listing, read actions, and pagination'
    );
    $check(
        str_contains($parentPage, 'require_valid_csrf_token()')
            && str_contains($parentPage, 'notification_service_mark_parent_notification_read(')
            && str_contains($parentPage, 'notification_service_mark_all_parent_notifications_read(')
            && str_contains($parentPage, "e(\$notification['title'])")
            && str_contains($parentPage, "e((string) \$notification['message'])"),
        'Protect state-changing notification forms and escape stored content'
    );
    $check(
        str_contains($header, 'notification_service_unread_count_for_user(')
            && str_contains($notificationService, "r.name = 'parent'"),
        'Show an unread count for the validated authenticated parent'
    );
    $check(
        str_contains($notificationService, 'ON DUPLICATE KEY UPDATE id = id')
            && str_contains($schema, 'uq_notifications_recipient_dedupe')
            && str_contains($migration, 'uq_notifications_recipient_dedupe'),
        'Make repeated generation idempotent at the database level'
    );
    $check(
        str_contains($notificationService, "pay.status = 'success' AND pay.verified_at IS NOT NULL")
            && str_contains($notificationService, "pa.status IN ('pending','failed')")
            && !str_contains($notificationService, 'payment_verification_'),
        'Generate payment notices from verified successes and persisted payment attempts only'
    );
    $check(
        str_contains($notificationService, 'upcoming_7_day')
            && str_contains($notificationService, "'installment:' . (int) \$installment['installment_id']")
            && str_contains($notificationService, 'notification_service_generation_cursor')
            && str_contains($notificationService, 'notification_service_advance_generation_cursor'),
        'Deduplicate due reminders and checkpoint event-based generation'
    );
    $check(
        str_contains($cron, "PHP_SAPI !== 'cli'")
            && str_contains($cron, 'notification_service_generate_due_reminders(')
            && str_contains($cron, 'notification_service_generate_academic_notifications('),
        'Run notification generation only through the scheduled CLI entry point'
    );
    $check(
        str_contains($notificationService, "a.status = 'published'")
            && str_contains($notificationService, 'a.publish_at <= CURRENT_TIMESTAMP')
            && str_contains($notificationService, 'a.expires_at >= CURRENT_TIMESTAMP')
            && str_contains($accessSql, "a.status = 'published'"),
        'Generate and expose only currently published, audience-authorized announcements'
    );
    $check(
        str_contains($adminService, 'WHERE institution_id = ? AND id = ?')
            && str_contains($adminService, 'announcement.updated')
            && str_contains($adminCreate, 'admin_panel_announcement(')
            && str_contains($adminIndex, 'notifications/create?id=')
            && str_contains($adminHistory, 'admin_panel_notifications('),
        'Edit announcements inside the institution-scoped existing admin workflow'
    );
}

if ($failures !== []) {
    fwrite(STDERR, 'FAIL: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'PASS: ' . $checks . ' notification checks.' . PHP_EOL);
