<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/page-placeholder.php';
require_once __DIR__ . '/../../services/admin-panel-service.php';

require_http_methods(['GET', 'POST']);
require_placeholder_route_access();
$db = db_connect();
$institutionId = admin_panel_institution_id();
$batches = admin_panel_get_announcement_batches($db, $institutionId);
$rawAnnouncementId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$announcementId = $rawAnnouncementId === false || $rawAnnouncementId === null
    ? null
    : (int) $rawAnnouncementId;
if (array_key_exists('id', $_GET) && ($announcementId === null || $announcementId < 1)) {
    http_response_code(404);
    exit('Announcement not found.');
}
$announcement = $announcementId === null
    ? null
    : admin_panel_announcement($db, $institutionId, $announcementId);
if ($announcementId !== null && $announcement === null) {
    http_response_code(404);
    exit('Announcement not found.');
}
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    $title = $_POST['title'] ?? '';
    $message = $_POST['message'] ?? '';
    $status = $_POST['status'] ?? 'draft';
    $publishAt = $_POST['publish_at'] ?? '';
    $expiresAt = $_POST['expires_at'] ?? '';
    $rawBatchIds = $_POST['batch_ids'] ?? [];
    if (!is_string($title) || !is_string($message) || !is_string($status)
        || !is_string($publishAt) || !is_string($expiresAt) || !is_array($rawBatchIds)) {
        $errors[] = 'The announcement form contains invalid values.';
    } else {
        $batchIds = [];
        foreach ($rawBatchIds as $rawId) {
            $validId = is_string($rawId) ? filter_var($rawId, FILTER_VALIDATE_INT) : false;
            if ($validId === false || $validId < 1) {
                $errors[] = 'Select valid target batches.';
                break;
            }
            $batchIds[] = (int) $validId;
        }
        if ($errors === []) {
            try {
                $user = current_user();
                admin_panel_save_announcement(
                    $db,
                    $institutionId,
                    (int) $user['id'],
                    $title,
                    $message,
                    $status,
                    $publishAt === '' ? null : $publishAt,
                    $expiresAt === '' ? null : $expiresAt,
                    $batchIds,
                    $announcementId
                );
                set_flash('success', $announcementId === null ? 'Announcement saved.' : 'Announcement updated.');
                safe_redirect('/admin/notifications/index', 303);
            } catch (InvalidArgumentException | OutOfBoundsException $exception) {
                $errors[] = $exception->getMessage();
            } catch (Throwable $exception) {
                log_error('admin_announcement_save_failed', [
                    'error_type' => get_class($exception),
                    'error_code' => $exception->getCode(),
                ]);
                $errors[] = 'The announcement could not be saved. Please try again.';
            }
        }
    }
}

$isEditing = $announcementId !== null;
$selectedBatchIds = [];
$rawSelectedBatchIds = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (is_array($_POST['batch_ids'] ?? null) ? $_POST['batch_ids'] : [])
    : ($announcement['batch_ids'] ?? []);
foreach ($rawSelectedBatchIds as $selectedBatchId) {
    if (is_string($selectedBatchId) || is_int($selectedBatchId)) {
        $selectedBatchIds[] = (string) $selectedBatchId;
    }
}
$announcementPublishAt = $announcement['publish_at'] ?? null;
$announcementExpiresAt = $announcement['expires_at'] ?? null;
$initialPublishAt = is_string($_POST['publish_at'] ?? null)
    ? $_POST['publish_at']
    : ($announcementPublishAt === null
        ? ''
        : substr(str_replace(' ', 'T', (string) $announcementPublishAt), 0, 16));
$initialExpiresAt = is_string($_POST['expires_at'] ?? null)
    ? $_POST['expires_at']
    : ($announcementExpiresAt === null
        ? ''
        : substr(str_replace(' ', 'T', (string) $announcementExpiresAt), 0, 16));
$initialTitle = is_string($_POST['title'] ?? null)
    ? $_POST['title']
    : (string) ($announcement['title'] ?? '');
$initialMessage = is_string($_POST['message'] ?? null)
    ? $_POST['message']
    : (string) ($announcement['message'] ?? '');
$initialStatus = is_string($_POST['status'] ?? null)
    ? $_POST['status']
    : (string) ($announcement['status'] ?? 'draft');
$formRoute = '/admin/notifications/create' . ($isEditing ? '?id=' . $announcementId : '');
render_app_page_start($isEditing ? 'Edit Announcement' : 'Create Announcement', 'Create a global institution notice or target one or more batches.', 'admin', ['admin-dashboard.css', 'announcement-form.css']);
?>
<?php echo render_flash_messages(); ?>
<nav class="announcement-breadcrumb" aria-label="Breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/dashboard')); ?>">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="<?php echo e(route_url('/admin/notifications/index')); ?>">Announcements</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?php echo $isEditing ? 'Edit' : 'Create'; ?></li>
    </ol>
