-- Migration: RFQ Requestor + Branch Head Award Approval Workflow
-- Purpose: Replace designated specification reviewer routing with requestor-owned
--          specification confirmation, followed by auto-routed Branch Head approval.
-- Note: This migration assumes the 2026_07_31 RFQ quote approval workflow migration
--       has already been applied. The CHANGE COLUMN statements are intentionally
--       non-idempotent because MySQL only runs each migration once in production.

START TRANSACTION;

-- ===================================
-- 1. Rename RFQ approval tracking columns
-- ===================================
ALTER TABLE `rfqs`
    CHANGE COLUMN `spec_review_status` `requestor_spec_review_status` ENUM('PENDING','APPROVED','REJECTED') DEFAULT 'PENDING',
    CHANGE COLUMN `spec_reviewer_id` `requestor_reviewer_id` INT(11) DEFAULT NULL,
    CHANGE COLUMN `spec_reviewed_at` `requestor_reviewed_at` DATETIME DEFAULT NULL,
    CHANGE COLUMN `spec_review_comments` `requestor_review_comments` TEXT DEFAULT NULL;

DROP INDEX `idx_rfq_spec_review_status` ON `rfqs`;
DROP INDEX `idx_rfq_spec_reviewer_id` ON `rfqs`;
CREATE INDEX `idx_rfq_requestor_spec_review_status` ON `rfqs`(`requestor_spec_review_status`);
CREATE INDEX `idx_rfq_requestor_reviewer_id` ON `rfqs`(`requestor_reviewer_id`);

-- ===================================
-- 2. Immutable requestor review history
-- ===================================
CREATE TABLE IF NOT EXISTS `rfq_requestor_reviews` (
  `rfq_requestor_review_id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `rfq_id` INT(11) NOT NULL,
  `requestor_id` INT(11) NOT NULL,
  `review_outcome` ENUM('MEETS_SPECIFICATIONS','DOES_NOT_MEET_SPECIFICATIONS') NOT NULL,
  `comments` TEXT DEFAULT NULL,
  `review_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_rfq_requestor_reviews_rfq_id` (`rfq_id`),
  KEY `idx_rfq_requestor_reviews_requestor_id` (`requestor_id`),
  CONSTRAINT `fk_rfq_requestor_reviews_rfq` FOREIGN KEY (`rfq_id`) REFERENCES `rfqs`(`rfq_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rfq_requestor_reviews_requestor` FOREIGN KEY (`requestor_id`) REFERENCES `users`(`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Immutable requestor specification confirmation submissions';

-- ===================================
-- 3. Extend RFQ approval audit trail and audit log
-- ===================================
ALTER TABLE `rfq_quote_approvals`
    MODIFY COLUMN `approval_stage` ENUM('REQUESTOR_REVIEW','BRANCH_HEAD_APPROVAL') NOT NULL COMMENT 'Which approval stage',
    ADD COLUMN IF NOT EXISTS `quote_id` INT(11) DEFAULT NULL AFTER `rfq_id`,
    ADD COLUMN IF NOT EXISTS `requestor_notes` TEXT DEFAULT NULL AFTER `comments`,
    ADD COLUMN IF NOT EXISTS `vendor_submission_details` TEXT DEFAULT NULL AFTER `requestor_notes`;

ALTER TABLE `rfq_quote_approvals`
    ADD CONSTRAINT `fk_rfq_quote_approvals_quote`
    FOREIGN KEY (`quote_id`) REFERENCES `rfq_quotes`(`quote_id`) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS `idx_rfq_quote_approvals_quote_id` ON `rfq_quote_approvals`(`quote_id`);

ALTER TABLE `audit_log`
    ADD COLUMN IF NOT EXISTS `requestor_review_outcome` VARCHAR(50) DEFAULT NULL AFTER `approval_comments`,
    ADD COLUMN IF NOT EXISTS `specification_comparison` TEXT DEFAULT NULL AFTER `requestor_review_outcome`;

-- ===================================
-- 4. Recreate workflow triggers and procedures with renamed columns
-- ===================================
DROP TRIGGER IF EXISTS `trg_initialize_rfq_approval_workflow`;
DELIMITER $$
CREATE TRIGGER `trg_initialize_rfq_approval_workflow` BEFORE INSERT ON `rfqs` FOR EACH ROW
BEGIN
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

DROP PROCEDURE IF EXISTS `sp_approve_rfq_spec_review`;
DROP PROCEDURE IF EXISTS `sp_reject_rfq_spec_review`;
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

-- ===================================
-- 5. Permissions and role mappings
-- ===================================
INSERT IGNORE INTO `permissions` (`name`, `description`) VALUES
('submit_requestor_spec_review', 'Submit the requestor specification confirmation for a selected RFQ quote'),
('view_requestor_spec_review_interface', 'Access the requestor specification confirmation interface'),
('approve_branch_head_award', 'Approve, reject, or return a selected RFQ quote as the Branch Head'),
('view_branch_head_approval_interface', 'Access the Branch Head RFQ award approval interface'),
('view_rfq_approval_audit', 'View RFQ quote approval history and audit trail'),
('assign_branch_head_approver', 'Assign or reassign RFQ Branch Head approvers'),
('override_requestor_review', 'Override requestor-only RFQ specification confirmation restrictions'),
('override_branch_head_approval', 'Override Branch Head-only RFQ approval restrictions');

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
  FROM `roles` r
  JOIN `permissions` p ON p.name = 'submit_requestor_spec_review'
 WHERE r.name IN ('Requestor', 'Procurement Officer', 'HOD', 'Admin', 'SuperAdmin');

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
  FROM `roles` r
  JOIN `permissions` p ON p.name = 'view_requestor_spec_review_interface'
 WHERE r.name IN ('Requestor', 'Procurement Officer', 'HOD', 'Admin', 'SuperAdmin');

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
  FROM `roles` r
  JOIN `permissions` p ON p.name = 'approve_branch_head_award'
 WHERE r.name IN ('HOD', 'Director HRM&A', 'Admin', 'SuperAdmin');

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
  FROM `roles` r
  JOIN `permissions` p ON p.name = 'view_branch_head_approval_interface'
 WHERE r.name IN ('HOD', 'Director HRM&A', 'Admin', 'SuperAdmin');

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
  FROM `roles` r
  JOIN `permissions` p ON p.name = 'assign_branch_head_approver'
 WHERE r.name IN ('Admin', 'SuperAdmin');

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
  FROM `roles` r
  JOIN `permissions` p ON p.name = 'override_requestor_review'
 WHERE r.name IN ('Admin', 'SuperAdmin');

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
  FROM `roles` r
  JOIN `permissions` p ON p.name = 'override_branch_head_approval'
 WHERE r.name IN ('Admin', 'SuperAdmin');

COMMIT;
