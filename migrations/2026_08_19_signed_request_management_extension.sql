-- ============================================================
-- Migration: 2026_08_19_signed_request_management_extension.sql
-- Purpose: Extend Signed Request Management to REIMBURSEMENT and PETTY_CASH
--          Add versioning, audit trail, and admin editing support
-- ============================================================

-- --------------------------------------------------------
-- ALTER procurement_requests: Add versioning and tracking
-- --------------------------------------------------------

ALTER TABLE `procurement_requests` 
ADD COLUMN IF NOT EXISTS `signed_request_version_count` INT(11) DEFAULT 0 COMMENT 'Number of times signed request has been uploaded' AFTER `signed_by_user_id`,
ADD COLUMN IF NOT EXISTS `signed_request_active_since` DATETIME DEFAULT NULL COMMENT 'When the current signed request document became active' AFTER `signed_request_version_count`;

-- --------------------------------------------------------
-- NEW TABLE: signed_request_documents
-- Purpose: Track version history, replacements, and audit trail for all request types
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `signed_request_documents` (
  `doc_id` INT(11) NOT NULL AUTO_INCREMENT,
  `request_id` INT(11) NOT NULL COMMENT 'Foreign key to procurement_requests.request_id',
  `request_type` ENUM('REGULAR', 'REIMBURSEMENT', 'PETTY_CASH') NOT NULL COMMENT 'Type of request',
  `document_path` VARCHAR(500) NOT NULL COMMENT 'Relative path to uploaded file',
  `file_name` VARCHAR(255) NOT NULL COMMENT 'Generated secure filename',
  `original_file_name` VARCHAR(255) NOT NULL COMMENT 'User-provided filename',
  `file_type` VARCHAR(100) NOT NULL COMMENT 'MIME type (pdf, image, document)',
  `file_size` INT(11) NOT NULL COMMENT 'File size in bytes',
  `version_number` INT(11) NOT NULL COMMENT 'Version sequence for this request',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=current active document, 0=superseded',
  `uploaded_by_user_id` INT(11) NOT NULL,
  `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Soft delete flag',
  `deleted_by_user_id` INT(11) DEFAULT NULL,
  `deleted_at` TIMESTAMP DEFAULT NULL,
  
  PRIMARY KEY (`doc_id`),
  KEY `idx_request_id` (`request_id`),
  KEY `idx_request_type` (`request_type`),
  KEY `idx_uploaded_by` (`uploaded_by_user_id`),
  KEY `idx_uploaded_at` (`uploaded_at`),
  
  CONSTRAINT `fk_signed_doc_request` FOREIGN KEY (`request_id`) 
    REFERENCES `procurement_requests` (`request_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_signed_doc_uploader` FOREIGN KEY (`uploaded_by_user_id`) 
    REFERENCES `users` (`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Version history and audit trail for signed request documents across all request types';

-- --------------------------------------------------------
-- NEW TABLE: admin_edits_log
-- Purpose: Comprehensive audit trail for administrative edits
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `admin_edits_log` (
  `edit_id` BIGINT(20) NOT NULL AUTO_INCREMENT,
  `request_id` INT(11) NOT NULL,
  `request_type` ENUM('REGULAR', 'REIMBURSEMENT', 'PETTY_CASH') NOT NULL,
  `table_name` VARCHAR(100) NOT NULL COMMENT 'Table being modified (e.g., procurement_requests)',
  `field_name` VARCHAR(100) NOT NULL COMMENT 'Column name being changed',
  `old_value` LONGTEXT DEFAULT NULL COMMENT 'Previous value (may be truncated for large values)',
  `new_value` LONGTEXT DEFAULT NULL COMMENT 'New value (may be truncated for large values)',
  `changed_by_user_id` INT(11) NOT NULL,
  `changed_by_role` VARCHAR(100) NOT NULL,
  `change_timestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `change_ip` VARCHAR(45) DEFAULT NULL COMMENT 'IPv4 or IPv6 address',
  `change_user_agent` VARCHAR(500) DEFAULT NULL COMMENT 'Browser/user agent info',
  `affected_approvals` JSON DEFAULT NULL COMMENT 'JSON array of approval records that were invalidated',
  `edit_notes` TEXT DEFAULT NULL COMMENT 'Admin-provided reason for edit (if any)',
  `edit_reason_code` VARCHAR(50) DEFAULT NULL COMMENT 'Standardized reason (CORRECTION, OVERRIDE, COMPLIANCE, etc.)',
  
  PRIMARY KEY (`edit_id`),
  KEY `idx_request_id` (`request_id`),
  KEY `idx_request_type` (`request_type`),
  KEY `idx_changed_by_user_id` (`changed_by_user_id`),
  KEY `idx_change_timestamp` (`change_timestamp`),
  KEY `idx_table_field` (`table_name`, `field_name`),
  
  CONSTRAINT `fk_admin_edit_request` FOREIGN KEY (`request_id`) 
    REFERENCES `procurement_requests` (`request_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_admin_edit_user` FOREIGN KEY (`changed_by_user_id`) 
    REFERENCES `users` (`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Append-only audit log of administrative edits to requests';

-- --------------------------------------------------------
-- PERMISSIONS: Add new permissions for signed request operations
-- --------------------------------------------------------

INSERT IGNORE INTO `permissions` (`name`, `description`) VALUES
  ('upload_signed_reimbursement_document', 'Upload signed reimbursement request documents'),
  ('upload_signed_petty_cash_document', 'Upload signed petty cash request documents'),
  ('print_reimbursement_approval_form', 'Print approval forms for reimbursement requests'),
  ('print_petty_cash_approval_form', 'Print approval forms for petty cash requests'),
  ('edit_reimbursement_request_admin', 'Edit reimbursement requests as administrator'),
  ('edit_petty_cash_request_admin', 'Edit petty cash requests as administrator'),
  ('view_admin_edits_log', 'View administrative edit audit trails'),
  ('export_signed_request_documents', 'Export signed request document records');

-- --------------------------------------------------------
-- INDEXES: Performance optimization for audit queries
-- --------------------------------------------------------

CREATE INDEX IF NOT EXISTS idx_admin_edits_change_timestamp ON admin_edits_log(change_timestamp DESC);
CREATE INDEX IF NOT EXISTS idx_signed_docs_request_active ON signed_request_documents(request_id, is_active);

-- ============================================================
-- AUDIT LOG ENTRY: Document this migration
-- ============================================================

INSERT INTO audit_log (table_name, record_id, action, notes)
VALUES ('DATABASE', 0, 'SCHEMA_CHANGE', 
  CONCAT('Migrated signed request management to support REIMBURSEMENT and PETTY_CASH request types. ',
         'Added signed_request_documents table for versioning, admin_edits_log table for audit trail, ',
         'and new permissions for signed document and admin operations.'));

-- ============================================================
-- VERIFICATION QUERIES
-- ============================================================
-- Verify new columns in procurement_requests:
-- SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
-- WHERE TABLE_NAME = 'procurement_requests' 
-- AND COLUMN_NAME IN ('signed_request_version_count', 'signed_request_active_since');

-- Verify new tables exist:
-- SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES 
-- WHERE TABLE_NAME IN ('signed_request_documents', 'admin_edits_log') 
-- AND TABLE_SCHEMA = DATABASE();

-- Verify new permissions:
-- SELECT COUNT(*) as permission_count FROM permissions 
-- WHERE name IN (
--   'upload_signed_reimbursement_document',
--   'upload_signed_petty_cash_document',
--   'edit_reimbursement_request_admin',
--   'edit_petty_cash_request_admin'
-- );
