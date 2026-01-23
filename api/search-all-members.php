<?php
/**
 * Search All Members API
 * Searches across Tarheta (CFO), HDB, and PNK registries
 * Returns unified results for family member selection
 */

require_once __DIR__ . '/../config/config.php';

Security::requireLogin();

header('Content-Type: application/json');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

try {
    $searchQuery = Security::sanitizeInput($_GET['q'] ?? '');
    $source = Security::sanitizeInput($_GET['source'] ?? 'all'); // all, tarheta, hdb, pnk
    $limit = intval($_GET['limit'] ?? 20);
    $excludeIds = isset($_GET['exclude']) ? json_decode($_GET['exclude'], true) : [];
    
    if (strlen($searchQuery) < 2) {
        echo json_encode(['success' => true, 'data' => []]);
        exit;
    }
    
    $results = [];
    $searchLower = mb_strtolower(trim($searchQuery), 'UTF-8');
    $searchTerms = array_filter(preg_split('/\s+/', $searchLower));
    
    // Build location filter based on user role
    $districtFilter = null;
    $localFilter = null;
    
    if ($currentUser['role'] === 'district') {
        $districtFilter = $currentUser['district_code'];
    } elseif (in_array($currentUser['role'], ['local', 'local_cfo'])) {
        $localFilter = $currentUser['local_code'];
    }
    
    // Search Tarheta (CFO members)
    if ($source === 'all' || $source === 'tarheta') {
        $whereConditions = [];
        $params = [];
        
        if ($districtFilter) {
            $whereConditions[] = 'district_code = ?';
            $params[] = $districtFilter;
        }
        if ($localFilter) {
            $whereConditions[] = 'local_code = ?';
            $params[] = $localFilter;
        }
        
        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
        
        $stmt = $db->prepare("
            SELECT 
                t.id,
                t.last_name_encrypted,
                t.first_name_encrypted,
                t.middle_name_encrypted,
                t.husbands_surname_encrypted,
                t.birthday_encrypted,
                t.registry_number_encrypted,
                t.cfo_classification,
                t.cfo_status,
                t.purok,
                t.grupo,
                t.district_code,
                t.local_code,
                d.district_name,
                lc.local_name
            FROM tarheta_control t
            LEFT JOIN districts d ON t.district_code = d.district_code
            LEFT JOIN local_congregations lc ON t.local_code = lc.local_code
            $whereClause
            ORDER BY t.id DESC
            LIMIT 500
        ");
        $stmt->execute($params);
        $records = $stmt->fetchAll();
        
        foreach ($records as $record) {
            try {
                $lastName = trim(Encryption::decrypt($record['last_name_encrypted'], $record['district_code']) ?? '');
                $firstName = trim(Encryption::decrypt($record['first_name_encrypted'], $record['district_code']) ?? '');
                $middleName = $record['middle_name_encrypted'] ? trim(Encryption::decrypt($record['middle_name_encrypted'], $record['district_code']) ?? '') : '';
                $husbandsSurname = $record['husbands_surname_encrypted'] ? trim(Encryption::decrypt($record['husbands_surname_encrypted'], $record['district_code']) ?? '') : '';
                $birthday = $record['birthday_encrypted'] ? trim(Encryption::decrypt($record['birthday_encrypted'], $record['district_code']) ?? '') : '';
                $registryNumber = trim(Encryption::decrypt($record['registry_number_encrypted'], $record['district_code']) ?? '');
                
                if (empty($lastName) && empty($firstName)) continue;
                
                // Build searchable text
                $searchableText = mb_strtolower(implode(' ', [$lastName, $firstName, $middleName, $husbandsSurname, $registryNumber]), 'UTF-8');
                
                // Check if all terms match
                $allMatch = true;
                foreach ($searchTerms as $term) {
                    if (mb_strpos($searchableText, $term, 0, 'UTF-8') === false) {
                        $allMatch = false;
                        break;
                    }
                }
                
                if ($allMatch) {
                    // Check if excluded
                    $excludeKey = 'tarheta_' . $record['id'];
                    if (in_array($excludeKey, $excludeIds)) continue;
                    
                    $fullName = trim($lastName . ', ' . $firstName . ' ' . $middleName);
                    if ($husbandsSurname) $fullName .= ' (' . $husbandsSurname . ')';
                    
                    $results[] = [
                        'id' => $record['id'],
                        'source' => 'tarheta',
                        'source_key' => $excludeKey,
                        'name' => $fullName,
                        'last_name' => $lastName,
                        'first_name' => $firstName,
                        'middle_name' => $middleName,
                        'birthday' => $birthday,
                        'registry_number' => $registryNumber,
                        'kapisanan' => $record['cfo_classification'],
                        'status' => $record['cfo_status'],
                        'purok' => $record['purok'],
                        'grupo' => $record['grupo'],
                        'district_code' => $record['district_code'],
                        'local_code' => $record['local_code'],
                        'district_name' => $record['district_name'],
                        'local_name' => $record['local_name']
                    ];
                }
            } catch (Exception $e) {
                continue;
            }
        }
    }
    
    // Search HDB (if table exists)
    if ($source === 'all' || $source === 'hdb') {
        try {
            $whereConditions = [];
            $params = [];
            
            if ($districtFilter) {
                $whereConditions[] = 'h.district_code = ?';
                $params[] = $districtFilter;
            }
            if ($localFilter) {
                $whereConditions[] = 'h.local_code = ?';
                $params[] = $localFilter;
            }
            
            $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
            
            $stmt = $db->prepare("
                SELECT 
                    h.id,
                    h.child_first_name_encrypted,
                    h.child_middle_name_encrypted,
                    h.child_last_name_encrypted,
                    h.child_birthday_encrypted,
                    h.registry_number_encrypted,
                    h.purok_grupo,
                    h.district_code,
                    h.local_code,
                    d.district_name,
                    lc.local_name
                FROM hdb_registry h
                LEFT JOIN districts d ON h.district_code = d.district_code
                LEFT JOIN local_congregations lc ON h.local_code = lc.local_code
                $whereClause
                ORDER BY h.id DESC
                LIMIT 200
            ");
            $stmt->execute($params);
            $records = $stmt->fetchAll();
            
            foreach ($records as $record) {
                try {
                    $firstName = trim(Encryption::decrypt($record['child_first_name_encrypted'], $record['district_code']) ?? '');
                    $middleName = $record['child_middle_name_encrypted'] ? trim(Encryption::decrypt($record['child_middle_name_encrypted'], $record['district_code']) ?? '') : '';
                    $lastName = trim(Encryption::decrypt($record['child_last_name_encrypted'], $record['district_code']) ?? '');
                    $birthday = $record['child_birthday_encrypted'] ? trim(Encryption::decrypt($record['child_birthday_encrypted'], $record['district_code']) ?? '') : '';
                    $registryNumber = $record['registry_number_encrypted'] ? trim(Encryption::decrypt($record['registry_number_encrypted'], $record['district_code']) ?? '') : '';
                    
                    if (empty($lastName) && empty($firstName)) continue;
                    
                    $searchableText = mb_strtolower(implode(' ', [$lastName, $firstName, $middleName, $registryNumber]), 'UTF-8');
                    
                    $allMatch = true;
                    foreach ($searchTerms as $term) {
                        if (mb_strpos($searchableText, $term, 0, 'UTF-8') === false) {
                            $allMatch = false;
                            break;
                        }
                    }
                    
                    if ($allMatch) {
                        $excludeKey = 'hdb_' . $record['id'];
                        if (in_array($excludeKey, $excludeIds)) continue;
                        
                        // Parse purok/grupo
                        $purok = '';
                        $grupo = '';
                        if (!empty($record['purok_grupo'])) {
                            $parts = explode('-', $record['purok_grupo']);
                            $purok = $parts[0] ?? '';
                            $grupo = $parts[1] ?? '';
                        }
                        
                        $results[] = [
                            'id' => $record['id'],
                            'source' => 'hdb',
                            'source_key' => $excludeKey,
                            'name' => trim($lastName . ', ' . $firstName . ' ' . $middleName),
                            'last_name' => $lastName,
                            'first_name' => $firstName,
                            'middle_name' => $middleName,
                            'birthday' => $birthday,
                            'registry_number' => $registryNumber,
                            'kapisanan' => 'HDB',
                            'status' => 'active',
                            'purok' => $purok,
                            'grupo' => $grupo,
                            'district_code' => $record['district_code'],
                            'local_code' => $record['local_code'],
                            'district_name' => $record['district_name'],
                            'local_name' => $record['local_name']
                        ];
                    }
                } catch (Exception $e) {
                    continue;
                }
            }
        } catch (Exception $e) {
            // HDB table might not exist
        }
    }
    
    // Search PNK (if table exists)
    if ($source === 'all' || $source === 'pnk') {
        try {
            $whereConditions = [];
            $params = [];
            
            if ($districtFilter) {
                $whereConditions[] = 'p.district_code = ?';
                $params[] = $districtFilter;
            }
            if ($localFilter) {
                $whereConditions[] = 'p.local_code = ?';
                $params[] = $localFilter;
            }
            
            $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
            
            $stmt = $db->prepare("
                SELECT 
                    p.id,
                    p.last_name_encrypted,
                    p.first_name_encrypted,
                    p.middle_name_encrypted,
                    p.birthday_encrypted,
                    p.registry_number_encrypted,
                    p.purok,
                    p.grupo,
                    p.district_code,
                    p.local_code,
                    d.district_name,
                    lc.local_name
                FROM pnk_registry p
                LEFT JOIN districts d ON p.district_code = d.district_code
                LEFT JOIN local_congregations lc ON p.local_code = lc.local_code
                $whereClause
                ORDER BY p.id DESC
                LIMIT 200
            ");
            $stmt->execute($params);
            $records = $stmt->fetchAll();
            
            foreach ($records as $record) {
                try {
                    $lastName = trim(Encryption::decrypt($record['last_name_encrypted'], $record['district_code']) ?? '');
                    $firstName = trim(Encryption::decrypt($record['first_name_encrypted'], $record['district_code']) ?? '');
                    $middleName = $record['middle_name_encrypted'] ? trim(Encryption::decrypt($record['middle_name_encrypted'], $record['district_code']) ?? '') : '';
                    $birthday = $record['birthday_encrypted'] ? trim(Encryption::decrypt($record['birthday_encrypted'], $record['district_code']) ?? '') : '';
                    $registryNumber = $record['registry_number_encrypted'] ? trim(Encryption::decrypt($record['registry_number_encrypted'], $record['district_code']) ?? '') : '';
                    
                    if (empty($lastName) && empty($firstName)) continue;
                    
                    $searchableText = mb_strtolower(implode(' ', [$lastName, $firstName, $middleName, $registryNumber]), 'UTF-8');
                    
                    $allMatch = true;
                    foreach ($searchTerms as $term) {
                        if (mb_strpos($searchableText, $term, 0, 'UTF-8') === false) {
                            $allMatch = false;
                            break;
                        }
                    }
                    
                    if ($allMatch) {
                        $excludeKey = 'pnk_' . $record['id'];
                        if (in_array($excludeKey, $excludeIds)) continue;
                        
                        $results[] = [
                            'id' => $record['id'],
                            'source' => 'pnk',
                            'source_key' => $excludeKey,
                            'name' => trim($lastName . ', ' . $firstName . ' ' . $middleName),
                            'last_name' => $lastName,
                            'first_name' => $firstName,
                            'middle_name' => $middleName,
                            'birthday' => $birthday,
                            'registry_number' => $registryNumber,
                            'kapisanan' => 'PNK',
                            'status' => 'active',
                            'purok' => $record['purok'] ?? '',
                            'grupo' => $record['grupo'] ?? '',
                            'district_code' => $record['district_code'],
                            'local_code' => $record['local_code'],
                            'district_name' => $record['district_name'],
                            'local_name' => $record['local_name']
                        ];
                    }
                } catch (Exception $e) {
                    continue;
                }
            }
        } catch (Exception $e) {
            // PNK table might not exist
        }
    }
    
    // Limit results
    $results = array_slice($results, 0, $limit);
    
    echo json_encode([
        'success' => true,
        'data' => $results,
        'count' => count($results)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
