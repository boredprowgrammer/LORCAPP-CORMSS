<?php
/**
 * Request CFO Registry Access
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
    $reason = Security::sanitizeInput($input['reason'] ?? '');
    $password = $input['password'] ?? '';
    
    // Validate inputs
    if (!in_array($cfoType, ['Buklod', 'Kadiwa', 'Binhi', 'All'])) {
        throw new Exception('Invalid CFO type selected');
    }
    
    if (empty($password)) {
        throw new Exception('Password is required for verification');
    }
    
    // Verify password
    $stmt = $db->prepare("SELECT password_hash FROM users WHERE user_id = ?");
    $stmt->execute([$currentUser['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user || !password_verify($password, $user['password_hash'])) {
        throw new Exception('Invalid password. Please try again.');
    }
    
    // Check if there's already a pending request for this type
    $stmt = $db->prepare("
        SELECT id FROM cfo_access_requests 
        WHERE requester_user_id = ? 
        AND cfo_type = ? 
        AND status = 'pending'
        AND deleted_at IS NULL
    ");
    $stmt->execute([$currentUser['user_id'], $cfoType]);
    
    if ($stmt->fetch()) {
        throw new Exception('You already have a pending request for ' . $cfoType . ' records');
    }
    
    // Create access request
    $stmt = $db->prepare("
        INSERT INTO cfo_access_requests 
        (requester_user_id, requester_local_code, cfo_type, request_reason) 
        VALUES (?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $currentUser['user_id'],
        $currentUser['local_code'],
        $cfoType,
        $reason
    ]);
    
    $requestId = $db->lastInsertId();
    
    // Log the action
    secureLog("CFO access requested", [
        'request_id' => $requestId,
        'user_id' => $currentUser['user_id'],
        'cfo_type' => $cfoType,
        'local_code' => $currentUser['local_code']
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Access request submitted successfully. Your senior officer will be notified.',
        'request_id' => $requestId
    ]);
    
} catch (Exception $e) {
    error_log("Error in request-cfo-access.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
