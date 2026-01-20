-- Add permissions for CFO Registry, HDB, and PNK access
-- These permissions control access to the CFO-related features

-- Add can_access_cfo_registry permission
ALTER TABLE user_permissions
ADD COLUMN can_access_cfo_registry TINYINT(1) DEFAULT 0 COMMENT 'Access CFO Registry app' 
AFTER can_view_legacy_registry;

-- Add can_access_hdb permission
ALTER TABLE user_permissions
ADD COLUMN can_access_hdb TINYINT(1) DEFAULT 0 COMMENT 'Access HDB (Handog Bukas) registry' 
AFTER can_access_cfo_registry;

-- Add can_access_pnk permission
ALTER TABLE user_permissions
ADD COLUMN can_access_pnk TINYINT(1) DEFAULT 0 COMMENT 'Access PNK (Pagpapala ng Kristiano) registry' 
AFTER can_access_hdb;

-- Grant all CFO permissions to admin users
UPDATE user_permissions up
JOIN users u ON up.user_id = u.user_id
SET up.can_access_cfo_registry = 1,
    up.can_access_hdb = 1,
    up.can_access_pnk = 1
WHERE u.role = 'admin';

-- Grant CFO registry access to local users (they manage their local CFO)
UPDATE user_permissions up
JOIN users u ON up.user_id = u.user_id
SET up.can_access_cfo_registry = 1,
    up.can_access_hdb = 1,
    up.can_access_pnk = 1
WHERE u.role = 'local';

-- Grant CFO registry access to local_cfo users (CFO-only access)
UPDATE user_permissions up
JOIN users u ON up.user_id = u.user_id
SET up.can_access_cfo_registry = 1,
    up.can_access_hdb = 1,
    up.can_access_pnk = 1
WHERE u.role = 'local_cfo';

-- Add indexes for performance
ALTER TABLE user_permissions
ADD INDEX idx_cfo_registry (can_access_cfo_registry),
ADD INDEX idx_hdb (can_access_hdb),
ADD INDEX idx_pnk (can_access_pnk);
