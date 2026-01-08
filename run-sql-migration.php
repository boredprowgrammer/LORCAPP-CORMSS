<?php
/**
 * Run SQL Migration Script
 * Usage: php run-sql-migration.php <sql-file-path>
 */

// Check if script is run from CLI
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

require_once __DIR__ . '/config/config.php';

// Check if SQL file is provided
if ($argc < 2) {
    die("Usage: php run-sql-migration.php <sql-file-path>\n");
}

$sqlFile = $argv[1];

// Check if file exists
if (!file_exists($sqlFile)) {
    die("Error: SQL file not found: $sqlFile\n");
}

echo "Running migration: $sqlFile\n";
echo str_repeat('-', 50) . "\n";

try {
    $db = Database::getInstance()->getConnection();
    
    // Read SQL file
    $sql = file_get_contents($sqlFile);
    
    if (empty($sql)) {
        die("Error: SQL file is empty\n");
    }
    
    // Split into individual statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && !preg_match('/^--/', $stmt);
        }
    );
    
    $executed = 0;
    $errors = 0;
    
    foreach ($statements as $statement) {
        try {
            $db->exec($statement);
            $executed++;
            echo ".";
            if ($executed % 50 === 0) {
                echo " $executed\n";
            }
        } catch (PDOException $e) {
            // Ignore "already exists" errors
            if (strpos($e->getMessage(), 'Duplicate column name') !== false ||
                strpos($e->getMessage(), 'Duplicate key name') !== false ||
                strpos($e->getMessage(), 'already exists') !== false) {
                echo "s"; // Skip already exists
                $executed++;
            } else {
                $errors++;
                echo "\nError executing statement: " . $e->getMessage() . "\n";
                echo "Statement: " . substr($statement, 0, 100) . "...\n";
            }
        }
    }
    
    echo "\n" . str_repeat('-', 50) . "\n";
    echo "Migration completed!\n";
    echo "Executed: $executed statements\n";
    
    if ($errors > 0) {
        echo "Errors: $errors statements\n";
    }
    
} catch (Exception $e) {
    die("Fatal error: " . $e->getMessage() . "\n");
}
