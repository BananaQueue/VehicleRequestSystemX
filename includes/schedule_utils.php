<?php

/**
 * Normalize the requested travel dates for a request record.
 *
 * @param array $request
 * @return array [$startDate, $endDate] in Y-m-d format or [null, null] if unavailable.
 */
function get_request_date_range(array $request): array
{
    $start = $request['departure_date'] ?? null;
    $end = $request['return_date'] ?? null;

    if (!$start && isset($request['request_date'])) {
        $start = date('Y-m-d', strtotime($request['request_date']));
    }

    if (!$end) {
        $end = $start;
    }

    if (!$start) {
        return [null, null];
    }

    return [
        date('Y-m-d', strtotime($start)),
        date('Y-m-d', strtotime($end)),
    ];
}

/**
 * Check if a vehicle has a scheduling conflict with approved requests
 * FIXED: Only checks 'approved' status, not 'pending_admin_approval'
 * Vehicles should only be unavailable for APPROVED bookings
 */
function has_vehicle_conflict(PDO $pdo, int $vehicleId, string $startDate, string $endDate, ?int $excludeRequestId = null): bool
{
    $query = "
        SELECT COUNT(*) FROM requests
        WHERE assigned_vehicle_id = :vehicle_id
          AND status = 'approved'
          AND COALESCE(departure_date, DATE(request_date)) IS NOT NULL
          AND :start_date <= COALESCE(return_date, departure_date, DATE(request_date))
          AND :end_date >= COALESCE(departure_date, DATE(request_date))
    ";

    $params = [
        ':vehicle_id' => $vehicleId,
        ':start_date' => $startDate,
        ':end_date' => $endDate,
    ];

    if ($excludeRequestId) {
        $query .= " AND id != :exclude_id";
        $params[':exclude_id'] = $excludeRequestId;
    }

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);

    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Check if a driver has a scheduling conflict with approved requests
 * FIXED: Only checks 'approved' status, not 'pending_admin_approval'
 * Drivers should only be unavailable for APPROVED bookings
 */
function has_driver_conflict(PDO $pdo, int $driverId, string $startDate, string $endDate, ?int $excludeRequestId = null): bool
{
    $query = "
        SELECT COUNT(*) FROM requests
        WHERE assigned_driver_id = :driver_id
          AND status = 'approved'
          AND COALESCE(departure_date, DATE(request_date)) IS NOT NULL
          AND :start_date <= COALESCE(return_date, departure_date, DATE(request_date))
          AND :end_date >= COALESCE(departure_date, DATE(request_date))
    ";

    $params = [
        ':driver_id' => $driverId,
        ':start_date' => $startDate,
        ':end_date' => $endDate,
    ];

    if ($excludeRequestId) {
        $query .= " AND id != :exclude_id";
        $params[':exclude_id'] = $excludeRequestId;
    }

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);

    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Check if a vehicle has pending approval conflicts (dispatch assigned same vehicle to multiple requests)
 * Returns details of conflicting request if found
 */
function get_pending_vehicle_conflict(PDO $pdo, int $vehicleId, string $startDate, string $endDate, ?int $excludeRequestId = null): ?array
{
    $query = "
        SELECT 
            r.id,
            r.requestor_name,
            r.departure_date,
            r.return_date,
            r.destination
        FROM requests r
        WHERE r.assigned_vehicle_id = :vehicle_id
          AND r.status = 'pending_admin_approval'
          AND COALESCE(r.departure_date, DATE(r.request_date)) IS NOT NULL
          AND :start_date <= COALESCE(r.return_date, r.departure_date, DATE(r.request_date))
          AND :end_date >= COALESCE(r.departure_date, DATE(r.request_date))
    ";

    $params = [
        ':vehicle_id' => $vehicleId,
        ':start_date' => $startDate,
        ':end_date' => $endDate,
    ];

    if ($excludeRequestId) {
        $query .= " AND r.id != :exclude_id";
        $params[':exclude_id'] = $excludeRequestId;
    }
    
    $query .= " LIMIT 1";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);

    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}

