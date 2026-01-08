<?php
/**
 * TOTP Setup API
 * Generate TOTP secret and QR code for 2FA setup
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

try {
    // Check if 2FA is already enabled
    $stmt = $db->prepare("SELECT totp_enabled FROM users WHERE user_id = ?");
    $stmt->execute([$currentUser['user_id']]);
    $user = $stmt->fetch();
    
    if ($user['totp_enabled']) {
        echo json_encode([
            'success' => false,
            'message' => 'Two-Factor Authentication is already enabled. Disable it first to set up again.'
        ]);
        exit;
    }
    
    // Initialize TwoFactorAuth with explicit parameters for maximum compatibility
    // 6 digits, 30 second period, SHA1 algorithm (most compatible with all apps)
    $tfa = new TwoFactorAuth(APP_NAME, 6, 30, 'sha1');
    
    // Generate a new secret
    $secret = $tfa->createSecret(160); // 160 bits = 32 chars in base32
    
    // Encrypt the secret before storing
    // Use the user's district code for encryption, not user_id
    $userDistrict = $currentUser['district_code'] ?? 'SYSTEM';
    $encryptedSecret = Encryption::encrypt($secret, $userDistrict);
    
    // Generate 10 backup codes (8 characters each, alphanumeric)
    $backupCodes = [];
    for ($i = 0; $i < 10; $i++) {
        $backupCodes[] = strtoupper(bin2hex(random_bytes(4))); // 8 hex chars
    }
    
    // Hash backup codes before storing (like passwords)
    $hashedBackupCodes = array_map(function($code) {
        return password_hash($code, PASSWORD_DEFAULT);
    }, $backupCodes);
    
    $encryptedBackupCodes = Encryption::encrypt(json_encode($hashedBackupCodes), $userDistrict);
    
    // Store in database (not yet enabled - requires verification)
    $stmt = $db->prepare("
        UPDATE users 
        SET totp_secret_encrypted = ?,
            totp_backup_codes_encrypted = ?,
            totp_enabled = 0
        WHERE user_id = ?
    ");
    $stmt->execute([
        $encryptedSecret,
        $encryptedBackupCodes,
        $currentUser['user_id']
    ]);
    
    // Generate QR code text (will be generated client-side with qrcodejs)
    $qrCodeText = $tfa->getQRText(
        $currentUser['username'] . '@' . APP_NAME,
        $secret
    );
    
    // Log setup initiation
    $auditStmt = $db->prepare("
        INSERT INTO audit_log (user_id, action, ip_address, user_agent)
        VALUES (?, 'totp_setup_initiated', ?, ?)
    ");
    $auditStmt->execute([
        $currentUser['user_id'],
        $_SERVER['REMOTE_ADDR'],
        $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
    
    echo json_encode([
        'success' => true,
        'secret' => $secret, // Send to display (will not be sent again)
        'qrCodeText' => $qrCodeText, // otpauth:// URL for client-side QR generation
        'backupCodes' => $backupCodes, // Send plain codes to user (will not be sent again)
        'message' => 'Scan the QR code with your authenticator app and save your backup codes.'
    ]);
    
} catch (Exception $e) {
    error_log("TOTP setup error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error setting up Two-Factor Authentication. Please try again.'
    ]);
}
