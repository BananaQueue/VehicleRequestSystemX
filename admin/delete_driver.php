<?php
require_once __DIR__ . '/../includes/session.php';
require '../db.php';
require_once __DIR__ . '/delete_entry.php'; // Include the generic delete utility

require_role('admin', '../login.php');

// Define a pre-delete action to unassign the driver from vehicles
$preDeleteDriverAction = function(PDO $pdo, int $id, array $item): array {
    try {
        $driverName = $item['name'];
        $updateVehicles = $pdo->prepare("UPDATE vehicles SET driver_name = NULL WHERE driver_name = ?");
        $updateVehicles->execute([$driverName]);
        return ['success' => true];
    } catch (PDOException $e) {
        error_log("Pre-delete Driver Action PDO Error: " . $e->getMessage());
        return ['success' => false, 'message' => "Failed to unassign driver from vehicles."];
    }
};

handle_delete_entry(
    $pdo,
    'drivers',
    'id',
    'name',
    "../dashboardX.php#driverManagement",
    ['pre_delete_action' => $preDeleteDriverAction]
);
