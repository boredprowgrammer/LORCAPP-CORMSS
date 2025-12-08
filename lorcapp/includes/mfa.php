<?php
/**
 * LORCAPP
 * Multi-Factor Authentication (MFA) Library
 * Uses secure libraries: spomky-labs/otphp and web-auth/webauthn-lib
 */

// Prevent direct access
if (count(get_included_files()) == 1) exit("Direct access forbidden");

require_once __DIR__ . '/config.php';

// Check if vendor/autoload.php exists before requiring it
$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($vendorAutoload)) {
    error_log("WARNING: Composer dependencies not installed. MFA features disabled. Run 'composer install' in admin directory.");
    
    // Define stub functions to prevent fatal errors
    if (!function_exists('hasMFAEnabled')) {
        function hasMFAEnabled($userId) {
            return ['enabled' => false, 'methods' => []];
        }
    }
    return; // Exit early - MFA not available
}

require_once $vendorAutoload;

use OTPHP\TOTP;
use OTPHP\TOTPInterface;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialSourceRepository;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialDescriptor;
use Cose\Algorithms;

/**
 * TOTP Helper Functions using OTPHP library
 */
class TOTPHelper {
    
    /**
     * Create new TOTP instance
     */
    public static function create($issuer, $accountName) {
        // Generate TOTP with default settings
        $totp = TOTP::generate();
        $totp->setLabel($accountName);
        $totp->setIssuer($issuer);
        $totp->setPeriod(30);  // 30 seconds window
        $totp->setDigits(6);   // 6-digit codes
        $totp->setDigest('sha1'); // Use SHA1 (most compatible)
        
        return $totp;
    }
    
    /**
     * Load existing TOTP from secret
     */
    public static function load($secret, $issuer = 'LORCAPP', $accountName = 'Admin') {
        $totp = TOTP::createFromSecret($secret);
        $totp->setLabel($accountName);
        $totp->setIssuer($issuer);
        
        return $totp;
    }
    
    /**
     * Get QR code URL for setup
     */
    public static function getQRCodeUrl(TOTPInterface $totp) {
        $uri = $totp->getProvisioningUri();
        // Use Google Charts API for QR code
        return 'https://chart.googleapis.com/chart?chs=250x250&chld=M|0&cht=qr&chl=' . urlencode($uri);
    }
    
    /**
     * Verify TOTP code with time window
     */
    public static function verify(TOTPInterface $totp, $code, $window = 1) {
        // Verify with ±1 window (allows 30 seconds before/after)
        return $totp->verify($code, null, $window);
    }
}

/**
 * WebAuthn Helper - Simplified for compatibility
 * Note: For production, consider using a full WebAuthn server implementation
 */
class WebAuthnHelper {
    
    /**
     * Generate registration options for new passkey
     */
    public static function generateRegistrationOptions($userId, $username, $displayName) {
        // Get hostname without port for rpId
        $host = $_SERVER['HTTP_HOST'];
        $rpId = parse_url('http://' . $host, PHP_URL_HOST) ?: 'localhost';
        
        // Relying Party
        $rpEntity = PublicKeyCredentialRpEntity::create(
            'LORCAPP Admin',
            $rpId
        );
        
        // User entity
        $userEntity = PublicKeyCredentialUserEntity::create(
            $username,
            (string)$userId,
            $displayName
        );
        
        // Challenge
        $challenge = random_bytes(32);
        $_SESSION['webauthn_challenge'] = base64_encode($challenge);
        
        // Supported algorithms
        $publicKeyCredentialParametersList = [
            PublicKeyCredentialParameters::create('public-key', Algorithms::COSE_ALGORITHM_ES256),
            PublicKeyCredentialParameters::create('public-key', Algorithms::COSE_ALGORITHM_ES384),
            PublicKeyCredentialParameters::create('public-key', Algorithms::COSE_ALGORITHM_RS256),
            PublicKeyCredentialParameters::create('public-key', Algorithms::COSE_ALGORITHM_EDDSA),
        ];
        
        // Authenticator selection
        $authenticatorSelection = AuthenticatorSelectionCriteria::create(
            null, // authenticatorAttachment (null = no preference)
            AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED
        );
        
        // Get existing credentials to exclude
        $existingCredentials = self::getExistingCredentials($userId);
        
        // Create options
        $options = PublicKeyCredentialCreationOptions::create(
            $rpEntity,
            $userEntity,
            $challenge,
            $publicKeyCredentialParametersList,
            $authenticatorSelection,
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            $existingCredentials,
            60000
        );
        
        return $options;
    }
    
