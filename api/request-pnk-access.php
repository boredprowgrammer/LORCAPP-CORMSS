<?php
/**
 * Request PNK Registry Access
 * Supports multiple access types with 7-day expiration
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

// Only local_cfo and local_limited users can request access
if (!in_array($currentUser['role'], ['local_cfo', 'local_limited', 'lorc'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Read JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    $requestTypes = $input['request_types'] ?? [];
    $dakoId = isset($input['dako_id']) && !empty($input['dako_id']) ? intval($input['dako_id']) : null;
    
    // Validate request types
    $validTypes = ['view', 'edit'];
    $requestTypes = array_filter($requestTypes, fn($t) => in_array($t, $validTypes));
    
    if (empty($requestTypes)) {
        throw new Exception('Please select at least one access type');
    }
    
    // Calculate expiration date (7 days from now)
    $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
    
    $createdRequestIds = [];
    $skippedTypes = [];
    
    foreach ($requestTypes as $requestType) {
        // Check if there's already a pending request for this type and dako
        $checkSql = "
            SELECT id FROM pnk_access_requests 
            WHERE requester_user_id = ? 
            AND request_type = ?
            AND status = 'pending'
            AND deleted_at IS NULL
        ";
        $params = [$currentUser['user_id'], $requestType];
        
        if ($dakoId) {
            $checkSql .= " AND dako_id = ?";
            $params[] = $dakoId;
        } else {
            $checkSql .= " AND dako_id IS NULL";
        }
        
        $stmt = $db->prepare($checkSql);
        $stmt->execute($params);
        
        if ($stmt->fetch()) {
            // Already has pending request for this type, skip
            $skippedTypes[] = $requestType;
            continue;
        }
        
        // Create access request with expiration
        $stmt = $db->prepare("
            INSERT INTO pnk_access_requests 
            (requester_user_id, requester_local_code, request_type, dako_id, verification_status, expires_at, password_hash) 
            VALUES (?, ?, ?, ?, 'submitted', ?, '')
        ");
        
        $stmt->execute([
            $currentUser['user_id'],
            $currentUser['local_code'],
            $requestType,
            $dakoId,
            $expiresAt
        ]);
        
        $createdRequestIds[] = $db->lastInsertId();
    }
    
    if (empty($createdRequestIds)) {
        throw new Exception('You already have pending requests for all selected access types');
    }
    
    // Log the action
    secureLog("PNK access requested", [
        'request_ids' => $createdRequestIds,
        'user_id' => $currentUser['user_id'],
        'request_types' => $requestTypes,
        'dako_id' => $dakoId,
        'local_code' => $currentUser['local_code'],
        'expires_at' => $expiresAt
    ]);
    
    $message = 'Access request submitted successfully. Your LORC will be notified.';
    if (!empty($skippedTypes)) {
        $message .= ' Note: Some types were skipped as you already have pending requests for them.';
    }
    
    echo json_encode([
        'success' => true,
        'message' => $message,
        'request_ids' => $createdRequestIds,
        'expires_at' => $expiresAt
    ]);
    
} catch (Exception $e) {
    error_log("Error in request-pnk-access.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
