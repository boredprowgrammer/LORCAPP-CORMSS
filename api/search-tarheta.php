<?php
/**
 * API: Search Tarheta Control Records
 * Used for autocomplete/search in officer add/edit forms
 */

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

Security::requireLogin();

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

$search = Security::sanitizeInput($_GET['search'] ?? $_GET['q'] ?? '');
$districtCode = Security::sanitizeInput($_GET['district'] ?? '');
$localCode = Security::sanitizeInput($_GET['local'] ?? '');

if (empty($search) || strlen($search) < 1) {
    echo json_encode(['success' => false, 'message' => 'Search term too short']);
    exit;
}

try {
    // Build query with flexible filtering
    $whereConditions = [];
    $params = [];
    
    // Apply role-based restrictions ONLY (ignore passed district/local filters for broader search)
    if ($currentUser['role'] === 'district') {
        // District users can only see their own district
        $whereConditions[] = 't.district_code = ?';
        $params[] = $currentUser['district_code'];
    } elseif ($currentUser['role'] === 'local') {
        // Local users can only see their own local
        $whereConditions[] = 't.local_code = ?';
        $params[] = $currentUser['local_code'];
    }
    // Admin users see ALL records (no restrictions)
    
    // NOTE: Ignoring $districtCode and $localCode from URL params to allow broader search
    // This ensures imported entries from different districts/locals are searchable
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // Memory-safe chunked search: Process records in batches
    $results = [];
    $maxResults = 50;
    $chunkSize = 500; // Increased chunk size for better performance
    $offset = 0;
    $totalScanned = 0;
    $hasMoreRecords = true;
    $maxScan = 5000; // Increased limit to scan more records
    $decryptionErrors = 0;
    $skippedRecords = 0;
    
    while ($hasMoreRecords && count($results) < $maxResults && $totalScanned < $maxScan) {
        // Get chunk of records
        $stmt = $db->prepare("
            SELECT 
                t.*,
                d.district_name,
                lc.local_name
            FROM tarheta_control t
            LEFT JOIN districts d ON t.district_code = d.district_code
            LEFT JOIN local_congregations lc ON t.local_code = lc.local_code
            $whereClause
            ORDER BY t.imported_at DESC
            LIMIT $chunkSize OFFSET $offset
        ");
        
        $stmt->execute($params);
        $chunk = $stmt->fetchAll();
        
        // If no more records, stop
        if (empty($chunk)) {
            $hasMoreRecords = false;
            break;
        }
        
        $totalScanned += count($chunk);
        
        foreach ($chunk as $record) {
            // Don't stop the loop early - continue scanning for better coverage
            $lastName = '';
            $firstName = '';
            $middleName = '';
            $registryNumber = '';
            $husbandsSurname = '';
            $fullName = '';
            $decryptionFailed = false;
            
            try {
                // Decrypt all fields with individual error handling
                try {
                    $lastName = Encryption::decrypt($record['last_name_encrypted'], $record['district_code']);
                } catch (Exception $e) {
                    error_log("Failed to decrypt last_name for tarheta ID {$record['id']}: " . $e->getMessage());
                    $lastName = '[DECRYPT_ERROR]';
                    $decryptionFailed = true;
                }
                
                try {
                    $firstName = Encryption::decrypt($record['first_name_encrypted'], $record['district_code']);
                } catch (Exception $e) {
                    error_log("Failed to decrypt first_name for tarheta ID {$record['id']}: " . $e->getMessage());
                    $firstName = '[DECRYPT_ERROR]';
                    $decryptionFailed = true;
                }
                
                try {
                    $middleName = !empty($record['middle_name_encrypted']) 
                        ? Encryption::decrypt($record['middle_name_encrypted'], $record['district_code'])
                        : '';
                } catch (Exception $e) {
                    error_log("Failed to decrypt middle_name for tarheta ID {$record['id']}: " . $e->getMessage());
                    $middleName = '';
                }
                
                try {
                    $registryNumber = Encryption::decrypt($record['registry_number_encrypted'], $record['district_code']);
                } catch (Exception $e) {
                    error_log("Failed to decrypt registry_number for tarheta ID {$record['id']}: " . $e->getMessage());
                    $registryNumber = '[DECRYPT_ERROR]';
                    $decryptionFailed = true;
                }
                
                try {
                    $husbandsSurname = !empty($record['husbands_surname_encrypted']) 
                        ? Encryption::decrypt($record['husbands_surname_encrypted'], $record['district_code'])
                        : '';
                } catch (Exception $e) {
                    error_log("Failed to decrypt husbands_surname for tarheta ID {$record['id']}: " . $e->getMessage());
                    $husbandsSurname = '';
                }
                
                // If critical fields failed, track but DON'T skip - show with error indicators
                if ($decryptionFailed) {
                    $decryptionErrors++;
                    // Don't skip - let it through so user can see it exists
                }
                
                $fullName = trim($lastName . ', ' . $firstName . 
                               (!empty($middleName) ? ' ' . $middleName : ''));
                
                // Prepare search variations and normalizations
                $searchOriginal = trim($search);
                $searchLower = mb_strtolower($searchOriginal, 'UTF-8');
                $searchClean = preg_replace('/[,\.\-\s]+/', ' ', $searchLower); // Remove punctuation
                $searchClean = trim($searchClean);
                
                // Normalize name fields for comparison
                $lastNameLower = mb_strtolower($lastName, 'UTF-8');
                $firstNameLower = mb_strtolower($firstName, 'UTF-8');
                $middleNameLower = mb_strtolower($middleName, 'UTF-8');
                $fullNameLower = mb_strtolower($fullName, 'UTF-8');
                $registryNumberLower = mb_strtolower($registryNumber, 'UTF-8');
                $husbandsSurnameLower = mb_strtolower($husbandsSurname, 'UTF-8');
                
                // Create searchable text with multiple formats
                $searchableText = implode(' | ', [
                    $fullNameLower,                                    // "pineda, jerry"
                    $firstNameLower . ' ' . $lastNameLower,           // "jerry pineda"
                    $lastNameLower . ' ' . $firstNameLower,           // "pineda jerry"
                    $firstNameLower . ' ' . $middleNameLower . ' ' . $lastNameLower,
                    $registryNumberLower,
                    $husbandsSurnameLower
                ]);
                
                $matches = false;
                
                // Method 1: Direct substring matching in any field
                $matches = $matches || (mb_strpos($lastNameLower, $searchLower) !== false);
                $matches = $matches || (mb_strpos($firstNameLower, $searchLower) !== false);
                $matches = $matches || (mb_strpos($middleNameLower, $searchLower) !== false);
                $matches = $matches || (mb_strpos($fullNameLower, $searchLower) !== false);
                $matches = $matches || (mb_strpos($registryNumberLower, $searchLower) !== false);
                $matches = $matches || (mb_strpos($husbandsSurnameLower, $searchLower) !== false);
                
                // Method 2: Search in combined searchable text (catches format variations)
                $matches = $matches || (mb_strpos($searchableText, $searchLower) !== false);
                $matches = $matches || (mb_strpos($searchableText, $searchClean) !== false);
                
                // Method 3: Reversed name matching (handle "PINEDA, JERRY" or "JERRY PINEDA")
                if (strpos($searchLower, ',') !== false) {
                    // Input has comma - try parsing as "LAST, FIRST"
                    $parts = array_map('trim', explode(',', $searchLower, 2));
                    if (count($parts) === 2) {
                        $searchLastPart = $parts[0];
                        $searchFirstPart = $parts[1];
                        $matches = $matches || (
                            mb_strpos($lastNameLower, $searchLastPart) !== false &&
                            mb_strpos($firstNameLower, $searchFirstPart) !== false
                        );
                    }
                } else {
                    // No comma - try as space-separated words
                    $searchWords = preg_split('/\s+/', $searchClean, -1, PREG_SPLIT_NO_EMPTY);
                    
                    if (count($searchWords) === 2) {
                        // Two words: try both "FIRST LAST" and "LAST FIRST" combinations
                        $word1 = $searchWords[0];
                        $word2 = $searchWords[1];
                        
                        // Try "FIRST LAST"
                        $matches = $matches || (
                            mb_strpos($firstNameLower, $word1) !== false &&
                            mb_strpos($lastNameLower, $word2) !== false
                        );
                        
                        // Try "LAST FIRST"
                        $matches = $matches || (
                            mb_strpos($lastNameLower, $word1) !== false &&
                            mb_strpos($firstNameLower, $word2) !== false
                        );
                        
                        // Try both words in husband's surname
                        $matches = $matches || (
                            mb_strpos($husbandsSurnameLower, $word1) !== false &&
                            mb_strpos($husbandsSurnameLower, $word2) !== false
                        );
                    }
                }
                
                // Method 4: Token-based matching (all tokens must appear somewhere)
                $searchTokens = preg_split('/[\s,\.\-]+/', $searchLower, -1, PREG_SPLIT_NO_EMPTY);
                if (count($searchTokens) > 0) {
                    $allTokensFound = true;
                    foreach ($searchTokens as $token) {
                        if (strlen($token) < 2) continue; // Skip single characters
                        
                        $tokenFound = false;
                        $tokenFound = $tokenFound || (mb_strpos($lastNameLower, $token) !== false);
                        $tokenFound = $tokenFound || (mb_strpos($firstNameLower, $token) !== false);
                        $tokenFound = $tokenFound || (mb_strpos($middleNameLower, $token) !== false);
                        $tokenFound = $tokenFound || (mb_strpos($husbandsSurnameLower, $token) !== false);
                        $tokenFound = $tokenFound || (mb_strpos($registryNumberLower, $token) !== false);
                        
                        if (!$tokenFound) {
                            $allTokensFound = false;
                            break;
                        }
                    }
                    
                    // Only apply token matching if we have multiple tokens
                    if (count($searchTokens) > 1) {
                        $matches = $matches || $allTokensFound;
                    }
                }
                
                // Method 5: Starts-with matching for names (more relevant results)
                $matches = $matches || (mb_strpos($lastNameLower, $searchLower) === 0);
                $matches = $matches || (mb_strpos($firstNameLower, $searchLower) === 0);
                
                // Method 6: Original case-insensitive fallback (for non-UTF8 compatibility)
                $matches = $matches || (stripos($lastName, $searchOriginal) !== false);
                $matches = $matches || (stripos($firstName, $searchOriginal) !== false);
                $matches = $matches || (stripos($middleName, $searchOriginal) !== false);
                $matches = $matches || (stripos($fullName, $searchOriginal) !== false);
                $matches = $matches || (stripos($registryNumber, $searchOriginal) !== false);
                $matches = $matches || (stripos($husbandsSurname, $searchOriginal) !== false);
                
                if ($matches && count($results) < $maxResults) {
                    $results[] = [
                        'id' => $record['id'],
                        'last_name' => $lastName,
                        'first_name' => $firstName,
                        'middle_name' => $middleName,
                        'husbands_surname' => $husbandsSurname,
                        'full_name' => $fullName,
                        'registry_number' => $registryNumber,
                        'district_code' => $record['district_code'],
                        'district_name' => $record['district_name'],
                        'local_code' => $record['local_code'],
                        'local_name' => $record['local_name']
                    ];
                }
                
            } catch (Exception $e) {
                error_log("Unexpected error processing tarheta ID {$record['id']}: " . $e->getMessage());
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
        'search_completed' => !$hasMoreRecords,
        'max_reached' => $totalScanned >= $maxScan
    ]);
    
} catch (Exception $e) {
    error_log("Tarheta search error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error searching records'
    ]);
}
