<?php
/**
 * Run R5-18 Checker Migration
 */

require_once __DIR__ . '/config/database.php';

$db = Database::getInstance()->getConnection();
$sql = file_get_contents(__DIR__ . '/database/add_r518_checker_support.sql');

// Remove single-line comments
$sql = preg_replace('/^--.*$/m', '', $sql);
// Remove multi-line comments
$sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

// Split by DELIMITER
$parts = preg_split('/DELIMITER\s+\$\$/i', $sql);

try {
    echo "Starting R5-18 Checker migration...\n\n";
    
    // Execute main SQL (before delimiter)
    if (!empty($parts[0])) {
        $statements = array_filter(array_map('trim', explode(';', $parts[0])));
        foreach ($statements as $statement) {
            if (!empty($statement)) {
                try {
                    $db->exec($statement);
                    if (stripos($statement, 'ALTER TABLE') !== false && stripos($statement, 'ADD COLUMN') !== false) {
                        preg_match('/ADD COLUMN (\w+)/', $statement, $matches);
                        $column = $matches[1] ?? 'column';
                        echo "✓ Added column: $column\n";
                    } elseif (stripos($statement, 'ADD INDEX') !== false) {
                        preg_match('/ADD INDEX (\w+)/', $statement, $matches);
                        $index = $matches[1] ?? 'index';
                        echo "✓ Added index: $index\n";
                    } elseif (stripos($statement, 'UPDATE officers') !== false) {
                        $affected = $db->exec($statement);
                        echo "✓ Data migration: $affected rows updated\n";
                    }
                } catch (PDOException $e) {
                    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
                        echo "⚠ Column already exists (skipped)\n";
                    } elseif (strpos($e->getMessage(), 'Duplicate key') !== false) {
                        echo "⚠ Index already exists (skipped)\n";
                    } elseif (strpos($e->getMessage(), 'Base table or view not found') !== false || 
                              strpos($e->getMessage(), "doesn't exist") !== false ||
                              strpos($e->getMessage(), 'Unknown column') !== false) {
                        echo "⚠ Skipped query (missing table/column)\n";
                    } else {
                        throw $e;
                    }
                }
            }
        }
    }
    
    // Execute trigger (between delimiters)
    if (isset($parts[1])) {
        $triggerParts = preg_split('/DELIMITER\s+;/i', $parts[1]);
        if (!empty($triggerParts[0])) {
            $trigger = trim($triggerParts[0]);
            if (!empty($trigger)) {
                try {
                    // Drop existing trigger first
                    $db->exec('DROP TRIGGER IF EXISTS trg_officers_r518_status_update');
                    // Create new trigger
                    $db->exec($trigger);
                    echo "✓ Trigger created: trg_officers_r518_status_update\n";
                } catch (PDOException $e) {
                    echo "⚠ Trigger warning: " . $e->getMessage() . "\n";
                }
            }
        }
    }
    
    echo "\n✅ Migration completed successfully!\n\n";
    
    // Show summary
    $stmt = $db->query("
        SELECT 
            r518_completion_status,
            COUNT(*) as count,
            ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM officers WHERE is_active = 1), 1) as percentage
        FROM officers 
        WHERE is_active = 1
        GROUP BY r518_completion_status
        ORDER BY FIELD(r518_completion_status, 'verified', 'complete', 'incomplete', 'pending')
    ");
    
    echo "R5-18 Status Summary:\n";
    echo "--------------------\n";
    $total = 0;
    while ($row = $stmt->fetch()) {
        echo sprintf("%-12s: %4d officers (%s%%)\n", 
            ucfirst($row['r518_completion_status']), 
            $row['count'],
            $row['percentage']
        );
        $total += $row['count'];
    }
    echo "--------------------\n";
    echo "Total Active: $total officers\n\n";
    
    echo "✓ R5-18 Checker is now ready to use!\n";
    echo "  Navigate to Reports > LORC/LCRC Checker\n";
    echo "  Select 'R5-18 Checker' from Issue Filter\n\n";
    
} catch (Exception $e) {
    echo "\n❌ Migration failed: " . $e->getMessage() . "\n";
    echo "Error details: " . $e->getTraceAsString() . "\n";
    exit(1);
}
