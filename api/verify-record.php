<?php
/**
 * Verify Record API
 * Handles verification or rejection of pending add/edit requests
 */

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

try {
    Security::requireLogin();
    
    $currentUser = getCurrentUser();
    
    // Only admin and local users can verify
    if (!in_array($currentUser['role'], ['admin', 'local'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
        exit;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $id = intval($input['id'] ?? 0);
    $action = $input['action'] ?? ''; // 'verify' or 'reject'
    $registryType = $input['registry_type'] ?? '';
    $reason = trim($input['reason'] ?? '');
    
    if (!$id || !in_array($action, ['verify', 'reject'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
        exit;
    }
    
    $db = Database::getInstance()->getConnection();
    
    // Get the pending verification
    $stmt = $db->prepare("SELECT * FROM pending_verifications WHERE id = ?");
    $stmt->execute([$id]);
    $pending = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$pending) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Verification not found']);
        exit;
    }
    
    // Check if user has permission for this local
    if ($currentUser['role'] !== 'admin') {
        $newData = json_decode($pending['new_data'], true);
        if (($newData['local_code'] ?? '') !== $currentUser['local_code']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'You can only verify records in your local']);
            exit;
        }
    }
    
    $db->beginTransaction();
    
    try {
        if ($action === 'reject') {
            // Update status to rejected
            $stmt = $db->prepare("
                UPDATE pending_verifications 
                SET verification_status = 'rejected',
                    verified_by = ?,
                    verified_at = NOW(),
                    rejection_reason = ?
                WHERE id = ?
            ");
            $stmt->execute([$currentUser['user_id'], $reason, $id]);
            
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Record rejected']);
            exit;
        }
        
        // Verify and add/update the record
        $newData = json_decode($pending['new_data'], true);
        $registryType = $pending['registry_type'];
        $actionType = $pending['action_type'];
        
        if ($registryType === 'hdb') {
            if ($actionType === 'add') {
                // Insert new HDB record
                $districtCode = $newData['district_code'];
                
                $stmt = $db->prepare("
                    INSERT INTO hdb_registry (
                        child_name_encrypted, birthday_encrypted, father_name_encrypted, mother_name_encrypted,
                        address_encrypted, dedication_status, local_code, district_code, created_by, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $stmt->execute([
                    Encryption::encrypt($newData['child_name'] ?? '', $districtCode),
                    Encryption::encrypt($newData['birthday'] ?? '', $districtCode),
                    $newData['father_name'] ? Encryption::encrypt($newData['father_name'], $districtCode) : null,
                    $newData['mother_name'] ? Encryption::encrypt($newData['mother_name'], $districtCode) : null,
                    $newData['address'] ? Encryption::encrypt($newData['address'], $districtCode) : null,
                    $newData['dedication_status'] ?? 'pending',
                    $newData['local_code'],
                    $districtCode,
                    $pending['submitted_by']
                ]);
                
            } else {
                // Update existing HDB record
                $recordId = $pending['record_id'];
                $districtCode = $newData['district_code'];
                
                $stmt = $db->prepare("
                    UPDATE hdb_registry SET
                        child_name_encrypted = ?,
                        birthday_encrypted = ?,
                        father_name_encrypted = ?,
                        mother_name_encrypted = ?,
                        address_encrypted = ?,
                        dedication_status = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                
                $stmt->execute([
                    Encryption::encrypt($newData['child_name'] ?? '', $districtCode),
                    Encryption::encrypt($newData['birthday'] ?? '', $districtCode),
                    $newData['father_name'] ? Encryption::encrypt($newData['father_name'], $districtCode) : null,
                    $newData['mother_name'] ? Encryption::encrypt($newData['mother_name'], $districtCode) : null,
                    $newData['address'] ? Encryption::encrypt($newData['address'], $districtCode) : null,
                    $newData['dedication_status'] ?? 'pending',
                    $recordId
                ]);
            }
        } elseif ($registryType === 'pnk') {
            if ($actionType === 'add') {
                // Insert new PNK record
                $districtCode = $newData['district_code'];
                
                $stmt = $db->prepare("
                    INSERT INTO pnk_registry (
                        first_name_encrypted, middle_name_encrypted, last_name_encrypted,
                        birthday_encrypted, parent_guardian_encrypted, baptism_status,
                        local_code, district_code, created_by, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $parentGuardian = '';
                if (!empty($newData['father_name']) || !empty($newData['mother_name'])) {
                    $parentGuardian = trim($newData['father_name'] ?? '') . ' / ' . trim($newData['mother_name'] ?? '');
                }
                
                $stmt->execute([
                    Encryption::encrypt($newData['first_name'] ?? '', $districtCode),
                    $newData['middle_name'] ? Encryption::encrypt($newData['middle_name'], $districtCode) : null,
                    Encryption::encrypt($newData['last_name'] ?? '', $districtCode),
                    Encryption::encrypt($newData['birthday'] ?? '', $districtCode),
                    $parentGuardian ? Encryption::encrypt($parentGuardian, $districtCode) : null,
                    $newData['baptism_status'] ?? 'active',
                    $newData['local_code'],
                    $districtCode,
                    $pending['submitted_by']
                ]);
                
            } else {
                // Update existing PNK record
                $recordId = $pending['record_id'];
                $districtCode = $newData['district_code'];
                
                $parentGuardian = '';
                if (!empty($newData['father_name']) || !empty($newData['mother_name'])) {
                    $parentGuardian = trim($newData['father_name'] ?? '') . ' / ' . trim($newData['mother_name'] ?? '');
                }
                
                $stmt = $db->prepare("
                    UPDATE pnk_registry SET
                        first_name_encrypted = ?,
                        middle_name_encrypted = ?,
                        last_name_encrypted = ?,
                        birthday_encrypted = ?,
                        parent_guardian_encrypted = ?,
                        baptism_status = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                
                $stmt->execute([
                    Encryption::encrypt($newData['first_name'] ?? '', $districtCode),
                    $newData['middle_name'] ? Encryption::encrypt($newData['middle_name'], $districtCode) : null,
                    Encryption::encrypt($newData['last_name'] ?? '', $districtCode),
                    Encryption::encrypt($newData['birthday'] ?? '', $districtCode),
                    $parentGuardian ? Encryption::encrypt($parentGuardian, $districtCode) : null,
                    $newData['baptism_status'] ?? 'active',
                    $recordId
                ]);
            }
        } elseif ($registryType === 'cfo') {
            // Handle CFO add/edit
            if ($actionType === 'add') {
                // Insert new CFO member - handled by existing CFO add system
                // Just mark as verified
            } else {
                // Update existing CFO member - handled by existing CFO edit system
                // Just mark as verified
            }
        }
        
        // Update pending verification status
        $stmt = $db->prepare("
            UPDATE pending_verifications 
            SET verification_status = 'verified',
                verified_by = ?,
                verified_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$currentUser['user_id'], $id]);
        
        $db->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Record verified and ' . ($actionType === 'add' ? 'added to' : 'updated in') . ' the registry'
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Verify Record Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to verify record: ' . $e->getMessage()]);
}
