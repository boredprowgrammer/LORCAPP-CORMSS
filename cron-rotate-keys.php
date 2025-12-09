<?php
/**
 * Webhook Endpoint for Automated Key Rotation
 * Triggered by cron-job.org every 90 days
 * 
 * URL: https://your-app.onrender.com/cron-rotate-keys.php
 */

// Security: Check for secret token
$expectedToken = getenv('CRON_SECRET_TOKEN') ?: 'your-secret-token-here-change-this';
$providedToken = $_GET['token'] ?? $_POST['token'] ?? '';

if (empty($providedToken) || $providedToken !== $expectedToken) {
    http_response_code(401);
    die(json_encode([
        'status' => 'error',
        'message' => 'Unauthorized: Invalid or missing token'
    ]));
}

// Log the cron job execution
$logFile = __DIR__ . '/logs/cron-rotation.log';
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

function logMessage($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

logMessage("Cron job triggered from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

// Capture output
ob_start();

try {
    require_once 'config/config.php';
    
    echo "Starting automated key rotation...\n\n";
    logMessage("Starting automated key rotation");
    
    // Check if rotation is needed
    try {
        $lastRotationDate = InfisicalKeyManager::getSecret('LAST_ROTATION_DATE', '/metadata');
        $lastRotation = new DateTime($lastRotationDate);
        $now = new DateTime();
        $daysSinceRotation = $now->diff($lastRotation)->days;
        
        echo "Last rotation: $lastRotationDate ($daysSinceRotation days ago)\n";
        logMessage("Last rotation: $lastRotationDate ($daysSinceRotation days ago)");
        
        if ($daysSinceRotation < 90) {
            $daysUntilNext = 90 - $daysSinceRotation;
            echo "Keys are current. Next rotation in $daysUntilNext days.\n";
            logMessage("Keys are current. Next rotation in $daysUntilNext days.");
            
            $output = ob_get_clean();
            http_response_code(200);
            die(json_encode([
                'status' => 'success',
                'message' => 'Keys are current, no rotation needed',
                'days_until_next' => $daysUntilNext,
                'output' => $output
            ]));
        }
        
        echo "Rotation needed (overdue by " . ($daysSinceRotation - 90) . " days)\n\n";
        logMessage("Rotation needed (overdue by " . ($daysSinceRotation - 90) . " days)");
        
    } catch (Exception $e) {
        echo "No previous rotation date found. Starting initial rotation.\n\n";
        logMessage("No previous rotation date found. Starting initial rotation.");
    }
    
    // Get all districts
    $db = Database::getInstance()->getConnection();
    $stmt = $db->query("SELECT district_code, district_name FROM districts ORDER BY district_code");
    $districts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($districts)) {
        throw new Exception("No districts found");
    }
    
    echo "Rotating " . count($districts) . " district(s)...\n";
    logMessage("Rotating " . count($districts) . " district(s)");
    
    $successCount = 0;
    $failCount = 0;
    
    // Rotate each district
    foreach ($districts as $district) {
        $districtCode = $district['district_code'];
        
        try {
            // Get current key
            $oldKey = InfisicalKeyManager::getDistrictKey($districtCode);
            
            // Archive old key
            $archiveKeyName = "DISTRICT_KEY_{$districtCode}_" . date('Ymd');
            InfisicalKeyManager::storeSecret($archiveKeyName, $oldKey, '/encryption-keys/archive');
            
            // Generate and store new key
            $newKey = base64_encode(random_bytes(32));
            InfisicalKeyManager::storeSecret("DISTRICT_KEY_" . $districtCode, $newKey, '/encryption-keys');
            
            // Test new key
            InfisicalKeyManager::clearCache();
            $testData = "Test-" . date('YmdHis');
            $encrypted = Encryption::encrypt($testData, $districtCode);
            $decrypted = Encryption::decrypt($encrypted, $districtCode);
            
            if ($decrypted === $testData) {
                echo "✓ Rotated: $districtCode\n";
                logMessage("✓ Rotated: $districtCode");
                $successCount++;
            } else {
                throw new Exception("Test failed for $districtCode");
            }
            
        } catch (Exception $e) {
            echo "✗ Failed: $districtCode - " . $e->getMessage() . "\n";
            logMessage("✗ Failed: $districtCode - " . $e->getMessage());
            $failCount++;
        }
    }
    
    // Rotate application keys
    echo "\nRotating application keys...\n";
    logMessage("Rotating application keys");
    
    $appKeys = ['MASTER_KEY', 'ENCRYPTION_KEY', 'SESSION_KEY', 'API_KEY', 'CHAT_MASTER_KEY', 'LORCAPP_ENCRYPTION_KEY'];
    
    foreach ($appKeys as $keyName) {
        try {
            $oldKey = InfisicalKeyManager::getSecret($keyName, '/');
            $archiveKeyName = $keyName . "_" . date('Ymd');
            InfisicalKeyManager::storeSecret($archiveKeyName, $oldKey, '/application-keys/archive');
            
            $newKey = base64_encode(random_bytes(32));
            InfisicalKeyManager::storeSecret($keyName, $newKey, '/');
            
            echo "✓ Rotated: $keyName\n";
            logMessage("✓ Rotated: $keyName");
            
        } catch (Exception $e) {
            echo "✗ Failed: $keyName\n";
            logMessage("✗ Failed: $keyName - " . $e->getMessage());
        }
    }
    
    // Update last rotation date
    InfisicalKeyManager::storeSecret('LAST_ROTATION_DATE', date('Y-m-d'), '/metadata');
    
    echo "\n=== ROTATION COMPLETE ===\n";
    echo "Districts: $successCount successful, $failCount failed\n";
    echo "Next rotation: " . date('Y-m-d', strtotime('+90 days')) . "\n";
    
    logMessage("=== ROTATION COMPLETE ===");
    logMessage("Districts: $successCount successful, $failCount failed");
    logMessage("Next rotation: " . date('Y-m-d', strtotime('+90 days')));
    
    $output = ob_get_clean();
    
    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => 'Key rotation completed successfully',
        'districts_rotated' => $successCount,
        'districts_failed' => $failCount,
        'next_rotation' => date('Y-m-d', strtotime('+90 days')),
        'output' => $output
    ], JSON_PRETTY_PRINT);
    
    logMessage("Cron job completed successfully");
    
} catch (Exception $e) {
    $output = ob_get_clean();
    
    logMessage("ERROR: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'output' => $output
    ], JSON_PRETTY_PRINT);
}
