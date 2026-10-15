<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/parent-portal-service.php';

$checks = 0;
$failures = [];
$check = static function (bool $condition, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $label;
    }
};

$check(parent_portal_initials('Ava Parent') === 'AP', 'Build a two-part child avatar');
$check(parent_portal_initials('  Single  ') === 'S', 'Build an avatar for a single-name student');
$check(parent_portal_initials('') === '', 'Handle an empty avatar name');
$oneChild = parent_portal_children_summary([
    ['student_status' => 'active', 'outstanding_amount' => '1250.50'],
]);
$check(
    $oneChild === [
        'total_count' => 1,
        'active_count' => 1,
        'outstanding_cents' => 125050,
        'has_fee_access' => true,
        'outstanding_amount' => 1250.5,
    ],
    'Summarize one active linked student with visible fees'
);
$multipleChildren = parent_portal_children_summary([
    ['student_status' => 'active', 'outstanding_amount' => '100.00'],
    ['student_status' => 'inactive', 'outstanding_amount' => null],
]);
$check(
    $multipleChildren['total_count'] === 2
        && $multipleChildren['active_count'] === 1
        && $multipleChildren['outstanding_cents'] === 10000
        && $multipleChildren['outstanding_amount'] === 100,
    'Summarize multiple students without counting hidden fee values'
);
$noChildren = parent_portal_children_summary([]);
$check(
    $noChildren === [
        'total_count' => 0,
        'active_count' => 0,
        'outstanding_cents' => 0,
        'has_fee_access' => false,
        'outstanding_amount' => 0,
    ],
    'Return zero counts and omit fee data for a parent with no linked children'
);
$check(parent_portal_money_to_cents('0.09') === 9, 'Convert small fee decimals to integer cents exactly');
$check(parent_portal_money_to_cents('-12.30') === -1230, 'Preserve signed balance adjustments');
$profile = parent_portal_validate_profile_update([
    'full_name' => 'Ava Parent',
    'alternate_mobile' => '',
    'address_line1' => '10 Main Street',
    'address_line2' => '',
    'city' => 'Mumbai',
    'state' => 'Maharashtra',
    'postal_code' => '400001',
    'country' => 'India',
    'emergency_contact' => '',
    'email' => 'untrusted@example.test',
    'institution_id' => '999',
]);
$check(
    $profile['errors'] === []
        && $profile['data']['full_name'] === 'Ava Parent'
        && !array_key_exists('email', $profile['data'])
        && !array_key_exists('institution_id', $profile['data']),
    'Accept valid editable profile fields and ignore submitted authorization/contact identity'
);
$invalidProfile = parent_portal_validate_profile_update([
    'full_name' => '',
    'alternate_mobile' => 'not a number',
    'address_line1' => '',
    'address_line2' => '',
    'city' => '',
    'state' => '',
    'postal_code' => '',
    'country' => '',
    'emergency_contact' => '',
]);
$check($invalidProfile['errors'] !== [], 'Reject missing required profile name and invalid alternate number');

$serviceSource = file_get_contents(__DIR__ . '/../services/parent-portal-service.php');
$notificationServiceSource = file_get_contents(__DIR__ . '/../services/notification-service.php');
$attendanceServiceSource = file_get_contents(__DIR__ . '/../services/attendance-management-service.php');
$childrenPage = file_get_contents(__DIR__ . '/../parent/children.php');
$detailsPage = file_get_contents(__DIR__ . '/../parent/child-details.php');
$paymentPage = file_get_contents(__DIR__ . '/../parent/payments/index.php');
$feeDetailsPage = file_get_contents(__DIR__ . '/../parent/fees/view.php');
$receiptDownloadPage = file_get_contents(__DIR__ . '/../parent/receipts/download.php');
$profileUpdatePage = file_get_contents(__DIR__ . '/../parent/profile/update.php');
$announcementPage = file_get_contents(__DIR__ . '/../parent/announcements.php');
$attendancePage = file_get_contents(__DIR__ . '/../parent/attendance.php');
$dashboardPage = file_get_contents(__DIR__ . '/../parent/dashboard.php');
$dashboardStyles = file_get_contents(__DIR__ . '/../public/assets/css/pages/parent-dashboard.css');
$sidebarLayout = file_get_contents(__DIR__ . '/../includes/layouts/sidebar.php');
$admissionPdfPage = file_get_contents(__DIR__ . '/../parent/admissions/download-pdf.php');
$parentAuth = file_get_contents(__DIR__ . '/../includes/auth.php');
$schema = file_get_contents(__DIR__ . '/../database/schema.sql');
$readable = is_string($serviceSource)
    && is_string($notificationServiceSource)
    && is_string($attendanceServiceSource)
    && is_string($childrenPage)
    && is_string($detailsPage)
    && is_string($paymentPage)
    && is_string($feeDetailsPage)
    && is_string($receiptDownloadPage)
    && is_string($profileUpdatePage)
    && is_string($announcementPage)
    && is_string($attendancePage)
    && is_string($dashboardPage)
    && is_string($dashboardStyles)
    && is_string($sidebarLayout)
    && is_string($admissionPdfPage)
    && is_string($parentAuth)
    && is_string($schema);
