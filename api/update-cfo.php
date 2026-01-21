<?php
/**
 * Update CFO Information
 */

require_once __DIR__ . '/../config/config.php';

Security::requireLogin();

header('Content-Type: application/json');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Check access for local_cfo users via approved access requests
$hasEditAccess = false;
$approvedCfoTypes = [];

if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'local') {
    // Admin and local have full access (if they have can_add_officers permission)
    if (hasPermission('can_add_officers')) {
        $hasEditAccess = true;
    }
} elseif ($currentUser['role'] === 'local_cfo') {
    // Check for approved edit_member access
    $stmt = $db->prepare("
        SELECT * FROM cfo_access_requests 
        WHERE requester_user_id = ? 
        AND status = 'approved'
        AND access_mode = 'edit_member'
        AND deleted_at IS NULL
        AND (expires_at IS NULL OR expires_at > NOW())
    ");
    $stmt->execute([$currentUser['user_id']]);
    $approvedRequests = $stmt->fetchAll();
    
    if (count($approvedRequests) > 0) {
        $hasEditAccess = true;
        foreach ($approvedRequests as $request) {
            if (!in_array($request['cfo_type'], $approvedCfoTypes)) {
                $approvedCfoTypes[] = $request['cfo_type'];
            }
        }
    }
}

if (!$hasEditAccess) {
    echo json_encode(['success' => false, 'error' => 'You do not have permission to edit CFO records.']);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }
    
    $id = intval($_POST['id'] ?? 0);
    $cfoClassification = isset($_POST['cfo_classification']) ? Security::sanitizeInput($_POST['cfo_classification']) : null;
    $cfoStatus = isset($_POST['cfo_status']) ? Security::sanitizeInput($_POST['cfo_status']) : null;
    $cfoNotes = isset($_POST['cfo_notes']) ? Security::sanitizeInput($_POST['cfo_notes']) : null;
    $registrationType = isset($_POST['registration_type']) ? Security::sanitizeInput($_POST['registration_type']) : null;
    $registrationDate = isset($_POST['registration_date']) ? Security::sanitizeInput($_POST['registration_date']) : null;
    $registrationOthersSpecify = isset($_POST['registration_others_specify']) ? Security::sanitizeInput($_POST['registration_others_specify']) : null;
    $transferOutDate = isset($_POST['transfer_out_date']) ? Security::sanitizeInput($_POST['transfer_out_date']) : null;
    $marriageDate = isset($_POST['marriage_date']) ? Security::sanitizeInput($_POST['marriage_date']) : null;
    $classificationChangeDate = isset($_POST['classification_change_date']) ? Security::sanitizeInput($_POST['classification_change_date']) : null;
    $classificationChangeReason = isset($_POST['classification_change_reason']) ? Security::sanitizeInput($_POST['classification_change_reason']) : null;
    
    // Name fields
    $firstName = isset($_POST['first_name']) ? Security::sanitizeInput($_POST['first_name']) : null;
    $middleName = isset($_POST['middle_name']) ? Security::sanitizeInput($_POST['middle_name']) : null;
    $lastName = isset($_POST['last_name']) ? Security::sanitizeInput($_POST['last_name']) : null;
    $husbandsSurname = isset($_POST['husbands_surname']) ? Security::sanitizeInput($_POST['husbands_surname']) : null;
    $birthday = isset($_POST['birthday']) ? Security::sanitizeInput($_POST['birthday']) : null;
    $registryNumber = isset($_POST['registry_number']) ? Security::sanitizeInput($_POST['registry_number']) : null;
    $purok = isset($_POST['purok']) ? Security::sanitizeInput($_POST['purok']) : null;
    $grupo = isset($_POST['grupo']) ? Security::sanitizeInput($_POST['grupo']) : null;
    
    if ($id <= 0) {
        throw new Exception('Invalid ID');
    }
    
    // Validate classification if provided
    if ($cfoClassification !== null) {
        $validClassifications = ['Buklod', 'Kadiwa', 'Binhi', ''];
        if (!in_array($cfoClassification, $validClassifications)) {
            throw new Exception('Invalid CFO classification');
        }
    }
    
    // Validate status if provided
    if ($cfoStatus !== null) {
        $validStatuses = ['active', 'transferred-out'];
        if (!in_array($cfoStatus, $validStatuses)) {
            throw new Exception('Invalid status');
        }
    }
    
    // Get record to check access
    $stmt = $db->prepare("SELECT district_code, local_code FROM tarheta_control WHERE id = ?");
    $stmt->execute([$id]);
    $record = $stmt->fetch();
    
    if (!$record) {
        throw new Exception('Record not found');
    }
    
    // Check access
    if ($currentUser['role'] === 'district' && $record['district_code'] !== $currentUser['district_code']) {
        throw new Exception('Access denied');
    }
    if (($currentUser['role'] === 'local' || $currentUser['role'] === 'local_cfo') && $record['local_code'] !== $currentUser['local_code']) {
        throw new Exception('Access denied');
    }
    
    // Build dynamic UPDATE query based on provided fields
    $updateFields = [];
    $params = [];
    
    // Track if name fields are being updated to rebuild search index
    $nameFieldsUpdated = false;
    
    // Handle name fields with encryption
    if ($firstName !== null) {
        if (empty(trim($firstName))) {
            throw new Exception('First name is required');
        }
        $updateFields[] = 'first_name_encrypted = ?';
        $params[] = Encryption::encrypt($firstName, $record['district_code']);
        $nameFieldsUpdated = true;
    }
    
    if ($middleName !== null) {
        $updateFields[] = 'middle_name_encrypted = ?';
        $params[] = empty(trim($middleName)) ? null : Encryption::encrypt($middleName, $record['district_code']);
        $nameFieldsUpdated = true;
    }
    
    if ($lastName !== null) {
        if (empty(trim($lastName))) {
            throw new Exception('Last name is required');
        }
        $updateFields[] = 'last_name_encrypted = ?';
        $params[] = Encryption::encrypt($lastName, $record['district_code']);
        $nameFieldsUpdated = true;
    }
    
    if ($husbandsSurname !== null) {
        $updateFields[] = 'husbands_surname_encrypted = ?';
        $params[] = empty(trim($husbandsSurname)) || trim($husbandsSurname) === '-' ? null : Encryption::encrypt($husbandsSurname, $record['district_code']);
    }
    
    if ($birthday !== null) {
        $updateFields[] = 'birthday_encrypted = ?';
        $params[] = empty(trim($birthday)) ? null : Encryption::encrypt($birthday, $record['district_code']);
    }
    
    // Handle registry number with encryption and uniqueness check
    if ($registryNumber !== null) {
        $registryNumber = trim($registryNumber);
        
        if (empty($registryNumber)) {
            throw new Exception('Registry number cannot be empty');
        }
        
        // Normalize registry number for comparison
        $normalizedRegNum = strtoupper(str_replace(' ', '', $registryNumber));
        $registryNumberHash = hash('sha256', strtolower($normalizedRegNum));
        
        // Check if registry number already exists (excluding current record)
        $stmtCheck = $db->prepare("SELECT id FROM tarheta_control WHERE registry_number_hash = ? AND id != ?");
        $stmtCheck->execute([$registryNumberHash, $id]);
        
        if ($stmtCheck->fetch()) {
            throw new Exception('Registry number already exists');
        }
        
        $updateFields[] = 'registry_number_encrypted = ?';
        $updateFields[] = 'registry_number_hash = ?';
        $params[] = Encryption::encrypt($registryNumber, $record['district_code']);
        $params[] = $registryNumberHash;
    }
    
    // Name fields updated - no additional action needed since we encrypt directly
    
    if ($cfoClassification !== null) {
        $updateFields[] = 'cfo_classification = ?';
        $updateFields[] = 'cfo_classification_auto = 0';
        $params[] = empty($cfoClassification) ? null : $cfoClassification;
    }
    
    if ($cfoStatus !== null) {
        $updateFields[] = 'cfo_status = ?';
        $params[] = $cfoStatus;
        
        // If status is being set to transferred-out and no transfer_out_date is provided, use current date
        if ($cfoStatus === 'transferred-out' && $transferOutDate === null) {
            $transferOutDate = date('Y-m-d');
        }
    }
    
    if ($transferOutDate !== null) {
        $updateFields[] = 'transfer_out_date = ?';
        $params[] = !empty($transferOutDate) ? $transferOutDate : null;
    }
    
    if ($registrationType !== null) {
        $validTypes = ['transfer-in', 'newly-baptized', 'others', ''];
        if (!in_array($registrationType, $validTypes)) {
            throw new Exception('Invalid registration type');
        }
        $updateFields[] = 'registration_type = ?';
        $params[] = empty($registrationType) ? null : $registrationType;
    }
    
    if ($registrationDate !== null) {
        $updateFields[] = 'registration_date = ?';
        $params[] = !empty($registrationDate) ? $registrationDate : null;
    }
    
    if ($registrationOthersSpecify !== null) {
        $updateFields[] = 'registration_others_specify = ?';
        $params[] = !empty(trim($registrationOthersSpecify)) ? trim($registrationOthersSpecify) : null;
    }
    
    if ($marriageDate !== null) {
        $updateFields[] = 'marriage_date = ?';
        $params[] = !empty($marriageDate) ? $marriageDate : null;
    }
    
    if ($classificationChangeDate !== null) {
        $updateFields[] = 'classification_change_date = ?';
        $params[] = !empty($classificationChangeDate) ? $classificationChangeDate : null;
    }
    
    if ($classificationChangeReason !== null) {
        $updateFields[] = 'classification_change_reason = ?';
        $params[] = !empty(trim($classificationChangeReason)) ? trim($classificationChangeReason) : null;
    }
    
    if ($cfoNotes !== null) {
        $updateFields[] = 'cfo_notes = ?';
        $params[] = $cfoNotes;
    }
    
    // Handle purok and grupo
    if ($purok !== null) {
        $updateFields[] = 'purok = ?';
        $params[] = empty(trim($purok)) ? null : trim($purok);
    }
    
    if ($grupo !== null) {
        $updateFields[] = 'grupo = ?';
        $params[] = empty(trim($grupo)) ? null : trim($grupo);
    }
    
    // Always update timestamp and user
    $updateFields[] = 'cfo_updated_at = NOW()';
    $updateFields[] = 'cfo_updated_by = ?';
    $params[] = $currentUser['user_id'];
    
    // Add ID for WHERE clause
    $params[] = $id;
    
    if (empty($updateFields)) {
        throw new Exception('No fields to update');
    }
    
    // For local_cfo users, save to pending_actions instead of direct update
    if ($currentUser['role'] === 'local_cfo') {
        // Check if CFO classification is allowed for this user
        if (!empty($approvedCfoTypes)) {
            // Get current classification of the record
            $stmtClass = $db->prepare("SELECT cfo_classification FROM tarheta_control WHERE id = ?");
            $stmtClass->execute([$id]);
            $currentClass = $stmtClass->fetchColumn();
            
            // Check if user can edit this classification
            if (!in_array($currentClass, $approvedCfoTypes)) {
                throw new Exception('You can only edit members with classification: ' . implode(', ', $approvedCfoTypes));
            }
        }
        
        // Find senior approver (local account from same congregation)
        $stmt = $db->prepare("
            SELECT user_id FROM users 
            WHERE role = 'local' AND local_code = ? AND is_active = 1 
            LIMIT 1
        ");
        $stmt->execute([$currentUser['local_code']]);
        $seniorApprover = $stmt->fetch();
        
        if (!$seniorApprover) {
            throw new Exception('No senior approver (LORC/LCRC) found for your local congregation.');
        }
        
        // Prepare action data (store raw data for approval)
        $actionData = json_encode([
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'husbands_surname' => $husbandsSurname,
            'birthday' => $birthday,
            'registry_number' => $registryNumber,
            'cfo_classification' => $cfoClassification,
            'cfo_status' => $cfoStatus,
            'cfo_notes' => $cfoNotes,
            'registration_type' => $registrationType,
            'registration_date' => $registrationDate,
            'registration_others_specify' => $registrationOthersSpecify,
            'transfer_out_date' => $transferOutDate,
            'marriage_date' => $marriageDate,
            'classification_change_date' => $classificationChangeDate,
            'classification_change_reason' => $classificationChangeReason,
            'purok' => $purok,
            'grupo' => $grupo
        ]);
        
        $actionDescription = "Edit CFO member (ID: $id)";
        
        $stmt = $db->prepare("
            INSERT INTO pending_actions (
                requester_user_id, approver_user_id, action_type, action_data,
                action_description, target_table, target_record_id, status, created_at, expires_at
            ) VALUES (?, ?, 'edit_cfo', ?, ?, 'tarheta_control', ?, 'pending', NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY))
        ");
        $stmt->execute([
            $currentUser['user_id'],
            $seniorApprover['user_id'],
            $actionData,
            $actionDescription,
            $id
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Edit submitted for LORC/LCRC review. You will be notified once approved.',
            'pending' => true
        ]);
        
    } else {
        // Direct update for admin/local users
        $sql = "UPDATE tarheta_control SET " . implode(', ', $updateFields) . " WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        echo json_encode([
            'success' => true,
            'message' => 'CFO information updated successfully'
        ]);
    }
    
} catch (Exception $e) {
    error_log("Error in update-cfo.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
