-- Add columns for manual name entry (not from officer registry)
-- This allows entering names that don't exist in the officers table

ALTER TABLE overseers_contacts 
ADD COLUMN katiwala_manual_name VARCHAR(255) NULL AFTER katiwala_officer_ids,
ADD COLUMN ii_katiwala_manual_name VARCHAR(255) NULL AFTER ii_katiwala_officer_ids,
ADD COLUMN kalihim_manual_name VARCHAR(255) NULL AFTER kalihim_officer_ids;
