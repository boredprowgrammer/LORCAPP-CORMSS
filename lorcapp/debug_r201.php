<?php
/**
 * Debug script for R201 Print issues
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

echo "<h1>R201 Debug Information</h1>";
echo "<pre>";

// Check if ID is provided
echo "\n=== GET Parameters ===\n";
print_r($_GET);

$id = isset($_GET['id']) ? $_GET['id'] : '';
echo "\nRecord ID: " . htmlspecialchars($id) . "\n";

// Load config
echo "\n=== Loading Config ===\n";
try {
    require_once 'includes/config.php';
    echo "✓ Config loaded successfully\n";
    echo "Database Host: " . LORCAPP_DB_HOST . "\n";
    echo "Database Name: " . LORCAPP_DB_NAME . "\n";
} catch (Exception $e) {
    echo "✗ Error loading config: " . $e->getMessage() . "\n";
    exit;
}

// Test database connection
echo "\n=== Database Connection ===\n";
try {
    $conn = getDbConnection();
    echo "✓ Database connected successfully\n";
} catch (Exception $e) {
    echo "✗ Database connection failed: " . $e->getMessage() . "\n";
    exit;
}

// Load encryption
echo "\n=== Loading Encryption ===\n";
try {
    require_once 'includes/encryption.php';
    echo "✓ Encryption loaded\n";
} catch (Exception $e) {
    echo "✗ Error loading encryption: " . $e->getMessage() . "\n";
}

// Check if record exists
if (!empty($id)) {
    echo "\n=== Checking Record ===\n";
    
    $query = "SELECT id, encrypted_last_name, encrypted_first_name, encrypted_middle_name FROM r201_members WHERE id = ?";
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        echo "✗ Failed to prepare statement: " . $conn->error . "\n";
        exit;
    }
    
    $stmt->bind_param("s", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        echo "✓ Record found!\n";
        $record = $result->fetch_assoc();
        echo "Record ID: " . $record['id'] . "\n";
        
        // Try to decrypt name
        echo "\n=== Testing Decryption ===\n";
        try {
            $lastName = lorcappDecrypt($record['encrypted_last_name']);
            echo "✓ Last Name decrypted: " . $lastName . "\n";
        } catch (Exception $e) {
            echo "✗ Decryption failed: " . $e->getMessage() . "\n";
        }
        
    } else {
        echo "✗ Record not found in database\n";
    }
    
    $stmt->close();
} else {
    echo "⚠ No record ID provided in URL\n";
}

echo "\n=== Session Information ===\n";
echo "Session started: " . (session_status() === PHP_SESSION_ACTIVE ? "Yes" : "No") . "\n";
echo "Session variables:\n";
print_r($_SESSION);

echo "\n=== Environment Variables ===\n";
echo "APP_ENV: " . (getenv('APP_ENV') ?: 'not set') . "\n";
echo "MASTER_KEY: " . (getenv('MASTER_KEY') ? 'SET' : 'NOT SET') . "\n";
echo "LORCAPP_ENCRYPTION_KEY: " . (getenv('LORCAPP_ENCRYPTION_KEY') ? 'SET' : 'NOT SET') . "\n";

$conn->close();

echo "\n=== Debug Complete ===\n";
echo "</pre>";
?>
