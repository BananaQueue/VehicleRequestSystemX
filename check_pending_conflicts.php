<?php
/**
 * API endpoint to check for pending request conflicts
 * Returns JSON with conflict information for admin review
 */

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/schedule_utils.php';
require 'db.php';

// Set JSON header
header('Content-Type: application/json');

// Check if user is admin
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Validate CSRF token
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit();
    }
}

$request_id = filter_var($_POST['request_id'] ?? 0, FILTER_VALIDATE_INT);
$vehicle_plate = $_POST['vehicle'] ?? '';
$driver_name = $_POST['driver'] ?? '';
$departure_date = $_POST['departure_date'] ?? '';
$return_date = $_POST['return_date'] ?? '';

if (!$request_id || !$vehicle_plate || !$departure_date) {
    echo json_encode(['error' => 'Missing required parameters']);
    exit();
}

// Use return_date if available, otherwise use departure_date
$end_date = $return_date ?: $departure_date;

try {
    $response = [
        'has_conflicts' => false,
        'vehicle_conflict' => null,
        'driver_conflict' => null
    ];

    // Get vehicle ID from plate number
    $stmt = $pdo->prepare("SELECT id FROM vehicles WHERE plate_number = :plate");
    $stmt->execute([':plate' => $vehicle_plate]);
    $vehicle_id = $stmt->fetchColumn();

    if ($vehicle_id) {
        // Check for pending vehicle conflicts
        $vehicleConflict = get_pending_vehicle_conflict($pdo, (int)$vehicle_id, $departure_date, $end_date, $request_id);
        
        if ($vehicleConflict) {
            $response['has_conflicts'] = true;
            
            $conflictDates = date('M j, Y', strtotime($vehicleConflict['departure_date']));
            if ($vehicleConflict['return_date'] && $vehicleConflict['return_date'] != $vehicleConflict['departure_date']) {
                $conflictDates .= ' - ' . date('M j, Y', strtotime($vehicleConflict['return_date']));
            }
            
            $response['vehicle_conflict'] = [
                'requestor' => $vehicleConflict['requestor_name'],
                'dates' => $conflictDates,
                'destination' => $vehicleConflict['destination'],
                'message' => sprintf(
                    'Vehicle %s is already assigned to %s for %s (Destination: %s)',
                    $vehicle_plate,
                    $vehicleConflict['requestor_name'],
                    $conflictDates,
                    $vehicleConflict['destination']
                )
            ];
        }
    }

    // Check driver conflicts if driver is assigned
    if ($driver_name && $driver_name !== '----' && $driver_name !== 'TBD') {
        // Get driver ID from name
        $stmt = $pdo->prepare("SELECT id FROM drivers WHERE name = :name");
        $stmt->execute([':name' => $driver_name]);
        $driver_id = $stmt->fetchColumn();

        if ($driver_id) {
            $driverConflict = get_pending_driver_conflict($pdo, (int)$driver_id, $departure_date, $end_date, $request_id);
            
            if ($driverConflict) {
                $response['has_conflicts'] = true;
                
                $conflictDates = date('M j, Y', strtotime($driverConflict['departure_date']));
                if ($driverConflict['return_date'] && $driverConflict['return_date'] != $driverConflict['departure_date']) {
                    $conflictDates .= ' - ' . date('M j, Y', strtotime($driverConflict['return_date']));
                }
                
                $response['driver_conflict'] = [
                    'requestor' => $driverConflict['requestor_name'],
                    'dates' => $conflictDates,
                    'destination' => $driverConflict['destination'],
                    'message' => sprintf(
                        'Driver %s is already assigned to %s for %s (Destination: %s)',
                        $driver_name,
                        $driverConflict['requestor_name'],
                        $conflictDates,
                        $driverConflict['destination']
                    )
                ];
            }
        }
    }

    echo json_encode($response);

} catch (Exception $e) {
    error_log("Check Pending Conflicts Error: " . $e->getMessage());
    echo json_encode(['error' => 'Server error occurred']);
}
?>