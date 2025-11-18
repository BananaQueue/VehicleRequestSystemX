<?php
// Set session name FIRST before any session operations
session_name("site3_session");
// Session configuration
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict'
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Generate CSRF token if it doesn't exist or is expired
if (empty($_SESSION['csrf_token']) || 
    (empty($_SESSION['csrf_token_time']) || (time() - $_SESSION['csrf_token_time']) > 1800)) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['csrf_token_time'] = time();
}

// Include security functions
require_once __DIR__ . '/security.php';