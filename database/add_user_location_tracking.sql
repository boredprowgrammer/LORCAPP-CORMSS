-- Add user location tracking table
CREATE TABLE IF NOT EXISTS user_locations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    latitude DECIMAL(10, 8) NULL,
    longitude DECIMAL(11, 8) NULL,
    ip_address VARCHAR(45) NOT NULL,
    accuracy FLOAT NULL,
    device_info TEXT NULL,
    last_updated DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_last_updated (last_updated)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add can_track_users permission to user_permissions table
ALTER TABLE user_permissions 
ADD COLUMN IF NOT EXISTS can_track_users TINYINT(1) DEFAULT 0;
