-- ============================================
-- Tarheta Control Table
-- Stores legacy registry records for linking
-- ============================================

CREATE TABLE IF NOT EXISTS tarheta_control (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- Encrypted personal information
    last_name_encrypted TEXT NOT NULL COMMENT 'Encrypted last name',
    first_name_encrypted TEXT NOT NULL COMMENT 'Encrypted first name',
    middle_name_encrypted TEXT COMMENT 'Encrypted middle name',
    husbands_surname_encrypted TEXT COMMENT 'Encrypted husband surname (for married women)',
    
    -- Registry information (encrypted)
    registry_number_encrypted TEXT NOT NULL COMMENT 'Encrypted registry/control number',
    registry_number_hash VARCHAR(64) NOT NULL COMMENT 'Hash for quick searching',
    
    -- Location information
    district_code VARCHAR(20) NOT NULL,
    local_code VARCHAR(20) NOT NULL,
    
    -- Metadata
    import_batch VARCHAR(100) COMMENT 'CSV import batch identifier',
    imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    imported_by INT COMMENT 'User who imported the record',
    
    -- Linking information
    linked_officer_id INT NULL COMMENT 'FK to officers table if linked',
    linked_at TIMESTAMP NULL COMMENT 'When record was linked to an officer',
    linked_by INT NULL COMMENT 'User who linked the record',
    
    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes
    INDEX idx_district (district_code),
    INDEX idx_local (local_code),
    INDEX idx_registry_hash (registry_number_hash),
    INDEX idx_linked_officer (linked_officer_id),
    INDEX idx_import_batch (import_batch),
    
    -- Foreign keys
    FOREIGN KEY (district_code) REFERENCES districts(district_code) ON DELETE RESTRICT,
    FOREIGN KEY (local_code) REFERENCES local_congregations(local_code) ON DELETE RESTRICT,
    FOREIGN KEY (linked_officer_id) REFERENCES officers(officer_id) ON DELETE SET NULL,
    FOREIGN KEY (imported_by) REFERENCES users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (linked_by) REFERENCES users(user_id) ON DELETE SET NULL
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
