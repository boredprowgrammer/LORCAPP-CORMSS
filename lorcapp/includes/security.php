<?php
/**
 * LORCAPP
 * Security Functions
 * Handles CSRF protection, session security, and rate limiting
 */

// Prevent direct access
if (count(get_included_files()) == 1) exit("Direct access forbidden");

// Start secure session if not already started
function startSecureSession() {
    if (session_status() === PHP_SESSION_NONE) {
        // Detect if HTTPS is being used
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
                   || $_SERVER['SERVER_PORT'] == 443
                   || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        
        // Configure secure session settings
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_only_cookies', 1);
        ini_set('session.cookie_secure', $isHttps ? 1 : 0); // Automatically enable for HTTPS
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.use_strict_mode', 1);
        
        session_name('R201_SESSION');
        session_start();
        
        // Log warning if not using HTTPS in production
        if (!$isHttps && !in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1', 'localhost'])) {
            error_log("WARNING: Session started over insecure HTTP connection from " . $_SERVER['REMOTE_ADDR']);
        }
    }
    
    // Regenerate session ID periodically to prevent fixation attacks
    // But preserve CSRF token during regeneration
    if (!isset($_SESSION['created'])) {
        $_SESSION['created'] = time();
    } else if (time() - $_SESSION['created'] > 1800) {
        // Store CSRF token before regeneration
        $csrf_token = $_SESSION['csrf_token'] ?? null;
        $csrf_token_time = $_SESSION['csrf_token_time'] ?? null;
        
        // Regenerate session every 30 minutes
        session_regenerate_id(true);
        $_SESSION['created'] = time();
        
        // Restore CSRF token after regeneration
        if ($csrf_token) {
            $_SESSION['csrf_token'] = $csrf_token;
            $_SESSION['csrf_token_time'] = $csrf_token_time;
        }
    }
    
    // Set session timeout (use CSRF_TOKEN_LIFETIME as default session timeout if not separately configured)
    if (!defined('CSRF_TOKEN_LIFETIME')) {
        define('CSRF_TOKEN_LIFETIME', 7200);
    }

    if (!isset($_SESSION['last_activity'])) {
        $_SESSION['last_activity'] = time();
    } else if (time() - $_SESSION['last_activity'] > CSRF_TOKEN_LIFETIME) {
        // Last request was more than CSRF_TOKEN_LIFETIME ago
        session_unset();
        session_destroy();
        session_start();
    }
    $_SESSION['last_activity'] = time();
}

// Generate CSRF token
function generateCSRFToken() {
    // CSRF token lifetime in seconds (2 hours)
    if (!defined('CSRF_TOKEN_LIFETIME')) {
        define('CSRF_TOKEN_LIFETIME', 7200);
    }

    if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time']) || 
        (time() - $_SESSION['csrf_token_time']) > CSRF_TOKEN_LIFETIME) {
        // Generate new token (valid for CSRF_TOKEN_LIFETIME seconds)
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    return $_SESSION['csrf_token'];
}

// Validate CSRF token
function validateCSRFToken($token) {
    if (!isset($_SESSION['csrf_token']) || !isset($token) || empty($token)) {
        return false;
    }
    
    // Check if token has expired (CSRF_TOKEN_LIFETIME)
    if (!defined('CSRF_TOKEN_LIFETIME')) {
        define('CSRF_TOKEN_LIFETIME', 7200);
    }

    if (isset($_SESSION['csrf_token_time']) && (time() - $_SESSION['csrf_token_time']) > CSRF_TOKEN_LIFETIME) {
        error_log("CSRF Validation FAILED - Token expired");
        return false;
    }
    
    // Use hash_equals to prevent timing attacks
    $valid = hash_equals($_SESSION['csrf_token'], $token);
    error_log("CSRF Validation - Result: " . ($valid ? "VALID" : "INVALID"));
    return $valid;
}

// Generate a unique form token for each form instance
function generateFormToken() {
    $token = bin2hex(random_bytes(32));
    $_SESSION['form_tokens'][$token] = time();
    
    // Clean up old tokens (older than 1 hour)
    if (isset($_SESSION['form_tokens'])) {
        foreach ($_SESSION['form_tokens'] as $key => $timestamp) {
            if (time() - $timestamp > 3600) {
                unset($_SESSION['form_tokens'][$key]);
            }
        }
    }
    
    return $token;
}

