<?php
/**
 * Get Senior Approvers API
 * Returns list of local users from a specific local congregation who can approve actions
 */

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

// Require authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$localCode = $_GET['local'] ?? '';

if (empty($localCode)) {
    http_response_code(400);
    echo json_encode(['error' => 'Local code is required']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Get all local users (not local_limited) from this local congregation
    $stmt = $db->prepare("
        SELECT user_id, username, full_name, email
        FROM users
        WHERE local_code = ? 
        AND role = 'local'
        AND is_active = 1
        ORDER BY full_name ASC
    ");
    
    $stmt->execute([$localCode]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($users);
    
} catch (Exception $e) {
    error_log("Get senior approvers error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
