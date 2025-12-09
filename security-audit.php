<?php
/**
 * Security Audit System
 * Comprehensive security assessment including encryption processes
 * 
 * This tool performs:
 * 1. Configuration Security Analysis
 * 2. Encryption Implementation Audit
 * 3. Authentication & Authorization Checks
 * 4. Session Security Review
 * 5. Database Security Assessment
 * 6. File Permission Checks
 * 7. Input Validation Analysis
 * 8. HTTPS/SSL Configuration
 * 9. Dependency & Version Checks
 * 10. Logging & Monitoring Review
 */

// Require admin access
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/security.php';

Security::requireLogin();
Security::requireRole('admin');

// Security audit configuration
define('AUDIT_VERSION', '1.0.0');
define('AUDIT_DATE', date('Y-m-d H:i:s'));

class SecurityAudit {
    private $results = [];
    private $criticalIssues = [];
    private $highIssues = [];
    private $mediumIssues = [];
    private $lowIssues = [];
    private $passedChecks = [];
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Run complete security audit
     */
    public function runFullAudit() {
        $this->results['audit_info'] = [
            'version' => AUDIT_VERSION,
            'timestamp' => AUDIT_DATE,
            'auditor' => $_SESSION['username'] ?? 'system',
            'environment' => APP_ENV
        ];
        
        // Run all audit modules
        $this->auditConfiguration();
        $this->auditEncryption();
        $this->auditAuthentication();
        $this->auditSessionSecurity();
        $this->auditDatabase();
        $this->auditFilePermissions();
        $this->auditInputValidation();
        $this->auditHTTPS();
        $this->auditDependencies();
        $this->auditLogging();
        $this->auditCSRFProtection();
        $this->auditPasswordPolicy();
        $this->auditKeyManagement();
        
        // Compile summary
        $this->results['summary'] = [
            'critical' => count($this->criticalIssues),
            'high' => count($this->highIssues),
            'medium' => count($this->mediumIssues),
            'low' => count($this->lowIssues),
            'passed' => count($this->passedChecks),
            'total_checks' => count($this->criticalIssues) + count($this->highIssues) + 
                             count($this->mediumIssues) + count($this->lowIssues) + 
                             count($this->passedChecks)
        ];
        
        $this->results['issues'] = [
            'critical' => $this->criticalIssues,
            'high' => $this->highIssues,
            'medium' => $this->mediumIssues,
            'low' => $this->lowIssues
        ];
        
        $this->results['passed'] = $this->passedChecks;
        
        // Calculate security score (0-100)
        $this->results['security_score'] = $this->calculateSecurityScore();
        
        return $this->results;
    }
    
    /**
     * Audit application configuration
     */
    private function auditConfiguration() {
        $category = 'Configuration Security';
        
        // Check error reporting in production
        if (APP_ENV === 'production') {
            if (ini_get('display_errors') == 1) {
                $this->addCritical($category, 'Display Errors Enabled in Production', 
                    'Error messages expose sensitive system information', 
                    'Set display_errors=0 in production');
            } else {
                $this->addPassed($category, 'Display errors disabled in production');
            }
            
            if (error_reporting() !== 0) {
                $this->addHigh($category, 'Error Reporting Not Zero in Production',
                    'Errors may expose sensitive information',
                    'Set error_reporting(0) in production');
            } else {
                $this->addPassed($category, 'Error reporting disabled in production');
            }
        }
        
        // Check HTTPS enforcement
        if (APP_ENV === 'production' && empty($_SERVER['HTTPS'])) {
            $this->addCritical($category, 'HTTPS Not Enforced',
                'Data transmitted in plain text can be intercepted',
                'Enable HTTPS and redirect all HTTP traffic');
        } elseif (!empty($_SERVER['HTTPS'])) {
            $this->addPassed($category, 'HTTPS enabled');
        }
        
        // Check session security settings
        if (ini_get('session.cookie_httponly') != 1) {
            $this->addHigh($category, 'Session HttpOnly Flag Not Set',
                'Session cookies vulnerable to XSS attacks',
                'Set session.cookie_httponly=1');
        } else {
            $this->addPassed($category, 'Session HttpOnly flag enabled');
        }
        
        if (ini_get('session.cookie_secure') != 1 && APP_ENV === 'production') {
            $this->addHigh($category, 'Session Secure Flag Not Set',
                'Session cookies can be transmitted over HTTP',
                'Set session.cookie_secure=1 in production');
        } elseif (ini_get('session.cookie_secure') == 1) {
            $this->addPassed($category, 'Session Secure flag enabled');
        }
        
        // Check session.cookie_samesite
        $samesite = ini_get('session.cookie_samesite');
        if (empty($samesite) || $samesite === 'None') {
            $this->addMedium($category, 'SameSite Cookie Not Configured',
                'Vulnerable to CSRF attacks',
                'Set session.cookie_samesite=Strict or Lax');
        } else {
            $this->addPassed($category, 'SameSite cookie policy configured: ' . $samesite);
        }
        
        // Check for .env file in web root
        if (file_exists(__DIR__ . '/.env')) {
            $webAccessible = $this->checkWebAccessible('/.env');
            if ($webAccessible) {
                $this->addCritical($category, '.env File Accessible via Web',
                    'Environment variables including secrets can be downloaded',
                    'Block access via .htaccess or move outside webroot');
            } else {
                $this->addPassed($category, '.env file protected from web access');
            }
        }
        
        // Check if critical files are web-accessible
        $sensitiveFiles = [
            '/config/config.php',
            '/config/database.php',
            '/composer.json',
            '/docker-compose.yml'
        ];
        
        foreach ($sensitiveFiles as $file) {
            if (file_exists(__DIR__ . $file)) {
                if ($this->checkWebAccessible($file)) {
                    $this->addHigh($category, "Sensitive File Web Accessible: $file",
                        'Configuration files expose system details',
                        'Block access via .htaccess or server configuration');
                }
            }
        }
    }
    
