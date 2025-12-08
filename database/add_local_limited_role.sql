-- Add Local (Limited) role to users table
-- This role requires approval from a senior local account for all actions

-- Step 1: Modify the role ENUM to include 'local_limited'
ALTER TABLE users 
MODIFY COLUMN role ENUM('admin', 'district', 'local', 'local_limited') NOT NULL;

-- Step 2: Add senior_approver_id field to track which senior local account approves this user's actions
ALTER TABLE users
ADD COLUMN senior_approver_id INT NULL COMMENT 'Senior local account that approves this user actions' AFTER local_code,
ADD CONSTRAINT fk_senior_approver FOREIGN KEY (senior_approver_id) REFERENCES users(user_id) ON DELETE SET NULL;

-- Add index for faster lookups
ALTER TABLE users
ADD INDEX idx_senior_approver (senior_approver_id);
