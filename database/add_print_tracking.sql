-- Add print tracking to CFO access requests
-- Run this migration to add print tracking columns

ALTER TABLE cfo_access_requests 
ADD COLUMN IF NOT EXISTS has_printed BOOLEAN DEFAULT FALSE AFTER pdf_size,
ADD COLUMN IF NOT EXISTS printed_at DATETIME DEFAULT NULL AFTER has_printed;

-- Create index for quick lookups
CREATE INDEX IF NOT EXISTS idx_has_printed ON cfo_access_requests(has_printed);

-- Update existing records to set has_printed to FALSE if NULL
UPDATE cfo_access_requests SET has_printed = FALSE WHERE has_printed IS NULL;
