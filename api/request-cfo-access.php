<?php
/**
 * Request CFO Registry Access
 * Supports multiple access modes with 7-day expiration
 */

require_once __DIR__ . '/../config/config.php';

Security::requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$currentUser = getCurrentUser();

// Only local_cfo users can request access
if ($currentUser['role'] !== 'local_cfo') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Read JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    $cfoType = Security::sanitizeInput($input['cfo_type'] ?? '');
    $accessModes = $input['access_modes'] ?? [];
    
    // Validate inputs
    if (!in_array($cfoType, ['Buklod', 'Kadiwa', 'Binhi', 'All'])) {
        throw new Exception('Invalid CFO type selected');
    }
    
    // Validate access modes
    $validModes = ['view_data', 'add_member', 'edit_member'];
    $accessModes = array_filter($accessModes, fn($m) => in_array($m, $validModes));
    
    if (empty($accessModes)) {
        throw new Exception('Please select at least one access type');
    }
    
    // Calculate expiration date (7 days from now)
    $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
    
    $createdRequestIds = [];
    $skippedModes = [];
    
    foreach ($accessModes as $accessMode) {
        // Check if there's already a pending request for this type and mode
        $stmt = $db->prepare("
            SELECT id FROM cfo_access_requests 
            WHERE requester_user_id = ? 
            AND cfo_type = ? 
            AND access_mode = ?
            AND status = 'pending'
            AND deleted_at IS NULL
        ");
        $stmt->execute([$currentUser['user_id'], $cfoType, $accessMode]);
        
        if ($stmt->fetch()) {
            // Already has pending request for this mode, skip
            $skippedModes[] = $accessMode;
            continue;
        }
        
        // Create access request with expiration
        $stmt = $db->prepare("
            INSERT INTO cfo_access_requests 
            (requester_user_id, requester_local_code, cfo_type, access_mode, expires_at) 
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $currentUser['user_id'],
            $currentUser['local_code'],
            $cfoType,
            $accessMode,
            $expiresAt
        ]);
        
        $createdRequestIds[] = $db->lastInsertId();
    }
    
    if (empty($createdRequestIds)) {
        throw new Exception('You already have pending requests for all selected access types');
    }
    
    // Log the action
    secureLog("CFO access requested", [
        'request_ids' => $createdRequestIds,
        'user_id' => $currentUser['user_id'],
        'cfo_type' => $cfoType,
        'access_modes' => $accessModes,
        'local_code' => $currentUser['local_code'],
        'expires_at' => $expiresAt
    ]);
    
    $message = 'Access request submitted successfully. Your senior officer will be notified.';
    if (!empty($skippedModes)) {
        $message .= ' Note: Some types were skipped as you already have pending requests for them.';
    }
    
    echo json_encode([
        'success' => true,
        'message' => $message,
        'request_ids' => $createdRequestIds,
        'expires_at' => $expiresAt
    ]);
    
} catch (Exception $e) {
    error_log("Error in request-cfo-access.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
