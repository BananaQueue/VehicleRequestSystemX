<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../db.php';

// Check if user is admin
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    $_SESSION['error'] = 'Unauthorized access. Admin privileges required.';
    header('Location: ../dashboardX.php');
    exit;
}

// Verify CSRF token
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $_SESSION['error'] = 'Invalid security token. Please try again.';
    header('Location: ../dashboardX.php?tab=adminRequests');
    exit;
}

// Check if request ID and cancel reason are provided
if (!isset($_POST['request_id']) || !isset($_POST['cancel_reason']) || empty(trim($_POST['cancel_reason']))) {
    $_SESSION['error'] = 'Request ID and cancellation reason are required.';
    header('Location: ../dashboardX.php?tab=adminRequests');
    exit;
}

$requestId = (int)$_POST['request_id'];
$cancelReason = trim($_POST['cancel_reason']);
$adminName = $_SESSION['user']['name'];

try {
    $pdo->beginTransaction();

    // Fetch the request details
    $stmt = $pdo->prepare("SELECT * FROM requests WHERE id = ?");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        throw new Exception('Request not found.');
    }

    // Verify the request is in a cancellable state (forwarded to dispatch)
    if (!in_array($request['status'], ['pending_dispatch_assignment', 'rejected_reassign_dispatch'])) {
        throw new Exception('This request cannot be cancelled. It is not currently with dispatch.');
    }

    // If there's an assigned vehicle/driver (in case of reassignment rejection), free them up
    if ($request['assigned_vehicle_id']) {
        $stmt = $pdo->prepare("UPDATE vehicles SET status = 'available', assigned_to = NULL, driver_name = NULL WHERE id = ?");
        $stmt->execute([$request['assigned_vehicle_id']]);
    }

    // Update the request status to rejected_new_request with the cancellation reason
    $stmt = $pdo->prepare("UPDATE requests SET status = 'rejected_new_request', admin_notes = ? WHERE id = ?");
    $cancelNote = "Request cancelled by admin ($adminName). Reason: $cancelReason";
    $stmt->execute([$cancelNote, $requestId]);

    $pdo->commit();

    $_SESSION['success'] = "Request from {$request['requestor_name']} has been successfully cancelled. The employee has been notified.";
    header('Location: ../dashboardX.php?tab=adminRequests');
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    $_SESSION['error'] = 'Failed to cancel request: ' . $e->getMessage();
    header('Location: ../dashboardX.php?tab=adminRequests');
    exit;
}