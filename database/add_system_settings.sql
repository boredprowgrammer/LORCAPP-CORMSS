-- Create system settings table for maintenance mode and other global settings
CREATE TABLE IF NOT EXISTS system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    setting_type ENUM('boolean', 'string', 'integer', 'json') DEFAULT 'string',
    description VARCHAR(255),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT,
    FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default settings
INSERT INTO system_settings (setting_key, setting_value, setting_type, description) VALUES
('maintenance_mode', '0', 'boolean', 'Enable maintenance mode for all users except admins'),
('maintenance_message', 'The system is currently undergoing scheduled maintenance. Please check back shortly.', 'string', 'Message displayed during maintenance'),
('maintenance_end_time', NULL, 'string', 'Estimated end time for maintenance (displayed to users)')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- Add index for faster lookups
CREATE INDEX idx_setting_key ON system_settings(setting_key);
