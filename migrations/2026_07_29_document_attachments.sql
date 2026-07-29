-- ============================================================
-- Migration: 2026_07_29_document_attachments.sql
-- Purpose : Create InvoiceAttachments and PaymentVoucherAttachments
--           tables to support document upload functionality.
-- ============================================================

-- --------------------------------------------------------
-- Table: invoice_attachments
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `invoice_attachments` (
  `id`                int(11)       NOT NULL AUTO_INCREMENT,
  `invoice_id`        int(11)       NOT NULL,
  `file_name`         varchar(255)  NOT NULL COMMENT 'Unique server-side file name',
  `original_file_name` varchar(255) NOT NULL COMMENT 'Original name provided by user',
  `file_path`         varchar(500)  NOT NULL COMMENT 'Relative path from document root',
  `file_type`         varchar(100)  NOT NULL COMMENT 'MIME type',
  `file_size`         int(11)       NOT NULL COMMENT 'File size in bytes',
  `uploaded_by`       int(11)       DEFAULT NULL,
  `uploaded_date`     timestamp     NOT NULL DEFAULT current_timestamp(),
  `is_deleted`        tinyint(1)    NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_invoice_attachments_invoice_id` (`invoice_id`),
  KEY `idx_invoice_attachments_uploaded_by` (`uploaded_by`),
  CONSTRAINT `fk_inv_att_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`invoice_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inv_att_user`    FOREIGN KEY (`uploaded_by`) REFERENCES `users`   (`user_id`)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: payment_voucher_attachments
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payment_voucher_attachments` (
  `id`                int(11)       NOT NULL AUTO_INCREMENT,
  `payment_id`        int(11)       NOT NULL,
  `file_name`         varchar(255)  NOT NULL COMMENT 'Unique server-side file name',
  `original_file_name` varchar(255) NOT NULL COMMENT 'Original name provided by user',
  `file_path`         varchar(500)  NOT NULL COMMENT 'Relative path from document root',
  `file_type`         varchar(100)  NOT NULL COMMENT 'MIME type',
  `file_size`         int(11)       NOT NULL COMMENT 'File size in bytes',
  `uploaded_by`       int(11)       DEFAULT NULL,
  `uploaded_date`     timestamp     NOT NULL DEFAULT current_timestamp(),
  `is_deleted`        tinyint(1)    NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_pva_payment_id` (`payment_id`),
  KEY `idx_pva_uploaded_by` (`uploaded_by`),
  CONSTRAINT `fk_pva_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments`  (`payment_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pva_user`    FOREIGN KEY (`uploaded_by`) REFERENCES `users`     (`user_id`)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Permissions for attachment actions
-- --------------------------------------------------------
INSERT IGNORE INTO `permissions` (`name`, `description`) VALUES
  ('upload_invoice_attachment',  'Upload documents to invoice records'),
  ('delete_invoice_attachment',  'Delete invoice attachment documents'),
  ('upload_payment_voucher',     'Upload payment voucher documents'),
  ('delete_payment_voucher',     'Delete payment voucher documents');
