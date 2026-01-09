<?php
/**
 * Clear Transfer History and Classification Changes
 * Only available for local_cfo role
 */

require_once __DIR__ . '/../config/config.php';

Security::requireLogin();
header('Content-Type: application/json');

$currentUser = getCurrentUser();

// Restrict to local role only
if ($currentUser['role'] !== 'local') {
    echo json_encode(['success' => false, 'error' => 'Access denied. This action is only available for local administrators.']);
    exit;
}

// Verify CSRF token
if (!Security::validateCSRFToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = Security::sanitizeInput($input['action'] ?? '');
    
    if (empty($action)) {
        throw new Exception('Action is required');
    }
    
    $db = Database::getInstance()->getConnection();
    $localCode = $currentUser['local_code'];
    
    if ($action === 'transfer_out') {
        // Mark transferred-out members as history cleared (don't delete)
        $stmt = $db->prepare("
            UPDATE tarheta_control 
            SET transfer_history_cleared_at = NOW()
            WHERE local_code = ? 
            AND cfo_status = 'transferred-out'
            AND (transfer_history_cleared_at IS NULL OR transfer_history_cleared_at < cfo_updated_at)
        ");
        $stmt->execute([$localCode]);
        $clearedCount = $stmt->rowCount();
        
        echo json_encode([
            'success' => true, 
            'cleared_count' => $clearedCount,
            'message' => "$clearedCount transferred-out member(s) hidden from history view"
        ]);
        
    } elseif ($action === 'classification_changes') {
        // Mark classification changes as history cleared (don't reset)
        $stmt = $db->prepare("
            UPDATE tarheta_control 
            SET classification_history_cleared_at = NOW()
            WHERE local_code = ? 
            AND cfo_status = 'active'
            AND cfo_classification != cfo_classification_auto
            AND cfo_classification IS NOT NULL
            AND (classification_history_cleared_at IS NULL OR classification_history_cleared_at < cfo_updated_at)
        ");
        $stmt->execute([$localCode]);
        $clearedCount = $stmt->rowCount();
        
        echo json_encode([
            'success' => true, 
            'cleared_count' => $clearedCount,
            'message' => "$clearedCount classification change(s) hidden from history view"
        ]);
        
    } else {
        throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    error_log("Error clearing history: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
