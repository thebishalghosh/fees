<?php
declare(strict_types=1);

$requestedStructureId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$adminFeeStructureId = is_int($requestedStructureId) && $requestedStructureId > 0 ? $requestedStructureId : -1;
require_once __DIR__ . '/../../includes/admin-fee-structure-form.php';
