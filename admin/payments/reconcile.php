<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/admin-panel-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$events = admin_panel_webhook_events(db_connect(), admin_panel_institution_id());
render_app_page_start('Webhook Review', 'Review webhook processing state. Gateway event payloads and reconciliation secrets are not displayed.', 'admin', ['admin-dashboard.css']);
?>
<?php echo render_flash_messages(); ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/payments/index')); ?>">Payments</a></li><li class="breadcrumb-item active" aria-current="page">Webhook Review</li></ol></nav>
<section class="dashboard-panel">
    <?php if ($events === []): ?>
        <div class="admin-panel-empty"><i class="bi bi-shield-check" aria-hidden="true"></i><strong>No webhook events recorded</strong><span>Gateway webhook activity will appear here after events are received.</span></div>
    <?php else: ?>
        <div class="table-responsive"><table class="table admin-panel-table"><thead><tr><th>Event</th><th>Gateway</th><th>Type</th><th>Processing state</th><th>Received</th><th>Processed</th><th></th></tr></thead><tbody>
            <?php foreach ($events as $event): ?><tr><td>#<?php echo e((string) $event['id']); ?></td><td><?php echo e(ucfirst((string) $event['gateway'])); ?></td><td><?php echo e((string) $event['event_type']); ?></td><td><span class="admin-status-badge is-<?php echo e((string) $event['processing_status']); ?>"><?php echo e(ucfirst((string) $event['processing_status'])); ?></span></td><td><?php echo e((string) $event['created_at']); ?></td><td><?php echo e((string) ($event['processed_at'] ?? '—')); ?></td><td><?php if ($event['payment_id'] !== null): ?><a href="<?php echo e(route_url('/admin/payments/details') . '?id=' . (int) $event['payment_id']); ?>">Payment</a><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <p class="admin-panel-note">This screen is read-only. Failed events must be reconciled through the gateway’s verified reconciliation workflow; viewing an event does not mark a payment successful.</p>
    <?php endif; ?>
</section>
<?php render_app_page_end('admin'); ?>
