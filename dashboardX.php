<?php
// session_name("site3_session"); // Handled in session.php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/schedule_utils.php';
require_once __DIR__ . '/includes/lookup_utils.php';
include 'error.php';
require 'db.php';

sync_active_assignments($pdo);


// Helper Functions
function canEmployeeRequest($employeeRequestStatus, $isAssignedVehicle)
{
    //$hasPendingRequest = in_array($employeeRequestStatus, [
    //'pending_dispatch_assignment', 
    //'pending_admin_approval', 
    //'rejected_reassign_dispatch'
    //]);

    //$hasApprovedWithVehicle = ($employeeRequestStatus === 'approved' && $isAssignedVehicle);

    //return !$hasPendingRequest && !$hasApprovedWithVehicle;

    // Allow employees to make requests at any time
    return true;
}


function sortVehiclesByStatus($vehicles, $priorities)
{
    $groups = [];

    // Group by status
    foreach ($vehicles as $vehicle) {
        $status = $vehicle['status'];
        if (!isset($groups[$status])) {
            $groups[$status] = [];
        }
        $groups[$status][] = $vehicle;
    }

    // Sort each group alphabetically
    foreach ($groups as &$group) {
        usort($group, function ($a, $b) {
            return strcasecmp($a['plate_number'], $b['plate_number']);
        });
    }

    // Merge in priority order
    $result = [];
    foreach ($priorities as $status) {
        if (isset($groups[$status])) {
            $result = array_merge($result, $groups[$status]);
        }
    }

    return $result;
}

function getBookedTripsStatus($employeeOrDriver, $isDriver = false)
{
    global $pdo;

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
            'driver_id' => $employeeOrDriver['id'],
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
        $employeeName = $employeeOrDriver['name'];
        $employeeId = $employeeOrDriver['id'];

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



function getStatusBadgeClass($status)
{
    $statusMap = [
        'pending_dispatch_assignment' => 'status-returning',
        'pending_admin_approval' => 'status-returning',
        'approved' => 'status-available',
        'concluded' => 'status-available',
        'cancelled' => 'status-assigned',
        'rejected_new_request' => 'status-assigned',
        'rejected_reassign_dispatch' => 'status-returning',
        'rejected' => 'status-assigned'
    ];

    return $statusMap[$status] ?? '';
}

function getStatusText($status)
{
    $statusMap = [
        'pending_dispatch_assignment' => 'Awaiting Dispatch',
        'pending_admin_approval' => 'Awaiting Admin Approval',
        'approved' => 'Approved',
        'concluded' => 'Concluded',
        'cancelled' => 'Cancelled',
        'rejected_new_request' => 'Rejected (New Request Needed)',
        'rejected_reassign_dispatch' => 'Rejected (Reassign Dispatch)',
        'rejected' => 'Rejected'
    ];

    return $statusMap[$status] ?? ucfirst($status);
}

// User Authentication and Role Check
$isLoggedIn = isset($_SESSION['user']);
$isAdmin = $isLoggedIn && $_SESSION['user']['role'] === 'admin';
$isEmployee = $isLoggedIn && $_SESSION['user']['role'] === 'employee';

$username = $isLoggedIn ? $_SESSION['user']['name'] : null;
$user_role = $isLoggedIn ? $_SESSION['user']['role'] : 'guest';
$user_id = $isLoggedIn ? $_SESSION['user']['id'] : null;

// Fetch vehicles - always available for viewing
$stmt = $pdo->query("SELECT * FROM vehicles ORDER BY 
    CASE 
        WHEN status = 'assigned' THEN 0 
        WHEN status = 'returning' THEN 1 
        WHEN status = 'available' THEN 2
        ELSE 3 
    END, 
    plate_number ASC");
$vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Initialize variables
$employees = [];
$drivers = [];
$myRequests = [];
$employeeRequestStatus = null;
$isAssignedVehicle = false;
$assignedVehicle = null;
$pendingRequests = [];
$approvedPendingDispatchRequests = [];
$pendingAdminApprovalRequests = []; // New variable for admin pending approval

// Employee-specific data and vehicle status
if ($isEmployee) {
    // Check for assigned and returning vehicles in one block
    $stmt = $pdo->prepare("SELECT * FROM vehicles WHERE assigned_to = :username AND status = 'assigned' LIMIT 1");
    $stmt->execute(['username' => $username]);
    $assignedVehicle = $stmt->fetch(PDO::FETCH_ASSOC);
    $isAssignedVehicle = (bool)$assignedVehicle;

    // Fetch employee requests
    $stmt = $pdo->prepare("SELECT * FROM requests WHERE user_id = :user_id ORDER BY request_date DESC");
    $stmt->execute(['user_id' => $user_id]);
    $myRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($myRequests)) {
        $employeeRequestStatus = $myRequests[0]['status'];
    }

    // Check if employee is passenger
    $stmt = $pdo->prepare("
        SELECT r.id, r.requestor_name, r.status, r.passenger_names, r.departure_date
        FROM requests r 
        WHERE r.status IN ('pending_dispatch_assignment', 'pending_admin_approval', 'approved')
        AND r.departure_date >= CURDATE()  -- ADDED: Only future/current trips
        AND JSON_SEARCH(r.passenger_names, 'one', :username) IS NOT NULL
    ");
    $stmt->execute(['username' => $username]);
    $passengerInRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $isPassengerInActiveRequest = !empty($passengerInRequests);
    $passengerRequestDetails = null;
    // Only set details if user is passenger but NOT the requestor
    if ($isPassengerInActiveRequest) {
        foreach ($passengerInRequests as $request) {
            if ($request['requestor_name'] !== $username) {
                $passengerRequestDetails = $request;
                break;
            }
        }
    }
}

// Admin-specific data
if ($isAdmin) {
    // Fetch employees
    $stmt = $pdo->query("SELECT * FROM users WHERE role != 'admin'");
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch drivers with assignment status - optimized approach
    $stmt = $pdo->query("SELECT id, name, email, phone, position FROM users WHERE role = 'driver' ORDER BY name ASC");
    $driversRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all driver assignments in one query
    $driverAssignments = [];
    $stmt = $pdo->query("SELECT driver_id, plate_number FROM vehicles WHERE status = 'assigned' AND driver_id IS NOT NULL");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $driverAssignments[$row['driver_id']] = $row['plate_number'];
    }

    // Add assignment status to drivers and sort
    // Add assignment status and trip status to drivers
    $drivers = [];
    $assignedDrivers = [];
    $availableDrivers = [];

    foreach ($driversRaw as $driver) {
        $driver['assigned_vehicle'] = $driverAssignments[$driver['id']] ?? null;
        $driver['is_assigned'] = isset($driverAssignments[$driver['id']]);

        // Add booked trips status for each driver
        $driver['trip_status'] = getBookedTripsStatus($driver, true);

        if ($driver['is_assigned']) {
            $assignedDrivers[] = $driver;
        } else {
            $availableDrivers[] = $driver;
        }
    }

    $drivers = array_merge($assignedDrivers, $availableDrivers);

    // Fetch requests pending admin approval
    $stmt = $pdo->query("SELECT * FROM requests WHERE status = 'pending_admin_approval' ORDER BY request_date ASC");
    $pendingAdminApprovalRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch requests forwarded to dispatch (pending dispatch assignment and rejected for reassignment)
    $stmt = $pdo->query("SELECT * FROM requests WHERE status IN ('pending_dispatch_assignment', 'rejected_reassign_dispatch') ORDER BY request_date ASC");
    $dispatchForwardedRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get employee status information
    $employeeStatusData = [];

    foreach ($employees as $employee) {
        $employeeStatusData[$employee['id']] = getBookedTripsStatus($employee, false);
    }
}


// Pre-fetch lookup data for all requests (employee, admin, dispatch) to avoid queries in HTML
$vehicleLookup = [];
$driverLookup = [];

$allRequestVehicleIds = [];
$allRequestDriverIds = [];

if ($isEmployee) {
    $allRequestVehicleIds = array_merge($allRequestVehicleIds, array_column($myRequests, 'assigned_vehicle_id'));
    $allRequestDriverIds = array_merge($allRequestDriverIds, array_column($myRequests, 'assigned_driver_id'));

    // Categorize requests for display in alerts
    $pendingRequests = [];
    $approvedRequests = [];
    $rejectedRequests = [];

    foreach ($myRequests as $req) {
        if (in_array($req['status'], ['pending_dispatch_assignment', 'pending_admin_approval', 'rejected_reassign_dispatch'])) {
            $pendingRequests[] = $req;
        } elseif ($req['status'] === 'approved') {
            $today = date('Y-m-d');
            $returnDate = $req['return_date'] ?? $req['departure_date'] ?? null;
            if ($returnDate && $returnDate >= $today) {
                $approvedRequests[] = $req;
            }
        } elseif ($req['status'] === 'rejected_new_request') {
            $rejectedRequests[] = $req;
        }
    }
}
if ($isAdmin) {
    $allRequestVehicleIds = array_merge($allRequestVehicleIds, array_column($pendingAdminApprovalRequests, 'assigned_vehicle_id'));
    $allRequestDriverIds = array_merge($allRequestDriverIds, array_column($pendingAdminApprovalRequests, 'assigned_driver_id'));
    $allRequestVehicleIds = array_merge($allRequestVehicleIds, array_column($dispatchForwardedRequests, 'assigned_vehicle_id'));
    $allRequestDriverIds = array_merge($allRequestDriverIds, array_column($dispatchForwardedRequests, 'assigned_driver_id'));
}

$vehicleIds = array_filter(array_unique($allRequestVehicleIds));
$driverIds = array_filter(array_unique($allRequestDriverIds));

if (!empty($vehicleIds)) {
    $placeholders = str_repeat('?,', count($vehicleIds) - 1) . '?';
    $stmt = $pdo->prepare("SELECT id, plate_number FROM vehicles WHERE id IN ($placeholders)");
    $stmt->execute(array_values($vehicleIds));
    while ($row = $stmt->fetch()) {
        $vehicleLookup[$row['id']] = $row['plate_number'];
    }
}

if (!empty($driverIds)) {
    $placeholders = str_repeat('?,', count($driverIds) - 1) . '?';
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE id IN ($placeholders) AND role = 'driver'");
    $stmt->execute(array_values($driverIds));
    while ($row = $stmt->fetch()) {
        $driverLookup[$row['id']] = $row['name'];
    }
}

// Sort vehicles based on user role
if ($isAdmin) {
    $vehicles = sortVehiclesByStatus($vehicles, ['assigned', 'returning', 'maintenance', 'available']);
} elseif ($isEmployee) {
    $myVehicles = [];
    $otherVehicles = [];

    foreach ($vehicles as $vehicle) {
        if (($vehicle['assigned_to'] === $username) ||
            ($vehicle['returned_by'] === $username && $vehicle['status'] === 'returning')
        ) {
            $myVehicles[] = $vehicle;
        } else {
            $otherVehicles[] = $vehicle;
        }
    }

    // Sort my vehicles: returning first, then assigned
    usort($myVehicles, function ($a, $b) {
        if ($a['status'] === 'returning' && $b['status'] !== 'returning') {
            return -1;
        }
        if ($b['status'] === 'returning' && $a['status'] !== 'returning') {
            return 1;
        }
        return strcasecmp($a['plate_number'], $b['plate_number']);
    });

    // Sort other vehicles by status priority
    $otherVehicles = sortVehiclesByStatus($otherVehicles, ['available', 'returning', 'assigned', 'maintenance']);

    $vehicles = array_merge($myVehicles, $otherVehicles);
}

// Create a lookup for vehicles that are assigned but awaiting admin approval
$assignedVehicleRequestStatusLookup = [];
$stmt = $pdo->query("SELECT assigned_vehicle_id, status FROM requests WHERE assigned_vehicle_id IS NOT NULL AND (status = 'pending_admin_approval' OR status = 'approved')");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $assignedVehicleRequestStatusLookup[$row['assigned_vehicle_id']] = $row['status'];
}

