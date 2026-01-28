<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/schedule_utils.php';
require_once __DIR__ . '/includes/lookup_utils.php'; 
include 'error.php';
require 'db.php';

// Check if user is logged in and is a dispatch user
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'dispatch') {
    $_SESSION['error_message'] = "Access denied. You must be logged in as a dispatch user.";
    header("Location: login.php");
    exit();
}

$username = $_SESSION['user']['name'] ?? 'Dispatch User';
$today = date('Y-m-d'); // Define today early for use in queries

// Fetch requests that are pending dispatch assignment
try {
    $stmt = $pdo->prepare("SELECT * FROM requests WHERE status = 'pending_dispatch_assignment' OR status = 'rejected_reassign_dispatch' ORDER BY request_date ASC");
    $stmt->execute();
    $pendingDispatchRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Dispatch Dashboard PDO Error: " . $e->getMessage(), 0);
    $_SESSION['error_message'] = "An unexpected error occurred while fetching requests.";
    $pendingDispatchRequests = [];
}

// Calculate stats
$pendingDispatchCount = count($pendingDispatchRequests);

// Get additional stats for dashboard
$availableVehiclesCount = 0;
$assignedVehiclesCount = 0;
$availableDriversCount = 0;
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM vehicles WHERE status = 'available'");
    $availableVehiclesCount = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM vehicles WHERE status = 'assigned'");
    $assignedVehiclesCount = $stmt->fetchColumn();

    // Drivers are determined by vehicle assignment, not status
    // Count drivers not currently assigned to any vehicle
    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT u.id) 
        FROM users u 
        WHERE u.role = 'driver' 
        AND NOT EXISTS (
            SELECT 1 FROM vehicles v WHERE v.driver_id = u.id AND v.status = 'assigned'
        )
    ");
    $availableDriversCount = $stmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Stats Error: " . $e->getMessage(), 0);
}

// Fetch approved trips for dispatch cancellation management
$approvedTrips = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            r.id,
            r.requestor_name,
            r.requestor_email,
            r.destination,
            r.purpose,
            r.departure_date,
            r.return_date,
            r.passenger_names,
            v.plate_number,
            v.make,
            v.model,
            d.name AS driver_name
        FROM requests r
        LEFT JOIN vehicles v ON v.id = r.assigned_vehicle_id
        LEFT JOIN users d ON d.id = r.assigned_driver_id AND d.role = 'driver'
        WHERE r.status = 'approved'
          AND COALESCE(r.return_date, r.departure_date) >= :today
        ORDER BY r.departure_date ASC
    ");
    $stmt->execute([':today' => $today]);
    $approvedTrips = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Dispatch Approved Trips Error: " . $e->getMessage(), 0);
}

// Categorize trips as upcoming or ongoing
$upcomingTrips = [];
$ongoingTrips = [];

foreach ($approvedTrips as $trip) {
    $departureDate = $trip['departure_date'] ?? $today;
    $returnDate = $trip['return_date'] ?? $departureDate;

    if ($departureDate <= $today && $returnDate >= $today) {
        $ongoingTrips[] = $trip;
    } else {
        $upcomingTrips[] = $trip;
    }
}

$activeTripsCount = count($approvedTrips);
$ongoingCount = count($ongoingTrips);
$upcomingCount = count($upcomingTrips);


$calendarEvents = [];
$calendarRequestDetails = [];
$auditLogsByRequest = [];
$calendarRequests = [];
$upcomingReservations = [];