    /**
     * Audit encryption implementation
     */
    private function auditEncryption() {
        $category = 'Encryption Security';
        
        // Check if OpenSSL is available
        if (!function_exists('openssl_encrypt')) {
            $this->addCritical($category, 'OpenSSL Not Available',
                'Cannot perform encryption operations',
                'Install/enable OpenSSL PHP extension');
            return;
        } else {
            $this->addPassed($category, 'OpenSSL extension available');
        }
        
        // Check encryption key configuration
        $lorcappKey = getenv('LORCAPP_ENCRYPTION_KEY');
        if (empty($lorcappKey)) {
            $this->addCritical($category, 'LORCAPP Encryption Key Not Set',
                'Cannot encrypt/decrypt LORCAPP data',
                'Set LORCAPP_ENCRYPTION_KEY in environment');
        } else {
            $this->addPassed($category, 'LORCAPP encryption key configured');
            
            // Check key strength
            if (strlen($lorcappKey) < 32) {
                $this->addHigh($category, 'Weak LORCAPP Encryption Key',
                    'Short keys are vulnerable to brute force',
                    'Use at least 32-character key: openssl rand -hex 32');
            } else {
                $this->addPassed($category, 'LORCAPP encryption key has adequate length');
            }
        }
        
        // Check district encryption keys
        $stmt = $this->db->query("SELECT district_code, encryption_key FROM districts");
        $districts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $missingKeys = 0;
        $weakKeys = 0;
        
        foreach ($districts as $district) {
            if (empty($district['encryption_key'])) {
                $missingKeys++;
            } else {
                $decodedKey = base64_decode($district['encryption_key']);
                if (strlen($decodedKey) < 32) {
                    $weakKeys++;
                }
            }
        }
        
        if ($missingKeys > 0) {
            $this->addHigh($category, "Missing District Encryption Keys",
                "$missingKeys districts lack encryption keys",
                'Generate keys: openssl rand -base64 32');
        }
        
        if ($weakKeys > 0) {
            $this->addMedium($category, "Weak District Encryption Keys",
                "$weakKeys districts have weak keys",
                'Rotate to stronger 32-byte keys');
        }
        
        if ($missingKeys === 0 && $weakKeys === 0 && count($districts) > 0) {
            $this->addPassed($category, 'All districts have strong encryption keys');
        }
        
        // Check encryption algorithm usage
        $this->checkEncryptionAlgorithms($category);
        
        // Check for hardcoded keys in code
        $this->checkHardcodedKeys($category);
        
        // Check Infisical integration
        if (class_exists('InfisicalKeyManager')) {
            $this->addPassed($category, 'Infisical key management integrated');
            
            // Try to authenticate
            try {
                $token = InfisicalKeyManager::authenticate();
                if (!empty($token)) {
                    $this->addPassed($category, 'Infisical authentication successful');
                }
            } catch (Exception $e) {
                $this->addMedium($category, 'Infisical Authentication Failed',
                    'Cannot access secure key storage: ' . $e->getMessage(),
                    'Check Infisical credentials and connection');
            }
        } else {
            $this->addMedium($category, 'Infisical Not Integrated',
                'Keys stored in database instead of secure vault',
                'Consider implementing Infisical for key management');
        }
        
        // Check key rotation policy
        $this->checkKeyRotation($category);
    }
    
    /**
     * Check encryption algorithms in use
     */
    private function checkEncryptionAlgorithms($category) {
        // Read encryption implementation files
        $encryptionFiles = [
            __DIR__ . '/includes/encryption.php',
            __DIR__ . '/lorcapp/includes/encryption.php',
            __DIR__ . '/includes/chat-encryption.php'
        ];
        
        $usesGCM = false;
        $usesCBC = false;
        
        foreach ($encryptionFiles as $file) {
            if (file_exists($file)) {
                $content = file_get_contents($file);
                if (strpos($content, 'aes-256-gcm') !== false) {
                    $usesGCM = true;
                }
                if (strpos($content, 'aes-256-cbc') !== false) {
                    $usesCBC = true;
                }
            }
        }
        
        if ($usesGCM) {
            $this->addPassed($category, 'Using AES-256-GCM (authenticated encryption)');
        }
        
        if ($usesCBC) {
            $this->addMedium($category, 'Legacy AES-256-CBC Usage Detected',
                'CBC mode lacks authentication, vulnerable to padding oracle',
                'Migrate to AES-256-GCM for new data');
        }
        
        if (!$usesGCM && !$usesCBC) {
            $this->addLow($category, 'Could not determine encryption algorithm',
                'Manual verification needed');
        }
    }
    
