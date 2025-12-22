<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php'; // For CSRF and role checking
require_once __DIR__ . '/../includes/audit_log.php'; // For logging

/**
 * Handles the addition of a new entry to a specified database table.
 *
 * @param PDO $pdo The PDO database connection.
 * @param string $tableName The name of the table to insert into.
 * @param array $fields An associative array where keys are database column names and values are the corresponding POST data keys.
 * @param array $validationRules An associative array where keys are POST data keys and values are validation rules (e.g., 'required', 'email', 'min_length:8').
 * @param array $uniqueChecks An associative array where keys are database column names and values are human-readable field names for unique checks.
 * @param string $successMessage A template for the success message, expecting a %s placeholder for the entry name.
 * @param string $redirectLocation The URL to redirect to on success.
 * @param array $optionalData Optional associative array for special handling:
 *   - 'password_field' (string): The POST data key for a password field that needs hashing.
 *   - 'default_status_field' (string): The database column name for a status field to be set to 'available'.
 *   - 'conditional_insert' (callable): A function for conditional inserts (e.g., for 'employee' vs 'driver' roles).
 * @return array An associative array with 'success' (boolean) and 'errors' (array of strings).
 */
function handle_add_entry(
    PDO $pdo,
    string $tableName,
    array $fields,
    array $validationRules,
    array $uniqueChecks,
    string $successMessage,
    string $redirectLocation,
    array $optionalData = []
): array {
    $errors = [];

    // Basic CSRF validation
    validate_csrf_token_post($_SERVER['SCRIPT_NAME']);

    // Input sanitization and collection
    $data = [];
    foreach ($fields as $dbColumn => $postKey) {
        $data[$dbColumn] = htmlspecialchars(trim($_POST[$postKey] ?? ''));
    }

    // Validation
    foreach ($validationRules as $postKey => $rules) {
        $value = $_POST[$postKey] ?? '';
        foreach (explode('|', $rules) as $rule) {
            if ($rule === 'required' && empty($value)) {
                $errors[] = ucfirst(str_replace('_', ' ', $postKey)) . " is required.";
            }
            if ($rule === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Valid email is required.";
            }
            if (strpos($rule, 'min_length:') === 0) {
                $minLength = (int) explode(':', $rule)[1];
                if (strlen($value) < $minLength) {
                    $errors[] = ucfirst(str_replace('_', ' ', $postKey)) . " must be at least {$minLength} characters.";
                }
            }
            // Add more validation rules as needed
        }
    }

    if (!empty($errors)) {
        return ['success' => false, 'errors' => $errors];
    }

    try {
        // Unique checks
        foreach ($uniqueChecks as $dbColumn => $fieldName) {
            $checkStmt = $pdo->prepare("SELECT id FROM {$tableName} WHERE {$dbColumn} = :value");
            $checkStmt->execute([':value' => $data[$dbColumn]]);
            if ($checkStmt->fetch()) {
                $errors[] = "{$fieldName} already exists.";
            }
        }

        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        // If a conditional insert function is provided, use it
        if (isset($optionalData['conditional_insert']) && is_callable($optionalData['conditional_insert'])) {
            $conditionalResult = $optionalData['conditional_insert']($pdo, $data, $fields, $successMessage, $redirectLocation, $optionalData);
            if ($conditionalResult['success']) {
                // The conditional insert handled the redirection and success message
                return $conditionalResult;
            } else {
                // Conditional insert failed, return its errors
                return ['success' => false, 'errors' => $conditionalResult['errors']];
            }
        }

        // Prepare fields and placeholders for insert statement
        $insertFields = array_keys($data);
        $insertPlaceholders = array_map(fn($field) => ":{$field}", $insertFields);

        // Handle password hashing for non-conditional inserts
        if (isset($optionalData['password_field']) && !empty($_POST[$optionalData['password_field']])) {
            $data[$optionalData['password_field']] = password_hash($_POST[$optionalData['password_field']], PASSWORD_DEFAULT);
        }

        // Handle default status for non-conditional inserts
        if (isset($optionalData['default_status_field'])) {
            $insertFields[] = $optionalData['default_status_field'];
            $insertPlaceholders[] = ':' . $optionalData['default_status_field'];
            $data[$optionalData['default_status_field']] = 'available'; // Default status
        }

        $stmt = $pdo->prepare("INSERT INTO {$tableName} (" . implode(', ', $insertFields) . ") VALUES (" . implode(', ', $insertPlaceholders) . ")");

        $result = $stmt->execute($data);

        if ($result) {
            $_SESSION['success_message'] = sprintf($successMessage, $data[$fields[array_key_first($fields)]]);
            header("Location: " . $redirectLocation);
            exit();
        } else {
            $errors[] = "Failed to add entry. Please try again.";
            return ['success' => false, 'errors' => $errors];
        }

    } catch (PDOException $e) {
        error_log("Add Entry PDO Error in {$tableName}: " . $e->getMessage());
        $errors[] = "An unexpected error occurred while adding the entry.";
        return ['success' => false, 'errors' => $errors];
    }
}

