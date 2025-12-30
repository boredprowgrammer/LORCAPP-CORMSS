-- Add dark mode preference to users table
ALTER TABLE users 
ADD COLUMN dark_mode TINYINT(1) DEFAULT 0 COMMENT '0=Light Mode, 1=Dark Mode' 
AFTER is_active;
