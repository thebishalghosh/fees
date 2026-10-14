<?php
declare(strict_types=1);

require_once __DIR__ . '/page-placeholder.php';
require_once __DIR__ . '/../services/admin-panel-service.php';

function render_admin_report_page(string $report): void
{
    require_http_methods(['GET', 'HEAD']);
    require_placeholder_route_access();
    $db = db_connect();
    $institutionId = admin_panel_institution_id();
    $reportNames = [
        'collections' => ['Fee Collections', 'Verified collections by date, with processed refunds deducted.'],
        'outstanding-fees' => ['Outstanding Fees', 'Current outstanding fee assignments and overdue installment indicators.'],
        'student-wise' => ['Student Fee Report', 'Assigned fees, saved payments, and outstanding balances by student.'],
        'parent-wise' => ['Parent Fee Report', 'Active linked students and their linked outstanding fee balances.'],
    ];
    if (!isset($reportNames[$report])) {
        throw new InvalidArgumentException('The requested report is not available.');
    }
    [$title, $description] = $reportNames[$report];
    $fromDate = isset($_GET['from']) && is_string($_GET['from']) ? $_GET['from'] : date('Y-m-01');
    $toDate = isset($_GET['to']) && is_string($_GET['to']) ? $_GET['to'] : date('Y-m-d');
    foreach ([$fromDate, $toDate] as $dateValue) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue);
        if ($date === false || $date->format('Y-m-d') !== $dateValue) {
            $fromDate = date('Y-m-01');
            $toDate = date('Y-m-d');
            break;
        }
    }
    if ($fromDate > $toDate) {
        [$fromDate, $toDate] = [$toDate, $fromDate];
    }

    $rows = match ($report) {
        'collections' => admin_panel_collection_report($db, $institutionId, $fromDate, $toDate),
        'outstanding-fees' => admin_panel_outstanding_assignments($db, $institutionId),
        'student-wise' => admin_panel_student_fee_report($db, $institutionId),
        'parent-wise' => admin_panel_parent_fee_report($db, $institutionId),
    };

    render_app_page_start($title, $description, 'admin', ['admin-dashboard.css']);
    ?>
