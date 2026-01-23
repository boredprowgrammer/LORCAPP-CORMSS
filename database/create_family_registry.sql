-- Family Registry Database Schema
-- Run this migration to create the family registry tables

-- Families table (Sambahayan)
CREATE TABLE IF NOT EXISTS families (
    id INT AUTO_INCREMENT PRIMARY KEY,
    family_code VARCHAR(50) UNIQUE NOT NULL,
    pangulo_id INT NOT NULL COMMENT 'Reference to tarheta_control.id for head of household',
    district_code VARCHAR(20) NOT NULL,
    local_code VARCHAR(20) NOT NULL,
    purok VARCHAR(50) DEFAULT NULL,
    grupo VARCHAR(50) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    contact_number VARCHAR(50) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    status ENUM('active', 'inactive', 'transferred') DEFAULT 'active',
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by INT DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    
    INDEX idx_family_code (family_code),
    INDEX idx_pangulo (pangulo_id),
    INDEX idx_district (district_code),
    INDEX idx_local (local_code),
    INDEX idx_status (status),
    
    FOREIGN KEY (district_code) REFERENCES districts(district_code) ON UPDATE CASCADE,
    FOREIGN KEY (local_code) REFERENCES local_congregations(local_code) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Family Members table (Kaanib ng Sambahayan)
CREATE TABLE IF NOT EXISTS family_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    family_id INT NOT NULL,
    member_type ENUM('pangulo', 'kaanib') NOT NULL DEFAULT 'kaanib',
    
    -- Reference to existing registries (only one should be set)
    tarheta_id INT DEFAULT NULL COMMENT 'Reference to tarheta_control.id',
    hdb_id INT DEFAULT NULL COMMENT 'Reference to hdb_registry.id',
    pnk_id INT DEFAULT NULL COMMENT 'Reference to pnk_registry.id (if exists)',
    
    -- If member not in any registry, store encrypted info
    first_name_encrypted TEXT DEFAULT NULL,
    middle_name_encrypted TEXT DEFAULT NULL,
    last_name_encrypted TEXT DEFAULT NULL,
    birthday_encrypted TEXT DEFAULT NULL,
    
    -- Relationship to head of household
    relasyon ENUM('Pangulo', 'Asawa', 'Anak', 'Pamangkin', 'Apo', 'Magulang', 'Kapatid', 'Indibidwal', 'Iba pa') NOT NULL,
    relasyon_specify VARCHAR(100) DEFAULT NULL COMMENT 'If relasyon is Iba pa',
    
    -- CFO/Organization classification
    kapisanan ENUM('Buklod', 'Kadiwa', 'Binhi', 'PNK', 'HDB', 'None') DEFAULT NULL,
    
    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    notes TEXT DEFAULT NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_family (family_id),
    INDEX idx_tarheta (tarheta_id),
    INDEX idx_hdb (hdb_id),
    INDEX idx_kapisanan (kapisanan),
    INDEX idx_relasyon (relasyon),
    
    FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- View for family summary
CREATE OR REPLACE VIEW family_summary AS
SELECT 
    f.id,
    f.family_code,
    f.pangulo_id,
    f.district_code,
    f.local_code,
    f.purok,
    f.grupo,
    f.status,
    f.created_at,
    d.district_name,
    lc.local_name,
    (SELECT COUNT(*) FROM family_members fm WHERE fm.family_id = f.id AND fm.is_active = 1) as member_count,
    (SELECT COUNT(*) FROM family_members fm WHERE fm.family_id = f.id AND fm.kapisanan = 'Buklod' AND fm.is_active = 1) as buklod_count,
    (SELECT COUNT(*) FROM family_members fm WHERE fm.family_id = f.id AND fm.kapisanan = 'Kadiwa' AND fm.is_active = 1) as kadiwa_count,
    (SELECT COUNT(*) FROM family_members fm WHERE fm.family_id = f.id AND fm.kapisanan = 'Binhi' AND fm.is_active = 1) as binhi_count,
    (SELECT COUNT(*) FROM family_members fm WHERE fm.family_id = f.id AND fm.kapisanan = 'PNK' AND fm.is_active = 1) as pnk_count,
    (SELECT COUNT(*) FROM family_members fm WHERE fm.family_id = f.id AND fm.kapisanan = 'HDB' AND fm.is_active = 1) as hdb_count
FROM families f
LEFT JOIN districts d ON f.district_code = d.district_code
LEFT JOIN local_congregations lc ON f.local_code = lc.local_code
WHERE f.deleted_at IS NULL;
