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

function has_vehicle_conflict(PDO $pdo, int $vehicleId, string $startDate, string $endDate, ?int $excludeRequestId = null): bool
{
    $query = "
        SELECT COUNT(*) FROM requests
        WHERE assigned_vehicle_id = :vehicle_id
          AND status IN ('pending_admin_approval', 'approved')
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

function has_driver_conflict(PDO $pdo, int $driverId, string $startDate, string $endDate, ?int $excludeRequestId = null): bool
{
    $query = "
        SELECT COUNT(*) FROM requests
        WHERE assigned_driver_id = :driver_id
          AND status IN ('pending_admin_approval', 'approved')
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

function sync_active_assignments(PDO $pdo): void
{
    $today = date('Y-m-d');

    $activeStmt = $pdo->prepare("
        SELECT 
            r.id,
            r.requestor_name,
            r.assigned_vehicle_id,
            r.assigned_driver_id,
            dr.name AS driver_name
        FROM requests r
        LEFT JOIN drivers dr ON dr.id = r.assigned_driver_id
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
    $activeDriverIds = [];

    while ($row = $activeStmt->fetch(PDO::FETCH_ASSOC)) {
        $vehicleId = $row['assigned_vehicle_id'];
        $driverId = $row['assigned_driver_id'];

        if ($vehicleId) {
            $activeVehicleIds[] = $vehicleId;
            $vehicleUpdate = $pdo->prepare("
                UPDATE vehicles
                SET status = 'assigned',
                    assigned_to = :assigned_to,
                    driver_name = :driver_name
                WHERE id = :id
            ");
            $vehicleUpdate->execute([
                ':assigned_to' => $row['requestor_name'],
                ':driver_name' => $row['driver_name'] ?? null,
                ':id' => $vehicleId,
            ]);
        }

        if ($driverId) {
            $activeDriverIds[] = $driverId;
            $driverUpdate = $pdo->prepare("UPDATE drivers SET status = 'assigned' WHERE id = :id");
            $driverUpdate->execute([':id' => $driverId]);
        }
    }

    // Release vehicles that are currently marked assigned but have no active trips today
    if (!empty($activeVehicleIds)) {
        $placeholders = implode(',', array_fill(0, count($activeVehicleIds), '?'));
        $releaseStmt = $pdo->prepare("
            UPDATE vehicles
            SET status = 'available',
                assigned_to = NULL,
                driver_name = NULL
            WHERE status = 'assigned'
              AND id NOT IN ($placeholders)
        ");
        $releaseStmt->execute($activeVehicleIds);
    } else {
        $pdo->exec("
            UPDATE vehicles
            SET status = 'available',
                assigned_to = NULL,
                driver_name = NULL
            WHERE status = 'assigned'
        ");
    }

    // Release drivers that are marked assigned but not driving today
    if (!empty($activeDriverIds)) {
        $placeholders = implode(',', array_fill(0, count($activeDriverIds), '?'));
        $releaseDrivers = $pdo->prepare("
            UPDATE drivers
            SET status = 'available'
            WHERE status = 'assigned'
              AND id NOT IN ($placeholders)
        ");
        $releaseDrivers->execute($activeDriverIds);
    } else {
        $pdo->exec("UPDATE drivers SET status = 'available' WHERE status = 'assigned'");
    }
}

