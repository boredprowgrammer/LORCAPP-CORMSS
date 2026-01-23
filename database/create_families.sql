-- INC Family Registry Tables
-- Run this migration to create the family registry structure

-- Families table (Sambahayan)
CREATE TABLE IF NOT EXISTS families (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- Head of Family (Pangulo ng Sambahayan) - links to tarheta_control
    head_member_id INT NOT NULL,
    head_source ENUM('tarheta', 'hdb', 'pnk') DEFAULT 'tarheta',
    
    -- Location info (inherited from head)
    district_code VARCHAR(20) NOT NULL,
    local_code VARCHAR(20) NOT NULL,
    purok VARCHAR(50) DEFAULT NULL,
    grupo VARCHAR(50) DEFAULT NULL,
    
    -- Family metadata
    family_name_encrypted TEXT NOT NULL, -- Encrypted family surname
    address_encrypted TEXT DEFAULT NULL, -- Optional encrypted address
    contact_encrypted TEXT DEFAULT NULL, -- Optional encrypted contact number
    
    -- Status
    status ENUM('active', 'inactive', 'transferred') DEFAULT 'active',
    notes TEXT DEFAULT NULL,
    
    -- Audit fields
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_by INT DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME DEFAULT NULL,
    
    -- Indexes
    INDEX idx_head_member (head_member_id),
    INDEX idx_district (district_code),
    INDEX idx_local (local_code),
    INDEX idx_status (status),
    INDEX idx_purok_grupo (purok, grupo),
    
    -- Foreign keys
    FOREIGN KEY (district_code) REFERENCES districts(district_code),
    FOREIGN KEY (local_code) REFERENCES local_congregations(local_code),
    FOREIGN KEY (created_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Family Members table (Kaanib ng Sambahayan)
CREATE TABLE IF NOT EXISTS family_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- Link to family
    family_id INT NOT NULL,
    
    -- Link to source record (member from tarheta, hdb, or pnk)
    source_type ENUM('tarheta', 'hdb', 'pnk', 'manual') NOT NULL,
    source_id INT DEFAULT NULL, -- ID from source table, NULL if manual entry
    
    -- Member info (cached/encrypted for display)
    name_encrypted TEXT NOT NULL,
    birthday_encrypted TEXT DEFAULT NULL,
    
    -- Relationship to head
    relationship ENUM('asawa', 'anak', 'pamangkin', 'apo', 'magulang', 'kapatid', 'indibidwal', 'others') NOT NULL,
    relationship_specify VARCHAR(100) DEFAULT NULL, -- For 'others'
    
    -- Organization/Classification
    kapisanan ENUM('Buklod', 'Kadiwa', 'Binhi', 'PNK', 'HDB') DEFAULT NULL,
    
    -- Order in family (for display)
    sort_order INT DEFAULT 0,
    
    -- Status
    is_head BOOLEAN DEFAULT FALSE,
    status ENUM('active', 'deceased', 'transferred', 'removed') DEFAULT 'active',
    notes TEXT DEFAULT NULL,
    
    -- Audit fields
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes
    INDEX idx_family (family_id),
    INDEX idx_source (source_type, source_id),
    INDEX idx_relationship (relationship),
    INDEX idx_kapisanan (kapisanan),
    INDEX idx_is_head (is_head),
    
    -- Foreign key
    FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- View for family statistics
CREATE OR REPLACE VIEW family_stats AS
SELECT 
    f.id as family_id,
    f.district_code,
    f.local_code,
    f.status,
    COUNT(fm.id) as total_members,
    SUM(CASE WHEN fm.kapisanan = 'Buklod' THEN 1 ELSE 0 END) as buklod_count,
    SUM(CASE WHEN fm.kapisanan = 'Kadiwa' THEN 1 ELSE 0 END) as kadiwa_count,
    SUM(CASE WHEN fm.kapisanan = 'Binhi' THEN 1 ELSE 0 END) as binhi_count,
    SUM(CASE WHEN fm.kapisanan = 'PNK' THEN 1 ELSE 0 END) as pnk_count,
    SUM(CASE WHEN fm.kapisanan = 'HDB' THEN 1 ELSE 0 END) as hdb_count
FROM families f
LEFT JOIN family_members fm ON f.id = fm.family_id AND fm.status = 'active'
WHERE f.deleted_at IS NULL
GROUP BY f.id, f.district_code, f.local_code, f.status;