/**
 * Check if a driver has pending approval conflicts (dispatch assigned same driver to multiple requests)
 * Returns details of conflicting request if found
 */
function get_pending_driver_conflict(PDO $pdo, int $driverId, string $startDate, string $endDate, ?int $excludeRequestId = null): ?array
{
    $query = "
        SELECT 
            r.id,
            r.requestor_name,
            r.departure_date,
            r.return_date,
            r.destination
        FROM requests r
        WHERE r.assigned_driver_id = :driver_id
          AND r.status = 'pending_admin_approval'
          AND COALESCE(r.departure_date, DATE(r.request_date)) IS NOT NULL
          AND :start_date <= COALESCE(r.return_date, r.departure_date, DATE(r.request_date))
          AND :end_date >= COALESCE(r.departure_date, DATE(r.request_date))
    ";

    $params = [
        ':driver_id' => $driverId,
        ':start_date' => $startDate,
        ':end_date' => $endDate,
    ];

    if ($excludeRequestId) {
        $query .= " AND r.id != :exclude_id";
        $params[':exclude_id'] = $excludeRequestId;
    }
    
    $query .= " LIMIT 1";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);

    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}

/**
 * UPDATED: Sync active assignments - no more driver status management
 */
function sync_active_assignments(PDO $pdo): void
{
    $today = date('Y-m-d');

    // Release vehicles from past trips
    $pastTripsStmt = $pdo->prepare("
        SELECT DISTINCT r.assigned_vehicle_id
        FROM requests r
        WHERE r.status = 'approved'
          AND r.assigned_vehicle_id IS NOT NULL
          AND COALESCE(r.return_date, r.departure_date, DATE(r.request_date)) < :today
    ");
    $pastTripsStmt->execute([':today' => $today]);
    $pastTrips = $pastTripsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Release vehicles from past trips
    foreach ($pastTrips as $pastTrip) {
        if ($pastTrip['assigned_vehicle_id']) {
            $releaseVehicle = $pdo->prepare("
                UPDATE vehicles
                SET status = 'available',
                    assigned_to = NULL,
                    driver_id = NULL
                WHERE id = :id AND status = 'assigned'
            ");
            $releaseVehicle->execute([':id' => $pastTrip['assigned_vehicle_id']]);
        }
        // REMOVED: Driver status update - drivers don't have status field anymore
    }

    // Handle TODAY's active trips
    $activeStmt = $pdo->prepare("
        SELECT 
            r.id,
            r.requestor_name,
            r.assigned_vehicle_id,
            r.assigned_driver_id,
            dr.name AS driver_name
        FROM requests r
        LEFT JOIN users dr ON dr.id = r.assigned_driver_id AND dr.role = 'driver'
        WHERE r.status = 'approved'
          AND r.assigned_vehicle_id IS NOT NULL
          AND COALESCE(r.departure_date, DATE(r.request_date)) <= :today_start
          AND COALESCE(r.return_date, r.departure_date, DATE(r.request_date)) >= :today_end
    ");
    $activeStmt->execute([
        ':today_start' => $today,
        ':today_end' => $today,
    ]);

    $activeVehicleIds = [];

    while ($row = $activeStmt->fetch(PDO::FETCH_ASSOC)) {
        $vehicleId = $row['assigned_vehicle_id'];

        if ($vehicleId) {
            $activeVehicleIds[] = $vehicleId;
            $vehicleUpdate = $pdo->prepare("
                UPDATE vehicles
                SET status = 'assigned',
                    assigned_to = :assigned_to,
                    driver_id = :driver_id
                WHERE id = :id
            ");
            $vehicleUpdate->execute([
                ':assigned_to' => $row['requestor_name'],
                ':driver_id' => $row['assigned_driver_id'],
                ':id' => $vehicleId,
            ]);
        }
        // REMOVED: Driver status update - drivers have no status field
    }
    
    // Remove null or empty IDs
    $activeVehicleIds = array_values(array_filter($activeVehicleIds));

    // Release any remaining vehicles that are marked assigned but have no active trips today
    if (!empty($activeVehicleIds)) {
        $placeholders = implode(',', array_fill(0, count($activeVehicleIds), '?'));
        $releaseStmt = $pdo->prepare("
            UPDATE vehicles
            SET status = 'available',
                assigned_to = NULL,
                driver_id = NULL
            WHERE status = 'assigned'
              AND id NOT IN ($placeholders)
        ");
        $releaseStmt->execute($activeVehicleIds);
    } else {
        $pdo->exec("
            UPDATE vehicles
            SET status = 'available',
                assigned_to = NULL,
                driver_id = NULL
            WHERE status = 'assigned'
        ");
    }
    
    // REMOVED: All driver release logic - drivers no longer have status field
}

/**
 * Get trip status display for drivers or employees
 * Used in admin dashboard to show driver availability and employee trip status
 * 
 * @param array $personData User data (driver or employee) with 'id' and 'name' keys
 * @param PDO $pdo Database connection
 * @param bool $isDriver Whether checking driver or employee
 * @return array Status info with 'status' text and 'status_class' CSS class
 */
function get_person_trip_status(array $personData, PDO $pdo, bool $isDriver = false): array
{
    $today = date('Y-m-d');

    if ($isDriver) {
        // For drivers, check if they're assigned to an active trip
        $stmt = $pdo->prepare("
            SELECT r.*, v.plate_number, r.departure_date, r.return_date
            FROM requests r
            INNER JOIN vehicles v ON v.id = r.assigned_vehicle_id
            WHERE r.assigned_driver_id = :driver_id
            AND r.status = 'approved'
            AND COALESCE(r.return_date, r.departure_date) >= :today
            ORDER BY r.departure_date ASC
        ");
        $stmt->execute([
            'driver_id' => $personData['id'],
            'today' => $today
        ]);
        $trips = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($trips)) {
            return [
                'status' => 'No Scheduled Trips',
                'status_class' => 'status-available'
            ];
        }

        // Check if currently on a trip (departure <= today <= return)
        foreach ($trips as $trip) {
            $departure = $trip['departure_date'] ?? $today;
            $return = $trip['return_date'] ?? $departure;

            if ($departure <= $today && $today <= $return) {
                return [
                    'status' => 'Currently On Trip: ' . $trip['plate_number'],
                    'status_class' => 'status-assigned',
                    'is_active' => true
                ];
            }
        }

        // Show upcoming trips
        $tripCount = count($trips);
        $nextTrip = $trips[0];
        $nextDeparture = date('M j', strtotime($nextTrip['departure_date']));

        if ($tripCount == 1) {
            return [
                'status' => '1 Trip: ' . $nextDeparture . ' (' . $nextTrip['plate_number'] . ')',
                'status_class' => 'status-returning'
            ];
        } else {
            return [
                'status' => $tripCount . ' Trips: Next on ' . $nextDeparture,
                'status_class' => 'status-returning'
            ];
        }
    } else {
        // For employees, check their trips as requestor or passenger
        $employeeName = $personData['name'];
        $employeeId = $personData['id'];

        // Check for trips as requestor
        $stmt = $pdo->prepare("
            SELECT r.*, v.plate_number, r.departure_date, r.return_date
            FROM requests r
            LEFT JOIN vehicles v ON v.id = r.assigned_vehicle_id
            WHERE r.user_id = :user_id
            AND r.status = 'approved'
            AND COALESCE(r.return_date, r.departure_date) >= :today
            ORDER BY r.departure_date ASC
        ");
        $stmt->execute([
            'user_id' => $employeeId,
            'today' => $today
        ]);
        $requestorTrips = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Check for trips as passenger
        $stmt = $pdo->prepare("
            SELECT r.*, v.plate_number, r.departure_date, r.return_date, r.requestor_name
            FROM requests r
            LEFT JOIN vehicles v ON v.id = r.assigned_vehicle_id
            WHERE r.status = 'approved'
            AND COALESCE(r.return_date, r.departure_date) >= :today
            AND JSON_SEARCH(r.passenger_names, 'one', :name) IS NOT NULL
            ORDER BY r.departure_date ASC
        ");
        $stmt->execute([
            'name' => $employeeName,
            'today' => $today
        ]);
        $passengerTrips = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Check for assigned vehicle (currently on trip)
        $stmt = $pdo->prepare("
            SELECT plate_number FROM vehicles 
            WHERE assigned_to = :name AND status = 'assigned' LIMIT 1
        ");
        $stmt->execute(['name' => $employeeName]);
        $assignedVehicle = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($assignedVehicle) {
            return [
                'status' => 'Currently On Trip: ' . $assignedVehicle['plate_number'],
                'status_class' => 'status-assigned',
                'is_active' => true
            ];
        }

        // Check for pending requests
        $stmt = $pdo->prepare("
            SELECT status FROM requests 
            WHERE user_id = :user_id 
            ORDER BY request_date DESC LIMIT 1
        ");
        $stmt->execute(['user_id' => $employeeId]);
        $latestRequest = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($latestRequest && $latestRequest['status'] === 'pending_admin_approval') {
            return [
                'status' => 'Awaiting Admin Approval',
                'status_class' => 'status-returning'
            ];
        }

        // Combine all trips
        $allTrips = array_merge($requestorTrips, $passengerTrips);

        if (empty($allTrips)) {
            // Check for rejected status
            if ($latestRequest) {
                switch ($latestRequest['status']) {
                    case 'pending_dispatch_assignment':
                        return [
                            'status' => 'Awaiting Dispatch',
                            'status_class' => 'status-returning'
                        ];
                    case 'rejected_new_request':
                        return [
                            'status' => 'Rejected (New Request Needed)',
                            'status_class' => 'status-assigned'
                        ];
                    case 'rejected_reassign_dispatch':
                        return [
                            'status' => 'Rejected (Reassign Dispatch)',
                            'status_class' => 'status-returning'
                        ];
                }
            }

            return [
                'status' => 'No Scheduled Trips',
                'status_class' => 'status-available'
            ];
        }

        // Sort trips by date
        usort($allTrips, function ($a, $b) {
            return strcmp($a['departure_date'] ?? '', $b['departure_date'] ?? '');
        });

        // Check if currently on a trip
        foreach ($allTrips as $trip) {
            $departure = $trip['departure_date'] ?? $today;
            $return = $trip['return_date'] ?? $departure;

            if ($departure <= $today && $today <= $return) {
                $vehicle = $trip['plate_number'] ?? 'TBD';
                if (isset($trip['requestor_name']) && $trip['requestor_name'] !== $employeeName) {
                    return [
                        'status' => 'On Trip as Passenger: ' . $vehicle,
                        'status_class' => 'status-assigned',
                        'is_active' => true
                    ];
                } else {
                    return [
                        'status' => 'Currently On Trip: ' . $vehicle,
                        'status_class' => 'status-assigned',
                        'is_active' => true
                    ];
                }
            }
        }

        // Show upcoming trips
        $tripCount = count($allTrips);
        $nextTrip = $allTrips[0];
        $nextDeparture = date('M j', strtotime($nextTrip['departure_date'] ?? $today));
        $vehicle = $nextTrip['plate_number'] ?? 'TBD';

        if ($tripCount == 1) {
            if (isset($nextTrip['requestor_name']) && $nextTrip['requestor_name'] !== $employeeName) {
                return [
                    'status' => '1 Trip as Passenger: ' . $nextDeparture,
                    'status_class' => 'status-returning'
                ];
            } else {
                return [
                    'status' => '1 Trip: ' . $nextDeparture . ' (' . $vehicle . ')',
                    'status_class' => 'status-returning'
                ];
            }
        } else {
            return [
                'status' => $tripCount . ' Trips: Next on ' . $nextDeparture,
                'status_class' => 'status-returning'
            ];
        }
    }
}