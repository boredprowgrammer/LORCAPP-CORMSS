-- CFO Access Control System
-- Add table for CFO access requests and approved PDFs

-- Table for access requests
CREATE TABLE IF NOT EXISTS cfo_access_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    requester_user_id INT NOT NULL,
    requester_local_code VARCHAR(10) NOT NULL,
    cfo_type ENUM('Buklod', 'Kadiwa', 'Binhi', 'All') NOT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    request_reason TEXT,
    request_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    approver_user_id INT,
    approval_date DATETIME,
    approval_notes TEXT,
    pdf_file LONGBLOB,
    pdf_filename VARCHAR(255),
    pdf_mime_type VARCHAR(100),
    pdf_size INT,
    first_opened_at DATETIME,
    is_locked BOOLEAN DEFAULT FALSE,
    locked_at DATETIME,
    will_delete_at DATETIME,
    deleted_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (requester_user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (approver_user_id) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_requester (requester_user_id),
    INDEX idx_status (status),
    INDEX idx_local (requester_local_code),
    INDEX idx_deletion (will_delete_at, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add column to users table to track if they need senior approval
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS requires_senior_approval BOOLEAN DEFAULT FALSE AFTER role;

-- Update existing local_cfo users to require senior approval
UPDATE users SET requires_senior_approval = TRUE WHERE role = 'local_cfo';

-- Table for tracking PDF access logs
CREATE TABLE IF NOT EXISTS cfo_pdf_access_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    access_request_id INT NOT NULL,
    user_id INT NOT NULL,
    access_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    user_agent TEXT,
    FOREIGN KEY (access_request_id) REFERENCES cfo_access_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_request (access_request_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
