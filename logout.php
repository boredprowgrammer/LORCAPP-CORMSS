<?php
require_once __DIR__ . '/config/config.php';

// Log logout action
if (Security::isLoggedIn()) {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            INSERT INTO audit_log (user_id, action, ip_address, user_agent) 
            VALUES (?, 'logout', ?, ?)
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    } catch (Exception $e) {
        error_log("Logout audit error: " . $e->getMessage());
    }
}

// Clear remember me token
Security::clearRememberMeToken();

// Clear session
session_unset();
session_destroy();

// Redirect to login
redirect(BASE_URL . '/login.php');
?>
