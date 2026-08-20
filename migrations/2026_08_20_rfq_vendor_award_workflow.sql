-- Migration: RFQ Vendor-Award Workflow Enhancement
-- Purpose: Implement comprehensive RFQ vendor-award workflow with all 10 stages
-- Date: 2026-08-20
-- 
-- This migration enhances the RFQ workflow to support the complete vendor-award process:
-- 1. Vendor quotation entry
-- 2. Requestor quotation review
-- 3. Quote selection
-- 4. Branch Head final approval
-- 5. Funds verification
-- 6. Commitment form
-- 7. RFQ letters
-- 8. Purchase order
-- 9. Invoice processing
-- 10. HOD approval

-- ===================================
-- 1. Extend rfqs table for all stages
-- ===================================
ALTER TABLE `rfqs` 
ADD COLUMN IF NOT EXISTS `funds_verified_status` ENUM('PENDING','APPROVED','REJECTED') DEFAULT 'PENDING' AFTER `branch_head_comments`,
ADD COLUMN IF NOT EXISTS `funds_verified_by` INT(11) DEFAULT NULL AFTER `funds_verified_status`,
ADD COLUMN IF NOT EXISTS `funds_verified_at` DATETIME DEFAULT NULL AFTER `funds_verified_by`,
ADD COLUMN IF NOT EXISTS `funds_verification_comments` TEXT DEFAULT NULL AFTER `funds_verified_at`,
ADD COLUMN IF NOT EXISTS `commitment_number` VARCHAR(50) DEFAULT NULL AFTER `funds_verification_comments`,
ADD COLUMN IF NOT EXISTS `commitment_status` ENUM('PENDING','APPROVED','REJECTED') DEFAULT 'PENDING' AFTER `commitment_number`,
ADD COLUMN IF NOT EXISTS `commitment_verified_by` INT(11) DEFAULT NULL AFTER `commitment_status`,
ADD COLUMN IF NOT EXISTS `commitment_verified_at` DATETIME DEFAULT NULL AFTER `commitment_verified_by`,
ADD COLUMN IF NOT EXISTS `commitment_comments` TEXT DEFAULT NULL AFTER `commitment_verified_at`,
ADD COLUMN IF NOT EXISTS `rfq_letter_issued_by` INT(11) DEFAULT NULL AFTER `commitment_comments`,
ADD COLUMN IF NOT EXISTS `rfq_letter_issued_at` DATETIME DEFAULT NULL AFTER `rfq_letter_issued_by`,
ADD COLUMN IF NOT EXISTS `po_number` VARCHAR(50) DEFAULT NULL AFTER `rfq_letter_issued_at`,
ADD COLUMN IF NOT EXISTS `po_created_by` INT(11) DEFAULT NULL AFTER `po_number`,
ADD COLUMN IF NOT EXISTS `po_created_at` DATETIME DEFAULT NULL AFTER `po_created_by`,
ADD COLUMN IF NOT EXISTS `invoice_checked_by` INT(11) DEFAULT NULL AFTER `po_created_at`,
ADD COLUMN IF NOT EXISTS `invoice_checked_at` DATETIME DEFAULT NULL AFTER `invoice_checked_by`,
ADD COLUMN IF NOT EXISTS `invoice_mismatch_comments` TEXT DEFAULT NULL AFTER `invoice_checked_at`,
ADD COLUMN IF NOT EXISTS `hod_approval_status` ENUM('PENDING','APPROVED','REJECTED') DEFAULT 'PENDING' AFTER `invoice_mismatch_comments`,
ADD COLUMN IF NOT EXISTS `hod_approved_by` INT(11) DEFAULT NULL AFTER `hod_approval_status`,
ADD COLUMN IF NOT EXISTS `hod_approved_at` DATETIME DEFAULT NULL AFTER `hod_approved_by`,
ADD COLUMN IF NOT EXISTS `hod_approval_comments` TEXT DEFAULT NULL AFTER `hod_approved_at`;

