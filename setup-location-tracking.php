<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

try {
    $db = Database::getInstance()->getConnection();
    
    // Check if user_locations table exists
    $stmt = $db->query("SHOW TABLES LIKE 'user_locations'");
    if ($stmt->rowCount() == 0) {
        echo "Creating user_locations table...\n";
        $db->exec("
            CREATE TABLE user_locations (
                id INT PRIMARY KEY AUTO_INCREMENT,
                user_id INT NOT NULL,
                latitude DECIMAL(10, 8) NULL,
                longitude DECIMAL(11, 8) NULL,
                ip_address VARCHAR(45) NOT NULL,
                accuracy FLOAT NULL,
                device_info TEXT NULL,
                address TEXT NULL,
                city VARCHAR(100) NULL,
                country VARCHAR(100) NULL,
                location_source VARCHAR(50) NULL,
                last_updated DATETIME NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
                INDEX idx_user_id (user_id),
                INDEX idx_last_updated (last_updated)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "✓ user_locations table created\n";
    } else {
        echo "✓ user_locations table already exists\n";
        
        // Add new columns if they don't exist
        $columnsToAdd = [
            'address' => "ALTER TABLE user_locations ADD COLUMN address TEXT NULL",
            'city' => "ALTER TABLE user_locations ADD COLUMN city VARCHAR(100) NULL",
            'country' => "ALTER TABLE user_locations ADD COLUMN country VARCHAR(100) NULL",
            'location_source' => "ALTER TABLE user_locations ADD COLUMN location_source VARCHAR(50) NULL"
        ];
        
        foreach ($columnsToAdd as $column => $sql) {
            $stmt = $db->query("SHOW COLUMNS FROM user_locations LIKE '$column'");
            if ($stmt->rowCount() == 0) {
                echo "Adding $column column...\n";
                $db->exec($sql);
                echo "✓ $column column added\n";
            }
        }
    }
    
    // Check if can_track_users column exists
    $stmt = $db->query("SHOW COLUMNS FROM user_permissions LIKE 'can_track_users'");
    if ($stmt->rowCount() == 0) {
        echo "Adding can_track_users column to user_permissions...\n";
        $db->exec("ALTER TABLE user_permissions ADD COLUMN can_track_users TINYINT(1) DEFAULT 0");
        echo "✓ can_track_users column added\n";
    } else {
        echo "✓ can_track_users column already exists\n";
    }
    
    echo "\n✓ All migrations completed successfully!\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
