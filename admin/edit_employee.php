<?php
require_once __DIR__ . '/../includes/session.php';
require '../db.php';
require_once __DIR__ . '/edit_entry.php'; // Include the generic edit utility

require_role('admin', '../login.php');

$errors = [];
$employee = []; // Initialize to prevent errors if not fetched

$editResult = handle_edit_entry(
    $pdo,
    'users',
    ['name' => 'name', 'email' => 'email', 'position' => 'position'],
    ['name' => 'required', 'email' => 'required|email', 'position' => 'required'],
    ['email' => 'Email'],
    "Employee '%s' updated successfully.",
    "../dashboardX.php#employeeManagement",
    ['fetch_sql' => "SELECT * FROM users WHERE id = :id AND role IN ('employee', 'dispatch')"]
);

if (!$editResult['success']) {
    $errors = $editResult['errors'];
} else {
    $employee = $editResult['current_entry'];
}

$name = $_POST['name'] ?? ($employee['name'] ?? '');
$email = $_POST['email'] ?? ($employee['email'] ?? '');
$position = $_POST['position'] ?? ($employee['position'] ?? '');

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
                    <h2 class="mb-4">Edit Employee: <?= htmlspecialchars($employee['name'] ?? '') ?></h2>
                    <p class="mb-4">Update the information for this employee account.</p>
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

                <form method="POST" novalidate>
                    <?= csrf_field() ?>

                    <div class="mb-3 input-group-icon">
                        <label for="name" class="form-label">Employee Name</label>
                        <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($name) ?>" required>
                        <i class="input-icon fas fa-user"></i>
                        <?php if (isset($errors['name'])): ?><div class="text-danger"><?= htmlspecialchars($errors['name']) ?></div><?php endif; ?>
                    </div>
                
                    <div class="mb-3 input-group-icon">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($email) ?>" required>
                        <i class="input-icon fas fa-envelope"></i>
                        <?php if (isset($errors['email'])): ?><div class="text-danger"><?= htmlspecialchars($errors['email']) ?></div><?php endif; ?>
                    </div>
                
                    <div class="mb-4 input-group-icon">
                        <label for="position" class="form-label">Position</label>
                        <input type="text" class="form-control" id="position" name="position" value="<?= htmlspecialchars($position) ?>" required>
                        <i class="input-icon fas fa-briefcase"></i>
                        <?php if (isset($errors['position'])): ?><div class="text-danger"><?= htmlspecialchars($errors['position']) ?></div><?php endif; ?>
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
});
</script>
</body>
</html>