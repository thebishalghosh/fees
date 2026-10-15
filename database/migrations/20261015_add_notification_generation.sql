ALTER TABLE notifications
    MODIFY notification_type ENUM(
        'fee_reminder',
        'payment_success',
        'payment_failed',
        'payment_pending',
        'class_update',
        'exam_reminder',
        'holiday_update',
        'announcement',
        'admission_update',
        'refund_update',
        'system',
        'other'
    ) NOT NULL DEFAULT 'other',
    ADD COLUMN dedupe_key VARCHAR(191) NULL AFTER related_entity_id,
    ADD UNIQUE KEY uq_notifications_recipient_dedupe
        (institution_id, recipient_user_id, dedupe_key);

CREATE TABLE IF NOT EXISTS notification_generation_cursors (
    cursor_key VARCHAR(80) NOT NULL,
    last_processed_at DATETIME NOT NULL,
    PRIMARY KEY (cursor_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO notification_generation_cursors (cursor_key, last_processed_at) VALUES
    ('payment_events', CURRENT_TIMESTAMP),
    ('payment_attempt_events', CURRENT_TIMESTAMP),
    ('admission_events', CURRENT_TIMESTAMP),
    ('refund_events', CURRENT_TIMESTAMP),
    ('timetable_events', CURRENT_TIMESTAMP),
    ('examination_events', CURRENT_TIMESTAMP),
    ('holiday_events', CURRENT_TIMESTAMP);
