<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/student-management-service.php';

require_http_methods(['GET', 'HEAD']);
require_placeholder_route_access();
$institutionId = student_management_institution_id();
$db = db_connect();
$search = isset($_GET['search']) && is_string($_GET['search']) ? trim($_GET['search']) : '';
if (mb_strlen($search, 'UTF-8') > 255) {
    $search = mb_substr($search, 0, 255, 'UTF-8');
}
$status = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : '';
if (!in_array($status, ['', ...STUDENT_MANAGEMENT_STATUSES], true)) {
    $status = '';
}
$classId = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT);
$classId = is_int($classId) && $classId > 0 ? $classId : 0;
$batchId = filter_input(INPUT_GET, 'batch_id', FILTER_VALIDATE_INT);
$batchId = is_int($batchId) && $batchId > 0 ? $batchId : 0;
$requestedPage = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$page = is_int($requestedPage) && $requestedPage > 0 ? $requestedPage : 1;
$summary = student_management_get_summary($db, $institutionId);
$result = student_management_list($db, $institutionId, $search, $status, $classId, $batchId, $page);
$options = student_management_get_options($db, $institutionId);
$canManage = user_has_permission('students.manage');

render_app_page_start(
    'Students Management',
    'Manage student records, academic placement and parent relationships.',
    'admin',
    ['admin-students.css']
);
?>
<?php echo render_flash_messages(); ?>
<div class="students-page-toolbar">
    <nav aria-label="Breadcrumb">
        <ol class="breadcrumb students-breadcrumb">
            <li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li>
            <li class="breadcrumb-item active" aria-current="page">Students</li>
        </ol>
    </nav>
    <?php if ($canManage): ?>
        <a class="students-add-button" href="<?php echo e(route_url('/admin/students/create')); ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i>Add Student</a>
    <?php endif; ?>
</div>

<section class="students-summary-grid" aria-label="Student account summary">
    <?php foreach ([
        ['Total Students', 'total_count', 'bi-people', 'students-icon-blue'],
        ['Active Students', 'active_count', 'bi-person-check', 'students-icon-green'],
        ['Inactive Students', 'inactive_count', 'bi-person-dash', 'students-icon-amber'],
        ['Without a Linked Parent', 'without_parent_count', 'bi-person-exclamation', 'students-icon-violet'],
    ] as [$label, $key, $icon, $class]): ?>
        <article class="students-summary-card">
            <span class="students-summary-icon <?php echo e($class); ?>" aria-hidden="true"><i class="bi <?php echo e($icon); ?>"></i></span>
            <div><span><?php echo e($label); ?></span><strong><?php echo e(number_format($summary[$key])); ?></strong></div>
        </article>
    <?php endforeach; ?>
</section>

