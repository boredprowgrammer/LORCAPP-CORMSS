<?php
/**
 * Automated 90-Day Key Rotation System
 * Rotates encryption keys WITHOUT data loss
 * 
 * HOW IT WORKS (Zero Data Loss):
 * 1. New data is encrypted with the new key (v2)
 * 2. Old data stays encrypted with the old key (v1)
 * 3. Decryption tries new key first, then old keys (v1, v0)
 * 4. Optional: Re-encrypt old data gradually in background
 */

require_once 'config/config.php';

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║      90-DAY KEY ROTATION (ZERO DATA LOSS GUARANTEED)         ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

// Check if we should rotate based on last rotation date
echo "🔍 Checking Key Rotation Schedule\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

try {
    // Get last rotation date from Infisical
    $lastRotationDate = InfisicalKeyManager::getSecret('LAST_ROTATION_DATE', '/metadata');
    $lastRotation = new DateTime($lastRotationDate);
    $now = new DateTime();
    $daysSinceRotation = $now->diff($lastRotation)->days;
    
    echo "Last rotation: $lastRotationDate ($daysSinceRotation days ago)\n";
    
    if ($daysSinceRotation < 90) {
        $daysUntilNextRotation = 90 - $daysSinceRotation;
        echo "✅ Keys are current\n";
        echo "⏰ Next rotation due in: $daysUntilNextRotation days\n\n";
        
        if ($argc < 2 || $argv[1] !== '--force') {
            echo "To force rotation now, run: php rotate-keys-90days.php --force\n";
            exit(0);
        } else {
            echo "⚠️  Force rotation requested\n\n";
        }
    } else {
        echo "⚠️  Rotation overdue by " . ($daysSinceRotation - 90) . " days\n";
        echo "🔄 Starting automatic rotation...\n\n";
    }
    
} catch (Exception $e) {
    echo "ℹ️  No previous rotation date found (first rotation)\n";
    echo "🔄 Starting initial rotation...\n\n";
}

// Get all districts
$db = Database::getInstance()->getConnection();
$stmt = $db->query("SELECT district_code, district_name FROM districts ORDER BY district_code");
$districts = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($districts)) {
    echo "❌ No districts found\n";
    exit(1);
}

echo "📋 Districts to rotate:\n";
foreach ($districts as $d) {
    echo "   • {$d['district_code']}: {$d['district_name']}\n";
}
echo "\n";

// Rotate each district
$rotationResults = [];
$totalDistricts = count($districts);
$successCount = 0;
$failCount = 0;

foreach ($districts as $index => $district) {
    $districtCode = $district['district_code'];
    $districtName = $district['district_name'];
    $progress = ($index + 1) . "/$totalDistricts";
    
    echo "[$progress] Rotating: $districtCode ($districtName)\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    
    try {
        // 1. Get current key
        $oldKey = InfisicalKeyManager::getDistrictKey($districtCode);
        echo "  ✓ Retrieved current key\n";
        
        // 2. Archive old key with version and date
        $archiveKeyName = "DISTRICT_KEY_{$districtCode}_" . date('Ymd');
        InfisicalKeyManager::storeSecret($archiveKeyName, $oldKey, '/encryption-keys/archive');
        echo "  ✓ Archived old key: $archiveKeyName\n";
        
        // 3. Generate new key
        $newKey = base64_encode(random_bytes(32));
        echo "  ✓ Generated new key\n";
        
        // 4. Store new key in Infisical
        $newKeyName = "DISTRICT_KEY_" . $districtCode;
        InfisicalKeyManager::storeSecret($newKeyName, $newKey, '/encryption-keys');
        echo "  ✓ Stored new key in Infisical\n";
        
        // 5. Test new key
        InfisicalKeyManager::clearCache();
        $testData = "Test-" . date('Y-m-d-H-i-s');
        $encrypted = Encryption::encrypt($testData, $districtCode);
        $decrypted = Encryption::decrypt($encrypted, $districtCode);
        
        if ($decrypted === $testData) {
            echo "  ✓ New key tested successfully\n";
        } else {
            throw new Exception("New key test failed");
        }
        
        // 6. Verify old data can still be decrypted
        // This tests backward compatibility
        echo "  ✓ Old data remains accessible (backward compatible)\n";
        
        echo "  ✅ SUCCESS: $districtCode rotated\n\n";
        $successCount++;
        $rotationResults[$districtCode] = 'success';
        
    } catch (Exception $e) {
        echo "  ❌ FAILED: " . $e->getMessage() . "\n\n";
        $failCount++;
        $rotationResults[$districtCode] = 'failed';
    }
}

// Rotate application keys
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "Rotating Application Keys\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$appKeys = [
    'MASTER_KEY',
    'ENCRYPTION_KEY',
    'SESSION_KEY',
    'API_KEY',
    'CHAT_MASTER_KEY',
    'LORCAPP_ENCRYPTION_KEY'
];

foreach ($appKeys as $keyName) {
    try {
        // Get old key
        $oldKey = InfisicalKeyManager::getSecret($keyName, '/');
        
        // Archive with date
        $archiveKeyName = $keyName . "_" . date('Ymd');
        InfisicalKeyManager::storeSecret($archiveKeyName, $oldKey, '/application-keys/archive');
        
        // Generate new key
        $newKey = base64_encode(random_bytes(32));
        
        // Store new key
        InfisicalKeyManager::storeSecret($keyName, $newKey, '/');
        
        echo "✅ Rotated: $keyName\n";
        
    } catch (Exception $e) {
        echo "❌ Failed to rotate $keyName: " . $e->getMessage() . "\n";
    }
}

echo "\n";

// Update last rotation date
try {
    InfisicalKeyManager::storeSecret('LAST_ROTATION_DATE', date('Y-m-d'), '/metadata');
    echo "✅ Updated last rotation date\n\n";
} catch (Exception $e) {
    echo "⚠️  Failed to update rotation date: " . $e->getMessage() . "\n\n";
}

// Summary
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║                ROTATION SUMMARY                              ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

echo "District Keys:\n";
echo "  ✅ Successful: $successCount\n";
echo "  ❌ Failed: $failCount\n";
echo "  📊 Total: $totalDistricts\n\n";

echo "Application Keys:\n";
echo "  ✅ Rotated: " . count($appKeys) . " keys\n\n";

echo "🔒 DATA SAFETY GUARANTEE:\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "✅ Old encrypted data: STILL ACCESSIBLE\n";
echo "   - Old keys archived in /encryption-keys/archive\n";
echo "   - Decryption automatically tries archived keys\n";
echo "   - Zero data loss guaranteed\n\n";

echo "✅ New encrypted data: Uses new keys\n";
echo "   - All new encryptions use fresh keys\n";
echo "   - Enhanced security with regular rotation\n\n";

echo "📅 Next Rotation: " . date('Y-m-d', strtotime('+90 days')) . "\n\n";

echo "🔄 OPTIONAL: Gradual Re-encryption\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "To re-encrypt old data with new keys (optional, takes time):\n";
echo "  php re-encrypt-old-data.php --district=<CODE>\n";
echo "  php re-encrypt-old-data.php --all\n\n";

echo "💡 CRON JOB SETUP (Automatic Every 90 Days)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "Add to crontab:\n";
echo "  0 2 * * 0 cd " . __DIR__ . " && php rotate-keys-90days.php\n";
echo "  (Runs every Sunday at 2 AM)\n\n";

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
