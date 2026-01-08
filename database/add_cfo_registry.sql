-- ============================================
-- CFO Registry (Christian Family Organization)
-- Add columns to tarheta_control for CFO management
-- ============================================

-- Add birthday and CFO classification columns to tarheta_control
ALTER TABLE tarheta_control
ADD COLUMN birthday_encrypted TEXT COMMENT 'Encrypted birthday',
ADD COLUMN cfo_classification ENUM('Buklod', 'Kadiwa', 'Binhi') DEFAULT NULL COMMENT 'CFO classification',
ADD COLUMN cfo_status ENUM('active', 'transferred-out') DEFAULT 'active' COMMENT 'CFO membership status',
ADD COLUMN cfo_classification_auto BOOLEAN DEFAULT FALSE COMMENT 'Whether classification was auto-generated',
ADD COLUMN cfo_notes TEXT COMMENT 'Additional notes about CFO member',
ADD COLUMN cfo_updated_at TIMESTAMP NULL COMMENT 'When CFO information was last updated',
ADD COLUMN cfo_updated_by INT NULL COMMENT 'User who last updated CFO information',
ADD INDEX idx_cfo_classification (cfo_classification),
ADD INDEX idx_cfo_status (cfo_status);

-- Add foreign key for cfo_updated_by
ALTER TABLE tarheta_control
ADD CONSTRAINT fk_cfo_updated_by FOREIGN KEY (cfo_updated_by) REFERENCES users(user_id) ON DELETE SET NULL;

-- ============================================
-- Notes:
-- - Birthday is encrypted for privacy
-- - CFO Classification logic:
--   * Buklod: If husband surname is available (married couples)
--   * Kadiwa: Youth organization
--   * Binhi: Children's organization
-- - Auto classification happens on import but can be manually overridden
-- - Status tracks active vs transferred-out members
-- ============================================
