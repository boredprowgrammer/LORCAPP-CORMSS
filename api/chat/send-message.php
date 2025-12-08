<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/chat-encryption.php';

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

// Validate CSRF token
if (!isset($_POST['csrf_token']) || !Security::validateCSRFToken($_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

try {
    $conversationId = $_POST['conversation_id'] ?? null;
    $message = trim($_POST['message'] ?? '');
    
    // Get database connection
    $pdo = Database::getInstance()->getConnection();
    
    if (!$conversationId || empty($message)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Conversation ID and message are required']);
        exit;
    }
    
    if (strlen($message) > 10000) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Message too long (max 10,000 characters)']);
        exit;
    }
    
    $userId = $_SESSION['user_id'];
    
    // Verify user is participant in this conversation
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
    
    // Encrypt message
    $chatEncryption = new ChatEncryption();
    $encrypted = $chatEncryption->encryptMessage($message, $conversationId);
    
    // Insert message
    $pdo->beginTransaction();
    
    $stmt = $pdo->prepare("
        INSERT INTO chat_messages (
            conversation_id,
            sender_id,
            message_encrypted,
            encryption_key_hash
        ) VALUES (
            :conversation_id,
            :sender_id,
            :message_encrypted,
            :encryption_key_hash
        )
    ");
    
    $stmt->execute([
        'conversation_id' => $conversationId,
        'sender_id' => $userId,
        'message_encrypted' => $encrypted['encrypted'],
        'encryption_key_hash' => $encrypted['key_hash']
    ]);
    
    $messageId = $pdo->lastInsertId();
    
    // Update conversation last_message_at
    $stmt = $pdo->prepare("
        UPDATE chat_conversations 
        SET last_message_at = NOW() 
        WHERE conversation_id = :conversation_id
    ");
    $stmt->execute(['conversation_id' => $conversationId]);
    
    // Log to audit
    $stmt = $pdo->prepare("
        INSERT INTO chat_audit_log (
            user_id, 
            action, 
            conversation_id, 
            message_id
        ) VALUES (
            :user_id, 
            'send_message', 
            :conversation_id, 
            :message_id
        )
    ");
    $stmt->execute([
        'user_id' => $userId,
        'conversation_id' => $conversationId,
        'message_id' => $messageId
    ]);
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message_id' => (int)$messageId,
        'sent_at' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Chat send-message error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to send message'
    ]);
}
