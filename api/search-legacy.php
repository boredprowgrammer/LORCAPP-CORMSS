<?php
/**
 * API: Search Legacy Officers
 * Used for autocomplete/search in officer add/edit forms
 */

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

Security::requireLogin();

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

$search = Security::sanitizeInput($_GET['search'] ?? $_GET['q'] ?? '');

if (empty($search) || strlen($search) < 1) {
    echo json_encode(['success' => false, 'message' => 'Search term too short']);
    exit;
}

try {
    $whereConditions = [];
    $params = [];
    
    // Role-based restrictions
    if ($currentUser['role'] === 'district') {
        $whereConditions[] = 'l.district_code = ?';
        $params[] = $currentUser['district_code'];
    } elseif ($currentUser['role'] === 'local') {
        $whereConditions[] = 'l.local_code = ?';
        $params[] = $currentUser['local_code'];
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    $results = [];
    $maxResults = 50;
    $chunkSize = 500;
    $offset = 0;
    $totalScanned = 0;
    $hasMoreRecords = true;
    $maxScan = 5000;
    $decryptionErrors = 0;
    $skippedRecords = 0;
    
    while ($hasMoreRecords && count($results) < $maxResults && $totalScanned < $maxScan) {
        $stmt = $db->prepare("
            SELECT 
                l.*,
                d.district_name,
                lc.local_name
            FROM legacy_officers l
            LEFT JOIN districts d ON l.district_code = d.district_code
            LEFT JOIN local_congregations lc ON l.local_code = lc.local_code
            $whereClause
            ORDER BY l.imported_at DESC
            LIMIT $chunkSize OFFSET $offset
        ");
        
        $stmt->execute($params);
        $chunk = $stmt->fetchAll();
        
        if (empty($chunk)) {
            $hasMoreRecords = false;
            break;
        }
        
        $totalScanned += count($chunk);
        
        foreach ($chunk as $record) {
            $name = '';
            $controlNumber = '';
            $decryptionFailed = false;
            
            try {
                try {
                    $name = Encryption::decrypt($record['name_encrypted'], $record['district_code']);
                } catch (Exception $e) {
                    error_log("Failed to decrypt name for legacy ID {$record['id']}: " . $e->getMessage());
                    $name = '[DECRYPT_ERROR]';
                    $decryptionFailed = true;
                }
                
                try {
                    $controlNumber = Encryption::decrypt($record['control_number_encrypted'], $record['district_code']);
                } catch (Exception $e) {
                    error_log("Failed to decrypt control_number for legacy ID {$record['id']}: " . $e->getMessage());
                    $controlNumber = '[DECRYPT_ERROR]';
                    $decryptionFailed = true;
                }
                
                if ($decryptionFailed) {
                    $decryptionErrors++;
                }
                
                $searchLower = mb_strtolower(trim($search), 'UTF-8');
                $nameLower = mb_strtolower($name, 'UTF-8');
                $controlNumberLower = mb_strtolower($controlNumber, 'UTF-8');
                
                $matches = false;
                
                // Method 1: Direct substring matching
                $matches = $matches || (mb_strpos($nameLower, $searchLower) !== false);
                $matches = $matches || (mb_strpos($controlNumberLower, $searchLower) !== false);
                
                // Method 2: Token-based matching
                $searchTokens = preg_split('/[\s,\.\-]+/', $searchLower, -1, PREG_SPLIT_NO_EMPTY);
                if (count($searchTokens) > 0) {
                    $allTokensFound = true;
                    foreach ($searchTokens as $token) {
                        if (strlen($token) < 2) continue;
                        
                        $tokenFound = false;
                        $tokenFound = $tokenFound || (mb_strpos($nameLower, $token) !== false);
                        $tokenFound = $tokenFound || (mb_strpos($controlNumberLower, $token) !== false);
                        
                        if (!$tokenFound) {
                            $allTokensFound = false;
                            break;
                        }
                    }
                    
                    if (count($searchTokens) > 1) {
                        $matches = $matches || $allTokensFound;
                    }
                }
                
                // Method 3: Starts-with matching
                $matches = $matches || (mb_strpos($nameLower, $searchLower) === 0);
                $matches = $matches || (mb_strpos($controlNumberLower, $searchLower) === 0);
                
                // Method 4: ASCII fallback
                $matches = $matches || (stripos($name, $search) !== false);
                $matches = $matches || (stripos($controlNumber, $search) !== false);
                
                if ($matches && count($results) < $maxResults) {
                    $results[] = [
                        'id' => $record['id'],
                        'name' => $name,
                        'control_number' => $controlNumber,
                        'district_code' => $record['district_code'],
                        'district_name' => $record['district_name'],
                        'local_code' => $record['local_code'],
                        'local_name' => $record['local_name']
                    ];
                }
                
            } catch (Exception $e) {
                error_log("Unexpected error processing legacy ID {$record['id']}: " . $e->getMessage());
                $skippedRecords++;
            }
        }
        
        $offset += $chunkSize;
    }
    
    echo json_encode([
        'success' => true,
        'records' => $results,
        'total_scanned' => $totalScanned,
        'total_matches' => count($results),
        'decryption_errors' => $decryptionErrors,
        'skipped_records' => $skippedRecords,
        'search_completed' => !$hasMoreRecords
    ]);
    
} catch (Exception $e) {
    error_log("Legacy search error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error searching records'
    ]);
}
