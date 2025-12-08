-- Create PDF Files table for encrypted storage
-- Date: December 5, 2025

USE church_officers_db;

CREATE TABLE IF NOT EXISTS pdf_files (
    pdf_id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- Reference Information
    reference_type ENUM('call_up_slip', 'palasumpaan', 'r201', 'other') NOT NULL,
    reference_id INT NULL COMMENT 'ID of the related record (e.g., call_up_slip_id)',
    reference_uuid VARCHAR(36) NULL COMMENT 'UUID of the related record',
    
    -- File Information
    file_name VARCHAR(255) NOT NULL,
    file_size INT NOT NULL COMMENT 'Size in bytes',
    mime_type VARCHAR(100) DEFAULT 'application/pdf',
    
    -- Encrypted PDF Data
    encrypted_pdf LONGBLOB NOT NULL COMMENT 'Encrypted PDF content',
    encryption_iv VARCHAR(255) NOT NULL COMMENT 'Initialization vector for decryption',
    encryption_method VARCHAR(50) DEFAULT 'AES-256-CBC',
    
    -- Metadata
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_accessed TIMESTAMP NULL,
    access_count INT DEFAULT 0,
    
    -- Security
    checksum VARCHAR(64) NOT NULL COMMENT 'SHA-256 hash for integrity verification',
    
    FOREIGN KEY (created_by) REFERENCES users(user_id),
    INDEX idx_reference (reference_type, reference_id),
    INDEX idx_reference_uuid (reference_uuid),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
