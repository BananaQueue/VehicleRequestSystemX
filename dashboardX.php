<?php
// session_name("site3_session"); // Handled in session.php
require_once __DIR__ . '/includes/session.php';
include 'error.php';
require 'db.php';



// Helper Functions
function canEmployeeRequest($employeeRequestStatus, $isAssignedVehicle, $isReturningVehicle) {
    return !in_array($employeeRequestStatus, ['pending_dispatch_assignment', 'pending_admin_approval', 'rejected_reassign_dispatch']) 
           && !($employeeRequestStatus === 'approved' && $isAssignedVehicle) 
           && !$isReturningVehicle;
}


function sortVehiclesByStatus($vehicles, $priorities) {
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
        usort($group, function($a, $b) {
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

function getStatusBadgeClass($status) {
    $statusMap = [
        'pending_dispatch_assignment' => 'status-returning',
        'pending_admin_approval' => 'status-returning',
        'approved' => 'status-available',
        'rejected_new_request' => 'status-assigned',
        'rejected_reassign_dispatch' => 'status-returning',
        'rejected' => 'status-assigned' // Keep for old rejected requests or for a general rejected status
    ];
    
    return $statusMap[$status] ?? '';
}

function getStatusText($status) {
    $statusMap = [
        'pending_dispatch_assignment' => 'Awaiting Dispatch',
        'pending_admin_approval' => 'Awaiting Admin Approval',
        'approved' => 'Approved',
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
$isDispatch = $isLoggedIn && $_SESSION['user']['role'] === 'dispatch';
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
$isReturningVehicle = false;
$returningVehicle = null;
$pendingRequests = [];
$approvedPendingDispatchRequests = [];
$pendingReturns = [];
$dispatchPendingRequests = [];
$pendingAdminApprovalRequests = []; // New variable for admin pending approval

// Employee-specific data and vehicle status
if ($isEmployee) {
    // Check for assigned and returning vehicles in one block
    $stmt = $pdo->prepare("SELECT * FROM vehicles WHERE assigned_to = :username AND status = 'assigned' LIMIT 1");
    $stmt->execute(['username' => $username]);
    $assignedVehicle = $stmt->fetch(PDO::FETCH_ASSOC);
    $isAssignedVehicle = (bool)$assignedVehicle;
    
    $stmt = $pdo->prepare("SELECT * FROM vehicles WHERE returned_by = :username AND status = 'returning' LIMIT 1");
    $stmt->execute(['username' => $username]);
    $returningVehicle = $stmt->fetch(PDO::FETCH_ASSOC);
    $isReturningVehicle = (bool)$returningVehicle;

    // Fetch employee requests
    $stmt = $pdo->prepare("SELECT * FROM requests WHERE user_id = :user_id ORDER BY request_date DESC");
    $stmt->execute(['user_id' => $user_id]);
    $myRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($myRequests)) {
        $employeeRequestStatus = $myRequests[0]['status'];
    }
}

// Admin-specific data
if ($isAdmin) {
    // Fetch employees
    $stmt = $pdo->query("SELECT * FROM users WHERE role != 'admin'");
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch drivers with assignment status - optimized approach
    $stmt = $pdo->query("SELECT * FROM drivers ORDER BY name ASC");
    $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all driver assignments in one query
    $driverAssignments = [];
    $stmt = $pdo->query("SELECT driver_name, plate_number FROM vehicles WHERE status = 'assigned' AND driver_name IS NOT NULL");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $driverAssignments[$row['driver_name']] = $row['plate_number'];
    }
    
    // Add assignment status to drivers and sort
    $assignedDrivers = [];
    $availableDrivers = [];
    
    foreach ($drivers as $driver) {
        $driver['assigned_vehicle'] = $driverAssignments[$driver['name']] ?? null;
        $driver['is_assigned'] = isset($driverAssignments[$driver['name']]);
        
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

    // Fetch pending returns
    $stmt = $pdo->query("SELECT * FROM vehicles WHERE status = 'returning' ORDER BY return_date ASC");
    $pendingReturns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get employee status information
    $employeeStatusData = [];
    
    foreach ($employees as $employee) {
        $empName = $employee['name'];
        $empId = $employee['id'];
        
        // Check for assigned vehicle
        $stmt = $pdo->prepare("SELECT plate_number FROM vehicles WHERE assigned_to = ? AND status = 'assigned' LIMIT 1");
        $stmt->execute([$empName]);
        $assignedVehicle = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Check for returning vehicle
        $stmt = $pdo->prepare("SELECT plate_number FROM vehicles WHERE returned_by = ? AND status = 'returning' LIMIT 1");
        $stmt->execute([$empName]);
        $returningVehicle = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get latest request status
        $stmt = $pdo->prepare("SELECT status FROM requests WHERE user_id = ? ORDER BY request_date DESC LIMIT 1");
        $stmt->execute([$empId]);
        $latestRequest = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Determine status priority (highest priority first)
        $status = 'No Activity';
        $statusClass = 'status-available';
        
        if ($latestRequest && $latestRequest['status'] === 'pending_admin_approval') {
            $status = 'Awaiting Admin Approval';
            $statusClass = 'status-returning';
        } elseif ($assignedVehicle) {
            $status = 'Assigned: ' . $assignedVehicle['plate_number'];
            $statusClass = 'status-assigned';
        } elseif ($returningVehicle) {
            $status = 'Returning: ' . $returningVehicle['plate_number'];
            $statusClass = 'status-returning';
        } elseif ($latestRequest) {
            switch ($latestRequest['status']) {
                case 'pending_dispatch_assignment':
                    $status = 'Awaiting Dispatch';
                    $statusClass = 'status-returning';
                    break;
                case 'approved':
                    $status = 'Approved';
                    $statusClass = 'status-available';
                    break;
                case 'rejected_new_request':
                    $status = 'Rejected (New Request Needed)';
                    $statusClass = 'status-assigned';
                    break;
                case 'rejected_reassign_dispatch':
                    $status = 'Rejected (Reassign Dispatch)';
                    $statusClass = 'status-returning';
                    break;
                case 'rejected': // Fallback for old rejected status
                    $status = 'Last Request Rejected';
                    $statusClass = 'status-assigned';
                    break;
            }
        }
        
        $employeeStatusData[$empId] = [
            'status' => $status,
            'status_class' => $statusClass
        ];
    }

}


// Dispatch-specific data
if ($isDispatch) {
    $stmt = $pdo->query("SELECT * FROM requests WHERE status = 'pending_dispatch_assignment' OR status = 'rejected_reassign_dispatch' ORDER BY request_date ASC");
    $dispatchPendingRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Pre-fetch lookup data for all requests (employee, admin, dispatch) to avoid queries in HTML
$vehicleLookup = [];
$driverLookup = [];

$allRequestVehicleIds = [];
$allRequestDriverIds = [];

if ($isEmployee) {
    $allRequestVehicleIds = array_merge($allRequestVehicleIds, array_column($myRequests, 'assigned_vehicle_id'));
    $allRequestDriverIds = array_merge($allRequestDriverIds, array_column($myRequests, 'assigned_driver_id'));
} 
if ($isAdmin) {
    $allRequestVehicleIds = array_merge($allRequestVehicleIds, array_column($pendingAdminApprovalRequests, 'assigned_vehicle_id'));
    $allRequestDriverIds = array_merge($allRequestDriverIds, array_column($pendingAdminApprovalRequests, 'assigned_driver_id'));
    $allRequestVehicleIds = array_merge($allRequestVehicleIds, array_column($dispatchForwardedRequests, 'assigned_vehicle_id'));
    $allRequestDriverIds = array_merge($allRequestDriverIds, array_column($dispatchForwardedRequests, 'assigned_driver_id'));
}
if ($isDispatch) {
    $allRequestVehicleIds = array_merge($allRequestVehicleIds, array_column($dispatchPendingRequests, 'assigned_vehicle_id'));
    $allRequestDriverIds = array_merge($allRequestDriverIds, array_column($dispatchPendingRequests, 'assigned_driver_id'));
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
    $stmt = $pdo->prepare("SELECT id, name FROM drivers WHERE id IN ($placeholders)");
    $stmt->execute(array_values($driverIds));
    while ($row = $stmt->fetch()) {
        $driverLookup[$row['id']] = $row['name'];
    }
}

// Sort vehicles based on user role
if ($isAdmin) {
    $vehicles = sortVehiclesByStatus($vehicles, ['assigned', 'returning','maintenance', 'available']);
} elseif ($isEmployee) {
    $myVehicles = [];
    $otherVehicles = [];
    
    foreach ($vehicles as $vehicle) {
        if (($vehicle['assigned_to'] === $username) || 
            ($vehicle['returned_by'] === $username && $vehicle['status'] === 'returning')) {
            $myVehicles[] = $vehicle;
        } else {
            $otherVehicles[] = $vehicle;
        }
    }
    
    // Sort my vehicles: returning first, then assigned
    usort($myVehicles, function($a, $b) {
        if ($a['status'] === 'returning' && $b['status'] !== 'returning') {
            return -1;
        }
        if ($b['status'] === 'returning' && $a['status'] !== 'returning') {
            return 1;
        }
        return strcasecmp($a['plate_number'], $b['plate_number']);
    });
    
    // Sort other vehicles by status priority
    $otherVehicles = sortVehiclesByStatus($otherVehicles, ['available', 'returning', 'assigned','maintenance']);
    
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
$assignedVehiclesCount = 0;

foreach ($vehicles as $vehicle) {
    if ($vehicle['status'] === 'available') {
        $availableVehiclesCount++;
    } elseif ($vehicle['status'] === 'assigned') {
        $assignedVehiclesCount++;
    }
}

// Calculate pending counts based on user role
$pendingRequestsCount = 0;
$pendingReturnsCount = 0;

if ($isAdmin) {
    $pendingRequestsCount = count($pendingAdminApprovalRequests); // Only count requests pending admin approval
    $pendingReturnsCount = count($pendingReturns);
} elseif ($isEmployee) {
    $pendingRequestsCount = count(array_filter($myRequests, function($req) {
        return in_array($req['status'], ['pending_dispatch_assignment', 'pending_admin_approval', 'rejected_reassign_dispatch']);
    }));
    $pendingReturnsCount = count(array_filter($vehicles, function($vehicle) use ($username) {
        return $vehicle['status'] === 'returning' && $vehicle['returned_by'] === $username;
    }));
} elseif ($isDispatch) {
    $pendingRequestsCount = count($dispatchPendingRequests);
    $pendingReturnsCount = 0; 
}

// Calculate request restrictions for employees
$cannotRequest = false;
if ($isEmployee) {
    $cannotRequest = !canEmployeeRequest($employeeRequestStatus, $isAssignedVehicle, $isReturningVehicle);
}

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
            <?php if (isset($_SESSION['success'])): ?>
            <div class="modern-alert alert-success alert-dismissible fade show text-success" role="alert">
                <i class="fas fa-check-circle alert-icon"></i>
                <?= htmlspecialchars($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
            <div class="modern-alert alert-danger alert-dismissible fade show text-danger" role="alert">
            <i class="fas fa-exclamation-triangle alert-icon"></i>
            <div class="alert-content">
                <strong>Error:</strong> <?= htmlspecialchars($_SESSION['error']); ?>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
            <?php endif; ?>


            <!-- Quick Stats Dashboard - Show for logged in admin/dispatch, or basic stats for guests -->
            <?php if ($isAdmin || $isDispatch || !$isLoggedIn): ?>
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
                            <i class="fas fa-car"></i>
                        </div>
                    </div>
                    <div class="stat-number"><?= $assignedVehiclesCount ?></div>
                    <div class="stat-label">Assigned Vehicles</div>
                </div>

                <?php if ($isAdmin || $isDispatch): ?>
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
                            <i class="fas fa-undo"></i>
                        </div>
                    </div>
                    <div class="stat-number"><?= $pendingReturnsCount ?></div>
                    <div class="stat-label">Pending Returns</div>
                </div>
                <?php else: ?>
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon text-info">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                    <div class="stat-number"><?= count($vehicles) ?></div>
                    <div class="stat-label">Total Fleet</div>
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

            <!-- Navigation Tabs -->
            <div class="nav-container">
                <ul class="nav nav-tabs" id="dashboardTabs">
                    <li class="nav-item">
                        <a class="nav-link active" data-bs-toggle="tab" href="#vehicles">
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
                    <li class="nav-item">
                        <a class="nav-link" data-bs-toggle="tab" href="#adminReturns">
                            <i class="fas fa-truck-loading me-2"></i>Returns
                            <?php if ($pendingReturnsCount > 0): ?>
                            <span class="badge bg-danger ms-1"><?= $pendingReturnsCount ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if ($isDispatch): ?>
                    <li class="nav-item">
                        <a class="nav-link" data-bs-toggle="tab" href="#dispatchRequests">
                            <i class="fas fa-clipboard-check me-2"></i>Dispatch
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- Tab Content -->
            <div class="tab-content">
                <!-- Vehicles Tab -->
                <div class="tab-pane fade show active" id="vehicles">
                    <?php if ($isEmployee): ?>
                    <?php if ($employeeRequestStatus === 'rejected_reassign_dispatch'): ?>
                    <div class="modern-alert alert-warning">
                        <i class="fas fa-sync-alt alert-icon"></i>
                        <div class="alert alert-permanent alert-warning">
                            <strong>Request Under Reassignment:</strong> Your vehicle request was sent back to dispatch for reassignment.  
                            You cannot submit a new request until this process is complete.
                        </div>
                    </div>
                    <?php elseif ($employeeRequestStatus === 'pending_admin_approval'): ?>
                    <div class="modern-alert alert-info">
                        <i class="fas fa-info-circle alert-icon"></i>
                        <div class="alert alert-permanent alert-info">
                            <strong>Request Status:</strong> Your vehicle request has been assigned a vehicle and driver, and is now pending admin approval.
                            You cannot submit another request at this time.
                        </div>
                    </div>
                    <?php elseif ($employeeRequestStatus === 'pending_dispatch_assignment'): ?>
                    <div class="modern-alert alert-info">
                        <i class="fas fa-info-circle alert-icon"></i>
                        <div class="alert alert-permanent alert-warning">
                            <strong>Request Status:</strong> Your vehicle request is currently awaiting dispatch assignment.
                            You cannot submit another request at this time.
                        </div>
                    </div>
                    <?php elseif ($employeeRequestStatus === 'approved_pending_dispatch'): ?>
                    <div class="modern-alert alert-info">
                        <i class="fas fa-info-circle alert-icon"></i>
                        <div class="alert alert-permanent alert-info">
                            <strong>Request Status:</strong> Your vehicle request has been approved and is awaiting
                            vehicle dispatch.
                        </div>
                    </div>
                    <?php elseif ($employeeRequestStatus === 'rejected'): ?>
                    <div class="modern-alert alert-danger">
                        <i class="fas fa-times-circle alert-icon"></i>
                        <div class="alert alert-permanent alert-danger">
                            <strong>Request Status:</strong> Your last vehicle request was denied. Please submit a new
                            request.
                        </div>
                    </div>
                    <?php elseif ($employeeRequestStatus === 'approved' && $isAssignedVehicle): ?>
                    <div class="modern-alert alert-success">
                        <i class="fas fa-check-circle alert-icon"></i>
                        <div class="alert alert-permanent alert-secondary">
                            <strong>Request Status:</strong> Your vehicle request has been approved and a vehicle has
                            been assigned!
                        </div>
                    </div>
                    <?php elseif ($isReturningVehicle): ?>
                    <div class="modern-alert alert-warning">
                        <i class="fas fa-clock alert-icon"></i>
                        <div class="alert alert-permanent alert-warning">
                            <strong>Return Status:</strong> You have a vehicle return in progress. You cannot submit a new request until the return is completed.
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>

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
                            <?php elseif ($isEmployee): ?>
                            <a href="create_request.php"
                                class="btn btn-success-modern btn-modern <?= $cannotRequest ? 'disabled' : '' ?>">
                                <i class="fas fa-plus me-2"></i>Request Vehicle
                            </a>
                            <?php elseif (!$isLoggedIn): ?>
                            <button class="btn btn-success-modern btn-modern" onclick="requireLogin()">
                                <i class="fas fa-plus me-2"></i>Request Vehicle
                            </button>
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
                                    <span class="detail-label">Status</span>
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
                if (in_array($req['status'], ['pending_dispatch_assignment', 'pending_admin_approval', 'rejected_reassign_dispatch']) 
                    && $req['assigned_vehicle_id'] == $vehicle['id']) {
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
                <!-- Show Return Vehicle button if request is approved -->
                <a href="return_vehicle.php?id=<?= $vehicle['id'] ?>"
                    class="btn btn-warning-modern btn-modern">
                    <i class="fas fa-undo me-1"></i>Return Vehicle
                </a>
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
                                class="btn btn-primary-modern btn-modern <?= $cannotRequest ? 'disabled' : '' ?>">
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
                                        <th>Status</th>
                                        <th>Vehicle</th>
                                        <th>Driver</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($myRequests)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center">No vehicle requests found.</td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($myRequests as $request): ?>
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
                                            <?php 
                                            if ($request['assigned_vehicle_id'] && $request['status'] === 'pending_admin_approval') {
                                                echo 'Pending Admin Approval';
                                            } else {
                                                echo $request['assigned_vehicle_id'] ? htmlspecialchars($vehicleLookup[$request['assigned_vehicle_id']] ?? '----') : '----';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php 
                                            if ($request['assigned_driver_id'] && $request['status'] === 'pending_admin_approval') {
                                                echo 'Pending Admin Approval';
                                            } else {
                                                echo $request['assigned_driver_id'] ? htmlspecialchars($driverLookup[$request['assigned_driver_id']] ?? '----') : '----';
                                            }
                                            ?>
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
                                        <th>Status</th>
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
                                            <span class="status-badge <?= $employeeStatusData[$emp['id']]['status_class'] ?>">
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
                                        <th>Position</th>
                                        <th>Phone</th>
                                        <th>Status</th>
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
                                        <td><?= htmlspecialchars($driver['position']) ?></td>
                                        <td><?= htmlspecialchars($driver['phone'] ?? '') ?></td>
                                        <td>
                                            <?php if ($driver['is_assigned']): ?>
                                                <?php 
                                                // Find the request associated with this driver assignment
                                                $assignedRequestStatus = 'unknown';
                                                foreach ($pendingAdminApprovalRequests as $req) {
                                                    if ($req['assigned_driver_id'] == $driver['id']) {
                                                        $assignedRequestStatus = $req['status'];
                                                        break;
                                                    }
                                                }
                                                if ($assignedRequestStatus === 'pending_admin_approval'):
                                                ?>
                                                    <span class="status-badge status-returning">Pending Admin Approval</span>
                                                <?php else: ?>
                                                    <span class="status-badge status-assigned">Assigned to
                                                        <?= htmlspecialchars($driver['assigned_vehicle']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                            <span class="status-badge status-available">Available</span>
                                            <?php endif; ?>
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
                                        <th>Email</th>
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
                                        <td colspan="11" class="text-center">No vehicle requests currently awaiting
                                            your approval.</td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($pendingAdminApprovalRequests as $request): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($request['requestor_name']) ?></td>
                                        <td><?= htmlspecialchars($request['requestor_email']) ?></td>
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
                                        <td><?= $request['assigned_vehicle_id'] ? htmlspecialchars($vehicleLookup[$request['assigned_vehicle_id']] ?? '----') : '----' ?></td>
                                        <td><?= $request['assigned_driver_id'] ? htmlspecialchars($driverLookup[$request['assigned_driver_id']] ?? '----') : '----' ?></td>
                                        <td><?= htmlspecialchars($request['request_date']) ?></td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-primary-modern" data-bs-toggle="modal" data-bs-target="#adminActionModal" 
                                                    data-request-id="<?= $request['id'] ?>"
                                                    data-requestor-name="<?= htmlspecialchars($request['requestor_name']) ?>">
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

                    <!-- Admin Action Modal -->
                    <div class="modal fade" id="adminActionModal" tabindex="-1" aria-labelledby="adminActionModalLabel" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <div class="modal-header bg-primary text-light">
                                    <h5 class="modal-title" id="adminActionModalLabel"><i class="fas fa-clipboard-check me-2"></i>Review Vehicle Request</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <form id="adminActionForm" action="process_request.php" method="POST">
                                    <input type="hidden" name="id" id="modalRequestId">
                                    <?= csrf_field() ?>
                                    <div class="modal-body text-dark">
                                        <p>Reviewing request by: <strong id="modalRequestorName"></strong></p>
                                        
                                        <div class="mb-3">
                                            <label for="actionSelect" class="form-label">Select Action:</label>
                                            <select class="form-select" id="actionSelect" name="action" required>
                                                <option value="">-- Choose an Action --</option>
                                                <option value="approve">Approve Request</option>
                                                <option value="reject">Reject Request</option>
                                            </select>
                                        </div>
                                        
                                        <div id="rejectionReasonGroup" class="mb-3" style="display: none;">
                                            <label for="rejectionReason" class="form-label">Reason for Rejection:</label>
                                            <select class="form-select" id="rejectionReason" name="rejection_reason">
                                                <option value="">-- Select a Reason --</option>
                                                <option value="reassign_vehicle">Reassign Vehicle</option>
                                                <option value="reassign_driver">Reassign Driver</option>
                                                <option value="new_request">Reject Completely</option>
                                            </select>
                                        </div>
                                        
                                        <div id="modalAlert" class="alert" style="display: none;"></div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-primary" id="modalSubmitButton">Submit</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Admin Returns Tab -->
                <div class="tab-pane fade" id="adminReturns">
                    <div class="table-container">
                        <div class="section-header">
                            <h2 class="section-title text-danger">
                                <i class="fas fa-truck-loading"></i>
                                Pending Vehicle Returns
                            </h2>
                        </div>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Vehicle Plate</th>
                                        <th>Returned By</th>
                                        <th>Return Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($pendingReturns)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center">No pending vehicle returns.</td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($pendingReturns as $return): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($return['plate_number']) ?></td>
                                        <td><?= htmlspecialchars($return['returned_by']) ?></td>
                                        <td><?= htmlspecialchars($return['return_date']) ?></td>
                                        <td>
                                            <a href="process_return.php?id=<?= $return['id'] ?>"
                                                class="btn btn-sm btn-primary-modern">
                                                <i class="fas fa-check-double"></i> Process Return
                                            </a>
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

                <?php if ($isDispatch): ?>
                <!-- Dispatch Requests Tab -->
                <div class="tab-pane fade" id="dispatchRequests">
                    <div class="table-container">
                        <div class="section-header">
                            <h2 class="section-title text-warning">
                                <i class="fas fa-clipboard-check"></i>
                                Requests Pending Dispatch Assignment
                            </h2>
                        </div>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Requestor Name</th>
                                        <th>Email</th>
                                        <th>Departure</th>
                                        <th>Return</th>
                                        <th>Destination</th>
                                        <th>Purpose</th>
                                        <th>Passengers</th>
                                        <th>Requested On</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($dispatchPendingRequests)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center">No requests currently pending vehicle
                                            dispatch.</td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($dispatchPendingRequests as $request): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($request['requestor_name']) ?></td>
                                        <td><?= htmlspecialchars($request['requestor_email']) ?></td>
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
                                        <td><?= htmlspecialchars($request['request_date']) ?></td>
                                        <td>
                                            <a href="assign_dispatch_vehicle.php?request_id=<?= $request['id'] ?>"
                                                class="btn btn-sm btn-primary-modern">
                                                <i class="fas fa-car-side"></i> Assign Vehicle
                                            </a>
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

            </div>
        </div>
    </div>

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

    <form id="cancelRequestForm" action="employee/cancel_my_request.php" method="POST" style="display:none;">
    <input type="hidden" name="request_id" id="cancelRequestId">
    <?= csrf_field() ?>
    </form>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function showCancelModal(requestId) {
        console.log('Opening cancel modal for request:', requestId);
        document.getElementById('cancelRequestId').value = requestId;
        document.getElementById('cancel_reason').value = '';
        document.getElementById('charCount').textContent = '0 / 150 characters';
        var cancelModal = new bootstrap.Modal(document.getElementById('cancelRequestModal'));
        cancelModal.show();
    }

        // Auto-dismiss alerts (but skip permanent ones)
        document.addEventListener('DOMContentLoaded', function () {
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
                // Default to 'vehicles' tab if no hash
                const defaultTab = document.querySelector('#dashboardTabs a[href="#vehicles"]');
                if (defaultTab) {
                    new bootstrap.Tab(defaultTab).show();
                }
            }

            // Update URL hash when tab changes
            const tabLinks = document.querySelectorAll('#dashboardTabs .nav-link');
            tabLinks.forEach(link => {
                link.addEventListener('shown.bs.tab', function (event) {
                    const newTabId = event.target.getAttribute('href').substring(1); // Remove '#'
                    const newUrl = new URL(window.location.href);
                    newUrl.searchParams.set('tab', newTabId);
                    window.history.pushState({ path: newUrl.href }, '', newUrl.href);
                });
            });

             var cancelReasonInput = document.getElementById('cancel_reason');
            var charCount = document.getElementById('charCount');
        
            if (cancelReasonInput) {
                cancelReasonInput.addEventListener('input', function() {
                    var length = this.value.length;
                    charCount.textContent = length + ' / 150 characters';
                });
            }
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
            button.addEventListener('click', function (e) {
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

        document.addEventListener("DOMContentLoaded", function () {
            // Handle clicks on tabs
            document.querySelectorAll('a[data-bs-toggle="tab"]').forEach(tabEl => {
                tabEl.addEventListener("shown.bs.tab", function (event) {
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
                        targetEl.scrollIntoView({ behavior: "smooth", block: "start" });
                    }, 500);
                }
            }

            // Admin Action Modal Logic
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

    adminActionForm.addEventListener('submit', function (e) {
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

    // This part runs every time the modal opens
    adminActionModal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const requestId = button.getAttribute('data-request-id');
        const requestorName = button.getAttribute('data-requestor-name');

        const modalRequestId = adminActionModal.querySelector('#modalRequestId');
        const modalRequestorName = adminActionModal.querySelector('#modalRequestorName');

        modalRequestId.value = requestId;
        modalRequestorName.textContent = requestorName;
        actionSelect.value = ''; // Reset select
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
    </script>
</body>

</html>