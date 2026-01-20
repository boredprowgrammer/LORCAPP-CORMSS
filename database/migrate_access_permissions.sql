-- ============================================
-- Migration: Enhanced Access Permission System
-- Updates HDB, PNK, and CFO access request tables
-- to support granular permissions (view, add, edit)
-- with data table access instead of PDF viewing
-- ============================================

-- ============================================
-- 1. Update HDB Access Requests Table
-- ============================================

-- Add request_type column for different permission types
ALTER TABLE hdb_access_requests 
ADD COLUMN IF NOT EXISTS request_type ENUM('view', 'add', 'edit') DEFAULT 'view' 
COMMENT 'Type of access being requested: view data, add records, or edit records';

-- Add dako_id for PNK specific access (null for HDB)
ALTER TABLE hdb_access_requests 
ADD COLUMN IF NOT EXISTS verification_status ENUM('submitted', 'pending_lorc_check', 'verified', 'rejected') DEFAULT 'submitted'
COMMENT 'LORC verification workflow status';

ALTER TABLE hdb_access_requests 
ADD COLUMN IF NOT EXISTS verified_by INT DEFAULT NULL
COMMENT 'User ID of LORC verifier';

ALTER TABLE hdb_access_requests 
ADD COLUMN IF NOT EXISTS verified_at TIMESTAMP NULL DEFAULT NULL
COMMENT 'Timestamp of verification';

-- Add index for verification status
CREATE INDEX IF NOT EXISTS idx_verification_status ON hdb_access_requests(verification_status);

-- ============================================
-- 2. Update PNK Access Requests Table
-- ============================================

-- Add request_type column
ALTER TABLE pnk_access_requests 
ADD COLUMN IF NOT EXISTS request_type ENUM('view', 'add', 'edit') DEFAULT 'view'
COMMENT 'Type of access being requested: view data, add records, or edit records';

-- Add dako_id for dako-specific access
ALTER TABLE pnk_access_requests 
ADD COLUMN IF NOT EXISTS dako_id INT DEFAULT NULL
COMMENT 'Specific dako to access (null = all dakos in local)';

-- Add verification status
ALTER TABLE pnk_access_requests 
ADD COLUMN IF NOT EXISTS verification_status ENUM('submitted', 'pending_lorc_check', 'verified', 'rejected') DEFAULT 'submitted'
COMMENT 'LORC verification workflow status';

ALTER TABLE pnk_access_requests 
ADD COLUMN IF NOT EXISTS verified_by INT DEFAULT NULL
COMMENT 'User ID of LORC verifier';

ALTER TABLE pnk_access_requests 
ADD COLUMN IF NOT EXISTS verified_at TIMESTAMP NULL DEFAULT NULL
COMMENT 'Timestamp of verification';

-- Add indexes
CREATE INDEX IF NOT EXISTS idx_dako ON pnk_access_requests(dako_id);
CREATE INDEX IF NOT EXISTS idx_verification_status ON pnk_access_requests(verification_status);

-- ============================================
-- 3. Update CFO Access Requests Table
-- ============================================

-- Modify cfo_type to include edit/add member options
-- First, we need to add a new column for access mode
ALTER TABLE cfo_access_requests 
ADD COLUMN IF NOT EXISTS access_mode ENUM('view_data', 'add_member', 'edit_member') DEFAULT 'view_data'
COMMENT 'Mode of access: view data tables, add new members, or edit existing members';

-- Add verification status for edit/add requests
ALTER TABLE cfo_access_requests 
ADD COLUMN IF NOT EXISTS verification_status ENUM('submitted', 'pending_lorc_check', 'verified', 'approved', 'rejected') DEFAULT 'submitted'
COMMENT 'LORC verification workflow status';

ALTER TABLE cfo_access_requests 
ADD COLUMN IF NOT EXISTS verified_by INT DEFAULT NULL
COMMENT 'User ID of LORC verifier';

ALTER TABLE cfo_access_requests 
ADD COLUMN IF NOT EXISTS verified_at TIMESTAMP NULL DEFAULT NULL
COMMENT 'Timestamp of verification';

-- Add index for verification status
CREATE INDEX IF NOT EXISTS idx_verification_status ON cfo_access_requests(verification_status);
CREATE INDEX IF NOT EXISTS idx_access_mode ON cfo_access_requests(access_mode);

