<?php
/**
 * LORCAPP
 * Database Configuration with Aiven SSL Support
 */

// Prevent direct access
if (count(get_included_files()) == 1) exit("Direct access forbidden");

// ==========================================
// SECURITY HEADERS (COMPREHENSIVE)
// ==========================================

// Prevent MIME type sniffing
header("X-Content-Type-Options: nosniff");

// XSS Protection
header("X-XSS-Protection: 1; mode=block");

// Prevent clickjacking
header("X-Frame-Options: SAMEORIGIN");

// Referrer Policy
header("Referrer-Policy: strict-origin-when-cross-origin");

// Permissions Policy (formerly Feature-Policy)
header("Permissions-Policy: geolocation=(), microphone=(), camera=(self), payment=(), usb=(), magnetometer=(), gyroscope=(), speaker=()");

// HSTS - Force HTTPS for 2 years (only if HTTPS)
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    header("Strict-Transport-Security: max-age=63072000; includeSubDomains; preload");
}

// Content Security Policy (Comprehensive with all directives)
header("Content-Security-Policy: "
    . "default-src 'self'; "
    . "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com https://cdnjs.cloudflare.com https://challenges.cloudflare.com https://unpkg.com https://site-assets.fontawesome.com https://cdn.jsdelivr.net; "
    . "script-src-elem 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://cdnjs.cloudflare.com https://challenges.cloudflare.com https://unpkg.com https://site-assets.fontawesome.com https://cdn.jsdelivr.net; "
    . "script-src-attr 'unsafe-inline'; "
    . "style-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://googleapis.com https://site-assets.fontawesome.com https://fonts.googleapis.com; "
    . "style-src-elem 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://googleapis.com https://site-assets.fontawesome.com https://fonts.googleapis.com; "
    . "style-src-attr 'unsafe-inline'; "
    . "font-src 'self' https://fonts.gstatic.com https://site-assets.fontawesome.com; "
    . "img-src 'self' data: blob: https:; "
    . "media-src 'self'; "
    . "connect-src 'self' https://api.altcha.org https://api-gateway.umami.dev https://cdn.jsdelivr.net; "
    . "worker-src 'self' blob:; "
    . "child-src 'self' blob:; "
    . "frame-src https://challenges.cloudflare.com; "
    . "frame-ancestors 'self'; "
    . "manifest-src 'self'; "
    . "object-src 'none'; "
    . "base-uri 'self'; "
    . "form-action 'self'; "
    . "upgrade-insecure-requests;"
);

// Remove server signature
header_remove("X-Powered-By");
header_remove("Server");

// Expect-CT (Certificate Transparency)
header("Expect-CT: max-age=86400, enforce");

// ==========================================
// END SECURITY HEADERS
// ==========================================

