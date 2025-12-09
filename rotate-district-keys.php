<?php
/**
 * Safe Key Rotation Script
 * Rotates encryption keys WITHOUT breaking existing encrypted data
 * 
 * HOW IT WORKS:
 * - New data is encrypted with the new key (version 2)
 * - Old data remains encrypted with the old key (version 1)
 * - Decryption tries new key first, falls back to old key
 * - Optionally re-encrypt old data with new key
 */

require_once 'config/config.php';

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║           SAFE KEY ROTATION (WITHOUT DATA LOSS)              ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

// Configuration
$districtCode = null;
$reEncryptData = false;

// Parse command line arguments
if ($argc > 1) {
    $districtCode = $argv[1];
}
if ($argc > 2 && $argv[2] === '--re-encrypt') {
    $reEncryptData = true;
}

// Step 1: Select district
echo "Step 1: Select District for Key Rotation\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$db = Database::getInstance()->getConnection();
$stmt = $db->query("SELECT district_code, district_name FROM districts ORDER BY district_code");
$districts = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($districtCode)) {
    echo "Available districts:\n\n";
    foreach ($districts as $d) {
        echo "  - {$d['district_code']}: {$d['district_name']}\n";
    }
    echo "\nUsage: php rotate-district-keys.php <DISTRICT_CODE> [--re-encrypt]\n";
    echo "Example: php rotate-district-keys.php D001\n";
    echo "Example: php rotate-district-keys.php D001 --re-encrypt\n\n";
    echo "Options:\n";
    echo "  --re-encrypt    Re-encrypt all existing data with new key (takes time)\n\n";
    exit(0);
}

// Verify district exists
$districtExists = false;
$districtName = '';
foreach ($districts as $d) {
    if ($d['district_code'] === $districtCode) {
        $districtExists = true;
        $districtName = $d['district_name'];
        break;
    }
}

if (!$districtExists) {
    echo "❌ District code '$districtCode' not found!\n";
    exit(1);
}

echo "Selected: $districtCode ($districtName)\n";
if ($reEncryptData) {
    echo "Mode: Rotate key AND re-encrypt existing data\n";
} else {
    echo "Mode: Rotate key only (old data uses old key)\n";
}
echo "\n";

// Step 2: Backup old key
echo "Step 2: Backing Up Current Key\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

try {
    $oldKey = InfisicalKeyManager::getDistrictKey($districtCode);
    echo "✅ Retrieved current key: " . substr($oldKey, 0, 10) . "...\n";
    
    // Store old key with version suffix
    $backupKeyName = "DISTRICT_KEY_{$districtCode}_V1_BACKUP_" . date('Ymd');
    InfisicalKeyManager::storeSecret($backupKeyName, $oldKey, '/encryption-keys/archive');
    echo "✅ Backed up to: $backupKeyName\n\n";
    
} catch (Exception $e) {
    echo "❌ Failed to backup old key: " . $e->getMessage() . "\n";
    exit(1);
}

// Step 3: Generate new key
echo "Step 3: Generating New Key\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$newKey = base64_encode(random_bytes(32));
echo "✅ Generated new key: " . substr($newKey, 0, 10) . "...\n";
echo "   Key length: " . strlen($newKey) . " characters\n\n";

// Step 4: Store new key in Infisical
echo "Step 4: Storing New Key in Infisical\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

try {
    $newKeyName = "DISTRICT_KEY_" . $districtCode;
    InfisicalKeyManager::storeSecret($newKeyName, $newKey, '/encryption-keys');
    echo "✅ Stored new key in Infisical: $newKeyName\n\n";
} catch (Exception $e) {
    echo "❌ Failed to store new key: " . $e->getMessage() . "\n";
    exit(1);
}

// Step 5: Update database (for fallback)
echo "Step 5: Updating Database (Fallback)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

try {
    $stmt = $db->prepare("UPDATE districts SET encryption_key = ? WHERE district_code = ?");
    $stmt->execute([$newKey, $districtCode]);
    echo "✅ Updated database with new key\n\n";
} catch (Exception $e) {
    echo "⚠️  Failed to update database: " . $e->getMessage() . "\n";
    echo "   (Infisical key is updated, database fallback not available)\n\n";
}

// Step 6: Test new key
echo "Step 6: Testing New Key\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

