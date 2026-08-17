-- ============================================================
-- Migration: 2026_08_17_reimbursement_invoice_attachments.sql
-- Purpose : Create reimbursement_invoice_attachments table
--           to support optional document upload functionality
--           for reimbursement invoice submissions.
-- ============================================================

-- --------------------------------------------------------
-- Table: reimbursement_invoice_attachments
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `reimbursement_invoice_attachments` (
  `id`                int(11)       NOT NULL AUTO_INCREMENT,
  `reimb_invoice_id`  int(11)       NOT NULL,
  `file_name`         varchar(255)  NOT NULL COMMENT 'Unique server-side file name',
  `original_file_name` varchar(255) NOT NULL COMMENT 'Original name provided by user',
  `file_path`         varchar(500)  NOT NULL COMMENT 'Relative path from document root',
  `file_type`         varchar(100)  NOT NULL COMMENT 'MIME type',
  `file_size`         int(11)       NOT NULL COMMENT 'File size in bytes',
  `uploaded_by`       int(11)       DEFAULT NULL,
  `uploaded_date`     timestamp     NOT NULL DEFAULT current_timestamp(),
  `is_deleted`        tinyint(1)    NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_reimb_invoice_attachments_id` (`reimb_invoice_id`),
  KEY `idx_reimb_invoice_attachments_uploaded_by` (`uploaded_by`),
  CONSTRAINT `fk_reimb_inv_att_invoice` FOREIGN KEY (`reimb_invoice_id`) REFERENCES `reimbursement_invoices` (`reimb_invoice_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reimb_inv_att_user`    FOREIGN KEY (`uploaded_by`) REFERENCES `users`   (`user_id`)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Attachments for reimbursement invoice submissions';

-- --------------------------------------------------------
-- Permissions for attachment actions
-- --------------------------------------------------------
INSERT IGNORE INTO `permissions` (`name`, `description`) VALUES
  ('upload_reimbursement_invoice_attachment',  'Upload documents to reimbursement invoice records'),
  ('delete_reimbursement_invoice_attachment',  'Delete reimbursement invoice attachment documents');
