-- Add pdf_file_id column to call_up_slips table
-- Date: December 5, 2025

USE church_officers_db;

ALTER TABLE call_up_slips 
ADD COLUMN pdf_file_id INT NULL COMMENT 'Reference to stored PDF in pdf_files table' AFTER destinado,
ADD INDEX idx_pdf_file_id (pdf_file_id);

-- Optional: Add foreign key constraint
-- ALTER TABLE call_up_slips 
-- ADD CONSTRAINT fk_callup_pdf 
-- FOREIGN KEY (pdf_file_id) REFERENCES pdf_files(pdf_id) ON DELETE SET NULL;
