<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/schedule_utils.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/request_cancellation.php';

// Check if user is an employee
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'employee') {
    $_SESSION['error_message'] = 'Unauthorized access. Employee privileges required.';
    header('Location: ../dashboardX.php');
    exit;
}

// Verify CSRF token
validate_csrf_token_post('../dashboardX.php', 'CSRF token mismatch. Please try again.');

// Check if request ID and cancel reason are provided
if (!isset($_POST['request_id'])) {
    $_SESSION['error_message'] = 'Request ID is required.';
    header('Location: ../dashboardX.php');
    exit;
}

$cancelReason = trim($_POST['cancel_reason'] ?? '');
if (empty($cancelReason)) {
    $_SESSION['error_message'] = 'Please provide a reason for cancelling your request.';
    header('Location: ../dashboardX.php');
    exit;
}

// Limit cancel reason to approximately 1 sentence (150 characters)
if (strlen($cancelReason) > 150) {
    $_SESSION['error_message'] = 'Cancellation reason is too long. Please keep it to one sentence (max 150 characters).';
    header('Location: ../dashboardX.php');
    exit;
}

$requestId = (int)$_POST['request_id'];
$userId = $_SESSION['user']['id'];

// Employees can cancel pending requests or approved requests that haven't started yet
$cancellableStatuses = [
    'pending_dispatch_assignment',
    'pending_admin_approval',
    'rejected_reassign_dispatch'
];

// For approved requests, check if trip hasn't started yet
$stmt = $pdo->prepare("SELECT status, departure_date FROM requests WHERE id = :id AND user_id = :user_id");
$stmt->execute(['id' => $requestId, 'user_id' => $userId]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if ($request && $request['status'] === 'approved') {
    $departureDate = $request['departure_date'] ?? null;
    $today = date('Y-m-d');
    if ($departureDate && $today < $departureDate) {
        // Trip hasn't started yet, allow cancellation
        $cancellableStatuses[] = 'approved';
    }
}

handle_request_cancellation(
    $pdo,
    $requestId,
    $cancelReason,
    $userId,
    'employee',
    $cancellableStatuses,
    '../dashboardX.php',
    '../dashboardX.php',
    $userId
);