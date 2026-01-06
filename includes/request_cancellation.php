<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/schedule_utils.php';

function handle_request_cancellation(PDO $pdo, int $requestId, string $cancelReason, int $actorId, string $actorRole, array $allowedStatuses, string $redirectSuccess, string $redirectFailure, ?int $userId = null): void
{
    try {
        $pdo->beginTransaction();

        // Fetch the request details
        $query = "SELECT * FROM requests WHERE id = :id";
        $params = ['id' => $requestId];

        if ($actorRole === 'employee' && $userId !== null) {
            $query .= " AND user_id = :user_id";
            $params['user_id'] = $userId;
        }

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            throw new Exception('Request not found or you do not have permission to cancel this request.');
        }

        // Verify the request is in a cancellable state
        if (!in_array($request['status'], $allowedStatuses)) {
            throw new Exception('This request cannot be cancelled at its current stage.');
        }

        // If there's an assigned vehicle/driver, free them up
        if ($request['assigned_vehicle_id']) {
            $stmt = $pdo->prepare("UPDATE vehicles SET status = 'available', assigned_to = NULL, driver_name = NULL WHERE id = ?");
            $stmt->execute([$request['assigned_vehicle_id']]);
        }
        if ($request['assigned_driver_id']) {
            $stmt = $pdo->prepare("UPDATE drivers SET status = 'available' WHERE id = ?");
            $stmt->execute([$request['assigned_driver_id']]);
        }

        // Update the request status to cancelled
        $updateStmt = $pdo->prepare("
            UPDATE requests
            SET status = 'cancelled',
                rejection_reason = NULL,
                assigned_vehicle_id = NULL,
                assigned_driver_id = NULL
            WHERE id = :id
        ");
        $updateStmt->execute([':id' => $requestId]);

        // Log the action
        log_request_audit($pdo, $requestId, $actorRole . '_cancelled', [
            'notes' => $cancelReason
        ]);

        $pdo->commit();

        sync_active_assignments($pdo);

        $_SESSION['success_message'] = "Request ID {$requestId} has been cancelled successfully.";
        header("Location: " . $redirectSuccess);
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Cancellation Error ({$actorRole}): " . $e->getMessage(), 0);
        $_SESSION['error_message'] = 'Failed to cancel request: ' . $e->getMessage();
        header("Location: " . $redirectFailure);
        exit;
    }
}
