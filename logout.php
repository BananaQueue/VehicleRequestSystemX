<?php
require_once __DIR__ . '/includes/session.php'; // Ensure session is started and configured

// Destroy all session variables to log the user out
session_destroy();

// Redirect the user to the public dashboard after logout
header('Location: dashboardX.php');
exit;
