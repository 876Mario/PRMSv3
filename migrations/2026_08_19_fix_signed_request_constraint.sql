-- ============================================================
-- Migration: 2026_08_19_fix_signed_request_constraint.sql
-- Purpose: Fix SQL constraint syntax error in signed_request_documents
-- ============================================================
--
-- Issue: The UNIQUE KEY with WHERE clause syntax is not compatible 
-- with all MySQL/MariaDB versions and has flawed uniqueness semantics
--
-- Original (problematic):
--   UNIQUE KEY `uk_signed_req_active` (`request_id`, `is_active`) WHERE `is_active` = 1
--
-- Problem: 
--   1. WHERE clause in UNIQUE KEY not supported in all MariaDB versions
--   2. Semantic issue: constraint should apply to non-deleted, active records
--
-- Solution:
--   Use a trigger-based approach to enforce the uniqueness constraint
--   and a regular unique index with application-level validation
--
-- ============================================================

-- ═══════════════════════════════════════════════════════════
-- STEP 1: Drop the problematic unique key (if it exists)
-- ═══════════════════════════════════════════════════════════

ALTER TABLE `signed_request_documents` 
DROP KEY IF EXISTS `uk_signed_req_active`;

-- ═══════════════════════════════════════════════════════════
-- STEP 2: Add regular unique index for active documents
--         (allows NULL, multiple 0 values for is_active)
-- ═══════════════════════════════════════════════════════════

-- Create a generated column that is NULL when is_active=0 or is_deleted=1
-- This allows the unique index to naturally exclude inactive/deleted records
-- Using IF() instead of CASE WHEN for MariaDB compatibility
ALTER TABLE `signed_request_documents`
ADD COLUMN `active_marker` INT GENERATED ALWAYS AS 
    IF(is_active = 1 AND is_deleted = 0, request_id, NULL) 
    STORED,
ADD UNIQUE KEY `uk_signed_req_active` (`active_marker`);

-- ═══════════════════════════════════════════════════════════
-- STEP 3: Add trigger to enforce active document constraint
--         (prevents multiple active documents per request)
-- ═══════════════════════════════════════════════════════════

DELIMITER //

CREATE TRIGGER `trg_signed_req_enforce_single_active` 
BEFORE INSERT ON `signed_request_documents`
FOR EACH ROW
BEGIN
    DECLARE existing_count INT DEFAULT 0;
    
    -- Only check if we're trying to set is_active = 1 and is_deleted = 0
    IF NEW.is_active = 1 AND NEW.is_deleted = 0 THEN
        -- Count existing active, non-deleted records for this request
        SELECT COUNT(*) INTO existing_count
        FROM `signed_request_documents`
        WHERE `request_id` = NEW.`request_id`
          AND `is_active` = 1
          AND `is_deleted` = 0;
        
        -- If one already exists, raise error
        IF existing_count > 0 THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Only one active signed document per request is allowed';
        END IF;
    END IF;
END//

DELIMITER ;

-- ═══════════════════════════════════════════════════════════
-- STEP 4: Add trigger to handle reactivation (update scenario)
-- ═══════════════════════════════════════════════════════════

DELIMITER //

CREATE TRIGGER `trg_signed_req_enforce_single_active_update` 
BEFORE UPDATE ON `signed_request_documents`
FOR EACH ROW
BEGIN
    DECLARE existing_count INT DEFAULT 0;
    
    -- Only check if we're trying to activate this record
    IF NEW.is_active = 1 AND NEW.is_deleted = 0 
       AND (OLD.is_active = 0 OR OLD.is_deleted = 1) THEN
        -- Count existing active, non-deleted records for this request (excluding this row)
        SELECT COUNT(*) INTO existing_count
        FROM `signed_request_documents`
        WHERE `request_id` = NEW.`request_id`
          AND `doc_id` != NEW.`doc_id`
          AND `is_active` = 1
          AND `is_deleted` = 0;
        
        -- If one already exists, deactivate it first or raise error
        IF existing_count > 0 THEN
            -- Deactivate the old active record to maintain single-active invariant
            UPDATE `signed_request_documents`
            SET `is_active` = 0
            WHERE `request_id` = NEW.`request_id`
              AND `doc_id` != NEW.`doc_id`
              AND `is_active` = 1
              AND `is_deleted` = 0
            LIMIT 1;
        END IF;
    END IF;
END//

DELIMITER ;

-- ═══════════════════════════════════════════════════════════
-- STEP 5: Verify table structure
-- ═══════════════════════════════════════════════════════════

-- DESCRIBE `signed_request_documents`;

-- ═══════════════════════════════════════════════════════════
-- STEP 6: Add indexes for optimization
-- ═══════════════════════════════════════════════════════════

-- Already has idx_request_id, idx_request_type, idx_uploaded_by, idx_uploaded_at
-- Additional index for soft-delete queries:
CREATE INDEX IF NOT EXISTS `idx_deleted_status` ON `signed_request_documents` (`is_deleted`, `request_id`);

-- ═══════════════════════════════════════════════════════════
-- STEP 7: Audit Log Entry
-- ═══════════════════════════════════════════════════════════

INSERT INTO audit_log (table_name, record_id, action, notes)
VALUES ('DATABASE', 0, 'SCHEMA_CHANGE', 
  CONCAT('Fixed signed_request_documents table: Replaced incompatible partial unique index with ',
         'generated column + unique index + trigger-based enforcement. Ensures only one active ',
         'non-deleted document per request across all request types.'));
