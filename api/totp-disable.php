<?php
/**
 * TOTP Disable API
 * Disable Two-Factor Authentication
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use RobThree\Auth\TwoFactorAuth;

Security::requireLogin();

header('Content-Type: application/json');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Validate CSRF token
$csrfToken = $_POST['csrf_token'] ?? '';
if (!Security::validateCSRFToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit;
}

$password = $_POST['password'] ?? '';
$code = Security::sanitizeInput($_POST['code'] ?? '');

if (empty($password) || empty($code)) {
    echo json_encode([
        'success' => false,
        'message' => 'Password and verification code are required'
    ]);
    exit;
}

try {
    // Get user data
    $stmt = $db->prepare("
        SELECT password, totp_secret_encrypted, totp_enabled 
        FROM users 
        WHERE user_id = ?
    ");
    $stmt->execute([$currentUser['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }
    
    // Verify password first
    if (!password_verify($password, $user['password'])) {
        // Log failed attempt
        $auditStmt = $db->prepare("
            INSERT INTO audit_log (user_id, action, ip_address, user_agent)
            VALUES (?, 'totp_disable_failed', ?, ?)
        ");
        $auditStmt->execute([
            $currentUser['user_id'],
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        
        echo json_encode(['success' => false, 'message' => 'Incorrect password']);
        exit;
    }
    
    if (!$user['totp_enabled']) {
        echo json_encode([
            'success' => false,
            'message' => 'Two-Factor Authentication is not enabled'
        ]);
        exit;
    }
    
    // Decrypt secret and verify code
    $userDistrict = $currentUser['district_code'] ?? 'SYSTEM';
    $secret = Encryption::decrypt($user['totp_secret_encrypted'], $userDistrict);
    // Use explicit parameters: 6 digits, 30 second period, SHA1 algorithm
    $tfa = new TwoFactorAuth(APP_NAME, 6, 30, 'sha1');
    
    if (!$tfa->verifyCode($secret, $code, 1)) {
        // Log failed attempt
        $auditStmt = $db->prepare("
            INSERT INTO audit_log (user_id, action, ip_address, user_agent)
            VALUES (?, 'totp_disable_failed', ?, ?)
        ");
        $auditStmt->execute([
            $currentUser['user_id'],
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        
        echo json_encode(['success' => false, 'message' => 'Invalid verification code']);
        exit;
    }
    
    // Disable 2FA and clear secrets
    $stmt = $db->prepare("
        UPDATE users 
        SET totp_enabled = 0,
            totp_secret_encrypted = NULL,
            totp_backup_codes_encrypted = NULL,
            totp_verified_at = NULL,
            totp_last_used = NULL
        WHERE user_id = ?
    ");
    $stmt->execute([$currentUser['user_id']]);
    
    // Log successful disablement
    $auditStmt = $db->prepare("
        INSERT INTO audit_log (user_id, action, ip_address, user_agent)
        VALUES (?, 'totp_disabled', ?, ?)
    ");
    $auditStmt->execute([
        $currentUser['user_id'],
        $_SERVER['REMOTE_ADDR'],
        $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Two-Factor Authentication has been disabled'
    ]);
    
} catch (Exception $e) {
    error_log("TOTP disable error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error disabling Two-Factor Authentication. Please try again.'
    ]);
}
