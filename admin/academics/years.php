<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/admin-panel-service.php';

require_http_methods(['GET', 'POST']);
require_placeholder_route_access();
$db = db_connect();
$institutionId = admin_panel_institution_id();
$user = current_user();
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    try {
        if (($_POST['action'] ?? '') === 'create') {
            $values = admin_panel_validate_academic_year($_POST);
            admin_panel_create_academic_year($db, $institutionId, (int) $user['id'], $values);
            set_flash('success', 'Academic year created as a draft.');
        } elseif (($_POST['action'] ?? '') === 'status') {
            $yearId = filter_var($_POST['year_id'] ?? null, FILTER_VALIDATE_INT);
            $status = $_POST['status'] ?? null;
            if ($yearId === false || !is_string($status)) {
                throw new InvalidArgumentException('The academic-year action is invalid.');
            }
            admin_panel_set_academic_year_status($db, $institutionId, (int) $user['id'], (int) $yearId, $status);
            set_flash('success', 'Academic-year status updated.');
        } else {
            throw new InvalidArgumentException('Select a supported academic-year action.');
        }
        safe_redirect('/admin/academics/years', 303);
    } catch (InvalidArgumentException | DomainException | OutOfBoundsException $exception) {
        $errors[] = $exception->getMessage();
    } catch (Throwable $exception) {
        log_error('admin_academic_year_save_failed', [
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
        ]);
        $errors[] = 'The academic year could not be saved. Check for a duplicate name and try again.';
    }
}
$years = admin_panel_simple_list($db, $institutionId, 'academic_years');
$canCreateYear = user_has_compatible_permission('academic_years.create', 'academics.manage');
$canEditYear = user_has_compatible_permission('academic_years.edit', 'academics.manage');
$yearCounts = ['total' => count($years), 'draft' => 0, 'active' => 0, 'closed' => 0];
foreach ($years as $year) {
    if (isset($yearCounts[$year['status']])) {
        $yearCounts[$year['status']]++;
    }
}
render_app_page_start(
    'Academic Years',
    'Plan academic sessions and manage their lifecycle without deleting historical references.',
    'admin',
    ['admin-dashboard.css', 'admin-academic-years.css']
);
?>
<div class="academic-years-page">
<?php echo render_flash_messages(); ?>
<?php foreach ($errors as $error): ?><div class="alert alert-danger" role="alert"><?php echo e($error); ?></div><?php endforeach; ?>
<section class="academic-year-summary" aria-label="Academic year summary">
    <article class="academic-year-summary-card">
        <span class="academic-year-summary-icon"><i class="bi bi-calendar3" aria-hidden="true"></i></span>
        <div><span>Total sessions</span><strong><?php echo e((string) $yearCounts['total']); ?></strong></div>
    </article>
    <article class="academic-year-summary-card academic-year-summary-active">
        <span class="academic-year-summary-icon"><i class="bi bi-calendar-check" aria-hidden="true"></i></span>
        <div><span>Active</span><strong><?php echo e((string) $yearCounts['active']); ?></strong></div>
    </article>
    <article class="academic-year-summary-card academic-year-summary-draft">
        <span class="academic-year-summary-icon"><i class="bi bi-pencil-square" aria-hidden="true"></i></span>
        <div><span>Drafts</span><strong><?php echo e((string) $yearCounts['draft']); ?></strong></div>
    </article>
    <article class="academic-year-summary-card academic-year-summary-closed">
        <span class="academic-year-summary-icon"><i class="bi bi-lock" aria-hidden="true"></i></span>
        <div><span>Closed</span><strong><?php echo e((string) $yearCounts['closed']); ?></strong></div>
    </article>
