<?php
/**
 * Delete family (soft delete)
 * Sets deleted_at timestamp instead of permanently removing
 */

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../../config/config.php';
    
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

    $familyId = intval($input['family_id'] ?? 0);

    if ($familyId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Family ID is required']);
        exit;
    }

    // Get family data to verify access
    $stmt = $db->prepare("
        SELECT id, family_code, district_code, local_code, pangulo_id 
        FROM families 
        WHERE id = ? AND deleted_at IS NULL
    ");
    $stmt->execute([$familyId]);
    $family = $stmt->fetch();

    if (!$family) {
        echo json_encode(['success' => false, 'error' => 'Family not found']);
        exit;
    }

    // Verify user has access to delete this family
    if ($currentUser['role'] === 'local' || $currentUser['role'] === 'local_cfo') {
        if ($currentUser['local_code'] !== $family['local_code']) {
            echo json_encode(['success' => false, 'error' => 'You do not have access to delete this family']);
            exit;
        }
    } elseif ($currentUser['role'] === 'district') {
        if ($currentUser['district_code'] !== $family['district_code']) {
            echo json_encode(['success' => false, 'error' => 'You do not have access to delete this family']);
            exit;
        }
    } elseif ($currentUser['role'] !== 'admin') {
        echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
        exit;
    }

    $db->beginTransaction();

    try {
        // Soft delete the family
        $stmt = $db->prepare("
            UPDATE families 
            SET deleted_at = NOW(), 
                deleted_by = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$currentUser['user_id'], $familyId]);

        // Deactivate all family members (don't delete, just mark inactive)
        $stmt = $db->prepare("
            UPDATE family_members 
            SET is_active = 0, 
                updated_at = NOW()
            WHERE family_id = ?
        ");
        $stmt->execute([$familyId]);

        $db->commit();

        // Log the deletion
        error_log("Family {$family['family_code']} (ID: $familyId) deleted by user {$currentUser['user_id']}");

        echo json_encode([
            'success' => true,
            'message' => 'Family deleted successfully',
            'family_code' => $family['family_code']
        ]);

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Delete family error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