try {
    $calendarStmt = $pdo->prepare("
        SELECT 
            r.id,
            r.requestor_name,
            r.requestor_email,
            r.destination,
            r.purpose,
            r.status,
            r.departure_date,
            r.return_date,
            r.passenger_names,
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
    if (!empty($calendarRequestIds)) {
        $placeholders = implode(',', array_fill(0, count($calendarRequestIds), '?'));
        $auditStmt = $pdo->prepare("
            SELECT request_id, action, actor_name, actor_role, notes, created_at
            FROM request_audit_logs
            WHERE request_id IN ($placeholders)
            ORDER BY created_at DESC
        ");
        $auditStmt->execute($calendarRequestIds);
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

        $passengerDisplay = format_passenger_names($calendarRequest['passenger_names']);

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
            'status' => get_status_display_text($calendarRequest['status']),
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
} catch (PDOException $e) {
    error_log("Dispatch Calendar Error: " . $e->getMessage(), 0);
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Dispatch Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
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
                    <span class="header-icon"><i class="fas fa-route"></i></span>
                    Dispatch Dashboard
                </h1>
                <div class="user-section">
                    <div class="welcome-text">
                        <i class="fas fa-user-circle me-2"></i>
                        Welcome back, <strong><?= htmlspecialchars($username) ?></strong> (Dispatch)
                    </div>
                    <div class="user-actions">
                        <a href="logout.php" class="btn btn-light btn-sm">
                            <i class="fas fa-sign-out-alt me-1"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <div class="container-fluid px-4">
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="modern-alert alert-success alert-dismissible fade show text-success" role="alert">
                    <i class="fas fa-check-circle alert-icon"></i>
                    <?= htmlspecialchars($_SESSION['success_message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="modern-alert alert-danger alert-dismissible fade show text-danger" role="alert">
                    <i class="fas fa-times-circle alert-icon"></i>
                    <?= htmlspecialchars($_SESSION['error_message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Quick Stats Dashboard -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon text-warning">
                            <i class="fas fa-clipboard-check"></i>
                        </div>
                    </div>
                    <div class="stat-number"><?= $pendingDispatchCount ?></div>
                    <div class="stat-label">Pending Dispatch</div>
                </div>

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

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon text-info">
                            <i class="fas fa-id-card"></i>
                        </div>
                    </div>
                    <div class="stat-number"><?= $availableDriversCount ?></div>
                    <div class="stat-label">Available Drivers</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon text-primary">
                            <i class="fas fa-route"></i>
                        </div>
                    </div>
                    <div class="stat-number"><?= $activeTripsCount ?></div>
                    <div class="stat-label">Active Trips</div>
                </div>

            </div>

            <div class="row g-4 dashboard-grid align-items-start">
                <div class="col-lg-12">
                    <div class="nav-container">
                        <ul class="nav nav-tabs" id="dispatchTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="calendar-tab" data-bs-toggle="tab" data-bs-target="#calendar-pane" type="button" role="tab" aria-controls="calendar-pane" aria-selected="true">
                                    <i class="fas fa-calendar-alt me-2"></i>Vehicle Schedule
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending-pane" type="button" role="tab" aria-controls="pending-pane" aria-selected="false">
                                    <i class="fas fa-clipboard-check me-2"></i>Pending Assignments
                                    <?php if (!empty($pendingDispatchRequests)): ?>
                                        <span class="badge bg-warning text-dark ms-2" id="pendingBadge"><?= count($pendingDispatchRequests) ?></span>
                                    <?php endif; ?>
                                </button>
                            </li>
                        </ul>
                    </div>

                    <div class="tab-content" id="dispatchTabsContent">
                        <!-- Calendar Tab -->
                        <div class="tab-pane fade show active" id="calendar-pane" role="tabpanel" aria-labelledby="calendar-tab">
                            <div class="table-container">
                                <div class="section-header">
                                    <h2 class="section-title text-white">
                                        <i class="fas fa-calendar-alt"></i>
                                        Vehicle Schedule Calendar
                                    </h2>
                                </div>
                                <div id="dispatchCalendar"></div>
                                <div class="calendar-legend mt-3">
                                    <div class="legend-item"><span class="legend-dot legend-active"></span> In Use Today</div>
                                    <div class="legend-item"><span class="legend-dot legend-upcoming"></span> Scheduled (Future)</div>
                                    <div class="legend-item"><span class="legend-dot legend-complete"></span> Past</div>
                                </div>
                            </div>
                        </div>

                        <!-- Pending Assignments Tab -->
                        <div class="tab-pane fade" id="pending-pane" role="tabpanel" aria-labelledby="pending-tab">
                            <div class="table-container">
                                <div class="section-header">
                                    <h2 class="section-title text-warning">
                                        <i class="fas fa-clipboard-check"></i>
                                        Requests Pending Vehicle Assignment
                                    </h2>
                                    <?php if (!empty($pendingDispatchRequests)): ?>
                                        <div class="badge bg-warning text-dark">
                                            <?= count($pendingDispatchRequests) ?> Pending
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($pendingDispatchRequests)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th><i class="fas fa-user me-2"></i>Requestor Name</th>
                                                    <th><i class="fas fa-envelope me-2"></i>Email</th>
                                                    <th><i class="fas fa-map-marker-alt me-2"></i>Destination</th>
                                                    <th><i class="fas fa-clipboard me-2"></i>Purpose</th>
                                                    <th><i class="fas fa-calendar me-2"></i>Requested On</th>
                                                    <th><i class="fas fa-cogs me-2"></i>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($pendingDispatchRequests as $request): ?>
                                                    <tr>
                                                        <td>
                                                            <div class="d-flex align-items-center">
                                                                <?= htmlspecialchars($request['requestor_name']) ?>
                                                            </div>
                                                        </td>
                                                        <td><?= htmlspecialchars($request['requestor_email']) ?></td>
                                                        <td>
                                                            <span class="status-badge status-upcoming">
                                                                <i class="fas fa-location-dot me-1"></i>
                                                                <?= htmlspecialchars($request['destination']) ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <div class="detail-value" style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                                <?= htmlspecialchars($request['purpose']) ?>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div>
                                                                <i class="far fa-calendar-alt me-1"></i>
                                                                <?= date('M j, Y', strtotime($request['request_date'])) ?>
                                                                <small class="text-muted d-block">
                                                                    <?= date('g:i A', strtotime($request['request_date'])) ?>
                                                                </small>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div class="action-group">
                                                                <a href="assign_dispatch_vehicle.php?request_id=<?= $request['id'] ?>"
                                                                    class="btn-modern btn-primary-modern">
                                                                    <i class="fas fa-car-side me-1"></i>Assign Vehicle
                                                                </a>

                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-5">
                                        <div class="mb-3" style="font-size: 4rem; color: var(--border-color);">
                                            <i class="fas fa-clipboard-check"></i>
                                        </div>
                                        <h3 class="mb-2">All Caught Up!</h3>
                                        <p class="text-muted">No requests currently pending vehicle dispatch assignment.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade text-dark" id="dispatchCalendarModal" tabindex="-1" aria-labelledby="dispatchCalendarModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="dispatchCalendarModalLabel">
                        <i class="fas fa-car-side me-2"></i>Reservation Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <p class="text-muted small mb-1">Vehicle</p>
                            <div class="fw-bold" id="dispatchModalVehicle">--</div>
                        </div>
                        <div class="col-md-6">
                            <p class="text-muted small mb-1">Plate Number</p>
                            <div class="fw-bold" id="dispatchModalPlate">--</div>
                        </div>
                        <div class="col-md-6">
                            <p class="text-muted small mb-1">Requestor</p>
                            <div class="fw-bold" id="dispatchModalRequestor">--</div>
                        </div>
                        <div class="col-md-6">
                            <p class="text-muted small mb-1">Email</p>
                            <div id="dispatchModalEmail">--</div>
                        </div>
                        <div class="col-md-6">
                            <p class="text-muted small mb-1">Destination</p>
                            <div id="dispatchModalDestination">--</div>
                        </div>
                        <div class="col-md-6">
                            <p class="text-muted small mb-1">Driver</p>
                            <div id="dispatchModalDriver">--</div>
                        </div>
                        <div class="col-md-6">
                            <p class="text-muted small mb-1">Travel Window</p>
                            <div id="dispatchModalDates">--</div>
                        </div>
                        <div class="col-md-6">
                            <p class="text-muted small mb-1">Status</p>
                            <div id="dispatchModalStatus">--</div>
                        </div>
                        <div class="col-12">
                            <p class="text-muted small mb-1">Purpose</p>
                            <div id="dispatchModalPurpose">--</div>
                        </div>
                        <div class="col-12">
                            <p class="text-muted small mb-1">Passengers</p>
                            <div id="dispatchModalPassengers">--</div>
                        </div>
                    </div>
                    <div class="mt-4">
                        <h6 class="text-muted text-uppercase small mb-2">
                            <i class="fas fa-history me-2"></i>Audit Trail
                        </h6>
                        <div id="dispatchAuditTimeline" class="audit-timeline">
                            <p class="text-muted small mb-0">No audit activity recorded.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js"></script>
    <script src="js/calendar_utils.js"></script>
    <script src="js/common.js"></script>
    <script>
        const dispatchCalendarEvents = <?= json_encode(array_values($calendarEvents), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const dispatchCalendarDetails = <?= json_encode($calendarRequestDetails, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_FORCE_OBJECT); ?>;
        const pendingRequestsCount = <?= $pendingDispatchCount ?>;

        document.addEventListener('DOMContentLoaded', function() {
            // Add subtle animation to pending badge
            const pendingBadge = document.getElementById('pendingBadge');
            if (pendingBadge && pendingRequestsCount > 0) {
                setInterval(function() {
                    pendingBadge.style.transform = 'scale(1.1)';
                    setTimeout(function() {
                        pendingBadge.style.transform = 'scale(1)';
                    }, 200);
                }, 3000);
            }

            // Store active tab in localStorage
            const tabButtons = document.querySelectorAll('#dispatchTabs button[data-bs-toggle="tab"]');
            tabButtons.forEach(button => {
                button.addEventListener('shown.bs.tab', function(event) {
                    localStorage.setItem('dispatchActiveTab', event.target.id);
                });
            });

            // Restore last active tab
            const savedTab = localStorage.getItem('dispatchActiveTab');
            if (savedTab) {
                const tabButton = document.getElementById(savedTab);
                if (tabButton) {
                    const tab = new bootstrap.Tab(tabButton);
                    tab.show();
                }
            }

            const calendarElement = document.getElementById('dispatchCalendar');
            const dispatchModalEl = document.getElementById('dispatchCalendarModal');
            const dispatchModalInstance = dispatchModalEl ? new bootstrap.Modal(dispatchModalEl) : null;
            let calendar = null;

            if (calendarElement && window.FullCalendar) {
                calendar = new FullCalendar.Calendar(calendarElement, {
                    initialView: 'dayGridMonth',
                    height: 'auto',
                    headerToolbar: {
                        left: 'prev,next today',
                        center: 'title',
                        right: ''
                    },
                    events: dispatchCalendarEvents,
                    eventDisplay: 'block',
                    eventClick(info) {
                        const details = dispatchCalendarDetails[String(info.event.id)];
                        if (!details || !dispatchModalInstance) {
                            return;
                        }
                        populateDispatchModal(details);
                        dispatchModalInstance.show();
                    },
                    eventDidMount(info) {
                        const details = dispatchCalendarDetails[String(info.event.id)];
                        if (details) {
                            info.el.setAttribute('title', `${details.requestor} • ${details.destination || 'Destination TBD'}`);
                        }
                    }
                });

                // Re-render calendar when tab is shown
                const calendarTab = document.getElementById('calendar-tab');
                if (calendarTab) {
                    calendarTab.addEventListener('shown.bs.tab', function() {
                        if (calendar) {
                            setTimeout(() => calendar.render(), 100);
                        }
                    });
                }

                // Initial render
                calendar.render();
            }

            // Add keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Alt + 1 = Calendar tab
                if (e.altKey && e.key === '1') {
                    e.preventDefault();
                    const calendarTab = document.getElementById('calendar-tab');
                    if (calendarTab) {
                        const tab = new bootstrap.Tab(calendarTab);
                        tab.show();
                    }
                }
                // Alt + 2 = Pending tab
                if (e.altKey && e.key === '2') {
                    e.preventDefault();
                    const pendingTab = document.getElementById('pending-tab');
                    if (pendingTab) {
                        const tab = new bootstrap.Tab(pendingTab);
                        tab.show();
                    }
                }
            });

            // Auto-dismiss Bootstrap alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                new bootstrap.Alert(alert);
                setTimeout(function() {
                    const alertInstance = bootstrap.Alert.getInstance(alert);
                    if (alertInstance) {
                        alertInstance.close();
                    }
                }, 5000);
            });

            // Add loading states to buttons
            document.querySelectorAll('.btn-modern').forEach(button => {
                button.addEventListener('click', function(e) {
                    if (!this.classList.contains('disabled') && !this.classList.contains('info-card')) {
                        const originalText = this.innerHTML;
                        this.dataset.originalText = originalText;
                        this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Loading...';
                        this.classList.add('loading');

                        setTimeout(() => {
                            if (this.dataset.originalText) {
                                this.innerHTML = this.dataset.originalText;
                                this.classList.remove('loading');
                            }
                        }, 3000);
                    }
                });
            });

            // Animate stat cards on load
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                setTimeout(() => {
                    card.style.animation = 'fadeInUp 0.6s ease-out forwards';
                }, index * 100);
            });
        });

        // Refresh page every 30 seconds for real-time updates
        setInterval(function() {
            if (!document.querySelector('.modal.show') && !document.querySelector('.btn-modern.loading')) {
                window.location.reload();
            }
        }, 30000);

        function dispatchSetText(id, value) {
            const el = document.getElementById(id);
            if (el) {
                el.textContent = value || '----';
            }
        }

        function populateDispatchModal(details) {
            CalendarUtils.populateModal(details, 'dispatch');
        }
    </script>
</body>

</html>