// Calculate stats
$availableVehiclesCount = 0;
$maintenanceVehiclesCount = 0;

foreach ($vehicles as $vehicle) {
    if ($vehicle['status'] === 'available') {
        $availableVehiclesCount++;
    } elseif ($vehicle['status'] === 'maintenance') {
        $maintenanceVehiclesCount++;
    }
}


// Calculate pending counts based on user role
$pendingRequestsCount = 0;
if ($isAdmin) {
    $pendingRequestsCount = count($pendingAdminApprovalRequests);
} elseif ($isEmployee) {
    $pendingRequestsCount = count(array_filter($myRequests, function ($req) {
        return in_array($req['status'], ['pending_dispatch_assignment', 'pending_admin_approval', 'rejected_reassign_dispatch']);
    }));
}

// Calculate request restrictions for employees
$cannotRequest = false;
if ($isEmployee) {
    $cannotRequest = !canEmployeeRequest($employeeRequestStatus, $isAssignedVehicle);
}

// Calendar + Upcoming reservations data
$calendarRequests = [];
$calendarEvents = [];
$calendarRequestDetails = [];
$today = date('Y-m-d');
$upcomingReservations = [];
$auditLogsByRequest = [];

$calendarStmt = $pdo->prepare("
    SELECT 
        r.*,
        v.plate_number,
        v.make,
        v.model,
        d.name AS driver_name
    FROM requests r
    LEFT JOIN vehicles v ON v.id = r.assigned_vehicle_id
    LEFT JOIN users d ON d.id = r.assigned_driver_id AND d.role = 'driver'
    WHERE r.status = 'approved'
      AND r.assigned_vehicle_id IS NOT NULL
      AND COALESCE(r.departure_date, DATE(r.request_date)) IS NOT NULL
");
$calendarStmt->execute();
$calendarRequests = $calendarStmt->fetchAll(PDO::FETCH_ASSOC);

$calendarRequestIds = array_column($calendarRequests, 'id');
$pendingAdminIds = array_column($pendingAdminApprovalRequests, 'id');
$auditRequestIds = array_values(array_unique(array_filter(array_merge($calendarRequestIds, $pendingAdminIds))));

if (!empty($auditRequestIds)) {
    $placeholders = implode(',', array_fill(0, count($auditRequestIds), '?'));
    $auditStmt = $pdo->prepare("
        SELECT request_id, action, actor_name, actor_role, notes, created_at
        FROM request_audit_logs
        WHERE request_id IN ($placeholders)
        ORDER BY created_at DESC
    ");
    $auditStmt->execute($auditRequestIds);
    while ($log = $auditStmt->fetch(PDO::FETCH_ASSOC)) {
        if (!isset($auditLogsByRequest[$log['request_id']])) {
            $auditLogsByRequest[$log['request_id']] = [];
        }
        if (count($auditLogsByRequest[$log['request_id']]) < 5) {
            $auditLogsByRequest[$log['request_id']][] = $log;
        }
    }
}

foreach ($calendarRequests as $calendarRequest) {
    [$eventStart, $eventEnd] = get_request_date_range($calendarRequest);
    if (!$eventStart) {
        continue;
    }

    $eventEnd = $eventEnd ?? $eventStart;
    if ($eventEnd < $eventStart) {
        $eventEnd = $eventStart;
    }

    $eventState = 'upcoming';
    if ($eventEnd < $today) {
        $eventState = 'past';
    } elseif ($eventStart <= $today && $eventEnd >= $today) {
        $eventState = 'active';
    }

    $passengerDisplay = '----';
    if (!empty($calendarRequest['passenger_names'])) {
        $decodedPassengers = json_decode($calendarRequest['passenger_names'], true);
        if (is_array($decodedPassengers)) {
            $passengerDisplay = implode(', ', $decodedPassengers);
        } else {
            $passengerDisplay = $calendarRequest['passenger_names'];
        }
    }

    $calendarEvents[] = [
        'id' => $calendarRequest['id'],
        'title' => $calendarRequest['plate_number'] ?? 'Vehicle',
        'start' => $eventStart,
        'end' => date('Y-m-d', strtotime($eventEnd . ' +1 day')),
        'allDay' => true,
        'className' => ['calendar-event', 'calendar-event--' . $eventState],
        'state' => $eventState,
    ];

    $calendarRequestDetails[$calendarRequest['id']] = [
        'plate' => $calendarRequest['plate_number'] ?? 'TBD',
        'vehicle' => trim(($calendarRequest['make'] ?? '') . ' ' . ($calendarRequest['model'] ?? '')),
        'requestor' => $calendarRequest['requestor_name'],
        'email' => $calendarRequest['requestor_email'],
        'destination' => $calendarRequest['destination'],
        'purpose' => $calendarRequest['purpose'],
        'start' => $eventStart,
        'end' => $eventEnd,
        'status' => getStatusText($calendarRequest['status']),
        'driver' => $calendarRequest['driver_name'] ?? 'TBD',
        'passengers' => $passengerDisplay,
        'audit' => $auditLogsByRequest[$calendarRequest['id']] ?? []
    ];
}

$upcomingStmt = $pdo->prepare("
    SELECT 
        r.id,
        r.requestor_name,
        r.destination,
        r.departure_date,
        r.return_date,
        v.plate_number
    FROM requests r
    LEFT JOIN vehicles v ON v.id = r.assigned_vehicle_id
    WHERE r.status = 'approved'
      AND r.assigned_vehicle_id IS NOT NULL
      AND COALESCE(r.return_date, r.departure_date) >= CURDATE()
    ORDER BY COALESCE(r.departure_date, DATE(r.request_date)) ASC
    LIMIT 7
");
$upcomingStmt->execute();
$upcomingReservations = $upcomingStmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Vehicle Request Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/main.min.css">
    <link rel="stylesheet" href="styles.css">
</head>

<body>
    <div class="container-fluid p-0">
        <!-- Modern Header -->
        <header class="modern-header">
            <div class="container-fluid">
                <h1>
                    <span class="header-icon"><i class="fas fa-car"></i></span>
                    Vehicle Request System
                </h1>
                <div class="user-section">
                    <div class="welcome-text">
                        <?php if ($isLoggedIn): ?>
                            <i class="fas fa-user-circle me-2"></i>
                            Welcome back, <strong><?= htmlspecialchars($username) ?></strong> (<?= ucfirst($user_role) ?>)
                        <?php else: ?>
                            <i class="fas fa-info-circle me-2"></i>
                            Welcome to the Vehicle Request System
                        <?php endif; ?>
                    </div>
                    <div class="user-actions">
                        <?php if ($isLoggedIn): ?>
                            <a href="logout.php" class="btn btn-light btn-sm">
                                <i class="fas fa-sign-out-alt me-1"></i> Logout
                            </a>
                        <?php else: ?>
                            <a href="login.php" class="btn btn-primary btn-sm me-2">
                                <i class="fas fa-sign-in-alt me-1"></i> Login
                            </a>
                            <button class="btn btn-outline-light btn-sm" onclick="showSignupInfo()">
                                <i class="fas fa-user-plus me-1"></i> Sign Up
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </header>

        <div class="container-fluid px-4">
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success alert-dismissible fade show text-success" role="alert">
                    <i class="fas fa-check-circle alert-icon"></i>
                    <?= htmlspecialchars($_SESSION['success_message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="modern-alert alert-danger alert-dismissible fade show text-danger" role="alert">
                    <i class="fas fa-exclamation-triangle alert-icon"></i>
                    <div class="alert-content">
                        <strong>Error:</strong> <?= htmlspecialchars($_SESSION['error_message']); ?>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>


            <!-- Quick Stats Dashboard - Show for logged in admin, or basic stats for guests -->
            <?php if ($isAdmin || !$isLoggedIn): ?>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon text-success">
                                <i class="fas fa-check-circle"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?= $availableVehiclesCount ?></div>
                        <div class="stat-label">Available Vehicles</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon text-danger">
                                <i class="fas fa-tools"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?= $maintenanceVehiclesCount ?></div>
                        <div class="stat-label">Vehicles in Maintenance</div>
                    </div>

                    <?php if ($isAdmin): ?>
                        <div class="stat-card">
                            <div class="stat-header">
                                <div class="stat-icon text-warning">
                                    <i class="fas fa-clock"></i>
                                </div>
                            </div>
                            <div class="stat-number"><?= $pendingRequestsCount ?></div>
                            <div class="stat-label">Pending Requests</div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-header">
                                <div class="stat-icon text-info">
                                    <i class="fas fa-calendar-check"></i>
                                </div>
                            </div>
                            <div class="stat-number"><?= count($upcomingReservations) ?></div>
                            <div class="stat-label">Upcoming Reservations</div>
                        </div>
                    <?php else: ?>
                        <div class="stat-card">
                            <div class="stat-header">
                                <div class="stat-icon text-info">
                                    <i class="fas fa-users"></i>
                                </div>
                            </div>
                            <div class="stat-number"><?= count($vehicles) ?></div>
                            <div class="stat-label">Total Drivers</div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-header">
                                <div class="stat-icon text-primary">
                                    <i class="fas fa-percentage"></i>
                                </div>
                            </div>
                            <div class="stat-number"><?= count($vehicles) > 0 ? round(($availableVehiclesCount / count($vehicles)) * 100) : 0 ?>%</div>
                            <div class="stat-label">Availability Rate</div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!--Alert Section-->
            <?php if ($isEmployee && $passengerRequestDetails !== null): ?>
                <div class="modern-alert">
                    <div class="alert alert-permanent alert-info">
                        <strong>Passenger Status:</strong> You are currently listed as a passenger in
                        <strong><?= htmlspecialchars($passengerRequestDetails['requestor_name']) ?></strong>'s vehicle request.
                        If you want to submit your own vehicle request, please coordinate your travel plans.
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($isEmployee): ?>
                <?php if ($employeeRequestStatus === 'rejected_reassign_dispatch'): ?>
                    <div class="modern-alert">
                        <div class="alert alert-permanent alert-warning">
                            <strong>Request Under Reassignment:</strong> Your vehicle request was sent back to dispatch for reassignment.
                        </div>
                    </div>
                <?php elseif ($employeeRequestStatus === 'rejected_new_request'): ?>
                    <div class="modern-alert">
                        <i class="fas fa-times-circle alert-icon"></i>
                        <div class="alert alert-permanent alert-danger">
                            <strong>Request Rejected:</strong> Your vehicle request has been rejected. Please resubmit a new request.
                        </div>
                    </div>

                <?php elseif ($employeeRequestStatus === 'pending_admin_approval'): ?>
                    <div class="modern-alert">
                        <i class="fas fa-info-circle alert-icon"></i>
                        <div class="alert alert-permanent alert-info">
                            <strong>Request Status:</strong> Your vehicle request has been assigned a vehicle and driver, and is now pending admin approval.
                        </div>
                    </div>
                <?php elseif ($employeeRequestStatus === 'pending_dispatch_assignment'): ?>
                    <div class="modern-alert">
                        <i class="fas fa-info-circle alert-icon"></i>
                        <div class="alert alert-permanent alert-warning">
                            <strong>Request Status:</strong> You have a request awaiting dispatch assignment.
                        </div>
                    </div>
                <?php elseif ($employeeRequestStatus === 'rejected'): ?>
                    <div class="modern-alert">
                        <i class="fas fa-times-circle alert-icon"></i>
                        <div class="alert alert-permanent alert-danger">
                            <strong>Request Status:</strong> Your last vehicle request was denied. Please submit a new
                            request.
                        </div>
                    </div>
                <?php elseif ($employeeRequestStatus === 'approved' && $isAssignedVehicle): ?>
                    <div class="modern-alert">
                        <i class="fas fa-check-circle alert-icon"></i>
                        <div class="alert alert-permanent alert-success">
                            <strong>Request Status:</strong> You have approved vehicle requests.
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Show Pending Requests Alert -->
                <?php if (!empty($pendingRequests)): ?>
                    <div class="modern-alert">
                        <div class="alert alert-warning">
                            <div class="d-flex align-items-start">
                                <i class="fas fa-clock alert-icon me-3"></i>
                                <div class="flex-grow-1">
                                    <strong>Pending Requests (<?= count($pendingRequests) ?>):</strong>
                                    <p class="mb-2">You have <?= count($pendingRequests) ?> request<?= count($pendingRequests) > 1 ? 's' : '' ?> being processed. You can still submit additional requests.</p>
                                    <div class="list-group list-group-flush">
                                        <?php foreach ($pendingRequests as $req): ?>
                                            <div class="list-group-item bg-transparent border-start-0 border-end-0 px-0 py-2">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                        <div class="fw-bold">
                                                            <i class="fas fa-location-dot me-1"></i><?= htmlspecialchars($req['destination']) ?>
                                                        </div>
                                                        <div class="small text-muted">
                                                            <i class="fas fa-calendar me-1"></i>
                                                            <?php if (!empty($req['departure_date'])): ?>
                                                                <?= date('M j', strtotime($req['departure_date'])) ?>
                                                                <?php if (!empty($req['return_date']) && $req['return_date'] != $req['departure_date']): ?>
                                                                    - <?= date('M j', strtotime($req['return_date'])) ?>
                                                                <?php endif; ?>
                                                            <?php else: ?>
                                                                Date TBD
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <span class="badge bg-warning text-dark">
                                                        <?php
                                                        if ($req['status'] === 'pending_dispatch_assignment') {
                                                            echo 'Awaiting Dispatch';
                                                        } elseif ($req['status'] === 'pending_admin_approval') {
                                                            echo 'Awaiting Admin';
                                                        } elseif ($req['status'] === 'rejected_reassign_dispatch') {
                                                            echo 'Reassigning';
                                                        }
                                                        ?>
                                                    </span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Show Approved/Active Trips Alert -->
                <?php if (!empty($approvedRequests)): ?>
                    <div class="modern-alert">
                        <div class="alert alert-success">
                            <div class="d-flex align-items-start">
                                <i class="fas fa-check-circle alert-icon me-3"></i>
                                <div class="flex-grow-1">
                                    <strong>Approved Trips (<?= count($approvedRequests) ?>):</strong>
                                    <p class="mb-2">You have <?= count($approvedRequests) ?> approved trip<?= count($approvedRequests) > 1 ? 's' : '' ?>. You can book additional vehicles for different dates.</p>
                                    <div class="list-group list-group-flush">
                                        <?php foreach ($approvedRequests as $req): ?>
                                            <?php
                                            $vehiclePlate = '----';
                                            if ($req['assigned_vehicle_id'] && isset($vehicleLookup[$req['assigned_vehicle_id']])) {
                                                $vehiclePlate = $vehicleLookup[$req['assigned_vehicle_id']];
                                            }
                                            $driverName = '----';
                                            if ($req['assigned_driver_id'] && isset($driverLookup[$req['assigned_driver_id']])) {
                                                $driverName = $driverLookup[$req['assigned_driver_id']];
                                            }
                                            ?>
                                            <div class="list-group-item bg-transparent border-start-0 border-end-0 px-0 py-2">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                        <div class="fw-bold">
                                                            <i class="fas fa-location-dot me-1"></i><?= htmlspecialchars($req['destination']) ?>
                                                        </div>
                                                        <div class="small text-muted">
                                                            <i class="fas fa-calendar me-1"></i>
                                                            <?php if (!empty($req['departure_date'])): ?>
                                                                <?= date('M j', strtotime($req['departure_date'])) ?>
                                                                <?php if (!empty($req['return_date']) && $req['return_date'] != $req['departure_date']): ?>
                                                                    - <?= date('M j', strtotime($req['return_date'])) ?>
                                                                <?php endif; ?>
                                                            <?php else: ?>
                                                                Date TBD
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="small">
                                                            <i class="fas fa-car me-1 text-primary"></i><?= htmlspecialchars($vehiclePlate) ?>
                                                            <i class="fas fa-user ms-2 me-1 text-info"></i><?= htmlspecialchars($driverName) ?>
                                                        </div>
                                                    </div>
                                                    <span class="badge bg-success">
                                                        Approved
                                                    </span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Show Rejected Requests Alert -->
                <?php if (!empty($rejectedRequests)): ?>
                    <div class="modern-alert">
                        <div class="alert alert-danger">
                            <div class="d-flex align-items-start">
                                <i class="fas fa-times-circle alert-icon me-3"></i>
                                <div class="flex-grow-1">
                                    <strong>Rejected Requests (<?= count($rejectedRequests) ?>):</strong>
                                    <p class="mb-2">You have <?= count($rejectedRequests) ?> rejected request<?= count($rejectedRequests) > 1 ? 's' : '' ?>. You can submit new requests anytime.</p>
                                    <div class="list-group list-group-flush">
                                        <?php foreach ($rejectedRequests as $req): ?>
                                            <div class="list-group-item bg-transparent border-start-0 border-end-0 px-0 py-2">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                        <div class="fw-bold">
                                                            <i class="fas fa-location-dot me-1"></i><?= htmlspecialchars($req['destination']) ?>
                                                        </div>
                                                        <div class="small text-muted">
                                                            <i class="fas fa-calendar me-1"></i>
                                                            <?php if (!empty($req['departure_date'])): ?>
                                                                <?= date('M j', strtotime($req['departure_date'])) ?>
                                                                <?php if (!empty($req['return_date']) && $req['return_date'] != $req['departure_date']): ?>
                                                                    - <?= date('M j', strtotime($req['return_date'])) ?>
                                                                <?php endif; ?>
                                                            <?php else: ?>
                                                                Date TBD
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <span class="badge bg-danger">
                                                        Rejected
                                                    </span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Passenger Status Alert (keep as is) -->
                <?php if ($passengerRequestDetails !== null): ?>
                    <div class="modern-alert">
                        <div class="alert alert-permanent alert-info">
                            <strong>Passenger Status:</strong> You are currently listed as a passenger in
                            <strong><?= htmlspecialchars($passengerRequestDetails['requestor_name']) ?></strong>'s vehicle request.
                            You can still submit your own vehicle requests.
                        </div>
                    </div>
                <?php endif; ?>

            <?php endif; ?>

            <div class="row dashboard-grid align-items-start">
                <div class="col-lg-3">
                    <?php $upcomingCount = count($upcomingReservations); ?>
                    <aside class="upcoming-sidebar">
                        <div class="card upcoming-card">
                            <div class="upcoming-header d-flex align-items-center justify-content-between">
                                <h3 class="h6 mb-0 text-uppercase">
                                    <i class="fas fa-calendar-day me-2"></i>Upcoming Reservations
                                </h3>
                                <span class="badge bg-light text-dark fw-semibold">
                                    <?= $upcomingCount ?>
                                </span>
                            </div>
                            <?php if (empty($upcomingReservations)): ?>
                                <p class=" text-secondary small pt-2 mb-0">No scheduled trips yet.</p>
                            <?php else: ?>
                                <div class="upcoming-list">
                                    <?php foreach ($upcomingReservations as $reservation): ?>
                                        <?php
                                        $startDateValue = $reservation['departure_date'] ?? null;
                                        $endDateValue = $reservation['return_date'] ?? $startDateValue;
                                        $startDateObj = $startDateValue ? new DateTime($startDateValue) : null;
                                        $endDateObj = ($endDateValue && $endDateValue !== $startDateValue) ? new DateTime($endDateValue) : null;
                                        $rangeLabel = $startDateObj ? $startDateObj->format('M j') : 'Date TBD';
                                        if ($startDateObj && $endDateObj) {
                                            $sameMonth = $startDateObj->format('M') === $endDateObj->format('M');
                                            $rangeLabel .= ' - ' . ($sameMonth ? $endDateObj->format('j') : $endDateObj->format('M j'));
                                        }
                                        $plateLabel = $reservation['plate_number'] ?? 'Vehicle TBD';
                                        ?>
                                        <div class="upcoming-item">
                                            <div class="upcoming-date text-center">
                                                <?php if ($startDateObj): ?>
                                                    <span class="month"><?= strtoupper($startDateObj->format('M')) ?></span>
                                                    <span class="day"><?= $startDateObj->format('d') ?></span>
                                                <?php else: ?>
                                                    <span class="month">TBD</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="upcoming-details">
                                                <div class="vehicle-label"><?= htmlspecialchars($plateLabel) ?></div>
                                                <div class="upcoming-range text-muted small"><?= htmlspecialchars($rangeLabel) ?></div>
                                                <div class="upcoming-meta">
                                                    <i class="fas fa-user me-1"></i><?= htmlspecialchars($reservation['requestor_name']) ?>
                                                </div>
                                                <div class="upcoming-meta">
                                                    <i class="fas fa-location-dot me-1"></i><?= htmlspecialchars($reservation['destination']) ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </aside>
                </div>

                <div class="col-lg-9">
                    <!-- Navigation Tabs -->
                    <div class="nav-container">
                        <div class="d-flex justify-content-between align-items-center">
                            <ul class="nav nav-tabs" id="dashboardTabs">
                                <li class="nav-item">
                                    <a class="nav-link active" data-bs-toggle="tab" href="#calendar">
                                        <i class="fas fa-calendar-week me-2"></i>Calendar
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" data-bs-toggle="tab" href="#vehicles">
                                        <i class="fas fa-car me-2"></i>Vehicles
                                    </a>
                                </li>
                                <?php if ($isEmployee): ?>
                                    <li class="nav-item">
                                        <a class="nav-link" data-bs-toggle="tab" href="#myRequests">
                                            <i class="fas fa-file-alt me-2"></i>My Requests
                                        </a>
                                    </li>
                                <?php endif; ?>
                                <?php if ($isAdmin): ?>
                                    <li class="nav-item">
                                        <a class="nav-link" data-bs-toggle="tab" href="#employeeManagement">
                                            <i class="fas fa-users me-2"></i>Employees
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" data-bs-toggle="tab" href="#driverManagement">
                                            <i class="fas fa-id-card me-2"></i>Drivers
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" data-bs-toggle="tab" href="#adminRequests">
                                            <i class="fas fa-clipboard-list me-2"></i>Requests
                                            <?php if ($pendingRequestsCount > 0): ?>
                                                <span class="badge bg-danger ms-1"><?= $pendingRequestsCount ?></span>
                                            <?php endif; ?>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                            <!-- REQUEST VEHICLE BUTTON - NEW LOCATION -->
                            <div class="ms-3">
                                <?php if ($isEmployee): ?>
                                    <?php
                                    // Check if user has any active or upcoming trips (approved trips only)
                                    $hasActiveTrips = false;
                                    if (!empty($myRequests)) {
                                        foreach ($myRequests as $req) {
                                            if ($req['status'] === 'approved') {
                                                $departureDate = $req['departure_date'] ?? null;
                                                $returnDate = $req['return_date'] ?? $departureDate;
                                                $today = date('Y-m-d');

                                                if ($departureDate && $returnDate >= $today) {
                                                    $hasActiveTrips = true;
                                                    break;
                                                }
                                            }
                                        }
                                    }

                                    // Check if user has any pending requests
                                    $hasPendingRequests = false;
                                    if (!empty($myRequests)) {
                                        foreach ($myRequests as $req) {
                                            if (in_array($req['status'], ['pending_dispatch_assignment', 'pending_admin_approval', 'rejected_reassign_dispatch'])) {
                                                $hasPendingRequests = true;
                                                break;
                                            }
                                        }
                                    }
                                    ?>

                                    <?php if ($hasActiveTrips || $hasPendingRequests): ?>
                                        <!-- User has existing trips/requests - show info modal -->
                                        <button type="button"
                                            class="btn btn-success-modern btn-modern"
                                            onclick="showMultipleRequestsModal()">
                                            <i class="fas fa-plus me-2"></i>Request Vehicle
                                        </button>
                                    <?php elseif ($isPassengerInActiveRequest): ?>
                                        <!-- User is passenger in someone else's request - show info modal -->
                                        <button type="button"
                                            class="btn btn-success-modern btn-modern"
                                            onclick="showPassengerWarningModal()">
                                            <i class="fas fa-plus me-2"></i>Request Vehicle
                                        </button>
                                    <?php else: ?>
                                        <!-- No existing requests - direct link -->
                                        <a href="create_request.php"
                                            class="btn btn-success-modern btn-modern">
                                            <i class="fas fa-plus me-2"></i>Request Vehicle
                                        </a>
                                    <?php endif; ?>
                                <?php elseif (!$isLoggedIn): ?>
                                    <button class="btn btn-success-modern btn-modern" onclick="requireLogin()">
                                        <i class="fas fa-plus me-2"></i>Request Vehicle
                                    </button>
                                <?php endif; ?>
                            </div>


                        </div>
                    </div>

                    <!-- Tab Content -->
                    <div class="tab-content">
                        <!-- Calendar Tab -->
                        <div class="tab-pane fade show active" id="calendar">
                            <div class="calendar-container card p-3 mb-4">
                                <div class="section-header mb-3">
                                    <h2 class="section-title mb-0">
                                        <i class="fas fa-calendar-alt me-2"></i>Vehicle Schedule
                                    </h2>
                                    <div class="legend-item"><span class="legend-dot legend-active"></span> In Use Today</div>
                                    <div class="legend-item"><span class="legend-dot legend-upcoming"></span> Scheduled (Future)</div>
                                    <div class="legend-item"><span class="legend-dot legend-complete"></span> Past</div>
                                </div>
                                <div id="fleetCalendar"></div>
                            </div>
                        </div>

                        <!-- Vehicles Tab -->
                        <div class="tab-pane fade" id="vehicles">


                            <!-- Action Bar -->
                            <div class="section-header">
                                <h2 class="section-title">
                                    <i class="fas fa-car"></i>
                                    Available Vehicles
                                </h2>
                                <div class="d-flex gap-2">
                                    <?php if ($isAdmin): ?>
                                        <a href="admin/add_vehicle.php" class="btn btn-primary-modern btn-modern">
                                            <i class="fas fa-plus me-2"></i>Add Vehicle
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Enhanced Vehicle Grid -->
                            <div class="vehicles-grid">
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <div class="vehicle-card status-<?= htmlspecialchars($vehicle['status']) ?>">
                                        <div class="vehicle-icon">🚗</div>
                                        <div class="vehicle-plate">
                                            <?= htmlspecialchars($vehicle['plate_number']) ?>
                                        </div>
                                        <div class="vehicle-details">
                                            <div class="detail-item">
                                                <span class="detail-label"> Current Status</span>
                                                <span class="status-badge status-<?= htmlspecialchars($vehicle['status']) ?>">
                                                    <?php if ($vehicle['status'] === 'available'): ?>
                                                        <i class="fas fa-check-circle"></i>Available
                                                    <?php elseif ($vehicle['status'] === 'assigned'): ?>
                                                        <?php if (isset($assignedVehicleRequestStatusLookup[$vehicle['id']]) && $assignedVehicleRequestStatusLookup[$vehicle['id']] === 'pending_admin_approval'): ?>
                                                            <i class="fas fa-clock"></i>Awaiting Admin Approval
                                                        <?php else: ?>
                                                            <i class="fas fa-user"></i>Assigned
                                                        <?php endif; ?>
                                                    <?php elseif ($vehicle['status'] === 'returning'): ?>
                                                        <i class="fas fa-clock"></i>Returning
                                                    <?php elseif ($vehicle['status'] === 'maintenance'): ?>
                                                        <i class="fas fa-screwdriver-wrench"></i>Maintenance
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                            <div class="detail-item">
                                                <span class="detail-label">Assigned to</span>
                                                <span class="detail-value">
                                                    <?php
                                                    $assignedStatus = $assignedVehicleRequestStatusLookup[$vehicle['id']] ?? null;
                                                    if ($vehicle['status'] === 'assigned' && $assignedStatus === 'pending_admin_approval') {
                                                        echo 'Pending Admin Approval';
                                                    } elseif (!$isLoggedIn && !empty($vehicle['assigned_to'])) {
                                                        echo 'Assigned';
                                                    } else {
                                                        echo !empty($vehicle['assigned_to']) ? htmlspecialchars($vehicle['assigned_to']) : '----';
                                                    }
                                                    ?>
                                                </span>
                                            </div>
                                            <div class="detail-item">
                                                <span class="detail-label">Driver</span>
                                                <span class="detail-value">
                                                    <?php
                                                    if ($vehicle['status'] === 'assigned' && $assignedStatus === 'pending_admin_approval') {
                                                        echo 'Pending Admin Approval';
                                                    } elseif (!$isLoggedIn && !empty($vehicle['driver_name'])) {
                                                        echo 'Assigned';
                                                    } else {
                                                        echo !empty($vehicle['driver_name']) ? htmlspecialchars($vehicle['driver_name']) : '----';
                                                    }
                                                    ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="action-group">
                                            <?php if ($isAdmin): ?>
                                                <a href="admin/edit_vehicle.php?id=<?= $vehicle['id'] ?>"
                                                    class="btn btn-primary-modern btn-modern">
                                                    <i class="fas fa-edit me-1"></i>Edit
                                                </a>
                                                <form action="admin/delete_vehicle.php" method="POST" style="display:inline;">
                                                    <input type="hidden" name="id" value="<?= $vehicle['id'] ?>">
                                                    <?= csrf_field() ?>
                                                    <button type="submit" class="btn btn-danger btn-modern"
                                                        onclick="return confirm('Are you sure you want to delete this vehicle?');">
                                                        <i class="fas fa-trash-alt me-1"></i>Delete
                                                    </button>
                                                </form>

                                            <?php elseif ($isEmployee): ?>
                                                <?php if ($vehicle['status'] === 'assigned' && $vehicle['assigned_to'] === $username): ?>
                                                    <?php
                                                    // Check if there's a pending (not yet approved) request for this vehicle
                                                    $hasPendingRequest = false;
                                                    $pendingRequestId = null;
                                                    foreach ($myRequests as $req) {
                                                        if (
                                                            in_array($req['status'], ['pending_dispatch_assignment', 'pending_admin_approval', 'rejected_reassign_dispatch'])
                                                            && $req['assigned_vehicle_id'] == $vehicle['id']
                                                        ) {
                                                            $hasPendingRequest = true;
                                                            $pendingRequestId = $req['id'];
                                                            break;
                                                        }
                                                    }
                                                    ?>

                                                    <?php if ($hasPendingRequest && $pendingRequestId): ?>
                                                        <!-- Show Cancel Request button if request is not yet approved -->
                                                        <button type="button" class="btn btn-danger btn-modern"
                                                            onclick="showCancelModal(<?= $pendingRequestId ?>)">
                                                            <i class="fas fa-times-circle me-1"></i>Cancel Request
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="text-success fw-semibold">
                                                            <i class="fas fa-calendar-check me-1"></i>Reserved for your trip
                                                        </span>
                                                    <?php endif; ?>

                                                <?php elseif ($vehicle['status'] === 'returning' && $vehicle['returned_by'] === $username): ?>
                                                    <span class="text-muted">
                                                        <i class="fas fa-clock me-1"></i>Pending Return
                                                    </span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <?php if ($isEmployee): ?>
                            <!-- My Requests Tab -->
                            <div class="tab-pane fade" id="myRequests">
                                <div class="table-container">
                                    <div class="section-header">
                                        <h2 class="section-title text-danger">
                                            <i class="fas fa-file-alt"></i>
                                            My Requests
                                        </h2>
                                        <a href="create_request.php"
                                            class="btn btn-primary-modern btn-modern">
                                            <i class="fas fa-plus me-2"></i>New Request
                                        </a>
                                    </div>

                                    <div class="table-responsive">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Departure</th>
                                                    <th>Return</th>
                                                    <th>Destination</th>
                                                    <th>Purpose</th>
                                                    <th>Passengers</th>
                                                    <th>Current Status</th>
                                                    <th>Assignment</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($myRequests)): ?>
                                                    <tr>
                                                        <td colspan="9" class="text-center">No vehicle requests found.</td>
                                                    </tr>
                                                <?php else: ?>
                                                    <?php foreach ($myRequests as $request):
                                                        // Determine if request can be cancelled
                                                        $canCancel = in_array($request['status'], [
                                                            'pending_dispatch_assignment',
                                                            'pending_admin_approval',
                                                            'rejected_reassign_dispatch'
                                                        ]);

                                                        // For approved requests, check if trip hasn't started yet
                                                        if ($request['status'] === 'approved') {
                                                            $departureDate = $request['departure_date'] ?? null;
                                                            $today = date('Y-m-d');
                                                            if ($departureDate && $today < $departureDate) {
                                                                $canCancel = true;
                                                            }
                                                        }
                                                        // Prepare vehicle and driver display
                                                        $vehicleDisplay = '----';
                                                        $driverDisplay = '----';

                                                        if ($request['status'] === 'pending_admin_approval') {
                                                            if ($request['assigned_vehicle_id']) {
                                                                $vehicleDisplay = 'Pending Admin Approval';
                                                            }
                                                            if ($request['assigned_driver_id']) {
                                                                $driverDisplay = 'Pending Admin Approval';
                                                            }
                                                        } else {
                                                            $vehicleDisplay = $request['assigned_vehicle_id'] ? htmlspecialchars($vehicleLookup[$request['assigned_vehicle_id']] ?? '----') : '----';
                                                            $driverDisplay = $request['assigned_driver_id'] ? htmlspecialchars($driverLookup[$request['assigned_driver_id']] ?? '----') : '----';
                                                        }
                                                    ?>
                                                        <tr>
                                                            <td><?= htmlspecialchars($request['request_date']) ?></td>
                                                            <td>
                                                                <?php
                                                                if (!empty($request['departure_date'])) {
                                                                    echo htmlspecialchars($request['departure_date']);
                                                                } else {
                                                                    echo '----';
                                                                }
                                                                ?>
                                                            </td>
                                                            <td>
                                                                <?php
                                                                if (!empty($request['return_date'])) {
                                                                    echo htmlspecialchars($request['return_date']);
                                                                } else {
                                                                    echo '----';
                                                                }
                                                                ?>
                                                            </td>
                                                            <td><?= htmlspecialchars($request['destination']) ?></td>
                                                            <td><?= htmlspecialchars($request['purpose']) ?></td>
                                                            <td>
                                                                <?php
                                                                if (!empty($request['passenger_names'])) {
                                                                    $passengers = json_decode($request['passenger_names'], true);
                                                                    if (is_array($passengers)) {
                                                                        echo htmlspecialchars(implode(', ', $passengers));
                                                                    } else {
                                                                        echo htmlspecialchars($request['passenger_names']);
                                                                    }
                                                                } else {
                                                                    echo '----';
                                                                }
                                                                ?>
                                                            </td>
                                                            <td>
                                                                <span class="status-badge <?= getStatusBadgeClass($request['status']) ?>">
                                                                    <?= getStatusText($request['status']) ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <div class="small">
                                                                    <div class="mb-1">
                                                                        <i class="fas fa-car me-1 text-primary"></i>
                                                                        <strong>Vehicle:</strong> <?= $vehicleDisplay ?>
                                                                    </div>
                                                                    <div>
                                                                        <i class="fas fa-user me-1 text-info"></i>
                                                                        <strong>Driver:</strong> <?= $driverDisplay ?>
                                                                    </div>
                                                                </div>
                                                            </td>
                                                            <td>
                                                                <?php if ($canCancel): ?>
                                                                    <button type="button"
                                                                        class="btn btn-sm btn-danger"
                                                                        onclick="showCancelModal(<?= $request['id'] ?>)">
                                                                        <i class="fas fa-times-circle me-1"></i>Cancel
                                                                    </button>
                                                                <?php elseif ($request['status'] === 'cancelled'): ?>
                                                                    <span class="text-muted">
                                                                        <i class="fas fa-ban me-1"></i>Cancelled
                                                                    </span>
                                                                <?php elseif ($request['status'] === 'concluded'): ?>
                                                                    <span class="text-success">
                                                                        <i class="fas fa-check-circle me-1"></i>Completed
                                                                    </span>
                                                                <?php else: ?>
                                                                    <span class="text-muted">
                                                                        <i class="fas fa-info-circle me-1"></i>No Actions
                                                                    </span>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($isAdmin): ?>
                            <!-- Employee Management Tab -->
                            <div class="tab-pane fade" id="employeeManagement">
                                <div class="table-container">
                                    <div class="section-header">
                                        <h2 class="section-title text-info">
                                            <i class="fas fa-users"></i>
                                            Employee Management
                                        </h2>
                                        <a href="admin/add_employee.php" class="btn btn-primary-modern btn-m">
                                            <i class="fas fa-user-plus me-2"></i>Add Employee
                                        </a>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Name</th>
                                                    <th>Email</th>
                                                    <th>Role</th>
                                                    <th>Position</th>
                                                    <th>Booked Trips</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($employees)): ?>
                                                    <tr>
                                                        <td colspan="4" class="text-center">No employees found.</td>
                                                    </tr>
                                                <?php else: ?>
                                                    <?php foreach ($employees as $emp): ?>
                                                        <tr>
                                                            <td><?= htmlspecialchars($emp['name']) ?></td>
                                                            <td><?= htmlspecialchars($emp['email']) ?></td>
                                                            <td><?= htmlspecialchars(ucfirst($emp['role'])) ?></td>
                                                            <td><?= htmlspecialchars($emp['position']) ?></td>
                                                            <td>
                                                                <span class="status-badge <?= htmlspecialchars($employeeStatusData[$emp['id']]['status_class']) ?>">
                                                                    <?= htmlspecialchars($employeeStatusData[$emp['id']]['status']) ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <a href="admin/edit_employee.php?id=<?= $emp['id'] ?>"
                                                                    class="btn btn-sm btn-primary-modern">
                                                                    <i class="fas fa-edit"></i> Edit
                                                                </a>
                                                                <form action="admin/delete_employee.php" method="POST" style="display:inline;">
                                                                    <input type="hidden" name="id" value="<?= $emp['id'] ?>">
                                                                    <?= csrf_field() ?>
                                                                    <button type="submit" class="btn btn-danger btn-sm"
                                                                        onclick="return confirm('Are you sure you want to delete <?= htmlspecialchars($emp['name']); ?>?');">
                                                                        <i class="fas fa-trash-alt me-1"></i> Delete
                                                                    </button>
                                                                </form>

                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <!-- Driver Management Tab -->
                            <div class="tab-pane fade" id="driverManagement">
                                <div class="table-container">
                                    <div class="section-header">
                                        <h2 class="section-title text-info">
                                            <i class="fas fa-id-card"></i>
                                            Driver Management
                                        </h2>
                                        <a href="admin/add_driver.php" class="btn btn-primary-modern btn-modern">
                                            <i class="fas fa-plus me-2"></i>Add Driver
                                        </a>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Name</th>
                                                    <th>Email</th>
                                                    <th>Phone</th>
                                                    <th>Booked Trips</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($drivers)): ?>
                                                    <tr>
                                                        <td colspan="6" class="text-center">No drivers found.</td>
                                                    </tr>
                                                <?php else: ?>
                                                    <?php foreach ($drivers as $driver): ?>
                                                        <tr>
                                                            <td><?= htmlspecialchars($driver['name']) ?></td>
                                                            <td><?= htmlspecialchars($driver['email']) ?></td>
                                                            <td><?= htmlspecialchars($driver['phone'] ?? '') ?></td>
                                                            <td>
                                                                <span class="status-badge <?= htmlspecialchars($driver['trip_status']['status_class']) ?>">
                                                                    <?= htmlspecialchars($driver['trip_status']['status']) ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <a href="admin/edit_driver.php?id=<?= $driver['id'] ?>"
                                                                    class="btn btn-sm btn-primary-modern">
                                                                    <i class="fas fa-edit"></i> Edit
                                                                </a>
                                                                <form action="admin/delete_driver.php" method="POST" style="display:inline;">
                                                                    <input type="hidden" name="id" value="<?= $driver['id'] ?>">
                                                                    <?= csrf_field() ?>
                                                                    <button type="submit" class="btn btn-danger btn-sm"
                                                                        onclick="return confirm('Are you sure you want to delete <?= htmlspecialchars($driver['name']); ?>?');">
                                                                        <i class="fas fa-trash-alt me-1"></i>Delete
                                                                    </button>
                                                                </form>

                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <!-- Admin Requests Tab -->
                            <div class="tab-pane fade" id="adminRequests">
                                <div class="table-container mb-4">
                                    <div class="section-header">
                                        <h2 class="section-title text-warning">
                                            <i class="fas fa-clipboard-list"></i>
                                            Vehicle Requests Awaiting Your Approval
                                        </h2>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Requestor</th>
                                                    <th>Departure</th>
                                                    <th>Return</th>
                                                    <th>Destination</th>
                                                    <th>Purpose</th>
                                                    <th>Passengers</th>
                                                    <th>Vehicle</th>
                                                    <th>Driver</th>
                                                    <th>Requested On</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($pendingAdminApprovalRequests)): ?>
                                                    <tr>
                                                        <td colspan="11" class="text-center">No vehicle requests currently awaiting your approval.</td>
                                                    </tr>
                                                <?php else: ?>
                                                    <?php foreach ($pendingAdminApprovalRequests as $request):
                                                        // Prepare passenger display
                                                        $passengerDisplay = '----';
                                                        if (!empty($request['passenger_names'])) {
                                                            $passengers = json_decode($request['passenger_names'], true);
                                                            if (is_array($passengers)) {
                                                                $passengerDisplay = implode(', ', $passengers);
                                                            } else {
                                                                $passengerDisplay = $request['passenger_names'];
                                                            }
                                                        }

                                                        // Get vehicle and driver names
                                                        $vehicleName = $request['assigned_vehicle_id'] ? ($vehicleLookup[$request['assigned_vehicle_id']] ?? '----') : '----';
                                                        $driverName = $request['assigned_driver_id'] ? ($driverLookup[$request['assigned_driver_id']] ?? '----') : '----';
                                                        $latestAudit = $auditLogsByRequest[$request['id']][0] ?? null;
                                                        $latestAuditLabel = ($latestAudit && !empty($latestAudit['created_at']))
                                                            ? date('M j, Y g:i A', strtotime($latestAudit['created_at']))
                                                            : null;
                                                    ?>
                                                        <tr>
                                                            <td><?= htmlspecialchars($request['requestor_name']) ?></td>
                                                            <td>
                                                                <?php
                                                                if (!empty($request['departure_date'])) {
                                                                    echo htmlspecialchars($request['departure_date']);
                                                                } else {
                                                                    echo '----';
                                                                }
                                                                ?>
                                                            </td>
                                                            <td>
                                                                <?php
                                                                if (!empty($request['return_date'])) {
                                                                    echo htmlspecialchars($request['return_date']);
                                                                } else {
                                                                    echo '----';
                                                                }
                                                                ?>
                                                            </td>
                                                            <td><?= htmlspecialchars($request['destination']) ?></td>
                                                            <td><?= htmlspecialchars($request['purpose']) ?></td>
                                                            <td><?= htmlspecialchars($passengerDisplay) ?></td>
                                                            <td><?= htmlspecialchars($vehicleName) ?></td>
                                                            <td><?= htmlspecialchars($driverName) ?></td>
                                                            <td>
                                                                <?= htmlspecialchars($request['request_date']) ?>
                                                                <?php if ($latestAudit): ?>
                                                                    <div class="text-muted small mt-1">
                                                                        <i class="fas fa-history me-1"></i><?= htmlspecialchars(ucwords(str_replace('_', ' ', $latestAudit['action']))) ?>
                                                                        <?php if ($latestAuditLabel): ?>
                                                                            <div><?= htmlspecialchars($latestAuditLabel) ?></div>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <button type="button"
                                                                    class="btn btn-sm btn-primary-modern"
                                                                    data-bs-toggle="modal"
                                                                    data-bs-target="#adminActionModal"
                                                                    data-request-id="<?= $request['id'] ?>"
                                                                    data-requestor-name="<?= htmlspecialchars($request['requestor_name']) ?>"
                                                                    data-requestor-email="<?= htmlspecialchars($request['requestor_email']) ?>"
                                                                    data-departure-date="<?= htmlspecialchars($request['departure_date'] ?? '----') ?>"
                                                                    data-return-date="<?= htmlspecialchars($request['return_date'] ?? '----') ?>"
                                                                    data-destination="<?= htmlspecialchars($request['destination']) ?>"
                                                                    data-purpose="<?= htmlspecialchars($request['purpose']) ?>"
                                                                    data-passengers="<?= htmlspecialchars($passengerDisplay) ?>"
                                                                    data-assigned-vehicle="<?= htmlspecialchars($vehicleName) ?>"
                                                                    data-assigned-driver="<?= htmlspecialchars($driverName) ?>"
                                                                    data-request-date="<?= htmlspecialchars($request['request_date']) ?>">
                                                                    <i class="fas fa-cogs"></i> Take Action
                                                                </button>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <!-- Admin Action Modal with Request Details Preview -->
                            <div class="modal fade" id="adminActionModal" tabindex="-1" aria-labelledby="adminActionModalLabel" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header bg-primary text-light">
                                            <h5 class="modal-title" id="adminActionModalLabel">
                                                <i class="fas fa-clipboard-check me-2"></i>Review Vehicle Request
                                            </h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <form id="adminActionForm" action="process_request.php" method="POST">
                                            <input type="hidden" name="id" id="modalRequestId">
                                            <?= csrf_field() ?>
                                            <div class="modal-body text-dark">
                                                <!-- Request Details Preview -->
                                                <div class="card mb-3 border-primary">
                                                    <div class="card-header bg-light">
                                                        <h6 class="mb-0">
                                                            <i class="fas fa-info-circle me-2"></i>Request Details
                                                        </h6>
                                                    </div>
                                                    <div class="card-body">
                                                        <div class="row">
                                                            <div class="col-md-6 mb-3">
                                                                <label class="text-muted small">Requestor</label>
                                                                <div class="fw-bold" id="modalRequestorName"></div>
                                                            </div>
                                                            <div class="col-md-6 mb-3">
                                                                <label class="text-muted small">Email</label>
                                                                <div class="fw-bold" id="modalRequestorEmail"></div>
                                                            </div>
                                                            <div class="col-md-6 mb-3">
                                                                <label class="text-muted small">Departure Date</label>
                                                                <div class="fw-bold" id="modalDepartureDate"></div>
                                                            </div>
                                                            <div class="col-md-6 mb-3">
                                                                <label class="text-muted small">Return Date</label>
                                                                <div class="fw-bold" id="modalReturnDate"></div>
                                                            </div>
                                                            <div class="col-md-12 mb-3">
                                                                <label class="text-muted small">Destination</label>
                                                                <div class="fw-bold" id="modalDestination"></div>
                                                            </div>
                                                            <div class="col-md-12 mb-3">
                                                                <label class="text-muted small">Purpose</label>
                                                                <div class="fw-bold" id="modalPurpose"></div>
                                                            </div>
                                                            <div class="col-md-12 mb-3">
                                                                <label class="text-muted small">Passengers</label>
                                                                <div class="fw-bold" id="modalPassengers"></div>
                                                            </div>
                                                            <div class="col-md-6 mb-3">
                                                                <label class="text-muted small">Assigned Vehicle</label>
                                                                <div class="fw-bold text-primary" id="modalAssignedVehicle"></div>
                                                            </div>
                                                            <div class="col-md-6 mb-3">
                                                                <label class="text-muted small">Assigned Driver</label>
                                                                <div class="fw-bold text-primary" id="modalAssignedDriver"></div>
                                                            </div>
                                                            <div class="col-md-6 mb-3">
                                                                <label class="text-muted small">Request Date</label>
                                                                <div class="fw-bold" id="modalRequestDate"></div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Action Selection -->
                                                <div class="mb-3">
                                                    <label for="actionSelect" class="form-label fw-bold">
                                                        <i class="fas fa-tasks me-2"></i>Select Action:
                                                    </label>
                                                    <select class="form-select" id="actionSelect" name="action" required>
                                                        <option value="">-- Choose an Action --</option>
                                                        <option value="approve">✓ Approve Request</option>
                                                        <option value="reject">✗ Reject Request</option>
                                                    </select>
                                                </div>

                                                <!-- Rejection Reason Group -->
                                                <div id="rejectionReasonGroup" class="mb-3" style="display: none;">
                                                    <label for="rejectionReason" class="form-label fw-bold">
                                                        <i class="fas fa-exclamation-circle me-2"></i>Reason for Rejection:
                                                    </label>
                                                    <select class="form-select" id="rejectionReason" name="rejection_reason">
                                                        <option value="">-- Select a Reason --</option>
                                                        <option value="reassign_vehicle">🚗 Reassign Vehicle</option>
                                                        <option value="reassign_driver">👤 Reassign Driver</option>
                                                        <option value="new_request">❌ Reject Completely</option>
                                                    </select>
                                                </div>

                                                <div id="modalAlert" class="alert" style="display: none;"></div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                    <i class="fas fa-times me-2"></i>Cancel
                                                </button>
                                                <button type="submit" class="btn btn-primary" id="modalSubmitButton">
                                                    <i class="fas fa-check me-2"></i>Submit
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                    </div>
                <?php endif; ?>


                </div>
            </div>
        </div>

        <?php if ($isAdmin): ?>
            <div class="modal fade text-dark" id="calendarEventModal" tabindex="-1" aria-labelledby="calendarEventModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <div class="modal-content">
                        <div class="modal-header bg-primary text-white">
                            <h5 class="modal-title" id="calendarEventModalLabel">
                                <i class="fas fa-car-side me-2"></i>Reservation Details
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <p class="text-muted small mb-1">Vehicle</p>
                                    <div class="fw-bold" id="calendarModalVehicle">--</div>
                                </div>
                                <div class="col-md-6">
                                    <p class="text-muted small mb-1">Plate Number</p>
                                    <div class="fw-bold" id="calendarModalPlate">--</div>
                                </div>
                                <div class="col-md-6">
                                    <p class="text-muted small mb-1">Requestor</p>
                                    <div class="fw-bold" id="calendarModalRequestor">--</div>
                                </div>
                                <div class="col-md-6">
                                    <p class="text-muted small mb-1">Email</p>
                                    <div id="calendarModalEmail">--</div>
                                </div>
                                <div class="col-md-6">
                                    <p class="text-muted small mb-1">Destination</p>
                                    <div id="calendarModalDestination">--</div>
                                </div>
                                <div class="col-md-6">
                                    <p class="text-muted small mb-1">Driver</p>
                                    <div id="calendarModalDriver">--</div>
                                </div>
                                <div class="col-md-6">
                                    <p class="text-muted small mb-1">Travel Window</p>
                                    <div id="calendarModalDates">--</div>
                                </div>
                                <div class="col-md-6">
                                    <p class="text-muted small mb-1">Status</p>
                                    <div id="calendarModalStatus">--</div>
                                </div>
                                <div class="col-12">
                                    <p class="text-muted small mb-1">Purpose</p>
                                    <div id="calendarModalPurpose">--</div>
                                </div>
                                <div class="col-12">
                                    <p class="text-muted small mb-1">Passengers</p>
                                    <div id="calendarModalPassengers">--</div>
                                </div>
                            </div>
                            <div class="mt-4">
                                <h6 class="text-muted text-uppercase small mb-2">
                                    <i class="fas fa-history me-2"></i>Audit Trail
                                </h6>
                                <div id="calendarAuditTimeline" class="audit-timeline">
                                    <p class="text-muted small mb-0">No audit activity recorded.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Login Required Modal -->
        <div class="modal fade text-dark" id="loginRequiredModal" tabindex="-1" aria-labelledby="loginRequiredModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="loginRequiredModalLabel">
                            <i class="fas fa-lock me-2"></i>Login Required
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="text-center">
                            <i class="fas fa-user-shield fa-3x text-primary mb-3"></i>
                            <h6>Authentication Required</h6>
                            <p>You need to be logged in as an employee to request a vehicle. Please login or create an account to continue.</p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a href="login.php" class="btn btn-primary">
                            <i class="fas fa-sign-in-alt me-2"></i>Login
                        </a>
                        <button class="btn btn-outline-primary" onclick="showSignupInfo()">
                            <i class="fas fa-user-plus me-2"></i>Sign Up
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Signup Info Modal -->
        <div class="modal fade text-dark" id="signupInfoModal" tabindex="-1" aria-labelledby="signupInfoModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="signupInfoModalLabel">
                            <i class="fas fa-user-plus me-2"></i>Account Registration
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="text-center">
                            <i class="fas fa-user-shield fa-3x text-info mb-3"></i>
                            <h6>Administrator Enrollment Required</h6>
                            <p>To get access to the Vehicle Request System, you need to be enrolled by an administrator.</p>
                            <div class="alert alert-info alert-permanent mt-3">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Next Steps:</strong>
                                <ul class="list-unstyled mt-2 mb-0">
                                    <li>• Contact your system administrator</li>
                                    <li>• Request to be enrolled in the system</li>
                                    <li>• Provide your employment details</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">
                            <i class="fas fa-check me-2"></i>Understood
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <!-- Passenger Warning Modal -->
        <div class="modal fade text-dark" id="passengerWarningModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title">
                            <i class="fas fa-exclamation-triangle me-2"></i>Passenger Status Warning
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>You are currently listed as a passenger in:</p>
                        <div class="card border-primary mb-3">
                            <div class="card-body">
                                <p><strong>Requestor:</strong> <?= htmlspecialchars($passengerRequestDetails['requestor_name'] ?? 'N/A') ?></p>
                                <p><strong>Status:</strong> <?= getStatusText($passengerRequestDetails['status'] ?? '') ?></p>
                            </div>
                        </div>
                        <p><strong>Do you want to proceed with creating a new request?</strong></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <a href="create_request.php" class="btn btn-primary">Yes, Continue</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Outgoing Trips Warning Modal - New (when user is the requestor with existing trips) -->
        <div class="modal fade text-dark" id="outgoingTripsWarningModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-info text-white">
                        <h5 class="modal-title">
                            <i class="fas fa-calendar-check me-2"></i>Outgoing Trips Detected
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p><strong>You have outgoing trips, do you wish to book another one?</strong></p>
                        <div class="alert alert-info mb-0">
                            <i class="fas fa-info-circle me-2"></i>
                            You can manage multiple vehicle requests for different dates.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-2"></i>Cancel
                        </button>
                        <a href="create_request.php" class="btn btn-primary">
                            <i class="fas fa-check me-2"></i>Yes, Continue
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Multiple Requests Info Modal - Shows when user has any existing requests/trips -->
        <div class="modal fade text-dark" id="multipleRequestsModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-info text-white">
                        <h5 class="modal-title">
                            <i class="fas fa-calendar-plus me-2"></i>Add Another Vehicle Request
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p><strong>You currently have:</strong></p>
                        <ul class="list-unstyled mb-3">
                            <?php if (!empty($pendingRequests)): ?>
                                <li class="mb-2">
                                    <i class="fas fa-clock text-warning me-2"></i>
                                    <strong><?= count($pendingRequests) ?></strong> pending request<?= count($pendingRequests) > 1 ? 's' : '' ?>
                                </li>
                            <?php endif; ?>
                            <?php if (!empty($approvedRequests)): ?>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success me-2"></i>
                                    <strong><?= count($approvedRequests) ?></strong> approved trip<?= count($approvedRequests) > 1 ? 's' : '' ?>
                                </li>
                            <?php endif; ?>
                        </ul>
                        <p>You can submit multiple vehicle requests for different dates. Each request will be processed independently.</p>
                        <div class="alert alert-info mb-0">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Tip:</strong> Make sure your trip dates don't overlap to avoid scheduling conflicts.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-2"></i>Cancel
                        </button>
                        <a href="create_request.php" class="btn btn-primary">
                            <i class="fas fa-check me-2"></i>Continue to Request
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <!-- Cancel Request Modal -->
        <div class="modal fade text-dark" id="cancelRequestModal" tabindex="-1" aria-labelledby="cancelRequestModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title text-white" id="cancelRequestModalLabel">
                            <i class="fas fa-times-circle me-2"></i>Cancel Vehicle Request
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form id="cancelRequestForm" action="employee/cancel_my_request.php" method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="request_id" id="cancelRequestId">
                        <div class="modal-body">
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Warning:</strong> This action cannot be undone. The vehicle and driver will become available for others.
                            </div>

                            <div class="mb-3">
                                <label for="cancel_reason" class="form-label">
                                    <i class="fas fa-comment me-1"></i>Reason for Cancellation <span class="text-danger">*</span>
                                </label>
                                <input type="text"
                                    class="form-control"
                                    id="cancel_reason"
                                    name="cancel_reason"
                                    placeholder="e.g., Schedule changed, No longer needed"
                                    maxlength="150"
                                    required>
                                <div class="form-text">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Please provide a brief reason (max 150 characters)
                                </div>
                            </div>

                            <div id="charCount" class="text-muted small text-end">
                                0 / 150 characters
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="fas fa-arrow-left me-1"></i>Go Back
                            </button>
                            <button type="submit" class="btn btn-danger">
                                <i class="fas fa-times-circle me-1"></i>Confirm Cancellation
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js"></script>
        <script src="js/common.js"></script>
        <script src="js/calendar_utils.js"></script>
        <script>
            const calendarEventsData = <?= json_encode(array_values($calendarEvents), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
            const calendarRequestDetails = <?= json_encode($calendarRequestDetails, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_FORCE_OBJECT); ?>;
            const isAdminUser = <?= $isAdmin ? 'true' : 'false'; ?>;

            function showCancelModal(requestId) {
                console.log('Opening cancel modal for request:', requestId);
                document.getElementById('cancelRequestId').value = requestId;
                document.getElementById('cancel_reason').value = '';
                document.getElementById('charCount').textContent = '0 / 150 characters';
                var cancelModal = new bootstrap.Modal(document.getElementById('cancelRequestModal'));
                cancelModal.show();
            }

            // Character counter for cancel reason
            document.addEventListener('DOMContentLoaded', function() {
                var cancelReasonInput = document.getElementById('cancel_reason');
                var charCount = document.getElementById('charCount');

                if (cancelReasonInput) {
                    cancelReasonInput.addEventListener('input', function() {
                        var length = this.value.length;
                        charCount.textContent = length + ' / 150 characters';

                        // Remove all color classes first
                        charCount.classList.remove('text-danger', 'text-warning', 'text-muted');

                        // Change color when approaching limit
                        if (length > 140) {
                            charCount.classList.add('text-danger');
                        } else if (length > 120) {
                            charCount.classList.add('text-warning');
                            charCount.classList.remove('text-danger');
                        } else {
                            charCount.classList.remove('text-warning', 'text-danger');
                        }
                    });
                }
            });

            function showPassengerWarningModal() {
                const modal = new bootstrap.Modal(document.getElementById('passengerWarningModal'));
                modal.show();
            }

            function showMultipleRequestsModal() {
                const modal = new bootstrap.Modal(document.getElementById('multipleRequestsModal'));
                modal.show();
            }

            function showOutgoingTripsWarningModal() {
                const modal = new bootstrap.Modal(document.getElementById('outgoingTripsWarningModal'));
                modal.show();
            }

            // Auto-dismiss alerts (but skip permanent ones)
            document.addEventListener('DOMContentLoaded', function() {
                const alerts = document.querySelectorAll('.alert:not(.alert-permanent)');
                alerts.forEach(alert => {
                    setTimeout(() => {
                        const bsAlert = new bootstrap.Alert(alert);
                        bsAlert.close();
                    }, 5000);
                });

                // Handle initial tab activation based on URL hash or default
                const urlParams = new URLSearchParams(window.location.search);
                const tab = urlParams.get('tab');
                if (tab) {
                    const tabElement = document.querySelector(`#dashboardTabs a[href="#${tab}"]`);
                    if (tabElement) {
                        new bootstrap.Tab(tabElement).show();
                    }
                } else {
                    // Default to 'calendar' tab if no hash
                    const defaultTab = document.querySelector('#dashboardTabs a[href="#calendar"]');
                    if (defaultTab) {
                        new bootstrap.Tab(defaultTab).show();
                    }
                }

                // Update URL hash when tab changes
                const tabLinks = document.querySelectorAll('#dashboardTabs .nav-link');
                tabLinks.forEach(link => {
                    link.addEventListener('shown.bs.tab', function(event) {
                        const newTabId = event.target.getAttribute('href').substring(1); // Remove '#'
                        const newUrl = new URL(window.location.href);
                        newUrl.searchParams.set('tab', newTabId);
                        window.history.pushState({
                            path: newUrl.href
                        }, '', newUrl.href);
                    });
                });
            });

            // Function to show login required modal for guests
            function requireLogin() {
                const modal = new bootstrap.Modal(document.getElementById('loginRequiredModal'));
                modal.show();
            }

            // Function to show signup info modal for guests
            function showSignupInfo() {
                const modal = new bootstrap.Modal(document.getElementById('signupInfoModal'));
                modal.show();
            }

            // Add loading states to buttons (optional, for visual feedback)
            document.querySelectorAll('.btn-modern').forEach(button => {
                button.addEventListener('click', function(e) {
                    if (!this.classList.contains('disabled') && !this.classList.contains('no-loading') && !this.onclick) {
                        const originalText = this.innerHTML;
                        this.dataset.originalText = originalText; // Store original text
                        this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Loading...';
                        this.classList.add('disabled'); // Disable button during loading

                        // Re-enable button after a short delay or on form submission/AJAX completion
                        setTimeout(() => {
                            if (this.dataset.originalText) {
                                this.innerHTML = this.dataset.originalText;
                                this.classList.remove('disabled');
                            }
                        }, 2000);
                    }
                });
            });

            document.addEventListener("DOMContentLoaded", function() {
                const calendarElement = document.getElementById('fleetCalendar');
                const calendarModalEl = document.getElementById('calendarEventModal');
                const calendarModalInstance = calendarModalEl ? new bootstrap.Modal(calendarModalEl) : null;

                if (calendarElement && window.FullCalendar) {
                    const calendar = new FullCalendar.Calendar(calendarElement, {
                        initialView: 'dayGridMonth',
                        height: 'auto',
                        headerToolbar: {
                            left: 'prev,next today',
                            center: 'title',
                            right: ''
                        },
                        events: calendarEventsData,
                        eventDisplay: 'block',
                        eventClick(info) {
                            if (!isAdminUser || !calendarModalInstance) {
                                return;
                            }
                            const details = calendarRequestDetails[String(info.event.id)];
                            if (!details) {
                                return;
                            }
                            populateCalendarModal(details);
                            calendarModalInstance.show();
                        },
                        eventDidMount(info) {
                            const details = calendarRequestDetails[String(info.event.id)];
                            if (details) {
                                info.el.setAttribute('title', `${details.requestor} • ${details.destination || 'Destination TBD'}`);
                            }
                        }
                    });
                    calendar.render();
                }

                // Handle clicks on tabs
                document.querySelectorAll('a[data-bs-toggle="tab"]').forEach(tabEl => {
                    tabEl.addEventListener("shown.bs.tab", function(event) {
                        const hash = event.target.getAttribute("href");
                        history.replaceState(null, null, hash); // updates URL hash
                    });
                });

                // Restore tab from hash (including redirect hashes)
                const hash = window.location.hash;
                if (hash) {
                    const triggerEl = document.querySelector(`a[href="${hash}"]`);
                    const targetEl = document.querySelector(hash);

                    if (triggerEl && targetEl) {
                        setTimeout(() => {
                            const tab = new bootstrap.Tab(triggerEl);
                            tab.show();
                            targetEl.scrollIntoView({
                                behavior: "smooth",
                                block: "start"
                            });
                        }, 500);
                    }
                }

                // Enhanced Admin Action Modal Logic with Request Preview
                const adminActionModal = document.getElementById('adminActionModal');
                if (adminActionModal) {
                    const actionSelect = adminActionModal.querySelector('#actionSelect');
                    const rejectionReasonGroup = adminActionModal.querySelector('#rejectionReasonGroup');
                    const rejectionReasonSelect = adminActionModal.querySelector('#rejectionReason');
                    const modalAlert = adminActionModal.querySelector('#modalAlert');
                    const adminActionForm = adminActionModal.querySelector('#adminActionForm');

                    // Only set up event listeners ONCE
                    actionSelect.addEventListener('change', function() {
                        if (this.value === 'reject') {
                            rejectionReasonGroup.style.display = 'block';
                            rejectionReasonSelect.setAttribute('required', 'required');
                        } else {
                            rejectionReasonGroup.style.display = 'none';
                            rejectionReasonSelect.removeAttribute('required');
                        }
                    });

                    adminActionForm.addEventListener('submit', function(e) {
                        modalAlert.style.display = 'none';
                        modalAlert.className = 'alert';

                        if (actionSelect.value === 'reject' && !rejectionReasonSelect.value) {
                            e.preventDefault();
                            modalAlert.textContent = 'Please select a rejection reason.';
                            modalAlert.classList.add('alert-danger');
                            modalAlert.style.display = 'block';
                        } else if (!actionSelect.value) {
                            e.preventDefault();
                            modalAlert.textContent = 'Please select an action (Approve or Reject).';
                            modalAlert.classList.add('alert-danger');
                            modalAlert.style.display = 'block';
                        }
                    });

                    // This part runs every time the modal opens - now with request details
                    adminActionModal.addEventListener('show.bs.modal', function(event) {
                        const button = event.relatedTarget;

                        // Get all data attributes
                        const requestId = button.getAttribute('data-request-id');
                        const requestorName = button.getAttribute('data-requestor-name');
                        const requestorEmail = button.getAttribute('data-requestor-email');
                        const departureDate = button.getAttribute('data-departure-date');
                        const returnDate = button.getAttribute('data-return-date');
                        const destination = button.getAttribute('data-destination');
                        const purpose = button.getAttribute('data-purpose');
                        const passengers = button.getAttribute('data-passengers');
                        const assignedVehicle = button.getAttribute('data-assigned-vehicle');
                        const assignedDriver = button.getAttribute('data-assigned-driver');
                        const requestDate = button.getAttribute('data-request-date');

                        // Populate modal fields
                        document.getElementById('modalRequestId').value = requestId;
                        document.getElementById('modalRequestorName').textContent = requestorName;
                        document.getElementById('modalRequestorEmail').textContent = requestorEmail || '----';
                        document.getElementById('modalDepartureDate').textContent = departureDate || '----';
                        document.getElementById('modalReturnDate').textContent = returnDate || '----';
                        document.getElementById('modalDestination').textContent = destination || '----';
                        document.getElementById('modalPurpose').textContent = purpose || '----';
                        document.getElementById('modalPassengers').textContent = passengers || '----';
                        document.getElementById('modalAssignedVehicle').textContent = assignedVehicle || '----';
                        document.getElementById('modalAssignedDriver').textContent = assignedDriver || '----';
                        document.getElementById('modalRequestDate').textContent = requestDate || '----';

                        // Reset form controls
                        actionSelect.value = '';
                        rejectionReasonGroup.style.display = 'none';
                        rejectionReasonSelect.removeAttribute('required');
                        modalAlert.style.display = 'none';
                    });
                }

            });

            function confirmCancelRequest(requestId) {
                if (confirm('Are you sure you want to cancel this vehicle request? This action cannot be undone and the vehicle will become available for others.')) {
                    document.getElementById('cancelRequestId').value = requestId;
                    document.getElementById('cancelRequestForm').submit();
                }
            }

            function setModalText(id, value) {
                const el = document.getElementById(id);
                if (el) {
                    el.textContent = value || '----';
                }
            }

            function formatDateLabel(dateStr) {
                if (!dateStr) {
                    return 'Date TBD';
                }
                const date = new Date(`${dateStr}T00:00:00`);
                if (Number.isNaN(date.getTime())) {
                    return 'Date TBD';
                }
                return date.toLocaleDateString(undefined, {
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric'
                });
            }

            function formatRange(start, end) {
                if (!start) {
                    return 'Date TBD';
                }
                if (!end || end === start) {
                    return formatDateLabel(start);
                }
                return `${formatDateLabel(start)} - ${formatDateLabel(end)}`;
            }

            function formatTimestamp(dateTimeStr) {
                if (!dateTimeStr) {
                    return '';
                }
                const date = new Date(dateTimeStr.replace(' ', 'T'));
                if (Number.isNaN(date.getTime())) {
                    return dateTimeStr;
                }
                return date.toLocaleString(undefined, {
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric',
                    hour: 'numeric',
                    minute: '2-digit'
                });
            }

            function formatActionLabel(action) {
                if (!action) {
                    return 'Update';
                }
                return action
                    .toLowerCase()
                    .split('_')
                    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
                    .join(' ');
            }

            function populateCalendarModal(details) {
                if (!details) {
                    return;
                }
                setModalText('calendarModalVehicle', details.vehicle || details.plate || 'Vehicle TBD');
                setModalText('calendarModalPlate', details.plate || 'Plate TBD');
                setModalText('calendarModalRequestor', details.requestor || '----');
                setModalText('calendarModalEmail', details.email || '----');
                setModalText('calendarModalDestination', details.destination || 'Destination TBD');
                setModalText('calendarModalDriver', details.driver || 'Pending Assignment');
                setModalText('calendarModalDates', formatRange(details.start, details.end));
                setModalText('calendarModalStatus', details.status || 'Approved');
                setModalText('calendarModalPurpose', details.purpose || '----');
                setModalText('calendarModalPassengers', details.passengers || '----');

                const auditContainer = document.getElementById('calendarAuditTimeline');
                if (!auditContainer) {
                    return;
                }

                auditContainer.innerHTML = '';
                if (Array.isArray(details.audit) && details.audit.length) {
                    details.audit.slice(0, 5).forEach(entry => {
                        const auditEntry = document.createElement('div');
                        auditEntry.className = 'audit-entry';

                        const title = document.createElement('div');
                        title.className = 'audit-entry__title';
                        title.textContent = formatActionLabel(entry.action);
                        auditEntry.appendChild(title);

                        const meta = document.createElement('div');
                        meta.className = 'audit-entry__meta';

                        const actorSpan = document.createElement('span');
                        const actorIcon = document.createElement('i');
                        actorIcon.className = 'fas fa-user me-1';
                        actorSpan.appendChild(actorIcon);
                        actorSpan.appendChild(document.createTextNode(entry.actor_name || 'System'));
                        meta.appendChild(actorSpan);

                        const timestampLabel = formatTimestamp(entry.created_at);
                        if (timestampLabel) {
                            const timeSpan = document.createElement('span');
                            const timeIcon = document.createElement('i');
                            timeIcon.className = 'fas fa-clock me-1';
                            timeSpan.appendChild(timeIcon);
                            timeSpan.appendChild(document.createTextNode(timestampLabel));
                            meta.appendChild(timeSpan);
                        }

                        auditEntry.appendChild(meta);

                        if (entry.notes) {
                            const notes = document.createElement('div');
                            notes.className = 'audit-entry__notes';
                            notes.textContent = entry.notes;
                            auditEntry.appendChild(notes);
                        }

                        auditContainer.appendChild(auditEntry);
                    });
                } else {
                    const emptyState = document.createElement('p');
                    emptyState.className = 'text-muted small mb-0';
                    emptyState.textContent = 'No audit activity recorded.';
                    auditContainer.appendChild(emptyState);
                }
            }
            document.addEventListener('DOMContentLoaded', function() {
                const adminActionModal = document.getElementById('adminActionModal');

                if (adminActionModal) {
                    adminActionModal.addEventListener('show.bs.modal', async function(event) {
                        const button = event.relatedTarget;

                        // Get all data attributes
                        const requestId = button.getAttribute('data-request-id');
                        const assignedVehicle = button.getAttribute('data-assigned-vehicle');
                        const assignedDriver = button.getAttribute('data-assigned-driver');
                        const departureDate = button.getAttribute('data-departure-date');
                        const returnDate = button.getAttribute('data-return-date');

                        // Populate modal fields (existing code)
                        document.getElementById('modalRequestId').value = requestId;
                        document.getElementById('modalRequestorName').textContent = button.getAttribute('data-requestor-name');
                        document.getElementById('modalRequestorEmail').textContent = button.getAttribute('data-requestor-email') || '----';
                        document.getElementById('modalDepartureDate').textContent = departureDate || '----';
                        document.getElementById('modalReturnDate').textContent = returnDate || '----';
                        document.getElementById('modalDestination').textContent = button.getAttribute('data-destination') || '----';
                        document.getElementById('modalPurpose').textContent = button.getAttribute('data-purpose') || '----';
                        document.getElementById('modalPassengers').textContent = button.getAttribute('data-passengers') || '----';
                        document.getElementById('modalAssignedVehicle').textContent = assignedVehicle || '----';
                        document.getElementById('modalAssignedDriver').textContent = assignedDriver || '----';
                        document.getElementById('modalRequestDate').textContent = button.getAttribute('data-request-date') || '----';

                        // Reset form controls
                        const actionSelect = document.getElementById('actionSelect');
                        const rejectionReasonGroup = document.getElementById('rejectionReasonGroup');
                        const rejectionReasonSelect = document.getElementById('rejectionReason');
                        const modalAlert = document.getElementById('modalAlert');

                        actionSelect.value = '';
                        rejectionReasonGroup.style.display = 'none';
                        rejectionReasonSelect.removeAttribute('required');
                        modalAlert.style.display = 'none';

                        // NEW: Check for conflicts with other pending requests
                        if (assignedVehicle !== '----' && assignedVehicle !== 'TBD' && departureDate !== '----') {
                            try {
                                const response = await fetch('check_pending_conflicts.php', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/x-www-form-urlencoded',
                                    },
                                    body: new URLSearchParams({
                                        request_id: requestId,
                                        vehicle: assignedVehicle,
                                        driver: assignedDriver,
                                        departure_date: departureDate,
                                        return_date: returnDate,
                                        csrf_token: document.querySelector('input[name="csrf_token"]').value
                                    })
                                });

                                const data = await response.json();

                                if (data.has_conflicts) {
                                    let warningHtml = '<div class="alert alert-warning mb-3" role="alert">';
                                    warningHtml += '<h6 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Conflict Detected!</h6>';

                                    if (data.vehicle_conflict) {
                                        warningHtml += '<p class="mb-2"><strong>Vehicle Conflict:</strong> ' + data.vehicle_conflict.message + '</p>';
                                    }

                                    if (data.driver_conflict) {
                                        warningHtml += '<p class="mb-2"><strong>Driver Conflict:</strong> ' + data.driver_conflict.message + '</p>';
                                    }

                                    warningHtml += '<hr class="my-2">';
                                    warningHtml += '<p class="mb-0 small"><i class="fas fa-info-circle me-1"></i><strong>Recommendation:</strong> Reject this request and select "Reassign Vehicle" or "Reassign Driver" to send it back to dispatch for reassignment.</p>';
                                    warningHtml += '</div>';

                                    // Insert warning at the top of the modal body
                                    const modalBody = adminActionModal.querySelector('.modal-body');
                                    const existingWarning = modalBody.querySelector('.conflict-warning-container');

                                    if (existingWarning) {
                                        existingWarning.innerHTML = warningHtml;
                                    } else {
                                        const warningContainer = document.createElement('div');
                                        warningContainer.className = 'conflict-warning-container';
                                        warningContainer.innerHTML = warningHtml;
                                        modalBody.insertBefore(warningContainer, modalBody.firstChild);
                                    }
                                } else {
                                    // Remove any existing warning
                                    const existingWarning = adminActionModal.querySelector('.conflict-warning-container');
                                    if (existingWarning) {
                                        existingWarning.remove();
                                    }
                                }
                            } catch (error) {
                                console.error('Error checking conflicts:', error);
                            }
                        }
                    });
                }
            });
        </script>
</body>

</html>