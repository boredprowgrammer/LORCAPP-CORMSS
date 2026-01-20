-- ============================================
-- HDB Registry (Handog Di Bautisado - Unbaptized Children Registry)
-- Separate database for tracking unbaptized children
-- ============================================

CREATE TABLE IF NOT EXISTS hdb_registry (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- Location Information
    district_code VARCHAR(10) NOT NULL,
    local_code VARCHAR(10) NOT NULL,
    
    -- Child's Personal Information (Encrypted)
    child_first_name_encrypted TEXT NOT NULL COMMENT 'Encrypted first name of child',
    child_middle_name_encrypted TEXT COMMENT 'Encrypted middle name of child',
    child_last_name_encrypted TEXT NOT NULL COMMENT 'Encrypted last name of child',
    child_birthday_encrypted TEXT COMMENT 'Encrypted birthday',
    child_birthplace_encrypted TEXT COMMENT 'Encrypted birthplace',
    child_sex ENUM('Male', 'Female') NOT NULL,
    
    -- Parent Information (Encrypted)
    father_first_name_encrypted TEXT COMMENT 'Encrypted father first name',
    father_middle_name_encrypted TEXT COMMENT 'Encrypted father middle name',
    father_last_name_encrypted TEXT COMMENT 'Encrypted father last name',
    mother_first_name_encrypted TEXT COMMENT 'Encrypted mother first name',
    mother_middle_name_encrypted TEXT COMMENT 'Encrypted mother middle name',
    mother_maiden_name_encrypted TEXT COMMENT 'Encrypted mother maiden name',
    mother_married_name_encrypted TEXT COMMENT 'Encrypted mother married last name',
    parent_address_encrypted TEXT COMMENT 'Encrypted parent address',
    parent_contact_encrypted TEXT COMMENT 'Encrypted parent contact number',
    
    -- Registry Information
    registry_number VARCHAR(100) NOT NULL COMMENT 'HDB registry number',
    registry_number_hash VARCHAR(255) NOT NULL UNIQUE COMMENT 'SHA-256 hash of registry number for uniqueness',
    registration_date DATE NOT NULL COMMENT 'Date of registration',
    
    -- Classification & Status
    dedication_status ENUM('active', 'pnk', 'baptized', 'transferred-out') DEFAULT 'active',
    dedication_date DATE COMMENT 'Date of dedication ceremony',
    baptism_date DATE COMMENT 'Date of baptism (if baptized)',
    
    -- Transfer Information
    transfer_to VARCHAR(100) COMMENT 'Local transferred to',
    transfer_to_district VARCHAR(100) COMMENT 'District transferred to',
    transfer_from VARCHAR(100) COMMENT 'Local transferred from',
    transfer_from_district VARCHAR(100) COMMENT 'District transferred from',
    transfer_date DATE COMMENT 'Date of transfer',
    transfer_reason TEXT COMMENT 'Reason for transfer',
    
    -- Additional Information
    notes TEXT COMMENT 'Additional notes',
    purok_grupo VARCHAR(100) COMMENT 'Purok or Grupo assignment',
    
    -- Tracking
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NOT NULL COMMENT 'User who created record',
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT COMMENT 'User who last updated record',
    deleted_at TIMESTAMP NULL COMMENT 'Soft delete timestamp',
    
    -- Indexes for performance
    INDEX idx_district (district_code),
    INDEX idx_local (local_code),
    INDEX idx_registry_number_hash (registry_number_hash),
    INDEX idx_dedication_status (dedication_status),
    INDEX idx_registration_date (registration_date),
    INDEX idx_created_at (created_at),
    
    -- Foreign Keys
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE RESTRICT,
    FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='HDB Registry - Handog Di Bautisado (Unbaptized Children Registry)';

-- ============================================
-- HDB Access Control Table
-- Track who can access HDB registry data
-- ============================================

CREATE TABLE IF NOT EXISTS hdb_access_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    requester_user_id INT NOT NULL,
    requester_local_code VARCHAR(10) NOT NULL,
    request_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Approval Information
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    approved_by INT COMMENT 'User ID of approver',
    approval_date TIMESTAMP NULL,
    rejection_reason TEXT,
    
    -- Access Control
    access_type ENUM('view', 'edit', 'admin') DEFAULT 'view',
    is_locked BOOLEAN DEFAULT FALSE COMMENT 'Lock access (temporary suspension)',
    valid_until DATE COMMENT 'Access expiration date',
    
    -- Password Protection
    password_hash VARCHAR(255) NOT NULL COMMENT 'Password for HDB access',
    
    -- Tracking
    deleted_at TIMESTAMP NULL,
    
    INDEX idx_requester (requester_user_id),
    INDEX idx_status (status),
    INDEX idx_local (requester_local_code),
    
    FOREIGN KEY (requester_user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='HDB Access Control - Manage access requests to HDB registry';

-- ============================================
-- HDB Activity Log
-- Track all activities in HDB registry
-- ============================================

CREATE TABLE IF NOT EXISTS hdb_activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hdb_record_id INT COMMENT 'Reference to HDB record',
    user_id INT NOT NULL,
    action VARCHAR(100) NOT NULL COMMENT 'Action performed (create, update, delete, view, export)',
    details TEXT COMMENT 'Additional details in JSON format',
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_hdb_record (hdb_record_id),
    INDEX idx_user (user_id),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at),
    
    FOREIGN KEY (hdb_record_id) REFERENCES hdb_registry(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Activity log for HDB Registry operations';

-- ============================================
-- Notes:
-- - All personal information is encrypted using district keys
-- - Registry numbers must be unique (enforced by hash)
-- - Tracks progression from registration -> dedication -> baptism
-- - Separate access control system similar to CFO registry
-- - Comprehensive activity logging for auditing
-- ============================================
