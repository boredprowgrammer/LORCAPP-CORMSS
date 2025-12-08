<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json');

// Security checks
if (!Security::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Only local and district users can use chat
$allowedRoles = ['local', 'district'];
if (!in_array($_SESSION['user_role'], $allowedRoles)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Chat is only available for local and district users']);
    exit;
}

try {
    $conversationId = $_GET['conversation_id'] ?? null;
    
    // Get database connection
    $pdo = Database::getInstance()->getConnection();
    
    if (!$conversationId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Conversation ID required']);
        exit;
    }
    
    $userId = $_SESSION['user_id'];
    
    // Clean up old typing indicators (older than 10 seconds)
    $stmt = $pdo->prepare("
        DELETE FROM chat_typing_indicators 
        WHERE started_at < DATE_SUB(NOW(), INTERVAL 10 SECOND)
    ");
    $stmt->execute();
    
    // Get current typing users (excluding self)
    $stmt = $pdo->prepare("
        SELECT u.username 
        FROM chat_typing_indicators t
        JOIN users u ON t.user_id = u.user_id
        WHERE t.conversation_id = :conversation_id 
        AND t.user_id != :user_id
        AND t.started_at >= DATE_SUB(NOW(), INTERVAL 10 SECOND)
    ");
    $stmt->execute([
        'conversation_id' => $conversationId,
        'user_id' => $userId
    ]);
    
    $typingUsers = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo json_encode([
        'success' => true,
        'typing_users' => $typingUsers,
        'is_typing' => !empty($typingUsers)
    ]);
    
} catch (Exception $e) {
    error_log('Chat get-typing error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to get typing indicators'
    ]);
}
