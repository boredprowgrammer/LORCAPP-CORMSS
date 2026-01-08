<?php
/**
 * Create Overseers Contact API
 */
// Suppress warnings and errors from output
error_reporting(E_ERROR | E_PARSE);
ob_start();

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/encryption.php';

// Clean any output buffer before sending JSON
ob_clean();
header('Content-Type: application/json');

Security::requireLogin();

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Check permissions
if (!in_array($currentUser['role'], ['admin', 'district', 'local'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    // Get POST data
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    $required = ['contactType', 'district', 'local'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }
    
    // Validate type-specific fields
    if ($data['contactType'] === 'grupo' && empty($data['purokGrupo'])) {
        throw new Exception("Purok Grupo is required for Grupo level");
    }
    if ($data['contactType'] === 'purok' && empty($data['purok'])) {
        throw new Exception("Purok is required for Purok level");
    }
    
    // Encrypt officer IDs
    $katiwalaIds = encryptOfficerIds($data['katiwalaOfficerId'] ?? '', $data['district']);
    $iiKatiwalaIds = encryptOfficerIds($data['iiKatiwalaOfficerId'] ?? '', $data['district']);
    $kalihimIds = encryptOfficerIds($data['kalihimOfficerId'] ?? '', $data['district']);
    
    // Insert contact
    $stmt = $db->prepare("
        INSERT INTO overseers_contacts (
            contact_type,
            district_code,
            local_code,
            purok_grupo,
            purok,
            katiwala_officer_ids,
            katiwala_manual_name,
            ii_katiwala_officer_ids,
            ii_katiwala_manual_name,
            kalihim_officer_ids,
            kalihim_manual_name,
            katiwala_contact,
            katiwala_telegram,
            ii_katiwala_contact,
            ii_katiwala_telegram,
            kalihim_contact,
            kalihim_telegram,
            created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $data['contactType'],
        $data['district'],
        $data['local'],
        $data['purokGrupo'] ?? null,
        $data['purok'] ?? null,
        $katiwalaIds,
        $data['katiwalaName'] ?? null,
        $iiKatiwalaIds,
        $data['iiKatiwalaName'] ?? null,
        $kalihimIds,
        $data['kalihimName'] ?? null,
        $data['katiwalaContact'] ?? null,
        $data['katiwalaTelegram'] ?? null,
        $data['iiKatiwalaContact'] ?? null,
        $data['iiKatiwalaTelegram'] ?? null,
        $data['kalihimContact'] ?? null,
        $data['kalihimTelegram'] ?? null,
        $currentUser['user_id']
    ]);
    
    $contactId = $db->lastInsertId();
    
    // Log audit
    logAudit($contactId, 'create', null, $data, $currentUser['user_id'], $db);
    
    echo json_encode([
        'success' => true,
        'message' => 'Contact created successfully',
        'contact_id' => $contactId
    ]);
    
} catch (Exception $e) {
    error_log("Error creating overseers contact: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Encrypt officer IDs as JSON array
 */
function encryptOfficerIds($officerIdString, $districtCode) {
    if (empty($officerIdString)) {
        return null;
    }
    
    // Support comma-separated IDs or single ID
    $ids = array_map('trim', explode(',', $officerIdString));
    $ids = array_filter($ids, 'is_numeric');
    
    if (empty($ids)) {
        return null;
    }
    
    $json = json_encode(array_map('intval', $ids));
    return Encryption::encrypt($json, $districtCode);
}

/**
 * Log audit trail
 */
function logAudit($contactId, $action, $oldValues, $newValues, $userId, $db) {
    try {
        $stmt = $db->prepare("
            INSERT INTO overseers_contacts_audit (
                contact_id, action, old_values, new_values, changed_by, ip_address, user_agent
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $contactId,
            $action,
            $oldValues ? json_encode($oldValues) : null,
            $newValues ? json_encode($newValues) : null,
            $userId,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (Exception $e) {
        error_log("Audit log error: " . $e->getMessage());
    }
}
