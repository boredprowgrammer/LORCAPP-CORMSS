-- User Permissions Table for Church Officers Registry System
-- This table stores which features/components each user can access

CREATE TABLE IF NOT EXISTS user_permissions (
    permission_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    
    -- Feature Access Flags
    can_view_officers TINYINT(1) DEFAULT 1 COMMENT 'View officer list',
    can_add_officers TINYINT(1) DEFAULT 1 COMMENT 'Add new officers',
    can_edit_officers TINYINT(1) DEFAULT 1 COMMENT 'Edit officer details',
    can_delete_officers TINYINT(1) DEFAULT 0 COMMENT 'Delete officers',
    
    can_transfer_in TINYINT(1) DEFAULT 1 COMMENT 'Process transfer in',
    can_transfer_out TINYINT(1) DEFAULT 1 COMMENT 'Process transfer out',
    can_remove_officers TINYINT(1) DEFAULT 1 COMMENT 'Remove officers (codes A-D)',
    
    can_view_requests TINYINT(1) DEFAULT 1 COMMENT 'View officer requests',
    can_manage_requests TINYINT(1) DEFAULT 0 COMMENT 'Approve/reject requests',
    
    can_view_reports TINYINT(1) DEFAULT 1 COMMENT 'View reports',
    can_view_headcount TINYINT(1) DEFAULT 1 COMMENT 'View headcount report',
    can_view_departments TINYINT(1) DEFAULT 1 COMMENT 'View department report',
    can_export_reports TINYINT(1) DEFAULT 0 COMMENT 'Export reports to file',
    
    can_view_calendar TINYINT(1) DEFAULT 1 COMMENT 'View calendar',
    can_view_announcements TINYINT(1) DEFAULT 1 COMMENT 'View announcements',
    
    -- Admin Features
    can_manage_users TINYINT(1) DEFAULT 0 COMMENT 'Manage users (admin only)',
    can_manage_announcements TINYINT(1) DEFAULT 0 COMMENT 'Manage announcements (admin only)',
    can_manage_districts TINYINT(1) DEFAULT 0 COMMENT 'Manage districts/locals (admin only)',
    can_view_audit_log TINYINT(1) DEFAULT 0 COMMENT 'View audit log (admin only)',
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Foreign Key
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    
    -- Indexes
    UNIQUE KEY unique_user_permission (user_id),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default permissions for existing admin user
INSERT INTO user_permissions (
    user_id,
    can_view_officers, can_add_officers, can_edit_officers, can_delete_officers,
    can_transfer_in, can_transfer_out, can_remove_officers,
    can_view_requests, can_manage_requests,
    can_view_reports, can_view_headcount, can_view_departments, can_export_reports,
    can_view_calendar, can_view_announcements,
    can_manage_users, can_manage_announcements, can_manage_districts, can_view_audit_log
)
SELECT 
    u.user_id,
    1, 1, 1, 1,
    1, 1, 1,
    1, 1,
    1, 1, 1, 1,
    1, 1,
    1, 1, 1, 1
FROM users u
WHERE u.role = 'admin'
ON DUPLICATE KEY UPDATE 
    can_view_officers = VALUES(can_view_officers);