-- Add indexes for workflow queries
CREATE INDEX IF NOT EXISTS idx_rfq_funds_verified_status ON rfqs(funds_verified_status);
CREATE INDEX IF NOT EXISTS idx_rfq_funds_verified_by ON rfqs(funds_verified_by);
CREATE INDEX IF NOT EXISTS idx_rfq_commitment_status ON rfqs(commitment_status);
CREATE INDEX IF NOT EXISTS idx_rfq_commitment_verified_by ON rfqs(commitment_verified_by);
CREATE INDEX IF NOT EXISTS idx_rfq_po_created_by ON rfqs(po_created_by);
CREATE INDEX IF NOT EXISTS idx_rfq_hod_approval_status ON rfqs(hod_approval_status);
CREATE INDEX IF NOT EXISTS idx_rfq_hod_approved_by ON rfqs(hod_approved_by);

-- ===================================
-- 2. Extend rfq_quotes for submission tracking
-- ===================================
ALTER TABLE `rfq_quotes`
ADD COLUMN IF NOT EXISTS `submission_date` DATETIME DEFAULT CURRENT_TIMESTAMP AFTER `submitted_at`,
ADD COLUMN IF NOT EXISTS `evaluation_history` JSON DEFAULT NULL AFTER `submission_date`,
ADD COLUMN IF NOT EXISTS `requestor_evaluation_status` ENUM('PENDING','MEETS_SPECIFICATION','DOES_NOT_MEET') DEFAULT 'PENDING' AFTER `evaluation_history`,
ADD COLUMN IF NOT EXISTS `requestor_evaluated_by` INT(11) DEFAULT NULL AFTER `requestor_evaluation_status`,
ADD COLUMN IF NOT EXISTS `requestor_evaluated_at` DATETIME DEFAULT NULL AFTER `requestor_evaluated_by`,
ADD COLUMN IF NOT EXISTS `requestor_evaluation_comments` TEXT DEFAULT NULL AFTER `requestor_evaluated_at`;

CREATE INDEX IF NOT EXISTS idx_rfq_quote_requestor_evaluation ON rfq_quotes(requestor_evaluation_status);
CREATE INDEX IF NOT EXISTS idx_rfq_quote_requestor_evaluator ON rfq_quotes(requestor_evaluated_by);

