<?php
/**
 * Security Functions
 */

class Security {
    
    /**
     * Generate CSRF Token (action-specific with expiration)
     * Also maintains legacy single token for backward compatibility
     */
    public static function generateCSRFToken($action = 'default') {
        // Check if session is started
        if (session_status() !== PHP_SESSION_ACTIVE) {
            error_log("WARNING: Attempting to generate CSRF token but session is not active!");
            session_start();
        }
        
        // Initialize token storage if not exists
        if (!isset($_SESSION['csrf_tokens'])) {
            $_SESSION['csrf_tokens'] = [];
        }
        
        // Clean old tokens (older than 1 hour)
        foreach ($_SESSION['csrf_tokens'] as $act => $data) {
            if (isset($data['created']) && time() - $data['created'] > 3600) {
                unset($_SESSION['csrf_tokens'][$act]);
            }
        }
        
        // If token already exists for this action and is not expired, return it
        if (isset($_SESSION['csrf_tokens'][$action])) {
            $existing = $_SESSION['csrf_tokens'][$action];
            if (isset($existing['created']) && time() - $existing['created'] < 3600) {
                return $existing['token'];
            }
        }
        
        // Generate unique token for this action
        $token = bin2hex(random_bytes(32));
        
        // Store with timestamp
        $_SESSION['csrf_tokens'][$action] = [
            'token' => $token,
            'created' => time()
        ];
        
        // Also update legacy single token for backward compatibility
        if ($action === 'default') {
            $_SESSION[CSRF_TOKEN_NAME] = $token;
        }
        
        return $token;
    }
    
    /**
     * Validate CSRF Token (supports one-time use and expiration)
     * Backward compatible with legacy single-token system
     */
    public static function validateCSRFToken($token, $action = 'default', $oneTime = false) {
        // Check if session is started
        if (session_status() !== PHP_SESSION_ACTIVE) {
            error_log("WARNING: Attempting to validate CSRF token but session is not active!");
            return false;
        }
        
        // Check if token is empty
        if (empty($token)) {
            return false;
        }
        
        // Try new action-specific token system first
        if (isset($_SESSION['csrf_tokens'][$action])) {
            $stored = $_SESSION['csrf_tokens'][$action];
            
            // Check expiration (1 hour)
            if (!isset($stored['created']) || time() - $stored['created'] > 3600) {
                unset($_SESSION['csrf_tokens'][$action]);
                return false;
            }
            
            // Validate token using timing-safe comparison
            if (isset($stored['token']) && hash_equals($stored['token'], $token)) {
                // One-time use: delete after validation
                if ($oneTime) {
                    unset($_SESSION['csrf_tokens'][$action]);
                }
                return true;
            }
        }
        
        // Fallback to legacy single token for backward compatibility
        if (isset($_SESSION[CSRF_TOKEN_NAME]) && hash_equals($_SESSION[CSRF_TOKEN_NAME], $token)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Sanitize Input with length validation
     */
    public static function sanitizeInput($data, $maxLength = 1000) {
        if (is_array($data)) {
            return array_map(function($item) use ($maxLength) {
                return self::sanitizeInput($item, $maxLength);
            }, $data);
        }
        // Limit length to prevent DoS
        $data = mb_substr(trim($data), 0, $maxLength);
        return htmlspecialchars(strip_tags($data), ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Validate Email
     */
    public static function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL);
    }
    
    /**
     * Hash Password
     */
    public static function hashPassword($password) {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3
        ]);
    }
    
    /**
     * Verify Password
     */
    public static function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
    
    /**
     * Check if user is logged in
     */
    public static function isLoggedIn() {
        return isset($_SESSION['user_id']) && isset($_SESSION['user_role']);
    }
    
