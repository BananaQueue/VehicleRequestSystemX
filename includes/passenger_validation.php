<?php
function validatePassengerAvailability($pdo, $passengerName, $excludeRequestId = null) {
    $currentDate = date('Y-m-d');
    
    // Check if passenger has active request (as a driver/requestor)
    if (!is_null($excludeRequestId)) {
        $sql = "
            SELECT r.id, r.status, r.departure_date, r.return_date
            FROM requests r
            INNER JOIN users u ON u.id = r.user_id
            WHERE u.name = ?
            AND (r.status IN ('pending_dispatch_assignment', 'pending_admin_approval') 
                OR (r.status = 'approved' AND r.departure_date <= ? AND r.return_date >= ?))
            AND r.id != ?
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$passengerName, $currentDate, $currentDate, $excludeRequestId]);
    } else {
        $sql = "
            SELECT r.id, r.status, r.departure_date, r.return_date
            FROM requests r
            INNER JOIN users u ON u.id = r.user_id
            WHERE u.name = ?
            AND (r.status IN ('pending_dispatch_assignment', 'pending_admin_approval') 
                OR (r.status = 'approved' AND r.departure_date <= ? AND r.return_date >= ?))
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$passengerName, $currentDate, $currentDate]);
    }
    
    $activeRequest = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($activeRequest) {
        return [
            'available' => false,
            'reason' => 'has_active_request',
            'message' => 'This employee is an active requestor or has a pending request.',
            'details' => $activeRequest
        ];
    }

    // Check if passenger is already an active passenger on an approved request
    if (!is_null($excludeRequestId)) {
        $sql = "
            SELECT id, departure_date, return_date
            FROM requests
            WHERE JSON_SEARCH(passenger_names, 'one', ?) IS NOT NULL
            AND status = 'approved'
            AND departure_date <= ? 
            AND return_date >= ?
            AND id != ?
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$passengerName, $currentDate, $currentDate, $excludeRequestId]);
    } else {
        $sql = "
            SELECT id, departure_date, return_date
            FROM requests
            WHERE JSON_SEARCH(passenger_names, 'one', ?) IS NOT NULL
            AND status = 'approved'
            AND departure_date <= ? 
            AND return_date >= ?
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$passengerName, $currentDate, $currentDate]);
    }
    
    $activePassengerRequest = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($activePassengerRequest) {
        return [
            'available' => false,
            'reason' => 'has_active_passenger_request',
            'message' => 'This employee is already an active passenger on an approved vehicle request.',
            'details' => $activePassengerRequest
        ];
    }

    // Check for assigned vehicle
    $stmt = $pdo->prepare("
        SELECT plate_number
        FROM vehicles
        WHERE assigned_to = ? AND status = 'assigned'
        LIMIT 1
    ");
    $stmt->execute([$passengerName]);
    $assignedVehicle = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($assignedVehicle) {
        return [
            'available' => false,
            'reason' => 'has_assigned_vehicle',
            'message' => 'This employee has an assigned vehicle: ' . $assignedVehicle['plate_number'],
            'details' => $assignedVehicle
        ];
    }

    // Check for returning vehicle
    $stmt = $pdo->prepare("
        SELECT plate_number
        FROM vehicles
        WHERE returned_by = ? AND status = 'returning'
        LIMIT 1
    ");
    $stmt->execute([$passengerName]);
    $returningVehicle = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($returningVehicle) {
        return [
            'available' => false,
            'reason' => 'returning_vehicle',
            'message' => 'This employee is returning vehicle: ' . $returningVehicle['plate_number'],
            'details' => $returningVehicle
        ];
    }

    return ['available' => true, 'message' => 'Available'];
}

function getEmployeeListWithAvailability($pdo, $excludeRequestId = null) {
    $stmt = $pdo->query("SELECT id, name, email, position FROM users WHERE role = 'employee' ORDER BY name ASC");
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($employees as &$employee) {
        $validation = validatePassengerAvailability($pdo, $employee['name'], $excludeRequestId);
        $employee['is_available'] = $validation['available'];
        $employee['unavailable_reason'] = $validation['message'] ?? '';
        $employee['validation_details'] = $validation;
    }

    return $employees;
}
?>