<?php
/**
 * Delete Dako API
 * Soft deletes a Dako entry
 */

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

Security::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$dakoId = intval($input['dako_id'] ?? 0);

if ($dakoId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Dako ID is required']);
    exit;
}

$currentUser = getCurrentUser();

// Check permission
if ($currentUser['role'] !== 'admin' && $currentUser['role'] !== 'local') {
    echo json_encode(['success' => false, 'error' => 'You do not have permission to delete Dako']);
    exit;
}

$db = Database::getInstance()->getConnection();

try {
    // Get Dako info first
    $stmt = $db->prepare("SELECT * FROM pnk_dako WHERE id = ?");
    $stmt->execute([$dakoId]);
    $dako = $stmt->fetch();
    
    if (!$dako) {
        echo json_encode(['success' => false, 'error' => 'Dako not found']);
        exit;
    }
    
    // Check if user has access to this local
    if ($currentUser['role'] !== 'admin' && $currentUser['local_code'] !== $dako['local_code']) {
        echo json_encode(['success' => false, 'error' => 'You do not have permission to delete this Dako']);
        exit;
    }
    
    // Soft delete by setting is_active = FALSE
    $stmt = $db->prepare("UPDATE pnk_dako SET is_active = FALSE, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$dakoId]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Dako deleted successfully'
    ]);
    
} catch (Exception $e) {
    error_log("Delete Dako error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to delete Dako']);
}
