-- Add table to track CFO report reset timestamps
CREATE TABLE IF NOT EXISTS cfo_report_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    local_code VARCHAR(10) NOT NULL,
    classification ENUM('Buklod', 'Kadiwa', 'Binhi', 'all') NOT NULL,
    period ENUM('week', 'month') NOT NULL,
    reset_at DATETIME NOT NULL,
    reset_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_local_classification (local_code, classification, period),
    FOREIGN KEY (reset_by) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
