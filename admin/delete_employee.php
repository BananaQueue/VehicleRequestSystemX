<?php

require_once __DIR__ . '/../includes/session.php';
require '../db.php';

// ✅ STANDARDIZED: Use helper function instead of manual check
require_role('admin', '../login.php');

// ✅ STANDARDIZED: Only allow POST, not GET
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = "Invalid request method.";
    header("Location: ../dashboardX.php");
    exit();
}

// ✅ STANDARDIZED: Use helper function instead of manual CSRF validation
validate_csrf_token_post('../dashboardX.php', 'Invalid security token.');
error_log("CSRF validation passed");

// ✅ STANDARDIZED: Consistent input validation
$id = $_POST['id'] ?? null;
if (!filter_var($id, FILTER_VALIDATE_INT)) {
    $_SESSION['error'] = "Invalid employee ID.";
    header("Location: ../dashboardX.php");
    exit();
}

try {
    // ✅ STANDARDIZED: Get employee info first for success message
    $stmt = $pdo->prepare("SELECT name FROM users WHERE id = ? AND role = 'employee'");
    $stmt->execute([$id]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee) {
        $_SESSION['error'] = "Employee not found.";
        header("Location: ../dashboardX.php");
        exit();
    }

    // Delete the employee
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $result = $stmt->execute([$id]);

    if ($result && $stmt->rowCount() > 0) {
        $_SESSION['success'] = "Employee '" . htmlspecialchars($employee['name']) . "' deleted successfully.";
    } else {
        $_SESSION['error'] = "Failed to delete employee.";
    }

} catch (PDOException $e) {
    // ✅ STANDARDIZED: Consistent error logging pattern
    error_log("Delete Employee PDO Error: " . $e->getMessage());
    $_SESSION['error'] = "An unexpected error occurred while deleting the employee.";
}

// ✅ STANDARDIZED: Always include exit() after header redirect
header("Location: ../dashboardX.php");
exit();