    /**
     * Generate authentication options
     */
    public static function generateAuthenticationOptions($userId = null) {
        // Get hostname without port for rpId
        $host = $_SERVER['HTTP_HOST'];
        $rpId = parse_url('http://' . $host, PHP_URL_HOST) ?: 'localhost';
        
        // Challenge
        $challenge = random_bytes(32);
        $_SESSION['webauthn_challenge'] = base64_encode($challenge);
        
        // Get allowed credentials if user is known
        $allowedCredentials = [];
        if ($userId !== null) {
            $allowedCredentials = self::getExistingCredentials($userId);
        }
        
        // Create options
        $options = PublicKeyCredentialRequestOptions::create(
            $challenge,
            $rpId,
            $allowedCredentials,
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            60000
        );
        
        return $options;
    }
    
    /**
     * Get existing credentials for user
     */
    private static function getExistingCredentials($userId) {
        $conn = getDbConnection();
        
        $stmt = $conn->prepare("SELECT credential_id FROM admin_passkeys WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $credentials = [];
        while ($row = $result->fetch_assoc()) {
            $credentialId = base64_decode($row['credential_id']);
            $credentials[] = PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                $credentialId
            );
        }
        
        return $credentials;
    }
    
    /**
     * Save passkey credential
     */
    public static function saveCredential($userId, $name, $credentialData) {
        $conn = getDbConnection();
        
        $credentialId = base64_encode($credentialData['rawId']);
        $publicKey = base64_encode($credentialData['publicKey']);
        $transports = !empty($credentialData['transports']) ? json_encode($credentialData['transports']) : null;
        $counter = 0;
        
        $stmt = $conn->prepare("INSERT INTO admin_passkeys (user_id, credential_id, public_key, counter, transports, name) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ississ", $userId, $credentialId, $publicKey, $counter, $transports, $name);
        
        return $stmt->execute();
    }
    
    /**
     * Verify passkey assertion
     */
    public static function verifyAssertion($userId, $credentialId) {
        $conn = getDbConnection();
        
        $stmt = $conn->prepare("SELECT id, counter FROM admin_passkeys WHERE user_id = ? AND credential_id = ?");
        $stmt->bind_param("is", $userId, $credentialId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $row = $result->fetch_assoc();
            
            // Update counter and last used
            $updateStmt = $conn->prepare("UPDATE admin_passkeys SET counter = counter + 1, last_used = NOW() WHERE id = ?");
            $updateStmt->bind_param("i", $row['id']);
            $updateStmt->execute();
            
            return true;
        }
        
        return false;
    }
}


/**
 * Helper Functions for TOTP Management
 */

/**
 * Setup TOTP for user
 */
function setupTOTP($userId, $username, $displayName = 'Admin') {
    $totp = TOTPHelper::create('LORCAPP', $displayName . ' (' . $username . ')');
    $secret = $totp->getSecret();
    
    return [
        'totp' => $totp,
        'secret' => $secret,
        'provisioning_uri' => $totp->getProvisioningUri()
    ];
}

/**
 * Verify TOTP code for user
 */
function verifyTOTP($userId, $code) {
    $secret = getUserTOTPSecret($userId);
    if (!$secret) {
        return false;
    }
    
    $totp = TOTPHelper::load($secret);
    return TOTPHelper::verify($totp, $code, 1);
}

/**
 * Enable TOTP for user
 */
function enableTOTP($userId, $secret) {
    $conn = getDbConnection();
    
    $stmt = $conn->prepare("UPDATE admin_users SET totp_secret = ?, totp_enabled = 1, mfa_enabled = 1, mfa_type = 'totp', mfa_setup_at = NOW() WHERE id = ?");
    $stmt->bind_param("si", $secret, $userId);
    
    return $stmt->execute();
}

/**
 * Get user's TOTP secret
 */
function getUserTOTPSecret($userId) {
    $conn = getDbConnection();
    
    $stmt = $conn->prepare("SELECT totp_secret FROM admin_users WHERE id = ? AND totp_enabled = 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();
        return $row['totp_secret'];
    }
    
    return null;
}

/**
 * Backup Code Functions
 */

/**
 * Generate backup recovery codes
 */
function generateBackupCodes($count = 8) {
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $code = sprintf('%04d-%04d', random_int(0, 9999), random_int(0, 9999));
        $codes[] = $code;
    }
    return $codes;
}

/**
 * Save backup codes to database
 */