</nav>
<?php foreach ($errors as $error): ?><div class="alert alert-danger" role="alert"><?php echo e($error); ?></div><?php endforeach; ?>
<section class="announcement-form-card" aria-labelledby="announcement-form-title">
    <header class="announcement-form-card-header">
        <span class="announcement-form-icon" aria-hidden="true"><i class="bi bi-megaphone"></i></span>
        <div>
            <span class="announcement-form-eyebrow">Institution communication</span>
            <h2 id="announcement-form-title"><?php echo $isEditing ? 'Edit announcement details' : 'Announcement details'; ?></h2>
            <p>Share an update with families in your institution. You can publish now or schedule it for later.</p>
        </div>
    </header>
    <form method="post" action="<?php echo e(route_url($formRoute)); ?>" class="announcement-form">
        <?php echo csrf_field(); ?>
        <div class="announcement-form-section">
            <div class="announcement-form-section-heading">
                <span class="announcement-section-number">01</span>
                <div><h3>What would you like to share?</h3><p>Add a clear title and the full announcement message.</p></div>
            </div>
            <label class="announcement-field announcement-field-wide" for="announcement-title">
                <span>Announcement title <span class="announcement-required">Required</span></span>
                <input class="form-control" id="announcement-title" name="title" maxlength="220" required placeholder="e.g. Updated school timings for next week" value="<?php echo e($initialTitle); ?>">
            </label>
            <label class="announcement-field announcement-field-wide" for="announcement-message">
                <span>Message <span class="announcement-required">Required</span></span>
                <textarea class="form-control" id="announcement-message" name="message" rows="7" maxlength="20000" required placeholder="Write the details families need to know..."><?php echo e($initialMessage); ?></textarea>
                <small>Keep your message clear and include any important dates or instructions.</small>
            </label>
        </div>
        <div class="announcement-form-section announcement-form-options">
            <div class="announcement-form-section-heading">
                <span class="announcement-section-number">02</span>
                <div><h3>Publishing options</h3><p>Choose when the announcement appears and how long it stays active.</p></div>
            </div>
            <label class="announcement-field" for="announcement-status">
                <span>Status</span>
                <select class="form-select" id="announcement-status" name="status">
                    <option value="draft"<?php echo $initialStatus === 'draft' ? ' selected' : ''; ?>>Save as draft</option>
                    <option value="published"<?php echo $initialStatus === 'published' ? ' selected' : ''; ?>>Published</option>
                </select>
                <small>Drafts are not visible to families.</small>
            </label>
            <label class="announcement-field" for="announcement-publish-at">
                <span>Publish date <span class="announcement-optional">Optional</span></span>
                <input class="form-control" id="announcement-publish-at" type="datetime-local" name="publish_at" value="<?php echo e($initialPublishAt); ?>">
                <small>Leave blank to use the default publishing time.</small>
            </label>
            <label class="announcement-field" for="announcement-expires-at">
                <span>Expiry date <span class="announcement-optional">Optional</span></span>
                <input class="form-control" id="announcement-expires-at" type="datetime-local" name="expires_at" value="<?php echo e($initialExpiresAt); ?>">
                <small>Leave blank if the announcement should not expire.</small>
            </label>
        </div>
        <fieldset class="announcement-audience">
            <legend><span class="announcement-section-number">03</span><span>Who should see this?</span></legend>
            <p>Choose specific batches, or leave every batch unselected to reach all eligible families in this institution.</p>
            <?php if ($batches === []): ?>
                <div class="announcement-no-batches"><i class="bi bi-info-circle" aria-hidden="true"></i><span>No batches are available yet. This announcement will be visible to all eligible families.</span></div>
            <?php else: ?>
                <div class="announcement-batch-grid">
                    <?php foreach ($batches as $batch): ?>
                        <label class="announcement-batch-option">
                            <input type="checkbox" name="batch_ids[]" value="<?php echo e((string) $batch['id']); ?>"<?php echo in_array((string) $batch['id'], $selectedBatchIds, true) ? ' checked' : ''; ?>>
                            <span class="announcement-batch-check" aria-hidden="true"><i class="bi bi-check"></i></span>
                            <span class="announcement-batch-name"><?php echo e($batch['name']); ?><small><?php echo e($batch['class_name']); ?></small></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </fieldset>
        <footer class="announcement-form-footer">
            <a class="announcement-cancel" href="<?php echo e(route_url('/admin/notifications/index')); ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Cancel</a>
            <button class="btn btn-cobalt announcement-submit" type="submit"><i class="bi bi-send" aria-hidden="true"></i> <?php echo $isEditing ? 'Update announcement' : 'Save announcement'; ?></button>
        </footer>
    </form>
</section>
<?php render_app_page_end('admin'); ?>
