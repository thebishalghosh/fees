<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/fee-assignment-service.php';

require_http_methods(['POST']);
require_permission('fees.manage');
require_valid_csrf_token();
$user = current_user();
if ($user === null) {
    throw new RuntimeException('An authenticated institution account is required.');
}
$assignmentId = filter_var($_POST['assignment_id'] ?? null, FILTER_VALIDATE_INT);
$firstDueDate = $_POST['first_due_date'] ?? null;
if ($assignmentId === false || !is_string($firstDueDate)) {
    set_flash('danger', 'Enter a valid fee assignment and first due date.');
    safe_redirect('/admin/fees/assignments', 303);
}

try {
    $result = fee_workflow_generate_installments(
        db_connect(),
        (int) $user['institution_id'],
        (int) $user['id'],
        $assignmentId,
        $firstDueDate
    );
    if ($result['already_exists']) {
        set_flash('warning', 'An installment schedule already exists for this assignment. It was left unchanged.');
    } else {
        set_flash('success', $result['created'] . ' installments generated. The fee balance and payment ledger were not changed.');
    }
} catch (InvalidArgumentException | DomainException | OutOfBoundsException $exception) {
    set_flash('danger', $exception->getMessage());
} catch (Throwable $exception) {
    log_error('installment_generation_failed', [
        'error_type' => get_class($exception),
        'error_code' => $exception->getCode(),
    ]);
    set_flash('danger', 'The installment schedule could not be generated. Check the assignment and try again.');
}
safe_redirect('/admin/fees/assignments', 303);