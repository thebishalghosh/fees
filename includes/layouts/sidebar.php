<?php
declare(strict_types=1);

function render_sidebar_nav(string $context = 'admin'): void
{
    $menus = [
        'admin' => [
            ['Overview', '/admin/dashboard', 'bi-grid-1x2', 'dashboard.view', 'Workspace'],
            ['Students', '/admin/students/index', 'bi-people', 'students.view', 'People'],
            ['Parents', '/admin/parents/index', 'bi-person-hearts', 'parents.view', 'People'],
            ['Staff & administrators', '/admin/staff/index', 'bi-person-badge', 'staff.manage', 'People'],
            ['Role permissions', '/admin/staff/permissions', 'bi-shield-lock', 'staff.manage', 'People'],
            ['Courses', '/admin/courses/index', 'bi-journals', 'courses.manage', 'Academics'],
            ['Batches', '/admin/batches/index', 'bi-collection', 'courses.manage', 'Academics'],
            ['Academic years', '/admin/academics/years', 'bi-calendar3', 'academics.manage', 'Academics'],
            ['Classes', '/admin/academics/classes', 'bi-building', 'academics.manage', 'Academics'],
            ['Attendance', '/admin/academics/attendance', 'bi-calendar-check', 'academics.manage', 'Academics'],
            ['Timetables', '/admin/academics/timetables', 'bi-calendar-week', 'academics.manage', 'Academics'],
            ['Examinations', '/admin/academics/examinations', 'bi-journal-check', 'academics.manage', 'Academics'],
            ['Holidays', '/admin/academics/holidays', 'bi-calendar2-minus', 'academics.manage', 'Academics'],
            ['Fee structures', '/admin/fees/structures', 'bi-cash-stack', 'fees.view', 'Finance'],
            ['Fee categories', '/admin/fees/categories', 'bi-tags', 'fees.manage', 'Finance'],
            ['Discounts & waivers', '/admin/fees/discounts', 'bi-percent', 'fees.view', 'Finance'],
            ['Payment plans', '/admin/fees/payment-plans', 'bi-calendar-range', 'fees.view', 'Finance'],
            ['Student fee assignments', '/admin/fees/assignments', 'bi-person-plus', 'fees.manage', 'Finance'],
            ['Installments', '/admin/fees/installments', 'bi-calendar2-check', 'fees.view', 'Finance'],
            ['Adjustments', '/admin/fees/adjustments', 'bi-sliders', 'fees.view', 'Finance'],
            ['Payments', '/admin/payments/index', 'bi-credit-card', 'payments.view', 'Finance'],
            ['Refunds', '/admin/payments/refunds', 'bi-arrow-counterclockwise', 'payments.refund', 'Finance'],
            ['Receipts', '/admin/receipts/index', 'bi-receipt', 'receipts.view', 'Finance'],
            ['Collection reports', '/admin/reports/collections', 'bi-bar-chart', 'reports.view', 'Reports'],
            ['Outstanding reports', '/admin/reports/outstanding-fees', 'bi-hourglass-split', 'reports.view', 'Reports'],
            ['Student reports', '/admin/reports/student-wise', 'bi-person-lines-fill', 'reports.view', 'Reports'],
            ['Parent reports', '/admin/reports/parent-wise', 'bi-people', 'reports.view', 'Reports'],
            ['Announcements', '/admin/notifications/index', 'bi-megaphone', 'notifications.manage', 'Communication'],
            ['Audit log', '/admin/audit/index', 'bi-journal-text', 'audit.view', 'Administration'],
            ['Institution profile', '/admin/settings/general', 'bi-gear', 'settings.manage', 'Administration'],
            ['Payment configuration', '/admin/settings/payment-settings', 'bi-credit-card-2-front', 'settings.manage', 'Administration'],
        ],
        'parent' => [
            ['Overview', '/parent/dashboard', 'bi-grid-1x2'],
            ['My children', '/parent/children', 'bi-people'],
            ['Fee details', '/parent/fees/index', 'bi-cash-stack'],
            ['Payments', '/parent/payments/index', 'bi-credit-card'],
            ['Receipts', '/parent/receipts/index', 'bi-receipt'],
            ['Examination results', '/parent/academics/examinations', 'bi-journal-check'],
            ['Attendance', '/parent/attendance', 'bi-calendar-check'],
            ['Announcements', '/parent/announcements', 'bi-megaphone'],
            ['Notifications', '/parent/notifications/index', 'bi-bell'],
            ['Profile', '/parent/profile/index', 'bi-person-circle'],
            ['Account', '/parent/account', 'bi-shield-lock'],
            ['Help & contact', '/parent/help', 'bi-question-circle'],
        ],
    ];
    $items = $menus[$context] ?? $menus['admin'];
    $currentPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
    $basePath = rtrim((string) (parse_url(APP_URL, PHP_URL_PATH) ?: ''), '/');
    if ($basePath !== '' && str_starts_with($currentPath, $basePath . '/')) {
        $currentPath = substr($currentPath, strlen($basePath));
    }
    ?>
<aside class="app-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="app-sidebar" aria-labelledby="app-sidebar-title">
    <div class="offcanvas-header d-lg-none">
        <h2 class="offcanvas-title h6" id="app-sidebar-title"><?php echo e(ucfirst($context)); ?> navigation</h2>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#app-sidebar" aria-label="Close navigation"></button>
    </div>
    <div class="offcanvas-body d-flex flex-column">
        <?php if ($context !== 'admin'): ?><div class="sidebar-heading">Workspace</div><?php endif; ?>
        <nav class="nav flex-column sidebar-nav" aria-label="<?php echo e(ucfirst($context)); ?> navigation">
            <?php $lastGroup = null; ?>
            <?php foreach ($items as $item): ?>
                <?php [$label, $href, $icon] = array_slice($item, 0, 3); ?>
                <?php if (isset($item[3]) && !user_has_permission($item[3])) { continue; } ?>
                <?php $group = $item[4] ?? ($context === 'parent' ? null : 'Workspace'); ?>
                <?php if ($group !== $lastGroup): ?><div class="sidebar-heading mt-3"><?php echo e($group); ?></div><?php $lastGroup = $group; ?><?php endif; ?>
                <a class="nav-link<?php echo $currentPath === $href ? ' active' : ''; ?>" href="<?php echo e(route_url($href)); ?>"<?php echo $currentPath === $href ? ' aria-current="page"' : ''; ?>>
                    <i class="bi <?php echo e($icon); ?>" aria-hidden="true"></i><span><?php echo e($label); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-note mt-auto">
            <i class="bi bi-shield-check" aria-hidden="true"></i>
            <span>Access controls are enforced by the application, not by navigation visibility.</span>
        </div>
    </div>
</aside>
<?php
}
