<?php
/**
 * Search members from Tarheta, HDB, PNK registries
 */

// Set JSON header early to prevent HTML errors
header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../../config/config.php';
    
    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    
    $currentUser = getCurrentUser();
    if (!$currentUser) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }
    
    $db = Database::getInstance()->getConnection();

    $query = Security::sanitizeInput($_GET['q'] ?? '');
    $source = Security::sanitizeInput($_GET['source'] ?? 'all'); // all, tarheta, hdb, pnk

    if (strlen($query) < 2) {
        echo json_encode(['success' => true, 'results' => []]);
        exit;
    }

    $results = [];

    // Build WHERE conditions based on user role
    $whereConditions = [];
    $params = [];
    
    if ($currentUser['role'] === 'local' || $currentUser['role'] === 'local_cfo') {
        $whereConditions[] = 'local_code = ?';
        $params[] = $currentUser['local_code'];
    } elseif ($currentUser['role'] === 'district') {
        $whereConditions[] = 'district_code = ?';
        $params[] = $currentUser['district_code'];
    }
    
    $baseWhere = !empty($whereConditions) ? ' AND ' . implode(' AND ', $whereConditions) : '';

    // Search Tarheta (CFO Registry) - includes Buklod, Kadiwa, Binhi
    if ($source === 'all' || $source === 'tarheta') {
        $stmt = $db->prepare("
            SELECT id, first_name_encrypted, middle_name_encrypted, last_name_encrypted,
                   registry_number_encrypted, cfo_classification, cfo_status, district_code, purok, grupo
            FROM tarheta_control 
            WHERE (cfo_status = 'active' OR cfo_status IS NULL) $baseWhere
        ");
        $stmt->execute($params);
        $tarhetaRecords = $stmt->fetchAll();
        
        $searchLower = mb_strtolower($query, 'UTF-8');
        
        foreach ($tarhetaRecords as $record) {
            try {
                $firstName = Encryption::decrypt($record['first_name_encrypted'], $record['district_code']);
                $lastName = Encryption::decrypt($record['last_name_encrypted'], $record['district_code']);
                $middleName = $record['middle_name_encrypted'] ? Encryption::decrypt($record['middle_name_encrypted'], $record['district_code']) : '';
                $registryNumber = Encryption::decrypt($record['registry_number_encrypted'], $record['district_code']);
                
                $fullName = trim("$firstName $middleName $lastName");
                $fullNameReversed = trim("$lastName $firstName $middleName");
                $searchText = mb_strtolower("$fullName $fullNameReversed $registryNumber $lastName $firstName", 'UTF-8');
                
                // Check if ALL search terms match (for multi-word searches)
                $searchTerms = array_filter(preg_split('/\s+/', $searchLower));
                $allMatch = true;
                foreach ($searchTerms as $term) {
                    if (mb_strpos($searchText, $term) === false) {
                        $allMatch = false;
                        break;
                    }
                }
                
                if ($allMatch) {
                    $purokGrupo = '';
                    if ($record['purok']) $purokGrupo = $record['purok'];
                    if ($record['grupo']) $purokGrupo .= ($purokGrupo ? '-' : '') . $record['grupo'];
                    
                    $results[] = [
                        'id' => $record['id'],
                        'full_name' => $fullName,
                        'registry_number' => $registryNumber,
                        'cfo_classification' => $record['cfo_classification'],
                        'kapisanan' => $record['cfo_classification'],
                        'purok' => $record['purok'],
                        'grupo' => $record['grupo'],
                        'purok_grupo' => $purokGrupo ?: null,
                        'source' => $record['cfo_classification'] ?: 'Tarheta'
                    ];
                }
            } catch (Exception $e) {
                continue;
            }
        }
    }
    
    // Search HDB Registry
    if ($source === 'all' || $source === 'hdb') {
        // Check if hdb_registry table exists
        try {
            $stmt = $db->prepare("
                SELECT id, child_first_name_encrypted, child_middle_name_encrypted, child_last_name_encrypted,
                       registry_number_encrypted, district_code
                FROM hdb_registry 
                WHERE 1=1 $baseWhere
            ");
            $stmt->execute($params);
            $hdbRecords = $stmt->fetchAll();
            
            $searchLower = mb_strtolower($query, 'UTF-8');
            
            foreach ($hdbRecords as $record) {
                try {
                    $firstName = Encryption::decrypt($record['child_first_name_encrypted'], $record['district_code']);
                    $lastName = Encryption::decrypt($record['child_last_name_encrypted'], $record['district_code']);
                    $middleName = $record['child_middle_name_encrypted'] ? Encryption::decrypt($record['child_middle_name_encrypted'], $record['district_code']) : '';
                    $registryNumber = $record['registry_number_encrypted'] ? Encryption::decrypt($record['registry_number_encrypted'], $record['district_code']) : '';
                    
                    $fullName = trim("$firstName $middleName $lastName");
                    $fullNameReversed = trim("$lastName $firstName $middleName");
                    $searchText = mb_strtolower("$fullName $fullNameReversed $registryNumber $lastName $firstName", 'UTF-8');
                    
                    $searchTerms = array_filter(preg_split('/\s+/', $searchLower));
                    $allMatch = true;
                    foreach ($searchTerms as $term) {
                        if (mb_strpos($searchText, $term) === false) {
                            $allMatch = false;
                            break;
                        }
                    }
                    
                    if ($allMatch) {
                        $results[] = [
                            'id' => $record['id'],
                            'full_name' => $fullName,
                            'registry_number' => $registryNumber,
                            'kapisanan' => 'HDB',
                            'source' => 'HDB'
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
    
    // Search PNK Registry
    if ($source === 'all' || $source === 'pnk') {
        // Check if pnk_registry table exists
        try {
            $stmt = $db->prepare("
                SELECT id, first_name_encrypted, middle_name_encrypted, last_name_encrypted,
                       registry_number_encrypted, district_code
                FROM pnk_registry 
                WHERE 1=1 $baseWhere
            ");
            $stmt->execute($params);
            $pnkRecords = $stmt->fetchAll();
            
            $searchLower = mb_strtolower($query, 'UTF-8');
            
            foreach ($pnkRecords as $record) {
                try {
                    $firstName = Encryption::decrypt($record['first_name_encrypted'], $record['district_code']);
                    $lastName = Encryption::decrypt($record['last_name_encrypted'], $record['district_code']);
                    $middleName = $record['middle_name_encrypted'] ? Encryption::decrypt($record['middle_name_encrypted'], $record['district_code']) : '';
                    $registryNumber = $record['registry_number_encrypted'] ? Encryption::decrypt($record['registry_number_encrypted'], $record['district_code']) : '';
                    
                    $fullName = trim("$firstName $middleName $lastName");
                    $fullNameReversed = trim("$lastName $firstName $middleName");
                    $searchText = mb_strtolower("$fullName $fullNameReversed $registryNumber $lastName $firstName", 'UTF-8');
                    
                    $searchTerms = array_filter(preg_split('/\s+/', $searchLower));
                    $allMatch = true;
                    foreach ($searchTerms as $term) {
                        if (mb_strpos($searchText, $term) === false) {
                            $allMatch = false;
                            break;
                        }
                    }
                    
                    if ($allMatch) {
                        $results[] = [
                            'id' => $record['id'],
                            'full_name' => $fullName,
                            'registry_number' => $registryNumber,
                            'kapisanan' => 'PNK',
                            'source' => 'PNK'
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
    
    // Limit results to 50
    $results = array_slice($results, 0, 50);
    
    echo json_encode(['success' => true, 'results' => $results]);
    
} catch (Exception $e) {
    error_log("Search members error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Search failed']);
}
