<?php
/**
 * Transfer PNK Member Out
 * Marks a PNK member as transferred to another local congregation
 */

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

Security::requireLogin();
requirePermission('can_view_reports');

$currentUser = getCurrentUser();

// Only admin and local accounts can transfer-out
if (!in_array($currentUser['role'], ['admin', 'local'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
    exit;
}

$db = Database::getInstance()->getConnection();

try {
    // Get POST data
    $input = json_decode(file_get_contents('php://input'), true);
    
    $pnkId = isset($input['pnk_id']) ? intval($input['pnk_id']) : 0;
    $transferTo = trim($input['transfer_to'] ?? '');
    $transferToDistrict = trim($input['transfer_to_district'] ?? '');
    $transferReason = trim($input['transfer_reason'] ?? '');
    
    // Validate required fields
    if (!$pnkId) {
        throw new Exception('PNK member ID is required');
    }
    
    if (empty($transferTo)) {
        throw new Exception('Transfer destination is required');
    }
    
    if (empty($transferReason)) {
        throw new Exception('Transfer reason is required');
    }
    
    // Get PNK member record
    $stmt = $db->prepare("SELECT * FROM pnk_registry WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$pnkId]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$member) {
        throw new Exception('PNK member not found');
    }
    
    // Check if already transferred
    if ($member['attendance_status'] === 'transferred-out') {
        throw new Exception('Member is already transferred out');
    }
    
    // Check access to district
    if (!hasDistrictAccess($member['district_code'])) {
        throw new Exception('You do not have access to this member\'s district');
    }
    
    // Update member status
    $stmt = $db->prepare("
        UPDATE pnk_registry 
        SET attendance_status = 'transferred-out',
            transfer_to = ?,
            transfer_to_district = ?,
            transfer_reason = ?,
            transfer_date = CURDATE(),
            updated_at = NOW()
        WHERE id = ?
    ");
    
    $stmt->execute([
        $transferTo,
        $transferToDistrict,
        $transferReason,
        $pnkId
    ]);
    
    // Log activity (with try-catch in case table doesn't exist)
    try {
        $stmt = $db->prepare("
            INSERT INTO pnk_activity_log (
                pnk_id,
                action,
                description,
                user_id,
                created_at
            ) VALUES (?, 'transfer_out', ?, ?, NOW())
        ");
        
        $description = sprintf(
            "PNK member transferred to %s%s. Reason: %s",
            $transferTo,
            $transferToDistrict ? " ({$transferToDistrict})" : '',
            $transferReason
        );
        
        $stmt->execute([
            $pnkId,
            $description,
            $currentUser['user_id']
        ]);
    } catch (PDOException $e) {
        // Table might not exist, log but don't fail
        error_log("PNK activity log failed: " . $e->getMessage());
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'PNK member transferred out successfully'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