// Load environment variables from .env file
if (!function_exists('loadEnv')) {
    function loadEnv($filePath) {
        if (!file_exists($filePath)) {
            return;
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
    }
}

// Load .env file - try multiple locations
// Load CORegistry root .env first for ENCRYPTION_KEY
$rootEnv = __DIR__ . '/../../.env';
$lorcappEnv = __DIR__ . '/../.env';
$lorcappIncludesEnv = __DIR__ . '/.env';

// Load root .env for shared keys like ENCRYPTION_KEY
if (file_exists($rootEnv)) {
    loadEnv($rootEnv);
    error_log("LORCAPP: Loaded root .env from: " . $rootEnv);
}

// Then load lorcapp-specific .env (can override if needed)
if (file_exists($lorcappEnv)) {
    loadEnv($lorcappEnv);
    error_log("LORCAPP: Loaded lorcapp .env from: " . $lorcappEnv);
} elseif (file_exists($lorcappIncludesEnv)) {
    loadEnv($lorcappIncludesEnv);
}

// Encryption keys - LORCAPP uses its own key to avoid conflicts
// If LORCAPP_ENCRYPTION_KEY is not set, use ENCRYPTION_KEY as fallback
if (!getenv('LORCAPP_ENCRYPTION_KEY') && getenv('ENCRYPTION_KEY')) {
    putenv('LORCAPP_ENCRYPTION_KEY=' . getenv('ENCRYPTION_KEY'));
    $_ENV['LORCAPP_ENCRYPTION_KEY'] = getenv('ENCRYPTION_KEY');
    $_SERVER['LORCAPP_ENCRYPTION_KEY'] = getenv('ENCRYPTION_KEY');
    error_log("LORCAPP: Set LORCAPP_ENCRYPTION_KEY from ENCRYPTION_KEY");
}

// Define constants for backward compatibility
if (!defined('ENCRYPTION_KEY')) {
    define('ENCRYPTION_KEY', getenv('ENCRYPTION_KEY') ?: '');
}

// Database credentials - Use Aiven configuration from .env (no fallbacks)
// Use LORCAPP-specific constants to avoid conflicts with CORegistry
define('LORCAPP_DB_HOST', getenv('AIVEN_HOST'));
define('LORCAPP_DB_PORT', getenv('AIVEN_PORT'));
define('LORCAPP_DB_USER', getenv('AIVEN_USER'));
define('LORCAPP_DB_PASS', getenv('AIVEN_PASSWORD'));
define('LORCAPP_DB_NAME', getenv('AIVEN_DATABASE'));
define('LORCAPP_DB_SSL_MODE', getenv('AIVEN_SSL_MODE'));

// For backward compatibility, set DB_* constants only if not already defined
if (!defined('DB_HOST')) {
    define('DB_HOST', LORCAPP_DB_HOST);
}
if (!defined('DB_PORT')) {
    define('DB_PORT', LORCAPP_DB_PORT);
}
if (!defined('DB_USER')) {
    define('DB_USER', LORCAPP_DB_USER);
}
if (!defined('DB_PASS')) {
    define('DB_PASS', LORCAPP_DB_PASS);
}
if (!defined('DB_NAME')) {
    define('DB_NAME', LORCAPP_DB_NAME);
}
if (!defined('DB_SSL_MODE')) {
    define('DB_SSL_MODE', LORCAPP_DB_SSL_MODE);
}

// Site configuration
if (!defined('SITE_URL')) {
    define('SITE_URL', getenv('SITE_URL') ?: getenv('BASE_URL') ?: 'http://localhost:8090');
}
if (!defined('SITE_NAME')) {
    define('SITE_NAME', getenv('SITE_NAME') ?: 'LORCAPP');
}
if (!defined('APP_ENV')) {
    define('APP_ENV', getenv('APP_ENV') ?: 'development');
}

// Timezone
date_default_timezone_set(getenv('TIMEZONE') ?: 'UTC');

// Create database connection with SSL support for Aiven
function getDbConnection() {
    static $conn = null;
    
    if ($conn === null) {
        try {
            // Check if SSL is required (Aiven)
            $useSSL = LORCAPP_DB_SSL_MODE === 'REQUIRED';
            
            // Ensure port is an integer
            $port = (int)(LORCAPP_DB_PORT ?: 3306);
            
            if ($useSSL) {
                // Check if OpenSSL is available
                if (!extension_loaded('openssl')) {
                    error_log("LORCAPP: OpenSSL extension not loaded, attempting connection without SSL verification");
                }
                
                // Initialize MySQLi for SSL connection (Aiven)
                $conn = mysqli_init();
                
                if (!$conn) {
                    throw new Exception("mysqli_init failed");
                }
                
                // Try to connect with SSL, but handle gracefully if SSL not supported
                try {
                    // Set SSL options - Aiven requires SSL but doesn't need local cert files
                    // Using NULL for all paths tells mysqli to use the default CA bundle
                    mysqli_ssl_set($conn, NULL, NULL, NULL, NULL, NULL);
                    
                    // Set options before connecting
                    mysqli_options($conn, MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false);
                    
                    // Connect with SSL using MYSQLI_CLIENT_SSL flag
                    $connected = @mysqli_real_connect(
                        $conn,
                        LORCAPP_DB_HOST,
                        LORCAPP_DB_USER,
                        LORCAPP_DB_PASS,
                        LORCAPP_DB_NAME,
                        $port,
                        NULL,
                        MYSQLI_CLIENT_SSL
                    );
                    
                    if (!$connected) {
                        // SSL connection failed, try without SSL
                        error_log("LORCAPP: SSL connection failed, trying without SSL: " . mysqli_connect_error());
                        
                        // Close failed connection
                        mysqli_close($conn);
                        
                        // Try standard connection instead
                        $conn = new mysqli(LORCAPP_DB_HOST, LORCAPP_DB_USER, LORCAPP_DB_PASS, LORCAPP_DB_NAME, $port);
                        
                        if ($conn->connect_error) {
                            throw new Exception("Connection failed: " . $conn->connect_error);
                        }
                        
                        error_log("LORCAPP: Connected without SSL successfully");
                    }
                } catch (Exception $e) {
                    error_log("LORCAPP: Exception during SSL connection: " . $e->getMessage());
                    
                    // Try standard connection as fallback
                    $conn = new mysqli(LORCAPP_DB_HOST, LORCAPP_DB_USER, LORCAPP_DB_PASS, LORCAPP_DB_NAME, $port);
                    
                    if ($conn->connect_error) {
                        throw new Exception("Connection failed: " . $conn->connect_error);
                    }
                    
                    error_log("LORCAPP: Connected without SSL after exception");
                }
                
            } else {
                // Standard connection for local MySQL (no SSL)
                $conn = new mysqli(LORCAPP_DB_HOST, LORCAPP_DB_USER, LORCAPP_DB_PASS, LORCAPP_DB_NAME, $port);
                
                if ($conn->connect_error) {
                    throw new Exception("Connection failed: " . $conn->connect_error);
                }
            }
            
            // Set charset
            $conn->set_charset("utf8mb4");
            
        } catch (Exception $e) {
            // Show detailed error in development only
            if (APP_ENV === 'development') {
                die("Database connection error: " . $e->getMessage() . "<br>Host: " . LORCAPP_DB_HOST . ":" . $port . "<br>Database: " . LORCAPP_DB_NAME . "<br>SSL Mode: " . LORCAPP_DB_SSL_MODE);
            }
            
            die("Database connection error. Please check your configuration.");
        }
    }
    
    return $conn;
}

// Helper function to sanitize input
function sanitize($data) {
    $conn = getDbConnection();
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }
    return $conn->real_escape_string(trim($data));
}

