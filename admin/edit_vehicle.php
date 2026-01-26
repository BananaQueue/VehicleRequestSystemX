<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/edit_entry.php'; // Include the generic edit utility

require_role('admin', '../login.php');

$errors = [];
$vehicle = []; // Initialize to prevent errors if not fetched

// Fetch drivers for dropdown (from users table where role='driver')
$drivers = [];
try {
    $drivers = $pdo->query("SELECT id, name FROM users WHERE role = 'driver' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Driver Fetch Error: " . $e->getMessage());
    $errors[] = "Could not load drivers list.";
}

// Conditional update function for vehicles
$conditionalVehicleUpdate = function(PDO $pdo, int $id, array $data, array $currentEntry, array $fields, string $successMessage, string $redirectLocation, array $optionalData) {
    $currentErrors = [];
    
    // Check if driver is already assigned to another vehicle, if a driver is selected and it's a new driver
    if (!empty($data['driver_id']) && ($data['driver_id'] !== ($currentEntry['driver_id'] ?? null))) {
        $checkStmt = $pdo->prepare("SELECT id FROM vehicles WHERE driver_id = ? AND id != ?");
        $checkStmt->execute([$data['driver_id'], $id]);
        if ($checkStmt->fetch()) {
            $currentErrors[] = "This driver is already assigned to another vehicle.";
            return ['success' => false, 'errors' => $currentErrors];
        }
    }

    // Proceed with the update if no new errors
    if (empty($currentErrors)) {
        $updateFields = [];
        $updateData = [];

        foreach ($fields as $dbColumn => $postKey) {
            if ($dbColumn !== 'id' && ($data[$dbColumn] !== ($currentEntry[$dbColumn] ?? null))) {
                $updateFields[] = "{$dbColumn} = :{$dbColumn}";
                $updateData[$dbColumn] = $data[$dbColumn];
            }
        }

        // Special handling for status if it's explicitly part of the form and changed
        if (isset($_POST['status']) && ($_POST['status'] !== ($currentEntry['status'] ?? null))) {
            $allowed_statuses = ['available', 'assigned', 'returning', 'maintenance'];
            if (!in_array($_POST['status'], $allowed_statuses)) {
                $currentErrors[] = "Invalid vehicle status.";
                return ['success' => false, 'errors' => $currentErrors];
            }
            $updateFields[] = "status = :status";
            $updateData['status'] = $_POST['status'];
        }

        if (empty($updateFields)) {
            $_SESSION['warning_message'] = "No changes detected for this entry.";
            header("Location: " . $redirectLocation);
            exit();
        }

        $stmt = $pdo->prepare("UPDATE vehicles SET " . implode(', ', $updateFields) . " WHERE id = :id");
        $updateData['id'] = $id;
        $result = $stmt->execute($updateData);

        if ($result) {
            $_SESSION['success_message'] = sprintf($successMessage, $data['plate_number']);
            header("Location: " . $redirectLocation);
            exit();
        } else {
            $currentErrors[] = "Failed to update vehicle. Please try again.";
            return ['success' => false, 'errors' => $currentErrors];
        }
    }
    return ['success' => false, 'errors' => $currentErrors];
};

$editResult = handle_edit_entry(
    $pdo,
    'vehicles',
    ['plate_number' => 'plate_number', 'driver_id' => 'driver_id', 'make' => 'make', 'model' => 'model', 'type' => 'type'],
    ['plate_number' => 'required', 'make' => 'required', 'model' => 'required', 'type' => 'required'],
    ['plate_number' => 'Plate number'],
    "Vehicle '%s' updated successfully.",
    "../dashboardX.php",
    [
        'fetch_sql' => "SELECT * FROM vehicles WHERE id = :id",
        'conditional_update' => $conditionalVehicleUpdate,
        'id_field' => 'id' // Specify ID field if different from default 'id'
    ]
);

if (!$editResult['success']) {
    $errors = array_merge($errors, $editResult['errors']);
} else {
    $vehicle = $editResult['current_entry'];
}

