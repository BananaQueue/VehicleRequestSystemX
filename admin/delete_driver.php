<?php
require_once __DIR__ . '/../includes/session.php';
require '../db.php';
require_once __DIR__ . '/delete_entry.php'; // Include the generic delete utility

require_role('admin', '../login.php');

// Define a pre-delete action to unassign the driver from vehicles
$preDeleteDriverAction = function(PDO $pdo, int $id, array $item): array {
    try {
        // Unassign vehicles by setting driver_id to NULL
        $updateVehicles = $pdo->prepare("UPDATE vehicles SET driver_id = NULL WHERE driver_id = ?");
        $updateVehicles->execute([$id]);
        return ['success' => true];
    } catch (PDOException $e) {
        error_log("Pre-delete Driver Action PDO Error: " . $e->getMessage());
        return ['success' => false, 'message' => "Failed to unassign driver from vehicles."];
    }
};

// Add WHERE clause to only delete users with role='driver'
$customWhereClause = "role = 'driver'";

handle_delete_entry(
    $pdo,
    'users',
    'id',
    'name',
    "../dashboardX.php#driverManagement",
    [
        'pre_delete_action' => $preDeleteDriverAction,
        'where_clause' => $customWhereClause
    ]
);