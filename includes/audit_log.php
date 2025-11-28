<?php

function log_request_audit(PDO $pdo, int $requestId, string $action, array $context = []): void
{
    $actorId = $context['actor_id'] ?? ($_SESSION['user']['id'] ?? null);
    $actorRole = $context['actor_role'] ?? ($_SESSION['user']['role'] ?? 'system');
    $actorName = $context['actor_name'] ?? ($_SESSION['user']['name'] ?? 'System');
    $notes = $context['notes'] ?? null;

    $stmt = $pdo->prepare("
        INSERT INTO request_audit_logs (request_id, action, actor_id, actor_role, actor_name, notes)
        VALUES (:request_id, :action, :actor_id, :actor_role, :actor_name, :notes)
    ");

    $stmt->execute([
        ':request_id' => $requestId,
        ':action' => $action,
        ':actor_id' => $actorId,
        ':actor_role' => $actorRole,
        ':actor_name' => $actorName,
        ':notes' => $notes,
    ]);
}


