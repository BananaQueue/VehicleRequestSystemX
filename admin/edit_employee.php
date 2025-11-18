<?php
// session_name("site3_session"); // Handled in session.php
require_once __DIR__ . '/../includes/session.php';
require '../db.php';

// ✅ STANDARDIZED: Use helper function instead of manual check
require_role('admin', '../login.php');

// ✅ STANDARDIZED: Initialize errors array for consistent error handling
$errors = [];

// ✅ STANDARDIZED: Validate ID as an integer
$id = $_GET['id'] ?? null;
if (!filter_var($id, FILTER_VALIDATE_INT)) {
    $_SESSION['error'] = "Invalid employee ID.";
    header("Location: ../dashboardX.php");
    exit();
}

// ✅ STANDARDIZED: Fetch current employee data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'employee'");
    $stmt->execute([$id]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Edit Employee Select Error: " . $e->getMessage());
    $_SESSION['error'] = "Error fetching employee data.";
    header("Location: ../dashboardX.php");
    exit();
}

if (!$employee) {
    $_SESSION['error'] = "Employee not found.";
    header("Location: ../dashboardX.php");
    exit();
}

// ✅ STANDARDIZED: Handle POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ✅ STANDARDIZED: Use helper function for CSRF validation
    validate_csrf_token_post('edit_employee.php');

    // ✅ STANDARDIZED: Consistent input sanitization
    $name = htmlspecialchars(trim($_POST['name'] ?? ''));
    $email = htmlspecialchars(trim($_POST['email'] ?? ''));
    $position = htmlspecialchars(trim($_POST['position'] ?? ''));

    // Input validation - collect all errors
    if (empty($name)) $errors[] = "Name is required.";
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email is required.";
    if (empty($position)) $errors[] = "Position is required.";

    // Check if email already exists for other employees
    if (!empty($email)) {
        try {
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $checkStmt->execute([$email, $id]);
            if ($checkStmt->fetch()) {
                $errors[] = "Email already exists for another user.";
            }
        } catch (PDOException $e) {
            error_log("Email Check Error: " . $e->getMessage());
            $errors[] = "Error checking email uniqueness.";
        }
    }

    // ✅ STANDARDIZED: Handle errors consistently
    if (!empty($errors)) {
        // Don't redirect on validation errors - stay on page to show form with retained values
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, position = ? WHERE id = ?");
            $result = $stmt->execute([$name, $email, $position, $id]);

            if ($result) {
                $_SESSION['success'] = "Employee updated successfully.";
                header("Location: ../dashboardX.php#employeeManagement");
                exit();
            } else {
                $errors[] = "Failed to update employee. Please try again.";
            }
        } catch (PDOException $e) {
            error_log("Edit Employee Update Error: " . $e->getMessage());
            $errors[] = "An unexpected error occurred while updating the employee.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Employee</title>
    <link rel="stylesheet" href="../styles.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    </head>
<body>
<div class="d-flex justify-content-center align-items-center vh-100 bg-light">
    <div class="simple-form-page-container">
                <div class="text-center mb-4">
                    <h2 class="mb-4">Edit Employee: <?= htmlspecialchars($employee['name']) ?></h2>
                    <p class="mb-4">Update the information for this employee account.</p>
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

                <!-- ✅ STANDARDIZED: Session-based error/success display -->
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

                <form method="POST" novalidate>
                    <!-- ✅ STANDARDIZED: Use helper function -->
                    <?= csrf_field() ?>

                    <div class="mb-3 input-group-icon">
                        <label for="name" class="form-label">Employee Name</label>
                        <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($_POST['name'] ?? $employee['name']) ?>" required>
                        <i class="input-icon fas fa-user"></i>
                        <?php if (isset($errors['name'])): ?><div class="text-danger"><?= $errors['name'] ?></div><?php endif; ?>
                    </div>
                
                    <div class="mb-3 input-group-icon">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? $employee['email']) ?>" required>
                        <i class="input-icon fas fa-envelope"></i>
                        <?php if (isset($errors['email'])): ?><div class="text-danger"><?= $errors['email'] ?></div><?php endif; ?>
                    </div>
                
                    <div class="mb-4 input-group-icon">
                        <label for="position" class="form-label">Position</label>
                        <input type="text" class="form-control" id="position" name="position" value="<?= htmlspecialchars($_POST['position'] ?? $employee['position']) ?>" required>
                        <i class="input-icon fas fa-briefcase"></i>
                        <?php if (isset($errors['position'])): ?><div class="text-danger"><?= $errors['position'] ?></div><?php endif; ?>
                    </div>

                    <div class="d-grid gap-2 mb-3">
                        <button type="submit" class="btn btn-primary">
                             <i class="fas fa-save me-2"></i>Update Employee</button>
                    </div>
                
                    <div class="text-center">
                        <a href="../dashboardX.php#employeeManagement" class="btn btn-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back to 
                            Dashboard
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
        var position = document.getElementById('position').value.trim();
        
        // Basic validation
        if (name === '') {
            e.preventDefault();
            alert('Please enter an employee name.');
            return false;
        }
        
        if (position === '') {
            e.preventDefault();
            alert('Please enter a position.');
            return false;
        }
        
        var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(email)) {
            e.preventDefault();
            alert('Please enter a valid email address.');
            return false;
        }
    });

});
</script>
</body>
</html>