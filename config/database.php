<?php
/**
 * Database Configuration
 */

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'church_officers_db');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_SSL_MODE', getenv('DB_SSL_MODE') ?: 'DISABLED'); // REQUIRED, PREFERRED, DISABLED

// Database password - require environment variable in production
$dbPass = getenv('DB_PASS');
if (empty($dbPass)) {
    $appEnv = getenv('APP_ENV') ?: 'development';
    if ($appEnv === 'production') {
        error_log('CRITICAL SECURITY ERROR: DB_PASS environment variable not set');
        die('Database configuration error. Please contact the system administrator.');
    } else {
        // Development fallback only
        error_log('WARNING: Using default DB_PASS for development. DO NOT use in production!');
        $dbPass = 'rootUser123';
    }
}
define('DB_PASS', $dbPass);
define('DB_CHARSET', 'utf8mb4');

class Database {
    private static $instance = null;
    private $conn;
    
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => true, // Enable persistent connections for better performance
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true, // Buffer queries for faster execution
                PDO::ATTR_TIMEOUT => 5, // Connection timeout
                PDO::MYSQL_ATTR_COMPRESS => true, // Enable compression for faster data transfer
                PDO::MYSQL_ATTR_LOCAL_INFILE => false // Security: disable local file loading
            ];
            
            // Add SSL configuration if required
            if (DB_SSL_MODE === 'REQUIRED' || DB_SSL_MODE === 'PREFERRED') {
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
                // For Aiven and other cloud providers with SSL
                $options[PDO::MYSQL_ATTR_SSL_CA] = true;
            }
            
            $this->conn = new PDO($dsn, DB_USER, DB_PASS, $options);
            
            // Set additional performance optimizations
            $this->conn->exec("SET SESSION sql_mode = 'TRADITIONAL'");
        } catch(PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            die("Database connection failed. Please contact administrator.");
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->conn;
    }
    
    // Prevent cloning
    private function __clone() {}
    
    // Prevent unserialization
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}
