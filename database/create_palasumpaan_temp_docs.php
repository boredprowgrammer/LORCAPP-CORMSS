<?php
/**
 * Create palasumpaan_temp_docs table
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance()->getConnection();
    
    $sql = file_get_contents(__DIR__ . '/palasumpaan_temp_docs.sql');
    
    $db->exec($sql);
    
    echo "✓ Table 'palasumpaan_temp_docs' created successfully!\n";
    
} catch (Exception $e) {
    echo "✗ Error creating table: " . $e->getMessage() . "\n";
    exit(1);
}
