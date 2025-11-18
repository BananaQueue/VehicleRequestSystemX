<?php
// session_name("site3_session"); // Handled in session.php
require_once __DIR__ . '/../includes/session.php';
require '../db.php';

// ✅ STANDARDIZED: Use helper function instead of manual check
require_role('admin', '../login.php');

// ✅ STANDARDIZED: Initialize errors array for consistent error handling
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ✅ STANDARDIZED: Use helper function instead of manual validation
    validate_csrf_token_post('add_employee.php');
    
    // ✅ STANDARDIZED: Consistent input sanitization pattern
    $username = htmlspecialchars(trim($_POST['name'] ?? ''));
    $email = htmlspecialchars(trim($_POST['email'] ?? ''));
    $position = htmlspecialchars(trim($_POST['position'] ?? ''));
    $role = htmlspecialchars(trim($_POST['role'] ?? 'employee'));
    $password = $_POST['password'] ?? '';
    $phone = htmlspecialchars(trim($_POST['phone'] ?? ''));

    // Input validation - collect all errors
    if (empty($username)) $errors[] = "Name is required.";
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email is required.";
    if (empty($position)) $errors[] = "Position is required.";
    if (!in_array($role, ['employee', 'dispatch', 'driver'])) $errors[] = "Invalid role selected.";
    
    // Role-specific validation
    if ($role === 'driver') {
        // Driver doesn't need password but needs phone
        if (empty($phone)) $errors[] = "Phone number is required for drivers.";
    } else {
        // Employee and dispatch need password
        if (empty($password) || strlen($password) < 8) $errors[] = "Password must be at least 8 characters.";
    }

    // ✅ STANDARDIZED: Handle errors consistently
    if (!empty($errors)) {
        // Don't redirect on validation errors - stay on page to show form with retained values
    } else {
        try {
            // Check for duplicate email in appropriate table
            if ($role === 'driver') {
                $checkStmt = $pdo->prepare("SELECT id FROM drivers WHERE email = ?");
            } else {
                $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            }
            $checkStmt->execute([$email]);
            
            if ($checkStmt->fetch()) {
                $errors[] = "Email already exists.";
            } else {
                if ($role === 'driver') {
                    // Insert into drivers table with default status 'available'
                    $stmt = $pdo->prepare("INSERT INTO drivers (name, email, phone, position, status) VALUES (?, ?, ?, ?, 'available')");
                    $result = $stmt->execute([$username, $email, $phone, $role]);
                } else {
                    // Insert into users table
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO users (name, email, position, password, role) VALUES (?, ?, ?, ?, ?)");
                    $result = $stmt->execute([$username, $email, $position, $hashed_password, $role]);
                }
                
                if ($result) {
                    $roleText = ucfirst($role);
                    $_SESSION['success_message'] = "{$roleText} '{$username}' added successfully.";
                    
                    // Redirect to appropriate tab
                    if ($role === 'driver') {
                        header("Location: ../dashboardX.php#driverManagement");
                    } else {
                        header("Location: ../dashboardX.php#employeeManagement");
                    }
                    exit();
                } else {
                    $errors[] = "Failed to add {$role}. Please try again.";
                }
            }
        } catch (PDOException $e) {
            // ✅ STANDARDIZED: Consistent error logging pattern
            error_log("Add Employee/Driver PDO Error: " . $e->getMessage());
            $errors[] = "An unexpected error occurred while adding the {$role}.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add User</title>
    <link rel="stylesheet" href="../styles.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
<div class="d-flex justify-content-center align-items-center vh-100 bg-light">
    <div class="simple-form-page-container">
        <div class="text-center mb-2">
            <h2 class="mb-4">Add New Employee</h2>
            <p>Fill in the details to add a new employee, dispatch, or driver account.</p>
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

        <!-- ✅ STANDARDIZED: Success message display (for consistency) -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= $_SESSION['success_message'] ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= $_SESSION['error_message'] ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <form action="add_employee.php" method="POST">
            <?= csrf_field() ?>
            
            <div class="mb-3 input-group-icon d-flex">
                <div class="col-6">
                    <label for="role" class="form-label">Role</label>
                    <select name="role" id="role" class="form-select" required>
                        <option value="">Select Role</option>
                        <option value="employee" <?= (isset($_POST['role']) && $_POST['role'] === 'employee') ? 'selected' : '' ?>>Employee</option>
                        <option value="dispatch" <?= (isset($_POST['role']) && $_POST['role'] === 'dispatch') ? 'selected' : '' ?>>Dispatch</option>
                        <option value="driver" <?= (isset($_POST['role']) && $_POST['role'] === 'driver') ? 'selected' : '' ?>>Driver</option>
                    </select>
                    <?php if (isset($errors['role'])): ?><div class="text-danger"><?= htmlspecialchars($errors['role']) ?></div><?php endif; ?>
                </div>
                <div class="input-group-icon col-6" id="positionField">
                    <label for="position" class="form-label">Position</label>
                    <input type="text" class="form-control" id="position" name="position" value="<?= htmlspecialchars($position ?? '') ?>" required>
                    <i class="input-icon fas fa-briefcase"></i>
                    <?php if (isset($errors['position'])): ?><div class="text-danger"><?= htmlspecialchars($errors['position']) ?></div><?php endif; ?>
                </div>
            </div>
            
            <div class="mb-3 input-group-icon">
                <label for="name" class="form-label">Name</label>
                <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($username ?? '') ?>" required>
                <i class="input-icon fas fa-user"></i>
                <?php if (isset($errors['name'])): ?><div class="text-danger"><?= htmlspecialchars($errors['name']) ?></div><?php endif; ?>
            </div>
            
            <div class="mb-3 input-group-icon">
                <label for="email" class="form-label">Email</label>
                <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($email ?? '') ?>" required>
                <i class="input-icon fas fa-envelope"></i>
                <?php if (isset($errors['email'])): ?><div class="text-danger"><?= htmlspecialchars($errors['email']) ?></div><?php endif; ?>
            </div>
            
            <div class="mb-3 input-group-icon" id="passwordField">
                <label for="password" class="form-label">Password</label>
                <input type="password" class="form-control" id="password" name="password" minlength="8" maxlength="255">
                <i class="input-icon fas fa-lock"></i>
                <?php if (isset($errors['password'])): ?><div class="text-danger"><?= htmlspecialchars($errors['password']) ?></div><?php endif; ?>
            </div>
            
            <div class="mb-3 input-group-icon" id="phoneField" style="display: none;">
                <label for="phone" class="form-label">Phone Number</label>
                <input type="tel" class="form-control" id="phone" name="phone" value="<?= htmlspecialchars($phone ?? '') ?>" maxlength="20" pattern="^09\d{9}$" placeholder="09XXXXXXXXX">
                <i class="input-icon fas fa-phone"></i>
                <?php if (isset($errors['phone'])): ?><div class="text-danger"><?= htmlspecialchars($errors['phone']) ?></div><?php endif; ?>
            </div>
            
            <div class="d-grid mb-3">
                <button type="submit" class="btn btn-primary" id="submitBtn">
                    <i class="fas fa-plus me-2"></i>Add User
                </button>
            </div>
            
            <div class="text-center">
                <a href="../dashboardX.php#employeeManagement" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                </a>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const roleSelect = document.getElementById('role');
    const passwordField = document.getElementById('passwordField');
    const phoneField = document.getElementById('phoneField');
    const positionField = document.getElementById('positionField'); 
    const passwordInput = document.getElementById('password');
    const phoneInput = document.getElementById('phone');
    const submitBtn = document.getElementById('submitBtn');

    // Function to toggle fields based on role
    function toggleFields() {
        const selectedRole = roleSelect.value;
        
        if (selectedRole === 'driver') {
            passwordField.style.display = 'none';
            phoneField.style.display = 'block';
            positionField.style.display = 'none';
            passwordInput.required = false;
            phoneInput.required = true;
            submitBtn.textContent = 'Add Driver';
        } else if (selectedRole === 'dispatch') {
            passwordField.style.display = 'block';
            phoneField.style.display = 'none';
            positionField.style.display = 'none';
            passwordInput.required = true;
            phoneInput.required = false;
            submitBtn.textContent = 'Add Dispatch';
        } else if (selectedRole === 'employee') {
            passwordField.style.display = 'block';
            phoneField.style.display = 'none';
            positionField.style.display = 'block';
            passwordInput.required = true;
            phoneInput.required = false;
            submitBtn.textContent = 'Add Employee';
        } else {
            passwordField.style.display = 'block';
            phoneField.style.display = 'none';
            positionField.style.display = 'block';
            passwordInput.required = false;
            phoneInput.required = false;
            submitBtn.textContent = 'Add User';
        }
    }

    // Initialize on page load
    toggleFields();

    // Handle role change
    roleSelect.addEventListener('change', toggleFields);

    // Auto-dismiss Bootstrap alerts after 5 seconds
    var alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        new bootstrap.Alert(alert);
        setTimeout(function() {
            const alertInstance = bootstrap.Alert.getInstance(alert);
            if (alertInstance) {
                alertInstance.close();
            }
        }, 5000);
    });
    
    // Enhanced client-side validation
    var form = document.querySelector('form');
    form.addEventListener('submit', function(e) {
        var role = roleSelect.value;
        var name = document.getElementById('name').value.trim();
        var email = document.getElementById('email').value.trim();
        var position = document.getElementById('position').value.trim();
        var password = document.getElementById('password').value;
        var phone = document.getElementById('phone').value.trim();
        
        // Basic validation
        if (role === '') {
            e.preventDefault();
            alert('Please select a role.');
            return false;
        }
        
        if (name === '') {
            e.preventDefault();
            alert('Please enter a name.');
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
        
        // Role-specific validation
        if (role === 'driver') {
            if (phone === '' || phone === '09') {
                e.preventDefault();
                alert('Please enter a phone number for the driver.');
                return false;
            }
            var phoneRegex = /^09\d{9}$/;
            if (!phoneRegex.test(phone)) {
                e.preventDefault();
                alert('Please enter a valid Philippine mobile number (09XXXXXXXXX).');
                return false;
            }
        } else {
            if (password.length < 8) {
                e.preventDefault();
                alert('Password must be at least 8 characters long.');
                return false;
            }
        }
    });

});
</script>
</body>
</html>