    /**
     * Check if session is valid
     */
    public static function validateSession() {
        if (!self::isLoggedIn()) {
            return false;
        }
        
        // RELAXED IP validation - only check if subnet changed drastically
        // This allows for normal IP changes (mobile, VPN, ISP rotation) while still detecting major hijacking
        if (isset($_SESSION['ip_address'])) {
            $sessionIP = $_SESSION['ip_address'];
            $currentIP = $_SERVER['REMOTE_ADDR'];
            
            // Only invalidate if IPs are completely different (not just last octet)
            // Compare first 3 octets for IPv4, or first 4 segments for IPv6
            $sessionPrefix = implode('.', array_slice(explode('.', $sessionIP), 0, 3));
            $currentPrefix = implode('.', array_slice(explode('.', $currentIP), 0, 3));
            
            if ($sessionPrefix !== $currentPrefix) {
                // Still allow but log for monitoring
                error_log("Session IP subnet changed. From: {$sessionIP}, To: {$currentIP}, User: {$_SESSION['user_id']}");
                // Update to new IP instead of destroying session
                $_SESSION['ip_address'] = $currentIP;
            }
        }
        
        // REMOVED strict user agent check - too prone to false positives from browser updates
        // Only store for audit purposes, don't validate
        $currentUA = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (!isset($_SESSION['user_agent'])) {
            $_SESSION['user_agent'] = $currentUA;
        }
        
        // Check session timeout - with auto-extension
        if (isset($_SESSION['last_activity'])) {
            $inactiveTime = time() - $_SESSION['last_activity'];
            
            // If session has been inactive for more than timeout, destroy it
            if ($inactiveTime > SESSION_TIMEOUT) {
                error_log("Session timeout for user: {$_SESSION['user_id']}");
                session_unset();
                session_destroy();
                return false;
            }
            
            // Auto-extend session every 5 minutes of activity
            if ($inactiveTime > 300) { // 5 minutes
                $_SESSION['last_activity'] = time();
            }
        } else {
            $_SESSION['last_activity'] = time();
        }
        
        return true;
    }
    
    /**
     * Require login
     */
    public static function requireLogin() {
        if (!self::validateSession()) {
            header('Location: ' . BASE_URL . '/login.php');
            exit();
        }
    }
    
    /**
     * Check user role
     */
    public static function hasRole($requiredRole) {
        if (!self::isLoggedIn()) {
            return false;
        }
        
        $userRole = $_SESSION['user_role'];
        
        // Admin has access to everything
        if ($userRole === 'admin') {
            return true;
        }
        
        // Check specific role
        return $userRole === $requiredRole;
    }
    
    /**
     * Require specific role
     */
    public static function requireRole($requiredRole) {
        if (!self::hasRole($requiredRole) && $_SESSION['user_role'] !== 'admin') {
            http_response_code(403);
            die('Access denied. Insufficient permissions.');
        }
    }
    
