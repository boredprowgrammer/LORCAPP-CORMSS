<?php
/**
 * Migration: Add request_class and seminar tracking to officer_requests
 */

require_once __DIR__ . '/../config/config.php';

try {
    $db = Database::getInstance()->getConnection();
    
    echo "Starting migration: Add request_class and seminar tracking...\n\n";
    
    // Add request_class column
    echo "Adding request_class column...\n";
    $db->exec("
        ALTER TABLE `officer_requests`
        ADD COLUMN `request_class` ENUM('R5-04', 'R5-15') DEFAULT NULL 
        COMMENT 'R5-04=30 days seminar, R5-15=8 days seminar' 
        AFTER `requested_duty`
    ");
    echo "✓ request_class column added\n\n";
    
    // Add seminar_days_required column
    echo "Adding seminar_days_required column...\n";
    $db->exec("
        ALTER TABLE `officer_requests`
        ADD COLUMN `seminar_days_required` INT DEFAULT NULL 
        COMMENT 'Number of seminar days required (30 for R5-04, 8 for R5-15, or custom)'
        AFTER `request_class`
    ");
    echo "✓ seminar_days_required column added\n\n";
    
    // Add seminar_days_completed column
    echo "Adding seminar_days_completed column...\n";
    $db->exec("
        ALTER TABLE `officer_requests`
        ADD COLUMN `seminar_days_completed` INT DEFAULT 0 
        COMMENT 'Number of seminar days completed'
        AFTER `seminar_days_required`
    ");
    echo "✓ seminar_days_completed column added\n\n";
    
    // Add seminar_dates JSON column
    echo "Adding seminar_dates JSON column...\n";
    $db->exec("
        ALTER TABLE `officer_requests`
        ADD COLUMN `seminar_dates` JSON DEFAULT NULL 
        COMMENT 'Array of seminar dates with topics and notes'
        AFTER `seminar_notes`
    ");
    echo "✓ seminar_dates column added\n\n";
    
    // Add r513_generated_at column
    echo "Adding r513_generated_at column...\n";
    $db->exec("
        ALTER TABLE `officer_requests`
        ADD COLUMN `r513_generated_at` TIMESTAMP NULL DEFAULT NULL 
        COMMENT 'When R5-13 (Form 513) certificate was generated'
        AFTER `completed_at`
    ");
    echo "✓ r513_generated_at column added\n\n";
    
    // Add r513_pdf_file_id column
    echo "Adding r513_pdf_file_id column...\n";
    $db->exec("
        ALTER TABLE `officer_requests`
        ADD COLUMN `r513_pdf_file_id` INT DEFAULT NULL 
        COMMENT 'Reference to pdf_files table for R5-13 certificate'
        AFTER `r513_generated_at`
    ");
    echo "✓ r513_pdf_file_id column added\n\n";
    
    // Add foreign key constraint
    echo "Adding foreign key constraint...\n";
    $db->exec("
        ALTER TABLE `officer_requests`
        ADD CONSTRAINT `fk_r513_pdf_file` 
        FOREIGN KEY (`r513_pdf_file_id`) 
        REFERENCES `pdf_files`(`pdf_id`) 
        ON DELETE SET NULL
    ");
    echo "✓ Foreign key constraint added\n\n";
    
    echo "========================================\n";
    echo "Migration completed successfully!\n";
    echo "========================================\n\n";
    
    echo "New columns added to officer_requests:\n";
    echo "  - request_class: ENUM('R5-04', 'R5-15')\n";
    echo "  - seminar_days_required: INT\n";
    echo "  - seminar_days_completed: INT\n";
    echo "  - seminar_dates: JSON\n";
    echo "  - r513_generated_at: TIMESTAMP\n";
    echo "  - r513_pdf_file_id: INT\n\n";
    
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "⚠ Warning: Columns may already exist. Migration might have been run before.\n";
        echo "Error: " . $e->getMessage() . "\n";
    } else {
        echo "✗ Migration failed!\n";
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "✗ Unexpected error!\n";
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
