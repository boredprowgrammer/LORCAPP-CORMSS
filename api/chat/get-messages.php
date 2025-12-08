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

try {
    $conversationId = $_GET['conversation_id'] ?? null;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    $beforeMessageId = isset($_GET['before']) ? (int)$_GET['before'] : null;
    
    // Get database connection
    $pdo = Database::getInstance()->getConnection();
    
    if (!$conversationId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Conversation ID required']);
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
    
    // Build query for messages
    $query = "
        SELECT 
            m.message_id,
            m.sender_id,
            m.message_encrypted,
            m.encryption_key_hash,
            m.sent_at,
            m.edited_at,
            u.username as sender_username,
            u.role as sender_role
        FROM chat_messages m
        JOIN users u ON m.sender_id = u.user_id
        WHERE m.conversation_id = :conversation_id
    ";
    
    $params = ['conversation_id' => $conversationId];
    
    if ($beforeMessageId) {
        $query .= " AND m.message_id < :before_id";
        $params['before_id'] = $beforeMessageId;
    }
    
    $query .= " ORDER BY m.sent_at DESC LIMIT :limit";
    
    $stmt = $pdo->prepare($query);
    $stmt->bindValue('conversation_id', $conversationId, PDO::PARAM_INT);
    if ($beforeMessageId) {
        $stmt->bindValue('before_id', $beforeMessageId, PDO::PARAM_INT);
    }
    $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Reverse to chronological order
    $messages = array_reverse($messages);
    
    // Decrypt messages
    $chatEncryption = new ChatEncryption();
    foreach ($messages as &$msg) {
        try {
            $decrypted = $chatEncryption->decryptMessage(
                $msg['message_encrypted'],
                $conversationId,
                $msg['encryption_key_hash']
            );
            $msg['message'] = $decrypted;
            unset($msg['message_encrypted']);
            unset($msg['encryption_key_hash']);
        } catch (Exception $e) {
            error_log("Failed to decrypt message {$msg['message_id']}: " . $e->getMessage());
            $msg['message'] = '[Message could not be decrypted]';
            $msg['decryption_failed'] = true;
        }
        
        $msg['is_own_message'] = ($msg['sender_id'] == $userId);
        $msg['message_id'] = (int)$msg['message_id'];
        $msg['sender_id'] = (int)$msg['sender_id'];
    }
    
    echo json_encode([
        'success' => true,
        'messages' => $messages
    ]);
    
} catch (Exception $e) {
    error_log('Chat get-messages error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to retrieve messages'
    ]);
}
