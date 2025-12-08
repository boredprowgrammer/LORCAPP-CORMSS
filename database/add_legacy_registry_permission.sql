-- Add permission to view Legacy Registry (LORCAPP)
-- This permission controls access to the LORCAPP/legacy registry system

ALTER TABLE user_permissions
ADD COLUMN can_view_legacy_registry TINYINT(1) DEFAULT 0 COMMENT 'View Legacy Registry/LORCAPP (admin/authorized users only)' 
AFTER can_view_audit_log;

-- Grant permission to all admin users by default
UPDATE user_permissions up
JOIN users u ON up.user_id = u.user_id
SET up.can_view_legacy_registry = 1
WHERE u.role = 'admin';

-- Add index for performance
ALTER TABLE user_permissions
ADD INDEX idx_legacy_registry (can_view_legacy_registry);
