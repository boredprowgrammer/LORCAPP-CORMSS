<?php
/**
 * Save new family
 */

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../../config/config.php';
    require_once __DIR__ . '/../../includes/FamilyLearning.php';
    
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    
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

    $familyCode = Security::sanitizeInput($input['family_code'] ?? '');
    $panguloId = intval($input['pangulo_id'] ?? 0);
    $purok = Security::sanitizeInput($input['purok'] ?? '');
$grupo = Security::sanitizeInput($input['grupo'] ?? '');
$notes = Security::sanitizeInput($input['notes'] ?? '');
$members = $input['members'] ?? [];

if ($panguloId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Pangulo ng Sambahayan is required']);
    exit;
}

$db->beginTransaction();

// Get pangulo's district and local codes
$stmt = $db->prepare("SELECT district_code, local_code FROM tarheta_control WHERE id = ?");
$stmt->execute([$panguloId]);
$pangulo = $stmt->fetch();

if (!$pangulo) {
    throw new Exception('Pangulo record not found');
}

$districtCode = $pangulo['district_code'];
$localCode = $pangulo['local_code'];

// Verify user has access to this local
if ($currentUser['role'] === 'local' || $currentUser['role'] === 'local_cfo') {
        if ($currentUser['local_code'] !== $localCode) {
            throw new Exception('You do not have access to this congregation');
        }
    } elseif ($currentUser['role'] === 'district') {
        if ($currentUser['district_code'] !== $districtCode) {
            throw new Exception('You do not have access to this district');
        }
    }
    
    // Auto-generate family code if empty
    if (empty($familyCode)) {
        $familyCode = 'FAM-' . strtoupper(substr($localCode, 0, 3)) . '-' . date('ymd') . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
    }
    
    // Check if family code already exists
    $stmt = $db->prepare("SELECT id FROM families WHERE family_code = ? AND deleted_at IS NULL");
    $stmt->execute([$familyCode]);
    if ($stmt->fetch()) {
        throw new Exception('Family code already exists');
    }
    
    // Check if pangulo is already head of another family
    $stmt = $db->prepare("SELECT id, family_code FROM families WHERE pangulo_id = ? AND deleted_at IS NULL");
    $stmt->execute([$panguloId]);
    $existingFamily = $stmt->fetch();
    if ($existingFamily) {
        throw new Exception('This person is already the Pangulo of family ' . $existingFamily['family_code']);
    }
    
    // Insert family
    $stmt = $db->prepare("
        INSERT INTO families (
            family_code, pangulo_id, district_code, local_code,
            purok, grupo, notes, status, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?)
    ");
    $stmt->execute([
        $familyCode,
        $panguloId,
        $districtCode,
        $localCode,
        $purok ?: null,
        $grupo ?: null,
        $notes ?: null,
        $currentUser['user_id']
    ]);
    
    $familyId = $db->lastInsertId();
    
    // Get pangulo's CFO classification
    $stmt = $db->prepare("SELECT cfo_classification FROM tarheta_control WHERE id = ?");
    $stmt->execute([$panguloId]);
    $panguloClass = $stmt->fetchColumn();
    
    // Add pangulo as first family member
    $stmt = $db->prepare("
        INSERT INTO family_members (
            family_id, member_type, tarheta_id, relasyon, kapisanan
        ) VALUES (?, 'pangulo', ?, 'Pangulo', ?)
    ");
    $stmt->execute([$familyId, $panguloId, $panguloClass ?: null]);
    
    // Add other family members
    foreach ($members as $member) {
        $relasyon = Security::sanitizeInput($member['relasyon'] ?? '');
        $relasyonSpecify = Security::sanitizeInput($member['relasyon_specify'] ?? '');
        $kapisanan = Security::sanitizeInput($member['kapisanan'] ?? '');
        $source = Security::sanitizeInput($member['source'] ?? '');
        $sourceId = intval($member['source_id'] ?? 0);
        
        if (empty($relasyon)) continue;
        
        $tarhetaId = null;
        $hdbId = null;
        $pnkId = null;
        
        // Determine source reference
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
    
    $db->commit();
    
    // Advanced behavioral learning for 98% accuracy
    try {
        // Get pangulo's full name for learning
        $stmt = $db->prepare("
            SELECT first_name_encrypted, middle_name_encrypted, last_name_encrypted, district_code
            FROM tarheta_control WHERE id = ?
        ");
        $stmt->execute([$panguloId]);
        $panguloData = $stmt->fetch();
        
        if ($panguloData) {
            $panguloFullName = trim(
                Encryption::decrypt($panguloData['first_name_encrypted'], $panguloData['district_code']) . ' ' .
                ($panguloData['middle_name_encrypted'] ? Encryption::decrypt($panguloData['middle_name_encrypted'], $panguloData['district_code']) . ' ' : '') .
                Encryption::decrypt($panguloData['last_name_encrypted'], $panguloData['district_code'])
            );
            
            $panguloInfo = ['full_name' => $panguloFullName, 'id' => $panguloId];
            
            // Get asawa from input if provided
            $asawaInfo = null;
            if (isset($input['asawa_name']) && !empty($input['asawa_name'])) {
                $asawaInfo = ['full_name' => $input['asawa_name']];
            }
            
            // Get suggestions tracking data from input
            $suggestionsShown = $input['suggestions_shown'] ?? [];
            $suggestionsAccepted = $input['suggestions_accepted'] ?? [];
            $suggestionsModified = $input['suggestions_modified'] ?? [];
            
            // Learn from user behavior - which suggestions were accepted/rejected/modified
            FamilyLearning::learnFromFamilySave(
                $panguloInfo,
                $members,
                $asawaInfo,
                $suggestionsShown,
                $suggestionsAccepted,
                $suggestionsModified
            );
        }
    } catch (Exception $learnEx) {
        // Don't fail the save if learning fails
        error_log("Learning error: " . $learnEx->getMessage());
    }
    
    echo json_encode([
        'success' => true,
        'family_id' => $familyId,
        'family_code' => $familyCode,
        'message' => 'Family created successfully'
    ]);
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Save family error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
