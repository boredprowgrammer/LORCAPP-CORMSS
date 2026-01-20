<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Get JSON input
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!isset($data['hdb_id']) || !isset($data['transfer_to']) || !isset($data['transfer_reason'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

$hdb_id = intval($data['hdb_id']);
$transfer_to = trim($data['transfer_to']);
$transfer_to_district = trim($data['transfer_to_district'] ?? '');
$transfer_reason = trim($data['transfer_reason']);
$user_id = $_SESSION['user_id'];

try {
    $db = Database::getInstance()->getConnection();
    
    // Get HDB record
    $stmt = $db->prepare("SELECT * FROM hdb_registry WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$hdb_id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$record) {
        throw new Exception('HDB record not found');
    }
    
    // Check if already transferred
    if ($record['dedication_status'] === 'transferred-out') {
        throw new Exception('Child has already been transferred');
    }
    
    // Update HDB record
    $updateStmt = $db->prepare("
        UPDATE hdb_registry 
        SET 
            dedication_status = 'transferred-out',
            transfer_to = ?,
            transfer_to_district = ?,
            transfer_reason = ?,
            transfer_date = CURDATE(),
            updated_at = NOW()
        WHERE id = ?
    ");
    $updateStmt->execute([$transfer_to, $transfer_to_district, $transfer_reason, $hdb_id]);
    
    // Log activity
    try {
        $activityStmt = $db->prepare("
            INSERT INTO hdb_activity_log (hdb_id, user_id, action, details, created_at)
            VALUES (?, ?, 'transfer_out', ?, NOW())
        ");
        $districtInfo = $transfer_to_district ? " ({$transfer_to_district})" : '';
        $activityStmt->execute([
            $hdb_id,
            $user_id,
            "Transferred out to: {$transfer_to}{$districtInfo}. Reason: {$transfer_reason}"
        ]);
    } catch (Exception $e) {
        error_log("Could not log activity: " . $e->getMessage());
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Child transferred out successfully'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
