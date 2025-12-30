<?php
/**
 * API: Update Dark Mode Preference
 */

require_once __DIR__ . '/../config/config.php';

// Require login
Security::requireLogin();

// Verify CSRF token
if (!isset($_POST['csrf_token']) || !Security::validateCSRFToken($_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $currentUser = getCurrentUser();
    
    if (!$currentUser) {
        throw new Exception('User not found');
    }
    
    // Get dark mode preference from request
    $darkMode = isset($_POST['dark_mode']) ? (int)$_POST['dark_mode'] : 0;
    
    // Validate value (must be 0 or 1)
    if (!in_array($darkMode, [0, 1])) {
        throw new Exception('Invalid dark mode value');
    }
    
    // Update database
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("UPDATE users SET dark_mode = ? WHERE user_id = ?");
    $stmt->execute([$darkMode, $currentUser['user_id']]);
    
    // Note: Audit logging can be added later if needed
    // logAudit('SETTINGS_UPDATE', 'users', $currentUser['user_id'], "Dark mode " . ($darkMode ? "enabled" : "disabled"));
    
    echo json_encode([
        'success' => true,
        'message' => 'Dark mode preference updated',
        'dark_mode' => $darkMode
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to update preference: ' . $e->getMessage()
    ]);
}
