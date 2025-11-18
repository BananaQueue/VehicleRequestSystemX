<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../db.php';

// ✅ STANDARDIZED: Use helper function instead of manual check
require_role('admin', '../login.php');

// ✅ STANDARDIZED: Initialize errors array for consistent error handling
$errors = [];

// ✅ STANDARDIZED: Validate ID as an integer
$id = $_GET['id'] ?? null;
if (!filter_var($id, FILTER_VALIDATE_INT)) {
    $_SESSION['error'] = "Invalid vehicle ID.";
    header("Location: ../dashboardX.php");
    exit();
}

// ✅ STANDARDIZED: Fetch the current vehicle data
try {
    $stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id = ?");
    $stmt->execute([$id]);
    $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Edit Vehicle Select PDO Error: " . $e->getMessage());
    $_SESSION['error'] = "An unexpected error occurred while fetching vehicle data.";
    header("Location: ../dashboardX.php");
    exit();
}

if (!$vehicle) {
    $_SESSION['error'] = "Vehicle not found.";
    header("Location: ../dashboardX.php");
    exit();
}

// ✅ STANDARDIZED: Fetch drivers for dropdown
$drivers = [];
try {
    $drivers = $pdo->query("SELECT name FROM drivers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Driver Fetch Error: " . $e->getMessage());
}

