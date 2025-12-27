<?php
/**
 * Cleanup old palasumpaan temporary documents
 * Run this script via cron job daily
 * Example cron: 0 2 * * * php /path/to/cleanup-palasumpaan-temp.php
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

try {
    $db = Database::getInstance()->getConnection();
    
    // Delete documents older than 7 days
    $stmt = $db->prepare("DELETE FROM palasumpaan_temp_docs WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $stmt->execute();
    
    $deletedCount = $stmt->rowCount();
    
    $timestamp = date('Y-m-d H:i:s');
    echo "[$timestamp] Cleaned up $deletedCount old palasumpaan temporary documents\n";
    
} catch (Exception $e) {
    $timestamp = date('Y-m-d H:i:s');
    echo "[$timestamp] Error cleaning up temporary documents: " . $e->getMessage() . "\n";
    exit(1);
}
