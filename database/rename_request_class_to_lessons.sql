-- Rename request class from R5-04/R5-15 to 8_lessons/33_lessons

-- First, add the new values to the enum
ALTER TABLE officer_requests 
MODIFY COLUMN request_class ENUM('R5-04','R5-15','8_lessons','33_lessons') DEFAULT NULL;

-- Update existing data
UPDATE officer_requests 
SET request_class = '8_lessons' 
WHERE request_class = 'R5-15';

UPDATE officer_requests 
SET request_class = '33_lessons' 
WHERE request_class = 'R5-04';

-- Remove old enum values
ALTER TABLE officer_requests 
MODIFY COLUMN request_class ENUM('8_lessons','33_lessons') DEFAULT NULL;
