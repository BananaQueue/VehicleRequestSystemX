<?php
require_once __DIR__ . '/includes/session.php';
require 'db.php';


if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$vehicle_id = $_GET['id'] ?? null;

// Validate vehicle_id as an integer
if (!filter_var($vehicle_id, FILTER_VALIDATE_INT)) {
    $_SESSION['error'] = "Invalid vehicle ID.";
    header("Location: dashboardX.php");
    exit();
}

if ($vehicle_id) {
    try {
        // Start transaction
        $pdo->beginTransaction();
        
        // First, get the vehicle's current driver (if any) before updating
        $stmt = $pdo->prepare("SELECT driver_name FROM vehicles WHERE id = ?");
        $stmt->execute([$vehicle_id]);
        $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Update vehicle status to available and clear assignments
        $stmt = $pdo->prepare("UPDATE vehicles SET status = 'available', assigned_to = NULL, driver_name = NULL, returned_by = NULL, return_date = NULL WHERE id = ?");
        $stmt->execute([$vehicle_id]);
        
        // If there was a driver assigned, update their status to 'available'
        if (!empty($vehicle['driver_name'])) {
            $stmt = $pdo->prepare("UPDATE drivers SET status = 'available' WHERE name = ?");
            $stmt->execute([$vehicle['driver_name']]);
        }
        
        $pdo->commit();
        $_SESSION['success'] = "Vehicle return processed successfully. Vehicle and driver are now available.";
        header("Location: dashboardX.php");
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Process Return PDO Error: " . $e->getMessage(), 0);
        $_SESSION['error'] = "An unexpected error occurred while processing the return.";
        header("Location: dashboardX.php");
        exit();
    }
}
?>