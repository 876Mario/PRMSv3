-- Migration: RFQ Quote Review and Approval Workflow
-- Purpose: Implement two-step approval process for RFQ quotes
-- Date: 2026-07-31
-- 
-- This migration adds comprehensive support for a two-stage approval workflow:
-- Stage 1: Specification Review (designated reviewer validates quote compliance)
-- Stage 2: Branch Head Final Approval (branch head gives final approval)

-- ===================================
-- 1. Extend rfqs table for approval tracking
-- ===================================
ALTER TABLE `rfqs` 
ADD COLUMN IF NOT EXISTS `spec_review_status` ENUM('PENDING','APPROVED','REJECTED') DEFAULT 'PENDING' AFTER `quote_review_status`,
ADD COLUMN IF NOT EXISTS `spec_reviewer_id` INT(11) DEFAULT NULL AFTER `spec_review_status`,
ADD COLUMN IF NOT EXISTS `spec_reviewed_at` DATETIME DEFAULT NULL AFTER `spec_reviewer_id`,
ADD COLUMN IF NOT EXISTS `spec_review_comments` TEXT DEFAULT NULL AFTER `spec_reviewed_at`,
ADD COLUMN IF NOT EXISTS `branch_head_approval_status` ENUM('PENDING','APPROVED','REJECTED') DEFAULT 'PENDING' AFTER `spec_review_comments`,
ADD COLUMN IF NOT EXISTS `branch_head_approver_id` INT(11) DEFAULT NULL AFTER `branch_head_approval_status`,
ADD COLUMN IF NOT EXISTS `branch_head_approved_at` DATETIME DEFAULT NULL AFTER `branch_head_approver_id`,
ADD COLUMN IF NOT EXISTS `branch_head_comments` TEXT DEFAULT NULL AFTER `branch_head_approved_at`;

-- Add indexes for approval workflow queries
CREATE INDEX IF NOT EXISTS idx_rfq_spec_review_status ON rfqs(spec_review_status);
CREATE INDEX IF NOT EXISTS idx_rfq_spec_reviewer_id ON rfqs(spec_reviewer_id);
CREATE INDEX IF NOT EXISTS idx_rfq_branch_head_approval_status ON rfqs(branch_head_approval_status);
CREATE INDEX IF NOT EXISTS idx_rfq_branch_head_approver_id ON rfqs(branch_head_approver_id);

