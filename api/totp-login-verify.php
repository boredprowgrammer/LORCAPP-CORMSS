<?php
/**
 * TOTP Login Verification API
 * Verify TOTP code during login (after password verification)
 */

// Register shutdown function to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json', true, 500);
        echo json_encode([
            'success' => false,
            'message' => 'Server error occurred'
        ]);
        error_log("TOTP Fatal Error: " . $error['message'] . " in " . $error['file'] . " on line " . $error['line']);
    }
});

// Start output buffering FIRST before any includes
ob_start();

// Suppress ALL PHP errors and warnings from being displayed
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

try {
    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../vendor/autoload.php';
} catch (Exception $e) {
    ob_end_clean();
    header('Content-Type: application/json', true, 500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load dependencies'
    ]);
    error_log("TOTP Include Error: " . $e->getMessage());
    exit;
}

use RobThree\Auth\TwoFactorAuth;

// Clear any accumulated output from includes
if (ob_get_length()) {
    ob_clean();
}

header('Content-Type: application/json');

/**
 * Send JSON response and exit
 */
function sendJsonResponse($data, $statusCode = 200) {
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

// This endpoint is called during login, so user is not yet authenticated
// If already logged in, redirect to launchpad instead of error
if (Security::isLoggedIn()) {
    sendJsonResponse([
        'success' => true,
        'already_logged_in' => true,
        'redirect' => BASE_URL . '/launchpad.php',
        'message' => 'Already logged in'
    ]);
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

// Validate CSRF token
$csrfToken = $_POST['csrf_token'] ?? '';
if (!Security::validateCSRFToken($csrfToken, 'login', false)) {
    sendJsonResponse(['success' => false, 'message' => 'Invalid security token'], 403);
}

$code = Security::sanitizeInput($_POST['code'] ?? '');
$userId = (int)($_SESSION['totp_pending_user_id'] ?? 0);
$isBackupCode = isset($_POST['is_backup']) && $_POST['is_backup'] === '1';

if (empty($code) || empty($userId)) {
    sendJsonResponse([
        'success' => false,
        'message' => 'Invalid request'
    ]);
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Rate limiting check
    $stmt = $db->prepare("
        SELECT COUNT(*) as attempt_count 
        FROM totp_attempts 
        WHERE user_id = ? 
        AND ip_address = ? 
        AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        AND success = 0
    ");
    $stmt->execute([$userId, $_SERVER['REMOTE_ADDR']]);
    $attempts = $stmt->fetch();
    
    if ($attempts['attempt_count'] >= 10) {
        sendJsonResponse([
            'success' => false,
            'message' => 'Too many failed attempts. Please try again in 15 minutes.'
        ]);
    }
    
    // Get user data
    $stmt = $db->prepare("
        SELECT u.*, d.district_name, lc.local_name
        FROM users u
        LEFT JOIN districts d ON u.district_code = d.district_code
        LEFT JOIN local_congregations lc ON u.local_code = lc.local_code
        WHERE u.user_id = ? AND u.is_active = 1 AND u.totp_enabled = 1
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        sendJsonResponse([
            'success' => false,
            'message' => 'Invalid session'
        ]);
    }
    
    $isValid = false;
    
    if ($isBackupCode) {
        // Verify backup code
        $backupCodesJson = Encryption::decrypt($user['totp_backup_codes_encrypted'], $user['district_code'] ?? 'SYSTEM');
        $hashedBackupCodes = json_decode($backupCodesJson, true);
        
        // Check if code matches any backup code
        foreach ($hashedBackupCodes as $index => $hashedCode) {
            if (password_verify($code, $hashedCode)) {
                $isValid = true;
                
                // Remove used backup code
                unset($hashedBackupCodes[$index]);
                $hashedBackupCodes = array_values($hashedBackupCodes); // Reindex
                
                // Update database
                $encryptedBackupCodes = Encryption::encrypt(json_encode($hashedBackupCodes), $user['district_code'] ?? 'SYSTEM');
                $updateStmt = $db->prepare("
                    UPDATE users 
                    SET totp_backup_codes_encrypted = ?
                    WHERE user_id = ?
                ");
                $updateStmt->execute([$encryptedBackupCodes, $userId]);
                
                // Log backup code usage
                $auditStmt = $db->prepare("
                    INSERT INTO audit_log (user_id, action, ip_address, user_agent)
                    VALUES (?, 'totp_backup_used', ?, ?)
                ");
                $auditStmt->execute([
                    $userId,
                    $_SERVER['REMOTE_ADDR'],
                    $_SERVER['HTTP_USER_AGENT'] ?? ''
                ]);
                
                break;
            }
        }
    } else {
        // Verify TOTP code
        $secret = Encryption::decrypt($user['totp_secret_encrypted'], $user['district_code'] ?? 'SYSTEM');
        // Use explicit parameters: 6 digits, 30 second period, SHA1 algorithm
        $tfa = new TwoFactorAuth(APP_NAME, 6, 30, 'sha1');
        $isValid = $tfa->verifyCode($secret, $code, 1);
    }
    
    // Record attempt
    $attemptStmt = $db->prepare("
        INSERT INTO totp_attempts (user_id, ip_address, success)
        VALUES (?, ?, ?)
    ");
    $attemptStmt->execute([
        $userId,
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
            $userId,
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        
        sendJsonResponse([
            'success' => false,
            'message' => 'Invalid verification code. Please try again.'
        ]);
    }
    
    // Code is valid - complete login
    session_regenerate_id(true);
    
    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['district_code'] = $user['district_code'];
    $_SESSION['local_code'] = $user['local_code'];
    $_SESSION['last_activity'] = time();
    $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // Clear pending 2FA session
    unset($_SESSION['totp_pending_user_id']);
    unset($_SESSION['totp_pending_remember_me']);
    
    // Update last login and last used
    $updateStmt = $db->prepare("
        UPDATE users 
        SET last_login = NOW(), totp_last_used = NOW()
        WHERE user_id = ?
    ");
    $updateStmt->execute([$user['user_id']]);
    
    // Handle remember me if it was requested
    if (isset($_SESSION['totp_pending_remember_me']) && $_SESSION['totp_pending_remember_me']) {
        Security::setRememberMeToken($user['user_id']);
    }
    
    // Clear login attempts
    Security::resetLoginAttempts($user['username']);
    
    // Log successful verification
    $auditStmt = $db->prepare("
        INSERT INTO audit_log (user_id, action, ip_address, user_agent)
        VALUES (?, 'totp_verified', ?, ?)
    ");
    $auditStmt->execute([
        $user['user_id'],
        $_SERVER['REMOTE_ADDR'],
        $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
    
    sendJsonResponse([
        'success' => true,
        'message' => 'Login successful',
        'redirect' => BASE_URL . '/launchpad.php'
    ]);
    
} catch (Exception $e) {
    error_log("TOTP login verification error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    sendJsonResponse([
        'success' => false,
        'message' => 'Error verifying code. Please try again.'
    ], 500);
}
