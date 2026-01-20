<?php
/**
 * Migration Script: Update HDB and PNK Registry Status Values
 * Run this to add new columns and prepare for new status values
 */

require_once __DIR__ . '/config/config.php';

$db = Database::getInstance()->getConnection();

echo "=== Running Status Migration ===\n\n";

// Helper function to check if column exists
function columnExists($db, $table, $column) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return $stmt->fetchColumn() > 0;
}

// Add columns if they don't exist
$columnsToAdd = [
    ['hdb_registry', 'transfer_to_district', 'VARCHAR(100) DEFAULT NULL'],
    ['hdb_registry', 'transfer_from', 'VARCHAR(100) DEFAULT NULL'],
    ['hdb_registry', 'transfer_from_district', 'VARCHAR(100) DEFAULT NULL'],
    ['hdb_registry', 'transfer_to', 'VARCHAR(100) DEFAULT NULL'],
    ['hdb_registry', 'transfer_date', 'DATE DEFAULT NULL'],
    ['hdb_registry', 'transfer_reason', 'TEXT DEFAULT NULL'],
    ['pnk_registry', 'transfer_to', 'VARCHAR(100) DEFAULT NULL'],
    ['pnk_registry', 'transfer_to_district', 'VARCHAR(100) DEFAULT NULL'],
    ['pnk_registry', 'transfer_from', 'VARCHAR(100) DEFAULT NULL'],
    ['pnk_registry', 'transfer_from_district', 'VARCHAR(100) DEFAULT NULL'],
    ['pnk_registry', 'transfer_date', 'DATE DEFAULT NULL'],
    ['pnk_registry', 'transfer_reason', 'TEXT DEFAULT NULL'],
    ['pnk_registry', 'dako_encrypted', 'TEXT DEFAULT NULL'],
];

echo "Adding columns...\n";
foreach ($columnsToAdd as $col) {
    $table = $col[0];
    $column = $col[1];
    $definition = $col[2];
    
    if (!columnExists($db, $table, $column)) {
        try {
            $db->exec("ALTER TABLE $table ADD COLUMN $column $definition");
            echo "  ✓ Added column $column to $table\n";
        } catch (PDOException $e) {
            echo "  ⚠ Error adding $column: " . $e->getMessage() . "\n";
        }
    } else {
        echo "  → Column $column already exists in $table\n";
    }
}

echo "\n✅ Column migration completed!\n";

// Show current status counts
echo "\n=== Current Status Counts ===\n";

echo "\nHDB Registry (dedication_status):\n";
$stmt = $db->query("SELECT dedication_status, COUNT(*) as count FROM hdb_registry WHERE deleted_at IS NULL GROUP BY dedication_status");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "  - " . ($row['dedication_status'] ?? 'NULL') . ": " . $row['count'] . " records\n";
}

echo "\nPNK Registry (baptism_status):\n";
$stmt = $db->query("SELECT baptism_status, COUNT(*) as count FROM pnk_registry WHERE deleted_at IS NULL GROUP BY baptism_status");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "  - " . ($row['baptism_status'] ?? 'NULL') . ": " . $row['count'] . " records\n";
}

echo "\nPNK Registry (attendance_status):\n";
$stmt = $db->query("SELECT attendance_status, COUNT(*) as count FROM pnk_registry WHERE deleted_at IS NULL GROUP BY attendance_status");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "  - " . ($row['attendance_status'] ?? 'NULL') . ": " . $row['count'] . " records\n";
}

echo "\n=== Migration Complete! ===\n";
echo "\nNote: The application code now handles both old and new status values.\n";
echo "Old values will be displayed as their new equivalents:\n";
echo "  - HDB: pending/dedicated → Active, transferred-out → Transferred Out, baptized → Baptized\n";
echo "  - PNK: not_baptized → Active, candidate → R3-01, baptized → Baptized\n";