// Validate form token (one-time use)
function validateFormToken($token) {
    if (!isset($token) || !isset($_SESSION['form_tokens'][$token])) {
        return false;
    }
    
    // Check if token is not expired (1 hour)
    if (time() - $_SESSION['form_tokens'][$token] > 3600) {
        unset($_SESSION['form_tokens'][$token]);
        return false;
    }
    
    // Remove token after validation (one-time use)
    unset($_SESSION['form_tokens'][$token]);
    return true;
}

// Rate limiting function
function checkRateLimit($action, $maxAttempts = 5, $timeWindow = 300) {
    $identifier = getRateLimitIdentifier();
    $key = $action . '_' . $identifier;
    
    if (!isset($_SESSION['rate_limit'])) {
        $_SESSION['rate_limit'] = [];
    }
    
    // Clean up old entries
    foreach ($_SESSION['rate_limit'] as $k => $data) {
        if (time() - $data['first_attempt'] > $timeWindow) {
            unset($_SESSION['rate_limit'][$k]);
        }
    }
    
    // Check current rate limit
    if (!isset($_SESSION['rate_limit'][$key])) {
        $_SESSION['rate_limit'][$key] = [
            'attempts' => 1,
            'first_attempt' => time()
        ];
        return true;
    }
    
    $rateData = $_SESSION['rate_limit'][$key];
    
    // Reset if outside time window
    if (time() - $rateData['first_attempt'] > $timeWindow) {
        $_SESSION['rate_limit'][$key] = [
            'attempts' => 1,
            'first_attempt' => time()
        ];
        return true;
    }
    
    // Check if limit exceeded
    if ($rateData['attempts'] >= $maxAttempts) {
        return false;
    }
    
    // Increment attempt counter
    $_SESSION['rate_limit'][$key]['attempts']++;
    return true;
}

// Get rate limit identifier (IP + User Agent)
function getRateLimitIdentifier() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    return hash('sha256', $ip . $userAgent);
}

// Check if IP is from Philippines (PH)
function checkGeoBlock() {
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? '';
    
    // Check if there are proxy headers (VPN/proxy in use)
    $hasProxyHeaders = isset($_SERVER['HTTP_X_FORWARDED_FOR']) || isset($_SERVER['HTTP_X_REAL_IP']);
    
    // Allow all localhost/loopback addresses when no proxy is detected
    // 127.0.0.0/8 (all 127.x.x.x), ::1 (IPv6 localhost)
    $isLocalhost = (strpos($clientIP, '127.') === 0) || ($clientIP === '::1') || ($clientIP === 'localhost');
    
    // Check if accessing from localhost only AND no proxy headers
    if ($isLocalhost && !$hasProxyHeaders) {
        error_log("Geo-blocking: Allowing localhost/loopback IP: {$clientIP} (no proxy detected)");
        return true;
    }
    
    // Check for IP behind proxy/load balancer/VPN
    $realIP = $clientIP;
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwardedIPs = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $realIP = trim($forwardedIPs[0]);
        error_log("Geo-blocking: Detected X-Forwarded-For, using IP: {$realIP} (original: {$clientIP})");
    } elseif (isset($_SERVER['HTTP_X_REAL_IP'])) {
        $realIP = $_SERVER['HTTP_X_REAL_IP'];
        error_log("Geo-blocking: Detected X-Real-IP, using IP: {$realIP} (original: {$clientIP})");
    }
    
    // IMPORTANT: If we have a valid public IP from headers (VPN/Proxy), use it!
    // Don't override it with getRealPublicIP() as that defeats VPN detection
    if (filter_var($realIP, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
        // We have a valid public IP, use it directly
        error_log("Geo-blocking: Using public IP from headers: {$realIP}");
    } else {
        // It's a private IP, need to detect actual public IP
        error_log("Geo-blocking: Private IP detected ({$realIP}), attempting to get public IP");
        
        // Try to get real public IP using external service
        $publicIP = getRealPublicIP();
        if ($publicIP !== null) {
            $realIP = $publicIP;
            error_log("Geo-blocking: Using detected public IP: {$realIP}");
        } else {
            // Can't determine public IP - for security, block access
            error_log("Geo-blocking: Cannot determine public IP from private address: {$realIP} - BLOCKING");
            logSecurityEvent('GEO_BLOCK', [
                'ip' => $realIP,
                'original_ip' => $clientIP,
                'country' => 'PRIVATE_IP',
                'url' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                'reason' => 'Cannot determine public IP from private address'
            ]);
            return false;
        }
    }
    
    // Get country code from IP
    $countryCode = getCountryFromIP($realIP);
    
    // Allow only Philippines (PH)
    if ($countryCode !== 'PH') {
        logSecurityEvent('GEO_BLOCK', [
            'ip' => $realIP,
            'original_ip' => $clientIP,
            'country' => $countryCode,
            'url' => $_SERVER['REQUEST_URI'] ?? 'unknown'
        ]);
        return false;
    }
    
    error_log("Geo-blocking: ALLOWED - IP: {$realIP}, Country: {$countryCode}");
    return true;
}