    /**
     * Check login attempts (database-backed for production)
     */
    public static function checkLoginAttempts($username, $ip_address = null) {
        if ($ip_address === null) {
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        }
        
        try {
            $db = Database::getInstance()->getConnection();
            
            // Check username-based attempts (last 15 minutes)
            $stmt = $db->prepare("
                SELECT COUNT(*) as attempts, MAX(attempted_at) as last_attempt
                FROM login_attempts
                WHERE username = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
            ");
            $stmt->execute([$username]);
            $result = $stmt->fetch();
            
            if ($result && $result['attempts'] >= MAX_LOGIN_ATTEMPTS) {
                $lastAttempt = strtotime($result['last_attempt']);
                $minutesRemaining = 15 - floor((time() - $lastAttempt) / 60);
                return [
                    'allowed' => false,
                    'message' => "Account temporarily locked. Please try again in {$minutesRemaining} minute(s)."
                ];
            }
            
            // Check IP-based attempts (last 5 minutes) to prevent distributed attacks
            $stmt = $db->prepare("
                SELECT COUNT(*) as attempts
                FROM login_attempts
                WHERE ip_address = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            ");
            $stmt->execute([$ip_address]);
            $ipResult = $stmt->fetch();
            
            if ($ipResult && $ipResult['attempts'] >= 10) {
                return [
                    'allowed' => false,
                    'message' => "Too many login attempts from this IP address. Please try again later."
                ];
            }
            
            return ['allowed' => true];
            
        } catch (Exception $e) {
            // Fallback to session-based if database fails
            secureLog("Database rate limiting failed, falling back to session-based", ['error' => $e->getMessage()], 'WARNING');
            return self::checkLoginAttemptsSession($username);
        }
    }
    
    /**
     * Session-based login attempts (fallback)
     */
    private static function checkLoginAttemptsSession($username) {
        $key = 'login_attempts_' . md5($username);
        
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = [
                'attempts' => 0,
                'locked_until' => null
            ];
        }
        
        $attempts = $_SESSION[$key];
        
        // Check if account is locked
        if ($attempts['locked_until'] && time() < $attempts['locked_until']) {
            $remaining = ceil(($attempts['locked_until'] - time()) / 60);
            return [
                'allowed' => false,
                'message' => "Account locked. Please try again in {$remaining} minute(s)."
            ];
        }
        
        // Reset if lockout period has passed
        if ($attempts['locked_until'] && time() >= $attempts['locked_until']) {
            $_SESSION[$key] = [
                'attempts' => 0,
                'locked_until' => null
            ];
        }
        
        return ['allowed' => true];
    }
    
    /**
     * Record failed login attempt (database-backed)
     */
    public static function recordFailedLogin($username, $ip_address = null) {
        if ($ip_address === null) {
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        }
        
        try {
            $db = Database::getInstance()->getConnection();
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            
            $stmt = $db->prepare("
                INSERT INTO login_attempts (username, ip_address, attempted_at, user_agent)
                VALUES (?, ?, NOW(), ?)
            ");
            $stmt->execute([$username, $ip_address, $userAgent]);
            
            secureLog("Failed login attempt", [
                'username' => $username,
                'ip_address' => $ip_address
            ], 'WARNING');
            
        } catch (Exception $e) {
            // Fallback to session-based
            secureLog("Database record failed login failed, falling back to session", ['error' => $e->getMessage()], 'WARNING');
            self::recordFailedLoginSession($username);
        }
    }
    
    /**
     * Session-based failed login recording (fallback)
     */
    private static function recordFailedLoginSession($username) {
        $key = 'login_attempts_' . md5($username);
        
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = [
                'attempts' => 0,
                'locked_until' => null
            ];
        }
        
        $_SESSION[$key]['attempts']++;
        
        if ($_SESSION[$key]['attempts'] >= MAX_LOGIN_ATTEMPTS) {
            $_SESSION[$key]['locked_until'] = time() + LOGIN_LOCKOUT_TIME;
        }
    }
    
    /**
     * Reset login attempts (database-backed)
     */
    public static function resetLoginAttempts($username) {
        try {
            $db = Database::getInstance()->getConnection();
            
            // Delete recent failed attempts for this username
            $stmt = $db->prepare("
                DELETE FROM login_attempts 
                WHERE username = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $stmt->execute([$username]);
            
        } catch (Exception $e) {
            secureLog("Database reset login attempts failed", ['error' => $e->getMessage()], 'WARNING');
        }
        
        // Also clear session-based
        $key = 'login_attempts_' . md5($username);
        unset($_SESSION[$key]);
    }
    
    /**
     * Generate random token
     */
    public static function generateToken($length = 32) {
        return bin2hex(random_bytes($length));
    }
    
    /**
     * Validate password strength
     */
    public static function validatePasswordStrength($password) {
        $errors = [];
        
        if (strlen($password) < 12) {
            $errors[] = 'Password must be at least 12 characters long';
        }
        
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter';
        }
        
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter';
        }
        
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number';
        }
        
