<?php
require_once __DIR__ . '/includes/session.php';
require 'db.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'employee') {
    header("Location: login.php");
    exit;
}

$vehicle_id = $_GET['id'] ?? null;
$user = $_SESSION['user']['name'];

// Validate vehicle_id as an integer
if (!filter_var($vehicle_id, FILTER_VALIDATE_INT)) {
    // Handle invalid ID, e.g., redirect or show error
    $_SESSION['error'] = "Invalid vehicle ID.";
    header("Location: dashboardX.php"); // Redirect back to dashboard
    exit();
}

if ($vehicle_id) {
    try {
        $stmt = $pdo->prepare("UPDATE vehicles SET status = 'returning', return_date = NOW(), returned_by = :returned_by WHERE id = :id AND assigned_to = :assigned_to");
        $stmt->execute([
            ':id' => $vehicle_id,
            ':assigned_to' => $user,
            ':returned_by' => $user
        ]);
        header("Location: dashboardX.php");
    } catch (PDOException $e) {
        error_log("Return Vehicle PDO Error: " . $e->getMessage(), 0);
        $_SESSION['error'] = "An unexpected error occurred while returning the vehicle.";
        header("Location: dashboardX.php");
        exit();
    }
}
?>
