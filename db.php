<?php
// Database credentials from environment variables or sensible defaults
$host = getenv('DB_HOST') ?: 'localhost';
$db   = getenv('DB_NAME') ?: 'vrsX_db';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';

$dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // throw exceptions
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // fetch assoc arrays
    PDO::ATTR_EMULATE_PREPARES   => false,                  // native prepares
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    if (getenv('APP_ENV') === 'development') {
        die("Database connection failed: " . $e->getMessage());
    } else {
        error_log("Database Connection Error: " . $e->getMessage(), 0);
        header("Location: /error.php"); // Generic error page
        exit();
    }
}