        if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
            $errors[] = 'Password must contain at least one special character';
        }
        
        // Check against common passwords
        $commonPasswords = ['password', '12345678', 'admin123', 'Admin@123', 'password123', 'P@ssw0rd'];
        if (in_array(strtolower($password), array_map('strtolower', $commonPasswords))) {
            $errors[] = 'Password is too common';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Prevent XSS
     */
    public static function escape($data) {
        if (is_array($data)) {
            return array_map([self::class, 'escape'], $data);
        }
        if ($data === null) {
            return '';
        }
        return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Generate Remember Me Token
     * Creates a secure token stored in database and sets a cookie
     * Returns false on failure
     */
    public static function generateRememberMeToken($userId) {
        try {
            $db = Database::getInstance()->getConnection();
            
            // Generate random selector and validator
            $selector = bin2hex(random_bytes(16)); // Public identifier
            $validator = bin2hex(random_bytes(32)); // Secret
            
            // Hash the validator for storage
            $hashedValidator = password_hash($validator, PASSWORD_ARGON2ID);
            
            // Get user agent hash for additional security
            $userAgentHash = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            
            // Token expires in 90 days
            $expiresAt = date('Y-m-d H:i:s', time() + (90 * 24 * 60 * 60));
            
            // Clean up old tokens for this user (keep max 5 devices)
            $stmt = $db->prepare("
                DELETE FROM remember_me_tokens 
                WHERE user_id = ? 
                AND token_id NOT IN (
                    SELECT token_id FROM (
                        SELECT token_id FROM remember_me_tokens 
                        WHERE user_id = ?
                        ORDER BY created_at DESC 
                        LIMIT 5
                    ) AS recent
                )
            ");
            $stmt->execute([$userId, $userId]);
            
            // Insert new token
            $stmt = $db->prepare("
                INSERT INTO remember_me_tokens 
                (user_id, selector, hashed_validator, user_agent_hash, ip_address, expires_at)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $selector, $hashedValidator, $userAgentHash, $ipAddress, $expiresAt]);
            
            // Set cookie with selector:validator
            $cookieValue = $selector . ':' . $validator;
            $cookieExpires = time() + (90 * 24 * 60 * 60); // 90 days
            
            // Secure cookie settings
            setcookie(
                'remember_me',
                $cookieValue,
                [
                    'expires' => $cookieExpires,
                    'path' => '/',
                    'domain' => '',
                    'secure' => isset($_SERVER['HTTPS']),
                    'httponly' => true,
                    'samesite' => 'Strict'
                ]
            );
            
            secureLog("Remember me token created", ['user_id' => $userId], 'INFO');
            return true;
            
        } catch (Exception $e) {
            error_log("Failed to generate remember me token: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Validate Remember Me Token
     * Checks cookie and database, returns user_id on success, false on failure
     */
    public static function validateRememberMeToken() {
        if (!isset($_COOKIE['remember_me'])) {
            return false;
        }
        
        try {
            $db = Database::getInstance()->getConnection();
            
            // Parse cookie value
            $cookieParts = explode(':', $_COOKIE['remember_me'], 2);
            if (count($cookieParts) !== 2) {
                self::clearRememberMeToken();
                return false;
            }
            
            list($selector, $validator) = $cookieParts;
            
            // Find token in database
            $stmt = $db->prepare("
                SELECT token_id, user_id, hashed_validator, user_agent_hash, expires_at
                FROM remember_me_tokens
                WHERE selector = ? AND expires_at > NOW()
            ");
            $stmt->execute([$selector]);
            $token = $stmt->fetch();
            
            if (!$token) {
                self::clearRememberMeToken();
                return false;
            }
            
            // Verify validator
            if (!password_verify($validator, $token['hashed_validator'])) {
                // Token theft detected - delete all tokens for this user
                $stmt = $db->prepare("DELETE FROM remember_me_tokens WHERE user_id = ?");
                $stmt->execute([$token['user_id']]);
                
                secureLog("Remember me token theft detected", ['user_id' => $token['user_id']], 'CRITICAL');
                self::clearRememberMeToken();
                return false;
            }
            
            // Verify user agent hasn't changed significantly
            $currentUserAgentHash = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
            if ($currentUserAgentHash !== $token['user_agent_hash']) {
                secureLog("Remember me user agent mismatch", ['user_id' => $token['user_id']], 'WARNING');
                // Don't fail completely, just log - browsers can update
                // Could add more strict checking here if needed
            }
            
            // Update last used timestamp
            $stmt = $db->prepare("UPDATE remember_me_tokens SET last_used_at = NOW() WHERE token_id = ?");
            $stmt->execute([$token['token_id']]);
            
            secureLog("Remember me token validated", ['user_id' => $token['user_id']], 'INFO');
            return $token['user_id'];
            
        } catch (Exception $e) {
            error_log("Failed to validate remember me token: " . $e->getMessage());
            self::clearRememberMeToken();
            return false;
        }
    }
    
    /**
     * Clear Remember Me Token
     * Removes cookie and database entry
     */
    public static function clearRememberMeToken() {
        if (isset($_COOKIE['remember_me'])) {
            // Parse selector from cookie
            $cookieParts = explode(':', $_COOKIE['remember_me'], 2);
            if (count($cookieParts) === 2) {
                try {
                    $db = Database::getInstance()->getConnection();
                    $stmt = $db->prepare("DELETE FROM remember_me_tokens WHERE selector = ?");
                    $stmt->execute([$cookieParts[0]]);
                } catch (Exception $e) {
                    error_log("Failed to delete remember me token: " . $e->getMessage());
                }
            }
            
            // Clear cookie
            setcookie(
                'remember_me',
                '',
                [
                    'expires' => time() - 3600,
                    'path' => '/',
                    'domain' => '',
                    'secure' => isset($_SERVER['HTTPS']),
                    'httponly' => true,
                    'samesite' => 'Strict'
                ]
            );
        }
    }
    
    /**
     * Clear all Remember Me Tokens for a user
     * Use when password is changed or account security is compromised
     */
    public static function clearAllRememberMeTokens($userId) {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("DELETE FROM remember_me_tokens WHERE user_id = ?");
            $stmt->execute([$userId]);
            
            secureLog("All remember me tokens cleared", ['user_id' => $userId], 'INFO');
            return true;
        } catch (Exception $e) {
            error_log("Failed to clear all remember me tokens: " . $e->getMessage());
            return false;
        }
    }
}
