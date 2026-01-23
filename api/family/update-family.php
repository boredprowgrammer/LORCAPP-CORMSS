<?php
/**
 * Update family
 */

require_once __DIR__ . '/../../config/config.php';

Security::requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
    exit;
}

$familyId = intval($input['id'] ?? 0);
$familyCode = Security::sanitizeInput($input['family_code'] ?? '');
$status = Security::sanitizeInput($input['status'] ?? 'active');
$purok = Security::sanitizeInput($input['purok'] ?? '');
$grupo = Security::sanitizeInput($input['grupo'] ?? '');
$notes = Security::sanitizeInput($input['notes'] ?? '');
$members = $input['members'] ?? [];

if ($familyId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid family ID']);
    exit;
}

try {
    // Get existing family
    $stmt = $db->prepare("SELECT * FROM families WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$familyId]);
    $family = $stmt->fetch();
    
    if (!$family) {
        throw new Exception('Family not found');
    }
    
    // Verify user has access
    if ($currentUser['role'] === 'local' || $currentUser['role'] === 'local_cfo') {
        if ($currentUser['local_code'] !== $family['local_code']) {
            throw new Exception('You do not have access to this family');
        }
    } elseif ($currentUser['role'] === 'district') {
        if ($currentUser['district_code'] !== $family['district_code']) {
            throw new Exception('You do not have access to this family');
        }
    }
    
    // Validate status
    if (!in_array($status, ['active', 'inactive', 'transferred'])) {
        throw new Exception('Invalid status');
    }
    
    // Check if family code is unique (if changed)
    if ($familyCode !== $family['family_code']) {
        $stmt = $db->prepare("SELECT id FROM families WHERE family_code = ? AND id != ? AND deleted_at IS NULL");
        $stmt->execute([$familyCode, $familyId]);
        if ($stmt->fetch()) {
            throw new Exception('Family code already exists');
        }
    }
    
    $db->beginTransaction();
    
    // Update family
    $stmt = $db->prepare("
        UPDATE families SET
            family_code = ?,
            status = ?,
            purok = ?,
            grupo = ?,
            notes = ?,
            updated_by = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $familyCode,
        $status,
        $purok ?: null,
        $grupo ?: null,
        $notes ?: null,
        $currentUser['user_id'],
        $familyId
    ]);
    
    // Get existing member IDs (excluding pangulo)
    $stmt = $db->prepare("SELECT id FROM family_members WHERE family_id = ? AND member_type = 'kaanib' AND is_active = 1");
    $stmt->execute([$familyId]);
    $existingMemberIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Track which members to keep
    $keepMemberIds = [];
    
    // Process members
    foreach ($members as $member) {
        $memberId = isset($member['id']) && is_int($member['id']) ? intval($member['id']) : null;
        $relasyon = Security::sanitizeInput($member['relasyon'] ?? '');
        $relasyonSpecify = Security::sanitizeInput($member['relasyon_specify'] ?? '');
        $kapisanan = Security::sanitizeInput($member['kapisanan'] ?? '');
        $source = Security::sanitizeInput($member['source'] ?? '');
        $sourceId = intval($member['source_id'] ?? 0);
        
        if (empty($relasyon)) continue;
        
        if ($memberId && in_array($memberId, $existingMemberIds)) {
            // Update existing member
            $stmt = $db->prepare("
                UPDATE family_members SET
                    relasyon = ?,
                    relasyon_specify = ?,
                    kapisanan = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $relasyon,
                $relasyon === 'Iba pa' ? $relasyonSpecify : null,
                $kapisanan ?: null,
                $memberId
            ]);
            $keepMemberIds[] = $memberId;
        } else {
            // Add new member
            $tarhetaId = null;
            $hdbId = null;
            $pnkId = null;
            
            if ($source === 'Tarheta' || in_array($source, ['Buklod', 'Kadiwa', 'Binhi'])) {
                $tarhetaId = $sourceId;
            } elseif ($source === 'HDB') {
                $hdbId = $sourceId;
            } elseif ($source === 'PNK') {
                $pnkId = $sourceId;
            }
            
            $stmt = $db->prepare("
                INSERT INTO family_members (
                    family_id, member_type, tarheta_id, hdb_id, pnk_id,
                    relasyon, relasyon_specify, kapisanan
                ) VALUES (?, 'kaanib', ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $familyId,
                $tarhetaId,
                $hdbId,
                $pnkId,
                $relasyon,
                $relasyon === 'Iba pa' ? $relasyonSpecify : null,
                $kapisanan ?: null
            ]);
        }
    }
    
    // Deactivate removed members
    $removedMemberIds = array_diff($existingMemberIds, $keepMemberIds);
    if (!empty($removedMemberIds)) {
        $placeholders = implode(',', array_fill(0, count($removedMemberIds), '?'));
        $stmt = $db->prepare("UPDATE family_members SET is_active = 0 WHERE id IN ($placeholders)");
        $stmt->execute($removedMemberIds);
    }
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Family updated successfully'
    ]);
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Update family error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
