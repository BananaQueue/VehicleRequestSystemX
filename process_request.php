<?php
// session_name("site3_session"); // Handled in includes/session.php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/schedule_utils.php';
require_once __DIR__ . '/includes/audit_log.php';
require 'db.php';

require_role('admin');
validate_csrf_token_post('dashboardX.php', 'CSRF token mismatch. Please try again.');

$request_id = filter_var($_POST['id'], FILTER_VALIDATE_INT);
$action = $_POST['action'] ?? '';
$rejection_reason = $_POST['rejection_reason'] ?? null;

// Validate inputs
if ($request_id === false || !in_array($action, ['approve', 'reject'])) {
    $_SESSION['error'] = "Invalid request parameters.";
    header("Location: dashboardX.php");
    exit();
}

if ($action === 'reject' && !in_array($rejection_reason, ['reassign_vehicle','reassign_driver', 'new_request'])) {
    $_SESSION['error'] = "Invalid rejection reason provided.";
    header("Location: dashboardX.php");
    exit();
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT * FROM requests WHERE id = :id AND status = 'pending_admin_approval'");
    $stmt->execute(['id' => $request_id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        $pdo->rollBack();
        $_SESSION['error'] = "Request not found or not in pending admin approval status.";
        header("Location: dashboardX.php");
        exit();
    }

    [$requestStartDate, $requestEndDate] = get_request_date_range($request);

    if ($action === 'approve') {
        if (!$request['assigned_vehicle_id']) {
            throw new Exception("A vehicle must be assigned before approval.");
        }

        if (!$requestStartDate) {
            throw new Exception("Departure date is required before approval.");
        }

        if (has_vehicle_conflict($pdo, (int)$request['assigned_vehicle_id'], $requestStartDate, $requestEndDate, $request_id)) {
            throw new Exception("Selected vehicle is already reserved within this date range.");
        }

        if ($request['assigned_driver_id'] && has_driver_conflict($pdo, (int)$request['assigned_driver_id'], $requestStartDate, $requestEndDate, $request_id)) {
            throw new Exception("Selected driver is already reserved within this date range.");
        }

        $updateRequestStmt = $pdo->prepare("UPDATE requests SET status = 'approved', rejection_reason = NULL WHERE id = :id AND status = 'pending_admin_approval'");
        $result = $updateRequestStmt->execute(['id' => $request_id]);

        if (!$result || $updateRequestStmt->rowCount() === 0) {
            throw new Exception("Failed to approve request - no rows affected");
        }

        log_request_audit($pdo, $request_id, 'admin_approved', [
            'notes' => sprintf(
                "Approved for %s to %s",
                $requestStartDate,
                $requestEndDate
            )
        ]);

        $_SESSION['success'] = "Request approved.";

    } elseif ($action === 'reject') {
        if ($rejection_reason === 'reassign_vehicle' || $rejection_reason === 'reassign_driver') {
            $new_status = 'rejected_reassign_dispatch';
            $_SESSION['success'] = "Request rejected and sent back to dispatch for reassignment.";
        } else {
            $new_status = 'rejected_new_request';
            $_SESSION['success'] = "Request rejected. Requestor needs to file a new request.";
        }

        $updateRequestStmt = $pdo->prepare("UPDATE requests SET status = :new_status, rejection_reason = :rejection_reason, assigned_vehicle_id = NULL, assigned_driver_id = NULL WHERE id = :id AND status = 'pending_admin_approval'");
        $result = $updateRequestStmt->execute([
            ':new_status' => $new_status,
            ':rejection_reason' => $rejection_reason,
            ':id' => $request_id
        ]);

        if (!$result || $updateRequestStmt->rowCount() === 0) {
            throw new Exception("Failed to update request status during rejection - no rows affected");
        }

        log_request_audit($pdo, $request_id, 'admin_rejected', [
            'notes' => $rejection_reason
        ]);
    }

    $pdo->commit();
    sync_active_assignments($pdo);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Process Request Error: " . $e->getMessage(), 0);
    $_SESSION['error'] = "An error occurred while processing the request: " . $e->getMessage();
}

header("Location: dashboardX.php");
?>