-- ============================================
-- Add Purok and Grupo columns to CFO Registry
-- Date: January 9, 2026
-- ============================================

USE church_officers_db;

-- Add purok and grupo columns to tarheta_control
ALTER TABLE tarheta_control
ADD COLUMN purok VARCHAR(50) NULL COMMENT 'Purok number' AFTER cfo_classification,
ADD COLUMN grupo VARCHAR(50) NULL COMMENT 'Grupo number' AFTER purok,
ADD INDEX idx_purok (purok),
ADD INDEX idx_grupo (grupo);

-- ============================================
-- Notes:
-- - Format in CSV: "1-7" (Purok-Grupo)
-- - Stored separately as purok="1", grupo="7"
-- - Display format: "1-7" or "Purok 1, Grupo 7"
-- ============================================
