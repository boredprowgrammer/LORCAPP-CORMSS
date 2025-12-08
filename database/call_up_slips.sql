-- Call-Up Slips Table
-- For tracking officer call-up notices

CREATE TABLE IF NOT EXISTS call_up_slips (
    slip_id INT AUTO_INCREMENT PRIMARY KEY,
    officer_id INT NOT NULL,
    
    -- Call-up details
    file_number VARCHAR(50) NOT NULL COMMENT 'e.g., BUK-2025-001',
    department VARCHAR(100) NOT NULL COMMENT 'Department/Kapisanan',
    reason TEXT NOT NULL COMMENT 'Dahilan/Reason for call-up',
    issue_date DATE NOT NULL DEFAULT (CURRENT_DATE),
    deadline_date DATE NOT NULL COMMENT 'Deadline for response',
    
    -- Status tracking
    status ENUM('issued', 'responded', 'expired', 'cancelled') DEFAULT 'issued',
    response_date DATE NULL,
    response_notes TEXT NULL,
    
    -- Tracking
    prepared_by INT NOT NULL COMMENT 'User who prepared the slip',
    local_code VARCHAR(20) NOT NULL,
    district_code VARCHAR(20) NOT NULL,
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (officer_id) REFERENCES officers(officer_id) ON DELETE CASCADE,
    FOREIGN KEY (prepared_by) REFERENCES users(user_id),
    FOREIGN KEY (local_code) REFERENCES local_congregations(local_code),
    FOREIGN KEY (district_code) REFERENCES districts(district_code),
    
    INDEX idx_officer (officer_id),
    INDEX idx_status (status),
    INDEX idx_issue_date (issue_date),
    INDEX idx_file_number (file_number),
    INDEX idx_local_district (local_code, district_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
