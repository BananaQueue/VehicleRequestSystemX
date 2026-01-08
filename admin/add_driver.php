<?php
require_once __DIR__ . '/../includes/session.php';
require '../db.php';
require_once __DIR__ . '/add_entry.php';

require_role('admin', '../login.php');

$errors = [];
$name = $_POST['name'] ?? '';
$email = $_POST['email'] ?? '';
$phone = $_POST['phone'] ?? '';

// Conditional insert function for drivers (no password needed)
$conditionalDriverInsert = function(PDO $pdo, array $data, array $fields, string $successMessage, string $redirectLocation, array $optionalData) {
    $currentErrors = [];
    try {
        // Insert into users table with role='driver'
        // No password - drivers don't log in
        $stmt = $pdo->prepare("INSERT INTO users (name, email, phone, role, position) VALUES (?, ?, ?, 'driver', 'Driver')");
        $result = $stmt->execute([
            $data['name'], 
            $data['email'], 
            $data['phone']
        ]);
        
        if ($result) {
            $_SESSION['success_message'] = "Driver '{$data['name']}' added successfully.";
            header("Location: ../dashboardX.php#driverManagement");
            exit();
        } else {
            $currentErrors[] = "Failed to add driver. Please try again.";
            return ['success' => false, 'errors' => $currentErrors];
        }
    } catch (PDOException $e) {
        error_log("Add Driver PDO Error: " . $e->getMessage());
        if ($e->getCode() == 23000) {
            $currentErrors[] = "A user with this email already exists.";
        } else {
            $currentErrors[] = "An unexpected error occurred while adding the driver.";
        }
        return ['success' => false, 'errors' => $currentErrors];
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = handle_add_entry(
        $pdo,
        'users',
        ['name' => 'name', 'email' => 'email', 'phone' => 'phone'],
        ['name' => 'required', 'email' => 'required|email', 'phone' => 'required'],
        ['email' => 'Email'],
        "Driver '%s' added successfully.",
        "../dashboardX.php#driverManagement",
        [
            'conditional_insert' => $conditionalDriverInsert
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
    <title>Add Driver</title>
    <link rel="stylesheet" href="../styles.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
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
            <p class="mb-4">Fill in the details to add a new driver to the system.</p>
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
                <?= htmlspecialchars($_SESSION['success_message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <form action="add_driver.php" method="POST">
            <?= csrf_field() ?>

            <div class="mb-3 input-group-icon">
                <label for="name" class="form-label">Driver Name</label>
                <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($name) ?>" required>
                <i class="input-icon fas fa-id-card"></i>
            </div>

            <div class="mb-3 input-group-icon">
                <label for="email" class="form-label">Email</label>
                <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($email) ?>" required>
                <i class="input-icon fas fa-envelope"></i>
            </div>

            <div class="mb-3 input-group-icon">
                <label for="phone" class="form-label">Phone Number</label>
                <input type="tel" class="form-control" id="phone" name="phone" value="<?= htmlspecialchars($phone) ?>" required pattern="^09\d{9}$" placeholder="09XXXXXXXXX">
                <i class="input-icon fas fa-phone"></i>
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
</body>
</html>