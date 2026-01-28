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

/**
 * OPTIMIZED: Batch fetch all employee availability instead of N queries
 * Combines constraint checks into single multi-join query
 */
function getEmployeeListWithAvailability($pdo, $excludeRequestId = null) {
    $currentDate = date('Y-m-d');
    $today = $currentDate;
    
    // OPTIMIZATION: Single query gets all employees + their constraint violations
    $stmt = $pdo->prepare("
        SELECT 
            u.id,
            u.name,
            u.email,
            u.position,
            -- Check for active passenger request
            MAX(CASE WHEN 
                JSON_SEARCH(r.passenger_names, 'one', u.name) IS NOT NULL
                AND r.status = 'approved'
                AND r.departure_date <= ?
                AND r.return_date >= ?
                THEN 'has_active_passenger_request' 
            END) as violation_type,
            MAX(CASE WHEN 
                JSON_SEARCH(r.passenger_names, 'one', u.name) IS NOT NULL
                AND r.status = 'approved'
                AND r.departure_date <= ?
                AND r.return_date >= ?
            THEN r.id END) as active_request_id,
            -- Check for assigned vehicle
            MAX(CASE WHEN 
                v1.assigned_to = u.name 
                AND v1.status = 'assigned' 
            THEN v1.plate_number END) as assigned_vehicle,
            -- Check for returning vehicle
            MAX(CASE WHEN 
                v2.returned_by = u.name 
                AND v2.status = 'returning' 
            THEN v2.plate_number END) as returning_vehicle
        FROM users u
        LEFT JOIN requests r ON r.status = 'approved'
        LEFT JOIN vehicles v1 ON v1.assigned_to = u.name AND v1.status = 'assigned'
        LEFT JOIN vehicles v2 ON v2.returned_by = u.name AND v2.status = 'returning'
        WHERE u.role = 'employee'
        GROUP BY u.id, u.name, u.email, u.position
        ORDER BY u.name ASC
    ");
    
    $stmt->execute([$today, $today, $today, $today]);
    $employeeData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process results to add availability flags
    foreach ($employeeData as &$employee) {
        $employee['is_available'] = true;
        $employee['unavailable_reason'] = '';
        $employee['validation_details'] = ['available' => true];
        
        // Check violations
        if ($employee['violation_type'] === 'has_active_passenger_request') {
            $employee['is_available'] = false;
            $employee['unavailable_reason'] = 'This employee is already an active passenger on an approved vehicle request.';
            $employee['validation_details'] = [
                'available' => false,
                'reason' => 'has_active_passenger_request',
                'message' => $employee['unavailable_reason']
            ];
        } elseif (!empty($employee['assigned_vehicle'])) {
            $employee['is_available'] = false;
            $employee['unavailable_reason'] = 'This employee has an assigned vehicle: ' . $employee['assigned_vehicle'];
            $employee['validation_details'] = [
                'available' => false,
                'reason' => 'has_assigned_vehicle',
                'message' => $employee['unavailable_reason']
            ];
        } elseif (!empty($employee['returning_vehicle'])) {
            $employee['is_available'] = false;
            $employee['unavailable_reason'] = 'This employee is returning vehicle: ' . $employee['returning_vehicle'];
            $employee['validation_details'] = [
                'available' => false,
                'reason' => 'returning_vehicle',
                'message' => $employee['unavailable_reason']
            ];
        }
        
        // Clean up temp columns
        unset($employee['violation_type']);
        unset($employee['active_request_id']);
        unset($employee['assigned_vehicle']);
        unset($employee['returning_vehicle']);
    }
    
    return $employeeData;
}
?>