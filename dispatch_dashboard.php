<?php
require_once __DIR__ . '/includes/session.php';
include 'error.php';
require 'db.php';


// Check if user is logged in and is a dispatch user
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'dispatch') {
    $_SESSION['error'] = "Access denied. You must be logged in as a dispatch user.";
    header("Location: login.php");
    exit();
}

$username = $_SESSION['user']['name'] ?? 'Dispatch User';

// Fetch requests that are pending dispatch assignment
try {
    $stmt = $pdo->prepare("SELECT * FROM requests WHERE status = 'pending_dispatch_assignment' OR status = 'rejected_reassign_dispatch' ORDER BY request_date ASC");
    $stmt->execute();
    $pendingDispatchRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Dispatch Dashboard PDO Error: " . $e->getMessage(), 0);
    $_SESSION['error'] = "An unexpected error occurred while fetching requests.";
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

    $stmt = $pdo->query("SELECT COUNT(*) FROM drivers WHERE status = 'available'");
    $availableDriversCount = $stmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Stats Error: " . $e->getMessage(), 0);
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
                <i class="fas fa-times-circle alert-icon"></i>
                <?= htmlspecialchars($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
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
            </div>

            <!-- Main Content -->
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
                                    <span class="destination-badge">
                                        <i class="fas fa-location-dot me-1"></i>
                                        <?= htmlspecialchars($request['destination']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="purpose-text">
                                        <?= htmlspecialchars($request['purpose']) ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="date-display">
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
                                           class="btn btn-primary-modern btn-modern">
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
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                    <h3>All Caught Up!</h3>
                    <p>No requests currently pending vehicle dispatch assignment.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
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
                button.addEventListener('click', function (e) {
                    if (!this.classList.contains('disabled') && !this.classList.contains('info-card')) {
                        const originalText = this.innerHTML;
                        this.dataset.originalText = originalText;
                        this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Loading...';
                        this.classList.add('loading');
                        
                        // Re-enable button after navigation or timeout
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
            // Only refresh if no modals are open and user isn't actively interacting
            if (!document.querySelector('.modal.show') && !document.querySelector('.btn-modern.loading')) {
                window.location.reload();
            }
        }, 30000);
    </script>
</body>
</html>