<?php
/**
 * Promote HDB to PNK API
 * Handles promotion of children from HDB registry to PNK registry when they turn 4+
 */

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

try {
    Security::requireLogin();
    requirePermission('can_add_officers');
    
    $currentUser = getCurrentUser();
    $db = Database::getInstance()->getConnection();
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Get HDB record ID
    $hdbRecordId = isset($input['hdb_record_id']) ? intval($input['hdb_record_id']) : 0;
    
    if (!$hdbRecordId) {
        throw new Exception('Invalid HDB record ID');
    }
    
    // Fetch HDB record
    $stmt = $db->prepare("SELECT * FROM hdb_registry WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$hdbRecordId]);
    $hdbRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$hdbRecord) {
        throw new Exception('HDB record not found');
    }
    
    // Check if already promoted
    if ($hdbRecord['dedication_status'] === 'transferred-out' || $hdbRecord['dedication_status'] === 'baptized') {
        throw new Exception('This child has already been promoted or transferred');
    }
    
    // Check access
    if (!hasDistrictAccess($hdbRecord['district_code'])) {
        throw new Exception('You do not have access to this district');
    }
    
    $districtCode = $hdbRecord['district_code'];
    
    // Decrypt data from HDB
    $childFirstName = Encryption::decrypt($hdbRecord['child_first_name_encrypted'], $districtCode);
    $childMiddleName = !empty($hdbRecord['child_middle_name_encrypted']) 
        ? Encryption::decrypt($hdbRecord['child_middle_name_encrypted'], $districtCode) 
        : '';
    $childLastName = Encryption::decrypt($hdbRecord['child_last_name_encrypted'], $districtCode);
    $childBirthday = !empty($hdbRecord['child_birthday_encrypted']) 
        ? Encryption::decrypt($hdbRecord['child_birthday_encrypted'], $districtCode) 
        : '';
    $childBirthplace = !empty($hdbRecord['child_birthplace_encrypted']) 
        ? Encryption::decrypt($hdbRecord['child_birthplace_encrypted'], $districtCode) 
        : '';
    
    // Decrypt parent names from HDB - use combined fields only
    $fatherName = !empty($hdbRecord['father_name_encrypted']) 
        ? Encryption::decrypt($hdbRecord['father_name_encrypted'], $districtCode) 
        : '';
    $motherName = !empty($hdbRecord['mother_name_encrypted']) 
        ? Encryption::decrypt($hdbRecord['mother_name_encrypted'], $districtCode) 
        : '';
    
    $parentAddress = !empty($hdbRecord['parent_address_encrypted']) 
        ? Encryption::decrypt($hdbRecord['parent_address_encrypted'], $districtCode) 
        : '';
    $parentContact = !empty($hdbRecord['parent_contact_encrypted']) 
        ? Encryption::decrypt($hdbRecord['parent_contact_encrypted'], $districtCode) 
        : '';
    
    $hdbRegistryNumber = Encryption::decrypt($hdbRecord['registry_number'], $districtCode);
    
    // Calculate child's age for PNK category
    $childAge = 0;
    if (!empty($childBirthday)) {
        try {
            $birthDate = new DateTime($childBirthday);
            $today = new DateTime();
            $childAge = $today->diff($birthDate)->y;
            
            // Verify child is at least 4 years old
            if ($childAge < 4) {
                throw new Exception('Child must be at least 4 years old to be promoted to PNK');
            }
        } catch (Exception $e) {
            throw new Exception('Invalid birth date');
        }
    }
    
    $db->beginTransaction();
    
    // Generate PNK registry number (you can customize this format)
    $pnkRegistryNumber = 'PNK-' . $hdbRecord['local_code'] . '-' . date('Y') . '-' . str_pad($hdbRecordId, 6, '0', STR_PAD_LEFT);
    $pnkRegistryNumberHash = hash('sha256', strtolower(trim($pnkRegistryNumber)));
    
    // Re-encrypt data for PNK
    $childFirstNameEnc = Encryption::encrypt($childFirstName, $districtCode);
    $childMiddleNameEnc = !empty($childMiddleName) ? Encryption::encrypt($childMiddleName, $districtCode) : null;
    $childLastNameEnc = Encryption::encrypt($childLastName, $districtCode);
    $childBirthdayEnc = !empty($childBirthday) ? Encryption::encrypt($childBirthday, $districtCode) : null;
    $childBirthplaceEnc = !empty($childBirthplace) ? Encryption::encrypt($childBirthplace, $districtCode) : null;
    
    $fatherNameEnc = !empty($fatherName) ? Encryption::encrypt($fatherName, $districtCode) : null;
    $motherNameEnc = !empty($motherName) ? Encryption::encrypt($motherName, $districtCode) : null;
    
    // Combine parent names for PNK's parent_guardian_encrypted field
    $parentGuardianText = trim(implode(' / ', array_filter([$fatherName, $motherName])));
    $parentGuardianEnc = !empty($parentGuardianText) ? Encryption::encrypt($parentGuardianText, $districtCode) : null;
    
    $parentAddressEnc = !empty($parentAddress) ? Encryption::encrypt($parentAddress, $districtCode) : null;
    $parentContactEnc = !empty($parentContact) ? Encryption::encrypt($parentContact, $districtCode) : null;
    
    $pnkRegistryNumberEnc = Encryption::encrypt($pnkRegistryNumber, $districtCode);
    
    // Determine PNK category based on age
    $pnkCategory = 'Preteen'; // Default for 4-11 years
    if ($childAge >= 13 && $childAge <= 17) {
        $pnkCategory = 'Teen';
    } elseif ($childAge >= 18) {
        $pnkCategory = 'Young Adult';
    }
    
    // Insert into PNK registry
    $stmt = $db->prepare("
        INSERT INTO pnk_registry (
            district_code, local_code,
            first_name_encrypted, middle_name_encrypted, last_name_encrypted,
            birthday_encrypted, birthplace_encrypted, sex,
            parent_guardian_encrypted,
            address_encrypted, contact_number_encrypted,
            registry_number, registry_number_hash,
            registration_date, pnk_category, baptism_status, attendance_status,
            purok_grupo,
            created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        $hdbRecord['district_code'], $hdbRecord['local_code'],
        $childFirstNameEnc, $childMiddleNameEnc, $childLastNameEnc,
        $childBirthdayEnc, $childBirthplaceEnc, $hdbRecord['child_sex'],
        $parentGuardianEnc,
        $parentAddressEnc, $parentContactEnc,
        $pnkRegistryNumberEnc, $pnkRegistryNumberHash,
        date('Y-m-d'), $pnkCategory, 'not_baptized', 'active',
        $hdbRecord['purok_grupo'],
        $currentUser['user_id']
    ]);
    
    $pnkRecordId = $db->lastInsertId();
    
    // Update HDB record - mark as promoted to PNK
    $stmt = $db->prepare("
        UPDATE hdb_registry 
        SET dedication_status = 'pnk',
            transfer_to = 'PNK Registry',
            transfer_reason = 'Promoted to PNK (Pagsamba ng Kabataan) - Age 4+',
            transfer_date = CURDATE(),
            updated_by = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$currentUser['user_id'], $hdbRecordId]);
    
    // Log HDB activity (if table exists)
    try {
        $stmt = $db->prepare("
            INSERT INTO hdb_activity_log (hdb_record_id, user_id, action, details, ip_address, user_agent)
            VALUES (?, ?, 'promote_to_pnk', ?, ?, ?)
        ");
        $stmt->execute([
            $hdbRecordId,
            $currentUser['user_id'],
            json_encode(['pnk_record_id' => $pnkRecordId, 'pnk_registry_number' => $pnkRegistryNumber]),
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (Exception $e) {
        error_log("Failed to log HDB activity: " . $e->getMessage());
    }
    
    // Log PNK activity (if table exists)
    try {
        $stmt = $db->prepare("
            INSERT INTO pnk_activity_log (pnk_record_id, user_id, action, details, ip_address, user_agent)
            VALUES (?, ?, 'promoted_from_hdb', ?, ?, ?)
        ");
        $stmt->execute([
            $pnkRecordId,
            $currentUser['user_id'],
            json_encode(['from_hdb_registry_number' => $hdbRegistryNumber, 'child_name' => $childFirstName . ' ' . $childLastName]),
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (Exception $e) {
        error_log("Failed to log PNK activity: " . $e->getMessage());
    }
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Child successfully promoted to PNK registry',
        'pnk_record_id' => $pnkRecordId,
        'pnk_registry_number' => $pnkRegistryNumber
    ]);
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Promote HDB to PNK API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
