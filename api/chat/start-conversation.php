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

// Validate CSRF token
if (!isset($_POST['csrf_token']) || !Security::validateCSRFToken($_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

try {
    $participantId = $_POST['participant_id'] ?? null;
    
    // Get database connection
    $pdo = Database::getInstance()->getConnection();
    
    if (!$participantId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Participant ID required']);
        exit;
    }
    
    $userId = $_SESSION['user_id'];
    
    // Verify the other user exists and is local or district
    $stmt = $pdo->prepare("
        SELECT user_id, username, role 
        FROM users 
        WHERE user_id = :user_id 
        AND role IN ('local', 'district')
        AND is_active = 1
    ");
    $stmt->execute(['user_id' => $participantId]);
    $otherUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$otherUser) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'User not found or cannot chat']);
        exit;
    }
    
    if ($participantId == $userId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Cannot start conversation with yourself']);
        exit;
    }
    
    // Check if conversation already exists between these two users
    $stmt = $pdo->prepare("
        SELECT c.conversation_id 
        FROM chat_conversations c
        INNER JOIN chat_participants p1 ON c.conversation_id = p1.conversation_id
        INNER JOIN chat_participants p2 ON c.conversation_id = p2.conversation_id
        WHERE c.conversation_type = 'direct'
        AND p1.user_id = :user1
        AND p2.user_id = :user2
        LIMIT 1
    ");
    $stmt->execute([
        'user1' => $userId,
        'user2' => $participantId
    ]);
    
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        // Return existing conversation
        echo json_encode([
            'success' => true,
            'conversation_id' => (int)$existing['conversation_id'],
            'is_new' => false
        ]);
        exit;
    }
    
    // Create new conversation
    $pdo->beginTransaction();
    
    $stmt = $pdo->prepare("
        INSERT INTO chat_conversations (
            conversation_type,
            created_by
        ) VALUES (
            'direct',
            :created_by
        )
    ");
    $stmt->execute(['created_by' => $userId]);
    
    $conversationId = $pdo->lastInsertId();
    
    // Add both participants
    $stmt = $pdo->prepare("
        INSERT INTO chat_participants (conversation_id, user_id) 
        VALUES 
            (:conversation_id1, :user1),
            (:conversation_id2, :user2)
    ");
    $stmt->execute([
        'conversation_id1' => $conversationId,
        'conversation_id2' => $conversationId,
        'user1' => $userId,
        'user2' => $participantId
    ]);
    
    // Log to audit
    $stmt = $pdo->prepare("
        INSERT INTO chat_audit_log (
            user_id, 
            action, 
            conversation_id
        ) VALUES (
            :user_id, 
            'create_conversation', 
            :conversation_id
        )
    ");
    $stmt->execute([
        'user_id' => $userId,
        'conversation_id' => $conversationId
    ]);
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'conversation_id' => (int)$conversationId,
        'is_new' => true,
        'other_user' => [
            'user_id' => (int)$otherUser['user_id'],
            'username' => $otherUser['username'],
            'role' => $otherUser['role']
        ]
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Chat start-conversation error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to start conversation'
    ]);
}
