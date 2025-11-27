<?php
require_once __DIR__ . '/includes/session.php';
require 'db.php';

// Check if user is logged in and is an employee
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'employee') {
    $_SESSION['error'] = "Access denied. You must be logged in as an employee to request a vehicle.";
    header("Location: login.php");
    exit();
}

// Define variables
$user_id = $_SESSION['user']['id'];
$requestor_name = $_SESSION['user']['name'];
$requestor_email = $_SESSION['user']['email'];
$isEmployee = true; // FIXED: Define this variable
$username = $requestor_name; // FIXED: Define username

$errors = [];
$success = '';

function validatePassengerAvailability($pdo, $passengerName, $excludeRequestId = null) {
    // Check if passenger has active request (not concluded ones)
    $stmt = $pdo->prepare("
        SELECT id, status, departure_date, return_date
        FROM requests 
        WHERE user_id = (SELECT id FROM users WHERE name = :name)
        AND status IN ('pending_dispatch_assignment', 'pending_admin_approval', 'approved')
        AND departure_date >= CURDATE()  -- ADDED: Only future/current trips
        LIMIT 1
    ");
    $stmt->execute(['name' => $passengerName]);
    $activeRequest = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($activeRequest) {
        return [
            'available' => false,
            'reason' => 'has_active_request',
            'message' => 'has an active vehicle request'
        ];
    }
    
    // Check for assigned vehicle
    $stmt = $pdo->prepare("
        SELECT plate_number FROM vehicles 
        WHERE assigned_to = :name AND status = 'assigned'
        LIMIT 1
    ");
    $stmt->execute(['name' => $passengerName]);
    $assignedVehicle = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($assignedVehicle) {
        return [
            'available' => false,
            'reason' => 'has_assigned_vehicle',
            'message' => 'has an assigned vehicle (' . $assignedVehicle['plate_number'] . ')'
        ];
    }
    
    // Check for returning vehicle
    $stmt = $pdo->prepare("
        SELECT plate_number FROM vehicles 
        WHERE returned_by = :name AND status = 'returning'
        LIMIT 1
    ");
    $stmt->execute(['name' => $passengerName]);
    $returningVehicle = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($returningVehicle) {
        return [
            'available' => false,
            'reason' => 'returning_vehicle',
            'message' => 'is returning a vehicle (' . $returningVehicle['plate_number'] . ')'
        ];
    }
    
    return ['available' => true, 'message' => 'Available'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_token_post(); // Validate CSRF token for POST requests

    $destination = trim($_POST['destination'] ?? '');
    $purpose = trim($_POST['purpose'] ?? '');
    $departure_date = trim($_POST['departure_date'] ?? '');
    $return_date = trim($_POST['return_date'] ?? '');
    $passenger_names = $_POST['passenger_names'] ?? [];

    if (empty($destination)) {
        $errors[] = "Destination is required.";
    }
    if (empty($purpose)) {
        $errors[] = "Purpose is required.";
    }
    if (empty($departure_date)) {
        $errors[] = "Departure date is required.";
    } elseif (strtotime($departure_date) < strtotime('today')) {
        $errors[] = "Departure date must be today or in the future.";
    }
    if (empty($return_date)) {
        $errors[] = "Return date is required.";
    } elseif (strtotime($return_date) < strtotime($departure_date)) {
        $errors[] = "Return date must be on or after departure date.";
    }
    if (count($passenger_names) < 1) {
        $errors[] = "At least 1 passenger is required.";
    }
    
    // ADDED: Validate passenger availability
    if (!empty($passenger_names)) {
        foreach ($passenger_names as $passenger) {
            // Skip validation for the requestor
            if ($passenger === $requestor_name) {
                continue;
            }
            
            $validation = validatePassengerAvailability($pdo, $passenger);
            if (!$validation['available']) {
                $errors[] = "Passenger '" . htmlspecialchars($passenger) . "' cannot be added: " . $validation['message'];
            }
        }
    }

    if (empty($errors)) {
        try {
            $passenger_count = count($passenger_names);
            $stmt = $pdo->prepare("INSERT INTO requests (user_id, requestor_name, requestor_email, destination, purpose, departure_date, return_date, passenger_count, passenger_names, status) VALUES (:user_id, :requestor_name, :requestor_email, :destination, :purpose, :departure_date, :return_date, :passenger_count, :passenger_names, 'pending_dispatch_assignment')");
            $stmt->execute([
                ':user_id' => $user_id,
                ':requestor_name' => htmlspecialchars($requestor_name, ENT_QUOTES, 'UTF-8'),
                ':requestor_email' => htmlspecialchars($requestor_email, ENT_QUOTES, 'UTF-8'),
                ':destination' => htmlspecialchars($destination, ENT_QUOTES, 'UTF-8'),
                ':purpose' => htmlspecialchars($purpose, ENT_QUOTES, 'UTF-8'),
                ':departure_date' => $departure_date,
                ':return_date' => $return_date,
                ':passenger_count' => $passenger_count,
                ':passenger_names' => json_encode($passenger_names)
            ]);

            $_SESSION['success'] = "Vehicle request submitted successfully. Awaiting dispatch assignment.";
            header("Location: dashboardX.php");
            exit();
        } catch (PDOException $e) {
            error_log("Request Submission Error: " . $e->getMessage(), 0);
            $_SESSION['error'] = "An unexpected error occurred. Please try again.";
            header("Location: dashboardX.php");
            exit();
        }
    } else {
        // If there are errors, store them in session to display on current page
        $_SESSION['errors'] = $errors;
        // Also store the POST data to repopulate the form
        $_SESSION['old_post'] = $_POST;
        header("Location: create_request.php");
        exit();
    }
}

// Retrieve errors and old POST data from session if redirected back
$errors = $_SESSION['errors'] ?? [];
unset($_SESSION['errors']);
$old_post = $_SESSION['old_post'] ?? [];
unset($_SESSION['old_post']);

// FIXED: Initialize form variables
$destination = $old_post['destination'] ?? '';
$purpose = $old_post['purpose'] ?? '';
$departure_date = $old_post['departure_date'] ?? '';
$return_date = $old_post['return_date'] ?? '';

// Fetch employees with availability status for passenger selection
$employees = [];
try {
    $stmt = $pdo->query("SELECT id, name, email, position FROM users WHERE role = 'employee' ORDER BY name ASC");
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ADDED: Add availability status to each employee
    foreach ($employees as &$employee) {
        // Don't validate the requestor themselves
        if ($employee['name'] === $requestor_name) {
            $employee['is_available'] = true;
            $employee['unavailable_reason'] = '';
            continue;
        }
        
        $validation = validatePassengerAvailability($pdo, $employee['name']);
        $employee['is_available'] = $validation['available'];
        $employee['unavailable_reason'] = $validation['message'] ?? '';
    }
    unset($employee); // Break reference
} catch (PDOException $e) {
    error_log("Error fetching employees: " . $e->getMessage());
    $employees = [];
}

// FIXED: Check if employee is a passenger in another request
$showPassengerWarning = false;
$passengerWarningDetails = null;

if ($isEmployee) {
    $stmt = $pdo->prepare("
        SELECT r.id, r.requestor_name, r.status, r.departure_date
        FROM requests r 
        WHERE r.status IN ('pending_dispatch_assignment', 'pending_admin_approval', 'approved')
        AND r.departure_date >= CURDATE()  -- ADDED: Only future/current trips
        AND JSON_SEARCH(r.passenger_names, 'one', :username) IS NOT NULL
    ");
    $stmt->execute(['username' => $username]);
    $passengerInRequest = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($passengerInRequest) {
        $showPassengerWarning = true;
        $passengerWarningDetails = $passengerInRequest;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Request Vehicle</title>
    <link rel="stylesheet" href="styles.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
</head>
<body>
    <div class="d-flex justify-content-center align-items-center vh-100 bg-light bg-light-subtle">
        <div class="container">
            <div class="text-center mb-4">
                <h2>Request a Vehicle</h2>
                <p>Fill out the form below to submit your vehicle request.</p>
            </div>
            
            <?php if (isset($showPassengerWarning) && $showPassengerWarning): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Notice:</strong> You are currently listed as a passenger in 
                <strong><?= htmlspecialchars($passengerWarningDetails['requestor_name']) ?></strong>'s vehicle request.
                Please coordinate your travel plans accordingly.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
        
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php foreach ($errors as $error): ?>
                        <?= htmlspecialchars($error) ?><br>
                    <?php endforeach; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= $_SESSION['success_message'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= $_SESSION['error_message'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <h2 class="mb-4">Create Vehicle Request</h2>

            <form action="create_request.php" method="POST">
                <?= csrf_field() ?>
                <div class="d-flex">
                    <div class="input-group-icon mb-3 col-6 p-3">
                        <div class="input-group-icon mb-3">
                            <label for="destination" class="form-label">Destination</label>
                            <input type="text" class="form-control text-dark" id="destination" name="destination" value="<?= htmlspecialchars($destination) ?>" required>
                            <?php if (isset($errors['destination'])): ?>
                                <div class="text-danger"><?= htmlspecialchars($errors['destination']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class= "input-group-icon">
                            <label for="purpose" class="form-label">Purpose</label>
                            <textarea class="form-control text-dark" id="purpose" name="purpose" rows="3" required><?= htmlspecialchars($purpose) ?></textarea>
                            <?php if (isset($errors['purpose'])): ?>
                                <div class="text-danger"><?= htmlspecialchars($errors['purpose']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="input-group-icon col-6">
                        <div class="row mb-3 p-3">
                            <div class="col-md-6">
                                <label for="departure_date" class="form-label">Departure Date</label>
                                <input type="date" class="form-control text-dark" id="departure_date" name="departure_date" value="<?= htmlspecialchars($departure_date) ?>" min="<?= date('Y-m-d') ?>" required>
                                <?php if (isset($errors['departure_date'])): ?>
                                    <div class="text-danger"><?= htmlspecialchars($errors['departure_date']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <label for="return_date" class="form-label">Return Date</label>
                                <input type="date" class="form-control text-dark" id="return_date" name="return_date" value="<?= htmlspecialchars($return_date) ?>" min="<?= date('Y-m-d') ?>" required>
                                <?php if (isset($errors['return_date'])): ?>
                                    <div class="text-danger"><?= htmlspecialchars($errors['return_date']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                
                        <div class="mb-3 p-3">
                            <label class="form-label">Add Passengers</label>
                            <div class="input-group mb-2">
                                <input type="text" class="form-control text-dark" id="passenger_search" placeholder="Search for employees..." autocomplete="off">
                                <button class="btn btn-outline-secondary" type="button" id="add_passenger_btn" disabled>
                                    <i class="fas fa-plus"></i> Add
                                </button>
                            </div>
                            <div id="passenger_suggestions" class="list-group" style="display: none; max-height: 200px; overflow-y: auto;"></div>
                        </div>
                    </div>
                </div> <!-- Close d-flex -->
                
                <div id="selected_passengers" class="mt-3">
                    <div class="d-flex align-items-center mb-2">
                        <span class="badge bg-primary me-2"><?= htmlspecialchars($requestor_name) ?> (You)</span>
                        <input type="hidden" name="passenger_names[]" value="<?= htmlspecialchars($requestor_name) ?>">
                    </div>
                </div>
            
                <small class="form-text text-muted">Search and add passengers for this trip. You are automatically included.</small>
                <?php if (isset($errors['passenger_names'])): ?>
                    <div class="text-danger"><?= htmlspecialchars($errors['passenger_names']) ?></div>
                <?php endif; ?>
                
                <div class="text-center mb-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-plus me-2"></i>Submit Request
                    </button>
                </div>
            </form>

            <div class="text-center mt-3">
                <a href="dashboardX.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                </a>
            </div>
        </div> <!-- Close container -->
    </div> <!-- Close d-flex wrapper -->

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
            }, 5000);
        });

        // Employee data for search
        var employees = <?= json_encode($employees) ?>;
        var selectedPassengers = ['<?= htmlspecialchars($requestor_name) ?>'];
        
        // Basic client-side validation
        var form = document.querySelector('form');
        form.addEventListener('submit', function(e) {
            var destination = document.getElementById('destination').value.trim();
            var purpose = document.getElementById('purpose').value.trim();
            var departureDate = document.getElementById('departure_date').value;
            var returnDate = document.getElementById('return_date').value;
            var selectedPassengerInputs = document.querySelectorAll('input[name="passenger_names[]"]');
            
            if (destination === '') {
                e.preventDefault();
                alert('Destination is required.');
                return false;
            }

            if (purpose === '') {
                e.preventDefault();
                alert('Purpose is required.');
                return false;
            }
            
            if (departureDate === '') {
                e.preventDefault();
                alert('Departure date is required.');
                return false;
            }
            
            if (returnDate === '') {
                e.preventDefault();
                alert('Return date is required.');
                return false;
            }
            
            if (selectedPassengerInputs.length < 1) {
                e.preventDefault();
                alert('At least 1 passenger is required.');
                return false;
            }
        });
        
        // Date validation
        var departureDateInput = document.getElementById('departure_date');
        var returnDateInput = document.getElementById('return_date');
        
        departureDateInput.addEventListener('change', function() {
            var departureDate = new Date(this.value);
            var returnDate = new Date(returnDateInput.value);
            
            if (returnDateInput.value && returnDate < departureDate) {
                returnDateInput.setCustomValidity('Return date must be on or after departure date');
            } else {
                returnDateInput.setCustomValidity('');
            }
        });
        
        returnDateInput.addEventListener('change', function() {
            var departureDate = new Date(departureDateInput.value);
            var returnDate = new Date(this.value);
            
            if (departureDateInput.value && returnDate < departureDate) {
                this.setCustomValidity('Return date must be on or after departure date');
            } else {
                this.setCustomValidity('');
            }
        });
        
        // Passenger search functionality
        var passengerSearchInput = document.getElementById('passenger_search');
        var passengerSuggestions = document.getElementById('passenger_suggestions');
        var addPassengerBtn = document.getElementById('add_passenger_btn');
        var selectedPassengersDiv = document.getElementById('selected_passengers');
        
        // Filter employees based on search
        function filterEmployees(searchTerm) {
            return employees.filter(function(employee) {
                var name = employee.name.toLowerCase();
                var position = employee.position.toLowerCase();
                var search = searchTerm.toLowerCase();
                return (name.includes(search) || position.includes(search)) && 
                       !selectedPassengers.includes(employee.name);
            });
        }
        
        // Show suggestions with availability status
        function showSuggestions(suggestions) {
            passengerSuggestions.innerHTML = '';
            if (suggestions.length === 0) {
                passengerSuggestions.style.display = 'none';
                return;
            }
            
            suggestions.forEach(function(employee) {
                var suggestionItem = document.createElement('div');
                suggestionItem.className = 'list-group-item list-group-item-action';
                
                // Check if employee is available
                if (!employee.is_available) {
                    suggestionItem.className += ' disabled';
                    suggestionItem.style.opacity = '0.6';
                    suggestionItem.style.cursor = 'not-allowed';
                    suggestionItem.innerHTML = 
                        '<strong>' + employee.name + '</strong> - ' + employee.position +
                        '<br><small class="text-danger"><i class="fas fa-exclamation-circle"></i> ' + 
                        employee.unavailable_reason + '</small>';
                } else {
                    suggestionItem.innerHTML = '<strong>' + employee.name + '</strong> - ' + employee.position;
                    suggestionItem.addEventListener('click', function() {
                        addPassenger(employee);
                    });
                }
                
                passengerSuggestions.appendChild(suggestionItem);
            });
            passengerSuggestions.style.display = 'block';
        }
        
        // Add passenger to selected list
        function addPassenger(employee) {
            if (selectedPassengers.includes(employee.name)) {
                return;
            }
            
            // Check if employee is available
            if (!employee.is_available) {
                alert('Cannot add ' + employee.name + ': ' + employee.unavailable_reason);
                return;
            }
            
            selectedPassengers.push(employee.name);
            
            var passengerItem = document.createElement('div');
            passengerItem.className = 'd-flex align-items-center mb-2';
            passengerItem.innerHTML = 
                '<span class="badge bg-secondary me-2">' + employee.name + '</span>' +
                '<button type="button" class="btn btn-sm btn-outline-danger" onclick="removePassenger(this, \'' + employee.name + '\')">' +
                '<i class="fas fa-times"></i></button>' +
                '<input type="hidden" name="passenger_names[]" value="' + employee.name + '">';
            selectedPassengersDiv.appendChild(passengerItem);
            
            passengerSearchInput.value = '';
            passengerSuggestions.style.display = 'none';
            updateAddButton();
        }
        
        // Remove passenger
        window.removePassenger = function(button, passengerName) {
            selectedPassengers = selectedPassengers.filter(function(name) {
                return name !== passengerName;
            });
            button.parentElement.remove();
            updateAddButton();
        };
        
        // Update add button state
        function updateAddButton() {
            var searchTerm = passengerSearchInput.value.trim();
            if (searchTerm.length < 2) {
                addPassengerBtn.disabled = true;
                return;
            }
            
            var suggestions = filterEmployees(searchTerm);
            // Only enable if there are available employees
            var hasAvailable = suggestions.some(function(emp) { return emp.is_available; });
            addPassengerBtn.disabled = !hasAvailable;
        }
        
        // Search input event
        passengerSearchInput.addEventListener('input', function() {
            var searchTerm = this.value.trim();
            if (searchTerm.length < 2) {
                passengerSuggestions.style.display = 'none';
                updateAddButton();
                return;
            }
            
            var suggestions = filterEmployees(searchTerm);
            showSuggestions(suggestions);
            updateAddButton();
        });
        
        // Add button click
        addPassengerBtn.addEventListener('click', function() {
            var searchTerm = passengerSearchInput.value.trim();
            if (searchTerm.length < 2) {
                return;
            }
            
            var suggestions = filterEmployees(searchTerm);
            var availableSuggestion = suggestions.find(function(emp) { return emp.is_available; });
            
            if (availableSuggestion) {
                addPassenger(availableSuggestion);
            }
        });
        
        // Hide suggestions when clicking outside
        document.addEventListener('click', function(e) {
            if (!passengerSearchInput.contains(e.target) && !passengerSuggestions.contains(e.target)) {
                passengerSuggestions.style.display = 'none';
            }
        });
        
        // Initialize - disable button on load
        updateAddButton();
    });
    </script>
</body>
</html>