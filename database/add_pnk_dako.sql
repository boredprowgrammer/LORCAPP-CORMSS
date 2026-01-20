-- ============================================
-- PNK Dako Table
-- Stores Dako (chapter/group) entries for PNK registry
-- ============================================

CREATE TABLE IF NOT EXISTS pnk_dako (
    id INT AUTO_INCREMENT PRIMARY KEY,
    district_code VARCHAR(10) NOT NULL,
    local_code VARCHAR(10) NOT NULL,
    dako_name VARCHAR(100) NOT NULL COMMENT 'Name of the Dako (chapter/group)',
    description TEXT COMMENT 'Description of the Dako',
    leader_name VARCHAR(200) COMMENT 'Leader or overseer name',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NOT NULL,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_district_local (district_code, local_code),
    INDEX idx_dako_name (dako_name),
    INDEX idx_is_active (is_active),
    
    UNIQUE KEY unique_dako_per_local (district_code, local_code, dako_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='PNK Dako (Chapter/Group) Registry';
