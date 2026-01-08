-- Add Local CFO role to the system
-- This role is restricted to CFO registry access only

-- Add the role to users table enum if not already present
ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'district', 'local', 'local_limited', 'local_cfo') NOT NULL DEFAULT 'local';

-- Create permissions for local_cfo role if permissions table exists
INSERT IGNORE INTO permissions (permission_name, permission_description, created_at) 
VALUES 
    ('can_access_cfo_dashboard', 'Can access CFO dashboard', NOW()),
    ('can_manage_cfo_registry', 'Can manage CFO registry within assigned local', NOW());

-- Create role_permissions table if it doesn't exist
CREATE TABLE IF NOT EXISTS role_permissions (
    role_name VARCHAR(50) NOT NULL,
    permission_name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role_name, permission_name)
);

-- Remove existing local_cfo permissions if any
DELETE FROM role_permissions WHERE role_name = 'local_cfo';

-- Add permissions for local_cfo role
INSERT INTO role_permissions (role_name, permission_name) VALUES
('local_cfo', 'can_access_cfo_dashboard'),
('local_cfo', 'can_manage_cfo_registry'),
('local_cfo', 'can_view_reports');

