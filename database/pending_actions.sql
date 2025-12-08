-- Pending Actions Table
-- Stores all actions from local_limited users that require approval

CREATE TABLE IF NOT EXISTS pending_actions (
    action_id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- User and Approval Info
    requester_user_id INT NOT NULL COMMENT 'User who requested the action (local_limited)',
    approver_user_id INT NULL COMMENT 'Senior local account assigned to approve',
    
    -- Action Details
    action_type ENUM('add_officer', 'edit_officer', 'remove_officer', 'transfer_in', 'transfer_out', 'bulk_update', 'add_request') NOT NULL,
    action_data JSON NOT NULL COMMENT 'Complete data needed to execute the action',
    action_description TEXT NULL COMMENT 'Human-readable description of the action',
    
    -- Officer Reference (if applicable)
    officer_id INT NULL COMMENT 'Reference to officer being modified',
    officer_uuid VARCHAR(36) NULL COMMENT 'Officer UUID for new officer additions',
    
    -- Status
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    
    -- Review Details
    reviewed_at TIMESTAMP NULL,
    reviewed_by INT NULL COMMENT 'User ID who actually reviewed (might differ from assigned approver)',
    rejection_reason TEXT NULL,
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL COMMENT 'Optional expiration date for the pending action',
    
    -- Foreign Keys
    FOREIGN KEY (requester_user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (approver_user_id) REFERENCES users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by) REFERENCES users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (officer_id) REFERENCES officers(officer_id) ON DELETE CASCADE,
    
    -- Indexes
    INDEX idx_status (status),
    INDEX idx_requester (requester_user_id),
    INDEX idx_approver (approver_user_id),
    INDEX idx_action_type (action_type),
    INDEX idx_created_at (created_at),
    INDEX idx_officer_id (officer_id)
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add audit trigger for approved actions
DELIMITER $$

CREATE TRIGGER after_pending_action_approved
AFTER UPDATE ON pending_actions
FOR EACH ROW
BEGIN
    IF NEW.status = 'approved' AND OLD.status = 'pending' THEN
        -- Log to audit table if exists
        IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'audit_logs') THEN
            INSERT INTO audit_logs (user_id, action, table_name, record_id, details, created_at)
            VALUES (
                NEW.reviewed_by,
                CONCAT('APPROVED_', NEW.action_type),
                'pending_actions',
                NEW.action_id,
                CONCAT('Approved action from user ', NEW.requester_user_id),
                NOW()
            );
        END IF;
    END IF;
END$$

DELIMITER ;
