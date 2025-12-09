-- ============================================
-- Remember Me Tokens Table
-- ============================================
-- This table stores secure tokens for "Remember Me" functionality
-- allowing users to stay logged in for up to 90 days

CREATE TABLE IF NOT EXISTS remember_me_tokens (
    token_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    selector VARCHAR(64) NOT NULL UNIQUE COMMENT 'Public identifier for the token',
    hashed_validator VARCHAR(255) NOT NULL COMMENT 'Hashed secret validator',
    user_agent_hash VARCHAR(64) NOT NULL COMMENT 'Hash of user agent for additional security',
    ip_address VARCHAR(45) NOT NULL COMMENT 'IP address when token was created',
    expires_at TIMESTAMP NOT NULL COMMENT 'Token expiration time (90 days from creation)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP NULL COMMENT 'Track when token was last used',
    
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    
    INDEX idx_selector (selector),
    INDEX idx_user_id (user_id),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create cleanup event to remove expired tokens daily
DELIMITER $$

CREATE EVENT IF NOT EXISTS cleanup_expired_remember_tokens
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO
BEGIN
    DELETE FROM remember_me_tokens 
    WHERE expires_at < NOW();
END$$

DELIMITER ;
