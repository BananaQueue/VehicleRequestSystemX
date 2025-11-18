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
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $_SESSION['error'] = 'Invalid security token. Please try again.';
    header('Location: ../dashboardX.php?tab=myRequests');
    exit;
}

// Check if request ID and cancel reason are provided
if (!isset($_POST['request_id']) || !isset($_POST['cancel_reason']) || empty(trim($_POST['cancel_reason']))) {
    $_SESSION['error'] = 'Request ID and cancellation reason are required.';
    header('Location: ../dashboardX.php?tab=myRequests');
    exit;
}

$requestId = (int)$_POST['request_id'];
$cancelReason = trim($_POST['cancel_reason']);
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

    // Verify the request is in a cancellable state
    $cancellableStatuses = ['pending_dispatch_assignment', 'pending_admin_approval', 'rejected_reassign_dispatch'];
    if (!in_array($request['status'], $cancellableStatuses)) {
        throw new Exception('This request cannot be cancelled at its current stage.');
    }

    // If there's an assigned vehicle/driver (in case of pending admin approval), free them up
    if ($request['assigned_vehicle_id']) {
        $stmt = $pdo->prepare("UPDATE vehicles SET status = 'available', assigned_to = NULL, driver_name = NULL WHERE id = ?");
        $stmt->execute([$request['assigned_vehicle_id']]);
    }

    // Delete the request (or you could mark it as cancelled with a new status)
    // Option 1: Delete the request
    $stmt = $pdo->prepare("DELETE FROM requests WHERE id = ?");
    $stmt->execute([$requestId]);

    // Option 2: Mark as cancelled (uncomment if you prefer to keep cancelled requests in the database)
    // $stmt = $pdo->prepare("UPDATE requests SET status = 'cancelled_by_employee', admin_notes = ? WHERE id = ?");
    // $cancelNote = "Request cancelled by employee ($userName). Reason: $cancelReason";
    // $stmt->execute([$cancelNote, $requestId]);

    $pdo->commit();

    $_SESSION['success'] = "Your request has been successfully cancelled. You can now submit a new request if needed.";
    header('Location: ../dashboardX.php?tab=myRequests');
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    $_SESSION['error'] = 'Failed to cancel request: ' . $e->getMessage();
    header('Location: ../dashboardX.php?tab=myRequests');
    exit;
}