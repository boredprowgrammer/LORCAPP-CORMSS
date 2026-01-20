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
    
    // Decrypt birthday to calculate age
    $district_code = $member['district_code'];
    $birthday = Encryption::decrypt($member['birthday_encrypted'], $district_code);
    $birthDate = new DateTime($birthday);
    $today = new DateTime();
    $age = $birthDate->diff($today)->y;
    
    // Check if already enrolled in R3-01
    if ($member['baptism_status'] === 'r301') {
        throw new Exception('Member is already enrolled in R3-01');
    }
    
    // Check age eligibility (12+ years)
    if ($age < 12) {
        throw new Exception('Member must be at least 12 years old for R3-01');
    }
    
    // Check if already baptized
    if ($member['baptism_status'] === 'baptized') {
        throw new Exception('Member is already baptized');
    }
    
    // Update PNK record to mark as enlisted in R3-01
    $updateStmt = $db->prepare("
        UPDATE pnk_registry 
        SET 
            baptism_status = 'r301',
            updated_at = NOW()
        WHERE id = ?
    ");
    $updateStmt->execute([$pnk_id]);
    
    // Log activity
    try {
        $activityStmt = $db->prepare("
            INSERT INTO pnk_activity_log (pnk_id, user_id, action, details, created_at)
            VALUES (?, ?, 'r301_enrollment', ?, NOW())
        ");
        $activityStmt->execute([
            $pnk_id,
            $user_id,
            'Enrolled in R3-01 baptismal preparation'
        ]);
    } catch (Exception $e) {
        // Activity log is optional, don't fail if table doesn't exist
        error_log("Could not log activity: " . $e->getMessage());
    }
    
    // Decrypt name for response
    $first_name = Encryption::decrypt($member['first_name_encrypted'], $district_code);
    $middle_name = Encryption::decrypt($member['middle_name_encrypted'], $district_code);
    $last_name = Encryption::decrypt($member['last_name_encrypted'], $district_code);
    $full_name = trim("$first_name $middle_name $last_name");
    
    echo json_encode([
        'success' => true,
        'message' => "$full_name has been enrolled in R3-01 baptismal preparation",
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
