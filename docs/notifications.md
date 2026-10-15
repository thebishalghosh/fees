# Notifications

Notifications are stored in the existing `notifications` table and loaded on
ordinary parent-portal requests. The shared parent header shows the unread
count. Parents can mark one notification or all visible notifications as read;
both actions use authenticated, CSRF-protected form submissions.

## Install and schedule

Apply `database/migrations/20261015_add_notification_generation.sql` before
running the generator. Schedule `cron/process-notifications.php` using the
PHP CLI; it returns 404 when invoked over HTTP.

For a Laragon/Windows deployment, create a daily Windows Task Scheduler task
that runs the configured PHP executable with this script as its argument, for
example:

```text
C:\laragon\bin\php\php-8.3.26-Win32-vs16-x64\php.exe C:\laragon\www\fees\cron\process-notifications.php
```

Use the PHP path and project path configured for the deployment. A daily run
generates due reminders, payment and admission status messages, academic
schedule changes, published-announcement notifications, and supported refund
outcomes. The application timezone (`APP_TIMEZONE`) determines the local date
used for the seven-day upcoming-installment window. The current schema does not
provide per-institution timezones.

Generated messages use stable deduplication keys and are safe to process again
after a failed or repeated run. Payment, admission, academic-change, and
processed-refund cursors start at migration time so existing historical
records are not backfilled; upcoming/overdue installments and currently
published announcements are checked on every run. Rejected refunds without a
`processed_at` value are not generated because the current schema does not
record when that status changed.

Payment notifications read persisted payment states only. A payment
confirmation requires `payments.status = 'success'` and a non-null
`verified_at`; pending and failed notices come from the persisted
`payment_attempts` state. Notification generation never updates payment
records or invokes gateway logic.

Timetable, examination, and holiday notifications are generated when those
records are created or updated after the cursor is initialized. For other
academic communications or class cancellations not represented by a changed
record, administrators can publish an appropriately targeted announcement.
Draft and unpublished announcements are never generated or shown to parents.
