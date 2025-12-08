-- Legacy Officers Control Number Table
-- For linking officers to their legacy control numbers

CREATE TABLE IF NOT EXISTS legacy_officers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- Encrypted name and control number
    name_encrypted TEXT NOT NULL,
    control_number_encrypted TEXT NOT NULL,
    control_number_hash VARCHAR(64) NOT NULL, -- For fast duplicate detection
    
    -- Organization details (for encryption key)
    district_code VARCHAR(10) NOT NULL,
    local_code VARCHAR(10) NOT NULL,
    
    -- Linking to current officer record
    linked_officer_id INT NULL,
    linked_at TIMESTAMP NULL,
    
    -- Import tracking
    import_batch VARCHAR(50) NULL,
    imported_by INT NULL,
    imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Indexes
    INDEX idx_control_number_hash (control_number_hash),
    INDEX idx_district_local (district_code, local_code),
    INDEX idx_linked_officer (linked_officer_id),
    
    FOREIGN KEY (linked_officer_id) REFERENCES officers(officer_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
