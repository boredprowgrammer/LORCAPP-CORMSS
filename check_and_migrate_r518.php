<?php
require_once 'config/config.php';

$db = Database::getInstance()->getConnection();
$currentDb = $db->query('SELECT DATABASE()')->fetchColumn();
echo "Current database: $currentDb\n";
echo "Running migration on this database...\n\n";

$sql = file_get_contents('database/add_r518_checker_support.sql');
$sql = preg_replace('/^--.*$/m', '', $sql);
$sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
$parts = preg_split('/DELIMITER\s+\$\$/i', $sql);

if (!empty($parts[0])) {
    $statements = array_filter(array_map('trim', explode(';', $parts[0])));
    foreach ($statements as $statement) {
        if (!empty($statement) && stripos($statement, 'ALTER TABLE') !== false) {
            try {
                $db->exec($statement);
                if (preg_match('/ADD COLUMN (\w+)/', $statement, $matches)) {
                    echo "✓ Added column: {$matches[1]}\n";
                }
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate column') !== false) {
                    echo "⚠ Column already exists (skipped)\n";
                } else {
                    echo "Error: " . $e->getMessage() . "\n";
                }
            }
        }
    }
}

echo "\nVerifying columns...\n";
$stmt = $db->query("SHOW COLUMNS FROM officers LIKE 'r518%'");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($columns)) {
    echo "❌ No r518 columns found!\n";
} else {
    echo "✅ Found " . count($columns) . " r518 columns:\n";
    foreach ($columns as $col) {
        echo "  - " . $col['Field'] . "\n";
    }
}
