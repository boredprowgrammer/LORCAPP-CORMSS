<?php
/**
 * Transfer PNK Member In (Receive from Another Local)
 * Creates a new PNK record for a member transferred from another congregation
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
    
    $firstName = trim($input['first_name'] ?? '');
    $middleName = trim($input['middle_name'] ?? '');
    $lastName = trim($input['last_name'] ?? '');
    $birthday = $input['birthday'] ?? '';
    $fatherName = trim($input['father_name'] ?? '');
    $motherName = trim($input['mother_name'] ?? '');
    $address = trim($input['address'] ?? '');
    $dako = trim($input['dako'] ?? '');
    $baptismStatus = $input['baptism_status'] ?? 'active';
    $attendanceStatus = $input['attendance_status'] ?? 'active';
    $transferFrom = trim($input['transfer_from'] ?? '');
    $transferFromDistrict = trim($input['transfer_from_district'] ?? '');
    $transferDate = $input['transfer_date'] ?? date('Y-m-d');
    $transferReason = trim($input['transfer_reason'] ?? '');
    
    // Validate required fields
    if (empty($firstName)) {
        throw new Exception('First name is required');
    }
    
    if (empty($lastName)) {
        throw new Exception('Last name is required');
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
    
    // Validate baptism status
    $validBaptismStatuses = ['not_baptized', 'baptized', 'candidate'];
    if (!in_array($baptismStatus, $validBaptismStatuses)) {
        throw new Exception('Invalid baptism status');
    }
    
    // Validate attendance status
    $validAttendanceStatuses = ['active', 'inactive', 'transferred-out', 'graduated'];
    if (!in_array($attendanceStatus, $validAttendanceStatuses)) {
        throw new Exception('Invalid attendance status');
    }
    
    // Get user's local and district codes
    $localCode = $currentUser['local_code'] ?? null;
    $districtCode = $currentUser['district_code'] ?? null;
    
    if (!$localCode || !$districtCode) {
        throw new Exception('User local or district code not found');
    }
    
    // Generate registry number
    $year = date('Y');
    
    // Get next sequence number for this local and year
    $stmt = $db->prepare("
        SELECT MAX(CAST(SUBSTRING_INDEX(registry_number, '-', -1) AS UNSIGNED)) as max_seq
        FROM pnk_registry
        WHERE local_code = ? AND YEAR(created_at) = ?
    ");
    $stmt->execute([$localCode, $year]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $nextSeq = ($result['max_seq'] ?? 0) + 1;
    
    $registryNumber = sprintf('PNK-%s-%s-%04d', $localCode, $year, $nextSeq);
    
    // Encrypt personal data with district code
    $firstNameEncrypted = Encryption::encrypt($firstName, $districtCode);
    $middleNameEncrypted = Encryption::encrypt($middleName, $districtCode);
    $lastNameEncrypted = Encryption::encrypt($lastName, $districtCode);
    $birthdayEncrypted = Encryption::encrypt($birthday, $districtCode);
    
    // Combine parent names
    $parentGuardian = '';
    if ($fatherName || $motherName) {
        $parentGuardian = ($fatherName ?: 'N/A') . ' / ' . ($motherName ?: 'N/A');
    }
    $parentGuardianEncrypted = $parentGuardian ? Encryption::encrypt($parentGuardian, $districtCode) : null;
    
    $addressEncrypted = $address ? Encryption::encrypt($address, $districtCode) : null;
    $dakoEncrypted = $dako ? Encryption::encrypt($dako, $districtCode) : null;
    
    // Insert into PNK registry
    $stmt = $db->prepare("
        INSERT INTO pnk_registry (
            registry_number,
            first_name_encrypted,
            middle_name_encrypted,
            last_name_encrypted,
            birthday_encrypted,
            parent_guardian_encrypted,
            address_encrypted,
            dako_encrypted,
            local_code,
            district_code,
            baptism_status,
            attendance_status,
            transfer_from,
            transfer_from_district,
            transfer_date,
            transfer_reason,
            created_by,
            created_at,
            updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    
    $stmt->execute([
        $registryNumber,
        $firstNameEncrypted,
        $middleNameEncrypted,
        $lastNameEncrypted,
        $birthdayEncrypted,
        $parentGuardianEncrypted,
        $addressEncrypted,
        $dakoEncrypted,
        $localCode,
        $districtCode,
        $baptismStatus,
        $attendanceStatus,
        $transferFrom,
        $transferFromDistrict,
        $transferDate,
        $transferReason,
        $currentUser['user_id']
    ]);
    
    $newPnkId = $db->lastInsertId();
    
    // Log activity (with try-catch in case table doesn't exist)
    try {
        $stmt = $db->prepare("
            INSERT INTO pnk_activity_log (
                pnk_id,
                action,
                description,
                user_id,
                created_at
            ) VALUES (?, 'transfer_in', ?, ?, NOW())
        ");
        
        $description = sprintf(
            "PNK member transferred in from %s%s. Reason: %s",
            $transferFrom,
            $transferFromDistrict ? " ({$transferFromDistrict})" : '',
            $transferReason ?: 'Not specified'
        );
        
        $stmt->execute([
            $newPnkId,
            $description,
            $currentUser['user_id']
        ]);
    } catch (PDOException $e) {
        // Table might not exist, log but don't fail
        error_log("PNK activity log failed: " . $e->getMessage());
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'PNK member successfully transferred in',
        'pnk_id' => $newPnkId,
        'registry_number' => $registryNumber
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
