<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/admin-panel-service.php';
require_once __DIR__ . '/../../services/payment-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$db = db_connect();
$razorpayReady = payment_razorpay_is_ready();
$institutionId = admin_panel_institution_id();
$statement = $db->prepare(
    'SELECT gateway, COUNT(*) AS order_count,
            SUM(status IN ("pending", "created", "authorized")) AS open_order_count
     FROM payment_orders WHERE institution_id = ? GROUP BY gateway ORDER BY gateway'
);
$statement->bind_param('i', $institutionId);
$statement->execute();
$gateways = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
$statement->close();
render_app_page_start('Payment Configuration', 'Review gateways represented by this institution’s payment orders.', 'admin', ['admin-dashboard.css']);
?>
<?php echo render_flash_messages(); ?>
<section class="dashboard-panel">
    <div class="alert <?php echo $razorpayReady ? 'alert-success' : 'alert-warning'; ?>" role="status">
        Razorpay checkout is <?php echo $razorpayReady ? 'ready.' : 'not ready; valid API credentials and PHP cURL are required.'; ?>
        Gateway credentials are managed by the deployment environment and are not displayed or editable here.
    </div>
    <div class="alert alert-info" role="status">
        Webhook notifications are <?php echo app_config()['payments']['razorpay_webhook_secret'] === '' ? 'not configured; pending payments are checked against Razorpay when their status is opened and by the reconciliation job.' : 'configured.'; ?>
        Webhooks are optional and are not required to use checkout.
    </div>
    <?php if ($gateways === []): ?>
        <div class="admin-panel-empty"><i class="bi bi-credit-card" aria-hidden="true"></i><strong>No payment orders recorded</strong><span>Gateway activity will be listed after an order is created through the normal payment flow.</span></div>
    <?php else: ?>
        <div class="table-responsive"><table class="table admin-panel-table"><thead><tr><th>Gateway</th><th>Recorded orders</th><th>Open orders</th></tr></thead><tbody>
            <?php foreach ($gateways as $gateway): ?><tr><td><?php echo e(ucwords((string) $gateway['gateway'])); ?></td><td><?php echo e((string) $gateway['order_count']); ?></td><td><?php echo e((string) $gateway['open_order_count']); ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>
<?php render_app_page_end('admin'); ?>
