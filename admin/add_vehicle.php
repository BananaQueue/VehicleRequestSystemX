<?php
require_once __DIR__ . '/../includes/session.php';
require '../db.php';
require_once __DIR__ . '/add_entry.php'; // Include the generic add utility

require_role('admin', '../login.php');

$errors = [];
$plate = $_POST['plate_number'] ?? '';
$driver = $_POST['driver_name'] ?? null;
$make = $_POST['make'] ?? '';
$model = $_POST['model'] ?? '';
$type = $_POST['type'] ?? '';

// Fetch drivers for dropdown
$drivers = [];
try {
    $drivers = $pdo->query("SELECT name FROM drivers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Driver Fetch Error: " . $e->getMessage());
    $errors[] = "Could not load drivers list.";
}

// Conditional insert function for vehicles
$conditionalVehicleInsert = function(PDO $pdo, array $data, array $fields, string $successMessage, string $redirectLocation, array $optionalData) use ($driver) {
    $currentErrors = [];
    try {
        // Check if driver is already assigned to another vehicle, if a driver is selected
        if (!empty($data['driver_name'])) {
            $checkStmt = $pdo->prepare("SELECT id FROM vehicles WHERE driver_name = ?");
            $checkStmt->execute([$data['driver_name']]);
            if ($checkStmt->fetch()) {
                $currentErrors[] = "This driver is already assigned to another vehicle.";
                return ['success' => false, 'errors' => $currentErrors];
            }
        }

        $stmt = $pdo->prepare("INSERT INTO vehicles (plate_number, driver_name, make, model, type, status) VALUES (?, ?, ?, ?, ?, 'available')");
        $result = $stmt->execute([$data['plate_number'], $data['driver_name'], $data['make'], $data['model'], $data['type']]);
        
        if ($result) {
            $_SESSION['success_message'] = "Vehicle '" . $data['plate_number'] . "' added successfully.";
            header("Location: ../dashboardX.php");
            exit();
        } else {
            $currentErrors[] = "Failed to add vehicle. Please try again.";
            return ['success' => false, 'errors' => $currentErrors];
        }
    } catch (PDOException $e) {
        error_log("Add Vehicle PDO Error: " . $e->getMessage());
        $currentErrors[] = "An unexpected error occurred while adding the vehicle.";
        return ['success' => false, 'errors' => $currentErrors];
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = handle_add_entry(
        $pdo,
        'vehicles',
        ['plate_number' => 'plate_number', 'driver_name' => 'driver_name', 'make' => 'make', 'model' => 'model', 'type' => 'type'],
        ['plate_number' => 'required', 'make' => 'required', 'model' => 'required', 'type' => 'required'],
        ['plate_number' => 'Plate number'],
        "Vehicle '%s' added successfully.",
        "../dashboardX.php", // This will be overridden by conditional insert
        [
            'default_status_field' => 'status',
            'conditional_insert' => $conditionalVehicleInsert
        ]
    );

    if (!$result['success']) {
        $errors = array_merge($errors, $result['errors']);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Vehicle</title>
    <link rel="stylesheet" href="../styles.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    </head>
<body>
<div class="d-flex justify-content-center align-items-center vh-100 bg-light">
    <div class="simple-form-page-container">
        <div class="text-center mb-4">
            <h2 class="mb-4">Add New Vehicle</h2>
            <p class="mb-4">Fill in the details to add a new vehicle to the fleet.</p>
        </div>
        
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php foreach ($errors as $error): ?>
                    <?= htmlspecialchars($error) ?><br>
                <?php endforeach; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($_SESSION['error_message']) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($_SESSION['success_message']) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <form action="add_vehicle.php" method="POST">
            <?= csrf_field() ?>
            
            <div class="mb-3 input-group-icon">
                <label for="plate_number" class="form-label">Plate Number</label>
                <input type="text" class="form-control" id="plate_number" name="plate_number" value="<?= htmlspecialchars($plate ?? '') ?>" required style="text-transform: uppercase;">
                <i class="input-icon fas fa-hashtag"></i>
                <?php if (isset($errors['plate_number'])): ?><div class="text-danger"><?= htmlspecialchars($errors['plate_number']) ?></div><?php endif; ?>
            </div>
            <div class="mb-3">
                <label for="driver_name" class="form-label">Assigned Driver (Optional)</label>
                <select class="form-select" id="driver_name" name="driver_name">
                    <option value="">Select Driver</option>
                    <?php foreach ($drivers as $d): ?>
                        <option value="<?= htmlspecialchars($d['name']) ?>" <?= ($driver === $d['name']) ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['driver_name'])): ?><div class="text-danger"><?= htmlspecialchars($errors['driver_name']) ?></div><?php endif; ?>
            </div>
            <div class="mb-3 input-group-icon">
                <label for="make" class="form-label">Make</label>
                <input type="text" class="form-control" id="make" name="make" value="<?= htmlspecialchars($make ?? '') ?>" required>
                <i class="input-icon fas fa-car-side"></i>
                <?php if (isset($errors['make'])): ?><div class="text-danger"><?= htmlspecialchars($errors['make']) ?></div><?php endif; ?>
            </div>
            <div class="mb-3 input-group-icon">
                <label for="model" class="form-label">Model</label>
                <input type="text" class="form-control" id="model" name="model" value="<?= htmlspecialchars($model ?? '') ?>" required>
                <i class="input-icon fas fa-car"></i>
                <?php if (isset($errors['model'])): ?><div class="text-danger"><?= htmlspecialchars($errors['model']) ?></div><?php endif; ?>
            </div>
            <div class="mb-3 input-group-icon">
                <label for="type" class="form-label">Type</label>
                <input type="text" class="form-control" id="type" name="type" value="<?= htmlspecialchars($type ?? '') ?>" required>
                <i class="input-icon fas fa-truck"></i>
                <?php if (isset($errors['type'])): ?><div class="text-danger"><?= htmlspecialchars($errors['type']) ?></div><?php endif; ?>
            </div>
            <div class="d-grid mb-3">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-plus me-2"></i>Add Vehicle
                </button>
            </div>
            
            <div class="text-center">
                <a href="../dashboardX.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                </a>
            </div>
        </form>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-dismiss Bootstrap alerts after 5 seconds
    var alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        new bootstrap.Alert(alert);
        setTimeout(function() {
            const alertInstance = bootstrap.Alert.getInstance(alert);
            if (alertInstance) {
                alertInstance.close();
            }
        }, 5000); // 5000 milliseconds = 5 seconds
    });
});
</script>
</body>
</html>