<?php echo render_flash_messages(); ?>
<nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li><li class="breadcrumb-item active" aria-current="page">Reports</li></ol></nav>
<section class="dashboard-panel admin-recent-payments-panel">
    <div class="dashboard-panel-heading"><div><h2><?php echo e($title); ?></h2><p><?php echo e($description); ?></p></div></div>
    <?php if ($report === 'collections'): ?>
        <form class="admin-list-filters" method="get"><label>From date<input class="form-control" type="date" name="from" value="<?php echo e($fromDate); ?>"></label><label>To date<input class="form-control" type="date" name="to" value="<?php echo e($toDate); ?>"></label><button class="btn btn-cobalt" type="submit">Apply dates</button></form>
        <?php $netTotalCents = 0; foreach ($rows as $row) { $netTotalCents += admin_panel_decimal_cents((string) $row['net_collected']); } ?>
        <p class="admin-report-total">Net collected: <strong>₹<?php echo e(number_format($netTotalCents / 100, 2)); ?></strong> · <?php echo e($fromDate); ?> to <?php echo e($toDate); ?></p>
        <?php if ($rows === []): ?><div class="admin-panel-empty"><i class="bi bi-bar-chart" aria-hidden="true"></i><strong>No verified collections in this date range</strong><span>Try a wider date range.</span></div><?php else: ?>
            <div class="table-responsive"><table class="table admin-panel-table"><thead><tr><th>Verified date</th><th>Payments</th><th class="text-end">Net amount</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><?php echo e(date('j M Y', strtotime((string) $row['collection_date']))); ?></td><td><?php echo e(number_format((int) $row['payment_count'])); ?></td><td class="text-end">₹<?php echo e(number_format((float) $row['net_collected'], 2)); ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    <?php elseif ($report === 'outstanding-fees'): ?>
        <?php if ($rows === []): ?><div class="admin-panel-empty"><i class="bi bi-check2-circle" aria-hidden="true"></i><strong>No outstanding fee assignments</strong><span>All active assignment balances are settled.</span></div><?php else: ?>
            <div class="table-responsive"><table class="table admin-panel-table"><thead><tr><th>Student</th><th>Class</th><th>Academic year</th><th>Fee structure</th><th>Status</th><th>Overdue since</th><th class="text-end">Balance</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><?php if (user_has_permission('students.view')): ?><a href="<?php echo e(route_url('/admin/students/view') . '?id=' . (int) $row['student_id']); ?>"><?php echo e($row['student_name']); ?></a><?php else: ?><?php echo e($row['student_name']); ?><?php endif; ?><small><?php echo e($row['student_code']); ?></small></td><td><?php echo e($row['class_name'] ?? '—'); ?></td><td><?php echo e($row['academic_year_name']); ?></td><td><?php echo e($row['fee_structure_name']); ?></td><td><?php echo e(ucfirst((string) $row['status'])); ?></td><td><?php echo e($row['oldest_overdue_date'] !== null ? date('j M Y', strtotime((string) $row['oldest_overdue_date'])) : '—'); ?></td><td class="text-end">₹<?php echo e(number_format((float) $row['outstanding_amount'], 2)); ?></td></tr><?php endforeach; ?></tbody></table></div>
            <p class="admin-panel-note">Showing up to 250 outstanding assignments ordered by oldest overdue due date; totals are not inferred from payment attempts.</p>
        <?php endif; ?>
    <?php elseif ($report === 'student-wise'): ?>
        <?php if ($rows === []): ?><div class="admin-panel-empty"><strong>No student records</strong></div><?php else: ?>
            <div class="table-responsive"><table class="table admin-panel-table"><thead><tr><th>Student</th><th>Class</th><th>Status</th><th>Assignments</th><th class="text-end">Assigned</th><th class="text-end">Recorded paid</th><th class="text-end">Outstanding</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><?php if (user_has_permission('students.view')): ?><a href="<?php echo e(route_url('/admin/students/view') . '?id=' . (int) $row['id']); ?>"><?php echo e($row['full_name']); ?></a><?php else: ?><?php echo e($row['full_name']); ?><?php endif; ?><small><?php echo e($row['student_code']); ?></small></td><td><?php echo e($row['class_name'] ?? '—'); ?></td><td><?php echo e(ucfirst((string) $row['student_status'])); ?></td><td><?php echo e(number_format((int) $row['assignment_count'])); ?></td><td class="text-end">₹<?php echo e(number_format((float) $row['assigned_fee'], 2)); ?></td><td class="text-end">₹<?php echo e(number_format((float) $row['recorded_paid'], 2)); ?></td><td class="text-end">₹<?php echo e(number_format((float) $row['outstanding'], 2)); ?></td></tr><?php endforeach; ?></tbody></table></div>
            <p class="admin-panel-note">Fee values are saved assignment aggregates, not recomputed from payment orders or unverified attempts.</p>
        <?php endif; ?>
    <?php else: ?>
        <?php if ($rows === []): ?><div class="admin-panel-empty"><strong>No parent records</strong></div><?php else: ?>
            <div class="table-responsive"><table class="table admin-panel-table"><thead><tr><th>Parent</th><th>Email</th><th>Active linked students</th><th class="text-end">Linked outstanding</th><th></th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><?php echo e($row['full_name']); ?></td><td><?php echo e($row['email'] ?? '—'); ?></td><td><?php echo e(number_format((int) $row['linked_students'])); ?></td><td class="text-end">₹<?php echo e(number_format((float) $row['linked_outstanding'], 2)); ?></td><td><?php if (user_has_permission('parents.view')): ?><a href="<?php echo e(route_url('/admin/parents/view') . '?id=' . (int) $row['id']); ?>">View</a><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php render_app_page_end('admin'); ?>
    <?php
}
