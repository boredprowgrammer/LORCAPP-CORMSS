-- Add Purok, Grupo, and Control Number fields
-- Date: December 5, 2025

USE church_officers_db;

-- Add fields to officers table
ALTER TABLE officers 
ADD COLUMN purok VARCHAR(100) NULL COMMENT 'Purok (optional)' AFTER local_code,
ADD COLUMN grupo VARCHAR(100) NULL COMMENT 'Grupo (optional)' AFTER purok,
ADD COLUMN control_number VARCHAR(50) NULL COMMENT 'Control Number (optional)' AFTER grupo;

-- Add indexes for better search performance
ALTER TABLE officers
ADD INDEX idx_purok (purok),
ADD INDEX idx_grupo (grupo),
ADD INDEX idx_control_number (control_number);
