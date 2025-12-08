-- Add manual_officer_name column to call_up_slips table
-- This allows creating call-ups for officers not in the system

ALTER TABLE call_up_slips
ADD COLUMN manual_officer_name VARCHAR(255) NULL COMMENT 'Manual officer name if not found in system'
AFTER officer_id;

-- Make officer_id nullable since manual entries won't have it
ALTER TABLE call_up_slips
MODIFY COLUMN officer_id INT(11) NULL;
