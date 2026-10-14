<?php
declare(strict_types=1);

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
