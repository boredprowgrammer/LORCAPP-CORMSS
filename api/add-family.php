<?php
/**
 * Create/Add Family API
 * Creates a new family with head and members
 */

require_once __DIR__ . '/../config/config.php';

Security::requireLogin();

header('Content-Type: application/json');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Check permission
if (!hasPermission('can_add_officers')) {
    echo json_encode(['success' => false, 'error' => 'You do not have permission to create families.']);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        throw new Exception('Invalid JSON data');
    }
    
    // Validate required fields
    if (empty($data['head'])) {
        throw new Exception('Pangulo ng Sambahayan is required');
    }
    
    $head = $data['head'];
    $members = $data['members'] ?? [];
    $notes = Security::sanitizeInput($data['notes'] ?? '');
    $address = Security::sanitizeInput($data['address'] ?? '');
    $contact = Security::sanitizeInput($data['contact'] ?? '');
    
    // Get head member details
    $districtCode = $head['district_code'];
    $localCode = $head['local_code'];
    $purok = $head['purok'] ?? null;
    $grupo = $head['grupo'] ?? null;
    
    // Encrypt family name (use head's last name)
    $familyName = $head['last_name'] ?? 'Unknown';
    $familyNameEnc = Encryption::encrypt($familyName, $districtCode);
    $addressEnc = !empty($address) ? Encryption::encrypt($address, $districtCode) : null;
    $contactEnc = !empty($contact) ? Encryption::encrypt($contact, $districtCode) : null;
    
    $db->beginTransaction();
    
    // Create family
    $stmt = $db->prepare("
        INSERT INTO families (
            head_member_id, head_source, district_code, local_code,
            purok, grupo, family_name_encrypted, address_encrypted, contact_encrypted,
            status, notes, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, NOW())
    ");
    
    $stmt->execute([
        $head['id'],
        $head['source'],
        $districtCode,
        $localCode,
        $purok,
        $grupo,
        $familyNameEnc,
        $addressEnc,
        $contactEnc,
        $notes,
        $currentUser['user_id']
    ]);
    
    $familyId = $db->lastInsertId();
    
    // Add head as first member
    $headNameEnc = Encryption::encrypt($head['name'], $districtCode);
    $headBirthdayEnc = !empty($head['birthday']) ? Encryption::encrypt($head['birthday'], $districtCode) : null;
    
    $stmt = $db->prepare("
        INSERT INTO family_members (
            family_id, source_type, source_id, name_encrypted, birthday_encrypted,
            relationship, kapisanan, sort_order, is_head, status, created_at
        ) VALUES (?, ?, ?, ?, ?, 'indibidwal', ?, 0, TRUE, 'active', NOW())
    ");
    
    $stmt->execute([
        $familyId,
        $head['source'],
        $head['id'],
        $headNameEnc,
        $headBirthdayEnc,
        $head['kapisanan']
    ]);
    
    // Add other members
    $sortOrder = 1;
    foreach ($members as $member) {
        $memberNameEnc = Encryption::encrypt($member['name'], $districtCode);
        $memberBirthdayEnc = !empty($member['birthday']) ? Encryption::encrypt($member['birthday'], $districtCode) : null;
        $relationship = $member['relationship'] ?? 'others';
        $relationshipSpecify = $member['relationship_specify'] ?? null;
        
        $stmt = $db->prepare("
            INSERT INTO family_members (
                family_id, source_type, source_id, name_encrypted, birthday_encrypted,
                relationship, relationship_specify, kapisanan, sort_order, is_head, status, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, FALSE, 'active', NOW())
        ");
        
        $stmt->execute([
            $familyId,
            $member['source'],
            $member['id'],
            $memberNameEnc,
            $memberBirthdayEnc,
            $relationship,
            $relationshipSpecify,
            $member['kapisanan'],
            $sortOrder
        ]);
        
        $sortOrder++;
    }
    
    $db->commit();
    
    // Log activity
    logActivity('create_family', 'families', $familyId, [
        'family_name' => $familyName,
        'head_id' => $head['id'],
        'head_source' => $head['source'],
        'member_count' => count($members) + 1
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Family created successfully',
        'family_id' => $familyId
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
