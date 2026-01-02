<?php
/**
 * Merge duplicate officers
 * Combines departments and transfers data to primary officer, then deletes duplicates
 */

// Start output buffering to prevent any stray output
ob_start();

require_once __DIR__ . '/../config/config.php';

Security::requireLogin();

// Clean any output and set JSON header
ob_end_clean();
header('Content-Type: application/json');

// Verify CSRF token
if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit;
}

$primaryId = intval($_POST['primary_id'] ?? 0);
$duplicateIdsJson = $_POST['duplicate_ids'] ?? '[]';

// Ensure it's a string before decoding
if (!is_string($duplicateIdsJson)) {
    echo json_encode(['success' => false, 'message' => 'Invalid duplicate_ids format']);
    exit;
}

$duplicateIds = json_decode($duplicateIdsJson, true);

if (empty($primaryId) || empty($duplicateIds) || !is_array($duplicateIds)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

// Remove primary ID from duplicates list
$duplicateIds = array_filter($duplicateIds, fn($id) => intval($id) !== $primaryId);

if (empty($duplicateIds)) {
    echo json_encode(['success' => false, 'message' => 'No duplicates to merge']);
    exit;
}

$db = Database::getInstance()->getConnection();
$user = getCurrentUser();

try {
    $db->beginTransaction();
    
    // Get primary officer info
    $stmt = $db->prepare("SELECT * FROM officers WHERE officer_id = ?");
    $stmt->execute([$primaryId]);
    $primaryOfficer = $stmt->fetch();
    
    if (!$primaryOfficer) {
        throw new Exception('Primary officer not found');
    }
    
    // Check permissions
    if ($user['role'] === 'district' && $primaryOfficer['district_code'] !== $user['district_code']) {
        throw new Exception('Permission denied');
    } elseif ($user['role'] === 'local' && $primaryOfficer['local_code'] !== $user['local_code']) {
        throw new Exception('Permission denied');
    }
    
    foreach ($duplicateIds as $dupId) {
        $dupId = intval($dupId);
        
        // Get duplicate officer departments
        $stmt = $db->prepare("SELECT * FROM officer_departments WHERE officer_id = ?");
        $stmt->execute([$dupId]);
        $departments = $stmt->fetchAll();
        
        // Merge departments into primary officer (skip duplicates)
        foreach ($departments as $dept) {
            // Check if primary already has this department+duty combination
            $checkStmt = $db->prepare("
                SELECT COUNT(*) 
                FROM officer_departments 
                WHERE officer_id = ? 
                AND department = ? 
                AND (duty = ? OR (duty IS NULL AND ? IS NULL))
            ");
            $checkStmt->execute([
                $primaryId, 
                $dept['department'], 
                $dept['duty'], 
                $dept['duty']
            ]);
            
            if ($checkStmt->fetchColumn() == 0) {
                // Add department to primary officer
                $insertStmt = $db->prepare("
                    INSERT INTO officer_departments 
                    (officer_id, department, duty, oath_date, is_active)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $insertStmt->execute([
                    $primaryId,
                    $dept['department'],
                    $dept['duty'],
                    $dept['oath_date'],
                    $dept['is_active']
                ]);
            }
        }
        
        // Update officer_requests to point to primary officer
        $stmt = $db->prepare("
            UPDATE officer_requests 
            SET existing_officer_uuid = ?
            WHERE existing_officer_uuid = (SELECT officer_uuid FROM officers WHERE officer_id = ?)
        ");
        $stmt->execute([$primaryOfficer['officer_uuid'], $dupId]);
        
        // Update transfers
        $stmt = $db->prepare("UPDATE transfers SET officer_id = ? WHERE officer_id = ?");
        $stmt->execute([$primaryId, $dupId]);
        
        // Update officer_removals
        $stmt = $db->prepare("UPDATE officer_removals SET officer_id = ? WHERE officer_id = ?");
        $stmt->execute([$primaryId, $dupId]);
        
        // Delete the duplicate officer's departments
        $stmt = $db->prepare("DELETE FROM officer_departments WHERE officer_id = ?");
        $stmt->execute([$dupId]);
        
        // Delete the duplicate officer
        $stmt = $db->prepare("DELETE FROM officers WHERE officer_id = ?");
        $stmt->execute([$dupId]);
    }
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Officers merged successfully',
        'merged_count' => count($duplicateIds)
    ]);
    
} catch (Exception $e) {
    $db->rollBack();
    error_log("Merge officers error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
