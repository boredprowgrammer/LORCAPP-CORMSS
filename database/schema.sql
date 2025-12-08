-- Church Officers Registry System Database Schema
-- Version: 1.0.0
-- Date: November 26, 2025

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+08:00";

-- Create Database
CREATE DATABASE IF NOT EXISTS church_officers_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE church_officers_db;

-- ============================================
-- Districts Table
-- ============================================
CREATE TABLE IF NOT EXISTS districts (
    district_id INT AUTO_INCREMENT PRIMARY KEY,
    district_code VARCHAR(20) UNIQUE NOT NULL,
    district_name VARCHAR(100) NOT NULL,
    encryption_key TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_district_code (district_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Local Congregations Table
-- ============================================
CREATE TABLE IF NOT EXISTS local_congregations (
    local_id INT AUTO_INCREMENT PRIMARY KEY,
    local_code VARCHAR(20) UNIQUE NOT NULL,
    local_name VARCHAR(100) NOT NULL,
    district_code VARCHAR(20) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (district_code) REFERENCES districts(district_code) ON DELETE CASCADE,
    INDEX idx_local_code (local_code),
    INDEX idx_district_code (district_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Users Table
-- ============================================
CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('admin', 'district', 'local') NOT NULL,
    district_code VARCHAR(20),
    local_code VARCHAR(20),
    is_active TINYINT(1) DEFAULT 1,
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (district_code) REFERENCES districts(district_code) ON DELETE CASCADE,
    FOREIGN KEY (local_code) REFERENCES local_congregations(local_code) ON DELETE CASCADE,
    INDEX idx_username (username),
    INDEX idx_role (role),
    INDEX idx_district_local (district_code, local_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Officers Table
-- ============================================
CREATE TABLE IF NOT EXISTS officers (
    officer_id INT AUTO_INCREMENT PRIMARY KEY,
    officer_uuid VARCHAR(36) UNIQUE NOT NULL,
    
    -- Encrypted Personal Information
    last_name_encrypted TEXT NOT NULL,
    first_name_encrypted TEXT NOT NULL,
    middle_initial_encrypted TEXT,
    
    -- Location and Status
    district_code VARCHAR(20) NOT NULL,
    local_code VARCHAR(20) NOT NULL,
    
    -- Record Information
    record_code ENUM('A', 'D') NOT NULL COMMENT 'A=New Record, D=Existing Record',
    is_active TINYINT(1) DEFAULT 1,
    
    -- Tracking
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (district_code) REFERENCES districts(district_code) ON DELETE CASCADE,
    FOREIGN KEY (local_code) REFERENCES local_congregations(local_code) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(user_id),
    
    INDEX idx_officer_uuid (officer_uuid),
    INDEX idx_district_local (district_code, local_code),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Officer Departments Table (Many-to-Many)
-- ============================================
CREATE TABLE IF NOT EXISTS officer_departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    officer_id INT NOT NULL,
    department VARCHAR(100) NOT NULL,
    duty TEXT COMMENT 'User-specified duty',
    oath_date DATE NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    removed_at TIMESTAMP NULL,
    
    FOREIGN KEY (officer_id) REFERENCES officers(officer_id) ON DELETE CASCADE,
    INDEX idx_officer_id (officer_id),
    INDEX idx_department (department),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Transfers Table
-- ============================================
CREATE TABLE IF NOT EXISTS transfers (
    transfer_id INT AUTO_INCREMENT PRIMARY KEY,
    officer_id INT NOT NULL,
    transfer_type ENUM('in', 'out') NOT NULL,
    
    -- Transfer Details
    from_local_code VARCHAR(20),
    from_district_code VARCHAR(20),
    to_local_code VARCHAR(20),
    to_district_code VARCHAR(20),
    
    -- Department and Office during transfer
    department VARCHAR(100),
    duty TEXT,
    oath_date DATE,
    
    -- Week tracking
    transfer_date DATE NOT NULL,
    week_number INT NOT NULL,
    year INT NOT NULL,
    
    -- Tracking
    processed_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notes TEXT,
    
    FOREIGN KEY (officer_id) REFERENCES officers(officer_id) ON DELETE CASCADE,
    FOREIGN KEY (processed_by) REFERENCES users(user_id),
    INDEX idx_officer_id (officer_id),
    INDEX idx_transfer_type (transfer_type),
    INDEX idx_week_year (week_number, year),
    INDEX idx_transfer_date (transfer_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Officer Removals Table
-- ============================================
CREATE TABLE IF NOT EXISTS officer_removals (
    removal_id INT AUTO_INCREMENT PRIMARY KEY,
    officer_id INT NOT NULL,
    removal_code ENUM('A', 'B', 'C', 'D') NOT NULL COMMENT 'A=Deceased, B=Transfer Out, C=Suspended, D=Transfer Kapisanan',
    removal_date DATE NOT NULL,
    week_number INT NOT NULL,
    year INT NOT NULL,
    reason TEXT,
    processed_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (officer_id) REFERENCES officers(officer_id) ON DELETE CASCADE,
    FOREIGN KEY (processed_by) REFERENCES users(user_id),
    INDEX idx_officer_id (officer_id),
    INDEX idx_removal_code (removal_code),
    INDEX idx_week_year (week_number, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Headcount Table
-- ============================================
CREATE TABLE IF NOT EXISTS headcount (
    id INT AUTO_INCREMENT PRIMARY KEY,
    district_code VARCHAR(20) NOT NULL,
    local_code VARCHAR(20) NOT NULL,
    total_count INT DEFAULT 0,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (district_code) REFERENCES districts(district_code) ON DELETE CASCADE,
    FOREIGN KEY (local_code) REFERENCES local_congregations(local_code) ON DELETE CASCADE,
    UNIQUE KEY unique_district_local (district_code, local_code),
    INDEX idx_district_code (district_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Audit Log Table
-- ============================================
CREATE TABLE IF NOT EXISTS audit_log (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    table_name VARCHAR(50),
    record_id INT,
    old_values TEXT,
    new_values TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Officer Requests Table (Aspiring Church Officers Management)
-- ============================================
CREATE TABLE IF NOT EXISTS officer_requests (
    request_id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- Encrypted Personal Information (NULL for CODE D since we link to existing officer)
    last_name_encrypted TEXT NULL,
    first_name_encrypted TEXT NULL,
    middle_initial_encrypted TEXT NULL,
    
    -- Contact Information (Optional)
    email VARCHAR(100),
    phone VARCHAR(20),
    
    -- Request Details
    district_code VARCHAR(20) NOT NULL,
    local_code VARCHAR(20) NOT NULL,
    requested_department VARCHAR(100) NOT NULL,
    requested_duty TEXT,
    -- Record code indicates whether this should create a new officer (A) or link to an existing one (D)
    record_code ENUM('A','D') NOT NULL DEFAULT 'A' COMMENT 'A=Create new officer, D=Link existing officer',
    existing_officer_uuid VARCHAR(36) NULL COMMENT 'If record_code=D, store officer UUID here',
    
    -- Workflow Status
    status ENUM(
        'pending',                  -- Initial submission
        'requested_to_seminar',     -- Approved for seminar attendance
        'in_seminar',              -- Currently attending seminar/circular
        'seminar_completed',       -- Completed seminar
        'requested_to_oath',       -- Approved to take oath
        'ready_to_oath',           -- Scheduled for oath ceremony
        'oath_taken',              -- Completed - Officer created
        'rejected',                -- Request denied
        'cancelled'                -- Cancelled by requester
    ) DEFAULT 'pending',
    
    -- Seminar Details
    seminar_date DATE NULL,
    seminar_location VARCHAR(200) NULL,
    seminar_completion_date DATE NULL,
    seminar_certificate_number VARCHAR(50) NULL,
    seminar_notes TEXT NULL,
    
    -- Oath Details
    oath_scheduled_date DATE NULL,
    oath_actual_date DATE NULL,
    oath_location VARCHAR(200) NULL,
    oath_notes TEXT NULL,
    
    -- Generated Officer Record
    officer_id INT NULL COMMENT 'Links to officers table after oath',
    
    -- Review and Notes
    status_reason TEXT,
    admin_notes TEXT,
    
    -- Tracking
    requested_by INT NOT NULL COMMENT 'User who submitted the request',
    reviewed_by INT NULL COMMENT 'Admin who reviewed',
    approved_seminar_by INT NULL COMMENT 'Admin who approved for seminar',
    approved_oath_by INT NULL COMMENT 'Admin who approved for oath',
    completed_by INT NULL COMMENT 'Admin who marked as completed',
    
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL,
    seminar_approved_at TIMESTAMP NULL,
    oath_approved_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (district_code) REFERENCES districts(district_code) ON DELETE CASCADE,
    FOREIGN KEY (local_code) REFERENCES local_congregations(local_code) ON DELETE CASCADE,
    FOREIGN KEY (requested_by) REFERENCES users(user_id),
    FOREIGN KEY (reviewed_by) REFERENCES users(user_id),
    FOREIGN KEY (approved_seminar_by) REFERENCES users(user_id),
    FOREIGN KEY (approved_oath_by) REFERENCES users(user_id),
    FOREIGN KEY (completed_by) REFERENCES users(user_id),
    FOREIGN KEY (officer_id) REFERENCES officers(officer_id) ON DELETE SET NULL,
    
    INDEX idx_status (status),
    INDEX idx_district_local (district_code, local_code),
    INDEX idx_record_code (record_code),
    INDEX idx_existing_officer_uuid (existing_officer_uuid),
    INDEX idx_requested_at (requested_at),
    INDEX idx_officer_id (officer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Insert Default Admin User
-- Password: Admin@123 (Change this immediately after first login)
-- ============================================
INSERT INTO users (username, password_hash, email, full_name, role, is_active) 
VALUES (
    'admin',
    '$argon2id$v=19$m=65536,t=4,p=3$TjZGSzBqeWFTd2lZZ1QzYQ$OX7nJQxMpPxqr8h6MnpRNW1vC5K9x8Y7Zq4w3s2a1b0',
    'admin@churchofficers.com',
    'System Administrator',
    'admin',
    1
);

-- ============================================
-- Sample Data (Optional - Remove in production)
-- ============================================

-- Sample Districts
INSERT INTO districts (district_code, district_name) VALUES
('DST001', 'District 1'),
('DST002', 'District 2');

-- Sample Local Congregations
INSERT INTO local_congregations (local_code, local_name, district_code) VALUES
('LCL001', 'Local Congregation 1', 'DST001'),
('LCL002', 'Local Congregation 2', 'DST001'),
('LCL003', 'Local Congregation 3', 'DST002');

-- Sample District User
-- Password: District@123
INSERT INTO users (username, password_hash, email, full_name, role, district_code, is_active) 
VALUES (
    'district1',
    '$argon2id$v=19$m=65536,t=4,p=3$TjZGSzBqeWFTd2lZZ1QzYQ$OX7nJQxMpPxqr8h6MnpRNW1vC5K9x8Y7Zq4w3s2a1b0',
    'district1@churchofficers.com',
    'District 1 Manager',
    'district',
    'DST001',
    1
);

-- Sample Local User
-- Password: Local@123
INSERT INTO users (username, password_hash, email, full_name, role, district_code, local_code, is_active) 
VALUES (
    'local1',
    '$argon2id$v=19$m=65536,t=4,p=3$TjZGSzBqeWFTd2lZZ1QzYQ$OX7nJQxMpPxqr8h6MnpRNW1vC5K9x8Y7Zq4w3s2a1b0',
    'local1@churchofficers.com',
    'Local 1 User',
    'local',
    'DST001',
    'LCL001',
    1
);

-- Initialize Headcount for sample locals
INSERT INTO headcount (district_code, local_code, total_count) VALUES
('DST001', 'LCL001', 0),
('DST001', 'LCL002', 0),
('DST002', 'LCL003', 0);
