<?php
/**
 * Application Configuration
 */

// Load environment variables from .env file
function loadEnv($filePath) {
    if (!file_exists($filePath)) {
        return false;
    }
    
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        
        // Parse KEY=VALUE
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Remove quotes if present
            $value = trim($value, '"\'');
            
            // Set as environment variable if not already set
            if (!getenv($key)) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
    return true;
}

// Load .env file from project root
$envFile = __DIR__ . '/../.env';
loadEnv($envFile);

// Load Infisical integration for secure key management
require_once __DIR__ . '/../includes/infisical.php';

// Error Reporting - Environment-based configuration
$appEnv = getenv('APP_ENV') ?: 'development';

if ($appEnv === 'production') {
    // Production: Hide all errors from users
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    ini_set('log_errors', 0);
    ini_set('error_log', '/dev/null');
} else {
    // Development only
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// Timezone
date_default_timezone_set('Asia/Manila');

// Performance Optimizations
// Enable gzip compression
if (!ob_start('ob_gzhandler')) {
    ob_start();
}

// Session Configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? 1 : 0);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.gc_maxlifetime', 28800); // 8 hours
ini_set('session.cookie_lifetime', 28800); // 8 hours
ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 100);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Force HTTPS in production
if ($appEnv === 'production' && empty($_SERVER['HTTPS'])) {
    header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], true, 301);
    exit;
}

// Application Settings
define('APP_NAME', 'LORCAPP CORRMS');
define('APP_VERSION', '1.0.0');
define('APP_ENV', $appEnv);

// Auto-detect BASE_URL
function getBaseUrl() {
    // Check if behind a proxy (ngrok, etc.)
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'];
    
    // Determine protocol
    $protocol = 'http';
    if (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
        (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') ||
        (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
    ) {
        $protocol = 'https';
    }
    
    // Get the base path (directory where index.php is located)
    $scriptName = $_SERVER['SCRIPT_NAME'];
    $basePath = str_replace('\\', '/', dirname($scriptName));
    $basePath = rtrim($basePath, '/');
    
    // Remove common subdirectories from path if script is in a subdirectory
    $basePath = preg_replace('#/(includes|config|api|officers|admin|requests|lorcapp|transfers|reports|tarheta|legacy|cfo-app|callup-app|officers-app|registry-app|reports-app|requests-app|masterlist-form|palasumpaan_template|R5-13).*$#', '', $basePath);
    
    return $protocol . '://' . $host . $basePath;
}

define('BASE_URL', getBaseUrl());

// Security Settings
define('CSRF_TOKEN_NAME', 'csrf_token');
define('SESSION_TIMEOUT', 28800); // 8 hours in seconds (increased from 1 hour)
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes in seconds

// Security Headers
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=(), magnetometer=(), gyroscope=()');

// Performance Headers - Cache static assets
$currentFile = basename($_SERVER['PHP_SELF']);
if (preg_match('/\.(css|js|jpg|jpeg|png|gif|ico|woff|woff2|ttf|svg)$/', $currentFile)) {
    header('Cache-Control: public, max-age=31536000, immutable'); // 1 year for assets
} else {
    header('Cache-Control: no-cache, must-revalidate, max-age=0'); // No cache for PHP pages
}

// Remove X-Powered-By header to prevent server version disclosure
header_remove('X-Powered-By');

// Strict Transport Security (for production with HTTPS)
if ($appEnv === 'production' && !empty($_SERVER['HTTPS'])) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}

// Encryption Settings - Load from Infisical or environment variables
define('ENCRYPTION_METHOD', 'AES-256-CBC');

// Try to get MASTER_KEY from Infisical first, then fall back to environment
try {
    $masterKey = InfisicalKeyManager::getSecret('MASTER_KEY');
} catch (Exception $e) {
    $masterKey = getenv('MASTER_KEY');
}

if (empty($masterKey)) {
    if ($appEnv === 'production') {
        error_log('MASTER_KEY not found in Infisical or environment');
        die('Configuration error. Please contact the system administrator.');
    } else {
        // Development fallback only
        $masterKey = 'DEVELOPMENT_ONLY_KEY_CHANGE_IN_PRODUCTION_' . hash('sha256', __DIR__);
    }
}
define('MASTER_KEY', $masterKey);

// Chat encryption key - Load from Infisical first
try {
    $chatKey = InfisicalKeyManager::getSecret('CHAT_MASTER_KEY');
} catch (Exception $e) {
    $chatKey = getenv('CHAT_MASTER_KEY');
}

if (empty($chatKey)) {
    if ($appEnv === 'production') {
        error_log('CHAT_MASTER_KEY not found in Infisical or environment');
        die('Configuration error. Please contact the system administrator.');
    } else {
        // Development fallback - derive from MASTER_KEY
        $chatKey = hash('sha256', $masterKey . '_chat_key_salt');
    }
}
define('CHAT_MASTER_KEY', $chatKey);

// Pagination
define('RECORDS_PER_PAGE', 25);

// File Upload Settings
define('UPLOAD_MAX_SIZE', 5242880); // 5MB in bytes
define('ALLOWED_FILE_TYPES', ['jpg', 'jpeg', 'png', 'pdf']);

// Load required files
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../includes/infisical.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/encryption.php';
require_once __DIR__ . '/../includes/permissions.php';
