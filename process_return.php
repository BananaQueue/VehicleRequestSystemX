<?php
require_once __DIR__ . '/includes/session.php';
require 'db.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    $_SESSION['error'] = "Access denied. Admin privileges required.";
    header("Location: dashboardX.php");
    exit();
}

// Get vehicle ID from URL
$vehicle_id = $_GET['id'] ?? null;

if (!$vehicle_id) {
    $_SESSION['error'] = "Invalid vehicle ID.";
    header("Location: dashboardX.php?tab=adminReturns");
    exit();
}

try {
    $pdo->beginTransaction();
    
    // Get vehicle details
    $stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id = :id AND status = 'returning'");
    $stmt->execute(['id' => $vehicle_id]);
    $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$vehicle) {
        throw new Exception("Vehicle not found or not in returning status.");
    }
    
    $returned_by = $vehicle['returned_by'];
    $plate_number = $vehicle['plate_number'];
    
    // Update vehicle status to available and clear assignments
    $stmt = $pdo->prepare("
        UPDATE vehicles 
        SET status = 'available', 
            assigned_to = NULL, 
            returned_by = NULL, 
            driver_name = NULL,
            return_date = NULL
        WHERE id = :id
    ");
    $stmt->execute(['id' => $vehicle_id]);
    
    // UPDATED: Mark the associated request as 'concluded' instead of keeping it 'approved'
    $stmt = $pdo->prepare("
        UPDATE requests 
        SET status = 'concluded'
        WHERE assigned_vehicle_id = :vehicle_id 
        AND status = 'approved'
    ");
    $result = $stmt->execute(['vehicle_id' => $vehicle_id]);
    
    $pdo->commit();
    
    // Log the action
    error_log("Vehicle return processed: Vehicle ID {$vehicle_id} ({$plate_number}) returned by {$returned_by}, request marked as concluded");
    
    $_SESSION['success'] = "Vehicle return processed successfully. The request has been marked as concluded and {$returned_by} is now available for new assignments.";
    header("Location: dashboardX.php?tab=adminReturns");
    exit();
    
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Return Processing Error: " . $e->getMessage());
    $_SESSION['error'] = "Error processing return: " . $e->getMessage();
    header("Location: dashboardX.php?tab=adminReturns");
    exit();
}
?>