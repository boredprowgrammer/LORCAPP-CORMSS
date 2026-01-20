<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/encryption.php';

header('Content-Type: application/json');

try {
    Security::requireLogin();
    
    $currentUser = getCurrentUser();
    $db = Database::getInstance()->getConnection();
    
    // Fetch active PNK records that haven't been baptized
    $stmt = $db->prepare("
        SELECT p.*, 
               d.district_name,
               l.local_name,
               l.local_code
        FROM pnk_registry p
        LEFT JOIN districts d ON p.district_code = d.district_code
        LEFT JOIN local_congregations l ON p.local_code = l.local_code
        WHERE p.attendance_status = 'active'
          AND p.baptism_status IN ('active', 'r301')
          AND p.deleted_at IS NULL
        ORDER BY p.created_at DESC
        LIMIT 300
    ");
    $stmt->execute();
    $records = $stmt->fetchAll();
    
    $eligibleMembers = [];
    $today = new DateTime();
    
    foreach ($records as $record) {
        try {
            // Decrypt the data
            $decryptedFirstName = Encryption::decrypt($record['first_name_encrypted'], $record['district_code']);
            $decryptedMiddleName = Encryption::decrypt($record['middle_name_encrypted'], $record['district_code']);
            $decryptedLastName = Encryption::decrypt($record['last_name_encrypted'], $record['district_code']);
            $decryptedDateOfBirth = Encryption::decrypt($record['birthday_encrypted'], $record['district_code']);
            
            // Decrypt registry number
            $registryNumber = $record['registry_number'];
            if (strpos($registryNumber, 'PNK-') !== 0) {
                try {
                    $registryNumber = Encryption::decrypt($registryNumber, $record['district_code']);
                } catch (Exception $e) {
                    // Use as-is if decryption fails
                }
            }
            
            // Decrypt parent/guardian names from combined field
            $fatherFullName = 'N/A';
            $motherFullName = 'N/A';
            
            if (!empty($record['parent_guardian_encrypted'])) {
                $parentGuardian = Encryption::decrypt($record['parent_guardian_encrypted'], $record['district_code']);
                // Parse "Father Name / Mother Name" format
                if (strpos($parentGuardian, ' / ') !== false) {
                    $parts = explode(' / ', $parentGuardian, 2);
                    $fatherFullName = trim($parts[0]) ?: 'N/A';
                    $motherFullName = isset($parts[1]) ? trim($parts[1]) : 'N/A';
                } else {
                    // Single parent/guardian
                    $fatherFullName = trim($parentGuardian) ?: 'N/A';
                }
            }
            
            if ($decryptedDateOfBirth) {
                // Calculate age
                $dob = new DateTime($decryptedDateOfBirth);
                $age = $today->diff($dob)->y;
                
                // Check if eligible for baptism (12 years or older)
                if ($age >= 12) {
                    $eligibleMembers[] = [
                        'id' => $record['id'],
                        'registry_number' => $registryNumber,
                        'first_name' => $decryptedFirstName,
                        'middle_name' => $decryptedMiddleName,
                        'last_name' => $decryptedLastName,
                        'date_of_birth' => $decryptedDateOfBirth,
                        'age' => $age,
                        'category' => $record['pnk_category'],
                        'father_name' => $fatherFullName,
                        'mother_name' => $motherFullName,
                        'district_name' => $record['district_name'],
                        'local_name' => $record['local_name'],
                        'local_code' => $record['local_code'],
                        'created_at' => $record['created_at']
                    ];
                }
            }
        } catch (Exception $e) {
            // Skip records that can't be decrypted
            error_log("Failed to decrypt PNK record {$record['id']}: " . $e->getMessage());
            continue;
        }
    }
    
    // Sort by age descending (oldest first)
    usort($eligibleMembers, function($a, $b) {
        return $b['age'] - $a['age'];
    });
    
    echo json_encode([
        'success' => true,
        'results' => $eligibleMembers,
        'count' => count($eligibleMembers)
    ]);
    
} catch (Exception $e) {
    error_log("Error fetching eligible members for baptism: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch eligible members'
    ]);
}