</section>
<?php if ($canCreateYear): ?>
    <section class="dashboard-panel academic-year-create-card">
        <div class="academic-year-section-heading">
            <span class="academic-year-section-icon" aria-hidden="true"><i class="bi bi-calendar-plus"></i></span>
            <div>
                <span class="academic-year-kicker">Set up a session</span>
                <h2>Add academic year</h2>
                <p>New years start as drafts. Activate them when your institution is ready.</p>
            </div>
        </div>
        <form method="post" action="<?php echo e(route_url('/admin/academics/years')); ?>" class="academic-year-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create">
            <label class="admin-form-field">Academic year name
                <input class="form-control" name="name" maxlength="120" placeholder="e.g. 2026–2027" required value="<?php echo e(is_string($_POST['name'] ?? null) ? $_POST['name'] : ''); ?>">
            </label>
            <label class="admin-form-field">Starts on
                <input class="form-control" type="date" name="start_date" required value="<?php echo e(is_string($_POST['start_date'] ?? null) ? $_POST['start_date'] : ''); ?>">
            </label>
            <label class="admin-form-field">Ends on
                <input class="form-control" type="date" name="end_date" required value="<?php echo e(is_string($_POST['end_date'] ?? null) ? $_POST['end_date'] : ''); ?>">
            </label>
            <div class="academic-year-form-action"><button class="btn btn-cobalt" type="submit"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Create draft year</button></div>
        </form>
    </section>
<?php endif; ?>
<section class="dashboard-panel academic-year-list-card" aria-labelledby="academic-year-list-title">
    <div class="academic-year-list-heading">
        <div>
            <span class="academic-year-kicker">Year setup</span>
            <h2 id="academic-year-list-title">Academic year directory</h2>
            <p>Manage session dates and open or close years for your institution.</p>
        </div>
        <span class="academic-year-count"><i class="bi bi-collection" aria-hidden="true"></i><?php echo e((string) $yearCounts['total']); ?> <?php echo $yearCounts['total'] === 1 ? 'session' : 'sessions'; ?></span>
    </div>
    <?php if ($years === []): ?>
        <div class="academic-year-empty">
            <span aria-hidden="true"><i class="bi bi-calendar2-week"></i></span>
            <strong>No academic years yet</strong>
            <p>Create a draft year to start configuring course fee structures.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive academic-year-table-wrap">
            <table class="table admin-panel-table academic-year-table">
                <thead><tr><th scope="col">Academic year</th><th scope="col">Date range</th><th scope="col">Status</th><th scope="col">Next action</th></tr></thead>
                <tbody>
                    <?php foreach ($years as $year):
                        $statusClass = match ($year['status']) {
                            'active' => 'active',
                            'draft' => 'draft',
                            'closed' => 'closed',
                            default => 'other',
                        };
                        ?>
                        <tr>
                            <td><span class="academic-year-name"><?php echo e((string) $year['name']); ?></span><small>Session #<?php echo e((string) $year['id']); ?></small></td>
                            <td><span class="academic-year-date-range"><i class="bi bi-calendar-event" aria-hidden="true"></i><?php echo e(date('M j, Y', strtotime((string) $year['start_date']))); ?><span aria-hidden="true">–</span><?php echo e(date('M j, Y', strtotime((string) $year['end_date']))); ?></span></td>
                            <td><span class="academic-year-status academic-year-status-<?php echo e($statusClass); ?>"><i aria-hidden="true"></i><?php echo e(ucfirst((string) $year['status'])); ?></span></td>
                            <td><?php if (!$canEditYear): ?><span class="text-muted">No action available</span><?php elseif ($year['status'] === 'draft'): ?><form method="post" action="<?php echo e(route_url('/admin/academics/years')); ?>"><?php echo csrf_field(); ?><input type="hidden" name="action" value="status"><input type="hidden" name="year_id" value="<?php echo e((string) $year['id']); ?>"><input type="hidden" name="status" value="active"><button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-play-fill me-1" aria-hidden="true"></i>Activate</button></form><?php elseif ($year['status'] === 'active'): ?><form method="post" action="<?php echo e(route_url('/admin/academics/years')); ?>"><?php echo csrf_field(); ?><input type="hidden" name="action" value="status"><input type="hidden" name="year_id" value="<?php echo e((string) $year['id']); ?>"><input type="hidden" name="status" value="closed"><button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-lock me-1" aria-hidden="true"></i>Close year</button></form><?php else: ?><span class="academic-year-locked"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Locked</span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
</div>
<?php render_app_page_end('admin'); ?>
