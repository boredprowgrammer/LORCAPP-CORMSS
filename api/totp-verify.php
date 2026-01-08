<?php
/**
 * TOTP Verification API
 * Verify TOTP code and enable 2FA
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

$code = Security::sanitizeInput($_POST['code'] ?? '');

if (empty($code)) {
    echo json_encode(['success' => false, 'message' => 'Verification code is required']);
    exit;
}

try {
    // Rate limiting check - prevent brute force
    $stmt = $db->prepare("
        SELECT COUNT(*) as attempt_count 
        FROM totp_attempts 
        WHERE user_id = ? 
        AND ip_address = ? 
        AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        AND success = 0
    ");
    $stmt->execute([$currentUser['user_id'], $_SERVER['REMOTE_ADDR']]);
    $attempts = $stmt->fetch();
    
    if ($attempts['attempt_count'] >= 5) {
        echo json_encode([
            'success' => false,
            'message' => 'Too many failed attempts. Please try again in 15 minutes.'
        ]);
        exit;
    }
    
    // Get encrypted secret
    $stmt = $db->prepare("
        SELECT totp_secret_encrypted, totp_enabled 
        FROM users 
        WHERE user_id = ?
    ");
    $stmt->execute([$currentUser['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user || empty($user['totp_secret_encrypted'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Two-Factor Authentication is not set up. Please set it up first.'
        ]);
        exit;
    }
    
    // Decrypt secret using user's district code
    $userDistrict = $currentUser['district_code'] ?? 'SYSTEM';
    $secret = Encryption::decrypt($user['totp_secret_encrypted'], $userDistrict);
    
    // Initialize TwoFactorAuth with explicit parameters matching setup
    // 6 digits, 30 second period, SHA1 algorithm
    $tfa = new TwoFactorAuth(APP_NAME, 6, 30, 'sha1');
    
    // Verify the code (with time window of ±1 period = 30 seconds before/after)
    $isValid = $tfa->verifyCode($secret, $code, 1);
    
    // Record attempt
    $attemptStmt = $db->prepare("
        INSERT INTO totp_attempts (user_id, ip_address, success)
        VALUES (?, ?, ?)
    ");
    $attemptStmt->execute([
        $currentUser['user_id'],
        $_SERVER['REMOTE_ADDR'],
        $isValid ? 1 : 0
    ]);
    
    if (!$isValid) {
        // Log failed attempt
        $auditStmt = $db->prepare("
            INSERT INTO audit_log (user_id, action, ip_address, user_agent)
            VALUES (?, 'totp_failed_attempt', ?, ?)
        ");
        $auditStmt->execute([
            $currentUser['user_id'],
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        
        echo json_encode([
            'success' => false,
            'message' => 'Invalid verification code. Please try again.'
        ]);
        exit;
    }
    
    // Code is valid - enable 2FA
    $stmt = $db->prepare("
        UPDATE users 
        SET totp_enabled = 1,
            totp_verified_at = NOW()
        WHERE user_id = ?
    ");
    $stmt->execute([$currentUser['user_id']]);
    
    // Log successful enablement
    $auditStmt = $db->prepare("
        INSERT INTO audit_log (user_id, action, ip_address, user_agent)
        VALUES (?, 'totp_enabled', ?, ?)
    ");
    $auditStmt->execute([
        $currentUser['user_id'],
        $_SERVER['REMOTE_ADDR'],
        $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Two-Factor Authentication has been successfully enabled!'
    ]);
    
} catch (Exception $e) {
    error_log("TOTP verification error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error verifying code. Please try again.'
    ]);
}
