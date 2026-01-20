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
    $db->beginTransaction();
    
    // Get PNK member details
    $stmt = $db->prepare("
        SELECT 
            p.*,
            l.local_name,
            l.local_code,
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
    
    // Check if ready for baptism (must be candidate)
    if ($member['baptism_status'] !== 'candidate') {
        throw new Exception('Member must be enrolled in R3-01 (candidate status) before baptism');
    }
    
    $district_code = $member['district_code'];
    
    // Decrypt PNK member data
    $first_name = Encryption::decrypt($member['first_name_encrypted'], $district_code);
    $middle_name = Encryption::decrypt($member['middle_name_encrypted'], $district_code);
    $last_name = Encryption::decrypt($member['last_name_encrypted'], $district_code);
    $suffix = Encryption::decrypt($member['suffix_encrypted'], $district_code);
    
    // Decrypt birthday
    $birthday = Encryption::decrypt($member['birthday_encrypted'], $district_code);
    
    // Decrypt parent/guardian data
    $parent_guardian = Encryption::decrypt($member['parent_guardian_encrypted'], $district_code);
    // Parse "Father / Mother" format
    $parent_parts = explode(' / ', $parent_guardian);
    $father_name = isset($parent_parts[0]) ? trim($parent_parts[0]) : 'N/A';
    $mother_name = isset($parent_parts[1]) ? trim($parent_parts[1]) : 'N/A';
    
    // Decrypt address fields
    $address_street = Encryption::decrypt($member['address_street_encrypted'], $district_code);
    $address_barangay = Encryption::decrypt($member['address_barangay_encrypted'], $district_code);
    $address_city = Encryption::decrypt($member['address_city_encrypted'], $district_code);
    $address_province = Encryption::decrypt($member['address_province_encrypted'], $district_code);
    
    // Use existing PNK registry number for Tarheta
    $tarheta_registry_number = $member['registry_number'];
    
    // Encrypt data for Tarheta
    $tarheta_first_name_enc = Encryption::encrypt($first_name, $district_code);
    $tarheta_middle_name_enc = Encryption::encrypt($middle_name, $district_code);
    $tarheta_last_name_enc = Encryption::encrypt($last_name, $district_code);
    $tarheta_suffix_enc = Encryption::encrypt($suffix, $district_code);
    $tarheta_father_enc = Encryption::encrypt($father_name, $district_code);
    $tarheta_mother_enc = Encryption::encrypt($mother_name, $district_code);
    $tarheta_street_enc = Encryption::encrypt($address_street, $district_code);
    $tarheta_barangay_enc = Encryption::encrypt($address_barangay, $district_code);
    $tarheta_city_enc = Encryption::encrypt($address_city, $district_code);
    $tarheta_province_enc = Encryption::encrypt($address_province, $district_code);
    
    // Insert into Tarheta registry
    $insertStmt = $db->prepare("
        INSERT INTO tarheta (
            registry_number,
            local_code,
            first_name_encrypted,
            middle_name_encrypted,
            last_name_encrypted,
            suffix_encrypted,
            date_of_birth,
            gender,
            father_name_encrypted,
            mother_name_encrypted,
            address_street_encrypted,
            address_barangay_encrypted,
            address_city_encrypted,
            address_province_encrypted,
            baptism_date,
            baptism_place,
            previous_registry_type,
            previous_registry_id,
            status,
            created_by,
            created_at,
            updated_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, 'PNK', ?, 'active', ?, NOW(), NOW()
        )
    ");
    
    $baptism_place = $member['local_name'];
    
    $insertStmt->execute([
        $tarheta_registry_number,
        $local_code,
        $tarheta_first_name_enc,
        $tarheta_middle_name_enc,
        $tarheta_last_name_enc,
        $tarheta_suffix_enc,
        $birthday,
        $member['gender'],
        $tarheta_father_enc,
        $tarheta_mother_enc,
        $tarheta_street_enc,
        $tarheta_barangay_enc,
        $tarheta_city_enc,
        $tarheta_province_enc,
        $baptism_place,
        $pnk_id,
        $user_id
    ]);
    
    $tarheta_id = $db->lastInsertId();
    
    // Update PNK record - mark as baptized and transferred
    $updateStmt = $db->prepare("
        UPDATE pnk_registry 
        SET 
            baptism_status = 'baptized',
            attendance_status = 'baptized',
            baptism_date = CURDATE(),
            promoted_to_tarheta = 1,
            tarheta_id = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $updateStmt->execute([$tarheta_id, $pnk_id]);
    
    // Log activity for PNK
    try {
        $activityStmt = $db->prepare("
            INSERT INTO pnk_activity_log (pnk_id, user_id, action, details, created_at)
            VALUES (?, ?, 'baptized_promoted', ?, NOW())
        ");
        $activityStmt->execute([
            $pnk_id,
            $user_id,
            "Baptized and promoted to Tarheta: $tarheta_registry_number"
        ]);
    } catch (Exception $e) {
        error_log("Could not log PNK activity: " . $e->getMessage());
    }
    
    // Log activity for Tarheta
    try {
        $tarhetaActivityStmt = $db->prepare("
            INSERT INTO tarheta_activity_log (tarheta_id, user_id, action, details, created_at)
            VALUES (?, ?, 'baptism_promotion', ?, NOW())
        ");
        $tarhetaActivityStmt->execute([
            $tarheta_id,
            $user_id,
            "Promoted from PNK registry after baptism"
        ]);
    } catch (Exception $e) {
        error_log("Could not log Tarheta activity: " . $e->getMessage());
    }
    
    $db->commit();
    
    $full_name = trim("$first_name $middle_name $last_name");
    
    echo json_encode([
        'success' => true,
        'message' => "$full_name has been baptized and promoted to Tarheta registry",
        'tarheta' => [
            'id' => $tarheta_id,
            'registry_number' => $tarheta_registry_number,
            'name' => $full_name
        ]
    ]);
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
