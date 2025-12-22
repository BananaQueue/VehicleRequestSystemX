<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/request_cancellation.php';

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

handle_request_cancellation(
    $pdo,
    $requestId,
    $cancelReason,
    $_SESSION['user']['id'],
    'admin',
    ['pending_dispatch_assignment', 'rejected_reassign_dispatch'],
    '../dashboardX.php?tab=adminRequests',
    '../dashboardX.php?tab=adminRequests'
);