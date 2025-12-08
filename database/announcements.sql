-- Announcements Table for Church Officers Registry System
-- This table stores system-wide announcements that can be displayed to users

CREATE TABLE IF NOT EXISTS announcements (
    announcement_id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- Announcement Content
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    announcement_type ENUM('info', 'warning', 'success', 'error') DEFAULT 'info' COMMENT 'Type determines display style',
    
    -- Priority and Display
    priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
    is_active TINYINT(1) DEFAULT 1 COMMENT 'Whether announcement is currently displayed',
    is_pinned TINYINT(1) DEFAULT 0 COMMENT 'Pinned announcements appear first',
    
    -- Visibility Control
    target_role ENUM('all', 'admin', 'district', 'local') DEFAULT 'all' COMMENT 'Which user roles can see this',
    target_district_code VARCHAR(20) NULL COMMENT 'If set, only users in this district can see it',
    target_local_code VARCHAR(20) NULL COMMENT 'If set, only users in this local can see it',
    
    -- Scheduling
    start_date DATETIME NULL COMMENT 'When announcement becomes visible',
    end_date DATETIME NULL COMMENT 'When announcement automatically hides',
    
    -- Tracking
    created_by INT NOT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Foreign Keys
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (target_district_code) REFERENCES districts(district_code) ON DELETE CASCADE,
    FOREIGN KEY (target_local_code) REFERENCES local_congregations(local_code) ON DELETE CASCADE,
    
    -- Indexes
    INDEX idx_is_active (is_active),
    INDEX idx_priority (priority),
    INDEX idx_target_role (target_role),
    INDEX idx_target_district (target_district_code),
    INDEX idx_target_local (target_local_code),
    INDEX idx_dates (start_date, end_date),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User Announcement Dismissals (track which announcements users have dismissed)
CREATE TABLE IF NOT EXISTS announcement_dismissals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    announcement_id INT NOT NULL,
    user_id INT NOT NULL,
    dismissed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (announcement_id) REFERENCES announcements(announcement_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    
    UNIQUE KEY unique_user_announcement (user_id, announcement_id),
    INDEX idx_user_id (user_id),
    INDEX idx_announcement_id (announcement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
