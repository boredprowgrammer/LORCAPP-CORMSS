-- ============================================
-- Update HDB Registry - Separate Parent Names
-- Split father_name and mother_name into individual fields
-- ============================================

-- Add new father columns (errors will be ignored if they already exist)
ALTER TABLE hdb_registry ADD COLUMN father_first_name_encrypted TEXT COMMENT 'Encrypted father first name' AFTER child_sex;
ALTER TABLE hdb_registry ADD COLUMN father_middle_name_encrypted TEXT COMMENT 'Encrypted father middle name' AFTER father_first_name_encrypted;
ALTER TABLE hdb_registry ADD COLUMN father_last_name_encrypted TEXT COMMENT 'Encrypted father last name' AFTER father_middle_name_encrypted;

-- Add new mother columns
ALTER TABLE hdb_registry ADD COLUMN mother_first_name_encrypted TEXT COMMENT 'Encrypted mother first name' AFTER father_last_name_encrypted;
ALTER TABLE hdb_registry ADD COLUMN mother_middle_name_encrypted TEXT COMMENT 'Encrypted mother middle name' AFTER mother_first_name_encrypted;
ALTER TABLE hdb_registry ADD COLUMN mother_maiden_name_encrypted TEXT COMMENT 'Encrypted mother maiden name' AFTER mother_middle_name_encrypted;
ALTER TABLE hdb_registry ADD COLUMN mother_married_name_encrypted TEXT COMMENT 'Encrypted mother married last name' AFTER mother_maiden_name_encrypted;
