-- Migration: R5-18 Checker Support - New Fields
-- Description: Add new fields specifically for R5-18 completeness tracking
-- Date: 2025-12-30

-- The R5-18 Checker tracks three requirements:
-- 1. Has R5-18 document submitted
-- 2. Has 2x2 Picture attached
-- 3. Complete Signatories verified

-- =====================================================
-- NEW FIELDS FOR R5-18 TRACKING IN OFFICERS TABLE
-- =====================================================

-- Add r518_submitted flag
ALTER TABLE officers 
ADD COLUMN r518_submitted TINYINT(1) DEFAULT 0 
COMMENT 'R5-18 form has been submitted (1=Yes, 0=No)'
AFTER tarheta_control_id;

-- Add r518_picture_attached flag
ALTER TABLE officers 
ADD COLUMN r518_picture_attached TINYINT(1) DEFAULT 0 
COMMENT '2x2 picture attached to R5-18 (1=Yes, 0=No)'
AFTER r518_submitted;

-- Add r518_signatories_complete flag
ALTER TABLE officers 
ADD COLUMN r518_signatories_complete TINYINT(1) DEFAULT 0 
COMMENT 'All required signatories are complete (1=Yes, 0=No)'
AFTER r518_picture_attached;

-- Add r518_verified_at timestamp
ALTER TABLE officers 
ADD COLUMN r518_verified_at TIMESTAMP NULL DEFAULT NULL 
COMMENT 'Date and time when R5-18 was verified as complete'
AFTER r518_signatories_complete;

-- Add r518_verified_by field
ALTER TABLE officers 
ADD COLUMN r518_verified_by INT NULL 
COMMENT 'User ID of who verified the R5-18 completeness'
AFTER r518_verified_at;

-- Add r518_notes field for additional information
ALTER TABLE officers 
ADD COLUMN r518_notes TEXT NULL 
COMMENT 'Additional notes or remarks about R5-18 status'
AFTER r518_verified_by;

-- Add r518_completion_status computed field helper
ALTER TABLE officers 
ADD COLUMN r518_completion_status ENUM('pending', 'incomplete', 'complete', 'verified') DEFAULT 'pending'
COMMENT 'Overall R5-18 status: pending=not started, incomplete=partial, complete=all submitted, verified=officially verified'
AFTER r518_notes;

-- =====================================================
-- INDEXES FOR PERFORMANCE
-- =====================================================

-- Add index on R5-18 status fields for faster filtering
ALTER TABLE officers 
ADD INDEX idx_r518_submitted (r518_submitted);

ALTER TABLE officers 
ADD INDEX idx_r518_completion_status (r518_completion_status);

ALTER TABLE officers 
ADD INDEX idx_r518_verified_at (r518_verified_at);

-- Composite index for R5-18 checker queries
ALTER TABLE officers 
ADD INDEX idx_r518_check (r518_submitted, r518_picture_attached, r518_signatories_complete);

-- =====================================================
-- DATA MIGRATION: Populate new fields from existing data
-- =====================================================

-- Populate r518_submitted from tarheta_control linkage
UPDATE officers o
SET r518_submitted = 1
WHERE tarheta_control_id IS NOT NULL;

-- Populate r518_picture_attached from requests.has_picture
UPDATE officers o
INNER JOIN requests r ON o.officer_id = r.officer_id AND r.status = 'approved'
SET o.r518_picture_attached = 1
WHERE r.has_picture = 1;

-- Populate r518_signatories_complete from tarheta_control.destinado
UPDATE officers o
INNER JOIN tarheta_control tc ON o.tarheta_control_id = tc.id
SET o.r518_signatories_complete = 1
WHERE tc.destinado IS NOT NULL AND tc.destinado != '';

-- Update r518_completion_status based on the three flags
UPDATE officers 
SET r518_completion_status = CASE
    WHEN r518_submitted = 0 AND r518_picture_attached = 0 AND r518_signatories_complete = 0 THEN 'pending'
    WHEN r518_submitted = 1 AND r518_picture_attached = 1 AND r518_signatories_complete = 1 THEN 'complete'
    ELSE 'incomplete'
END
WHERE is_active = 1;

-- =====================================================
-- VERIFICATION QUERIES
-- =====================================================

-- Check R5-18 status distribution
/*
SELECT 
    r518_completion_status,
    COUNT(*) as officer_count,
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM officers WHERE is_active = 1), 2) as percentage
FROM officers 
WHERE is_active = 1
GROUP BY r518_completion_status
ORDER BY FIELD(r518_completion_status, 'verified', 'complete', 'incomplete', 'pending');
*/

-- List officers with incomplete R5-18
/*
SELECT 
    o.officer_id,
    CASE WHEN o.r518_submitted = 1 THEN '✓' ELSE '✗' END as has_r518,
    CASE WHEN o.r518_picture_attached = 1 THEN '✓' ELSE '✗' END as has_picture,
    CASE WHEN o.r518_signatories_complete = 1 THEN '✓' ELSE '✗' END as has_signatories,
    o.r518_completion_status,
    o.r518_notes
FROM officers o
WHERE o.is_active = 1
  AND o.r518_completion_status IN ('pending', 'incomplete')
ORDER BY o.r518_completion_status, o.officer_id;
*/

-- =====================================================
-- TRIGGER: Auto-update completion status
-- =====================================================

DELIMITER $$

CREATE TRIGGER trg_officers_r518_status_update
BEFORE UPDATE ON officers
FOR EACH ROW
BEGIN
    -- Auto-calculate completion status if any R5-18 flag changes
    IF NEW.r518_submitted != OLD.r518_submitted 
       OR NEW.r518_picture_attached != OLD.r518_picture_attached 
       OR NEW.r518_signatories_complete != OLD.r518_signatories_complete THEN
        
        IF NEW.r518_submitted = 0 AND NEW.r518_picture_attached = 0 AND NEW.r518_signatories_complete = 0 THEN
            SET NEW.r518_completion_status = 'pending';
        ELSEIF NEW.r518_submitted = 1 AND NEW.r518_picture_attached = 1 AND NEW.r518_signatories_complete = 1 THEN
            -- Only auto-set to complete, not verified (verified must be manual)
            IF OLD.r518_completion_status != 'verified' THEN
                SET NEW.r518_completion_status = 'complete';
            END IF;
        ELSE
            SET NEW.r518_completion_status = 'incomplete';
        END IF;
    END IF;
END$$

DELIMITER ;

-- =====================================================
-- SUMMARY
-- =====================================================

-- New fields added to officers table:
-- ✓ r518_submitted - R5-18 form submitted flag
-- ✓ r518_picture_attached - 2x2 picture attached flag
-- ✓ r518_signatories_complete - Signatories complete flag
-- ✓ r518_verified_at - Verification timestamp
-- ✓ r518_verified_by - Verifier user ID
-- ✓ r518_notes - Additional notes
-- ✓ r518_completion_status - Overall status (pending/incomplete/complete/verified)

-- Indexes added for performance:
-- ✓ idx_r518_submitted
-- ✓ idx_r518_completion_status
-- ✓ idx_r518_verified_at
-- ✓ idx_r518_check (composite)

-- Trigger added:
-- ✓ trg_officers_r518_status_update - Auto-updates completion status

-- Migration Complete!
