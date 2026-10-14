<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/reconciliation-service.php';

try {
    $summary = payment_reconciliation_run(db_connect());
    fwrite(STDOUT, json_encode($summary, JSON_THROW_ON_ERROR) . PHP_EOL);
    if ($summary['errors'] > 0) {
        exit(1);
    }
} catch (Throwable $exception) {
    log_error('payment_reconciliation_run_failed', [
        'operation' => 'reconcile_batch',
        'error_type' => get_class($exception),
        'error_code' => $exception->getCode(),
    ]);
    fwrite(STDERR, "Payment reconciliation could not complete." . PHP_EOL);
    exit(1);
}