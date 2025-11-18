<?php
// Prevent default PHP error output
ini_set('display_errors', 0);
error_reporting(E_ALL);

function customErrorHandler($errno, $errstr, $errfile, $errline) {
    $class = match ($errno) {
        E_USER_ERROR, E_ERROR     => "php-error fatal",
        E_USER_WARNING, E_WARNING => "php-error warning",
        E_USER_NOTICE, E_NOTICE   => "php-error notice",
        default => "php-error",
    };

    echo "<div class='{$class}'>";
    echo "<button class='php-error-close' onclick='this.parentElement.remove()'>&times;</button>";
    echo "<strong>" . ucfirst($class) . "</strong>: $errstr <br>";
    echo "<small>$errfile on line $errline</small>";
    echo "</div>";
}
set_error_handler("customErrorHandler");
