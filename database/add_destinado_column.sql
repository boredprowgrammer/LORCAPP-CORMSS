-- Add destinado column to call_up_slips table
ALTER TABLE call_up_slips 
ADD COLUMN destinado VARCHAR(255) NULL COMMENT 'Signatory/Resident Minister name' 
AFTER reason;