<section class="students-list-panel" aria-labelledby="students-directory-title">
    <div class="students-list-heading">
        <div>
            <div class="students-title-row">
                <h2 id="students-directory-title">Student Directory</h2>
                <span class="students-live-banner"><i class="bi bi-database-check" aria-hidden="true"></i> Live institution data</span>
            </div>
            <p>Search student names or IDs and linked parent contact details.</p>
        </div>
        <span class="students-result-count" aria-live="polite"><strong><?php echo e(number_format($result['total'])); ?></strong> records</span>
    </div>

    <form class="students-filter-toolbar" action="<?php echo e(route_url('/admin/students/index')); ?>" method="get" role="search">
        <label class="students-search-field">
            <span class="visually-hidden">Search students by name, ID, or parent contact</span>
            <i class="bi bi-search" aria-hidden="true"></i>
            <input class="form-control" type="search" name="search" value="<?php echo e($search); ?>" placeholder="Search name, student ID or parent contact…" autocomplete="off">
        </label>
        <label class="visually-hidden" for="students-status-filter">Filter by student status</label>
        <select class="form-select students-filter-select" id="students-status-filter" name="status">
            <option value="">All Status</option>
            <?php foreach (STUDENT_MANAGEMENT_STATUSES as $studentStatus): ?>
                <option value="<?php echo e($studentStatus); ?>"<?php echo $status === $studentStatus ? ' selected' : ''; ?>><?php echo e(ucfirst($studentStatus)); ?></option>
            <?php endforeach; ?>
        </select>
        <label class="visually-hidden" for="students-class-filter">Filter by class</label>
        <select class="form-select students-filter-select" id="students-class-filter" name="class_id">
            <option value="">All Classes</option>
            <?php foreach ($options['classes'] as $class): ?>
                <option value="<?php echo e((string) $class['id']); ?>"<?php echo $classId === $class['id'] ? ' selected' : ''; ?>><?php echo e($class['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <label class="visually-hidden" for="students-batch-filter">Filter by batch</label>
        <select class="form-select students-filter-select" id="students-batch-filter" name="batch_id">
            <option value="">All Batches</option>
            <?php foreach ($options['batches'] as $batch): ?>
                <option value="<?php echo e((string) $batch['id']); ?>"<?php echo $batchId === $batch['id'] ? ' selected' : ''; ?>><?php echo e($batch['name'] . ' — ' . $batch['class_name']); ?></option>
            <?php endforeach; ?>
        </select>
        <div class="students-filter-actions">
            <button class="students-filter-submit" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i>Apply</button>
            <a class="students-filter-reset" href="<?php echo e(route_url('/admin/students/index')); ?>"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Reset</a>
        </div>
    </form>

    <div class="table-responsive students-table-scroll">
        <table class="table students-table align-middle">
            <thead>
                <tr>
                    <th scope="col">Student</th>
                    <th scope="col">Student ID</th>
                    <th scope="col">Course / Class / Batch</th>
                    <th scope="col">Linked Parents</th>
                    <th scope="col">Admission Date</th>
                    <th scope="col">Status</th>
                    <th scope="col"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result['students'] === []): ?>
                    <tr><td colspan="7">
                        <div class="students-empty-state">
                            <span aria-hidden="true"><i class="bi bi-search"></i></span>
                            <strong><?php echo $result['total'] === 0 && $search === '' && $status === '' && $classId === 0 && $batchId === 0 ? 'No students found' : 'No students match these filters'; ?></strong>
                            <p><?php echo $result['total'] === 0 ? 'Student records added to this institution will appear here.' : 'Try another search or reset the filters.'; ?></p>
                            <?php if ($result['total'] > 0): ?><a class="students-empty-reset" href="<?php echo e(route_url('/admin/students/index')); ?>">Reset filters</a><?php endif; ?>
                        </div>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($result['students'] as $student):
                        $initials = implode('', array_map(
                            static fn (string $part): string => strtoupper(substr($part, 0, 1)),
                            array_filter(explode(' ', trim((string) $student['full_name'])))
                        ));
                        $statusClass = in_array($student['status'], ['active', 'admitted', 'prospect'], true)
                            ? $student['status']
                            : 'inactive';
                    ?>
                        <tr>
                            <td><div class="students-identity"><span class="students-avatar" aria-hidden="true"><?php echo e($initials); ?></span><span class="students-name"><?php echo e($student['full_name']); ?></span></div></td>
                            <td><span class="students-code"><?php echo e($student['student_code']); ?></span></td>
                            <td>
                                <div class="students-placement">
                                    <span><?php echo e($student['course_name'] ?? 'Course not assigned'); ?></span>
                                    <small><?php echo e($student['class_name'] ?? 'Class not assigned'); ?><?php echo $student['batch_name'] !== null ? ' · ' . e($student['batch_name']) : ''; ?></small>
                                </div>
                            </td>
                            <td><span class="students-parent-names"><?php echo e($student['parent_names'] ?? 'No linked parent'); ?></span></td>
                            <td><time datetime="<?php echo e((string) $student['admission_date']); ?>"><?php echo e(date('j M Y', strtotime((string) $student['admission_date']))); ?></time></td>
                            <td><span class="students-status students-status-<?php echo e($statusClass); ?>"><i aria-hidden="true"></i><?php echo e(ucfirst((string) $student['status'])); ?></span></td>
                            <td>
                                <div class="students-row-actions" aria-label="Actions for <?php echo e($student['full_name']); ?>">
                                    <a class="students-action-button" title="View Student" aria-label="View <?php echo e($student['full_name']); ?>" href="<?php echo e(route_url('/admin/students/view') . '?id=' . $student['id']); ?>"><i class="bi bi-eye" aria-hidden="true"></i></a>
                                    <?php if ($canManage): ?>
                                        <a class="students-action-button" title="Edit Student" aria-label="Edit <?php echo e($student['full_name']); ?>" href="<?php echo e(route_url('/admin/students/edit') . '?id=' . $student['id']); ?>"><i class="bi bi-pencil" aria-hidden="true"></i></a>
                                        <?php if (in_array($student['status'], ['prospect', 'admitted', 'active', 'inactive'], true)): ?>
                                            <form action="<?php echo e(route_url('/admin/students/toggle-status')); ?>" method="post" onsubmit="return confirm('Change this student status? Student and financial history will be preserved.');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="student_id" value="<?php echo e((string) $student['id']); ?>">
                                                <button class="students-action-button<?php echo $student['status'] === 'active' ? ' is-danger' : ''; ?>" type="submit" title="<?php echo $student['status'] === 'active' ? 'Deactivate' : 'Activate'; ?> Student" aria-label="<?php echo $student['status'] === 'active' ? 'Deactivate' : 'Activate'; ?> <?php echo e($student['full_name']); ?>"><i class="bi <?php echo $student['status'] === 'active' ? 'bi-person-dash' : 'bi-person-check'; ?>" aria-hidden="true"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if (user_has_permission('students.view')): ?>
                                        <a class="students-action-button" title="View Registration Form PDF" aria-label="View registration form for <?php echo e($student['full_name']); ?>" href="<?php echo e(route_url('/admin/students/admission-form') . '?id=' . $student['id']); ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i></a>
                                        <a class="students-action-button" title="Download Registration Form PDF" aria-label="Download registration form for <?php echo e($student['full_name']); ?>" href="<?php echo e(route_url('/admin/students/admission-form') . '?id=' . $student['id'] . '&download=1'); ?>"><i class="bi bi-download" aria-hidden="true"></i></a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($result['total_pages'] > 1): ?>
        <nav class="students-pagination" aria-label="Student list pages">
            <span>Page <?php echo e((string) $result['page']); ?> of <?php echo e((string) $result['total_pages']); ?></span>
            <div>
                <?php if ($result['page'] > 1): ?>
                    <a href="<?php echo e(route_url('/admin/students/index') . '?' . http_build_query(['search' => $search, 'status' => $status, 'class_id' => $classId ?: '', 'batch_id' => $batchId ?: '', 'page' => $result['page'] - 1])); ?>"><i class="bi bi-chevron-left" aria-hidden="true"></i>Previous</a>
                <?php endif; ?>
                <?php if ($result['page'] < $result['total_pages']): ?>
                    <a href="<?php echo e(route_url('/admin/students/index') . '?' . http_build_query(['search' => $search, 'status' => $status, 'class_id' => $classId ?: '', 'batch_id' => $batchId ?: '', 'page' => $result['page'] + 1])); ?>">Next<i class="bi bi-chevron-right" aria-hidden="true"></i></a>
                <?php endif; ?>
            </div>
        </nav>
    <?php endif; ?>
</section>
<?php render_app_page_end('admin'); ?>
