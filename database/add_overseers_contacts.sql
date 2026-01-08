-- Group and Area Overseers Contact Registry
-- Stores contacts for Grupo and Purok level overseers with encrypted names

CREATE TABLE IF NOT EXISTS overseers_contacts (
    contact_id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- Tipo ng Level: 'grupo' o 'purok'
    contact_type ENUM('grupo', 'purok') NOT NULL,
    
    -- Geographic reference
    district_code VARCHAR(20) NOT NULL,
    local_code VARCHAR(20) NOT NULL,
    purok_grupo VARCHAR(100) NULL, -- For grupo level, name of the purok grupo
    purok VARCHAR(100) NULL, -- For purok level, name of the purok
    
    -- Officer positions (encrypted officer IDs, multiple per position using JSON array)
    katiwala_officer_ids TEXT NULL COMMENT 'Encrypted JSON array of officer IDs for Katiwala',
    ii_katiwala_officer_ids TEXT NULL COMMENT 'Encrypted JSON array of officer IDs for II Katiwala',
    kalihim_officer_ids TEXT NULL COMMENT 'Encrypted JSON array of officer IDs for Kalihim',
    
    -- Contact Information
    katiwala_contact VARCHAR(255) NULL,
    katiwala_telegram VARCHAR(255) NULL,
    ii_katiwala_contact VARCHAR(255) NULL,
    ii_katiwala_telegram VARCHAR(255) NULL,
    kalihim_contact VARCHAR(255) NULL,
    kalihim_telegram VARCHAR(255) NULL,
    
    -- Metadata
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT NOT NULL,
    updated_by INT NULL,
    
    -- Indexes
    INDEX idx_type_district_local (contact_type, district_code, local_code),
    INDEX idx_purok_grupo (purok_grupo),
    INDEX idx_purok (purok),
    INDEX idx_active (is_active),
    
    -- Foreign keys
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE RESTRICT,
    FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Group and Area Overseers Contact Registry';

-- Audit log for overseers contacts
CREATE TABLE IF NOT EXISTS overseers_contacts_audit (
    audit_id INT AUTO_INCREMENT PRIMARY KEY,
    contact_id INT NOT NULL,
    action ENUM('create', 'update', 'delete', 'view') NOT NULL,
    old_values JSON NULL,
    new_values JSON NULL,
    changed_by INT NOT NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    
    INDEX idx_contact_id (contact_id),
    INDEX idx_changed_at (changed_at),
    FOREIGN KEY (changed_by) REFERENCES users(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
