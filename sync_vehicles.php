<?php
/**
 * Manual Sync Tool
 * Use this to force update vehicle statuses when they get stuck
 */

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/schedule_utils.php';
require 'db.php';

// Check if user is admin or dispatch
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['admin', 'dispatch'])) {
    die("Access denied. Admin or Dispatch access required.");
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sync_now'])) {
    try {
        sync_active_assignments($pdo);
        $message = "✅ Vehicle statuses synchronized successfully!";
        $messageType = "success";
    } catch (Exception $e) {
        $message = "❌ Error: " . $e->getMessage();
        $messageType = "error";
    }
}

// Get current vehicle statuses
$stmt = $pdo->query("SELECT * FROM vehicles ORDER BY plate_number ASC");
$vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get today's active trips
$today = date('Y-m-d');
$stmt = $pdo->prepare("
    SELECT r.*, v.plate_number, u.name as requestor_name
    FROM requests r
    LEFT JOIN vehicles v ON v.id = r.assigned_vehicle_id
    LEFT JOIN users u ON u.id = r.user_id
    WHERE r.status = 'approved'
      AND r.assigned_vehicle_id IS NOT NULL
      AND COALESCE(r.departure_date, DATE(r.request_date)) <= :today
      AND COALESCE(r.return_date, r.departure_date, DATE(r.request_date)) >= :today
");
$stmt->execute([':today' => $today]);
$activeTrips = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manual Sync Tool</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            padding: 20px; 
            background-color: #f5f5f5;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 { 
            color: #333; 
            border-bottom: 3px solid #4CAF50;
            padding-bottom: 10px;
        }
        .sync-button {
            background-color: #4CAF50;
            color: white;
            padding: 15px 30px;
            font-size: 16px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin: 20px 0;
        }
        .sync-button:hover {
            background-color: #45a049;
        }
        .message {
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }
        .success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        table { 
            border-collapse: collapse; 
            width: 100%; 
            margin: 20px 0; 
        }
        th, td { 
            border: 1px solid #ddd; 
            padding: 12px; 
            text-align: left; 
        }
        th { 
            background-color: #4CAF50; 
            color: white; 
        }
        tr:nth-child(even) { 
            background-color: #f2f2f2; 
        }
        .status-assigned { 
            background-color: #ffeb3b; 
            padding: 4px 8px; 
            border-radius: 3px;
        }
        .status-available { 
            background-color: #4CAF50; 
            color: white;
            padding: 4px 8px; 
            border-radius: 3px;
        }
        .status-returning { 
            background-color: #ff9800; 
            color: white;
            padding: 4px 8px; 
            border-radius: 3px;
        }
        .status-maintenance { 
            background-color: #f44336; 
            color: white;
            padding: 4px 8px; 
            border-radius: 3px;
        }
        .info-box {
            background-color: #e3f2fd;
            border-left: 4px solid #2196F3;
            padding: 15px;
            margin: 20px 0;
        }
        .warning-box {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔄 Manual Vehicle Status Sync Tool</h1>
        
        <?php if ($message): ?>
            <div class="message <?= $messageType ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="info-box">
            <h3>ℹ️ What does this do?</h3>
            <p>This tool synchronizes vehicle statuses based on <strong>today's date (<?= date('F j, Y') ?>)</strong>:</p>
            <ul>
                <li><strong>Assigned:</strong> Vehicles with approved trips happening today</li>
                <li><strong>Available:</strong> Vehicles with no active trips today</li>
            </ul>
            <p><strong>Use this when:</strong> Vehicles are stuck in "assigned" status after trips have ended</p>
        </div>

        <form method="POST">
            <button type="submit" name="sync_now" class="sync-button">
                🔄 Sync Vehicle Statuses Now
            </button>
        </form>

        <h2>Active Trips Today (<?= date('F j, Y') ?>)</h2>
        <?php if (empty($activeTrips)): ?>
            <div class="warning-box">
                <strong>⚠️ No active trips today</strong><br>
                All vehicles should be marked as 'available' after sync.
            </div>
        <?php else: ?>
            <table>
                <tr>
                    <th>Request ID</th>
                    <th>Vehicle</th>
                    <th>Requestor</th>
                    <th>Travel Dates</th>
                </tr>
                <?php foreach ($activeTrips as $trip): ?>
                    <tr>
                        <td><?= $trip['id'] ?></td>
                        <td><strong><?= htmlspecialchars($trip['plate_number']) ?></strong></td>
                        <td><?= htmlspecialchars($trip['requestor_name']) ?></td>
                        <td>
                            <?= $trip['departure_date'] ?> 
                            to 
                            <?= $trip['return_date'] ?? $trip['departure_date'] ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>

        <h2>Current Vehicle Statuses</h2>
        <table>
            <tr>
                <th>Plate Number</th>
                <th>Current Status</th>
                <th>Assigned To</th>
                <th>Driver</th>
                <th>Expected Status After Sync</th>
            </tr>
            <?php foreach ($vehicles as $vehicle): ?>
                <?php
                // Determine expected status after sync
                $isInActiveTrip = false;
                foreach ($activeTrips as $trip) {
                    if ($trip['assigned_vehicle_id'] == $vehicle['id']) {
                        $isInActiveTrip = true;
                        break;
                    }
                }
                
                $expectedStatus = $vehicle['status'];
                if ($vehicle['status'] !== 'maintenance') {
                    $expectedStatus = $isInActiveTrip ? 'assigned' : 'available';
                }
                
                $needsUpdate = ($vehicle['status'] !== $expectedStatus && $vehicle['status'] !== 'maintenance');
                ?>
                <tr style="<?= $needsUpdate ? 'background-color: #fff3cd;' : '' ?>">
                    <td><strong><?= htmlspecialchars($vehicle['plate_number']) ?></strong></td>
                    <td>
                        <span class="status-<?= $vehicle['status'] ?>">
                            <?= htmlspecialchars($vehicle['status']) ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($vehicle['assigned_to'] ?? '----') ?></td>
                    <td><?= htmlspecialchars($vehicle['driver_name'] ?? '----') ?></td>
                    <td>
                        <span class="status-<?= $expectedStatus ?>">
                            <?= htmlspecialchars($expectedStatus) ?>
                        </span>
                        <?php if ($needsUpdate): ?>
                            <strong>⚠️ Will be updated</strong>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>

        <div class="info-box">
            <h3>🔍 Troubleshooting</h3>
            <ul>
                <li>If vehicles are stuck in "assigned" status → Click sync button</li>
                <li>If EMBR4321 shows as "assigned" but Rocco's trip ended → Sync will fix it</li>
                <li>If a vehicle is in "maintenance" status → It won't be changed by sync</li>
                <li>This sync runs automatically on dashboardX.php and assign_dispatch_vehicle.php</li>
            </ul>
        </div>

        <div style="margin-top: 30px; text-align: center;">
            <a href="dashboardX.php" style="color: #2196F3; text-decoration: none;">← Back to Dashboard</a>
            <span style="margin: 0 15px;">|</span>
            <a href="debug_vehicles.php" style="color: #2196F3; text-decoration: none;">View Debug Tool →</a>
        </div>
    </div>
</body>
</html>