// Get real public IP address
function getRealPublicIP() {
    $services = [
        'https://api.ipify.org?format=text',
        'https://icanhazip.com',
        'https://ipinfo.io/ip'
    ];
    
    $context = stream_context_create([
        'http' => [
            'timeout' => 2,
            'ignore_errors' => true,
            'method' => 'GET'
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);
    
    foreach ($services as $service) {
        try {
            $ip = @file_get_contents($service, false, $context);
            if ($ip && filter_var(trim($ip), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return trim($ip);
            }
        } catch (Exception $e) {
            continue;
        }
    }
    
    return null;
}

// Get country code from IP address using free API with fallbacks
function getCountryFromIP($ip) {
    // Validate IP address
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        // Private/reserved IP, assume local (allow)
        return 'PH';
    }
    
    // Try multiple free geolocation APIs as fallbacks
    $apis = [
        // ip-api.com - 45 requests/minute, no key required
        [
            'url' => "http://ip-api.com/json/{$ip}?fields=status,countryCode",
            'parser' => function($data) {
                if (isset($data['status']) && $data['status'] === 'success' && isset($data['countryCode'])) {
                    return $data['countryCode'];
                }
                return null;
            }
        ],
        // ipapi.co - 1000 requests/day, no key required
        [
            'url' => "https://ipapi.co/{$ip}/country/",
            'parser' => function($data) {
                // Returns plain text country code
                $country = trim($data);
                if (strlen($country) === 2 && ctype_alpha($country)) {
                    return strtoupper($country);
                }
                return null;
            }
        ],
        // ipwhois.app - no rate limit on free tier
        [
            'url' => "http://ipwho.is/{$ip}",
            'parser' => function($data) {
                if (isset($data['success']) && $data['success'] === true && isset($data['country_code'])) {
                    return $data['country_code'];
                }
                return null;
            }
        ]
    ];
    
    // Set timeout for API calls
    $context = stream_context_create([
        'http' => [
            'timeout' => 3,
            'ignore_errors' => true,
            'method' => 'GET',
            'header' => "User-Agent: R2-01-Records-System/1.0\r\n"
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);
    
    // Try each API until one succeeds
    foreach ($apis as $index => $api) {
        try {
            error_log("Geo-blocking: Trying API " . ($index + 1) . " for IP: {$ip}");
            
            $response = @file_get_contents($api['url'], false, $context);
            
            if ($response === false) {
                error_log("Geo-blocking: API " . ($index + 1) . " failed to respond for IP: {$ip}");
                continue;
            }
            
            // Parse based on content type
            if (strpos($api['url'], 'ipapi.co') !== false) {
                // Plain text response
                $country = $api['parser']($response);
            } else {
                // JSON response
                $data = json_decode($response, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    error_log("Geo-blocking: API " . ($index + 1) . " returned invalid JSON for IP: {$ip}");
                    continue;
                }
                $country = $api['parser']($data);
            }
            
            if ($country !== null) {
                error_log("Geo-blocking: Successfully detected country '{$country}' for IP: {$ip} using API " . ($index + 1));
                return $country;
            }
            
        } catch (Exception $e) {
            error_log("Geo-blocking: Exception with API " . ($index + 1) . " for IP {$ip}: " . $e->getMessage());
            continue;
        }
    }
    
    // All APIs failed, deny access for security
    error_log("Geo-blocking: All APIs failed for IP: {$ip} - denying access");
    return 'UNKNOWN';
}

// Display geo-block error page and exit
function showGeoBlockPage() {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    
    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied - Geographic Restriction</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 40px;
            max-width: 500px;
            text-align: center;
        }
        .icon {
            font-size: 64px;
            margin-bottom: 20px;
        }
        h1 {
            color: #333;
            font-size: 28px;
            margin-bottom: 16px;
        }
        p {
            color: #666;
            font-size: 16px;
            line-height: 1.6;
            margin-bottom: 12px;
        }
        .error-code {
            background: #f5f5f5;
            border-radius: 6px;
            padding: 12px;
            margin-top: 24px;
            font-family: monospace;
            color: #999;
            font-size: 14px;
        }
        .ip-info {
            margin-top: 16px;
            padding: 12px;
            background: #fff3cd;
            border-radius: 6px;
            border-left: 4px solid #ffc107;
            color: #856404;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">🌏🚫</div>
        <h1>Access Denied</h1>
        <p>Your current location does not have access to this system.</p>
        <div class="ip-info">
            <strong>Your IP:</strong> ' . htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? 'Unknown') . '
        </div>
        <div class="error-code">
            Error Code: 403 - Geographic Restriction
        </div>
    </div>
</body>
</html>';
    exit;
}

// Log security events
function logSecurityEvent($event, $details = []) {
    $logFile = __DIR__ . '/../logs/security.log';
    $logDir = dirname($logFile);
    
    if (!file_exists($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    
    $logEntry = sprintf(
        "[%s] %s | IP: %s | User Agent: %s | Details: %s\n",
        $timestamp,
        $event,
        $ip,
        substr($userAgent, 0, 100),
        json_encode($details)
    );
    
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

// Log personal data access for GDPR compliance
function logDataAccess($action, $recordId, $userId = null, $details = []) {
    $logFile = __DIR__ . '/../logs/data_access.log';
    $logDir = dirname($logFile);
    
    if (!file_exists($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $sessionUser = isset($_SESSION['admin_username']) ? $_SESSION['admin_username'] : 'anonymous';
    
    $logEntry = sprintf(
        "[%s] ACTION: %s | Record ID: %s | User: %s | Session User: %s | IP: %s | User Agent: %s | Details: %s\n",
        $timestamp,
        $action,
        $recordId,
        $userId ?? 'N/A',
        $sessionUser,
        $ip,
        substr($userAgent, 0, 100),
        json_encode($details)
    );
    
    file_put_contents($logFile, $logEntry, FILE_APPEND);
    
    // Also log to security log for critical actions
    if (in_array($action, ['RECORD_DELETED', 'RECORD_EDITED', 'RECORD_EXPORTED'])) {
        logSecurityEvent($action, array_merge(['record_id' => $recordId, 'user' => $sessionUser], $details));
    }
}

// Sanitize output for display (prevent XSS)
function secureOutput($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// Validate referrer for form submissions
function validateReferrer() {
    if (!isset($_SERVER['HTTP_REFERER'])) {
        return false;
    }
    
    $referer = parse_url($_SERVER['HTTP_REFERER']);
    $current = parse_url($_SERVER['HTTP_HOST']);
    
    return isset($referer['host']) && isset($current) && 
           $referer['host'] === $_SERVER['HTTP_HOST'];
}

// Validate record ID to prevent enumeration attacks
function validateRecordId($id) {
    // Check if ID is a valid string (custom format: INITIALS + 10 digits)
    // Allow alphanumeric IDs (both old numeric and new custom format)
    if (empty($id) || !is_string($id) && !is_numeric($id)) {
        logSecurityEvent('INVALID_RECORD_ID', [
            'id' => $id,
            'user' => $_SESSION['admin_username'] ?? 'anonymous'
        ]);
        return false;
    }
    
    // Sanitize the ID (remove any potential SQL injection attempts)
    if (!preg_match('/^[A-Z0-9]+$/i', $id)) {
        logSecurityEvent('INVALID_RECORD_ID_FORMAT', [
            'id' => $id,
            'user' => $_SESSION['admin_username'] ?? 'anonymous'
        ]);
        return false;
    }
    
    return true;
}

// Check if record exists in database
function recordExists($conn, $id) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM r201_members WHERE id = ?");
    $stmt->bind_param("s", $id); // Changed from "i" to "s" for VARCHAR
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['count'] > 0;
}
?>
