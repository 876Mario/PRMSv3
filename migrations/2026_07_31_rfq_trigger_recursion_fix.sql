-- Migration: Force-fix RFQ trigger/procedure recursion (MySQL Error 1442)
-- Purpose: Guarantee that the RFQ approval-workflow triggers and stored
-- procedures are recreated with their non-recursive definitions, even on
-- environments where an earlier deployment already created objects with the
-- same names.
--
-- Background:
-- `migrations/2026_07_31_rfq_quote_approval_workflow.sql` originally used
-- `CREATE TRIGGER IF NOT EXISTS` / `CREATE PROCEDURE IF NOT EXISTS`. MySQL
-- only checks the object NAME when evaluating "IF NOT EXISTS" — if a trigger
-- or procedure with that name already existed (e.g. an older/broken version
-- that issued `UPDATE rfqs` from inside a trigger fired by an update on
-- `rfqs`), the statement is silently skipped and the broken definition is
-- left in place. That is why SQLSTATE[HY000] 1442
-- ("Can't update table 'rfqs' in stored function/trigger...") kept
-- recurring even after the workflow migration had been applied.
--
-- This migration explicitly DROPs every RFQ-related trigger/procedure by
-- name (regardless of whether it currently exists) and recreates them using
-- the safe pattern:
--   - BEFORE INSERT triggers only ever modify NEW.<column> values.
--   - No trigger issues an UPDATE/INSERT/DELETE against `rfqs` itself.
--   - Status changes on `rfqs` are performed exclusively from the
--     application/service layer (see rfq/*.php and
--     services/RFQQuoteApprovalService.php), never from within a trigger
--     that itself fires on `rfqs`.
-- ===================================================================

-- ===================================
-- 1. Drop and recreate rfqs triggers (defensive, idempotent)
-- ===================================
DROP TRIGGER IF EXISTS `trg_initialize_rfq_approval_workflow`;
DELIMITER $$
CREATE TRIGGER `trg_initialize_rfq_approval_workflow` BEFORE INSERT ON `rfqs` FOR EACH ROW
BEGIN
    -- Only sets NEW.* values before the row is written; never touches `rfqs`
    -- via a separate UPDATE/INSERT/DELETE statement.
    IF NEW.spec_review_status IS NULL THEN
        SET NEW.spec_review_status = 'PENDING';
    END IF;
    IF NEW.branch_head_approval_status IS NULL THEN
        SET NEW.branch_head_approval_status = 'PENDING';
    END IF;
END
$$
DELIMITER ;

-- ===================================
-- 2. Drop any legacy/renamed variants that may still exist from earlier,
--    unreleased attempts at this workflow (safe no-ops if absent)
-- ===================================
DROP TRIGGER IF EXISTS `trg_auto_initialize_rfq_approval`;
DROP TRIGGER IF EXISTS `trg_rfq_approval_workflow_init`;
DROP TRIGGER IF EXISTS `trg_auto_update_rfq_approval_status`;
DROP TRIGGER IF EXISTS `trg_rfq_status_sync`;
DROP TRIGGER IF EXISTS `trg_after_rfq_update`;
DROP TRIGGER IF EXISTS `trg_after_rfq_insert`;

-- ===================================
-- 3. Drop and recreate the commitments trigger that reads (but never
--    updates) rfqs
-- ===================================
DROP TRIGGER IF EXISTS `trg_require_quote_approval_for_commitment`;
DELIMITER $$
CREATE TRIGGER `trg_require_quote_approval_for_commitment` BEFORE INSERT ON `commitments` FOR EACH ROW
BEGIN
    DECLARE spec_status VARCHAR(50);
    DECLARE branch_head_status VARCHAR(50);

    IF NEW.rfq_id IS NOT NULL AND NEW.selected_quote_id IS NOT NULL THEN
        -- Read-only lookup; does not modify `rfqs`
        SELECT spec_review_status, branch_head_approval_status
        INTO spec_status, branch_head_status
        FROM rfqs
        WHERE rfq_id = NEW.rfq_id
        LIMIT 1;

        IF spec_status IS NULL OR spec_status != 'APPROVED' THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Cannot create commitment: Specification review not approved';
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
-- 4. Drop and recreate the stored procedures used for approval workflow
--    (these UPDATE `rfqs` directly from application/CLI callers, never from
--    inside a trigger fired by `rfqs`, so no recursion occurs)
-- ===================================
DROP PROCEDURE IF EXISTS `sp_approve_rfq_spec_review`;
DELIMITER $$
CREATE PROCEDURE `sp_approve_rfq_spec_review`(
    IN p_rfq_id INT,
    IN p_approver_id INT,
    IN p_comments TEXT
)
BEGIN
    START TRANSACTION;

    UPDATE rfqs
    SET spec_review_status = 'APPROVED',
        spec_reviewer_id = p_approver_id,
        spec_reviewed_at = NOW(),
        spec_review_comments = p_comments
    WHERE rfq_id = p_rfq_id;

    INSERT INTO rfq_quote_approvals
    (rfq_id, approval_stage, approver_id, action, comments, created_at)
    VALUES (p_rfq_id, 'SPEC_REVIEW', p_approver_id, 'APPROVED', p_comments, NOW());

    COMMIT;
END
$$
DELIMITER ;

DROP PROCEDURE IF EXISTS `sp_approve_rfq_branch_head`;
DELIMITER $$
CREATE PROCEDURE `sp_approve_rfq_branch_head`(
    IN p_rfq_id INT,
    IN p_approver_id INT,
    IN p_comments TEXT
)
BEGIN
    START TRANSACTION;

    UPDATE rfqs
    SET branch_head_approval_status = 'APPROVED',
        branch_head_approver_id = p_approver_id,
        branch_head_approved_at = NOW(),
        branch_head_comments = p_comments
    WHERE rfq_id = p_rfq_id;

    INSERT INTO rfq_quote_approvals
    (rfq_id, approval_stage, approver_id, action, comments, created_at)
    VALUES (p_rfq_id, 'BRANCH_HEAD_APPROVAL', p_approver_id, 'APPROVED', p_comments, NOW());

    COMMIT;
END
$$
DELIMITER ;

DROP PROCEDURE IF EXISTS `sp_reject_rfq_spec_review`;
DELIMITER $$
CREATE PROCEDURE `sp_reject_rfq_spec_review`(
    IN p_rfq_id INT,
    IN p_approver_id INT,
    IN p_reason TEXT
)
BEGIN
    START TRANSACTION;

    UPDATE rfqs
    SET spec_review_status = 'REJECTED',
        spec_reviewer_id = p_approver_id,
        spec_reviewed_at = NOW(),
        spec_review_comments = p_reason
    WHERE rfq_id = p_rfq_id;

    INSERT INTO rfq_quote_approvals
    (rfq_id, approval_stage, approver_id, action, rejection_reason, created_at)
    VALUES (p_rfq_id, 'SPEC_REVIEW', p_approver_id, 'REJECTED', p_reason, NOW());

    COMMIT;
END
$$
DELIMITER ;

DROP PROCEDURE IF EXISTS `sp_reject_rfq_branch_head`;
DELIMITER $$
CREATE PROCEDURE `sp_reject_rfq_branch_head`(
    IN p_rfq_id INT,
    IN p_approver_id INT,
    IN p_reason TEXT
)
BEGIN
    START TRANSACTION;

    UPDATE rfqs
    SET branch_head_approval_status = 'REJECTED',
        branch_head_approver_id = p_approver_id,
        branch_head_approved_at = NOW(),
        branch_head_comments = p_reason
    WHERE rfq_id = p_rfq_id;

    INSERT INTO rfq_quote_approvals
    (rfq_id, approval_stage, approver_id, action, rejection_reason, created_at)
    VALUES (p_rfq_id, 'BRANCH_HEAD_APPROVAL', p_approver_id, 'REJECTED', p_reason, NOW());

    COMMIT;
END
$$
DELIMITER ;

-- ===================================
-- 5. Sanity check: confirm no remaining trigger on `rfqs` contains a
--    self-referencing UPDATE/INSERT/DELETE statement. Run manually after
--    applying this migration to verify (informational only, no output
--    consumed by application code):
--
--   SELECT TRIGGER_NAME, EVENT_MANIPULATION, ACTION_TIMING
--   FROM information_schema.TRIGGERS
--   WHERE EVENT_OBJECT_TABLE = 'rfqs' AND TRIGGER_SCHEMA = DATABASE();
-- ===================================
