<?php
function validatePassengerAvailability($pdo, $passengerName, $excludeRequestId = null) {
    // Check if passenger has active request
    $stmt = $pdo->prepare("
        SELECT id, status, departure_date, return_date
        FROM requests 
        WHERE user_id = (SELECT id FROM users WHERE name = :name)
        AND status IN ('pending_dispatch_assignment', 'pending_admin_approval', 'approved')
        " . ($excludeRequestId ? "AND id != :exclude_id" : "") . "
        LIMIT 1
    ");
    
    $params = ['name' => $passengerName];
    if ($excludeRequestId) {
        $params['exclude_id'] = $excludeRequestId;
    }
    
    $stmt->execute($params);
    $activeRequest = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($activeRequest) {
        return [
            'available' => false,
            'reason' => 'has_active_request',
            'message' => 'This employee has an active vehicle request',
            'details' => $activeRequest
        ];
    }
    
    // Check for assigned vehicle
    $stmt = $pdo->prepare("
        SELECT plate_number 
        FROM vehicles 
        WHERE assigned_to = :name AND status = 'assigned'
        LIMIT 1
    ");
    $stmt->execute(['name' => $passengerName]);
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
        WHERE returned_by = :name AND status = 'returning'
        LIMIT 1
    ");
    $stmt->execute(['name' => $passengerName]);
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