<?php
require_once __DIR__ . '/../includes/session.php';
require '../db.php';
require_once __DIR__ . '/add_entry.php';

require_role('admin', '../login.php');

$errors = [];
$username = $_POST['name'] ?? '';
$email = $_POST['email'] ?? '';
$position = $_POST['position'] ?? '';
$role = $_POST['role'] ?? 'employee';
$password = $_POST['password'] ?? '';
$phone = $_POST['phone'] ?? '';

// Unified insert function - DIFFERENT LOGIC for drivers vs employees/dispatch
$conditionalUserInsert = function(PDO $pdo, array $data, array $fields, string $successMessage, string $redirectLocation, array $optionalData) use ($password, $role, $phone) {
    $currentErrors = [];
    try {
        if ($role === 'driver') {
            // DRIVERS: No password, need phone
            $stmt = $pdo->prepare("INSERT INTO users (name, email, phone, role, position) VALUES (?, ?, ?, 'driver', 'Driver')");
            $result = $stmt->execute([$data['name'], $data['email'], $phone]);
        } else {
            // EMPLOYEES & DISPATCH: Need password, position
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (name, email, position, password, role) VALUES (?, ?, ?, ?, ?)");
            $result = $stmt->execute([$data['name'], $data['email'], $data['position'], $hashed_password, $role]);
        }

        if ($result) {
            $roleText = ucfirst($role);
            $_SESSION['success_message'] = "{$roleText} '{$data['name']}' added successfully.";
            
            // Redirect to appropriate tab
            if ($role === 'driver') {
                header("Location: ../dashboardX.php#driverManagement");
            } else {
                header("Location: ../dashboardX.php#employeeManagement");
            }
            exit();
        } else {
            $currentErrors[] = "Failed to add {$role}. Please try again.";
            return ['success' => false, 'errors' => $currentErrors];
        }
    } catch (PDOException $e) {
        error_log("Add User PDO Error: " . $e->getMessage());
        if ($e->getCode() == 23000) {
            $currentErrors[] = "A user with this email already exists.";
        } else {
            $currentErrors[] = "An unexpected error occurred while adding the {$role}.";
        }
        return ['success' => false, 'errors' => $currentErrors];
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $validationRules = [
        'role' => 'required',
        'name' => 'required',
        'email' => 'required|email',
    ];

    // Conditional validation based on role
    if ($role === 'driver') {
        $validationRules['phone'] = 'required';
    } else {
        $validationRules['password'] = 'required|min_length:8';
        if ($role === 'employee') {
            $validationRules['position'] = 'required';
        }
    }

    $result = handle_add_entry(
        $pdo,
        'users',
        ['name' => 'name', 'email' => 'email', 'position' => 'position', 'phone' => 'phone', 'role' => 'role', 'password' => 'password'],
        $validationRules,
        ['email' => 'Email'],
        "%s added successfully.",
        "../dashboardX.php",
        [
            'conditional_insert' => $conditionalUserInsert
        ]
    );

    if (!$result['success']) {
        $errors = $result['errors'];
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
<div class="d-flex justify-content-center align-items-center vh-100 bg-light">
    <div class="simple-form-page-container">
        <div class="text-center mb-2">
            <h2 class="mb-4">Add New User</h2>
            <p>Fill in the details to add a new employee, dispatch, or driver.</p>
        </div>
        
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php foreach ($errors as $error): ?>
                    <?= htmlspecialchars($error) ?><br>
                <?php endforeach; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= $_SESSION['success_message'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <form action="add_employee.php" method="POST">
            <?= csrf_field() ?>
            
            <div class="mb-3">
                <label for="role" class="form-label">Role</label>
                <select name="role" id="role" class="form-select" required>
                    <option value="">Select Role</option>
                    <option value="employee" <?= ($role === 'employee') ? 'selected' : '' ?>>Employee (Can request vehicles)</option>
                    <option value="dispatch" <?= ($role === 'dispatch') ? 'selected' : '' ?>>Dispatch (Assigns vehicles)</option>
                    <option value="driver" <?= ($role === 'driver') ? 'selected' : '' ?>>Driver (No login access)</option>
                </select>
            </div>
            
            <div class="mb-3 input-group-icon">
                <label for="name" class="form-label">Name</label>
                <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($username) ?>" required>
                <i class="input-icon fas fa-user"></i>
            </div>
            
            <div class="mb-3 input-group-icon">
                <label for="email" class="form-label">Email</label>
                <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($email) ?>" required>
                <i class="input-icon fas fa-envelope"></i>
            </div>
            
            <!-- POSITION FIELD: Only for Employee -->
            <div class="mb-3 input-group-icon" id="positionField">
                <label for="position" class="form-label">Position</label>
                <input type="text" class="form-control" id="position" name="position" value="<?= htmlspecialchars($position) ?>">
                <i class="input-icon fas fa-briefcase"></i>
            </div>
            
            <!-- PASSWORD FIELD: Only for Employee and Dispatch -->
            <div class="mb-3 input-group-icon" id="passwordField">
                <label for="password" class="form-label">Password</label>
                <input type="password" class="form-control" id="password" name="password" minlength="8" maxlength="255">
                <i class="input-icon fas fa-lock"></i>
            </div>
            
            <!-- PHONE FIELD: Only for Driver -->
            <div class="mb-3 input-group-icon" id="phoneField" style="display: none;">
                <label for="phone" class="form-label">Phone Number</label>
                <input type="tel" class="form-control" id="phone" name="phone" value="<?= htmlspecialchars($phone) ?>" maxlength="20" pattern="^09\d{9}$" placeholder="09XXXXXXXXX">
                <i class="input-icon fas fa-phone"></i>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/common.js"></script> 
<script>
document.addEventListener('DOMContentLoaded', function() {
    const roleSelect = document.getElementById('role');
    const passwordField = document.getElementById('passwordField');
    const phoneField = document.getElementById('phoneField');
    const positionField = document.getElementById('positionField'); 
    const passwordInput = document.getElementById('password');
    const phoneInput = document.getElementById('phone');
    const positionInput = document.getElementById('position');
    const submitBtn = document.getElementById('submitBtn');
    const roleInfo = document.getElementById('roleInfo');
    const roleInfoText = document.getElementById('roleInfoText');

    function toggleFields() {
        const selectedRole = roleSelect.value;
        
        if (selectedRole === 'driver') {
            passwordField.style.display = 'none';
            phoneField.style.display = 'block';
            positionField.style.display = 'none';
            passwordInput.required = false;
            phoneInput.required = true;
            positionInput.required = false;
            submitBtn.innerHTML = '<i class="fas fa-plus me-2"></i>Add Driver';

            
        } else if (selectedRole === 'dispatch') {
            passwordField.style.display = 'block';
            phoneField.style.display = 'none';
            positionField.style.display = 'none';
            passwordInput.required = true;
            phoneInput.required = false;
            positionInput.required = false;
            submitBtn.innerHTML = '<i class="fas fa-plus me-2"></i>Add Dispatch';
            
        } else if (selectedRole === 'employee') {
            passwordField.style.display = 'block';
            phoneField.style.display = 'none';
            positionField.style.display = 'block';
            passwordInput.required = true;
            phoneInput.required = false;
            positionInput.required = true;
            submitBtn.innerHTML = '<i class="fas fa-plus me-2"></i>Add Employee';

            
        } else {
            passwordField.style.display = 'block';
            phoneField.style.display = 'none';
            positionField.style.display = 'block';
            passwordInput.required = false;
            phoneInput.required = false;
            positionInput.required = false;
            submitBtn.innerHTML = '<i class="fas fa-plus me-2"></i>Add User';

        }
    }

    toggleFields();
    roleSelect.addEventListener('change', toggleFields);

    var alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        new bootstrap.Alert(alert);
        setTimeout(function() {
            const alertInstance = bootstrap.Alert.getInstance(alert);
            if (alertInstance) alertInstance.close();
        }, 5000);
    });
});
</script>
</body>
</html>