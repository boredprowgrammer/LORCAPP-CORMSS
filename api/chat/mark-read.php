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
    
    // Update last_read_at for this participant
    $stmt = $pdo->prepare("
        UPDATE chat_participants 
        SET last_read_at = NOW() 
        WHERE conversation_id = :conversation_id 
        AND user_id = :user_id
    ");
    $stmt->execute([
        'conversation_id' => $conversationId,
        'user_id' => $userId
    ]);
    
    // Create read receipts for all unread messages from others in this conversation
    $stmt = $pdo->prepare("
        INSERT INTO chat_read_receipts (message_id, user_id, read_at)
        SELECT m.message_id, :user_id, NOW()
        FROM chat_messages m
        WHERE m.conversation_id = :conversation_id
        AND m.sender_id != :user_id2
        AND NOT EXISTS (
            SELECT 1 FROM chat_read_receipts rr 
            WHERE rr.message_id = m.message_id 
            AND rr.user_id = :user_id3
        )
    ");
    $stmt->execute([
        'user_id' => $userId,
        'conversation_id' => $conversationId,
        'user_id2' => $userId,
        'user_id3' => $userId
    ]);
    
    echo json_encode([
        'success' => true,
        'marked_read' => $stmt->rowCount()
    ]);
    
} catch (Exception $e) {
    error_log('Chat mark-read error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to mark messages as read'
    ]);
}
