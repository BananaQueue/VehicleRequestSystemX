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