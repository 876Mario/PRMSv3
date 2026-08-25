-- Migration: Fix RFQ Triggers and Stored Procedures Column Names
-- Purpose: Update triggers and stored procedures created by earlier migrations
--          that reference the old spec_review_status column name, which was
--          renamed to requestor_spec_review_status in the August 21 migration.
--
-- Background:
-- The July 31 migrations created triggers and stored procedures using
-- spec_review_status, spec_reviewer_id, etc. The August 21 migration renamed
-- these columns to requestor_spec_review_status, requestor_reviewer_id, etc.
-- However, the triggers and stored procedures were not updated, causing
-- "Column not found" errors when they try to use the old column names.
--
-- This migration recreates all affected triggers and stored procedures with
-- the correct column names.
-- ===================================================================

START TRANSACTION;

-- ===================================
-- 1. Recreate triggers with correct column names
-- ===================================
DROP TRIGGER IF EXISTS `trg_initialize_rfq_approval_workflow`;
DELIMITER $$
CREATE TRIGGER `trg_initialize_rfq_approval_workflow` BEFORE INSERT ON `rfqs` FOR EACH ROW
BEGIN
    -- Only sets NEW.* values before the row is written
    IF NEW.requestor_spec_review_status IS NULL THEN
        SET NEW.requestor_spec_review_status = 'PENDING';
    END IF;
    IF NEW.branch_head_approval_status IS NULL THEN
        SET NEW.branch_head_approval_status = 'PENDING';
    END IF;
END
$$
DELIMITER ;

DROP TRIGGER IF EXISTS `trg_require_quote_approval_for_commitment`;
DELIMITER $$
CREATE TRIGGER `trg_require_quote_approval_for_commitment` BEFORE INSERT ON `commitments` FOR EACH ROW
BEGIN
    DECLARE requestor_status VARCHAR(50);
    DECLARE branch_head_status VARCHAR(50);

    IF NEW.rfq_id IS NOT NULL AND NEW.selected_quote_id IS NOT NULL THEN
        -- Read-only lookup; does not modify `rfqs`
        SELECT requestor_spec_review_status, branch_head_approval_status
          INTO requestor_status, branch_head_status
          FROM rfqs
         WHERE rfq_id = NEW.rfq_id
         LIMIT 1;

        IF requestor_status IS NULL OR requestor_status != 'APPROVED' THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Cannot create commitment: Requestor specification confirmation not approved';
        END IF;

        IF branch_head_status IS NULL OR branch_head_status != 'APPROVED' THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Cannot create commitment: Branch Head approval not granted';
        END IF;
    END IF;
END
$$
DELIMITER ;

-- ===================================
-- 2. Recreate stored procedures with correct column names
-- ===================================
-- Note: The old procedures sp_approve_rfq_spec_review and sp_reject_rfq_spec_review
-- are dropped and replaced with sp_approve_rfq_requestor_review and 
-- sp_reject_rfq_requestor_review to match the August 21 migration's naming convention.

DROP PROCEDURE IF EXISTS `sp_approve_rfq_spec_review`;
DROP PROCEDURE IF EXISTS `sp_approve_rfq_requestor_review`;
DELIMITER $$
CREATE PROCEDURE `sp_approve_rfq_requestor_review`(
    IN p_rfq_id INT,
    IN p_requestor_id INT,
    IN p_comments TEXT,
    IN p_quote_id INT
)
BEGIN
    START TRANSACTION;

    UPDATE rfqs
       SET requestor_spec_review_status = 'APPROVED',
           requestor_reviewer_id = p_requestor_id,
           requestor_reviewed_at = NOW(),
           requestor_review_comments = p_comments
     WHERE rfq_id = p_rfq_id;

    INSERT INTO rfq_requestor_reviews
        (rfq_id, requestor_id, review_outcome, comments, review_date, created_at, updated_at)
    VALUES
        (p_rfq_id, p_requestor_id, 'MEETS_SPECIFICATIONS', p_comments, NOW(), NOW(), NOW());

    INSERT INTO rfq_quote_approvals
        (rfq_id, quote_id, approval_stage, approver_id, action, comments, requestor_notes, created_at)
    VALUES
        (p_rfq_id, p_quote_id, 'REQUESTOR_REVIEW', p_requestor_id, 'APPROVED', p_comments, p_comments, NOW());

    COMMIT;
END
$$
DELIMITER ;

DROP PROCEDURE IF EXISTS `sp_reject_rfq_spec_review`;
DROP PROCEDURE IF EXISTS `sp_reject_rfq_requestor_review`;
DELIMITER $$
CREATE PROCEDURE `sp_reject_rfq_requestor_review`(
    IN p_rfq_id INT,
    IN p_requestor_id INT,
    IN p_reason TEXT,
    IN p_quote_id INT
)
BEGIN
    START TRANSACTION;

    UPDATE rfqs
       SET requestor_spec_review_status = 'REJECTED',
           requestor_reviewer_id = p_requestor_id,
           requestor_reviewed_at = NOW(),
           requestor_review_comments = p_reason
     WHERE rfq_id = p_rfq_id;

    INSERT INTO rfq_requestor_reviews
        (rfq_id, requestor_id, review_outcome, comments, review_date, created_at, updated_at)
    VALUES
        (p_rfq_id, p_requestor_id, 'DOES_NOT_MEET_SPECIFICATIONS', p_reason, NOW(), NOW(), NOW());

    INSERT INTO rfq_quote_approvals
        (rfq_id, quote_id, approval_stage, approver_id, action, comments, rejection_reason, requestor_notes, created_at)
    VALUES
        (p_rfq_id, p_quote_id, 'REQUESTOR_REVIEW', p_requestor_id, 'REJECTED', p_reason, p_reason, p_reason, NOW());

    COMMIT;
END
$$
DELIMITER ;

COMMIT;
