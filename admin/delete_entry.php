<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php'; // For role checking and CSRF

/**
 * Handles the deletion of an entry from a specified database table.
 *
 * @param PDO $pdo The PDO database connection.
 * @param string $tableName The name of the table to delete from.
 * @param string $idColumn The name of the ID column in the table (defaults to 'id').
 * @param string $nameColumn The name of the column containing the entry's name for success messages.
 * @param string $redirectLocation The URL to redirect to after deletion.
 * @param array $optionalData Optional associative array for special handling:
 *   - 'pre_delete_action' (callable): A function to execute before the actual deletion (e.g., unassigning a driver).
 * @return void This function handles redirection and exits.
 */
function handle_delete_entry(
    PDO $pdo,
    string $tableName,
    string $idColumn,
    string $nameColumn,
    string $redirectLocation,
    array $optionalData = []
): void {
    // Ensure only POST requests are allowed and validate CSRF
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $_SESSION['error_message'] = "Invalid request method.";
        header("Location: " . $redirectLocation);
        exit();
    }
    validate_csrf_token_post($redirectLocation, 'Invalid security token.');

    // Validate ID
    $id = $_POST['id'] ?? null;
    if (!filter_var($id, FILTER_VALIDATE_INT)) {
        $_SESSION['error_message'] = "Invalid ID.";
        header("Location: " . $redirectLocation);
        exit();
    }

    try {
        $pdo->beginTransaction();

        // Fetch item info for pre-delete action and success message
        $stmt = $pdo->prepare("SELECT {$nameColumn} FROM {$tableName} WHERE {$idColumn} = ?");
        $stmt->execute([$id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            $pdo->rollBack();
            $_SESSION['error_message'] = "Entry not found.";
            header("Location: " . $redirectLocation);
            exit();
        }

        // Execute pre-delete action if provided
        if (isset($optionalData['pre_delete_action']) && is_callable($optionalData['pre_delete_action'])) {
            $preDeleteResult = $optionalData['pre_delete_action']($pdo, $id, $item);
            if (!$preDeleteResult['success']) {
                $pdo->rollBack();
                $_SESSION['error_message'] = $preDeleteResult['message'];
                header("Location: " . $redirectLocation);
                exit();
            }
        }

        // Delete the entry
        $stmt = $pdo->prepare("DELETE FROM {$tableName} WHERE {$idColumn} = ?");
        $result = $stmt->execute([$id]);

        if ($result) {
            $pdo->commit();
            $_SESSION['success_message'] = ucfirst($tableName) . " '" . htmlspecialchars($item[$nameColumn]) . "' deleted successfully.";
        } else {
            $pdo->rollBack();
            $_SESSION['error_message'] = "Failed to delete " . strtolower($tableName) . ". Please try again.";
        }

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Delete Entry PDO Error in {$tableName}: " . $e->getMessage());
        $_SESSION['error_message'] = "An unexpected error occurred while deleting the " . strtolower($tableName) . ".";
    }

    header("Location: " . $redirectLocation);
    exit();
}

