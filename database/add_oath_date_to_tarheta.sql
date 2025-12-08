-- ============================================
-- Add oath_date column to tarheta_control table
-- ============================================

ALTER TABLE tarheta_control
ADD COLUMN oath_date DATE NULL COMMENT 'Date when officer took oath' AFTER registry_number_hash;

-- Add index for searching by oath date
CREATE INDEX idx_oath_date ON tarheta_control(oath_date);