-- ===================================
-- 2. Create RFQ Quote Approval History Table
-- ===================================
-- Tracks all approval actions for individual quotes and the overall RFQ
CREATE TABLE IF NOT EXISTS `rfq_quote_approvals` (
  `approval_id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `rfq_id` INT(11) NOT NULL,
  `approval_stage` ENUM('SPEC_REVIEW','BRANCH_HEAD_APPROVAL') NOT NULL COMMENT 'Which approval stage',
  `approver_id` INT(11) NOT NULL COMMENT 'User who performed the action',
  `approver_role` VARCHAR(100) DEFAULT NULL COMMENT 'Role of approver (Specification Reviewer, Branch Head, etc.)',
  `action` ENUM('APPROVED','REJECTED','RETURNED_FOR_CLARIFICATION') NOT NULL COMMENT 'Approval action taken',
  `comments` TEXT DEFAULT NULL COMMENT 'Comments/reason for rejection or return',
  `rejection_reason` TEXT DEFAULT NULL COMMENT 'Specific reason for rejection',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_rfq_id` (`rfq_id`),
  KEY `idx_approval_stage` (`approval_stage`),
  KEY `idx_approver_id` (`approver_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_rfq_quote_approvals_rfq` FOREIGN KEY (`rfq_id`) REFERENCES `rfqs`(`rfq_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rfq_quote_approvals_approver` FOREIGN KEY (`approver_id`) REFERENCES `users`(`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit trail for RFQ quote approval workflow';

-- ===================================
-- 3. Create RFQ Specification Review Assignments Table
-- ===================================
-- Tracks who is assigned to review specs for an RFQ
CREATE TABLE IF NOT EXISTS `rfq_spec_reviewers` (
  `assignment_id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `rfq_id` INT(11) NOT NULL,
  `reviewer_id` INT(11) NOT NULL COMMENT 'User assigned as spec reviewer',
  `reviewer_role` VARCHAR(100) NOT NULL COMMENT 'Role: Specification Reviewer, Procurement Officer, etc.',
  `assigned_by` INT(11) NOT NULL COMMENT 'Admin who made the assignment',
  `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY `idx_rfq_reviewer` (`rfq_id`, `reviewer_id`),
  KEY `idx_reviewer_id` (`reviewer_id`),
  KEY `idx_assigned_by` (`assigned_by`),
  CONSTRAINT `fk_rfq_spec_reviewers_rfq` FOREIGN KEY (`rfq_id`) REFERENCES `rfqs`(`rfq_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rfq_spec_reviewers_reviewer` FOREIGN KEY (`reviewer_id`) REFERENCES `users`(`user_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_rfq_spec_reviewers_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `users`(`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Track spec review assignments for RFQs';

-- ===================================
-- 4. Create RFQ Branch Head Assignment Table
-- ===================================
-- Tracks which branch head (or their designate) approves the RFQ
CREATE TABLE IF NOT EXISTS `rfq_branch_head_approvers` (
  `assignment_id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `rfq_id` INT(11) NOT NULL,
  `approver_id` INT(11) NOT NULL COMMENT 'User assigned as branch head approver',
  `approver_role` VARCHAR(100) NOT NULL COMMENT 'Role: Branch Head, Director, etc.',
  `assigned_by` INT(11) NOT NULL COMMENT 'Admin who made the assignment',
  `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY `idx_rfq_approver` (`rfq_id`, `approver_id`),
  KEY `idx_approver_id` (`approver_id`),
  KEY `idx_assigned_by` (`assigned_by`),
  CONSTRAINT `fk_rfq_branch_head_approvers_rfq` FOREIGN KEY (`rfq_id`) REFERENCES `rfqs`(`rfq_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rfq_branch_head_approvers_approver` FOREIGN KEY (`approver_id`) REFERENCES `users`(`user_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_rfq_branch_head_approvers_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `users`(`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Track branch head approval assignments for RFQs';

-- ===================================
-- 5. Add audit log columns for tracking approval events
-- ===================================
-- These ensure audit trail captures all approval workflow events
ALTER TABLE `audit_log`
ADD COLUMN IF NOT EXISTS `approval_stage` VARCHAR(100) DEFAULT NULL AFTER `description`,
ADD COLUMN IF NOT EXISTS `approval_action` VARCHAR(50) DEFAULT NULL AFTER `approval_stage`,
ADD COLUMN IF NOT EXISTS `approval_comments` TEXT DEFAULT NULL AFTER `approval_action`;

CREATE INDEX IF NOT EXISTS idx_audit_approval_stage ON audit_log(approval_stage);
CREATE INDEX IF NOT EXISTS idx_audit_approval_action ON audit_log(approval_action);

-- ===================================
-- 6. Update workflow transitions for quote approval stages
-- ===================================
-- Transition rules are managed in config/workflow.php
-- Documentation: 
-- - QUOTE_REVIEW_PENDING → QUOTE_SPEC_REVIEW_PENDING (when quotes uploaded)
-- - QUOTE_SPEC_REVIEW_PENDING → QUOTE_SPEC_REVIEW_APPROVED (when spec review approved)
-- - QUOTE_SPEC_REVIEW_APPROVED → QUOTE_BRANCH_HEAD_APPROVAL_PENDING (auto-route to branch head)
-- - QUOTE_BRANCH_HEAD_APPROVAL_PENDING → QUOTE_APPROVED (when branch head approves)
-- - Any stage → QUOTE_REVIEW_PENDING (when rejected/returned for clarification)

-- ===================================
-- 7. Triggers for workflow automation
-- ===================================

-- Auto-initialize approval status when RFQ is created
-- NOTE: Using BEFORE INSERT trigger to avoid MySQL error 1442
-- (Cannot update table in trigger when table is used by statement that invoked trigger)
DELIMITER $$
CREATE TRIGGER IF NOT EXISTS `trg_initialize_rfq_approval_workflow` BEFORE INSERT ON `rfqs` FOR EACH ROW
BEGIN
    -- When RFQ is created, initialize approval statuses using SET NEW
    -- This ensures the default values are set before row insertion
    IF NEW.spec_review_status IS NULL THEN
        SET NEW.spec_review_status = 'PENDING';
    END IF;
    IF NEW.branch_head_approval_status IS NULL THEN
        SET NEW.branch_head_approval_status = 'PENDING';
    END IF;
END
$$
DELIMITER ;

-- Auto-initialize approvals when first quote is uploaded
-- NOTE: This trigger was removed to fix MySQL Error 1442 (Cannot update table in trigger).
-- The specification review initialization is now handled in rfq/upload_quote.php (lines 106-148)
-- when the first quote is uploaded, avoiding recursive trigger execution.
-- See: rfq/upload_quote.php::uploadQuote() for the application-layer implementation.

-- Prevent commitment creation until both approvals are complete
DELIMITER $$
CREATE TRIGGER IF NOT EXISTS `trg_require_quote_approval_for_commitment` BEFORE INSERT ON `commitments` FOR EACH ROW
BEGIN
    DECLARE spec_status VARCHAR(50);
    DECLARE branch_head_status VARCHAR(50);
    
    IF NEW.rfq_id IS NOT NULL AND NEW.selected_quote_id IS NOT NULL THEN
        -- Get approval statuses for this RFQ
        SELECT spec_review_status, branch_head_approval_status
        INTO spec_status, branch_head_status
        FROM rfqs
        WHERE rfq_id = NEW.rfq_id
        LIMIT 1;
        
        -- Check if both approvals are complete
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
-- 8. Create stored procedure for approval workflow
-- ===================================
DELIMITER $$
CREATE PROCEDURE IF NOT EXISTS `sp_approve_rfq_spec_review`(
    IN p_rfq_id INT,
    IN p_approver_id INT,
    IN p_comments TEXT
)
BEGIN
    START TRANSACTION;
    
    -- Update RFQ spec review status
    UPDATE rfqs
    SET spec_review_status = 'APPROVED',
        spec_reviewer_id = p_approver_id,
        spec_reviewed_at = NOW(),
        spec_review_comments = p_comments
    WHERE rfq_id = p_rfq_id;
    
    -- Log approval in audit table
    INSERT INTO rfq_quote_approvals 
    (rfq_id, approval_stage, approver_id, action, comments, created_at)
    VALUES (p_rfq_id, 'SPEC_REVIEW', p_approver_id, 'APPROVED', p_comments, NOW());
    
    COMMIT;
END
$$
DELIMITER ;

DELIMITER $$
CREATE PROCEDURE IF NOT EXISTS `sp_approve_rfq_branch_head`(
    IN p_rfq_id INT,
    IN p_approver_id INT,
    IN p_comments TEXT
)
BEGIN
    START TRANSACTION;
    
    -- Update RFQ branch head approval status
    UPDATE rfqs
    SET branch_head_approval_status = 'APPROVED',
        branch_head_approver_id = p_approver_id,
        branch_head_approved_at = NOW(),
        branch_head_comments = p_comments
    WHERE rfq_id = p_rfq_id;
    
    -- Log approval in audit table
    INSERT INTO rfq_quote_approvals 
    (rfq_id, approval_stage, approver_id, action, comments, created_at)
    VALUES (p_rfq_id, 'BRANCH_HEAD_APPROVAL', p_approver_id, 'APPROVED', p_comments, NOW());
    
    COMMIT;
END
$$
DELIMITER ;

DELIMITER $$
CREATE PROCEDURE IF NOT EXISTS `sp_reject_rfq_spec_review`(
    IN p_rfq_id INT,
    IN p_approver_id INT,
    IN p_reason TEXT
)
BEGIN
    START TRANSACTION;
    
    -- Update RFQ spec review status
    UPDATE rfqs
    SET spec_review_status = 'REJECTED',
        spec_reviewer_id = p_approver_id,
        spec_reviewed_at = NOW(),
        spec_review_comments = p_reason
    WHERE rfq_id = p_rfq_id;
    
    -- Log rejection in audit table
    INSERT INTO rfq_quote_approvals 
    (rfq_id, approval_stage, approver_id, action, rejection_reason, created_at)
    VALUES (p_rfq_id, 'SPEC_REVIEW', p_approver_id, 'REJECTED', p_reason, NOW());
    
    COMMIT;
END
$$
DELIMITER ;

DELIMITER $$
CREATE PROCEDURE IF NOT EXISTS `sp_reject_rfq_branch_head`(
    IN p_rfq_id INT,
    IN p_approver_id INT,
    IN p_reason TEXT
)
BEGIN
    START TRANSACTION;
    
    -- Update RFQ branch head approval status
    UPDATE rfqs
    SET branch_head_approval_status = 'REJECTED',
        branch_head_approver_id = p_approver_id,
        branch_head_approved_at = NOW(),
        branch_head_comments = p_reason
    WHERE rfq_id = p_rfq_id;
    
    -- Log rejection in audit table
    INSERT INTO rfq_quote_approvals 
    (rfq_id, approval_stage, approver_id, action, rejection_reason, created_at)
    VALUES (p_rfq_id, 'BRANCH_HEAD_APPROVAL', p_approver_id, 'REJECTED', p_reason, NOW());
    
    COMMIT;
END
$$
DELIMITER ;

-- ===================================
-- 9. Permissions and documentation
-- ===================================
-- Note: Permissions are managed via the permissions table
-- New permissions to add/verify:
-- - approve_rfq_spec_review: Access to spec review approval interface
-- - approve_rfq_branch_head: Access to branch head approval interface
-- - assign_rfq_spec_reviewer: Ability to assign spec reviewers
-- - assign_rfq_branch_head_approver: Ability to assign branch head approvers
-- - view_rfq_approval_audit: View approval audit trail

COMMIT;
