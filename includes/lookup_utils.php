<?php
/**
 * Lookup Utilities
 * Helper functions for building vehicle/driver lookup arrays and status display
 */

/**
 * Get human-readable status text
 * Consolidated from getStatusText() and getStatusTextDispatch()
 * 
 * @param string $status Status code
 * @return string Human-readable status text
 */
function get_status_display_text(string $status): string {
    $statusMap = [
        'pending_dispatch_assignment' => 'Awaiting Dispatch',
        'pending_admin_approval' => 'Awaiting Admin Approval',
        'approved' => 'Approved',
        'concluded' => 'Concluded',
        'cancelled' => 'Cancelled',
        'rejected_new_request' => 'Rejected (New Request Needed)',
        'rejected_reassign_dispatch' => 'Rejected (Reassign Dispatch)',
        'rejected' => 'Rejected'
    ];
    
    return $statusMap[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

/**
 * Get CSS class for status badge
 * 
 * @param string $status Status code (request or vehicle status)
 * @return string CSS class name
 */
function get_status_badge_class(string $status): string {
    $classMap = [
        // Request statuses
        'pending_dispatch_assignment' => 'status-returning',
        'pending_admin_approval' => 'status-returning',
        'approved' => 'status-available',
        'concluded' => 'status-available',
        'cancelled' => 'status-assigned',
        'rejected_new_request' => 'status-assigned',
        'rejected_reassign_dispatch' => 'status-returning',
        'rejected' => 'status-assigned',
        
        // Vehicle statuses
        'available' => 'status-available',
        'assigned' => 'status-assigned',
        'returning' => 'status-returning',
        'maintenance' => 'status-maintenance'
    ];
    
    return $classMap[$status] ?? 'status-available';
}

/**
 * Render status badge HTML
 * 
 * @param string $status Status code
 * @param bool $showIcon Whether to show icon
 * @return string HTML badge
 */
function render_status_badge(string $status, bool $showIcon = true): string {
    $class = get_status_badge_class($status);
    $text = get_status_display_text($status);
    
    $icons = [
        'status-available' => 'fa-check-circle',
        'status-assigned' => 'fa-user',
        'status-returning' => 'fa-clock',
        'status-maintenance' => 'fa-tools'
    ];
    
    $icon = $showIcon && isset($icons[$class]) 
        ? '<i class="fas ' . $icons[$class] . ' me-1"></i>' 
        : '';
    
    return '<span class="status-badge ' . htmlspecialchars($class) . '">' 
         . $icon 
         . htmlspecialchars($text) 
         . '</span>';
}

/**
 * Build lookup arrays for vehicles and drivers from multiple request arrays
 * 
 * @param PDO $pdo Database connection
 * @param array $requestArrays Array of request arrays to process
 * @return array ['vehicles' => [...], 'drivers' => [...]]
 * 
 * Example usage:
 * $lookups = build_request_lookups($pdo, [$myRequests, $adminRequests]);
 * $vehicleLookup = $lookups['vehicles'];
 * $driverLookup = $lookups['drivers'];
 */
function build_request_lookups(PDO $pdo, array $requestArrays): array {
    $vehicleIds = [];
    $driverIds = [];
    
    // Collect all vehicle and driver IDs from request arrays
    foreach ($requestArrays as $requests) {
        if (is_array($requests)) {
            $vehicleIds = array_merge($vehicleIds, array_column($requests, 'assigned_vehicle_id'));
            $driverIds = array_merge($driverIds, array_column($requests, 'assigned_driver_id'));
        }
    }
    
    // Remove duplicates and null values
    $vehicleIds = array_values(array_filter(array_unique($vehicleIds)));
    $driverIds = array_values(array_filter(array_unique($driverIds)));
    
    $vehicleLookup = [];
    $driverLookup = [];
    
    // Fetch vehicle data
    if (!empty($vehicleIds)) {
        try {
            $placeholders = implode(',', array_fill(0, count($vehicleIds), '?'));
            $stmt = $pdo->prepare("SELECT id, plate_number FROM vehicles WHERE id IN ($placeholders)");
            $stmt->execute($vehicleIds);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $vehicleLookup[$row['id']] = $row['plate_number'];
            }
        } catch (PDOException $e) {
            error_log("Vehicle Lookup Error: " . $e->getMessage());
        }
    }
    
    // Fetch driver data
    if (!empty($driverIds)) {
        try {
            $placeholders = implode(',', array_fill(0, count($driverIds), '?'));
            $stmt = $pdo->prepare("SELECT id, name FROM users WHERE id IN ($placeholders) AND role = 'driver'");
            $stmt->execute($driverIds);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $driverLookup[$row['id']] = $row['name'];
            }
        } catch (PDOException $e) {
            error_log("Driver Lookup Error: " . $e->getMessage());
        }
    }
    
    return [
        'vehicles' => $vehicleLookup,
        'drivers' => $driverLookup
    ];
}

/**
 * Get vehicle plate number by ID
 * 
 * @param array $vehicleLookup Vehicle lookup array
 * @param int|null $vehicleId Vehicle ID
 * @param string $default Default value if not found
 * @return string Vehicle plate number or default
 */
function get_vehicle_plate(array $vehicleLookup, ?int $vehicleId, string $default = '----'): string {
    if ($vehicleId && isset($vehicleLookup[$vehicleId])) {
        return $vehicleLookup[$vehicleId];
    }
    return $default;
}

/**
 * Get driver name by ID
 * 
 * @param array $driverLookup Driver lookup array
 * @param int|null $driverId Driver ID
 * @param string $default Default value if not found
 * @return string Driver name or default
 */
function get_driver_name(array $driverLookup, ?int $driverId, string $default = '----'): string {
    if ($driverId && isset($driverLookup[$driverId])) {
        return $driverLookup[$driverId];
    }
    return $default;
}

/**
 * Format passenger names from JSON
 * 
 * @param string|null $passengerNamesJson JSON encoded passenger names
 * @param string $default Default value if empty
 * @return string Formatted passenger names
 */
function format_passenger_names(?string $passengerNamesJson, string $default = '----'): string {
    if (empty($passengerNamesJson)) {
        return $default;
    }
    
    $passengers = json_decode($passengerNamesJson, true);
    
    if (is_array($passengers) && !empty($passengers)) {
        return implode(', ', $passengers);
    }
    
    return $passengerNamesJson;
}

/**
 * Get vehicle and driver display info for a request
 * 
 * @param array $request Request data
 * @param array $vehicleLookup Vehicle lookup array
 * @param array $driverLookup Driver lookup array
 * @return array ['vehicle' => '...', 'driver' => '...']
 */
function get_request_assignment_display(array $request, array $vehicleLookup, array $driverLookup): array {
    $vehicleDisplay = '----';
    $driverDisplay = '----';
    
    // Handle pending admin approval status
    if ($request['status'] === 'pending_admin_approval') {
        if ($request['assigned_vehicle_id']) {
            $vehicleDisplay = 'Pending Admin Approval';
        }
        if ($request['assigned_driver_id']) {
            $driverDisplay = 'Pending Admin Approval';
        }
    } else {
        // Normal display
        $vehicleDisplay = get_vehicle_plate($vehicleLookup, $request['assigned_vehicle_id']);
        $driverDisplay = get_driver_name($driverLookup, $request['assigned_driver_id']);
    }
    
    return [
        'vehicle' => $vehicleDisplay,
        'driver' => $driverDisplay
    ];
}