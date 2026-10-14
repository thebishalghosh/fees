<?php
declare(strict_types=1);

const INSTITUTION_LOGO_MAX_BYTES = 2097152;
const INSTITUTION_LOGO_MAX_DIMENSION = 4000;
const INSTITUTION_LOGO_MAX_PIXELS = 10000000;

function institution_branding_logo_file(?string $logoPath): ?array
{
    if ($logoPath === null || str_contains($logoPath, "\0")) {
        return null;
    }

    $relativePath = str_replace('\\', '/', $logoPath);
    if (preg_match('#^storage/institution-logos/[a-f0-9]{32}\.(?:png|jpe?g)$#D', $relativePath) === 1) {
        $root = realpath(APP_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'institution-logos');
        $candidate = APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $allowedMimeTypes = ['image/png', 'image/jpeg'];
    } elseif (str_starts_with($relativePath, 'public/')
        && preg_match('#(?:^|/)\.\.(?:/|$)#', $relativePath) !== 1) {
        $root = realpath(APP_ROOT . DIRECTORY_SEPARATOR . 'public');
        $candidate = APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $allowedMimeTypes = ['image/png', 'image/jpeg', 'image/gif'];
    } else {
        return null;
    }

    if ($root === false) {
        return null;
    }
    $filePath = realpath($candidate);
    $fileSize = $filePath === false ? false : filesize($filePath);
    if ($filePath === false
        || !str_starts_with(strtolower($filePath), strtolower($root . DIRECTORY_SEPARATOR))
        || !is_file($filePath)
        || !is_int($fileSize)
        || $fileSize > INSTITUTION_LOGO_MAX_BYTES) {
        return null;
    }

    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($filePath);
    $image = @getimagesize($filePath);
    if (!is_string($mimeType)
        || !in_array($mimeType, $allowedMimeTypes, true)
        || !is_array($image)
        || ($image['mime'] ?? null) !== $mimeType
        || !isset($image[0], $image[1])
        || $image[0] > INSTITUTION_LOGO_MAX_DIMENSION
        || $image[1] > INSTITUTION_LOGO_MAX_DIMENSION
        || $image[0] * $image[1] > INSTITUTION_LOGO_MAX_PIXELS) {
        return null;
    }

    return ['path' => $filePath, 'mime_type' => $mimeType];
}

function institution_branding_validate_logo_upload(array $upload, bool $requireUploadedFile = true): array
{
    $error = $upload['error'] ?? null;
    $temporaryPath = $upload['tmp_name'] ?? null;
    if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        throw new InvalidArgumentException('The institution logo must be no larger than 2 MB.');
    }
    if (!is_int($error) || $error !== UPLOAD_ERR_OK
        || !is_string($temporaryPath) || $temporaryPath === ''
        || ($requireUploadedFile && !is_uploaded_file($temporaryPath))
        || (!$requireUploadedFile && !is_file($temporaryPath))) {
        throw new InvalidArgumentException('Choose a logo image to upload.');
    }

    $fileSize = filesize($temporaryPath);
    if (!is_int($fileSize) || $fileSize < 1 || $fileSize > INSTITUTION_LOGO_MAX_BYTES) {
        throw new InvalidArgumentException('The institution logo must be no larger than 2 MB.');
    }

    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
    $image = @getimagesize($temporaryPath);
    $acceptedTypes = [
        'image/png' => ['extension' => 'png', 'image_type' => IMAGETYPE_PNG],
        'image/jpeg' => ['extension' => 'jpg', 'image_type' => IMAGETYPE_JPEG],
    ];
    if (!is_array($image)
        || !is_string($mimeType)
        || !isset($acceptedTypes[$mimeType])
        || ($image['mime'] ?? null) !== $mimeType
        || ($image[2] ?? null) !== $acceptedTypes[$mimeType]['image_type']) {
        throw new InvalidArgumentException('Upload a valid PNG or JPEG logo image.');
    }

    $width = filter_var($image[0] ?? null, FILTER_VALIDATE_INT);
    $height = filter_var($image[1] ?? null, FILTER_VALIDATE_INT);
    if ($width === false || $height === false
        || $width < 1 || $height < 1
        || $width > INSTITUTION_LOGO_MAX_DIMENSION
        || $height > INSTITUTION_LOGO_MAX_DIMENSION
        || $width * $height > INSTITUTION_LOGO_MAX_PIXELS) {
        throw new InvalidArgumentException('The institution logo dimensions are too large.');
    }

    $contents = file_get_contents($temporaryPath);
    $decodedImage = is_string($contents) ? @imagecreatefromstring($contents) : false;
    if ($decodedImage === false) {
        throw new InvalidArgumentException('Upload a valid PNG or JPEG logo image.');
    }
    imagedestroy($decodedImage);

    return [
        'temporary_path' => $temporaryPath,
        'mime_type' => $mimeType,
        'extension' => $acceptedTypes[$mimeType]['extension'],
        'size' => $fileSize,
        'width' => $width,
        'height' => $height,
    ];
}