-- ============================================
-- 4. Create HDB Data Access Table
-- Track which data tables users can access
-- ============================================

CREATE TABLE IF NOT EXISTS hdb_data_access (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    access_request_id INT NOT NULL,
    can_view BOOLEAN DEFAULT FALSE,
    can_add BOOLEAN DEFAULT FALSE,
    can_edit BOOLEAN DEFAULT FALSE,
    granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    granted_by INT NOT NULL,
    expires_at DATE DEFAULT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (access_request_id) REFERENCES hdb_access_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (granted_by) REFERENCES users(user_id) ON DELETE SET NULL,
    
    INDEX idx_user (user_id),
    INDEX idx_active (is_active),
    UNIQUE KEY unique_user_request (user_id, access_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Track granular data access permissions for HDB registry';

-- ============================================
-- 5. Create PNK Data Access Table
-- Track which data tables and dakos users can access
-- ============================================

CREATE TABLE IF NOT EXISTS pnk_data_access (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    access_request_id INT NOT NULL,
    dako_id INT DEFAULT NULL COMMENT 'Null means all dakos in local',
    can_view BOOLEAN DEFAULT FALSE,
    can_add BOOLEAN DEFAULT FALSE,
    can_edit BOOLEAN DEFAULT FALSE,
    granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    granted_by INT NOT NULL,
    expires_at DATE DEFAULT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (access_request_id) REFERENCES pnk_access_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (dako_id) REFERENCES pnk_dako(id) ON DELETE SET NULL,
    FOREIGN KEY (granted_by) REFERENCES users(user_id) ON DELETE SET NULL,
    
    INDEX idx_user (user_id),
    INDEX idx_dako (dako_id),
    INDEX idx_active (is_active),
    UNIQUE KEY unique_user_request_dako (user_id, access_request_id, dako_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Track granular data access permissions for PNK registry with dako support';

-- ============================================
-- 6. Create CFO Data Access Table
-- Track data table access for CFO (Buklod, Kadiwa, Binhi)
-- ============================================

CREATE TABLE IF NOT EXISTS cfo_data_access (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    access_request_id INT NOT NULL,
    cfo_type ENUM('Buklod', 'Kadiwa', 'Binhi') NOT NULL,
    can_view BOOLEAN DEFAULT FALSE,
    can_add BOOLEAN DEFAULT FALSE,
    can_edit BOOLEAN DEFAULT FALSE,
    granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    granted_by INT NOT NULL,
    expires_at DATE DEFAULT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (access_request_id) REFERENCES cfo_access_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (granted_by) REFERENCES users(user_id) ON DELETE SET NULL,
    
    INDEX idx_user (user_id),
    INDEX idx_cfo_type (cfo_type),
    INDEX idx_active (is_active),
    UNIQUE KEY unique_user_request_type (user_id, access_request_id, cfo_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Track granular data access permissions for CFO registry (Buklod, Kadiwa, Binhi)';

-- ============================================
-- 7. Create Pending Verifications Table
-- Track records pending LORC verification
-- ============================================

CREATE TABLE IF NOT EXISTS pending_verifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    registry_type ENUM('hdb', 'pnk', 'cfo') NOT NULL,
    record_id INT NOT NULL COMMENT 'ID of the record in respective registry',
    action_type ENUM('add', 'edit') NOT NULL,
    submitted_by INT NOT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Original data (for edit comparison)
    original_data JSON DEFAULT NULL COMMENT 'Original record data before edit',
    
    -- New/Updated data
    new_data JSON NOT NULL COMMENT 'New or updated record data',
    
    -- Verification workflow
    verification_status ENUM('submitted', 'pending_lorc_check', 'verified', 'rejected') DEFAULT 'submitted',
    verified_by INT DEFAULT NULL,
    verified_at TIMESTAMP NULL,
    rejection_reason TEXT DEFAULT NULL,
    
    -- For CFO add member
    cfo_member_id INT DEFAULT NULL,
    
    -- Tracking
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (submitted_by) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(user_id) ON DELETE SET NULL,
    
    INDEX idx_registry_type (registry_type),
    INDEX idx_record (record_id),
    INDEX idx_status (verification_status),
    INDEX idx_submitted_by (submitted_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Track pending add/edit verifications across all registries';
