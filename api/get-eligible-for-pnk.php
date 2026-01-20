<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/encryption.php';

header('Content-Type: application/json');

try {
    Security::requireLogin();
    
    $currentUser = getCurrentUser();
    $db = Database::getInstance()->getConnection();
    $needsAccessRequest = ($currentUser['role'] === 'local_cfo' || $currentUser['role'] === 'local_limited');
    
    // If user needs access, verify they have approved access
    if ($needsAccessRequest) {
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
    
    // Fetch HDB records that haven't been promoted to PNK
    $stmt = $db->prepare("
        SELECT h.*, 
               d.district_name,
               l.local_name,
               l.local_code
        FROM hdb_registry h
        LEFT JOIN districts d ON h.district_code = d.district_code
        LEFT JOIN local_congregations l ON h.local_code = l.local_code
        WHERE h.dedication_status = 'active'
          AND h.deleted_at IS NULL
        ORDER BY h.created_at DESC
        LIMIT 200
    ");
    $stmt->execute();
    $records = $stmt->fetchAll();
    
    $eligibleChildren = [];
    $today = new DateTime();
    
    foreach ($records as $record) {
        try {
            // Decrypt the data
            $decryptedFirstName = Encryption::decrypt($record['child_first_name_encrypted'], $record['district_code']);
            $decryptedMiddleName = Encryption::decrypt($record['child_middle_name_encrypted'], $record['district_code']);
            $decryptedLastName = Encryption::decrypt($record['child_last_name_encrypted'], $record['district_code']);
            $decryptedDateOfBirth = Encryption::decrypt($record['child_birthday_encrypted'], $record['district_code']);
            
            if ($decryptedDateOfBirth) {
                // Calculate age
                $dob = new DateTime($decryptedDateOfBirth);
                $age = $today->diff($dob)->y;
                
                // Check if eligible (4 years or older)
                if ($age >= 4) {
                    // Decrypt registry number if encrypted
                    $registryNumber = $record['registry_number'];
                    if (strpos($registryNumber, 'HDB-') !== 0) {
                        try {
                            $registryNumber = Encryption::decrypt($registryNumber, $record['district_code']);
                        } catch (Exception $e) {
                            // Use as-is if decryption fails
                        }
                    }
                    
                    $eligibleChildren[] = [
                        'id' => $record['id'],
                        'registry_number' => $registryNumber,
                        'first_name' => $decryptedFirstName,
                        'middle_name' => $decryptedMiddleName,
                        'last_name' => $decryptedLastName,
                        'date_of_birth' => $decryptedDateOfBirth,
                        'age' => $age,
                        'district_name' => $record['district_name'],
                        'local_name' => $record['local_name'],
                        'local_code' => $record['local_code'],
                        'created_at' => $record['created_at']
                    ];
                }
            }
        } catch (Exception $e) {
            // Skip records that can't be decrypted
            error_log("Failed to decrypt HDB record {$record['id']}: " . $e->getMessage());
            continue;
        }
    }
    
    // Sort by age descending (oldest first)
    usort($eligibleChildren, function($a, $b) {
        return $b['age'] - $a['age'];
    });
    
    echo json_encode([
        'success' => true,
        'results' => $eligibleChildren,
        'count' => count($eligibleChildren)
    ]);
    
} catch (Exception $e) {
    error_log("Error fetching eligible children: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch eligible children'
    ]);
}
