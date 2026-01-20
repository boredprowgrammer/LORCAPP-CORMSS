<?php
/**
 * Get CFO Data for DataTables
 */

require_once __DIR__ . '/../config/config.php';

Security::requireLogin();

// Check if user has reports permission OR has approved CFO access
$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

$hasAccess = false;
if (hasPermission('can_view_reports')) {
    $hasAccess = true;
} elseif ($currentUser['role'] === 'local_cfo') {
    // Check for approved CFO access
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) as count FROM cfo_access_requests 
            WHERE requester_user_id = ? 
            AND status = 'approved'
            AND deleted_at IS NULL
            AND (expires_at IS NULL OR expires_at > NOW())
        ");
        $stmt->execute([$currentUser['user_id']]);
        $result = $stmt->fetch();
        if ($result && $result['count'] > 0) {
            $hasAccess = true;
        }
    } catch (Exception $e) {
        error_log("Error checking CFO access: " . $e->getMessage());
    }
}

if (!$hasAccess) {
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode([
        'draw' => 1,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => 'Access denied'
    ]);
    exit;
}

header('Content-Type: application/json');

// Check if this is a stats request
$action = Security::sanitizeInput($_GET['action'] ?? '');

if ($action === 'stats') {
    // Get filter parameters
    $filterClassification = Security::sanitizeInput($_GET['classification'] ?? '');
    $filterStatus = Security::sanitizeInput($_GET['status'] ?? '');
    $filterDistrict = Security::sanitizeInput($_GET['district'] ?? '');
    $filterLocal = Security::sanitizeInput($_GET['local'] ?? '');
    $filterMissingBirthday = Security::sanitizeInput($_GET['missing_birthday'] ?? '');
    $approvedCfoTypes = Security::sanitizeInput($_GET['approved_cfo_types'] ?? '');
    
    // Build WHERE conditions
    $whereConditions = [];
    $params = [];
    
    // Role-based filtering
    if ($currentUser['role'] === 'district') {
        $whereConditions[] = 'district_code = ?';
        $params[] = $currentUser['district_code'];
    } elseif ($currentUser['role'] === 'local' || $currentUser['role'] === 'local_cfo') {
        $whereConditions[] = 'local_code = ?';
        $params[] = $currentUser['local_code'];
    }
    
    // Approved CFO types filter (for local_cfo users with restricted access)
    if (!empty($approvedCfoTypes)) {
        $types = array_filter(array_map('trim', explode(',', $approvedCfoTypes)));
        if (!empty($types)) {
            $placeholders = implode(',', array_fill(0, count($types), '?'));
            $whereConditions[] = "cfo_classification IN ($placeholders)";
            $params = array_merge($params, $types);
        }
    }
    
    // Classification filter
    if (!empty($filterClassification)) {
        if ($filterClassification === 'null') {
            $whereConditions[] = 'cfo_classification IS NULL';
        } else {
            $whereConditions[] = 'cfo_classification = ?';
            $params[] = $filterClassification;
        }
    }
    
    // Status filter
    if (!empty($filterStatus)) {
        $whereConditions[] = 'cfo_status = ?';
        $params[] = $filterStatus;
    }
    
    // District filter
    if (!empty($filterDistrict)) {
        $whereConditions[] = 'district_code = ?';
        $params[] = $filterDistrict;
    }
    
    // Local filter
    if (!empty($filterLocal)) {
        $whereConditions[] = 'local_code = ?';
        $params[] = $filterLocal;
    }
    
    // Missing birthday filter
    if (!empty($filterMissingBirthday)) {
        $whereConditions[] = '(birthday_encrypted IS NULL OR birthday_encrypted = "")';
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // Get statistics
    $stats = [
        'total' => 0,
        'buklod' => 0,
        'kadiwa' => 0,
        'binhi' => 0
    ];
    
    try {
        // Total count
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM tarheta_control $whereClause");
        $stmt->execute($params);
        $stats['total'] = intval($stmt->fetch()['total']);
        
        // By classification
        $stmt = $db->prepare("
            SELECT 
                cfo_classification,
                COUNT(*) as count 
            FROM tarheta_control 
            $whereClause 
            GROUP BY cfo_classification
        ");
        $stmt->execute($params);
        while ($row = $stmt->fetch()) {
            if ($row['cfo_classification']) {
                $stats[strtolower($row['cfo_classification'])] = intval($row['count']);
            }
        }
    } catch (Exception $e) {
        error_log("Error getting stats: " . $e->getMessage());
    }
    
    echo json_encode(['stats' => $stats]);
    exit;
}

try {
    // Get DataTables parameters
    $draw = intval($_GET['draw'] ?? 1);
    $start = intval($_GET['start'] ?? 0);
    $length = intval($_GET['length'] ?? 25);
    
    // Safely get search value (DataTables sends it as search[value])
    $searchValue = '';
    if (isset($_GET['search']) && is_array($_GET['search']) && isset($_GET['search']['value'])) {
        $searchValue = Security::sanitizeInput($_GET['search']['value']);
    }
    
    // Get filter parameters
    $filterClassification = Security::sanitizeInput($_GET['classification'] ?? '');
    $filterStatus = Security::sanitizeInput($_GET['status'] ?? '');
    $filterDistrict = Security::sanitizeInput($_GET['district'] ?? '');
    $filterLocal = Security::sanitizeInput($_GET['local'] ?? '');
    $approvedCfoTypes = Security::sanitizeInput($_GET['approved_cfo_types'] ?? '');
    
    // Build WHERE conditions
    $whereConditions = [];
    $params = [];
    
    // Role-based filtering
    if ($currentUser['role'] === 'district') {
        $whereConditions[] = 't.district_code = ?';
        $params[] = $currentUser['district_code'];
    } elseif ($currentUser['role'] === 'local' || $currentUser['role'] === 'local_cfo') {
        $whereConditions[] = 't.local_code = ?';
        $params[] = $currentUser['local_code'];
    }
    
    // Approved CFO types filter (for local_cfo users with restricted access)
    if (!empty($approvedCfoTypes)) {
        $types = array_filter(array_map('trim', explode(',', $approvedCfoTypes)));
        if (!empty($types)) {
            $placeholders = implode(',', array_fill(0, count($types), '?'));
            $whereConditions[] = "t.cfo_classification IN ($placeholders)";
            $params = array_merge($params, $types);
        }
    }
    
    // Classification filter
    if (!empty($filterClassification)) {
        if ($filterClassification === 'null') {
            $whereConditions[] = 't.cfo_classification IS NULL';
        } else {
            $whereConditions[] = 't.cfo_classification = ?';
            $params[] = $filterClassification;
        }
    }
    
    // Status filter
    if (!empty($filterStatus)) {
        $whereConditions[] = 't.cfo_status = ?';
        $params[] = $filterStatus;
    }
    
    // District filter
    if (!empty($filterDistrict)) {
        $whereConditions[] = 't.district_code = ?';
        $params[] = $filterDistrict;
    }
    
    // Local filter
    if (!empty($filterLocal)) {
        $whereConditions[] = 't.local_code = ?';
        $params[] = $filterLocal;
    }
    
    // Missing birthday filter
    if (!empty($filterMissingBirthday)) {
        $whereConditions[] = '(t.birthday_encrypted IS NULL OR t.birthday_encrypted = "")';
    }
    
    // Check if we need to search on encrypted fields (name/registry)
    $searchOnEncrypted = !empty($searchValue);
    
    // If not searching on encrypted data, we can use normal SQL search on non-encrypted columns
    if (!$searchOnEncrypted) {
        // No search or search handled via encrypted field filtering
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // Get sorting parameters (safely handle nested array)
    $orderColumnIndex = 0;
    $orderDir = 'DESC';
    if (isset($_GET['order']) && is_array($_GET['order']) && isset($_GET['order'][0])) {
        if (isset($_GET['order'][0]['column'])) {
            $orderColumnIndex = intval($_GET['order'][0]['column']);
        }
        if (isset($_GET['order'][0]['dir'])) {
            $orderDir = strtoupper($_GET['order'][0]['dir']);
        }
    }
    $orderDir = ($orderDir === 'ASC') ? 'ASC' : 'DESC'; // Sanitize
    
    // Map column index to actual database column (non-encrypted columns only for DB sorting)
    $columns = [
        0 => 't.id',
        1 => 't.id', // Name - encrypted, handled in PHP
        2 => 't.id', // last_name - encrypted
        3 => 't.id', // first_name - encrypted
        4 => 't.id', // middle_name - encrypted
        5 => 't.id', // registry_number - encrypted
        6 => 't.id', // husbands_surname - encrypted
        7 => 't.id', // birthday - encrypted
        8 => 't.cfo_classification',
        9 => 't.cfo_status',
        10 => 't.purok',
        11 => 'd.district_name',
        12 => 'lc.local_name'
    ];
    
    $orderColumn = $columns[$orderColumnIndex] ?? 't.id';
    
    // Get total count (without search and filters)
    $stmtTotal = $db->prepare("SELECT COUNT(*) as total FROM tarheta_control t");
    $stmtTotal->execute([]);
    $totalRecords = $stmtTotal->fetch()['total'];
    
    // If searching on encrypted data, we need to fetch all matching records, decrypt, filter, then paginate
    if ($searchOnEncrypted) {
        // Fetch all records matching base filters (without pagination)
        $query = "
            SELECT 
                t.id,
                t.last_name_encrypted,
                t.first_name_encrypted,
                t.middle_name_encrypted,
                t.husbands_surname_encrypted,
                t.registry_number_encrypted,
                t.birthday_encrypted,
                t.cfo_classification,
                t.cfo_classification_auto,
                t.cfo_status,
                t.cfo_notes,
                t.purok,
                t.grupo,
                t.registration_type,
                t.registration_date,
                t.registration_others_specify,
                t.transfer_out_date,
                t.district_code,
                t.local_code,
                d.district_name,
                lc.local_name
            FROM tarheta_control t
            LEFT JOIN districts d ON t.district_code = d.district_code
            LEFT JOIN local_congregations lc ON t.local_code = lc.local_code
            $whereClause
            ORDER BY $orderColumn $orderDir
        ";
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $allRecords = $stmt->fetchAll();
        
        // Decrypt and filter records based on search term
        $searchLower = strtolower($searchValue);
        $filteredData = [];
        
        foreach ($allRecords as $record) {
            try {
                $lastName = Encryption::decrypt($record['last_name_encrypted'], $record['district_code']);
                $firstName = Encryption::decrypt($record['first_name_encrypted'], $record['district_code']);
                $middleName = $record['middle_name_encrypted'] ? Encryption::decrypt($record['middle_name_encrypted'], $record['district_code']) : '';
                $husbandsSurname = $record['husbands_surname_encrypted'] ? Encryption::decrypt($record['husbands_surname_encrypted'], $record['district_code']) : '';
                $registryNumber = Encryption::decrypt($record['registry_number_encrypted'], $record['district_code']);
                $birthday = $record['birthday_encrypted'] ? Encryption::decrypt($record['birthday_encrypted'], $record['district_code']) : '';
                
                // Check if search term matches any field
                $searchableText = strtolower(implode(' ', [
                    $lastName, $firstName, $middleName, $husbandsSurname, $registryNumber,
                    $record['district_name'] ?? '', $record['local_name'] ?? '',
                    $record['cfo_classification'] ?? '', $record['cfo_status'] ?? '',
                    $record['purok'] ?? '', $record['grupo'] ?? ''
                ]));
                
                if (strpos($searchableText, $searchLower) !== false) {
                    // Format birthday
                    $birthdayFormatted = '-';
                    if ($birthday) {
                        try {
                            $birthdayDate = new DateTime($birthday);
                            $birthdayFormatted = $birthdayDate->format('M d, Y');
                        } catch (Exception $e) {
                            $birthdayFormatted = '-';
                        }
                    }
                    
                    // Full name
                    $fullName = trim($firstName . ' ' . $middleName . ' ' . $lastName);
                    if ($husbandsSurname) {
                        $fullName .= ' (' . $husbandsSurname . ')';
                    }
                    
                    // Obfuscated name for display
                    $displayName = obfuscateName(trim($lastName . ', ' . $firstName . ' ' . $middleName));
                    
                    // Format purok-grupo
                    $purokGrupo = '-';
                    if (!empty($record['purok']) && !empty($record['grupo'])) {
                        $purokGrupo = $record['purok'] . '-' . $record['grupo'];
                    } elseif (!empty($record['purok'])) {
                        $purokGrupo = $record['purok'];
                    } elseif (!empty($record['grupo'])) {
                        $purokGrupo = 'Grupo ' . $record['grupo'];
                    }
                    
                    $filteredData[] = [
                        'id' => $record['id'],
                        'name' => $displayName,
                        'name_real' => $fullName,
                        'last_name' => obfuscateWord($lastName),
                        'last_name_real' => $lastName,
                        'first_name' => obfuscateWord($firstName),
                        'first_name_real' => $firstName,
                        'middle_name' => $middleName ? obfuscateWord($middleName) : '-',
                        'middle_name_real' => $middleName ?: '-',
                        'registry_number' => $registryNumber,
                        'husbands_surname' => $husbandsSurname ? obfuscateWord($husbandsSurname) : '-',
                        'husbands_surname_real' => $husbandsSurname ?: '-',
                        'birthday' => $birthdayFormatted,
                        'cfo_classification' => $record['cfo_classification'],
                        'cfo_classification_auto' => $record['cfo_classification_auto'],
                        'cfo_status' => $record['cfo_status'],
                        'purok_grupo' => $purokGrupo,
                        'registration_type' => $record['registration_type'],
                        'registration_date' => $record['registration_date'],
                        'registration_others_specify' => $record['registration_others_specify'],
                        'transfer_out_date' => $record['transfer_out_date'],
                        'district_name' => $record['district_name'],
                        'local_name' => $record['local_name']
                    ];
                }
            } catch (Exception $e) {
                error_log("Decryption error for record {$record['id']}: " . $e->getMessage());
            }
        }
        
        $filteredRecords = count($filteredData);
        
        // Apply pagination on filtered data
        $data = array_slice($filteredData, $start, $length);
        
        echo json_encode([
            'draw' => $draw,
            'recordsTotal' => $totalRecords,
            'recordsFiltered' => $filteredRecords,
            'data' => $data
        ]);
        exit;
    }
    
    // No search - use normal SQL pagination
    $query = "
        SELECT 
            t.id,
            t.last_name_encrypted,
            t.first_name_encrypted,
            t.middle_name_encrypted,
            t.husbands_surname_encrypted,
            t.registry_number_encrypted,
            t.birthday_encrypted,
            t.cfo_classification,
            t.cfo_classification_auto,
            t.cfo_status,
            t.cfo_notes,
            t.purok,
            t.grupo,
            t.registration_type,
            t.registration_date,
            t.registration_others_specify,
            t.transfer_out_date,
            t.district_code,
            t.local_code,
            d.district_name,
            lc.local_name
        FROM tarheta_control t
        LEFT JOIN districts d ON t.district_code = d.district_code
        LEFT JOIN local_congregations lc ON t.local_code = lc.local_code
        $whereClause
        ORDER BY $orderColumn $orderDir
        LIMIT $start, $length
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $records = $stmt->fetchAll();
    
    // Get filtered count
    $stmtFiltered = $db->prepare("SELECT COUNT(*) as total FROM tarheta_control t LEFT JOIN districts d ON t.district_code = d.district_code LEFT JOIN local_congregations lc ON t.local_code = lc.local_code $whereClause");
    $stmtFiltered->execute($params);
    $filteredRecords = $stmtFiltered->fetch()['total'];
    
    // Decrypt and format records
    $data = [];
    foreach ($records as $record) {
        try {
            $lastName = Encryption::decrypt($record['last_name_encrypted'], $record['district_code']);
            $firstName = Encryption::decrypt($record['first_name_encrypted'], $record['district_code']);
            $middleName = $record['middle_name_encrypted'] ? Encryption::decrypt($record['middle_name_encrypted'], $record['district_code']) : '';
            $husbandsSurname = $record['husbands_surname_encrypted'] ? Encryption::decrypt($record['husbands_surname_encrypted'], $record['district_code']) : '';
            $registryNumber = Encryption::decrypt($record['registry_number_encrypted'], $record['district_code']);
            $birthday = $record['birthday_encrypted'] ? Encryption::decrypt($record['birthday_encrypted'], $record['district_code']) : '';
            
            // Format birthday
            if ($birthday) {
                try {
                    $birthdayDate = new DateTime($birthday);
                    $birthday = $birthdayDate->format('M d, Y');
                } catch (Exception $e) {
                    $birthday = '-';
                }
            } else {
                $birthday = '-';
            }
            
            // Full name (unobfuscated for internal use)
            $fullName = trim($firstName . ' ' . $middleName . ' ' . $lastName);
            if ($husbandsSurname) {
                $fullName .= ' (' . $husbandsSurname . ')';
            }
            
            // Obfuscated name for display (privacy protection)
            $displayName = obfuscateName(trim($lastName . ', ' . $firstName . ' ' . $middleName));
            
            // Format purok-grupo
            $purokGrupo = '-';
            if (!empty($record['purok']) && !empty($record['grupo'])) {
                $purokGrupo = $record['purok'] . '-' . $record['grupo'];
            } elseif (!empty($record['purok'])) {
                $purokGrupo = $record['purok'];
            } elseif (!empty($record['grupo'])) {
                $purokGrupo = 'Grupo ' . $record['grupo'];
            }
            
            $data[] = [
                'id' => $record['id'],
                'name' => $displayName, // Obfuscated for privacy
                'name_real' => $fullName, // Real name for tooltip
                'last_name' => obfuscateWord($lastName), // Obfuscated
                'last_name_real' => $lastName, // Real for tooltip
                'first_name' => obfuscateWord($firstName), // Obfuscated
                'first_name_real' => $firstName, // Real for tooltip
                'middle_name' => $middleName ? obfuscateWord($middleName) : '-', // Obfuscated
                'middle_name_real' => $middleName ?: '-', // Real for tooltip
                'registry_number' => $registryNumber,
                'husbands_surname' => $husbandsSurname ? obfuscateWord($husbandsSurname) : '-', // Obfuscated
                'husbands_surname_real' => $husbandsSurname ?: '-', // Real for tooltip
                'birthday' => $birthday,
                'cfo_classification' => $record['cfo_classification'],
                'cfo_classification_auto' => $record['cfo_classification_auto'],
                'cfo_status' => $record['cfo_status'],
                'purok_grupo' => $purokGrupo,
                'registration_type' => $record['registration_type'],
                'registration_date' => $record['registration_date'],
                'registration_others_specify' => $record['registration_others_specify'],
                'transfer_out_date' => $record['transfer_out_date'],
                'district_name' => $record['district_name'],
                'local_name' => $record['local_name']
            ];
        } catch (Exception $e) {
            error_log("Decryption error for record {$record['id']}: " . $e->getMessage());
        }
    }
    
    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => $totalRecords,
        'recordsFiltered' => $filteredRecords,
        'data' => $data
    ]);
    
} catch (Exception $e) {
    error_log("Error in get-cfo-data.php: " . $e->getMessage());
    echo json_encode([
        'draw' => 1,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => 'An error occurred while loading data'
    ]);
}
