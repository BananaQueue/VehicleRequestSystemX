<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/schedule_utils.php';
require_once __DIR__ . '/../includes/audit_log.php';
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

try {
    $pdo->beginTransaction();

    // Fetch the request details and verify ownership
    $stmt = $pdo->prepare("SELECT * FROM requests WHERE id = ? AND user_id = ?");
    $stmt->execute([$requestId, $userId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        throw new Exception('Request not found or you do not have permission to cancel this request.');
    }

    [$requestStartDate, $requestEndDate] = get_request_date_range($request);
    $today = date('Y-m-d');

    $cancellableStatuses = ['pending_dispatch_assignment', 'pending_admin_approval', 'rejected_reassign_dispatch', 'approved'];
    if (!in_array($request['status'], $cancellableStatuses)) {
        throw new Exception('This request cannot be cancelled at its current stage.');
    }

    if ($request['status'] === 'approved' && $requestStartDate && $today >= $requestStartDate) {
        throw new Exception('This trip already started and can no longer be cancelled online.');
    }

    $updateStmt = $pdo->prepare("
        UPDATE requests
        SET status = 'cancelled',
            rejection_reason = NULL,
            assigned_vehicle_id = NULL,
            assigned_driver_id = NULL
        WHERE id = :id
    ");
    $updateStmt->execute([':id' => $requestId]);

    log_request_audit($pdo, $requestId, 'employee_cancelled', [
        'notes' => $cancelReason
    ]);

    $pdo->commit();

    sync_active_assignments($pdo);

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