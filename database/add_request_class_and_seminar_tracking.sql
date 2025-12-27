-- Add request_class column to officer_requests table
-- R5-04: Requires 30 days of seminar
-- R5-15: Requires 8 days of seminar

ALTER TABLE `officer_requests`
ADD COLUMN `request_class` ENUM('R5-04', 'R5-15') DEFAULT NULL 
COMMENT 'R5-04=30 days seminar, R5-15=8 days seminar' 
AFTER `requested_duty`;

-- Add seminar_dates JSON column to track individual seminar attendance
-- Will store array of objects: [{"date": "2025-12-24", "topic": "Leadership", "notes": "Completed"}]
ALTER TABLE `officer_requests`
ADD COLUMN `seminar_dates` JSON DEFAULT NULL 
COMMENT 'Array of seminar dates with topics and notes'
AFTER `seminar_notes`;

-- Add seminar_days_required column (computed from request_class or custom)
ALTER TABLE `officer_requests`
ADD COLUMN `seminar_days_required` INT DEFAULT NULL 
COMMENT 'Number of seminar days required (30 for R5-04, 8 for R5-15, or custom)'
AFTER `request_class`;

-- Add seminar_days_completed column (computed from seminar_dates JSON array length)
ALTER TABLE `officer_requests`
ADD COLUMN `seminar_days_completed` INT DEFAULT 0 
COMMENT 'Number of seminar days completed'
AFTER `seminar_days_required`;

-- Add r513_certificate_generated flag
ALTER TABLE `officer_requests`
ADD COLUMN `r513_generated_at` TIMESTAMP NULL DEFAULT NULL 
COMMENT 'When R5-13 (Form 513) certificate was generated'
AFTER `completed_at`;

-- Add r513_pdf_content to store PDF as LONGBLOB
ALTER TABLE `officer_requests`
ADD COLUMN `r513_pdf_content` LONGBLOB DEFAULT NULL 
COMMENT 'R5-13 certificate PDF content stored as binary'
AFTER `r513_generated_at`;

-- Add r513_pdf_filename to store original filename
ALTER TABLE `officer_requests`
ADD COLUMN `r513_pdf_filename` VARCHAR(255) DEFAULT NULL 
COMMENT 'Original filename of R5-13 certificate PDF'
AFTER `r513_pdf_content`;