-- ===================================
-- 3. Create RFQ Funds Verification Table
-- ===================================
CREATE TABLE IF NOT EXISTS `rfq_funds_verification` (
  `verification_id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `rfq_id` INT(11) NOT NULL,
  `verified_by` INT(11) NOT NULL COMMENT 'Finance Officer who verified',
  `status` ENUM('APPROVED','REJECTED') NOT NULL,
  `available_funds` DECIMAL(15,2) DEFAULT NULL COMMENT 'Confirmed available funds',
  `quote_amount` DECIMAL(15,2) NOT NULL COMMENT 'Quote amount being verified',
  `verification_comments` TEXT DEFAULT NULL COMMENT 'Finance officer comments',
  `rejection_reason` TEXT DEFAULT NULL COMMENT 'Reason if rejected',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_rfq_id` (`rfq_id`),
  KEY `idx_verified_by` (`verified_by`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_rfq_funds_verification_rfq` FOREIGN KEY (`rfq_id`) REFERENCES `rfqs`(`rfq_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rfq_funds_verification_user` FOREIGN KEY (`verified_by`) REFERENCES `users`(`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit trail for RFQ funds verification by Finance Officer';

-- ===================================
-- 4. Create RFQ Commitment Form Table
-- ===================================
CREATE TABLE IF NOT EXISTS `rfq_commitment_forms` (
  `commitment_id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `rfq_id` INT(11) NOT NULL,
  `commitment_number` VARCHAR(50) NOT NULL UNIQUE,
  `prepared_by` INT(11) NOT NULL COMMENT 'Finance Officer who prepared',
  `status` ENUM('DRAFT','PENDING_APPROVAL','APPROVED','REJECTED') DEFAULT 'DRAFT',
  `commitment_amount` DECIMAL(15,2) NOT NULL,
  `commitment_date` DATE NOT NULL,
  `account_code` VARCHAR(100) DEFAULT NULL,
  `fund_source` VARCHAR(255) DEFAULT NULL,
  `attachment_file` VARCHAR(255) DEFAULT NULL COMMENT 'Uploaded commitment form path',
  `approved_by` INT(11) DEFAULT NULL COMMENT 'Finance Officer who approved',
  `approved_at` DATETIME DEFAULT NULL,
  `approval_comments` TEXT DEFAULT NULL,
  `rejection_reason` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_rfq_id` (`rfq_id`),
  KEY `idx_status` (`status`),
  KEY `idx_commitment_number` (`commitment_number`),
  KEY `idx_prepared_by` (`prepared_by`),
  KEY `idx_approved_by` (`approved_by`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_rfq_commitment_forms_rfq` FOREIGN KEY (`rfq_id`) REFERENCES `rfqs`(`rfq_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rfq_commitment_prepared` FOREIGN KEY (`prepared_by`) REFERENCES `users`(`user_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_rfq_commitment_approved` FOREIGN KEY (`approved_by`) REFERENCES `users`(`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='RFQ commitment form tracking and approval';

-- ===================================
-- 5. Create RFQ Procurement Letters Table
-- ===================================
CREATE TABLE IF NOT EXISTS `rfq_procurement_letters` (
  `letter_id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `rfq_id` INT(11) NOT NULL,
  `letter_type` ENUM('RFQ_NOTICE','AWARD_LETTER','REJECTION_LETTER','CLARIFICATION_REQUEST','OTHER') NOT NULL,
  `letter_number` VARCHAR(50) DEFAULT NULL,
  `issued_by` INT(11) NOT NULL COMMENT 'Procurement Officer or Director who issued',
  `issued_to_vendor_id` INT(11) DEFAULT NULL COMMENT 'Vendor receiving letter, or NULL if general',
  `document_file` VARCHAR(255) NOT NULL COMMENT 'Path to issued letter document',
  `letter_date` DATE NOT NULL,
  `issued_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `acknowledgment_status` ENUM('PENDING','RECEIVED','REJECTED') DEFAULT 'PENDING',
  `acknowledged_at` DATETIME DEFAULT NULL COMMENT 'When recipient acknowledged receipt',
  `comments` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_rfq_id` (`rfq_id`),
  KEY `idx_letter_type` (`letter_type`),
  KEY `idx_issued_by` (`issued_by`),
  KEY `idx_issued_to_vendor_id` (`issued_to_vendor_id`),
  KEY `idx_issued_at` (`issued_at`),
  CONSTRAINT `fk_rfq_proc_letters_rfq` FOREIGN KEY (`rfq_id`) REFERENCES `rfqs`(`rfq_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rfq_proc_letters_issuer` FOREIGN KEY (`issued_by`) REFERENCES `users`(`user_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_rfq_proc_letters_vendor` FOREIGN KEY (`issued_to_vendor_id`) REFERENCES `vendors`(`vendor_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Auditable record of all RFQ procurement letters issued';

-- ===================================
-- 6. Create RFQ Purchase Order Table
-- ===================================
CREATE TABLE IF NOT EXISTS `rfq_purchase_orders` (
  `po_id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `rfq_id` INT(11) NOT NULL,
  `po_number` VARCHAR(50) NOT NULL UNIQUE,
  `po_date` DATE NOT NULL,
  `vendor_id` INT(11) NOT NULL COMMENT 'Selected vendor',
  `quote_id` INT(11) DEFAULT NULL COMMENT 'Reference to selected quote',
  `approved_quote_amount` DECIMAL(15,2) NOT NULL COMMENT 'Approved quote amount',
  `po_amount` DECIMAL(15,2) NOT NULL COMMENT 'PO amount (should not exceed quote without variation)',
  `variation_amount` DECIMAL(15,2) DEFAULT NULL COMMENT 'Approved variation if PO exceeds quote',
  `variation_approval_id` INT(11) DEFAULT NULL COMMENT 'Reference to variation approval',
  `delivery_date` DATE DEFAULT NULL,
  `delivery_location` VARCHAR(255) DEFAULT NULL,
  `created_by` INT(11) NOT NULL COMMENT 'Procurement Officer who created',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `approved_by` INT(11) DEFAULT NULL COMMENT 'HOD who approved PO',
  `approved_at` DATETIME DEFAULT NULL,
  `approval_comments` TEXT DEFAULT NULL,
  `rejection_reason` TEXT DEFAULT NULL,
  `status` ENUM('DRAFT','PENDING_APPROVAL','APPROVED','REJECTED','CANCELLED') DEFAULT 'DRAFT',
  KEY `idx_rfq_id` (`rfq_id`),
  KEY `idx_po_number` (`po_number`),
  KEY `idx_vendor_id` (`vendor_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_approved_by` (`approved_by`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_rfq_po_rfq` FOREIGN KEY (`rfq_id`) REFERENCES `rfqs`(`rfq_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rfq_po_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors`(`vendor_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_rfq_po_quote` FOREIGN KEY (`quote_id`) REFERENCES `rfq_quotes`(`quote_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_rfq_po_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`user_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_rfq_po_approver` FOREIGN KEY (`approved_by`) REFERENCES `users`(`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='RFQ Purchase Order tracking and approval';

-- ===================================
-- 7. Create RFQ Invoice Verification Table
-- ===================================
CREATE TABLE IF NOT EXISTS `rfq_invoice_verifications` (
  `verification_id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `rfq_id` INT(11) NOT NULL,
  `invoice_id` INT(11) DEFAULT NULL COMMENT 'Reference to invoices table if exists',
  `invoice_number` VARCHAR(50) NOT NULL,
  `verified_by` INT(11) NOT NULL COMMENT 'Finance Officer who verified',
  `verification_status` ENUM('PENDING','VERIFIED','MISMATCH_FLAGGED','APPROVED_FOR_PAYMENT') DEFAULT 'PENDING',
  `invoice_amount` DECIMAL(15,2) NOT NULL,
  `rfq_amount` DECIMAL(15,2) NOT NULL COMMENT 'Original RFQ quote amount',
  `po_amount` DECIMAL(15,2) DEFAULT NULL COMMENT 'PO amount for comparison',
  `amount_matches` TINYINT(1) DEFAULT NULL COMMENT 'True if invoice matches PO/RFQ',
  `deliverables_received` TINYINT(1) DEFAULT NULL COMMENT 'True if all goods/services received',
  `commitment_matches` TINYINT(1) DEFAULT NULL COMMENT 'True if matches commitment',
  `verification_comments` TEXT DEFAULT NULL,
  `mismatch_details` JSON DEFAULT NULL COMMENT 'JSON array of detected mismatches',
  `verified_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `approved_for_payment_at` DATETIME DEFAULT NULL,
  KEY `idx_rfq_id` (`rfq_id`),
  KEY `idx_invoice_number` (`invoice_number`),
  KEY `idx_verified_by` (`verified_by`),
  KEY `idx_status` (`verification_status`),
  KEY `idx_verified_at` (`verified_at`),
  CONSTRAINT `fk_rfq_invoice_verif_rfq` FOREIGN KEY (`rfq_id`) REFERENCES `rfqs`(`rfq_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rfq_invoice_verif_user` FOREIGN KEY (`verified_by`) REFERENCES `users`(`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Invoice verification against RFQ, PO, commitment and deliverables';

-- ===================================
-- 8. Create Branch Assignment Table (for routing approvals)
-- ===================================
CREATE TABLE IF NOT EXISTS `rfq_branch_routing_rules` (
  `rule_id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `branch_id` INT(11) NOT NULL,
  `approval_stage` VARCHAR(100) NOT NULL COMMENT 'e.g., QUOTE_APPROVAL, PO_APPROVAL',
  `responsible_role` VARCHAR(100) NOT NULL COMMENT 'e.g., Branch Head, Deputy Government Chemist',
  `alternate_role` VARCHAR(100) DEFAULT NULL COMMENT 'Escalation/backup role if primary unavailable',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `idx_branch_stage_role` (`branch_id`, `approval_stage`, `responsible_role`),
  KEY `idx_branch_id` (`branch_id`),
  KEY `idx_approval_stage` (`approval_stage`),
  KEY `idx_responsible_role` (`responsible_role`),
  CONSTRAINT `fk_rfq_branch_routing_branch` FOREIGN KEY (`branch_id`) REFERENCES `departments`(`dept_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Configurable branch-based routing rules for approvals';

-- ===================================
-- 9. Create RFQ Workflow Assignment Table (for tracking all assignments)
-- ===================================
CREATE TABLE IF NOT EXISTS `rfq_workflow_assignments` (
  `assignment_id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `rfq_id` INT(11) NOT NULL,
  `workflow_stage` VARCHAR(100) NOT NULL COMMENT 'Current workflow stage',
  `responsible_user_id` INT(11) NOT NULL,
  `responsible_role` VARCHAR(100) NOT NULL,
  `branch_id` INT(11) DEFAULT NULL COMMENT 'Branch used for routing decision',
  `routing_reason` VARCHAR(255) DEFAULT NULL COMMENT 'Why this person was selected',
  `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `due_date` DATETIME DEFAULT NULL,
  `escalation_date` DATETIME DEFAULT NULL,
  `backup_user_id` INT(11) DEFAULT NULL COMMENT 'Backup officer if primary unavailable',
  `status` ENUM('ASSIGNED','COMPLETED','ESCALATED','REASSIGNED','EXPIRED') DEFAULT 'ASSIGNED',
  `completed_at` DATETIME DEFAULT NULL,
  `completion_action` VARCHAR(50) DEFAULT NULL COMMENT 'APPROVED, REJECTED, RETURNED, etc.',
  KEY `idx_rfq_id` (`rfq_id`),
  KEY `idx_workflow_stage` (`workflow_stage`),
  KEY `idx_responsible_user_id` (`responsible_user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_assigned_at` (`assigned_at`),
  KEY `idx_due_date` (`due_date`),
  CONSTRAINT `fk_rfq_workflow_assignments_rfq` FOREIGN KEY (`rfq_id`) REFERENCES `rfqs`(`rfq_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rfq_workflow_assignments_user` FOREIGN KEY (`responsible_user_id`) REFERENCES `users`(`user_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_rfq_workflow_assignments_backup` FOREIGN KEY (`backup_user_id`) REFERENCES `users`(`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Track all workflow stage assignments with responsible officers';

-- ===================================
-- 10. Extend rfq_quote_approvals for all stages
-- ===================================
ALTER TABLE `rfq_quote_approvals`
MODIFY COLUMN `approval_stage` ENUM('SPEC_REVIEW','BRANCH_HEAD_APPROVAL','FUNDS_VERIFICATION','COMMITMENT','PO_APPROVAL','INVOICE_VERIFICATION','HOD_APPROVAL') NOT NULL COMMENT 'Which approval stage',
ADD COLUMN IF NOT EXISTS `stage_sequence` INT(11) DEFAULT NULL COMMENT 'Order in workflow',
ADD COLUMN IF NOT EXISTS `is_required` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Whether this stage is required',
ADD COLUMN IF NOT EXISTS `assignment_id` INT(11) DEFAULT NULL COMMENT 'Link to workflow assignment',
ADD COLUMN IF NOT EXISTS `branch_used` INT(11) DEFAULT NULL COMMENT 'Branch used for routing',
ADD COLUMN IF NOT EXISTS `escalation_triggered` TINYINT(1) DEFAULT 0 COMMENT 'Whether escalation occurred',
ADD KEY `idx_approval_stage_sequence` (`approval_stage`, `stage_sequence`),
ADD KEY `idx_escalation_triggered` (`escalation_triggered`);

-- ===================================
-- 11. Add audit trail columns to existing audit_log
-- ===================================
ALTER TABLE `audit_log`
ADD COLUMN IF NOT EXISTS `workflow_stage` VARCHAR(100) DEFAULT NULL AFTER `approval_stage`,
ADD COLUMN IF NOT EXISTS `approval_sequence_number` INT(11) DEFAULT NULL AFTER `workflow_stage`,
ADD COLUMN IF NOT EXISTS `responsible_officer_id` INT(11) DEFAULT NULL AFTER `approval_sequence_number`,
ADD COLUMN IF NOT EXISTS `responsible_officer_role` VARCHAR(100) DEFAULT NULL AFTER `responsible_officer_id`,
ADD COLUMN IF NOT EXISTS `branch_used_for_routing` INT(11) DEFAULT NULL AFTER `responsible_officer_role`;

CREATE INDEX IF NOT EXISTS idx_audit_workflow_stage ON audit_log(workflow_stage);
CREATE INDEX IF NOT EXISTS idx_audit_responsible_officer ON audit_log(responsible_officer_id);

-- ===================================
-- 12. Create Master RFQ Workflow Stage Configuration Table
-- ===================================
CREATE TABLE IF NOT EXISTS `rfq_workflow_stages_config` (
  `stage_id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `stage_name` VARCHAR(100) NOT NULL UNIQUE COMMENT 'Stage name in uppercase',
  `stage_display_name` VARCHAR(150) NOT NULL COMMENT 'Display name for UI',
  `stage_sequence` INT(11) NOT NULL COMMENT 'Order in workflow (1-10)',
  `responsible_role` VARCHAR(100) NOT NULL COMMENT 'Primary role responsible',
  `alternate_role` VARCHAR(100) DEFAULT NULL COMMENT 'Escalation/backup role',
  `is_required` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Must complete before proceeding',
  `can_be_skipped` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether stage can be skipped (e.g., under threshold)',
  `default_due_days` INT(11) DEFAULT 5 COMMENT 'Default days to complete stage',
  `escalation_days` INT(11) DEFAULT 3 COMMENT 'Days before escalation',
  `requires_segregation_of_duties` TINYINT(1) DEFAULT 1 COMMENT 'Cannot self-approve',
  `approval_method` ENUM('SINGLE_APPROVAL','DUAL_APPROVAL','COMMITTEE','NONE') DEFAULT 'SINGLE_APPROVAL',
  `description` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_stage_sequence` (`stage_sequence`),
  KEY `idx_responsible_role` (`responsible_role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Master configuration for RFQ workflow stages';

-- ===================================
-- 13. Populate workflow stages configuration
-- ===================================
INSERT IGNORE INTO `rfq_workflow_stages_config` 
(stage_name, stage_display_name, stage_sequence, responsible_role, alternate_role, is_required, can_be_skipped, default_due_days, escalation_days, requires_segregation_of_duties, approval_method, description)
VALUES
('VENDOR_QUOTATION_ENTRY', 'Vendor Quotation Entry', 1, 'Vendor', 'Procurement Officer', 1, 0, 30, 25, 0, 'NONE', 'Vendors submit quotations for RFQ'),
('REQUESTOR_QUOTATION_REVIEW', 'Requestor Quotation Review', 2, 'Requestor', 'Branch Head', 1, 0, 3, 2, 1, 'SINGLE_APPROVAL', 'Requestor reviews quotation against specifications'),
('QUOTE_SELECTION', 'Quote Selection', 3, 'Requestor', 'Branch Head', 1, 0, 2, 1, 1, 'SINGLE_APPROVAL', 'Requestor selects recommended quotation'),
('BRANCH_HEAD_FINAL_APPROVAL', 'Branch Head Final Approval', 4, 'Branch Head', 'Deputy Government Chemist', 1, 0, 2, 1, 1, 'SINGLE_APPROVAL', 'Branch Head provides final approval'),
('FUNDS_VERIFICATION', 'Funds Verification', 5, 'Finance Officer', NULL, 1, 0, 2, 1, 1, 'SINGLE_APPROVAL', 'Finance Officer verifies fund availability'),
('COMMITMENT_FORM', 'Commitment Form Preparation', 6, 'Finance Officer', NULL, 1, 0, 3, 2, 1, 'SINGLE_APPROVAL', 'Finance Officer prepares commitment form'),
('RFQ_LETTERS_ISSUE', 'RFQ Letters and Correspondence', 7, 'Procurement Officer', 'Director of Procurement', 1, 0, 5, 3, 0, 'SINGLE_APPROVAL', 'Procurement issues RFQ letters'),
('PURCHASE_ORDER', 'Purchase Order Creation', 8, 'Procurement Officer', 'Director of Procurement', 1, 0, 3, 2, 1, 'SINGLE_APPROVAL', 'Procurement creates purchase order'),
('INVOICE_PROCESSING', 'Invoice Processing and Verification', 9, 'Finance Officer', NULL, 1, 0, 5, 3, 1, 'SINGLE_APPROVAL', 'Finance checks invoice against RFQ/PO/Commitment'),
('HOD_APPROVAL', 'HOD Final Approval', 10, 'Government Chemist', 'Deputy Government Chemist', 1, 0, 2, 1, 1, 'SINGLE_APPROVAL', 'Head of Department provides final approval');

-- ===================================
-- 14. Add constraints and indexes for performance
-- ===================================
CREATE INDEX IF NOT EXISTS idx_rfqs_created_by_status ON rfqs(created_by, status);
CREATE INDEX IF NOT EXISTS idx_rfqs_request_id_status ON rfqs(request_id, status);

-- ===================================
-- End of Migration
-- ===================================
