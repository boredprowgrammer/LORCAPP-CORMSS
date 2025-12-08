-- Add existing_officer_uuid column to officer_requests table
-- Run this SQL script to update the database schema
-- Note: record_code column already exists

USE church_officers_db;

-- Add existing_officer_uuid column (stores UUID when CODE D selected)
ALTER TABLE officer_requests 
ADD COLUMN existing_officer_uuid VARCHAR(36) NULL 
COMMENT 'If record_code=D, store officer UUID here' 
AFTER record_code;

-- Add index for better query performance
ALTER TABLE officer_requests 
ADD INDEX idx_existing_officer_uuid (existing_officer_uuid);

-- Verify the changes
DESCRIBE officer_requests;
