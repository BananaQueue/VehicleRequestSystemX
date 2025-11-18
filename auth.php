<?php
require_once __DIR__ . '/includes/session.php'; 
require 'db.php'; // Include your database connection

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    validate_csrf_token_post(); // Validate CSRF token for POST requests
    $email = $_POST['email'];
    $password = $_POST['password'];

    $email = filter_var($email, FILTER_SANITIZE_EMAIL);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = "Invalid email format.";
        header("Location: login.php");
        exit();
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Auth PDO Error: " . $e->getMessage(), 0);
        $_SESSION['error'] = "An unexpected error occurred. Please try again.";
        header("Location: login.php");
        exit();
    }
    
    if ($user && password_verify($password, $user['password'])) {
        // ✅ Ensure session is started (in case includes/session.php didn’t already)
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        // ✅ Regenerate session ID (this should produce a new ID)
        $oldId = session_id();
        session_regenerate_id(true);
        $newId = session_id();

        // Debug: check difference (temporary!)
        //echo "Old ID: $oldId<br>";
        //echo "New ID: $newId";
        //exit; // stop execution so you can see it


        $_SESSION['user'] = [
            'id'    => $user['id'],
            'role'  => $user['role'],
            'name'  => $user['name'],
            'email' => $user['email'],
        ];
        
        $_SESSION['show_login_alert'] = true;

        if ($_SESSION['user']['role'] === 'dispatch') {
            header("Location: dispatch_dashboard.php");
        } else {
            header("Location: dashboardX.php");
        }
        exit;
    } else {
        $_SESSION['error'] = "Invalid email or password.";
        header("Location: login.php");
        exit();
    }
}