// ✅ STANDARDIZED: Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ✅ ADDED: CSRF protection
    validate_csrf_token_post('edit_vehicle.php');
    
    // ✅ STANDARDIZED: Consistent input sanitization pattern
    $plate = htmlspecialchars(trim($_POST['plate_number'] ?? ''));
    $driver_name = !empty($_POST['driver_name']) ? trim($_POST['driver_name']) : null;
    $make = htmlspecialchars(trim($_POST['make'] ?? ''));
    $model = htmlspecialchars(trim($_POST['model'] ?? ''));
    $type = htmlspecialchars(trim($_POST['type'] ?? ''));
    $status = $_POST['status'] ?? '';

    // Input validation - collect all errors
    if (empty($plate)) $errors[] = "Plate number is required.";
    if (empty($make)) $errors[] = "Make is required.";
    if (empty($model)) $errors[] = "Model is required.";
    if (empty($type)) $errors[] = "Type is required.";
    
    // Validate status input
    $allowed_statuses = ['available', 'assigned', 'returning', 'maintenance'];
    if (!in_array($status, $allowed_statuses)) {
        $errors[] = "Invalid vehicle status.";
    }

    // Check if driver is already assigned to another vehicle
    if ($driver_name) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM vehicles WHERE driver_name = ? AND id != ?");
            $stmt->execute([$driver_name, $id]);
            if ($stmt->fetch()) {
                $errors[] = "This driver is already assigned to another vehicle.";
            }
        } catch (PDOException $e) {
            error_log("Driver Check Error: " . $e->getMessage());
            $errors[] = "Error checking driver assignment.";
        }
    }

    // ✅ STANDARDIZED: Handle errors consistently
    if (!empty($errors)) {
        // Don't redirect on validation errors - stay on page to show form with retained values
    } else {
        try {
            $update = $pdo->prepare("UPDATE vehicles 
                                     SET plate_number = ?, driver_name = ?, make = ?, model = ?, type = ?, status = ? 
                                     WHERE id = ?");
            $result = $update->execute([$plate, $driver_name, $make, $model, $type, $status, $id]);
            
            if ($result) {
                $_SESSION['success'] = "Vehicle updated successfully.";
                header("Location: ../dashboardX.php");
                exit();
            } else {
                $errors[] = "Failed to update vehicle. Please try again.";
            }
        } catch (PDOException $e) {
            // ✅ STANDARDIZED: Consistent error logging pattern
            error_log("Edit Vehicle Update PDO Error: " . $e->getMessage());
            $errors[] = "An unexpected error occurred while updating the vehicle.";
        }
    }
}
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
                        <h2 class="mb-4">Edit Vehicle: <?= htmlspecialchars($vehicle['plate_number']) ?></h2>
                        <p class="mb-4">Update the information for this vehicle.</p>
                    </div>

                    <!-- ✅ STANDARDIZED: Consistent error display pattern -->
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?php foreach ($errors as $error): ?>
                                <?= htmlspecialchars($error) ?><br>
                            <?php endforeach; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <!-- ✅ STANDARDIZED: Session-based error display -->
                    <?php if (isset($_SESSION['error'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?= htmlspecialchars($_SESSION['error']) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php unset($_SESSION['error']); ?>
                    <?php endif; ?>

                    <?php if (isset($_SESSION['success'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?= htmlspecialchars($_SESSION['success']) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php unset($_SESSION['success']); ?>
                    <?php endif; ?>

                    <form action="edit_vehicle.php?id=<?= $vehicle['id'] ?>" method="POST">
                        <?= csrf_field() ?>
                        <div class="mb-3 input-group-icon d-flex">
                            <div class="col-6">
                                <label for="plate_number" class="form-label">Plate Number</label>
                                <input type="text" class="form-control" id="plate_number" name="plate_number" value="<?= htmlspecialchars($plate_number ?? $vehicle['plate_number']) ?>" required>
                                <i class="input-icon fas fa-hashtag"></i>
                                <?php if (isset($errors['plate_number'])): ?><div class="text-danger"><?= htmlspecialchars($errors['plate_number']) ?></div><?php endif; ?>
                            </div>
                            <div class="col-6">
                            <label for="status" class="form-label">Status:</label>
                            <?php $selected_status = $_POST['status'] ?? $vehicle['status'] ?? 'available'; ?>
                            <select name="status" id="status" class="form-select" required>
                                <option value="available" <?= $selected_status === 'available' ? 'selected' : '' ?>>Available</option>
                                <option value="assigned" <?= $selected_status === 'assigned' ? 'selected' : '' ?>>Assigned</option>
                                <option value="returning" <?= $selected_status === 'returning' ? 'selected' : '' ?>>Pending return</option>
                                <option value="maintenance" <?= $selected_status === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                            </select>
                            <?php if (isset($errors['status'])): ?><div class="text-danger"><?= htmlspecialchars($errors['status']) ?></div><?php endif; ?>
                            </div>
                        </div>
                        <div class="mb-3 input-group-icon">
                            <label for="make" class="form-label">Make:</label>
                            <input type="text" class="form-control" id="make" name="make" value="<?= htmlspecialchars($make ?? $vehicle['make']) ?>" required>
                            <i class="input-icon fas fa-car-side"></i>
                            <?php if (isset($errors['make'])): ?><div class="text-danger"><?= htmlspecialchars($errors['make']) ?></div><?php endif; ?>
                        </div>
                        <div class="mb-3 input-group-icon">
                            <label for="model" class="form-label">Model:</label>
                            <input type="text" class="form-control" id="model" name="model" value="<?= htmlspecialchars($model ?? $vehicle['model']) ?>" required>
                            <i class="input-icon fas fa-car"></i>
                            <?php if (isset($errors['model'])): ?><div class="text-danger"><?= htmlspecialchars($errors['model']) ?></div><?php endif; ?>
                        </div>
                        <div class="mb-3 input-group-icon">
                            <label for="type" class="form-label">Type:</label>
                            <input type="text" class="form-control" id="type" name="type" value="<?= htmlspecialchars($type ?? $vehicle['type']) ?>" required>
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
    
    // Basic client-side validation
    var form = document.querySelector('form');
    form.addEventListener('submit', function(e) {
        var plate = document.getElementById('plate_number').value.trim();
        var make = document.getElementById('make').value.trim();
        var model = document.getElementById('model').value.trim();
        var type = document.getElementById('type').value.trim();
        
        // Basic validation
        if (plate === '') {
            e.preventDefault();
            alert('Please enter a plate number.');
            return false;
        }
        
        if (make === '') {
            e.preventDefault();
            alert('Please enter a make.');
            return false;
        }
        
        if (model === '') {
            e.preventDefault();
            alert('Please enter a model.');
            return false;
        }
        
        if (type === '') {
            e.preventDefault();
            alert('Please enter a type.');
            return false;
        }
    });

});
</script>
</body>
</html>