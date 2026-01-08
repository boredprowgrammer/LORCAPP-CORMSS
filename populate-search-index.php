<?php
/**
 * Populate Search Index for CFO Records
 * This script populates the search_name and search_registry columns for faster searching
 */

require_once __DIR__ . '/config/config.php';

Security::requireLogin();

// Only admins can run this
$currentUser = getCurrentUser();
if ($currentUser['role'] !== 'admin') {
    die('Access denied. Only administrators can run this script.');
}

$db = Database::getInstance()->getConnection();

echo "<!DOCTYPE html><html><head><title>Populate Search Index</title>";
echo "<style>body { font-family: monospace; padding: 20px; } .success { color: green; } .error { color: red; } .info { color: blue; }</style>";
echo "</head><body>";
echo "<h2>Populating Search Index for CFO Records</h2>";

try {
    // Get all records
    $stmt = $db->query("
        SELECT 
            id, 
            first_name_encrypted, 
            middle_name_encrypted, 
            last_name_encrypted,
            registry_number_encrypted,
            district_code,
            search_name,
            search_registry
        FROM tarheta_control
        WHERE search_name IS NULL OR search_registry IS NULL
    ");
    
    $records = $stmt->fetchAll();
    $total = count($records);
    
    echo "<p class='info'>Found $total records to process...</p>";
    
    $updated = 0;
    $errors = 0;
    
    foreach ($records as $record) {
        try {
            // Decrypt names
            $firstName = Encryption::decrypt($record['first_name_encrypted'], $record['district_code']);
            $middleName = $record['middle_name_encrypted'] ? Encryption::decrypt($record['middle_name_encrypted'], $record['district_code']) : '';
            $lastName = Encryption::decrypt($record['last_name_encrypted'], $record['district_code']);
            $registryNumber = Encryption::decrypt($record['registry_number_encrypted'], $record['district_code']);
            
            // Build search values
            $searchName = trim($firstName . ' ' . $middleName . ' ' . $lastName);
            $searchRegistry = $registryNumber;
            
            // Update record
            $updateStmt = $db->prepare("
                UPDATE tarheta_control 
                SET search_name = ?, search_registry = ?
                WHERE id = ?
            ");
            
            $updateStmt->execute([
                $searchName,
                $searchRegistry,
                $record['id']
            ]);
            
            $updated++;
            
            if ($updated % 100 === 0) {
                echo "<p class='info'>Processed $updated / $total records...</p>";
                flush();
                ob_flush();
            }
            
        } catch (Exception $e) {
            $errors++;
            echo "<p class='error'>Error processing record ID {$record['id']}: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
    
    echo "<hr>";
    echo "<p class='success'><strong>Complete!</strong></p>";
    echo "<p class='success'>Successfully updated: $updated records</p>";
    
    if ($errors > 0) {
        echo "<p class='error'>Errors: $errors records</p>";
    }
    
    echo "<p class='info'>Search index population completed.</p>";
    echo "<p><a href='cfo-registry.php'>Back to CFO Registry</a></p>";
    
} catch (Exception $e) {
    echo "<p class='error'>Fatal error: " . htmlspecialchars($e->getMessage()) . "</p>";
    error_log("Populate search index error: " . $e->getMessage());
}

echo "</body></html>";
