<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php'; // For CSRF and role checking
require_once __DIR__ . '/../includes/audit_log.php'; // For logging

/**
 * Handles the editing of an existing entry in a specified database table.
 *
 * @param PDO $pdo The PDO database connection.
 * @param string $tableName The name of the table to update.
 * @param array $fields An associative array where keys are database column names and values are the corresponding POST data keys.
 * @param array $validationRules An associative array where keys are POST data keys and values are validation rules (e.g., 'required', 'email', 'min_length:8').
 * @param array $uniqueChecks An associative array where keys are database column names and values are human-readable field names for unique checks (excluding the current entry).
 * @param string $successMessage A template for the success message, expecting a %s placeholder for the entry name.
 * @param string $redirectLocation The URL to redirect to on success.
 * @param array $optionalData Optional associative array for special handling:
 *   - 'id_field' (string): The POST/GET data key for the ID (defaults to 'id').
 *   - 'id_column' (string): The database column name for the ID (defaults to 'id').
 *   - 'password_field' (string): The POST data key for a password field that needs hashing.
 *   - 'fetch_sql' (string): Custom SQL query to fetch the existing entry (e.g., "SELECT * FROM users WHERE id = :id"). If not provided, a default query is used.
 *   - 'conditional_update' (callable): A function for conditional updates (e.g., for 'employee' vs 'driver' roles).
 * @return array An associative array with 'success' (boolean) and 'errors' (array of strings).
 */
function handle_edit_entry(
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
    $idField = $optionalData['id_field'] ?? 'id';
    $idColumn = $optionalData['id_column'] ?? 'id';

    // Validate ID
    $id = $_GET[$idField] ?? null;
    if (!filter_var($id, FILTER_VALIDATE_INT)) {
        $_SESSION['error_message'] = "Invalid ID.";
        header("Location: " . $redirectLocation);
        exit();
    }

    // Fetch current data
    $currentEntry = null;
    try {
        if (isset($optionalData['fetch_sql'])) {
            $stmt = $pdo->prepare($optionalData['fetch_sql']);
            $stmt->execute([':id' => $id]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM {$tableName} WHERE {$idColumn} = :id");
            $stmt->execute([':id' => $id]);
        }
        $currentEntry = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Edit Entry Fetch Error in {$tableName}: " . $e->getMessage());
        $_SESSION['error_message'] = "Error fetching data.";
        header("Location: " . $redirectLocation);
        exit();
    }

    if (!$currentEntry) {
        $_SESSION['error_message'] = "Entry not found.";
        header("Location: " . $redirectLocation);
        exit();
    }

    // Set initial form values from current entry
    foreach ($fields as $dbColumn => $postKey) {
        if (!isset($_POST[$postKey])) { // Only pre-fill if form not submitted
            $_POST[$postKey] = $currentEntry[$dbColumn] ?? '';
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            // Unique checks (excluding current entry)
            foreach ($uniqueChecks as $dbColumn => $fieldName) {
                $checkStmt = $pdo->prepare("SELECT {$idColumn} FROM {$tableName} WHERE {$dbColumn} = :value AND {$idColumn} != :id");
                $checkStmt->execute([':value' => $data[$dbColumn], ':id' => $id]);
                if ($checkStmt->fetch()) {
                    $errors[] = "{$fieldName} already exists.";
                }
            }

            if (!empty($errors)) {
                return ['success' => false, 'errors' => $errors];
            }

            // Handle conditional update if provided
            if (isset($optionalData['conditional_update']) && is_callable($optionalData['conditional_update'])) {
                $conditionalResult = $optionalData['conditional_update']($pdo, $id, $data, $currentEntry, $fields, $successMessage, $redirectLocation, $optionalData);
                if ($conditionalResult['success']) {
                    return $conditionalResult;
                } else {
                    return ['success' => false, 'errors' => array_merge($errors, $conditionalResult['errors'])];
                }
            }

            // Prepare fields for update statement
            $updateFields = [];
            foreach ($data as $dbColumn => $value) {
                // Only update fields that have changed
                if ($value !== ($currentEntry[$dbColumn] ?? null)) {
                     $updateFields[] = "{$dbColumn} = :{$dbColumn}";
                }
            }

            // Handle password hashing for non-conditional updates
            if (isset($optionalData['password_field']) && !empty($_POST[$optionalData['password_field']])) {
                $data[$optionalData['password_field']] = password_hash($_POST[$optionalData['password_field']], PASSWORD_DEFAULT);
                 $updateFields[] = "{$optionalData['password_field']} = :{$optionalData['password_field']}";
            } else if (isset($optionalData['password_field']) && empty($_POST[$optionalData['password_field']])) {
                 // If password field is empty, remove it from update fields to prevent hashing an empty string
                 unset($data[$optionalData['password_field']]);
            }

            if (empty($updateFields)) {
                $_SESSION['warning_message'] = "No changes detected for this entry.";
                header("Location: " . $redirectLocation);
                exit();
            }

            $stmt = $pdo->prepare("UPDATE {$tableName} SET " . implode(', ', $updateFields) . " WHERE {$idColumn} = :id");
            $data['id'] = $id; // Add ID to data for execution
            
            // Remove unchanged data from $data to avoid 'no such parameter' error
            $finalData = [];
            foreach ($updateFields as $fieldClause) {
                $dbCol = explode(' = ', $fieldClause)[0];
                $finalData[$dbCol] = $data[$dbCol];
            }
            $finalData['id'] = $id;

            $result = $stmt->execute($finalData);

            if ($result) {
                $_SESSION['success_message'] = sprintf($successMessage, $data[$fields[array_key_first($fields)]]);
                header("Location: " . $redirectLocation);
                exit();
            } else {
                $errors[] = "Failed to update entry. Please try again.";
                return ['success' => false, 'errors' => $errors];
            }

        } catch (PDOException $e) {
            error_log("Edit Entry PDO Error in {$tableName}: " . $e->getMessage());
            $errors[] = "An unexpected error occurred while updating the entry.";
            return ['success' => false, 'errors' => $errors];
        }
    }
    
    return ['success' => true, 'current_entry' => $currentEntry];
}


