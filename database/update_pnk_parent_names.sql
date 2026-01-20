-- ============================================
-- Update PNK Registry - Separate Parent Names
-- Split parent_guardian into individual fields
-- ============================================

-- Add new parent/guardian columns (errors will be ignored if they already exist)
ALTER TABLE pnk_registry ADD COLUMN father_first_name_encrypted TEXT COMMENT 'Encrypted father first name' AFTER contact_number_encrypted;
ALTER TABLE pnk_registry ADD COLUMN father_middle_name_encrypted TEXT COMMENT 'Encrypted father middle name' AFTER father_first_name_encrypted;
ALTER TABLE pnk_registry ADD COLUMN father_last_name_encrypted TEXT COMMENT 'Encrypted father last name' AFTER father_middle_name_encrypted;
ALTER TABLE pnk_registry ADD COLUMN mother_first_name_encrypted TEXT COMMENT 'Encrypted mother first name' AFTER father_last_name_encrypted;
ALTER TABLE pnk_registry ADD COLUMN mother_middle_name_encrypted TEXT COMMENT 'Encrypted mother middle name' AFTER mother_first_name_encrypted;
ALTER TABLE pnk_registry ADD COLUMN mother_maiden_name_encrypted TEXT COMMENT 'Encrypted mother maiden name' AFTER mother_middle_name_encrypted;
ALTER TABLE pnk_registry ADD COLUMN mother_married_name_encrypted TEXT COMMENT 'Encrypted mother married last name' AFTER mother_maiden_name_encrypted;
ALTER TABLE pnk_registry ADD COLUMN guardian_name_encrypted TEXT COMMENT 'Encrypted guardian name (if not parent)' AFTER mother_married_name_encrypted;
