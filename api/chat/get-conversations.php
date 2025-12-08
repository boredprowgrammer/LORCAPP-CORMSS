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
    $userId = $_SESSION['user_id'];
    
    // Get database connection
    $pdo = Database::getInstance()->getConnection();
    
    // Get all conversations for this user with last message info
    $stmt = $pdo->prepare("
        SELECT 
            c.conversation_id,
            c.conversation_type as type,
            c.conversation_name as name,
            c.created_by,
            c.created_at,
            c.last_message_at,
            (SELECT COUNT(*) 
             FROM chat_messages cm 
             WHERE cm.conversation_id = c.conversation_id 
             AND cm.sent_at > COALESCE(p.last_read_at, '1970-01-01')
             AND cm.sender_id != :user_id) as unread_count,
            (SELECT u.full_name 
             FROM chat_participants cp2 
             JOIN users u ON cp2.user_id = u.user_id 
             WHERE cp2.conversation_id = c.conversation_id 
             AND cp2.user_id != :user_id2
             LIMIT 1) as other_username,
            (SELECT u.role 
             FROM chat_participants cp3 
             JOIN users u ON cp3.user_id = u.user_id 
             WHERE cp3.conversation_id = c.conversation_id 
             AND cp3.user_id != :user_id3
             LIMIT 1) as other_role,
            (SELECT cm2.message_encrypted 
             FROM chat_messages cm2 
             WHERE cm2.conversation_id = c.conversation_id 
             ORDER BY cm2.sent_at DESC 
             LIMIT 1) as last_message_encrypted,
            (SELECT cm2.encryption_key_hash 
             FROM chat_messages cm2 
             WHERE cm2.conversation_id = c.conversation_id 
             ORDER BY cm2.sent_at DESC 
             LIMIT 1) as last_message_key_hash
        FROM chat_conversations c
        INNER JOIN chat_participants p ON c.conversation_id = p.conversation_id
        WHERE p.user_id = :user_id4
        ORDER BY c.last_message_at DESC
    ");
    
    $stmt->execute([
        'user_id' => $userId,
        'user_id2' => $userId,
        'user_id3' => $userId,
        'user_id4' => $userId
    ]);
    
    $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Initialize chat encryption for decrypting last messages
    $chatEncryption = new ChatEncryption();
    
    // Format conversations
    foreach ($conversations as &$conv) {
        // For direct conversations without a name, use the other user's info
        if ($conv['type'] === 'direct' && empty($conv['name'])) {
            $conv['display_name'] = $conv['other_username'] ?: 'Unknown User';
            $conv['display_subtitle'] = ucfirst($conv['other_role'] ?: '');
        } else {
            $conv['display_name'] = $conv['name'];
            $conv['display_subtitle'] = 'Group Chat';
        }
        
        $conv['unread_count'] = (int)$conv['unread_count'];
        
        // Decrypt last message for preview
        $conv['last_message'] = null;
        if (!empty($conv['last_message_encrypted']) && !empty($conv['last_message_key_hash'])) {
            try {
                $conv['last_message'] = $chatEncryption->decryptMessage(
                    $conv['last_message_encrypted'],
                    $conv['conversation_id'],
                    $conv['last_message_key_hash']
                );
            } catch (Exception $e) {
                $conv['last_message'] = '[Message]';
            }
        }
        
        // Remove encrypted data from response
        unset($conv['last_message_encrypted']);
        unset($conv['last_message_key_hash']);
    }
    
    echo json_encode([
        'success' => true,
        'conversations' => $conversations
    ]);
    
} catch (Exception $e) {
    error_log('Chat get-conversations error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to retrieve conversations'
    ]);
}
