<?php
// session_name("site3_session"); // Handled in includes/session.php
require_once __DIR__ . '/includes/session.php';
require 'db.php';

require_role('admin');
// error_log("SESSION CSRF Token: " . ($_SESSION['csrf_token'] ?? 'NOT SET'));
// error_log("POST CSRF Token: " . ($_POST['csrf_token'] ?? 'NOT SET'));
validate_csrf_token_post('dashboardX.php', 'CSRF token mismatch. Please try again.');

$request_id = filter_var($_POST['id'], FILTER_VALIDATE_INT);
$action = $_POST['action'] ?? ''; // Use POST for action
$rejection_reason = $_POST['rejection_reason'] ?? null; // Get rejection reason from POST

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
    // Start transaction
    $pdo->beginTransaction();

    // Fetch request details
    $stmt = $pdo->prepare("SELECT * FROM requests WHERE id = :id AND status = 'pending_admin_approval'");
    $stmt->execute(['id' => $request_id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        $pdo->rollBack();
        $_SESSION['error'] = "Request not found or not in pending admin approval status.";
        header("Location: dashboardX.php");
        exit();
    }

    if ($action === 'approve') {
        // Approval logic
        $updateRequestStmt = $pdo->prepare("UPDATE requests SET status = 'approved', rejection_reason = NULL WHERE id = :id AND status = 'pending_admin_approval'");
        $result = $updateRequestStmt->execute(['id' => $request_id]);
        
        if (!$result || $updateRequestStmt->rowCount() === 0) {
            throw new Exception("Failed to approve request - no rows affected");
        }

        $pdo->commit();
        $_SESSION['success'] = "Request approved.";
        
    } elseif ($action === 'reject') {
        // Rejection logic
        if ($rejection_reason === 'reassign_vehicle') {
            $new_status = 'rejected_reassign_dispatch';
            $_SESSION['success'] = "Request rejected and sent back to dispatch for vehicle reassignment.";
        } else if ($rejection_reason === 'reassign_driver') {
            $new_status = 'rejected_reassign_dispatch';
            $_SESSION['success'] = "Request rejected and sent back to dispatch for driver reassignment.";
        } else { // 'new_request'
            $new_status = 'rejected_new_request';
            $_SESSION['success'] = "Request rejected. Requestor needs to file a new request.";
        }

        // Get assigned vehicle and driver IDs before clearing them
        $assigned_vehicle_id = $request['assigned_vehicle_id'];
        $assigned_driver_id = $request['assigned_driver_id'];

        // Update request status, clear assigned vehicle/driver
        $updateRequestStmt = $pdo->prepare("UPDATE requests SET status = :new_status, rejection_reason = :rejection_reason, assigned_vehicle_id = NULL, assigned_driver_id = NULL WHERE id = :id AND status = 'pending_admin_approval'");
        $result = $updateRequestStmt->execute([
            ':new_status' => $new_status,
            ':rejection_reason' => $rejection_reason,
            ':id' => $request_id
        ]);
        
        if (!$result || $updateRequestStmt->rowCount() === 0) {
            throw new Exception("Failed to update request status during rejection - no rows affected");
        }

        // If a vehicle and driver were assigned, make them available again
        if ($assigned_vehicle_id) {
            $updateVehicleStmt = $pdo->prepare("UPDATE vehicles SET status = 'available', assigned_to = NULL, driver_name = NULL WHERE id = :id");
            $updateVehicleStmt->execute([':id' => $assigned_vehicle_id]);
        }
        if ($assigned_driver_id) {
            $updateDriverStmt = $pdo->prepare("UPDATE drivers SET status = 'available' WHERE id = :id");
            $updateDriverStmt->execute([':id' => $assigned_driver_id]);
        }

        $pdo->commit();
    }

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Process Request Error: " . $e->getMessage(), 0);
    $_SESSION['error'] = "An error occurred while processing the request: " . $e->getMessage();
}

header("Location: dashboardX.php");
?>