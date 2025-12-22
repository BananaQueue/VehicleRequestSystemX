<?php 
require_once __DIR__ . '/../includes/session.php';
require '../db.php';
require_once __DIR__ . '/delete_entry.php'; // Include the generic delete utility

require_role('admin', '../login.php');

// Define a pre-delete action to update the associated driver's status
$preDeleteVehicleAction = function(PDO $pdo, int $id, array $item): array {
    try {
        $vehiclePlate = $item['plate_number'] ?? '';
        $driverName = $item['driver_name'] ?? null;

        if (!empty($driverName)) {
            $stmt = $pdo->prepare("UPDATE drivers SET status = 'available' WHERE name = ?");
            $stmt->execute([$driverName]);
        }

        $message = "Vehicle '" . htmlspecialchars($vehiclePlate) . "' deleted successfully.";
        if (!empty($driverName)) {
            $message .= " Driver '" . htmlspecialchars($driverName) . "' is now available for assignment.";
        }

        return ['success' => true, 'message' => $message];
    } catch (PDOException $e) {
        error_log("Pre-delete Vehicle Action PDO Error: " . $e->getMessage());
        return ['success' => false, 'message' => "Failed to update driver status before deleting vehicle."];
    }
};

handle_delete_entry(
    $pdo,
    'vehicles',
    'id',
    'plate_number',
    "../dashboardX.php",
    ['pre_delete_action' => $preDeleteVehicleAction]
);
