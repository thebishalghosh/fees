<?php
declare(strict_types=1);

$adminListTable = 'fee_adjustments';
$adminListTitle = 'Fee Adjustments';
$adminListDescription = 'Recorded fee adjustments and their approving accounts. This history is read-only.';
require_once __DIR__ . '/../../includes/admin-simple-list.php';
