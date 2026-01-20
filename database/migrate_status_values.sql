-- Migration: Update HDB and PNK Registry Status Values
-- Date: 2026-01-19

-- =====================================================
-- HDB REGISTRY STATUS UPDATE
-- New statuses: active, pnk, transferred-out, baptized
-- =====================================================

-- Add transfer columns if they don't exist
ALTER TABLE hdb_registry 
    ADD COLUMN IF NOT EXISTS transfer_to_district VARCHAR(100) DEFAULT NULL AFTER transfer_to,
    ADD COLUMN IF NOT EXISTS transfer_from VARCHAR(100) DEFAULT NULL AFTER transfer_date,
    ADD COLUMN IF NOT EXISTS transfer_from_district VARCHAR(100) DEFAULT NULL AFTER transfer_from;

-- Update existing records: Convert 'pending' and 'dedicated' to 'active'
UPDATE hdb_registry SET dedication_status = 'active' WHERE dedication_status IN ('pending', 'dedicated');

-- Modify the ENUM to new values
ALTER TABLE hdb_registry 
    MODIFY COLUMN dedication_status ENUM('active', 'pnk', 'transferred-out', 'baptized') DEFAULT 'active';

-- =====================================================
-- PNK REGISTRY STATUS UPDATE  
-- New statuses: active, r301, transferred-out, baptized
-- =====================================================

-- Add transfer columns if they don't exist
ALTER TABLE pnk_registry 
    ADD COLUMN IF NOT EXISTS transfer_to VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS transfer_to_district VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS transfer_from VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS transfer_from_district VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS dako_encrypted TEXT DEFAULT NULL;

-- Update existing records: Convert 'not_baptized' to 'active', 'candidate' to 'r301'
UPDATE pnk_registry SET baptism_status = 'active' WHERE baptism_status = 'not_baptized';
UPDATE pnk_registry SET baptism_status = 'r301' WHERE baptism_status = 'candidate';

-- Modify the ENUM to new values
ALTER TABLE pnk_registry 
    MODIFY COLUMN baptism_status ENUM('active', 'r301', 'transferred-out', 'baptized') DEFAULT 'active';

-- Update attendance_status: Convert 'graduated' to 'baptized'
UPDATE pnk_registry SET attendance_status = 'baptized' WHERE attendance_status = 'graduated';

-- Modify attendance_status ENUM
ALTER TABLE pnk_registry 
    MODIFY COLUMN attendance_status ENUM('active', 'inactive', 'transferred-out', 'baptized') DEFAULT 'active';

-- =====================================================
-- VERIFICATION QUERIES (run these to verify changes)
-- =====================================================
-- SELECT DISTINCT dedication_status FROM hdb_registry;
-- SELECT DISTINCT baptism_status FROM pnk_registry;
-- SELECT DISTINCT attendance_status FROM pnk_registry;
