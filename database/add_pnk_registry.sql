-- ============================================
-- PNK Registry (Pagsamba ng Kabataan - Youth Worship Registry)
-- Separate database for tracking youth worship participants
-- ============================================

CREATE TABLE IF NOT EXISTS pnk_registry (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- Location Information
    district_code VARCHAR(10) NOT NULL,
    local_code VARCHAR(10) NOT NULL,
    
    -- Member Personal Information (Encrypted)
    first_name_encrypted TEXT NOT NULL COMMENT 'Encrypted first name',
    middle_name_encrypted TEXT COMMENT 'Encrypted middle name',
    last_name_encrypted TEXT NOT NULL COMMENT 'Encrypted last name',
    birthday_encrypted TEXT NOT NULL COMMENT 'Encrypted birthday',
    birthplace_encrypted TEXT COMMENT 'Encrypted birthplace',
    sex ENUM('Male', 'Female') NOT NULL,
    
    -- Contact Information (Encrypted)
    address_encrypted TEXT COMMENT 'Encrypted residential address',
    contact_number_encrypted TEXT COMMENT 'Encrypted contact number',
    
    -- Parent/Guardian Information (Encrypted)
    father_first_name_encrypted TEXT COMMENT 'Encrypted father first name',
    father_middle_name_encrypted TEXT COMMENT 'Encrypted father middle name',
    father_last_name_encrypted TEXT COMMENT 'Encrypted father last name',
    mother_first_name_encrypted TEXT COMMENT 'Encrypted mother first name',
    mother_middle_name_encrypted TEXT COMMENT 'Encrypted mother middle name',
    mother_maiden_name_encrypted TEXT COMMENT 'Encrypted mother maiden name',
    mother_married_name_encrypted TEXT COMMENT 'Encrypted mother married last name',
    guardian_name_encrypted TEXT COMMENT 'Encrypted guardian name (if not parent)',
    
    -- Registry Information
    registry_number VARCHAR(100) NOT NULL COMMENT 'PNK registry number',
    registry_number_hash VARCHAR(255) NOT NULL UNIQUE COMMENT 'SHA-256 hash of registry number for uniqueness',
    registration_date DATE NOT NULL COMMENT 'Date of registration in PNK',
    
    -- PNK-Specific Information
    pnk_category ENUM('Preteen', 'Teen', 'Young Adult') NOT NULL COMMENT 'Age category in PNK',
    baptism_status ENUM('active', 'r301', 'baptized', 'transferred-out') DEFAULT 'active',
    baptism_date DATE COMMENT 'Date of baptism',
    dako_encrypted TEXT COMMENT 'Encrypted Dako (chapter/group) where PNK is registered',
    
    -- Participation & Involvement
    attendance_status ENUM('active', 'inactive', 'transferred-out', 'baptized') DEFAULT 'active',
    purok_grupo VARCHAR(100) COMMENT 'Purok or Grupo assignment',
    department_assignment VARCHAR(100) COMMENT 'Department or ministry assignment',
    officer_position VARCHAR(100) COMMENT 'If assigned as officer/leader',
    
    -- Educational/Formation Tracking
    bible_study_level VARCHAR(50) COMMENT 'Current bible study level',
    seminars_attended TEXT COMMENT 'JSON array of seminars attended',
    awards_recognitions TEXT COMMENT 'JSON array of awards/recognitions',
    
    -- Transfer Information
    transfer_type ENUM('transfer-in', 'transfer-out', 'graduated', 'promoted') COMMENT 'Type of transfer',
    transfer_from VARCHAR(100) COMMENT 'Previous local/organization',
    transfer_from_district VARCHAR(100) COMMENT 'Previous district',
    transfer_to VARCHAR(100) COMMENT 'Destination local/organization',
    transfer_to_district VARCHAR(100) COMMENT 'Destination district',
    transfer_date DATE COMMENT 'Date of transfer',
    transfer_reason TEXT COMMENT 'Reason for transfer',
    
    -- Additional Information
    notes TEXT COMMENT 'Additional notes',
    emergency_contact_encrypted TEXT COMMENT 'Encrypted emergency contact',
    
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
    INDEX idx_pnk_category (pnk_category),
    INDEX idx_attendance_status (attendance_status),
    INDEX idx_registration_date (registration_date),
    INDEX idx_created_at (created_at),
    
    -- Foreign Keys
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE RESTRICT,
    FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='PNK Registry - Pagsamba ng Kabataan (Youth Worship Registry)';

-- ============================================
-- PNK Access Control Table
-- Track who can access PNK registry data
-- ============================================

CREATE TABLE IF NOT EXISTS pnk_access_requests (
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
    password_hash VARCHAR(255) NOT NULL COMMENT 'Password for PNK access',
    
    -- Tracking
    deleted_at TIMESTAMP NULL,
    
    INDEX idx_requester (requester_user_id),
    INDEX idx_status (status),
    INDEX idx_local (requester_local_code),
    
    FOREIGN KEY (requester_user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='PNK Access Control - Manage access requests to PNK registry';

-- ============================================
-- PNK Activity Log
-- Track all activities in PNK registry
-- ============================================

CREATE TABLE IF NOT EXISTS pnk_activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pnk_record_id INT COMMENT 'Reference to PNK record',
    user_id INT NOT NULL,
    action VARCHAR(100) NOT NULL COMMENT 'Action performed (create, update, delete, view, export)',
    details TEXT COMMENT 'Additional details in JSON format',
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_pnk_record (pnk_record_id),
    INDEX idx_user (user_id),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at),
    
    FOREIGN KEY (pnk_record_id) REFERENCES pnk_registry(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Activity log for PNK Registry operations';

-- ============================================
-- PNK Events/Activities Table
-- Track PNK-specific events and activities
-- ============================================

CREATE TABLE IF NOT EXISTS pnk_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    district_code VARCHAR(10) NOT NULL,
    local_code VARCHAR(10) NOT NULL,
    
    event_name VARCHAR(255) NOT NULL,
    event_type ENUM('worship', 'seminar', 'fellowship', 'outreach', 'sports', 'training', 'other') NOT NULL,
    event_date DATE NOT NULL,
    event_time TIME,
    event_location VARCHAR(255),
    event_description TEXT,
    
    -- Participation Tracking
    total_participants INT DEFAULT 0,
    participant_ids TEXT COMMENT 'JSON array of PNK registry IDs who participated',
    
    -- Tracking
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NOT NULL,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT,
    
    INDEX idx_district (district_code),
    INDEX idx_local (local_code),
    INDEX idx_event_date (event_date),
    INDEX idx_event_type (event_type),
    
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE RESTRICT,
    FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='PNK Events and Activities tracking';

-- ============================================
-- Notes:
-- - All personal information is encrypted using district keys
-- - Registry numbers must be unique (enforced by hash)
-- - Tracks youth from enrollment through graduation/transfer
-- - Separate access control system
-- - Comprehensive activity and event logging
-- - Age categories: Preteen (10-12), Teen (13-17), Young Adult (18-24)
-- ============================================