function institution_branding_store_logo(array $upload, bool $requireUploadedFile = true): string
{
    $validated = institution_branding_validate_logo_upload($upload, $requireUploadedFile);
    $directory = APP_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'institution-logos';
    if (!is_dir($directory)
        && !mkdir($directory, 0750, true)
        && !is_dir($directory)) {
        throw new RuntimeException('Institution logo storage is unavailable.');
    }
    $storageRoot = realpath(APP_ROOT . DIRECTORY_SEPARATOR . 'storage');
    $logoDirectory = realpath($directory);
    if ($storageRoot === false
        || $logoDirectory === false
        || is_link(APP_ROOT . DIRECTORY_SEPARATOR . 'storage')
        || is_link($directory)
        || strtolower($logoDirectory) !== strtolower($storageRoot . DIRECTORY_SEPARATOR . 'institution-logos')) {
        throw new RuntimeException('Institution logo storage is unavailable.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $validated['extension'];
    $destination = $directory . DIRECTORY_SEPARATOR . $filename;
    $stored = $requireUploadedFile
        ? move_uploaded_file($validated['temporary_path'], $destination)
        : copy($validated['temporary_path'], $destination);
    if (!$stored) {
        throw new RuntimeException('The institution logo could not be stored.');
    }
    if (!chmod($destination, 0640)) {
        unlink($destination);
        throw new RuntimeException('Institution logo permissions could not be secured.');
    }

    return 'storage/institution-logos/' . $filename;
}

function institution_branding_delete_logo_file(?string $logoPath): void
{
    if ($logoPath === null
        || preg_match('#^storage/institution-logos/[a-f0-9]{32}\.(?:png|jpe?g)$#D', str_replace('\\', '/', $logoPath)) !== 1) {
        return;
    }
    $file = institution_branding_logo_file($logoPath);
    if ($file !== null && !unlink($file['path'])) {
        throw new RuntimeException('The previous institution logo could not be removed.');
    }
}

function institution_branding_get(mysqli $db, int $institutionId): ?array
{
    $statement = $db->prepare('SELECT id, name, logo_path FROM institutions WHERE id = ? LIMIT 1');
    $statement->bind_param('i', $institutionId);
    $statement->execute();
    $institution = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_array($institution)) {
        return null;
    }
    $institution['id'] = (int) $institution['id'];

    return $institution;
}

function institution_branding_send_logo(mysqli $db, int $institutionId): never
{
    $institution = institution_branding_get($db, $institutionId);
    $logoFile = is_array($institution)
        ? institution_branding_logo_file(is_string($institution['logo_path']) ? $institution['logo_path'] : null)
        : null;
    if ($logoFile === null) {
        http_response_code(404);
        exit('Institution logo not found.');
    }

    header('Content-Type: ' . $logoFile['mime_type']);
    header('Content-Length: ' . (string) filesize($logoFile['path']));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
        readfile($logoFile['path']);
    }
    exit;
}

function admin_panel_update_institution_logo(
    mysqli $db,
    int $institutionId,
    int $actorUserId,
    ?string $logoPath
): ?string {
    $before = admin_panel_institution($db, $institutionId);
    if ($before === null) {
        throw new OutOfBoundsException('Institution not found.');
    }
    $oldLogoPath = is_string($before['logo_path'] ?? null) ? $before['logo_path'] : null;
    $db->begin_transaction();
    try {
        $statement = $db->prepare('UPDATE institutions SET logo_path = ? WHERE id = ?');
        $statement->bind_param('si', $logoPath, $institutionId);
        $statement->execute();
        $statement->close();

        $actor = current_user();
        write_audit_log(
            $db,
            $institutionId,
            $actorUserId,
            is_array($actor) ? (string) $actor['user_type'] : 'admin',
            $logoPath === null ? 'institution.logo_removed' : 'institution.logo_updated',
            'institution',
            (string) $institutionId,
            ['logo_path' => $oldLogoPath],
            ['logo_path' => $logoPath]
        );
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }

    return $oldLogoPath;
}

function admin_panel_cleanup_replaced_logo(mysqli $db, ?string $oldLogoPath): void
{
    if ($oldLogoPath === null) {
        return;
    }
    $statement = $db->prepare('SELECT COUNT(*) AS usage_count FROM institutions WHERE logo_path = ?');
    $statement->bind_param('s', $oldLogoPath);
    $statement->execute();
    $usageCount = (int) ($statement->get_result()->fetch_assoc()['usage_count'] ?? 0);
    $statement->close();
    if ($usageCount === 0) {
        institution_branding_delete_logo_file($oldLogoPath);
    }
}