// Pre-fill form values for display
$plate = $_POST['plate_number'] ?? ($vehicle['plate_number'] ?? '');
$driver_id = $_POST['driver_id'] ?? ($vehicle['driver_id'] ?? null);
$make = $_POST['make'] ?? ($vehicle['make'] ?? '');
$model = $_POST['model'] ?? ($vehicle['model'] ?? '');
$type = $_POST['type'] ?? ($vehicle['type'] ?? '');
$status = $_POST['status'] ?? ($vehicle['status'] ?? 'available');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Vehicle</title>
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
                        <h2 class="mb-4">Edit Vehicle: <?= htmlspecialchars($vehicle['plate_number'] ?? '') ?></h2>
                        <p class="mb-4">Update the information for this vehicle.</p>
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

                    <form action="edit_vehicle.php?id=<?= $vehicle['id'] ?? '' ?>" method="POST">
                        <?= csrf_field() ?>
                        <div class="mb-3 input-group-icon d-flex">
                            <div class="col-6">
                                <label for="plate_number" class="form-label">Plate Number</label>
                                <input type="text" class="form-control" id="plate_number" name="plate_number" value="<?= htmlspecialchars($plate) ?>" required>
                                <i class="input-icon fas fa-hashtag"></i>
                                <?php if (isset($errors['plate_number'])): ?><div class="text-danger"><?= htmlspecialchars($errors['plate_number']) ?></div><?php endif; ?>
                            </div>
                            <div class="col-6">
                            <label for="status" class="form-label">Status:</label>
                            <select name="status" id="status" class="form-select" required>
                                <option value="available" <?= $status === 'available' ? 'selected' : '' ?>>Available</option>
                                <option value="assigned" <?= $status === 'assigned' ? 'selected' : '' ?>>Assigned</option>
                                <option value="returning" <?= $status === 'returning' ? 'selected' : '' ?>>Pending return</option>
                                <option value="maintenance" <?= $status === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                            </select>
                            <?php if (isset($errors['status'])): ?><div class="text-danger"><?= htmlspecialchars($errors['status']) ?></div><?php endif; ?>
                            </div>
                        </div>
                        <div class="mb-3 input-group-icon">
                            <label for="driver_id" class="form-label">Assigned Driver (Optional)</label>
                            <select class="form-select" id="driver_id" name="driver_id">
                                <option value="">Select Driver</option>
                                <?php foreach ($drivers as $d): ?>
                                    <option value="<?= htmlspecialchars($d['id']) ?>" <?= ($driver_id == $d['id']) ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['driver_id'])): ?><div class="text-danger"><?= htmlspecialchars($errors['driver_id']) ?></div><?php endif; ?>
                        </div>
                        <div class="mb-3 input-group-icon">
                            <label for="make" class="form-label">Make:</label>
                            <input type="text" class="form-control" id="make" name="make" value="<?= htmlspecialchars($make) ?>" required>
                            <i class="input-icon fas fa-car-side"></i>
                            <?php if (isset($errors['make'])): ?><div class="text-danger"><?= htmlspecialchars($errors['make']) ?></div><?php endif; ?>
                        </div>
                        <div class="mb-3 input-group-icon">
                            <label for="model" class="form-label">Model:</label>
                            <input type="text" class="form-control" id="model" name="model" value="<?= htmlspecialchars($model) ?>" required>
                            <i class="input-icon fas fa-car"></i>
                            <?php if (isset($errors['model'])): ?><div class="text-danger"><?= htmlspecialchars($errors['model']) ?></div><?php endif; ?>
                        </div>
                        <div class="mb-3 input-group-icon">
                            <label for="type" class="form-label">Type:</label>
                            <input type="text" class="form-control" id="type" name="type" value="<?= htmlspecialchars($type) ?>" required>
                            <i class="input-icon fas fa-truck"></i>
                            <?php if (isset($errors['type'])): ?><div class="text-danger"><?= htmlspecialchars($errors['type']) ?></div><?php endif; ?>
                        </div>
                        
                        <div class="d-grid mb-3">   
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Update Vehicle
                            </button>
                        </div> 
                        
                        <div class="text-center">
                            <a href="../dashboardX.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                            </a>
                        </div>
                    </form>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script src="js/common.js"></script>
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