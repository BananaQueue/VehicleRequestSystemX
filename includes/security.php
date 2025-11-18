<?php
// =============================================================================
// FILE: includes/security.php (NEW FILE - CREATE THIS)
// =============================================================================

/**
 * Security utilities for CSRF protection and input validation
 */

/**
 * Validates CSRF token and handles errors
 * @param string $redirect_url Where to redirect on failure
 * @param string $error_message Custom error message
 * @return bool True if valid, exits on failure
 */
function validate_csrf_token_post(string $redirect_url = 'dashboardX.php', string $error_message = 'Invalid request. Please try again.') {
    // Check if token exists in POST data
    if (!isset($_POST['csrf_token'])) {
        $_SESSION['error'] = $error_message;
        header("Location: $redirect_url");
        exit();
    }
    
    // Check if session token exists
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['error'] = 'Session expired. Please refresh and try again.';
        header("Location: $redirect_url");
        exit();
    }
    
    // Use hash_equals to prevent timing attacks
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error'] = $error_message;
        header("Location: $redirect_url");
        exit();
    }
    
    return true;
}

/**
 * Validates CSRF token for GET requests (sensitive operations)
 * @param string $redirect_url Where to redirect on failure
 * @param string $error_message Custom error message
 * @return bool True if valid, exits on failure
 */
function validate_csrf_token_get(string $redirect_url = 'dashboardX.php', string $error_message = 'Invalid security token.') {
    if (!isset($_GET['csrf_token']) || empty($_SESSION['csrf_token'])) {
        $_SESSION['error'] = $error_message;
        header("Location: $redirect_url");
        exit();
    }
    
    if (!hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) {
        $_SESSION['error'] = $error_message;
        header("Location: $redirect_url");
        exit();
    }
    
    return true;
}

/**
 * Check if user has required role
 * @param string $required_role Required role (admin, employee, dispatch)
 * @param string $redirect_url Where to redirect if unauthorized
 * @return bool True if authorized, exits if not
 */
function require_role(string $required_role, string $redirect_url = 'login.php') {
    if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== $required_role) {
        $_SESSION['error'] = "Access denied. Required role: $required_role";
        header("Location: $redirect_url");
        exit();
    }
    return true;
}

/**
 * Get CSRF token for forms and URLs
 * @return string CSRF token
 */
function get_csrf_token(): string {
    return $_SESSION['csrf_token'] ?? '';
}

/**
 * Generate CSRF hidden input field
 * @return string HTML input field
 */
function csrf_field(): string {
    $token = htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8');
    return "<input type=\"hidden\" name=\"csrf_token\" value=\"$token\">";
}

/**
 * Add CSRF token to URL
 * @param string $url Base URL
 * @return string URL with CSRF token parameter
 */
function add_csrf_to_url(string $url): string {
    $separator = strpos($url, '?') !== false ? '&' : '?';
    return $url . $separator . 'csrf_token=' . urlencode(get_csrf_token());
}