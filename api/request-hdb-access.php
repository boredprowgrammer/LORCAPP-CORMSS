<?php
/**
 * Request HDB Registry Access
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
if (!in_array($currentUser['role'], ['local_cfo', 'lorc'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Read JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Handle both single request_type and multiple request_types
    $requestTypes = [];
    if (isset($input['request_types']) && is_array($input['request_types'])) {
        $requestTypes = $input['request_types'];
    } elseif (isset($input['request_type'])) {
        $requestTypes = [$input['request_type']];
    }
    
    // Validate and filter request types
    $validTypes = ['view', 'add', 'edit'];
    $requestTypes = array_filter($requestTypes, fn($t) => in_array($t, $validTypes));
    
    if (empty($requestTypes)) {
        throw new Exception('Please select at least one access type');
    }
    
    // Calculate expiration date (7 days from now)
    $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
    
    $createdRequests = [];
    $skippedTypes = [];
    
    foreach ($requestTypes as $requestType) {
        // Check if there's already a pending request for this type
        $stmt = $db->prepare("
            SELECT id FROM hdb_access_requests 
            WHERE requester_user_id = ? 
            AND request_type = ?
            AND status = 'pending'
            AND deleted_at IS NULL
        ");
        $stmt->execute([$currentUser['user_id'], $requestType]);
        
        if ($stmt->fetch()) {
            $skippedTypes[] = $requestType;
            continue;
        }
        
        // Create access request with expiration
        $stmt = $db->prepare("
            INSERT INTO hdb_access_requests 
            (requester_user_id, requester_local_code, request_type, verification_status, expires_at, password_hash) 
            VALUES (?, ?, ?, 'submitted', ?, '')
        ");
        
        $stmt->execute([
            $currentUser['user_id'],
            $currentUser['local_code'],
            $requestType,
            $expiresAt
        ]);
        
        $createdRequests[] = [
            'id' => $db->lastInsertId(),
            'type' => $requestType
        ];
    }
    
    if (empty($createdRequests)) {
        throw new Exception('You already have pending requests for all selected access types');
    }
    
    // Log the action
    secureLog("HDB access requested", [
        'request_ids' => array_column($createdRequests, 'id'),
        'user_id' => $currentUser['user_id'],
        'request_types' => array_column($createdRequests, 'type'),
        'expires_at' => $expiresAt,
        'local_code' => $currentUser['local_code']
    ]);
    
    $message = 'Access request submitted successfully. Your LORC will be notified.';
    if (!empty($skippedTypes)) {
        $message .= ' (Skipped already pending: ' . implode(', ', $skippedTypes) . ')';
    }
    $message .= ' Access will expire in 7 days after approval.';
    
    echo json_encode([
        'success' => true,
        'message' => $message,
        'request_ids' => array_column($createdRequests, 'id'),
        'expires_at' => $expiresAt
    ]);
    
} catch (Exception $e) {
    error_log("Error in request-hdb-access.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
