<?php
/**
 * Get Officer Details API
 * Returns complete officer information for display in modals
 */

require_once __DIR__ . '/../config/config.php';

Security::requireLogin();

header('Content-Type: application/json');

$uuid = Security::sanitizeInput($_GET['uuid'] ?? '');
$officerId = Security::sanitizeInput($_GET['officer_id'] ?? '');

if (empty($uuid) && empty($officerId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Officer UUID or ID is required']);
    exit;
}

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

try {
    // Fetch officer basic information
    $whereClause = !empty($uuid) ? 'WHERE o.officer_uuid = ?' : 'WHERE o.officer_id = ?';
    $param = !empty($uuid) ? $uuid : $officerId;
    
    $stmt = $db->prepare("
        SELECT 
            o.officer_id,
            o.officer_uuid,
            o.last_name_encrypted,
            o.first_name_encrypted,
            o.middle_initial_encrypted,
            o.district_code,
            o.local_code,
            o.is_active,
            o.purok,
            o.grupo,
            o.control_number,
            o.registry_number_encrypted,
            lc.local_name,
            d.district_name
        FROM officers o
        LEFT JOIN local_congregations lc ON o.local_code = lc.local_code
        LEFT JOIN districts d ON o.district_code = d.district_code
        $whereClause
    ");
    
    $stmt->execute([$param]);
    $officer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$officer) {
        http_response_code(404);
        echo json_encode(['error' => 'Officer not found']);
        exit;
    }
    
    // Check permissions - users can only view officers in their scope
    if ($currentUser['role'] === 'local' && $officer['local_code'] !== $currentUser['local_code']) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }
    
    if ($currentUser['role'] === 'district' && $officer['district_code'] !== $currentUser['district_code']) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }
    
    // Decrypt officer name
    $decrypted = Encryption::decryptOfficerName(
        $officer['last_name_encrypted'],
        $officer['first_name_encrypted'],
        $officer['middle_initial_encrypted'],
        $officer['district_code']
    );
    
    // Decrypt registry number if exists
    $registryNumber = '';
    if (!empty($officer['registry_number_encrypted'])) {
        try {
            $registryNumber = Encryption::decrypt($officer['registry_number_encrypted'], $officer['district_code']);
        } catch (Exception $e) {
            error_log("Registry number decryption error: " . $e->getMessage());
            $registryNumber = '';
        }
    }
    
    // Fetch departments
    $deptStmt = $db->prepare("
        SELECT 
            od.id,
            od.department,
            od.duty,
            od.is_active,
            od.oath_date,
            od.assigned_at,
            r.removal_code,
            r.reason as removal_reason,
            t.transfer_type,
            t.transfer_date
        FROM officer_departments od
        LEFT JOIN officer_removals r ON r.department_id = od.id AND r.officer_id = od.officer_id
        LEFT JOIN transfers t ON t.officer_id = od.officer_id AND t.transfer_type = 'out'
        WHERE od.officer_id = ?
        ORDER BY od.is_active DESC, od.department ASC
    ");
    
    $deptStmt->execute([$officer['officer_id']]);
    $departments = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Build response with obfuscated names for security
    $fullName = trim($decrypted['first_name'] . ' ' . 
                    ($decrypted['middle_initial'] ? $decrypted['middle_initial'] . '. ' : '') . 
                    $decrypted['last_name']);
    
    $response = [
        'officer_id' => $officer['officer_id'],
        'officer_uuid' => $officer['officer_uuid'],
        'full_name' => obfuscateName($fullName),
        'last_name' => obfuscateName($decrypted['last_name']),
        'first_name' => obfuscateName($decrypted['first_name']),
        'middle_initial' => $decrypted['middle_initial'],
        'is_active' => (bool) $officer['is_active'],
        'district_code' => $officer['district_code'],
        'district_name' => $officer['district_name'] ?? '',
        'local_code' => $officer['local_code'],
        'local_name' => $officer['local_name'] ?? '',
        'purok' => $officer['purok'] ?? '',
        'grupo' => $officer['grupo'] ?? '',
        'control_number' => $officer['control_number'] ?? '',
        'registry_number' => $registryNumber,
        'departments' => $departments
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log("Get officer details API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
?>