// Helper function for JSON encoding
function jsonEncode($data) {
    return json_encode($data, JSON_UNESCAPED_UNICODE);
}

// Helper function for JSON decoding
function jsonDecode($json) {
    return json_decode($json, true);
}

/**
 * Generate a unique record ID
 * Format: INITIALS + 10 random digits
 * Example: JDF1234567890 (for Juan Dela Cruz Fernando)
 * 
 * @param string $given_name First name
 * @param string $father_surname Father's surname
 * @param string $mother_surname Mother's surname (optional)
 * @param mysqli $conn Database connection
 * @return string Unique record ID
 */
function generateRecordId($given_name, $father_surname, $mother_surname = '', $conn = null) {
    if ($conn === null) {
        $conn = getDbConnection();
    }
    
    // Extract initials from names
    $initials = '';
    
    // Get first letter of given name
    if (!empty($given_name)) {
        $initials .= strtoupper(substr(trim($given_name), 0, 1));
    }
    
    // Get first letter of father surname
    if (!empty($father_surname)) {
        $initials .= strtoupper(substr(trim($father_surname), 0, 1));
    }
    
    // Get first letter of mother surname
    if (!empty($mother_surname)) {
        $initials .= strtoupper(substr(trim($mother_surname), 0, 1));
    }
    
    // If no initials could be extracted, use default
    if (empty($initials)) {
        $initials = 'UNK'; // Unknown
    }
    
    // Generate unique ID by trying random numbers until we find a unique one
    $max_attempts = 100;
    $attempt = 0;
    
    do {
        // Generate 10 random digits
        $random_digits = '';
        for ($i = 0; $i < 10; $i++) {
            $random_digits .= random_int(0, 9);
        }
        
        $record_id = $initials . $random_digits;
        
        // Check if this ID already exists
        $check_query = "SELECT id FROM r201_members WHERE id = ? LIMIT 1";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("s", $record_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows === 0) {
            // ID is unique, return it
            return $record_id;
        }
        
        $attempt++;
    } while ($attempt < $max_attempts);
    
    // If we couldn't generate a unique ID after max attempts, throw exception
    throw new Exception("Failed to generate unique record ID after {$max_attempts} attempts");
}

// Note: Session management is handled by security.php
// Do not start session here to avoid conflicts
?>
