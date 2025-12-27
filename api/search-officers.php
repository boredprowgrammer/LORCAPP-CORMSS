<?php
require_once __DIR__ . '/../config/config.php';

Security::requireLogin();

header('Content-Type: application/json');

$query = Security::sanitizeInput($_GET['q'] ?? '');

if (strlen($query) < 2) {
    echo json_encode([]);
    exit;
}

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Build WHERE clause based on user role
$whereConditions = ['o.is_active = 1'];
$params = [];

if ($currentUser['role'] === 'local') {
    $whereConditions[] = 'o.local_code = ?';
    $params[] = $currentUser['local_code'];
} elseif ($currentUser['role'] === 'district') {
    $whereConditions[] = 'o.district_code = ?';
    $params[] = $currentUser['district_code'];
}

$whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

try {
    // Fetch more officers to ensure we don't miss matches due to encryption
    // Since names are encrypted, we need to decrypt all to search
    $stmt = $db->prepare("
        SELECT 
            o.officer_id,
            o.officer_uuid,
            o.last_name_encrypted,
            o.first_name_encrypted,
            o.middle_initial_encrypted,
            o.district_code,
            lc.local_name,
            d.district_name,
            GROUP_CONCAT(DISTINCT od.department ORDER BY od.department SEPARATOR ', ') as departments
        FROM officers o
        LEFT JOIN local_congregations lc ON o.local_code = lc.local_code
        LEFT JOIN districts d ON o.district_code = d.district_code
        LEFT JOIN officer_departments od ON o.officer_id = od.officer_id AND od.is_active = 1
        $whereClause
        GROUP BY o.officer_id
        LIMIT 500
    ");
    
    $stmt->execute($params);
    $officers = $stmt->fetchAll();
    
    $results = [];
    $matchCount = 0;
    $maxResults = 50; // Limit results returned to client
    
    foreach ($officers as $officer) {
        if ($matchCount >= $maxResults) {
            break;
        }
        
        // Decrypt name for search matching
        try {
            $decrypted = Encryption::decryptOfficerName(
                $officer['last_name_encrypted'],
                $officer['first_name_encrypted'],
                $officer['middle_initial_encrypted'],
                $officer['district_code']
            );
            
            $fullName = trim($decrypted['first_name'] . ' ' . 
                            ($decrypted['middle_initial'] ? $decrypted['middle_initial'] . '. ' : '') . 
                            $decrypted['last_name']);
            
            // Check if name matches query (multiple search strategies)
            $queryLower = mb_strtolower($query, 'UTF-8');
            $fullNameLower = mb_strtolower($fullName, 'UTF-8');
            $lastNameLower = mb_strtolower($decrypted['last_name'], 'UTF-8');
            $firstNameLower = mb_strtolower($decrypted['first_name'], 'UTF-8');
            
            // Match if query appears in full name, last name, or first name
            if (stripos($fullNameLower, $queryLower) !== false ||
                stripos($lastNameLower, $queryLower) !== false ||
                stripos($firstNameLower, $queryLower) !== false) {
                
                // Build location string safely
                $location = '';
                if (!empty($officer['local_name']) && !empty($officer['district_name'])) {
                    $location = $officer['local_name'] . ', ' . $officer['district_name'];
                } elseif (!empty($officer['local_name'])) {
                    $location = $officer['local_name'];
                } elseif (!empty($officer['district_name'])) {
                    $location = $officer['district_name'];
                }
                
                $results[] = [
                    'id' => $officer['officer_id'],
                    'uuid' => $officer['officer_uuid'],
                    'name' => obfuscateName($fullName),
                    'full_name' => $fullName, // Keep full name for tooltip
                    'location' => $location,
                    'local_name' => $officer['local_name'] ?? '',
                    'district_name' => $officer['district_name'] ?? '',
                    'departments' => $officer['departments'] ?: 'No departments',
                    'lastName' => $decrypted['last_name'],
                    'firstName' => $decrypted['first_name'],
                    'middleInitial' => $decrypted['middle_initial']
                ];
                
                $matchCount++;
            }
        } catch (Exception $e) {
            // Skip officers with decryption errors
            error_log("Decryption error for officer {$officer['officer_id']}: " . $e->getMessage());
            continue;
        }
    }
    
    echo json_encode($results);
    
} catch (Exception $e) {
    error_log("Search officers API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
?>
