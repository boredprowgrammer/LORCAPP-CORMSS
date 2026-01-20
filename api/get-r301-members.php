<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Get R3-01 enrolled members (candidate status)
    $stmt = $db->prepare("
        SELECT 
            p.*,
            l.local_name,
            l.local_code,
            l.district_code,
            DATE_FORMAT(p.updated_at, '%b %d, %Y') as r301_date
        FROM pnk_registry p
        LEFT JOIN local_congregations l ON p.local_code = l.local_code
        WHERE p.baptism_status = 'r301'
        AND p.attendance_status = 'active'
        ORDER BY p.updated_at DESC, p.last_name_encrypted ASC
        LIMIT 200
    ");
    $stmt->execute();
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $results = [];
    $today = new DateTime();
    
    foreach ($members as $member) {
        $district_code = $member['district_code'];
        
        // Decrypt member data
        $first_name = Encryption::decrypt($member['first_name_encrypted'], $district_code);
        $middle_name = Encryption::decrypt($member['middle_name_encrypted'], $district_code);
        $last_name = Encryption::decrypt($member['last_name_encrypted'], $district_code);
        
        // Decrypt birthday to calculate age
        $birthday = Encryption::decrypt($member['birthday_encrypted'], $district_code);
        $birthDate = new DateTime($birthday);
        $age = $birthDate->diff($today)->y;
        
        // Decrypt registry number if needed
        $registry_number = $member['registry_number'];
        if (!preg_match('/^PNK-/', $registry_number)) {
            try {
                $registry_number = Encryption::decrypt($registry_number, $district_code);
            } catch (Exception $e) {
                // Keep original if decryption fails
            }
        }
        
        $results[] = [
            'id' => $member['id'],
            'first_name' => $first_name,
            'middle_name' => $middle_name,
            'last_name' => $last_name,
            'registry_number' => $registry_number,
            'age' => $age,
            'baptism_status' => $member['baptism_status'],
            'r301_date' => $member['r301_date'],
            'pnk_category' => $member['pnk_category'],
            'local_name' => $member['local_name'],
            'local_code' => $member['local_code']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'count' => count($results),
        'results' => $results
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
