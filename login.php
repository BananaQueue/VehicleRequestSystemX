<?php
// ✅ If session is active, user should never see login page
if (isset($_SESSION['user'])) {
    header("Location: dashboardX.php");
    exit;
}


// session_name("site3_session"); // Handled in includes/session.php
require_once __DIR__ . '/includes/session.php';
$error = ''; // Initialize error variable


if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Redirect to auth.php for authentication
    header("Location: auth.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - Vehicle Request System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
</head>
<body class="login-page">
    <!-- Animated background -->
    <div class="login-bg-decoration">
        <div class="floating-shape"></div>
        <div class="floating-shape"></div>
        <div class="floating-shape"></div>
        <div class="floating-shape"></div>
    </div>

    <div class="login-wrapper">
        <div class="login-container">
            <!-- Header Section -->
            <div class="login-header">
                <div class="login-icon">
                    <i class="fas fa-car"></i>
                </div>
                <h1 class="login-title">Welcome Back</h1>
                <p class="login-subtitle">Sign in to access the Vehicle Request System</p>
            </div>
            
            <!-- Error Alert -->
            <?php if (isset($_SESSION['error'])): ?>
                <div class="login-alert" role="alert">
                    <i class="fas fa-exclamation-triangle alert-icon"></i>
                    <div>
                        <strong>Login Failed:</strong> <?= htmlspecialchars($_SESSION['error']) ?>
                    </div>
                    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <!-- Login Form -->
            <form method="POST" action="auth.php" id="loginForm">
                <?= csrf_field() ?>
                <div class="form-floating">
                    <div><i class="input-icon fas fa-envelope"></i>
                    </div>
                    <div>    
                        <input type="email" 
                            name="email" 
                            id="email" 
                            class="form-control" 
                            placeholder=" "
                            required
                            autocomplete="email">
                        <label for="email">Email Address</label>
                    </div>
                </div>

                <div class="form-floating">
                    <div><i class="input-icon fas fa-lock"></i></div>
                    <div>
                        <input type="password" 
                            name="password" 
                            id="password" 
                            class="form-control" 
                            placeholder=""
                            required
                            autocomplete="current-password">
                        <label for="password">Password</label>
                    </div>
                </div>

                <button type="submit" class="login-btn" id="loginButton">
                    <i class="fas fa-sign-in-alt me-2"></i>
                    Sign In
                </button>
            </form>

            <!-- Back to Dashboard Link -->
            <div class="back-link">
                <a href="dashboardX.php">
                    <i class="fas fa-arrow-left"></i>
                    Back to Dashboard
                </a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    
    <script>
        // Enhanced form handling
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('loginForm');
            const button = document.getElementById('loginButton');
            const alerts = document.querySelectorAll('.login-alert');

            // Auto-dismiss alerts after 5 seconds
            alerts.forEach(alert => {
                setTimeout(() => {
                    if (alert && !alert.classList.contains('fade')) {
                        const bsAlert = new bootstrap.Alert(alert);
                        if (bsAlert) bsAlert.close();
                    }
                }, 5000);
            });

            // Form submission handling
            form.addEventListener('submit', function(e) {
                // Add loading state
                button.classList.add('loading');
                button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Signing in...';
                
                // Basic client-side validation
                const email = document.getElementById('email').value.trim();
                const password = document.getElementById('password').value.trim();
                
                if (!email || !password) {
                    e.preventDefault();
                    button.classList.remove('loading');
                    button.innerHTML = '<i class="fas fa-sign-in-alt me-2"></i>Sign In';
                    
                    // Show validation error (you could enhance this with a proper alert)
                    alert('Please fill in all required fields.');
                    return false;
                }
            });

            // Input focus enhancements
            const inputs = document.querySelectorAll('.form-floating input');
            inputs.forEach(input => {
                // Add visual feedback for validation
                input.addEventListener('blur', function() {
                    if (this.validity.valid && this.value.trim() !== '') {
                        this.classList.add('is-valid');
                        this.classList.remove('is-invalid');
                    } else if (this.value.trim() !== '') {
                        this.classList.add('is-invalid');
                        this.classList.remove('is-valid');
                    }
                });

                // Clear validation classes on input
                input.addEventListener('input', function() {
                    this.classList.remove('is-valid', 'is-invalid');
                });
            });

            // Keyboard accessibility
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && e.target.matches('.form-floating input')) {
                    const inputs = Array.from(document.querySelectorAll('.form-floating input'));
                    const currentIndex = inputs.indexOf(e.target);
                    
                    if (currentIndex < inputs.length - 1) {
                        e.preventDefault();
                        inputs[currentIndex + 1].focus();
                    }
                }
            });
        });
        // Force reload if page is loaded from back/forward cache (bfcache)
        window.addEventListener('pageshow', function(event) {
            if (event.persisted || performance.getEntriesByType("navigation")[0].type === "back_forward") {
                window.location.reload();
            }
        });
    </script>
</body>
</html>