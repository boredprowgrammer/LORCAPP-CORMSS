-- Add Two-Factor Authentication (TOTP) support to users table
-- Date: 2026-01-05

-- Add TOTP columns to users table
ALTER TABLE users 
ADD COLUMN totp_secret_encrypted TEXT NULL COMMENT 'Encrypted TOTP secret key',
ADD COLUMN totp_enabled TINYINT(1) DEFAULT 0 NOT NULL COMMENT 'Whether 2FA is enabled',
ADD COLUMN totp_backup_codes_encrypted TEXT NULL COMMENT 'Encrypted backup codes (JSON array)',
ADD COLUMN totp_verified_at TIMESTAMP NULL COMMENT 'When 2FA was first verified',
ADD COLUMN totp_last_used TIMESTAMP NULL COMMENT 'Last time 2FA was used for login',
ADD INDEX idx_totp_enabled (totp_enabled);

-- Create table to track failed 2FA attempts (rate limiting)
CREATE TABLE IF NOT EXISTS totp_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    success TINYINT(1) DEFAULT 0,
    INDEX idx_user_ip (user_id, ip_address),
    INDEX idx_attempted_at (attempted_at),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add audit log entries for 2FA events
INSERT INTO audit_log_types (action_type, description) VALUES
('totp_enabled', 'Two-Factor Authentication enabled'),
('totp_disabled', 'Two-Factor Authentication disabled'),
('totp_verified', 'Two-Factor Authentication verified during login'),
('totp_backup_used', 'Backup code used for 2FA login'),
('totp_backup_regenerated', 'Backup codes regenerated'),
('totp_failed_attempt', 'Failed 2FA verification attempt')
ON DUPLICATE KEY UPDATE description = VALUES(description);
