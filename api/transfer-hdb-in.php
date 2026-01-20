<?php
/**
 * Transfer HDB Child In (Receive from Another Local)
 * Creates a new HDB record for a child transferred from another congregation
 */

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

Security::requireLogin();
requirePermission('can_view_reports');

$currentUser = getCurrentUser();

// Only admin and local accounts can transfer-in
if (!in_array($currentUser['role'], ['admin', 'local'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
    exit;
}

$db = Database::getInstance()->getConnection();

try {
    // Get POST data
    $input = json_decode(file_get_contents('php://input'), true);
    
    $childName = trim($input['child_name'] ?? '');
    $birthday = $input['birthday'] ?? '';
    $fatherName = trim($input['father_name'] ?? '');
    $motherName = trim($input['mother_name'] ?? '');
    $address = trim($input['address'] ?? '');
    $dedicationStatus = 'active'; // All transferred-in HDB entries are active
    $transferFrom = trim($input['transfer_from'] ?? '');
    $transferFromDistrict = trim($input['transfer_from_district'] ?? '');
    $transferDate = $input['transfer_date'] ?? date('Y-m-d');
    $transferReason = trim($input['transfer_reason'] ?? '');
    
    // Validate required fields
    if (empty($childName)) {
        throw new Exception('Child name is required');
    }
    
    if (empty($birthday)) {
        throw new Exception('Birthday is required');
    }
    
    if (empty($transferFrom)) {
        throw new Exception('Transfer from local is required');
    }
    
    // Validate birthday format and date
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday)) {
        throw new Exception('Invalid birthday format');
    }
    
    $birthdayDate = DateTime::createFromFormat('Y-m-d', $birthday);
    if (!$birthdayDate || $birthdayDate->format('Y-m-d') !== $birthday) {
        throw new Exception('Invalid birthday date');
    }
    
    // Get user's local and district codes
    $localCode = $currentUser['local_code'] ?? null;
    $districtCode = $currentUser['district_code'] ?? null;
    
    if (!$localCode || !$districtCode) {
        throw new Exception('User local or district code not found');
    }
    
    // Encrypt personal data with district code
    $childNameEncrypted = Encryption::encrypt($childName, $districtCode);
    $birthdayEncrypted = Encryption::encrypt($birthday, $districtCode);
    $fatherNameEncrypted = $fatherName ? Encryption::encrypt($fatherName, $districtCode) : null;
    $motherNameEncrypted = $motherName ? Encryption::encrypt($motherName, $districtCode) : null;
    $addressEncrypted = $address ? Encryption::encrypt($address, $districtCode) : null;
    
    // Insert into HDB registry
    $stmt = $db->prepare("
        INSERT INTO hdb_registry (
            child_name_encrypted,
            birthday_encrypted,
            father_name_encrypted,
            mother_name_encrypted,
            address_encrypted,
            local_code,
            district_code,
            dedication_status,
            transfer_from,
            transfer_from_district,
            transfer_date,
            transfer_reason,
            created_by,
            created_at,
            updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    
    $stmt->execute([
        $childNameEncrypted,
        $birthdayEncrypted,
        $fatherNameEncrypted,
        $motherNameEncrypted,
        $addressEncrypted,
        $localCode,
        $districtCode,
        $dedicationStatus,
        $transferFrom,
        $transferFromDistrict,
        $transferDate,
        $transferReason,
        $currentUser['user_id']
    ]);
    
    $newHdbId = $db->lastInsertId();
    
    // Log activity (with try-catch in case table doesn't exist)
    try {
        $stmt = $db->prepare("
            INSERT INTO hdb_activity_log (
                hdb_id,
                action,
                description,
                user_id,
                created_at
            ) VALUES (?, 'transfer_in', ?, ?, NOW())
        ");
        
        $description = sprintf(
            "Child transferred in from %s%s. Reason: %s",
            $transferFrom,
            $transferFromDistrict ? " ({$transferFromDistrict})" : '',
            $transferReason ?: 'Not specified'
        );
        
        $stmt->execute([
            $newHdbId,
            $description,
            $currentUser['user_id']
        ]);
    } catch (PDOException $e) {
        // Table might not exist, log but don't fail
        error_log("HDB activity log failed: " . $e->getMessage());
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Child successfully transferred into HDB Registry',
        'hdb_id' => $newHdbId
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
