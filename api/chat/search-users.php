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
    $search = $_GET['q'] ?? '';
    $userId = $_SESSION['user_id'];
    
    // Get database connection
    $pdo = Database::getInstance()->getConnection();
    
    if (strlen($search) < 2) {
        echo json_encode([
            'success' => true,
            'users' => []
        ]);
        exit;
    }
    
    // Search for local and district users (excluding self)
    $stmt = $pdo->prepare("
        SELECT 
            user_id,
            username,
            role,
            district_code,
            local_code
        FROM users 
        WHERE (role = 'local' OR role = 'district')
        AND user_id != :user_id
        AND is_active = 1
        AND username LIKE :search
        ORDER BY username
        LIMIT 20
    ");
    
    $stmt->execute([
        'user_id' => $userId,
        'search' => '%' . $search . '%'
    ]);
    
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format results
    foreach ($users as &$user) {
        $user['user_id'] = (int)$user['user_id'];
        $user['display_name'] = $user['username'];
        $user['display_subtitle'] = ucfirst($user['role']);
        
        if ($user['role'] === 'district' && !empty($user['district_code'])) {
            $user['display_subtitle'] .= ' - ' . $user['district_code'];
        } elseif ($user['role'] === 'local' && !empty($user['local_code'])) {
            $user['display_subtitle'] .= ' - ' . $user['local_code'];
        }
    }
    
    echo json_encode([
        'success' => true,
        'users' => $users
    ]);
    
} catch (Exception $e) {
    error_log('Chat search-users error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to search users'
    ]);
}
