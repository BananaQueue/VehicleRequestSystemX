<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../db.php';

// Check if user is an employee
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'employee') {
    $_SESSION['error'] = 'Unauthorized access. Employee privileges required.';
    header('Location: ../dashboardX.php');
    exit;
}

// Verify CSRF token
validate_csrf_token_post('../dashboardX.php', 'CSRF token mismatch. Please try again.');

// Check if request ID and cancel reason are provided
if (!isset($_POST['request_id'])) {
    $_SESSION['error'] = 'Request ID is required.';
    header('Location: ../dashboardX.php');
    exit;
}

$cancelReason = trim($_POST['cancel_reason'] ?? '');
if (empty($cancelReason)) {
    $_SESSION['error'] = 'Please provide a reason for cancelling your request.';
    header('Location: ../dashboardX.php');
    exit;
}

// Limit cancel reason to approximately 1 sentence (150 characters)
if (strlen($cancelReason) > 150) {
    $_SESSION['error'] = 'Cancellation reason is too long. Please keep it to one sentence (max 150 characters).';
    header('Location: ../dashboardX.php');
    exit;
}

$requestId = (int)$_POST['request_id'];
$userId = $_SESSION['user']['id'];
$userName = $_SESSION['user']['name'];

try {
    $pdo->beginTransaction();

    // Fetch the request details and verify ownership
    $stmt = $pdo->prepare("SELECT * FROM requests WHERE id = ? AND user_id = ?");
    $stmt->execute([$requestId, $userId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        throw new Exception('Request not found or you do not have permission to cancel this request.');
    }

    // Check if request can be cancelled (not approved)
    if ($request['status'] === 'approved') {
        throw new Exception('Cannot cancel an approved request. Please use the return vehicle option instead.');
    }

    // Verify the request is in a cancellable state
    $cancellableStatuses = ['pending_dispatch_assignment', 'pending_admin_approval', 'rejected_reassign_dispatch'];
    if (!in_array($request['status'], $cancellableStatuses)) {
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

    // Log the cancellation reason before deleting (optional - for audit trail)
    error_log("Request #{$requestId} cancelled by {$userName} (ID: {$userId}). Reason: {$cancelReason}");

    // Delete the request
    $stmt = $pdo->prepare("DELETE FROM requests WHERE id = ?");
    $stmt->execute([$requestId]);

    $pdo->commit();

    $_SESSION['success'] = "Your vehicle request has been cancelled successfully. You can now submit a new request if needed.";
    header('Location: ../dashboardX.php');
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Employee Cancel Request Error: " . $e->getMessage(), 0);
    $_SESSION['error'] = 'Failed to cancel request: ' . $e->getMessage();
    header('Location: ../dashboardX.php');
    exit;
}