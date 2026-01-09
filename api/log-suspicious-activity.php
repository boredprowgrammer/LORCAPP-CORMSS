<?php
/**
 * Log Suspicious Activity
 * Tracks suspicious actions on protected PDFs
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

if ($currentUser['role'] !== 'local_cfo') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Read JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    $requestId = intval($input['request_id'] ?? 0);
    $action = Security::sanitizeInput($input['action'] ?? '');
    
    if (!$requestId || !$action) {
        throw new Exception('Invalid input');
    }
    
    // Verify the request belongs to the user
    $stmt = $db->prepare("
        SELECT id FROM cfo_access_requests 
        WHERE id = ? 
        AND requester_user_id = ?
    ");
    $stmt->execute([$requestId, $currentUser['user_id']]);
    
    if (!$stmt->fetch()) {
        throw new Exception('Invalid request');
    }
    
    // Log the suspicious activity
    $stmt = $db->prepare("
        INSERT INTO cfo_pdf_access_logs 
        (access_request_id, user_id, ip_address, user_agent, access_date) 
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $requestId,
        $currentUser['user_id'],
        $_SERVER['REMOTE_ADDR'] ?? '',
        'SUSPICIOUS_ACTIVITY: ' . $action
    ]);
    
    // Also log to error log for admin review
    error_log("SUSPICIOUS ACTIVITY - User: {$currentUser['username']} (ID: {$currentUser['user_id']}) - Action: $action - Request ID: $requestId");
    
    echo json_encode(['success' => true, 'logged' => true]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
