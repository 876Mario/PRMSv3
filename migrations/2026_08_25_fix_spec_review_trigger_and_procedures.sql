-- Migration: Fix RFQ Spec Review Trigger and Create Backward-Compatible Stored Procedures
-- Purpose: Fix the trg_initialize_spec_review_on_first_quote trigger that still uses
--          the old column name spec_review_status (renamed to requestor_spec_review_status)
--          and create backward-compatible stored procedure aliases for sp_approve_rfq_spec_review
--          and sp_reject_rfq_spec_review
--
-- Root Cause Analysis:
-- 1. The trigger trg_initialize_spec_review_on_first_quote was created in the initial schema
--    and uses SET spec_review_status = 'PENDING'
-- 2. The August 21, 2026 migration renamed spec_review_status to requestor_spec_review_status
-- 3. However, the trigger was not updated, causing "Column not found" errors when quotes are uploaded
-- 4. The stored procedures sp_approve_rfq_spec_review and sp_reject_rfq_spec_review were
--    dropped and replaced with sp_approve_rfq_requestor_review and sp_reject_rfq_requestor_review
-- 5. If any old code or external scripts call the old procedure names, they will fail
--
-- This migration:
-- 1. Recreates the trigger with the correct column name requestor_spec_review_status
-- 2. Creates backward-compatible alias procedures that call the new procedures
-- 3. Ensures idempotent execution (safe to run multiple times)
-- ================================================================================

-- ===================================
-- 1. Fix the trigger to use correct column name
-- ===================================
DROP TRIGGER IF EXISTS `trg_initialize_spec_review_on_first_quote`;
DELIMITER $$
CREATE TRIGGER `trg_initialize_spec_review_on_first_quote` AFTER INSERT ON `rfq_quotes` FOR EACH ROW 
BEGIN
    DECLARE quote_count INT;
    
    -- Count quotes for this RFQ vendor
    SELECT COUNT(*) INTO quote_count
    FROM rfq_quotes q
    JOIN rfq_vendors rv ON q.rfq_vendor_id = rv.rfq_vendor_id
    WHERE rv.rfq_id = (
        SELECT rfq_id FROM rfq_vendors WHERE rfq_vendor_id = NEW.rfq_vendor_id
    );
    
    -- If this is the first quote, initialize the workflow with correct column name
    IF quote_count = 1 THEN
        UPDATE rfqs
        SET requestor_spec_review_status = 'PENDING'
        WHERE rfq_id = (
            SELECT rfq_id FROM rfq_vendors WHERE rfq_vendor_id = NEW.rfq_vendor_id LIMIT 1
        );
    END IF;
END
$$
DELIMITER ;

-- ===================================
-- 2. Create backward-compatible stored procedure aliases
-- ===================================
-- These procedures maintain the old names for backward compatibility but call
-- the new procedures with the correct column names

DROP PROCEDURE IF EXISTS `sp_approve_rfq_spec_review`;
DELIMITER $$
CREATE PROCEDURE `sp_approve_rfq_spec_review`(
    IN p_rfq_id INT,
    IN p_requestor_id INT,
    IN p_comments TEXT,
    IN p_quote_id INT
)
BEGIN
    -- Backward-compatible alias that calls the new procedure
    CALL sp_approve_rfq_requestor_review(p_rfq_id, p_requestor_id, p_comments, p_quote_id);
END
$$
DELIMITER ;

DROP PROCEDURE IF EXISTS `sp_reject_rfq_spec_review`;
DELIMITER $$
CREATE PROCEDURE `sp_reject_rfq_spec_review`(
    IN p_rfq_id INT,
    IN p_requestor_id INT,
    IN p_reason TEXT,
    IN p_quote_id INT
)
BEGIN
    -- Backward-compatible alias that calls the new procedure
    CALL sp_reject_rfq_requestor_review(p_rfq_id, p_requestor_id, p_reason, p_quote_id);
END
$$
DELIMITER ;

-- ===================================
-- End of migration
-- ===================================
