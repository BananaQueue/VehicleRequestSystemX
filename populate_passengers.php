<?php
/**
 * populate_passengers.php
 * Run this script ONCE after creating the request_passengers table
 * to populate it with data from existing requests
 */

require_once 'db.php';

try {
    $pdo->beginTransaction();
    
    // Get all requests with passenger data
    $stmt = $pdo->query("
        SELECT id, user_id, passenger_names 
        FROM requests 
        WHERE passenger_names IS NOT NULL 
        AND passenger_names != ''
        AND passenger_names != '[]'
    ");
    
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $processedCount = 0;
    $skippedCount = 0;
    
    echo "Processing " . count($requests) . " requests...\n";
    
    foreach ($requests as $request) {
        $requestId = $request['id'];
        $userId = $request['user_id'];
        $passengerNames = json_decode($request['passenger_names'], true);
        
        if (!is_array($passengerNames)) {
            echo "Skipping request {$requestId}: Invalid passenger data\n";
            $skippedCount++;
            continue;
        }
        
        foreach ($passengerNames as $passengerName) {
            if (empty(trim($passengerName))) {
                continue;
            }
            
            // Find user by name
            $userStmt = $pdo->prepare("SELECT id, name FROM users WHERE name = :name LIMIT 1");
            $userStmt->execute(['name' => trim($passengerName)]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                echo "Warning: User '{$passengerName}' not found for request {$requestId}\n";
                continue;
            }
            
            // Insert into request_passengers
            try {
                $insertStmt = $pdo->prepare("
                    INSERT INTO request_passengers (request_id, user_id, user_name)
                    VALUES (:request_id, :user_id, :user_name)
                    ON DUPLICATE KEY UPDATE user_name = :user_name
                ");
                
                $insertStmt->execute([
                    'request_id' => $requestId,
                    'user_id' => $user['id'],
                    'user_name' => $user['name']
                ]);
                
                $processedCount++;
                echo "Added passenger: {$user['name']} to request {$requestId}\n";
            } catch (PDOException $e) {
                echo "Error adding passenger {$passengerName} to request {$requestId}: " . $e->getMessage() . "\n";
            }
        }
    }
    
    $pdo->commit();
    
    echo "\n=== Summary ===\n";
    echo "Total requests processed: " . count($requests) . "\n";
    echo "Passengers added: {$processedCount}\n";
    echo "Requests skipped: {$skippedCount}\n";
    echo "\nPassenger data population complete!\n";
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "Error: " . $e->getMessage() . "\n";
    echo "Transaction rolled back.\n";
}
?>