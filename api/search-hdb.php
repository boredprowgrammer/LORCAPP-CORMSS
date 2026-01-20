<?php
/**
 * Search HDB Registry API
 * Returns search results for HDB (Handog Di Bautisado) records
 */

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

try {
    Security::requireLogin();
    
    // Check permissions
    $currentUser = getCurrentUser();
    $needsAccessRequest = ($currentUser['role'] === 'local_cfo' || $currentUser['role'] === 'local_limited');
    
    // If user needs access, verify they have approved access
    if ($needsAccessRequest) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT COUNT(*) as count FROM hdb_access_requests 
            WHERE requester_user_id = ? 
            AND status = 'approved'
            AND deleted_at IS NULL
            AND is_locked = FALSE
        ");
        $stmt->execute([$currentUser['user_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['count'] == 0) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access to HDB Registry requires approval']);
            exit;
        }
    }
    
    // Get search parameters
    $searchName = isset($_GET['name']) ? strtolower(trim($_GET['name'])) : '';
    $searchRegistry = isset($_GET['registry']) ? trim($_GET['registry']) : '';
    $searchStatus = isset($_GET['status']) ? trim($_GET['status']) : '';
    
    $db = Database::getInstance()->getConnection();
    
    // Build query - note: names are encrypted, so we'll filter after decryption
    $query = "SELECT * FROM hdb_registry WHERE deleted_at IS NULL";
    $params = [];
    
    // Filter by registry number (stored in plaintext)
    if (!empty($searchRegistry)) {
        $query .= " AND registry_number LIKE ?";
        $params[] = "%{$searchRegistry}%";
    }
    
    // Filter by status
    if (!empty($searchStatus)) {
        $query .= " AND dedication_status = ?";
        $params[] = $searchStatus;
    }
    
    $query .= " ORDER BY registration_date DESC LIMIT 200";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Decrypt and filter by name if search term provided
    $results = [];
    foreach ($records as $record) {
        try {
            $districtCode = $record['district_code'];
            
            // Decrypt names
            $firstName = Encryption::decrypt($record['child_first_name_encrypted'], $districtCode);
            $middleName = !empty($record['child_middle_name_encrypted']) 
                ? Encryption::decrypt($record['child_middle_name_encrypted'], $districtCode) 
                : '';
            $lastName = Encryption::decrypt($record['child_last_name_encrypted'], $districtCode);
            
            // Decrypt other fields for display
            $birthday = !empty($record['child_birthday_encrypted']) 
                ? Encryption::decrypt($record['child_birthday_encrypted'], $districtCode) 
                : '';
            $fatherName = !empty($record['father_name_encrypted']) 
                ? Encryption::decrypt($record['father_name_encrypted'], $districtCode) 
                : '';
            $motherName = !empty($record['mother_name_encrypted']) 
                ? Encryption::decrypt($record['mother_name_encrypted'], $districtCode) 
                : '';
            $registryNumber = Encryption::decrypt($record['registry_number'], $districtCode);
            
            // Filter by name if search term provided
            if (!empty($searchName)) {
                $fullName = strtolower($firstName . ' ' . $middleName . ' ' . $lastName);
                if (strpos($fullName, $searchName) === false) {
                    continue; // Skip this record if name doesn't match
                }
            }
            
            // Add decrypted data to result
            $results[] = [
                'id' => $record['id'],
                'registry_number' => $registryNumber,
                'first_name' => $firstName,
                'middle_name' => $middleName,
                'last_name' => $lastName,
                'date_of_birth' => $birthday,
                'father_name' => $fatherName,
                'mother_name' => $motherName,
                'status' => $record['dedication_status'],
                'registration_date' => $record['registration_date']
            ];
            
            // Limit to 100 results
            if (count($results) >= 100) {
                break;
            }
            
        } catch (Exception $e) {
            error_log("Error decrypting HDB record {$record['id']}: " . $e->getMessage());
            continue;
        }
    }
    
    echo json_encode([
        'success' => true,
        'results' => $results,
        'count' => count($results)
    ]);
    
} catch (Exception $e) {
    error_log("HDB Search API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred while searching'
    ]);
}