    /**
     * Check for hardcoded encryption keys
     */
    private function checkHardcodedKeys($category) {
        $configFile = __DIR__ . '/config/config.php';
        if (file_exists($configFile)) {
            $content = file_get_contents($configFile);
            
            // Look for suspicious patterns
            $patterns = [
                '/define\s*\(\s*[\'"].*KEY.*[\'"]\s*,\s*[\'"][^\'"]{8,}[\'"]/',
                '/\$.*[Kk]ey.*=\s*[\'"][^\'"]{8,}[\'"]/',
                '/CHANGE_THIS/',
                '/secret.*=.*[\'"][^\'"]{8,}[\'"]/'
            ];
            
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $content)) {
                    $this->addHigh($category, 'Possible Hardcoded Key in config.php',
                        'Keys should be in environment variables, not code',
                        'Move keys to .env file and use getenv()');
                    break;
                }
            }
        }
    }
    
    /**
     * Check key rotation policy
     */
    private function checkKeyRotation($category) {
        // Check if rotation script exists
        if (file_exists(__DIR__ . '/rotate-keys-90days.php')) {
            $this->addPassed($category, 'Key rotation script exists');
        } else {
            $this->addMedium($category, 'No Key Rotation Script',
                'Keys should be rotated periodically',
                'Implement key rotation procedure');
        }
        
        // Check last rotation time from database or log
        try {
            $stmt = $this->db->query("
                SELECT MAX(created_at) as last_rotation 
                FROM district_key_history 
                WHERE action = 'rotation'
            ");
            $result = $stmt->fetch();
            
            if ($result && $result['last_rotation']) {
                $daysSince = (time() - strtotime($result['last_rotation'])) / 86400;
                
                if ($daysSince > 90) {
                    $this->addMedium($category, 'Keys Not Rotated Recently',
                        "Last rotation: $daysSince days ago",
                        'Rotate encryption keys every 90 days');
                } else {
                    $this->addPassed($category, "Keys rotated $daysSince days ago");
                }
            }
        } catch (Exception $e) {
            // Table might not exist
        }
    }
    
    /**
     * Audit authentication mechanisms
     */
    private function auditAuthentication() {
        $category = 'Authentication & Authorization';
        
        // Check password hashing algorithm
        try {
            $stmt = $this->db->query("SELECT password_hash FROM users LIMIT 1");
            $user = $stmt->fetch();
            
            if ($user && isset($user['password_hash'])) {
                $hash = $user['password_hash'];
                
                // Check if using modern hashing
                if (strpos($hash, '$argon2id$') === 0) {
                    $this->addPassed($category, 'Using Argon2id password hashing');
                } elseif (strpos($hash, '$2y$') === 0) {
                    $this->addMedium($category, 'Using Bcrypt Password Hashing',
                        'Argon2id is more secure',
                        'Migrate to Argon2id on next password change');
                } else {
                    $this->addCritical($category, 'Weak Password Hashing',
                        'Passwords vulnerable to rainbow table attacks',
                        'Rehash all passwords with Argon2id');
                }
            }
        } catch (Exception $e) {
            $this->addMedium($category, 'Could not verify password hashing',
                'Database error: ' . $e->getMessage(),
                'Check users table structure');
        }
        
        // Check login attempt limiting
        try {
            $stmt = $this->db->query("SHOW TABLES LIKE 'login_attempts'");
            if ($stmt->rowCount() > 0) {
                $this->addPassed($category, 'Login attempt tracking enabled');
            } else {
                $this->addHigh($category, 'No Login Attempt Tracking',
                    'Vulnerable to brute force attacks',
                    'Implement rate limiting');
            }
        } catch (Exception $e) {
            $this->addMedium($category, 'Could not verify login attempt tracking');
        }
        
        // Check MFA availability
        if (file_exists(__DIR__ . '/lorcapp/includes/mfa.php')) {
            $this->addPassed($category, 'Multi-factor authentication available');
        } else {
            $this->addMedium($category, 'No Multi-Factor Authentication',
                'Accounts vulnerable to credential theft',
                'Implement TOTP or WebAuthn MFA');
        }
        
        // Check for default/weak credentials
        $this->checkDefaultCredentials($category);
    }
    
    /**
     * Check for default or weak credentials
     */
    private function checkDefaultCredentials($category) {
        $weakPasswords = ['password', 'admin', '123456', 'test', 'demo'];
        
        try {
            $stmt = $this->db->query("SELECT username, password_hash FROM users");
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($users as $user) {
                foreach ($weakPasswords as $weak) {
                    if (password_verify($weak, $user['password_hash'])) {
                        $this->addCritical($category, 'Weak/Default Password Detected',
                            "User '{$user['username']}' has a weak password",
                            'Force password reset for all weak passwords');
                        break;
                    }
                }
            }
        } catch (Exception $e) {
            // Skip if users table has issues
            $this->addLow($category, 'Could not check for weak passwords',
                'Database error',
                'Verify users table structure');
        }
    }
    
    /**
     * Audit session security
     */
    private function auditSessionSecurity() {
        $category = 'Session Security';
        
        // Check session timeout
        if (defined('SESSION_TIMEOUT')) {
            if (SESSION_TIMEOUT > 3600) {
                $this->addMedium($category, 'Long Session Timeout',
                    'Sessions remain active for ' . (SESSION_TIMEOUT/60) . ' minutes',
                    'Reduce to 30 minutes for sensitive operations');
            } else {
                $this->addPassed($category, 'Appropriate session timeout configured');
            }
        } else {
            $this->addHigh($category, 'No Session Timeout Defined',
                'Sessions may remain active indefinitely',
                'Define SESSION_TIMEOUT constant');
        }
        
        // Check session regeneration
        $securityFile = __DIR__ . '/includes/security.php';
        if (file_exists($securityFile)) {
            $content = file_get_contents($securityFile);
            if (strpos($content, 'session_regenerate_id') !== false) {
                $this->addPassed($category, 'Session ID regeneration implemented');
            } else {
                $this->addHigh($category, 'No Session Regeneration',
                    'Vulnerable to session fixation attacks',
                    'Regenerate session ID on login');
            }
        }
        
        // Check session validation
        if (file_exists($securityFile)) {
            $content = file_get_contents($securityFile);
            if (strpos($content, 'validateSession') !== false) {
                $this->addPassed($category, 'Session validation implemented');
            } else {
                $this->addMedium($category, 'No Session Validation',
                    'Cannot detect session hijacking',
                    'Validate IP address and user agent');
            }
        }
    }
    
    /**
     * Audit database security
     */
    private function auditDatabase() {
        $category = 'Database Security';
        
        // Check database connection encryption
        $stmt = $this->db->query("SHOW STATUS LIKE 'Ssl_cipher'");
        $result = $stmt->fetch();
        
        if ($result && !empty($result['Value'])) {
            $this->addPassed($category, 'Database connection encrypted: ' . $result['Value']);
        } else {
            if (APP_ENV === 'production') {
                $this->addHigh($category, 'Database Connection Not Encrypted',
                    'Data transmitted to database in plain text',
                    'Enable SSL/TLS for database connections');
            }
        }
        
        // Check prepared statements usage
        $phpFiles = glob(__DIR__ . '/{*.php,api/*.php,admin/*.php}', GLOB_BRACE);
        $unsafeQueries = 0;
        
        foreach (array_slice($phpFiles, 0, 50) as $file) {
            $content = file_get_contents($file);
            // Look for unsafe query patterns
            if (preg_match('/query\s*\(\s*["\'].*\$/', $content)) {
                $unsafeQueries++;
            }
        }
        
        if ($unsafeQueries > 0) {
            $this->addHigh($category, 'Potential SQL Injection Vulnerabilities',
                "Found $unsafeQueries files with unsafe query patterns",
                'Use prepared statements for all database queries');
        } else {
            $this->addPassed($category, 'Prepared statements used for queries');
        }
        
        // Check database user privileges
        $stmt = $this->db->query("SELECT USER(), DATABASE()");
        $dbInfo = $stmt->fetch();
        
        if ($dbInfo) {
            $this->addPassed($category, 'Database user: ' . $dbInfo['USER()']);
        }
    }
    
    /**
     * Audit file permissions
     */
    private function auditFilePermissions() {
        $category = 'File Permissions';
        
        $criticalFiles = [
            '.env' => 0600,
            'config/config.php' => 0640,
            'config/database.php' => 0640,
        ];
        
        foreach ($criticalFiles as $file => $recommendedPerm) {
            $fullPath = __DIR__ . '/' . $file;
            if (file_exists($fullPath)) {
                $perms = fileperms($fullPath);
                $octal = substr(sprintf('%o', $perms), -4);
                
                if ($perms & 0x0004 || $perms & 0x0002) {
                    $this->addHigh($category, "File World-Readable: $file",
                        "Permissions: $octal - File readable by all users",
                        sprintf("chmod %o $file", $recommendedPerm));
                } else {
                    $this->addPassed($category, "$file has restricted permissions");
                }
            }
        }
        
        // Check upload directory
        $uploadsDir = __DIR__ . '/uploads';
        if (is_dir($uploadsDir)) {
            if (is_writable($uploadsDir)) {
                $this->addPassed($category, 'Uploads directory is writable');
            }
            
            // Check for .htaccess protection
            if (!file_exists($uploadsDir . '/.htaccess')) {
                $this->addMedium($category, 'Uploads Directory Not Protected',
                    'Uploaded files may be executable',
                    'Add .htaccess to prevent script execution');
            } else {
                $this->addPassed($category, 'Uploads directory protected');
            }
        }
    }
    
    /**
     * Audit input validation
     */
    private function auditInputValidation() {
        $category = 'Input Validation';
        
        // Check if security functions exist
        if (class_exists('Security')) {
            if (method_exists('Security', 'sanitizeInput')) {
                $this->addPassed($category, 'Input sanitization functions available');
            } else {
                $this->addHigh($category, 'No Input Sanitization',
                    'Vulnerable to XSS attacks',
                    'Implement input sanitization');
            }
            
            if (method_exists('Security', 'validateCSRFToken')) {
                $this->addPassed($category, 'CSRF protection functions available');
            }
        }
        
        // Check for XSS vulnerabilities
        $phpFiles = glob(__DIR__ . '/{*.php,api/*.php}', GLOB_BRACE);
        $unsafeEchos = 0;
        
        foreach (array_slice($phpFiles, 0, 30) as $file) {
            $content = file_get_contents($file);
            // Look for unescaped output
            if (preg_match('/echo\s+\$_(GET|POST|REQUEST)/', $content)) {
                $unsafeEchos++;
            }
        }
        
        if ($unsafeEchos > 0) {
            $this->addHigh($category, 'Potential XSS Vulnerabilities',
                "Found $unsafeEchos files with unsafe output",
                'Always escape output with htmlspecialchars()');
        }
    }
    
    /**
     * Audit HTTPS/SSL configuration
     */
    private function auditHTTPS() {
        $category = 'HTTPS/SSL Configuration';
        
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $this->addPassed($category, 'HTTPS enabled');
            
            // Check HSTS header
            if (function_exists('headers_list')) {
                $headers = headers_list();
                $hasHSTS = false;
                foreach ($headers as $header) {
                    if (stripos($header, 'Strict-Transport-Security') !== false) {
                        $hasHSTS = true;
                        break;
                    }
                }
                
                if ($hasHSTS) {
                    $this->addPassed($category, 'HSTS header configured');
                } else {
                    $this->addMedium($category, 'HSTS Header Not Set',
                        'Browsers may still connect via HTTP',
                        'Add Strict-Transport-Security header');
                }
            }
        } else {
            if (APP_ENV === 'production') {
                $this->addCritical($category, 'HTTPS Not Enabled',
                    'All data transmitted in plain text',
                    'Enable HTTPS with valid SSL certificate');
            }
        }
    }
    
    /**
     * Audit dependencies and versions
     */
    private function auditDependencies() {
        $category = 'Dependencies & Versions';
        
        // Check PHP version
        $phpVersion = phpversion();
        if (version_compare($phpVersion, '7.4.0', '<')) {
            $this->addCritical($category, 'Unsupported PHP Version',
                "PHP $phpVersion is end-of-life",
                'Upgrade to PHP 8.0 or higher');
        } elseif (version_compare($phpVersion, '8.0.0', '<')) {
            $this->addMedium($category, 'PHP Version Near End-of-Life',
                "PHP $phpVersion security support ending soon",
                'Plan upgrade to PHP 8.0+');
        } else {
            $this->addPassed($category, "PHP version $phpVersion is supported");
        }
        
        // Check for composer dependencies
        if (file_exists(__DIR__ . '/composer.lock')) {
            $this->addPassed($category, 'Composer dependencies locked');
            
            // Could integrate with security advisory database
            $this->addLow($category, 'Dependency Security Scan',
                'Run: composer audit',
                'Check for known vulnerabilities');
        }
    }
    
    /**
     * Audit logging and monitoring
     */
    private function auditLogging() {
        $category = 'Logging & Monitoring';
        
        // Check if audit log exists
        try {
            $stmt = $this->db->query("SHOW TABLES LIKE 'audit_log'");
            if ($stmt->rowCount() > 0) {
                $this->addPassed($category, 'Audit logging table exists');
                
                // Check recent log entries
                $stmt = $this->db->query("SELECT COUNT(*) as count FROM audit_log WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
                $result = $stmt->fetch();
                
                if ($result['count'] > 0) {
                    $this->addPassed($category, 'Audit logging active');
                } else {
                    $this->addMedium($category, 'No Recent Audit Logs',
                        'Audit logging may not be working',
                        'Verify audit logging functionality');
                }
            } else {
                $this->addMedium($category, 'No Audit Log Table',
                    'Cannot track security events',
                    'Create audit_log table');
            }
        } catch (Exception $e) {
            // Skip if table doesn't exist
        }
        
        // Check for security event logging
        if (function_exists('secureLog')) {
            $this->addPassed($category, 'Security logging functions available');
        } else {
            $this->addMedium($category, 'No Security Logging Functions',
                'Cannot track security events',
                'Implement security event logging');
        }
    }
    
    /**
     * Audit CSRF protection
     */
    private function auditCSRFProtection() {
        $category = 'CSRF Protection';
        
        if (class_exists('Security') && method_exists('Security', 'generateCSRFToken')) {
            $this->addPassed($category, 'CSRF token generation available');
        } else {
            $this->addHigh($category, 'No CSRF Protection',
                'Vulnerable to cross-site request forgery',
                'Implement CSRF tokens');
        }
        
        // Check if forms include CSRF tokens
        $formFiles = glob(__DIR__ . '/{*.php,officers/*.php,requests/*.php}', GLOB_BRACE);
        $formsWithoutCSRF = 0;
        
        foreach (array_slice($formFiles, 0, 20) as $file) {
            $content = file_get_contents($file);
            if (preg_match('/<form/i', $content)) {
                if (!preg_match('/csrf.*token/i', $content)) {
                    $formsWithoutCSRF++;
                }
            }
        }
        
        if ($formsWithoutCSRF > 0) {
            $this->addMedium($category, 'Forms Without CSRF Protection',
                "Found $formsWithoutCSRF forms potentially missing CSRF tokens",
                'Add CSRF tokens to all forms');
        }
    }
    
    /**
     * Audit password policy
     */
    private function auditPasswordPolicy() {
        $category = 'Password Policy';
        
        // Check if password policy is defined
        $minLength = defined('MIN_PASSWORD_LENGTH') ? MIN_PASSWORD_LENGTH : 0;
        
        if ($minLength < 8) {
            $this->addMedium($category, 'Weak Password Length Requirement',
                "Minimum length: $minLength characters",
                'Require at least 12 characters');
        } elseif ($minLength >= 12) {
            $this->addPassed($category, 'Strong password length requirement');
        }
        
        // Check for password complexity requirements
        $securityFile = __DIR__ . '/includes/security.php';
        if (file_exists($securityFile)) {
            $content = file_get_contents($securityFile);
            if (strpos($content, 'uppercase') !== false || 
                strpos($content, 'lowercase') !== false ||
                strpos($content, 'number') !== false) {
                $this->addPassed($category, 'Password complexity rules implemented');
            } else {
                $this->addMedium($category, 'No Password Complexity Rules',
                    'Users can set weak passwords',
                    'Require mix of upper, lower, numbers, symbols');
            }
        }
        
        // Check for password expiration
        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM users LIKE 'password_changed_at'");
            if ($stmt->rowCount() > 0) {
                $this->addPassed($category, 'Password change tracking enabled');
            } else {
                $this->addLow($category, 'No Password Expiration',
                    'Passwords never expire',
                    'Consider 90-day password rotation');
            }
        } catch (Exception $e) {
            // Skip if column doesn't exist
        }
    }
    
    /**
     * Audit key management practices
     */
    private function auditKeyManagement() {
        $category = 'Key Management';
        
        // Check if .gitignore protects secrets
        if (file_exists(__DIR__ . '/.gitignore')) {
            $gitignore = file_get_contents(__DIR__ . '/.gitignore');
            if (strpos($gitignore, '.env') !== false) {
                $this->addPassed($category, '.env file excluded from git');
            } else {
                $this->addCritical($category, '.env Not in .gitignore',
                    'Secrets may be committed to repository',
                    'Add .env to .gitignore immediately');
            }
        } else {
            $this->addHigh($category, 'No .gitignore File',
                'Sensitive files may be committed',
                'Create .gitignore with sensitive files');
        }
        
        // Check if key backup exists
        $backupFiles = glob(__DIR__ . '/backup_keys_*.txt');
        if (count($backupFiles) > 0) {
            $this->addPassed($category, 'Encryption key backups exist');
            
            // Check if backups are protected
            foreach ($backupFiles as $backup) {
                $perms = fileperms($backup);
                if ($perms & 0x0004) {
                    $this->addHigh($category, 'Key Backup World-Readable',
                        basename($backup) . ' readable by all users',
                        'chmod 600 on backup files');
                }
            }
        } else {
            $this->addMedium($category, 'No Key Backups Found',
                'Key loss could cause data loss',
                'Implement secure key backup procedure');
        }
        
        // Check key storage location
        if (class_exists('InfisicalKeyManager')) {
            $this->addPassed($category, 'Using external key management (Infisical)');
        } else {
            $this->addMedium($category, 'Keys Stored in Database',
                'Database compromise exposes all keys',
                'Consider external key management service');
        }
    }
    
    /**
     * Add critical issue
     */
    private function addCritical($category, $issue, $risk, $remediation) {
        $this->criticalIssues[] = compact('category', 'issue', 'risk', 'remediation');
    }
    
    /**
     * Add high priority issue
     */
    private function addHigh($category, $issue, $risk, $remediation) {
        $this->highIssues[] = compact('category', 'issue', 'risk', 'remediation');
    }
    
    /**
     * Add medium priority issue
     */
    private function addMedium($category, $issue, $risk, $remediation) {
        $this->mediumIssues[] = compact('category', 'issue', 'risk', 'remediation');
    }
    
    /**
     * Add low priority issue
     */
    private function addLow($category, $issue, $risk, $remediation) {
        $this->lowIssues[] = compact('category', 'issue', 'risk', 'remediation');
    }
    
    /**
     * Add passed check
     */
    private function addPassed($category, $description) {
        $this->passedChecks[] = compact('category', 'description');
    }
    
    /**
     * Calculate security score (0-100)
     */
    private function calculateSecurityScore() {
        $total = count($this->criticalIssues) + count($this->highIssues) + 
                 count($this->mediumIssues) + count($this->lowIssues) + 
                 count($this->passedChecks);
        
        if ($total === 0) return 0;
        
        // Weight: Critical=-10, High=-5, Medium=-2, Low=-1, Pass=+1
        $weightedScore = (
            (count($this->passedChecks) * 1) - 
            (count($this->criticalIssues) * 10) - 
            (count($this->highIssues) * 5) - 
            (count($this->mediumIssues) * 2) - 
            (count($this->lowIssues) * 1)
        );
        
        $maxScore = count($this->passedChecks) * 1;
        
        if ($maxScore === 0) return 0;
        
        $score = ($weightedScore / $maxScore) * 100;
        return max(0, min(100, round($score, 2)));
    }
    
    /**
     * Check if file is web-accessible
     */
    private function checkWebAccessible($path) {
        // This is a simplified check - would need actual HTTP request in production
        $fullPath = __DIR__ . $path;
        if (!file_exists($fullPath)) {
            return false;
        }
        
        // Check if .htaccess blocks it
        $dir = dirname($fullPath);
        $htaccess = $dir . '/.htaccess';
        
        if (file_exists($htaccess)) {
            $content = file_get_contents($htaccess);
            if (strpos($content, 'Deny from all') !== false ||
                strpos($content, 'Require all denied') !== false) {
                return false;
            }
        }
        
        // Assume accessible if no .htaccess protection
        return true;
    }
    
    /**
     * Export results as JSON
     */
    public function exportJSON() {
        return json_encode($this->results, JSON_PRETTY_PRINT);
    }
    
    /**
     * Export results as HTML report
     */
    public function exportHTML() {
        ob_start();
        include __DIR__ . '/includes/security-audit-template.php';
        return ob_get_clean();
    }
}

