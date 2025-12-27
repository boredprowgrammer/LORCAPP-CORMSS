-- Table for storing temporary palasumpaan documents
CREATE TABLE IF NOT EXISTS palasumpaan_temp_docs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    docx_content LONGBLOB NOT NULL,
    pdf_content LONGBLOB NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    converted_at TIMESTAMP NULL,
    UNIQUE KEY uk_request_id (request_id),
    FOREIGN KEY (request_id) REFERENCES officer_requests(request_id) ON DELETE CASCADE,
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add cleanup for old temporary documents (older than 7 days)
-- This can be run as a scheduled job
-- DELETE FROM palasumpaan_temp_docs WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY);
