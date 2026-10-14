<?php
declare(strict_types=1);

$requestedBatchId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$adminBatchId = is_int($requestedBatchId) && $requestedBatchId > 0 ? $requestedBatchId : -1;
require_once __DIR__ . '/../../includes/admin-batch-form.php';
