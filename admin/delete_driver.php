<?php
// DO NOT call session_name here - it's handled in session.php
require_once __DIR__ . '/../includes/session.php';
require '../db.php';

// Debug: Log everything
error_log("=== DELETE DRIVER DEBUG ===");
error_log("Session ID: " . session_id());
error_log("Session exists: " . (isset($_SESSION['user']) ? 'YES' : 'NO'));
if (isset($_SESSION['user'])) {
    error_log("User role: '" . $_SESSION['user']['role'] . "'");
    error_log("User name: " . $_SESSION['user']['name']);
}
error_log("POST data: " . print_r($_POST, true));

// Check if user is logged in and is admin
if (!isset($_SESSION['user'])) {
    error_log("FAILED: No user in session");
    $_SESSION['error'] = "Session expired. Please log in again.";
    header("Location: ../login.php");
    exit();
}

if ($_SESSION['user']['role'] !== 'admin') {
    error_log("FAILED: Role mismatch. Expected 'admin', got '" . $_SESSION['user']['role'] . "'");
    $_SESSION['error'] = "Access denied. Admin privileges required.";
    header("Location: ../login.php");
    exit();
}

error_log("Auth check passed");

// Only allow POST, not GET
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = "Invalid request method.";
    header("Location: ../dashboardX.php");
    exit();
}

// Validate CSRF token
validate_csrf_token_post('../dashboardX.php', 'Invalid security token.');
error_log("CSRF validation passed");

// Consistent input validation
$driver_id = $_POST['id'] ?? null;
if (!filter_var($driver_id, FILTER_VALIDATE_INT)) {
    $_SESSION['error'] = "Invalid driver ID.";
    header("Location: ../dashboardX.php");
    exit();
}

error_log("Processing delete for driver ID: " . $driver_id);

try {
    // Start transaction for data consistency
    $pdo->beginTransaction();
    
    // Get driver info first
    $stmt = $pdo->prepare("SELECT name FROM drivers WHERE id = ?");
    $stmt->execute([$driver_id]);
    $driver = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$driver) {
        $pdo->rollBack();
        $_SESSION['error'] = "Driver not found.";
        header("Location: ../dashboardX.php");
        exit();
    }

    error_log("Driver found: " . $driver['name']);

    // Unassign driver from vehicles (set driver_name to NULL)
    $updateVehicles = $pdo->prepare("UPDATE vehicles SET driver_name = NULL WHERE driver_name = ?");
    $updateVehicles->execute([$driver['name']]);

    // Delete the driver
    $stmt = $pdo->prepare("DELETE FROM drivers WHERE id = ?");
    $result = $stmt->execute([$driver_id]);

    if ($result) {
        $pdo->commit();
        error_log("SUCCESS: Driver deleted");
        $_SESSION['success'] = "Driver '" . htmlspecialchars($driver['name']) . "' deleted successfully and unassigned from all vehicles.";
    } else {
        $pdo->rollBack();
        $_SESSION['error'] = "Failed to delete driver.";
    }

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Delete Driver PDO Error: " . $e->getMessage());
    $_SESSION['error'] = "An unexpected error occurred while deleting the driver.";
}

error_log("=== END DELETE DRIVER DEBUG ===");
header("Location: ../dashboardX.php");
exit();