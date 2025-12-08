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
    $conversationId = $_POST['conversation_id'] ?? null;
    $isTyping = isset($_POST['is_typing']) ? (bool)$_POST['is_typing'] : true;
    
    // Get database connection
    $pdo = Database::getInstance()->getConnection();
    
    if (!$conversationId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Conversation ID required']);
        exit;
    }
    
    $userId = $_SESSION['user_id'];
    
    // Verify user is participant
    $stmt = $pdo->prepare("
        SELECT 1 FROM chat_participants 
        WHERE conversation_id = :conversation_id 
        AND user_id = :user_id
    ");
    $stmt->execute([
        'conversation_id' => $conversationId,
        'user_id' => $userId
    ]);
    
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }
    
    if ($isTyping) {
        // Insert or update typing indicator
        $stmt = $pdo->prepare("
            INSERT INTO chat_typing_indicators (
                conversation_id, 
                user_id, 
                started_at
            ) VALUES (
                :conversation_id, 
                :user_id, 
                NOW()
            ) ON DUPLICATE KEY UPDATE 
                started_at = NOW()
        ");
        $stmt->execute([
            'conversation_id' => $conversationId,
            'user_id' => $userId
        ]);
    } else {
        // Remove typing indicator
        $stmt = $pdo->prepare("
            DELETE FROM chat_typing_indicators 
            WHERE conversation_id = :conversation_id 
            AND user_id = :user_id
        ");
        $stmt->execute([
            'conversation_id' => $conversationId,
            'user_id' => $userId
        ]);
    }
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    error_log('Chat typing-indicator error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to update typing indicator'
    ]);
}