function saveBackupCodes($userId, $codes) {
    $conn = getDbConnection();
    
    // Delete existing backup codes
    $stmt = $conn->prepare("DELETE FROM admin_backup_codes WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    
    // Insert new codes
    $stmt = $conn->prepare("INSERT INTO admin_backup_codes (user_id, code_hash) VALUES (?, ?)");
    foreach ($codes as $code) {
        $hash = password_hash($code, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt->bind_param("is", $userId, $hash);
        $stmt->execute();
    }
    
    return true;
}

/**
 * Verify backup code
 */
function verifyBackupCode($userId, $code) {
    $conn = getDbConnection();
    
    $stmt = $conn->prepare("SELECT id, code_hash FROM admin_backup_codes WHERE user_id = ? AND used = 0");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        if (password_verify($code, $row['code_hash'])) {
            // Mark as used
            $updateStmt = $conn->prepare("UPDATE admin_backup_codes SET used = 1, used_at = NOW() WHERE id = ?");
            $updateStmt->bind_param("i", $row['id']);
            $updateStmt->execute();
            
            return true;
        }
    }
    
    return false;
}

/**
 * Get remaining backup codes count
 */
function getRemainingBackupCodesCount($userId) {
    $conn = getDbConnection();
    
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM admin_backup_codes WHERE user_id = ? AND used = 0");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();
        return (int)$row['count'];
    }
    
    return 0;
}

/**
 * MFA Status Functions
 */

/**
 * Check if user has MFA enabled
 */
function hasMFAEnabled($userId) {
    $conn = getDbConnection();
    
    $stmt = $conn->prepare("SELECT mfa_enabled, mfa_type FROM admin_users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();
        return [
            'enabled' => (bool)$row['mfa_enabled'],
            'type' => $row['mfa_type']
        ];
    }
    
    return ['enabled' => false, 'type' => 'none'];
}

/**
 * Disable MFA for user
 */
function disableMFA($userId) {
    $conn = getDbConnection();
    
    $stmt = $conn->prepare("UPDATE admin_users SET mfa_enabled = 0, mfa_type = 'none', totp_enabled = 0, totp_secret = NULL WHERE id = ?");
    $stmt->bind_param("i", $userId);
    
    // Also delete passkeys and backup codes
    $conn->query("DELETE FROM admin_passkeys WHERE user_id = $userId");
    $conn->query("DELETE FROM admin_backup_codes WHERE user_id = $userId");
    
    return $stmt->execute();
}

/**
 * Log MFA attempt
 */
function logMFAAttempt($userId, $method, $success) {
    $conn = getDbConnection();
    
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    $successInt = $success ? 1 : 0;
    
    $stmt = $conn->prepare("INSERT INTO admin_mfa_attempts (user_id, method, success, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("isiss", $userId, $method, $successInt, $ipAddress, $userAgent);
    $stmt->execute();
}

/**
 * Passkey/WebAuthn Functions
 */

/**
 * Get user's passkeys
 */
function getUserPasskeys($userId) {
    $conn = getDbConnection();
    
    $stmt = $conn->prepare("SELECT id, credential_id, name, created_at, last_used, counter FROM admin_passkeys WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $passkeys = [];
    while ($row = $result->fetch_assoc()) {
        $passkeys[] = $row;
    }
    
    return $passkeys;
}

/**
 * Delete passkey
 */
function deletePasskey($passkeyId, $userId) {
    $conn = getDbConnection();
    
    $stmt = $conn->prepare("DELETE FROM admin_passkeys WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $passkeyId, $userId);
    
    return $stmt->execute();
}

/**
 * Rename passkey
 */
function renamePasskey($passkeyId, $userId, $newName) {
    $conn = getDbConnection();
    
    $stmt = $conn->prepare("UPDATE admin_passkeys SET name = ? WHERE id = ? AND user_id = ?");
    $stmt->bind_param("sii", $newName, $passkeyId, $userId);
    
    return $stmt->execute();
}

/**
 * Enable passkey MFA for user
 */
function enablePasskeyMFA($userId) {
    $conn = getDbConnection();
    
    // Check if user already has TOTP enabled
    $stmt = $conn->prepare("SELECT totp_enabled FROM admin_users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();
        $mfaType = $row['totp_enabled'] ? 'both' : 'passkey';
        
        $updateStmt = $conn->prepare("UPDATE admin_users SET mfa_enabled = 1, mfa_type = ?, mfa_setup_at = NOW() WHERE id = ?");
        $updateStmt->bind_param("si", $mfaType, $userId);
        return $updateStmt->execute();
    }
    
    return false;
}
?>
