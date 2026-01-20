-- Migration: Add CFO/HDB/PNK action types to pending_actions
-- This allows local_cfo users to submit CFO/HDB/PNK changes for LORC/LCRC review

-- Modify the action_type enum to include CFO, HDB, and PNK actions
ALTER TABLE pending_actions 
MODIFY COLUMN action_type ENUM(
    'add_officer', 
    'edit_officer', 
    'remove_officer', 
    'transfer_in', 
    'transfer_out', 
    'bulk_update', 
    'add_request',
    'add_cfo',
    'edit_cfo',
    'add_hdb',
    'edit_hdb',
    'add_pnk',
    'edit_pnk'
) NOT NULL;

-- Add column to track target table/record type (check if exists first)
-- MySQL doesn't support IF NOT EXISTS for ADD COLUMN, so we use a procedure
DROP PROCEDURE IF EXISTS add_pending_actions_columns;

DELIMITER $$
CREATE PROCEDURE add_pending_actions_columns()
BEGIN
    -- Add target_table column if not exists
    IF NOT EXISTS (
        SELECT * FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'pending_actions' 
        AND COLUMN_NAME = 'target_table'
    ) THEN
        ALTER TABLE pending_actions 
        ADD COLUMN target_table VARCHAR(50) DEFAULT 'officers' 
        COMMENT 'Target table: officers, tarheta_control, hdb_members, pnk_members'
        AFTER officer_uuid;
    END IF;
    
    -- Add target_record_id column if not exists
    IF NOT EXISTS (
        SELECT * FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'pending_actions' 
        AND COLUMN_NAME = 'target_record_id'
    ) THEN
        ALTER TABLE pending_actions 
        ADD COLUMN target_record_id INT NULL 
        COMMENT 'ID in the target table (for edits)'
        AFTER target_table;
    END IF;
    
    -- Add index if not exists
    IF NOT EXISTS (
        SELECT * FROM information_schema.STATISTICS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'pending_actions' 
        AND INDEX_NAME = 'idx_target_table'
    ) THEN
        CREATE INDEX idx_target_table ON pending_actions(target_table);
    END IF;
END$$
DELIMITER ;

CALL add_pending_actions_columns();
DROP PROCEDURE IF EXISTS add_pending_actions_columns;
