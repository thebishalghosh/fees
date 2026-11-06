<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

function institution_audit_actor_user_id(?int $actorUserId): ?int
{
    return current_platform_admin() === null ? $actorUserId : null;
}

function institution_platform_actor_matches(int $institutionId): bool
{
    return $institutionId > 0
        && current_platform_admin() !== null
        && platform_admin_selected_institution_id() === $institutionId;
}

function write_audit_log(
    mysqli $db,
    ?int $institutionId,
    ?int $actorUserId,
    ?string $actorType,
    string $action,
    string $entityType,
    ?string $entityId,
    ?array $oldValues,
    ?array $newValues
): void {
    $platformAdmin = current_platform_admin();
    if ($platformAdmin !== null) {
        $selectedInstitutionId = platform_admin_selected_institution_id();
        if ($institutionId === null
            || $selectedInstitutionId === null
            || $institutionId !== $selectedInstitutionId) {
            throw new RuntimeException('Platform audit events must match the selected institution.');
        }
        write_platform_audit_log(
            $db,
            (int) $platformAdmin['id'],
            'platform.institution_admin.' . $action,
            $entityType,
            $entityId !== null && ctype_digit($entityId) ? (int) $entityId : null,
            $institutionId,
            null,
            platform_admin_correlation_id(),
            is_string($_SERVER['HTTP_USER_AGENT'] ?? null) ? $_SERVER['HTTP_USER_AGENT'] : null,
            filter_var($_SERVER['REMOTE_ADDR'] ?? null, FILTER_VALIDATE_IP) ?: null
        );
        return;
    }

    $oldJson = $oldValues === null
        ? null
        : json_encode($oldValues, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $newJson = $newValues === null
        ? null
        : json_encode($newValues, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $ipAddress = filter_var($_SERVER['REMOTE_ADDR'] ?? null, FILTER_VALIDATE_IP) !== false
        ? (string) $_SERVER['REMOTE_ADDR']
        : null;
    $userAgent = isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT'])
        ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500)
        : null;

    $statement = $db->prepare(
        'INSERT INTO audit_logs (
            institution_id, actor_user_id, actor_type, action, entity_type, entity_id,
            ip_address, user_agent, old_values, new_values
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $statement->bind_param(
        'iissssssss',
        $institutionId,
        $actorUserId,
        $actorType,
        $action,
        $entityType,
        $entityId,
        $ipAddress,
        $userAgent,
        $oldJson,
        $newJson
    );
    $statement->execute();
    $statement->close();
}
