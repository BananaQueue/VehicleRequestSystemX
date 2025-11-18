<?php 
require_once __DIR__ . '/../includes/session.php';
require '../db.php';

// ✅ STANDARDIZED: Use helper function instead of manual check
require_role('admin', '../login.php');

// ✅ STANDARDIZED: Only allow POST, not GET
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = "Invalid request method.";
    header("Location: ../dashboardX.php");
    exit();
}

// ✅ STANDARDIZED: Use helper function instead of manual CSRF validation
validate_csrf_token_post('../dashboardX.php', 'Invalid security token.');
error_log("CSRF validation passed");

// ✅ STANDARDIZED: Consistent input validation
$id = $_POST['id'] ?? null;
if (!filter_var($id, FILTER_VALIDATE_INT)) {
    $_SESSION['error'] = "Invalid vehicle ID.";
    header("Location: ../dashboardX.php");
    exit();
}

try {
    // ✅ STANDARDIZED: Start transaction for data consistency
    $pdo->beginTransaction();
    
    // First, get the vehicle details to check if there's an assigned driver
    $stmt = $pdo->prepare("SELECT driver_name, plate_number FROM vehicles WHERE id = ?");
    $stmt->execute([$id]);
    $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$vehicle) {
        $pdo->rollBack();
        $_SESSION['error'] = "Vehicle not found.";
        header("Location: ../dashboardX.php");
        exit();
    }
    
    // If there's an assigned driver, update their status to 'available'
    if (!empty($vehicle['driver_name'])) {
        $stmt = $pdo->prepare("UPDATE drivers SET status = 'available' WHERE name = ?");
        $stmt->execute([$vehicle['driver_name']]);
    }
    
    // Delete the vehicle
    $stmt = $pdo->prepare("DELETE FROM vehicles WHERE id = ?");
    $result = $stmt->execute([$id]);
    
    if ($result && $stmt->rowCount() > 0) {
        $pdo->commit();
        
        $successMessage = "Vehicle '" . htmlspecialchars($vehicle['plate_number']) . "' deleted successfully.";
        if (!empty($vehicle['driver_name'])) {
            $successMessage .= " Driver " . htmlspecialchars($vehicle['driver_name']) . " is now available for assignment.";
        }
        
        $_SESSION['success'] = $successMessage;
    } else {
        $pdo->rollBack();
        $_SESSION['error'] = "Failed to delete vehicle.";
    }
    
} catch (PDOException $e) {
    // ✅ STANDARDIZED: Rollback transaction on error
    $pdo->rollBack();
    // ✅ STANDARDIZED: Consistent error logging pattern
    error_log("Delete Vehicle PDO Error: " . $e->getMessage());
    $_SESSION['error'] = "An unexpected error occurred while deleting the vehicle.";
}

// ✅ STANDARDIZED: Always include exit() after header redirect
header("Location: ../dashboardX.php");
exit();