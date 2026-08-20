-- Migration: RFQ Vendor Award Approval Workflow Enhancements
-- Purpose: Add missing fields and controls per business requirements
-- Date: 2026-08-20
--
-- Changes:
-- 1. Add branch_head_id to branches table for org-unit-based Branch Head assignment
-- 2. Add delivery_timeline, vendor_specifications, validity_date to rfq_quotes
-- 3. Add quote_locked flag to rfqs for locking during approval
-- 4. Add cancelled/expired status checks

-- ===================================
-- 1. Add branch_head_id to branches
-- ===================================
ALTER TABLE `branches`
ADD COLUMN IF NOT EXISTS `branch_head_id` INT(11) DEFAULT NULL COMMENT 'User ID of the branch head for this organizational unit',
ADD CONSTRAINT `fk_branches_head_user` FOREIGN KEY (`branch_head_id`) REFERENCES `users`(`user_id`) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_branches_head ON branches(branch_head_id);

-- ===================================
-- 2. Extend rfq_quotes with additional vendor quotation fields
-- ===================================
ALTER TABLE `rfq_quotes`
ADD COLUMN IF NOT EXISTS `validity_date` DATE DEFAULT NULL COMMENT 'Quote validity expiry date' AFTER `validity_days`,
ADD COLUMN IF NOT EXISTS `delivery_timeline` VARCHAR(255) DEFAULT NULL COMMENT 'Vendor proposed delivery timeline' AFTER `validity_date`,
ADD COLUMN IF NOT EXISTS `vendor_specifications` TEXT DEFAULT NULL COMMENT 'Vendor specifications/notes submitted with quote' AFTER `delivery_timeline`;

-- ===================================
-- 3. Add quote lock flag to rfqs for approval-stage locking
-- ===================================
ALTER TABLE `rfqs`
ADD COLUMN IF NOT EXISTS `quotes_locked` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = quotes locked during approval, cannot edit/re-upload' AFTER `branch_head_comments`,
ADD COLUMN IF NOT EXISTS `quotes_locked_at` DATETIME DEFAULT NULL AFTER `quotes_locked`,
ADD COLUMN IF NOT EXISTS `quotes_locked_by` INT(11) DEFAULT NULL AFTER `quotes_locked_at`;

-- ===================================
-- 4. Add approval reminder configuration table
-- ===================================
CREATE TABLE IF NOT EXISTS `approval_reminder_config` (
  `config_id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `approval_stage` VARCHAR(100) NOT NULL COMMENT 'SPEC_REVIEW or BRANCH_HEAD_APPROVAL',
  `reminder_after_hours` INT NOT NULL DEFAULT 48 COMMENT 'Send first reminder after N hours',
  `escalation_after_hours` INT NOT NULL DEFAULT 96 COMMENT 'Escalate after N hours with no action',
  `max_reminders` INT NOT NULL DEFAULT 3 COMMENT 'Max number of reminders before escalation',
  `escalate_to_role` VARCHAR(100) DEFAULT 'Admin' COMMENT 'Role to escalate to',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `idx_stage` (`approval_stage`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Configurable reminder/escalation rules for approval stages';

-- Insert default configuration
INSERT IGNORE INTO `approval_reminder_config` (`approval_stage`, `reminder_after_hours`, `escalation_after_hours`, `max_reminders`, `escalate_to_role`)
VALUES
('SPEC_REVIEW', 48, 96, 3, 'Admin'),
('BRANCH_HEAD_APPROVAL', 48, 96, 3, 'Admin');

-- ===================================
-- 5. Track reminders sent for approvals
-- ===================================
CREATE TABLE IF NOT EXISTS `approval_reminders_sent` (
  `reminder_id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `rfq_id` INT(11) NOT NULL,
  `approval_stage` VARCHAR(100) NOT NULL,
  `reminder_number` INT NOT NULL DEFAULT 1,
  `sent_to_user_id` INT(11) NOT NULL,
  `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_escalation` TINYINT(1) NOT NULL DEFAULT 0,
  KEY `idx_rfq_stage` (`rfq_id`, `approval_stage`),
  CONSTRAINT `fk_reminders_rfq` FOREIGN KEY (`rfq_id`) REFERENCES `rfqs`(`rfq_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
