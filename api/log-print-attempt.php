<?php
/**
 * Log Print Attempt
 * Tracks when a user prints a protected PDF
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
    $markAsPrinted = $input['mark_as_printed'] ?? false;
    
    if (!$requestId) {
        throw new Exception('Invalid request ID');
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
    
    // Mark as printed in database if requested
    if ($markAsPrinted) {
        $stmt = $db->prepare("
            UPDATE cfo_access_requests 
            SET has_printed = TRUE, 
                printed_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$requestId]);
    }
    
    // Log the print attempt
    $stmt = $db->prepare("
        INSERT INTO cfo_pdf_access_logs 
        (access_request_id, user_id, ip_address, user_agent, access_date) 
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $requestId,
        $currentUser['user_id'],
        $_SERVER['REMOTE_ADDR'] ?? '',
        'PRINT_ATTEMPT: User printed document once'
    ]);
    
    // Log to error log for admin review
    error_log("PRINT ATTEMPT - User: {$currentUser['username']} (ID: {$currentUser['user_id']}) - Request ID: $requestId - Document printed once");
    
    echo json_encode(['success' => true, 'logged' => true]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
