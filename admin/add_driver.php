<?php
require_once __DIR__ . '/../includes/session.php';
require '../db.php';

// ✅ STANDARDIZED: Use helper function instead of manual check
require_role('admin', '../login.php');

// ✅ STANDARDIZED: Initialize errors array for consistent error handling
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ✅ STANDARDIZED: Use helper function for CSRF validation
    validate_csrf_token_post('add_driver.php');

    // ✅ STANDARDIZED: Consistent input sanitization pattern
    $name = htmlspecialchars(trim($_POST['name'] ?? ''));
    $email = htmlspecialchars(trim($_POST['email'] ?? ''));
    $phone = htmlspecialchars(trim($_POST['phone'] ?? ''));

    // Validate inputs - collect all errors
    if (empty($name)) $errors[] = "Name is required.";
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email is required.";

    // ✅ STANDARDIZED: Handle errors consistently
    if (!empty($errors)) {
        // Don't redirect on validation errors - stay on page to show form with retained values
    } else {
        try {
            // Check if email already exists
            $checkStmt = $pdo->prepare("SELECT id FROM drivers WHERE email = :email");
            $checkStmt->execute(['email' => $email]);
            if ($checkStmt->fetch()) {
                $errors[] = "Email already exists.";
            } else {
                // Insert new driver
                $stmt = $pdo->prepare("INSERT INTO drivers (name, email, phone) VALUES (?, ?, ?)");
                $result = $stmt->execute([$name, $email, $phone]);
                
                if ($result) {
                    $_SESSION['success_message'] = "Driver '" . $name . "' added successfully.";
                    header("Location: ../dashboardX.php#driverManagement");
                    exit();
                } else {
                    $errors[] = "Failed to add driver. Please try again.";
                }
            }
        } catch (PDOException $e) {
            // ✅ STANDARDIZED: Consistent error logging pattern
            error_log("Add Driver PDO Error: " . $e->getMessage());
            $errors[] = "An unexpected error occurred while adding the driver.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Driver</title>
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
                    <h2 class="mb-4">Add New Driver</h2>
                    <p class="mb-4">Fill in the details to add a new driver account.</p>
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

            <!-- ✅ STANDARDIZED: Session-based success message display -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($_SESSION['success_message']) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($_SESSION['error_message']) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <form action="add_driver.php" method="POST">
                <!-- ✅ STANDARDIZED: Use helper function -->
                <?= csrf_field() ?>

                <div class="mb-3 input-group-icon">
                    <label for="name" class="form-label">Driver Name</label>
                    <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($name ?? '') ?>" required>
                    <?php if (isset($errors['name'])): ?><div class="text-danger"><?= htmlspecialchars($errors['name']) ?></div><?php endif; ?>
                    <i class="input-icon fas fa-id-card"></i>
                </div>
                <div class="mb-3 input-group-icon">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($email ?? '') ?>" required>
                    <i class="input-icon fas fa-envelope"></i>
                    <?php if (isset($errors['email'])): ?><div class="text-danger"><?= htmlspecialchars($errors['email']) ?></div><?php endif; ?>
                </div>
                <div class="mb-3 input-group-icon">
                    <label for="phone" class="form-label">Phone Number</label>
                    <input type="tel" class="form-control" id="phone" name="phone" value="<?= htmlspecialchars($phone ?? '09') ?>" required>
                    <i class="input-icon fas fa-phone"></i>
                    <?php if (isset($errors['phone'])): ?><div class="text-danger"><?= htmlspecialchars($errors['phone']) ?></div><?php endif; ?>
                </div>
                <div class="d-grid mb-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-plus me-2"></i>Add Driver
                    </button>
                </div>
                
                <div class="text-center">
                    <a href="../dashboardX.php#driverManagement" class="btn btn-secondary">
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
    
    // Basic client-side validation
    var form = document.querySelector('form');
    form.addEventListener('submit', function(e) {
        var name = document.getElementById('name').value.trim();
        var email = document.getElementById('email').value.trim();
        var phone = document.getElementById('phone').value.trim();
        
        // Basic validation
        if (name === '') {
            e.preventDefault();
            alert('Please enter a driver name.');
            return false;
        }
        
        // Basic email validation
        var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(email)) {
            e.preventDefault();
            alert('Please enter a valid email address.');
            return false;
        }

        // Phone number validation
        var phoneRegex = /^09\d{9}$/;
        if (!phoneRegex.test(phone)) {
            e.preventDefault();
            alert('Please enter a valid Philippine mobile number (09XXXXXXXXX).');
            return false;
        }
    });

});
</script>
</body>
</html>