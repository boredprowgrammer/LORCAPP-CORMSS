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

if (!isset($data['pnk_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'PNK ID is required']);
    exit;
}

$pnk_id = intval($data['pnk_id']);
$user_id = $_SESSION['user_id'];

try {
    $db = Database::getInstance()->getConnection();
    
    // Get PNK member details
    $stmt = $db->prepare("
        SELECT 
            p.*,
            l.local_name,
            l.district_code
        FROM pnk_registry p
        LEFT JOIN local_congregations l ON p.local_code = l.local_code
        WHERE p.id = ?
    ");
    $stmt->execute([$pnk_id]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$member) {
        throw new Exception('PNK member not found');
    }
    
    // Check if enlisted in R3-01
    if ($member['baptism_status'] !== 'r301') {
        throw new Exception('Member must be enrolled in R3-01 first (candidate status)');
    }
    
    // Note: Keep baptism_status as 'r301' - will change to 'baptized' when actually baptized
    // Just update the timestamp to track when they were marked ready
    $updateStmt = $db->prepare("
        UPDATE pnk_registry 
        SET 
            updated_at = NOW()
        WHERE id = ?
    ");
    $updateStmt->execute([$pnk_id]);
    
    // Log activity
    try {
        $activityStmt = $db->prepare("
            INSERT INTO pnk_activity_log (pnk_id, user_id, action, details, created_at)
            VALUES (?, ?, 'ready_for_baptism', ?, NOW())
        ");
        $activityStmt->execute([
            $pnk_id,
            $user_id,
            'Marked as ready for baptism'
        ]);
    } catch (Exception $e) {
        // Activity log is optional
        error_log("Could not log activity: " . $e->getMessage());
    }
    
    // Decrypt name for response
    $district_code = $member['district_code'];
    $first_name = Encryption::decrypt($member['first_name_encrypted'], $district_code);
    $middle_name = Encryption::decrypt($member['middle_name_encrypted'], $district_code);
    $last_name = Encryption::decrypt($member['last_name_encrypted'], $district_code);
    $full_name = trim("$first_name $middle_name $last_name");
    
    echo json_encode([
        'success' => true,
        'message' => "$full_name is now ready for baptism",
        'member' => [
            'id' => $pnk_id,
            'name' => $full_name,
            'baptism_status' => 'r301'
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