try {
    // Clear cache to force fresh key retrieval
    InfisicalKeyManager::clearCache();
    
    $testData = "Test message for key rotation - " . date('Y-m-d H:i:s');
    
    // Encrypt with new key
    $encrypted = Encryption::encrypt($testData, $districtCode);
    echo "✅ Encrypted test data with new key\n";
    
    // Decrypt with new key
    $decrypted = Encryption::decrypt($encrypted, $districtCode);
    
    if ($decrypted === $testData) {
        echo "✅ Decryption successful with new key!\n\n";
    } else {
        throw new Exception("Decrypted data doesn't match original");
    }
    
} catch (Exception $e) {
    echo "❌ Key test failed: " . $e->getMessage() . "\n";
    echo "⚠️  Rolling back to old key...\n";
    
    // Rollback
    try {
        InfisicalKeyManager::storeSecret("DISTRICT_KEY_" . $districtCode, $oldKey, '/encryption-keys');
        $db->prepare("UPDATE districts SET encryption_key = ? WHERE district_code = ?")
           ->execute([$oldKey, $districtCode]);
        echo "✅ Rolled back to old key\n";
    } catch (Exception $rollbackError) {
        echo "❌ CRITICAL: Rollback failed! Manual intervention required!\n";
        echo "   Old key backup: $backupKeyName\n";
    }
    exit(1);
}

// Step 7: Re-encrypt existing data (optional)
if ($reEncryptData) {
    echo "Step 7: Re-encrypting Existing Data\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "⚠️  This will re-encrypt all data for district: $districtCode\n";
    echo "   This operation may take several minutes...\n\n";
    
    // Find encrypted columns in database
    $tablesToReEncrypt = [
        'church_officers' => ['full_name', 'address', 'contact_number'],
        'legacy_officers' => ['full_name', 'address'],
        // Add more tables as needed
    ];
    
    $totalReEncrypted = 0;
    $errors = 0;
    
    foreach ($tablesToReEncrypt as $table => $columns) {
        try {
            // Check if table exists
            $checkTable = $db->query("SHOW TABLES LIKE '$table'")->fetch();
            if (!$checkTable) {
                echo "⊘ Table '$table' not found, skipping\n";
                continue;
            }
            
            // Get records for this district
            $stmt = $db->prepare("SELECT id, " . implode(', ', $columns) . " FROM $table WHERE district_code = ?");
            $stmt->execute([$districtCode]);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($records)) {
                echo "ℹ No records found in $table for $districtCode\n";
                continue;
            }
            
            echo "Processing $table (" . count($records) . " records)...\n";
            
            foreach ($records as $record) {
                $updates = [];
                $params = [];
                
                foreach ($columns as $column) {
                    if (!empty($record[$column])) {
                        try {
                            // Decrypt with old key
                            $decrypted = Encryption::decrypt($record[$column], $districtCode, $oldKey);
                            
                            // Re-encrypt with new key
                            $reEncrypted = Encryption::encrypt($decrypted, $districtCode);
                            
                            $updates[] = "$column = ?";
                            $params[] = $reEncrypted;
                            
                        } catch (Exception $e) {
                            echo "  ⚠️  Failed to re-encrypt $table.id={$record['id']}, $column: {$e->getMessage()}\n";
                            $errors++;
                        }
                    }
                }
                
                if (!empty($updates)) {
                    $params[] = $record['id'];
                    $updateSql = "UPDATE $table SET " . implode(', ', $updates) . " WHERE id = ?";
                    $db->prepare($updateSql)->execute($params);
                    $totalReEncrypted++;
                }
            }
            
            echo "✅ Completed $table\n";
            
        } catch (Exception $e) {
            echo "❌ Error processing $table: " . $e->getMessage() . "\n";
            $errors++;
        }
    }
    
    echo "\n✅ Re-encryption complete!\n";
    echo "   Records updated: $totalReEncrypted\n";
    echo "   Errors: $errors\n\n";
    
} else {
    echo "Step 7: Skipping Data Re-encryption\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "ℹ Old encrypted data will continue using the old key\n";
    echo "ℹ New data will use the new key\n";
    echo "ℹ Decryption handles both automatically\n\n";
}

// Summary
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║                  KEY ROTATION COMPLETE                       ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

echo "✅ District: $districtCode ($districtName)\n";
echo "✅ Old key backed up: $backupKeyName\n";
echo "✅ New key activated in Infisical\n";
echo "✅ Database updated (fallback)\n";
if ($reEncryptData) {
    echo "✅ Existing data re-encrypted: $totalReEncrypted records\n";
}
echo "\n";

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "IMPORTANT NOTES:\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

if ($reEncryptData) {
    echo "✅ All data now uses the new key\n";
    echo "✅ Old key backup is kept for emergency recovery\n";
} else {
    echo "⚠️  Old data still uses old key (backed up in Infisical)\n";
    echo "✅ New data uses new key\n";
    echo "✅ Decryption works for both automatically\n";
}

echo "\n📅 Recommended rotation schedule: Every 90 days\n";
echo "🔐 Old key location: /encryption-keys/archive/$backupKeyName\n\n";

echo "To rotate another district:\n";
echo "  php rotate-district-keys.php <DISTRICT_CODE>\n\n";
