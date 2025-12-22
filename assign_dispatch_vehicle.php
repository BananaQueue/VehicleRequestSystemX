<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/passenger_validation.php';
require_once __DIR__ . '/includes/schedule_utils.php';
require_once __DIR__ . '/includes/audit_log.php';
require 'db.php';

// Check if user is logged in and is a dispatch user
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'dispatch') {
    $_SESSION['error'] = "Access denied. You must be logged in as a dispatch user to assign vehicles.";
    header("Location: login.php");
    exit();
}

$request_id = filter_var($_GET['request_id'] ?? null, FILTER_VALIDATE_INT);
$errors = [];
$success = '';

if ($request_id === false) {
    $_SESSION['error'] = "Invalid request ID.";
    header("Location: dispatch_dashboard.php");
    exit();
}

sync_active_assignments($pdo);

$request = null;
$requestStartDate = null;
$requestEndDate = null;
try {
    // Fetch request details
    $stmt = $pdo->prepare("SELECT * FROM requests WHERE id = :id AND (status = 'pending_dispatch_assignment' OR status = 'rejected_reassign_dispatch')");
    $stmt->execute(['id' => $request_id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        $_SESSION['error'] = "Request not found or not pending dispatch assignment.";
        header("Location: dispatch_dashboard.php");
        exit();
    }

    [$requestStartDate, $requestEndDate] = get_request_date_range($request);

    if (!$requestStartDate) {
        $_SESSION['error'] = "Request is missing departure date information. Please update the request before assigning a vehicle.";
        header("Location: dispatch_dashboard.php");
        exit();
    }

    // FIXED: Fetch available vehicles - Only check against APPROVED requests, not pending ones
    $stmt = $pdo->prepare("
        SELECT *
        FROM vehicles v
        WHERE v.status = 'available'
          AND NOT EXISTS (
              SELECT 1 FROM requests r
              WHERE r.assigned_vehicle_id = v.id
                AND r.status = 'approved'
                AND :start_date <= COALESCE(r.return_date, r.departure_date, DATE(r.request_date))
                AND :end_date >= COALESCE(r.departure_date, DATE(r.request_date))
                AND r.id != :current_request_id
          )
        ORDER BY v.plate_number ASC
    ");
    $stmt->execute([
        ':start_date' => $requestStartDate,
        ':end_date' => $requestEndDate,
        ':current_request_id' => $request_id,
    ]);
    $availableVehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // FIXED: Fetch available drivers - Only check against APPROVED requests, not pending ones
    $stmt = $pdo->prepare("
        SELECT *
        FROM drivers d
        WHERE d.status = 'available'
          AND NOT EXISTS (
              SELECT 1 FROM requests r
              WHERE r.assigned_driver_id = d.id
                AND r.status = 'approved'
                AND :start_date <= COALESCE(r.return_date, r.departure_date, DATE(r.request_date))
                AND :end_date >= COALESCE(r.departure_date, DATE(r.request_date))
                AND r.id != :current_request_id
          )
        ORDER BY d.name ASC
    ");
    $stmt->execute([
        ':start_date' => $requestStartDate,
        ':end_date' => $requestEndDate,
        ':current_request_id' => $request_id,
    ]);
    $availableDrivers = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Assign Vehicle Load Error: " . $e->getMessage(), 0);
    $_SESSION['error'] = "An unexpected error occurred.";
    header("Location: dispatch_dashboard.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_vehicle'])) {
    validate_csrf_token_post(); // Validate CSRF token
    $selected_vehicle_id = filter_var($_POST['vehicle_id'] ?? null, FILTER_VALIDATE_INT);
    $selected_driver_id = filter_var($_POST['driver_id'] ?? null, FILTER_VALIDATE_INT);

    if ($selected_vehicle_id === false) {
        $errors[] = "Please select a valid vehicle.";
    }
    if ($selected_driver_id === false) {
        $errors[] = "Please select a valid driver.";
    }

    if (!$requestStartDate || !$requestEndDate) {
        $errors[] = "Request dates are not properly set. Please update the request.";
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Fetch driver name and verify availability
            $stmt = $pdo->prepare("SELECT name FROM drivers WHERE id = :id");
            $stmt->execute([':id' => $selected_driver_id]);
            $driver_name = $stmt->fetchColumn();

            if (!$driver_name) {
                throw new Exception("Selected driver not found.");
            }

            // Confirm vehicle availability for requested dates (only checks approved requests)
            if (has_vehicle_conflict($pdo, $selected_vehicle_id, $requestStartDate, $requestEndDate, $request_id)) {
                throw new Exception("Selected vehicle already has a reservation within the requested dates.");
            }

            if (has_driver_conflict($pdo, $selected_driver_id, $requestStartDate, $requestEndDate, $request_id)) {
                throw new Exception("Selected driver already has a reservation within the requested dates.");
            }

            // Update request status, assigned_vehicle_id, and assigned_driver_id
            $updateRequestStmt = $pdo->prepare("UPDATE requests SET status = 'pending_admin_approval', assigned_vehicle_id = :vehicle_id, assigned_driver_id = :driver_id WHERE id = :request_id AND (status = 'pending_dispatch_assignment' OR status = 'rejected_reassign_dispatch')");
            $request_updated = $updateRequestStmt->execute([
                ':vehicle_id' => $selected_vehicle_id,
                ':driver_id' => $selected_driver_id,
                ':request_id' => $request_id
            ]);

            if (!$request_updated || $updateRequestStmt->rowCount() === 0) {
                throw new Exception("Failed to update request status.");
            }

            log_request_audit($pdo, $request_id, 'dispatch_assigned', [
                'notes' => sprintf(
                    "Vehicle #%s assigned with driver %s for %s to %s",
                    $selected_vehicle_id,
                    $driver_name,
                    $requestStartDate,
                    $requestEndDate
                )
            ]);

            $pdo->commit();

            sync_active_assignments($pdo);

            $_SESSION['success'] = "Vehicle assigned successfully to " . htmlspecialchars($request['requestor_name']) . " with driver " . htmlspecialchars($driver_name) . "." . " Forwarding to admin for approval.";
            header("Location: dispatch_dashboard.php");
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Vehicle Assignment Error: " . $e->getMessage(), 0);
            $errors[] = "An error occurred during vehicle assignment: " . $e->getMessage();
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Assign Vehicle</title>
    <link rel="stylesheet" href="styles.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="dashboard-container py-4 px-3">
        <div class="header mb-4">
            <h1><i class="fas fa-car-side me-2"></i>Assign Vehicle to Request</h1>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <?php foreach ($errors as $error): ?>
                    <?= htmlspecialchars($error) ?><br>
                <?php endforeach; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="card p-4 mb-4 shadow-sm">
            <h5 class="card-title mb-3"><i class="fas fa-info-circle me-2"></i>Request Details</h5>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="detail-group mb-3">
                        <label class="text-muted small"><i class="fas fa-user me-1"></i>Requestor</label>
                        <p class="fw-bold mb-0"><?= htmlspecialchars($request['requestor_name']) ?></p>
                    </div>
                    
                    <div class="detail-group mb-3">
                        <label class="text-muted small"><i class="fas fa-envelope me-1"></i>Email</label>
                        <p class="mb-0"><?= htmlspecialchars($request['requestor_email']) ?></p>
                    </div>
                    
                    <div class="detail-group mb-3">
                        <label class="text-muted small"><i class="fas fa-map-marker-alt me-1"></i>Destination</label>
                        <p class="mb-0"><?= htmlspecialchars($request['destination']) ?></p>
                    </div>
                    
                    <div class="detail-group mb-3">
                        <label class="text-muted small"><i class="fas fa-clipboard me-1"></i>Purpose</label>
                        <p class="mb-0"><?= htmlspecialchars($request['purpose']) ?></p>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="detail-group mb-3">
                        <label class="text-muted small"><i class="fas fa-calendar-alt me-1"></i>Requested On</label>
                        <p class="mb-0"><?= htmlspecialchars(date('F j, Y', strtotime($request['request_date']))) ?></p>
                    </div>
                    
                    <?php if (!empty($request['departure_date'])): ?>
                    <div class="detail-group mb-3">
                        <label class="text-muted small"><i class="fas fa-plane-departure me-1"></i>Departure Date</label>
                        <p class="mb-0"><?= htmlspecialchars(date('F j, Y', strtotime($request['departure_date']))) ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($request['return_date'])): ?>
                    <div class="detail-group mb-3">
                        <label class="text-muted small"><i class="fas fa-plane-arrival me-1"></i>Return Date</label>
                        <p class="mb-0"><?= htmlspecialchars(date('F j, Y', strtotime($request['return_date']))) ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($request['travel_date'])): ?>
                    <div class="detail-group mb-3">
                        <label class="text-muted small"><i class="fas fa-calendar-check me-1"></i>Travel Date</label>
                        <p class="mb-0"><?= htmlspecialchars(date('F j, Y', strtotime($request['travel_date']))) ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($request['passenger_count']) && $request['passenger_count'] > 0): ?>
                    <div class="detail-group mb-3">
                        <label class="text-muted small"><i class="fas fa-users me-1"></i>Passenger Count</label>
                        <p class="mb-0"><?= htmlspecialchars($request['passenger_count']) ?> passenger(s)</p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($request['passenger_names'])): ?>
                    <div class="detail-group mb-3">
                        <label class="text-muted small"><i class="fas fa-user-friends me-1"></i>Passengers</label>
                        <p class="mb-0">
                            <?php 
                            $passengers = json_decode($request['passenger_names'], true);
                            if (is_array($passengers)) {
                                echo htmlspecialchars(implode(', ', $passengers));
                            } else {
                                echo htmlspecialchars($request['passenger_names']);
                            }
                            ?>
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if ($request['status'] === 'rejected_reassign_dispatch' && !empty($request['rejection_reason'])): ?>
            <div class="alert alert-warning mt-3" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <strong>Rejection Reason:</strong> <?= htmlspecialchars($request['rejection_reason']) ?>
            </div>
            <?php endif; ?>
        </div>

        <form action="assign_dispatch_vehicle.php?request_id=<?= $request['id'] ?>" method="POST" class="bg-white p-4 rounded shadow-sm">
            <?= csrf_field() ?>
            <h5 class="mb-4"><i class="fas fa-car me-2"></i>Assignment Details</h5>
            
            <div class="mb-3">
                <label for="vehicle_id" class="form-label">
                    <i class="fas fa-car-side me-1"></i>Select Available Vehicle
                </label>
                <select class="form-select" id="vehicle_id" name="vehicle_id" required>
                    <option value="">-- Select a Vehicle --</option>
                    <?php foreach ($availableVehicles as $vehicle): ?>
                        <option value="<?= $vehicle['id'] ?>">
                            <?= htmlspecialchars($vehicle['plate_number']) ?> 
                            (<?= htmlspecialchars($vehicle['make']) ?> <?= htmlspecialchars($vehicle['model']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($availableVehicles)): ?>
                    <div class="form-text text-danger">
                        <i class="fas fa-info-circle me-1"></i>No vehicles currently available for the requested dates
                    </div>
                <?php else: ?>
                    <div class="form-text text-success">
                        <i class="fas fa-check-circle me-1"></i><?= count($availableVehicles) ?> vehicle(s) available for <?= date('M j', strtotime($requestStartDate)) ?> - <?= date('M j, Y', strtotime($requestEndDate)) ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="mb-4">
                <label for="driver_id" class="form-label">
                    <i class="fas fa-id-card me-1"></i>Select Available Driver
                </label>
                <select class="form-select" id="driver_id" name="driver_id" required>
                    <option value="">-- Select a Driver --</option>
                    <?php foreach ($availableDrivers as $driver): ?>
                        <option value="<?= $driver['id'] ?>">
                            <?= htmlspecialchars($driver['name']) ?>
                            <?php if (!empty($driver['position'])): ?>
                                - <?= htmlspecialchars($driver['position']) ?>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($availableDrivers)): ?>
                    <div class="form-text text-danger">
                        <i class="fas fa-info-circle me-1"></i>No drivers currently available for the requested dates
                    </div>
                <?php else: ?>
                    <div class="form-text text-success">
                        <i class="fas fa-check-circle me-1"></i><?= count($availableDrivers) ?> driver(s) available for <?= date('M j', strtotime($requestStartDate)) ?> - <?= date('M j, Y', strtotime($requestEndDate)) ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if (empty($availableVehicles) || empty($availableDrivers)): ?>
                <button type="submit" class="btn btn-primary w-100" disabled>
                    <i class="fas fa-ban me-2"></i>Assign Vehicle (Unavailable)
                </button>
                <div class="alert alert-info mt-3">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Note:</strong> All vehicles/drivers are currently reserved for approved trips during these dates. 
                    You can still assign a vehicle, and conflicts will be checked when the admin reviews the request.
                </div>
            <?php else: ?>
                <button type="submit" name="assign_vehicle" class="btn btn-primary w-100">
                    <i class="fas fa-check-circle me-2"></i>Assign Vehicle
                </button>
            <?php endif; ?>
        </form>

        <div class="text-center mt-3">
            <a href="dispatch_dashboard.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Dispatch Dashboard
            </a>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script>
        // Auto-dismiss alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert-dismissible');
            alerts.forEach(alert => {
                setTimeout(() => {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, 5000);
            });
        });
    </script>
</body>
</html>