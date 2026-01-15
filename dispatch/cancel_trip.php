<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/schedule_utils.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/request_cancellation.php';

// Check if user is dispatch
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'dispatch') {
    $_SESSION['error_message'] = 'Unauthorized access. Dispatch privileges required.';
    header('Location: ../dispatch_dashboard.php');
    exit;
}

// Verify CSRF token
validate_csrf_token_post('../dispatch_dashboard.php', 'CSRF token mismatch. Please try again.');

// Check if request ID and cancel reason are provided
if (!isset($_POST['request_id'])) {
    $_SESSION['error_message'] = 'Request ID is required.';
    header('Location: ../dispatch_dashboard.php');
    exit;
}

$cancelReason = trim($_POST['cancel_reason'] ?? '');
if (empty($cancelReason)) {
    $_SESSION['error_message'] = 'Please provide a reason for cancelling this trip.';
    header('Location: ../dispatch_dashboard.php');
    exit;
}

// Limit cancel reason to 150 characters
if (strlen($cancelReason) > 150) {
    $_SESSION['error_message'] = 'Cancellation reason is too long. Please keep it to one sentence (max 150 characters).';
    header('Location: ../dispatch_dashboard.php');
    exit;
}

$requestId = (int)$_POST['request_id'];

// Dispatch can cancel approved trips (upcoming or ongoing)
// Unlike employees who can only cancel before trip starts, dispatch can cancel anytime
$cancellableStatuses = ['approved'];

handle_request_cancellation(
    $pdo,
    $requestId,
    $cancelReason,
    $_SESSION['user']['id'],
    'dispatch',
    $cancellableStatuses,
    '../dispatch_dashboard.php',
    '../dispatch_dashboard.php',
    null // No user_id restriction - dispatch can cancel anyone's trip
);