$check($readable, 'Read parent portal implementation and actual schema');

if ($readable) {
    $check(
        str_contains($serviceSource, "u.user_type = 'parent'")
            && str_contains($serviceSource, "u.status = 'active'")
            && str_contains($serviceSource, 'u.email_verified_at IS NOT NULL')
            && str_contains($serviceSource, "p.status = 'active'"),
        'Require an active, verified parent account and profile'
    );
    $check(
        str_contains($serviceSource, 'WHERE u.id = ? AND u.institution_id = ?')
            && str_contains($serviceSource, "i.status = 'active'"),
        'Resolve the parent from authenticated user and institution scope'
    );
    $check(
        str_contains($serviceSource, 'WHERE p.id = ? AND p.institution_id = ?')
            && str_contains($serviceSource, "psr.status = 'active'")
            && str_contains($serviceSource, 'psr.student_id'),
        'List only this parent’s active, same-institution student links'
    );
    $check(
        str_contains($serviceSource, 'AND psr.can_view_academics = 1 AND s.id = ?'),
        'Reject child detail access without an active academic-view relationship'
    );
    $check(
        str_contains($serviceSource, 'CASE WHEN psr.can_view_academics = 1')
            && str_contains($childrenPage, 'Details not enabled for this link'),
        'Avoid exposing academic fields when a relationship lacks that permission'
    );
    $check(
        str_contains($serviceSource, 'psr.can_view_fees')
            && str_contains($serviceSource, "sfa.status IN ('active', 'completed', 'suspended')")
            && str_contains($serviceSource, 'SUM(sfa.outstanding_amount)'),
        'Limit fee totals to fee-enabled links and persisted non-draft assignments'
    );
    $check(
        str_contains($serviceSource, "pay.status IN ('success', 'partial_refund', 'refunded')")
            && str_contains($serviceSource, 'pay.verified_at IS NOT NULL')
            && str_contains($serviceSource, "WHERE institution_id = ? AND status = 'processed'")
            && str_contains($serviceSource, 'refund_totals.refunded_amount')
            && str_contains($serviceSource, "WHEN pay.status = 'refunded' THEN 0"),
        'Show only verified payment history and account for processed refunds'
    );
    $check(
        str_contains($schema, 'CREATE TABLE IF NOT EXISTS parent_student_relationships')
            && str_contains($schema, "status ENUM('active','inactive','revoked')")
            && str_contains($schema, 'can_view_academics')
            && str_contains($schema, 'can_view_fees'),
        'Use the schema-defined relationship states and visibility flags'
    );
    $check(
        str_contains($schema, 'CREATE TABLE IF NOT EXISTS classes')
            && str_contains($schema, 'CREATE TABLE IF NOT EXISTS batches')
            && str_contains($schema, 'CREATE TABLE IF NOT EXISTS academic_years')
            && !str_contains($schema, 'CREATE TABLE IF NOT EXISTS sections'),
        'Use schema-supported class, batch, and academic-year values without inventing sections'
    );
    $check(
        str_contains($dashboardPage, "parent_portal_get_children(")
            && str_contains($dashboardPage, "parent_portal_children_summary(")
            && str_contains($dashboardPage, "count(\$dueSoon)")
            && str_contains($dashboardPage, "\$summary['outstanding_amount']")
            && str_contains($dashboardPage, "\$payments['payments']")
            && str_contains($dashboardPage, "\$announcements"),
        'Keep all dashboard summary and activity values sourced from parent portal data'
    );
    $check(
        str_contains($dashboardPage, 'No payments recorded')
            && str_contains($dashboardPage, 'No current announcements')
            && str_contains($dashboardPage, 'No upcoming fee deadlines')
            && str_contains($dashboardPage, 'View Fee Details')
            && str_contains($dashboardPage, "\$installment['status']"),
        'Render useful empty states and per-installment status and details links'
    );
    $check(
        str_contains($dashboardPage, "route_url('/parent/profile/index')")
            && str_contains($dashboardPage, "route_url('/parent/children')")
            && str_contains($dashboardPage, "route_url('/parent/payments/index')")
            && str_contains($dashboardPage, "route_url('/parent/announcements')")
            && str_contains($dashboardStyles, 'grid-template-columns: repeat(4, minmax(0, 1fr))')
            && str_contains($dashboardStyles, '@media (max-width: 767.98px)'),
        'Preserve dashboard destinations and define responsive four-card and stacked layouts'
    );
    $check(
        str_contains($sidebarLayout, "\$context === 'parent' ? null : 'Workspace'")
            && str_contains($dashboardPage, 'Parent Workspace'),
        'Avoid duplicating the parent workspace label while retaining the dashboard section label'
    );
    $check(
        str_contains($childrenPage, 'require_placeholder_route_access()')
            && str_contains($detailsPage, 'require_placeholder_route_access()')
            && str_contains($detailsPage, 'http_response_code(404)')
            && str_contains($detailsPage, 'parent_portal_get_student('),
        'Require parent route authorization and server-side student access checks'
    );
    $check(
        str_contains($admissionPdfPage, 'parent_portal_require_current_parent($db)')
            && str_contains($admissionPdfPage, 'parent_portal_get_student($db, $parentId, $institutionId, $studentId)')
            && str_contains($admissionPdfPage, "\$parent['parent_id']")
            && !str_contains($admissionPdfPage, "\$_GET['parent_id']")
            && str_contains($admissionPdfPage, "\$student['parents'] = array_values(array_filter(")
            && str_contains($serviceSource, "\$statement->bind_param('iii', \$parentId, \$institutionId, \$studentId)")
            && str_contains($parentAuth, "\$scriptPath === '/parent/admissions/download-pdf.php'")
            && str_contains($parentAuth, "\$permission = 'students.view'"),
        'Authorize registration PDFs using the session parent and active same-institution child link, never a submitted parent ID'
    );
    $check(
        str_contains($detailsPage, 'View Registration Form')
            && str_contains($detailsPage, 'Download PDF')
            && str_contains($detailsPage, "route_url('/parent/admissions/download-pdf')"),
        'Show registration PDF actions on authorized child details'
    );
    $check(
        str_contains($childrenPage, 'No linked children yet')
            && str_contains($childrenPage, 'parent_portal_get_children(')
            && str_contains($detailsPage, 'parent_portal_get_parent_context('),
        'Render database-backed child listings and a no-links empty state'
    );
    $check(
        str_contains($serviceSource, '$values = [$institutionId];')
            && str_contains($serviceSource, '$queryValues = [$parentId, ...$values, $pageSize, $offset]')
            && str_contains($serviceSource, 'payment_installment.student_fee_assignment_id = ?')
            && str_contains($serviceSource, "ref.status = \\'processed\\'")
            && parent_portal_net_payment_amount('250.00', '50.00', 'partial_refund') === '200.00'
            && parent_portal_net_payment_amount('250.00', '250.00', 'refunded') === '0.00'
            && str_contains($paymentPage, "['refunded_amount']"),
        'Bind payment list parameters in placeholder order and filter assignment payment history accurately'
    );
    $check(
        str_contains($notificationServiceSource, 'n.recipient_user_id = ?')
            && str_contains($notificationServiceSource, 'psr.parent_id = ?')
            && str_contains($notificationServiceSource, "psr.status = 'active'")
            && str_contains($serviceSource, 'notification_service_get_parent_notifications('),
        'Restrict notifications to the authenticated recipient and active linked students'
    );
    $check(
        str_contains($announcementPage, 'parent_portal_get_announcements(')
            && str_contains($serviceSource, "a.status = 'published'")
            && str_contains($serviceSource, 'announcement_batches'),
        'Display published global and child-batch announcements only'
    );
    $check(
        str_contains($attendancePage, 'parent_portal_require_current_parent($db)')
            && str_contains($attendancePage, 'attendance_parent_get_children(')
            && str_contains($attendancePage, 'attendance_parent_get_summary(')
            && str_contains($attendancePage, 'attendance_parent_list_records(')
            && !str_contains($attendancePage, 'method="post"')
            && str_contains($sidebarLayout, "['Attendance', '/parent/attendance'")
            && str_contains($schema, 'CREATE TABLE IF NOT EXISTS attendance_records'),
        'Render a read-only attendance page behind parent authentication with existing navigation'
    );
    $check(
        str_contains($attendanceServiceSource, 'psr.parent_id = ?')
            && str_contains($attendanceServiceSource, 'psr.can_view_academics = 1')
            && str_contains($attendanceServiceSource, 'ar.institution_id = ?')
            && str_contains($attendanceServiceSource, "u.email_verified_at IS NOT NULL")
            && str_contains($attendanceServiceSource, "SUM(ar.status IN ('present','late'))")
            && str_contains($attendanceServiceSource, 'LIMIT ? OFFSET ?'),
        'Scope attendance rows and summary to the authenticated parent and paginate safely'
    );
    $check(
        str_contains($attendancePage, 'Attendance records not found.')
            && str_contains($attendancePage, 'attendance_validate_parent_filters(')
            && str_contains($attendancePage, 'No attendance records found')
            && str_contains($attendancePage, 'No linked students available'),
        'Reject inaccessible child IDs and provide validated filters and empty states'
    );
    $check(
        str_contains($profileUpdatePage, 'require_valid_csrf_token()')
            && str_contains($profileUpdatePage, 'parent_portal_update_profile(')
            && str_contains($serviceSource, "'parent.self_profile_updated'"),
        'Protect profile mutation with CSRF and audit logging'
    );
    $check(
        str_contains($receiptDownloadPage, 'parent_portal_get_receipt(')
            && str_contains($receiptDownloadPage, 'parent_portal_require_current_parent($db)')
            && str_contains($receiptDownloadPage, 'receipt_service_pdf_render($receipt)'),
        'Authorize receipt access and generate its PDF from saved payment records'
    );
}

if ($failures !== []) {
    fwrite(STDERR, 'FAIL: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'PASS: ' . $checks . ' parent portal checks.' . PHP_EOL);