// Run audit if accessed directly
if (php_sapi_name() === 'cli' || !empty($_GET['run_audit'])) {
    $audit = new SecurityAudit();
    $results = $audit->runFullAudit();
    
    // Output format
    $format = $_GET['format'] ?? 'html';
    
    if ($format === 'json') {
        header('Content-Type: application/json');
        echo $audit->exportJSON();
    } else {
        // HTML output
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Security Audit Report - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .security-score {
            font-size: 3rem;
            font-weight: bold;
        }
        .score-excellent { color: #28a745; }
        .score-good { color: #17a2b8; }
        .score-fair { color: #ffc107; }
        .score-poor { color: #dc3545; }
        .issue-card {
            border-left: 4px solid;
            margin-bottom: 1rem;
        }
        .critical { border-left-color: #dc3545; }
        .high { border-left-color: #fd7e14; }
        .medium { border-left-color: #ffc107; }
        .low { border-left-color: #17a2b8; }
        .passed { border-left-color: #28a745; }
        .category-badge {
            font-size: 0.8rem;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <div class="row">
            <div class="col-12">
                <h1 class="mb-4">
                    <i class="fas fa-shield-alt"></i> Security Audit Report
                </h1>
                
                <!-- Audit Info -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <strong>Audit Version:</strong><br>
                                <?php echo $results['audit_info']['version']; ?>
                            </div>
                            <div class="col-md-3">
                                <strong>Timestamp:</strong><br>
                                <?php echo $results['audit_info']['timestamp']; ?>
                            </div>
                            <div class="col-md-3">
                                <strong>Auditor:</strong><br>
                                <?php echo htmlspecialchars($results['audit_info']['auditor']); ?>
                            </div>
                            <div class="col-md-3">
                                <strong>Environment:</strong><br>
                                <span class="badge bg-<?php echo $results['audit_info']['environment'] === 'production' ? 'danger' : 'info'; ?>">
                                    <?php echo strtoupper($results['audit_info']['environment']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Security Score -->
                <div class="card mb-4">
                    <div class="card-body text-center">
                        <h2>Security Score</h2>
                        <div class="security-score <?php 
                            $score = $results['security_score'];
                            if ($score >= 80) echo 'score-excellent';
                            elseif ($score >= 60) echo 'score-good';
                            elseif ($score >= 40) echo 'score-fair';
                            else echo 'score-poor';
                        ?>">
                            <?php echo $results['security_score']; ?>/100
                        </div>
                        <p class="text-muted mt-2">
                            <?php
                            if ($score >= 80) echo 'Excellent - System is well secured';
                            elseif ($score >= 60) echo 'Good - Some improvements needed';
                            elseif ($score >= 40) echo 'Fair - Significant issues to address';
                            else echo 'Poor - Critical security issues present';
                            ?>
                        </p>
                    </div>
                </div>
                
                <!-- Summary -->
                <div class="row mb-4">
                    <div class="col-md-2">
                        <div class="card text-white bg-danger">
                            <div class="card-body text-center">
                                <h3><?php echo $results['summary']['critical']; ?></h3>
                                <small>CRITICAL</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card text-white bg-warning">
                            <div class="card-body text-center">
                                <h3><?php echo $results['summary']['high']; ?></h3>
                                <small>HIGH</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card text-white bg-info">
                            <div class="card-body text-center">
                                <h3><?php echo $results['summary']['medium']; ?></h3>
                                <small>MEDIUM</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card text-white bg-secondary">
                            <div class="card-body text-center">
                                <h3><?php echo $results['summary']['low']; ?></h3>
                                <small>LOW</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card text-white bg-success">
                            <div class="card-body text-center">
                                <h3><?php echo $results['summary']['passed']; ?></h3>
                                <small>PASSED</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card border-primary">
                            <div class="card-body text-center">
                                <h3><?php echo $results['summary']['total_checks']; ?></h3>
                                <small>TOTAL</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Issues -->
                <?php if (count($results['issues']['critical']) > 0): ?>
                <div class="card mb-4">
                    <div class="card-header bg-danger text-white">
                        <h3><i class="fas fa-exclamation-triangle"></i> Critical Issues (Must Fix Immediately)</h3>
                    </div>
                    <div class="card-body">
                        <?php foreach ($results['issues']['critical'] as $issue): ?>
                        <div class="issue-card critical card">
                            <div class="card-body">
                                <span class="badge bg-secondary category-badge"><?php echo htmlspecialchars($issue['category']); ?></span>
                                <h5 class="mt-2"><?php echo htmlspecialchars($issue['issue']); ?></h5>
                                <p><strong>Risk:</strong> <?php echo htmlspecialchars($issue['risk']); ?></p>
                                <p><strong>Remediation:</strong> <?php echo htmlspecialchars($issue['remediation']); ?></p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (count($results['issues']['high']) > 0): ?>
                <div class="card mb-4">
                    <div class="card-header bg-warning text-dark">
                        <h3><i class="fas fa-exclamation-circle"></i> High Priority Issues</h3>
                    </div>
                    <div class="card-body">
                        <?php foreach ($results['issues']['high'] as $issue): ?>
                        <div class="issue-card high card">
                            <div class="card-body">
                                <span class="badge bg-secondary category-badge"><?php echo htmlspecialchars($issue['category']); ?></span>
                                <h5 class="mt-2"><?php echo htmlspecialchars($issue['issue']); ?></h5>
                                <p><strong>Risk:</strong> <?php echo htmlspecialchars($issue['risk']); ?></p>
                                <p><strong>Remediation:</strong> <?php echo htmlspecialchars($issue['remediation']); ?></p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (count($results['issues']['medium']) > 0): ?>
                <div class="card mb-4">
                    <div class="card-header bg-info text-white">
                        <h3><i class="fas fa-info-circle"></i> Medium Priority Issues</h3>
                    </div>
                    <div class="card-body">
                        <?php foreach ($results['issues']['medium'] as $issue): ?>
                        <div class="issue-card medium card">
                            <div class="card-body">
                                <span class="badge bg-secondary category-badge"><?php echo htmlspecialchars($issue['category']); ?></span>
                                <h5 class="mt-2"><?php echo htmlspecialchars($issue['issue']); ?></h5>
                                <p><strong>Risk:</strong> <?php echo htmlspecialchars($issue['risk']); ?></p>
                                <p><strong>Remediation:</strong> <?php echo htmlspecialchars($issue['remediation']); ?></p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (count($results['issues']['low']) > 0): ?>
                <div class="card mb-4">
                    <div class="card-header bg-secondary text-white">
                        <h3><i class="fas fa-minus-circle"></i> Low Priority Issues</h3>
                    </div>
                    <div class="card-body">
                        <?php foreach ($results['issues']['low'] as $issue): ?>
                        <div class="issue-card low card">
                            <div class="card-body">
                                <span class="badge bg-secondary category-badge"><?php echo htmlspecialchars($issue['category']); ?></span>
                                <h5 class="mt-2"><?php echo htmlspecialchars($issue['issue']); ?></h5>
                                <p><strong>Risk:</strong> <?php echo htmlspecialchars($issue['risk']); ?></p>
                                <p><strong>Remediation:</strong> <?php echo htmlspecialchars($issue['remediation']); ?></p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Passed Checks -->
                <div class="card mb-4">
                    <div class="card-header bg-success text-white">
                        <h3><i class="fas fa-check-circle"></i> Passed Security Checks</h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($results['passed'] as $check): ?>
                            <div class="col-md-6 mb-2">
                                <div class="issue-card passed card">
                                    <div class="card-body py-2">
                                        <span class="badge bg-secondary category-badge"><?php echo htmlspecialchars($check['category']); ?></span>
                                        <span class="ms-2"><?php echo htmlspecialchars($check['description']); ?></span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Actions -->
                <div class="card">
                    <div class="card-body">
                        <h4>Next Steps</h4>
                        <ul>
                            <li>Address all <span class="badge bg-danger">CRITICAL</span> issues immediately</li>
                            <li>Fix <span class="badge bg-warning">HIGH</span> priority issues before production deployment</li>
                            <li>Schedule remediation for <span class="badge bg-info">MEDIUM</span> and <span class="badge bg-secondary">LOW</span> issues</li>
                            <li>Re-run audit after fixes: <code>security-audit.php?run_audit=1</code></li>
                            <li>Export JSON report: <code>security-audit.php?run_audit=1&format=json</code></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
        <?php
    }
}
?>
