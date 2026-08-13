-- =====================================================================
-- PRMS DATABASE MIGRATION: PETTY CASH RECONCILIATION DOCUMENTS
-- Database Name: u153072617_prms
-- Generated: 2026-08-13
-- Purpose: Add support for Finance Officer to attach supporting documents
--          to petty cash reconciliations
-- =====================================================================

USE `u153072617_prms`;

-- =====================================================================
-- TABLE 1: petty_cash_reconciliation_documents
-- Purpose: Store documents attached to petty cash reconciliations by Finance
-- =====================================================================
CREATE TABLE IF NOT EXISTS `petty_cash_reconciliation_documents` (
  `document_id` int(11) NOT NULL AUTO_INCREMENT,
  `reconcile_id` int(11) NOT NULL,
  `document_type` enum('RECEIPT','INVOICE','PROOF_OF_PURCHASE','CHANGE_RETURN','OTHER') NOT NULL DEFAULT 'OTHER',
  `file_name` varchar(255) NOT NULL,
  `original_file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_type` varchar(50) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
  `uploaded_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `document_notes` text,
  `is_deleted` tinyint(1) DEFAULT 0,
  `deleted_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`document_id`),
  KEY `idx_reconcile_id` (`reconcile_id`),
  KEY `idx_uploaded_by` (`uploaded_by`),
  KEY `idx_uploaded_date` (`uploaded_date`),
  KEY `idx_document_type` (`document_type`),
  CONSTRAINT `fk_recon_doc_reconciliation` FOREIGN KEY (`reconcile_id`) 
    REFERENCES `petty_cash_reconciliations` (`reconcile_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_recon_doc_uploaded_by` FOREIGN KEY (`uploaded_by`) 
    REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Supporting documents attached to petty cash reconciliations (receipts, invoices, etc.)';

-- =====================================================================
-- TABLE 2: petty_cash_reconciliation_verifications
-- Purpose: Track Finance Officer verification actions on reconciliations
-- =====================================================================
CREATE TABLE IF NOT EXISTS `petty_cash_reconciliation_verifications` (
  `verification_id` int(11) NOT NULL AUTO_INCREMENT,
  `reconcile_id` int(11) NOT NULL,
  `verified_by` int(11) NOT NULL,
  `verification_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `verification_status` enum('APPROVED','REJECTED_DISCREPANCY','RETURNED_FOR_CORRECTION') NOT NULL,
  `verification_notes` text,
  `discrepancy_amount` decimal(15,2) DEFAULT NULL COMMENT 'Amount of discrepancy if rejected',
  `required_action` text COMMENT 'What requestor needs to do to resolve',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`verification_id`),
  UNIQUE KEY `uq_reconcile_verification` (`reconcile_id`),
  KEY `idx_verified_by` (`verified_by`),
  KEY `idx_verification_date` (`verification_date`),
  KEY `idx_verification_status` (`verification_status`),
  CONSTRAINT `fk_verification_reconciliation` FOREIGN KEY (`reconcile_id`) 
    REFERENCES `petty_cash_reconciliations` (`reconcile_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_verification_verified_by` FOREIGN KEY (`verified_by`) 
    REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Finance Officer verification records for petty cash reconciliations';

-- =====================================================================
-- INDEX OPTIMIZATION
-- =====================================================================
CREATE INDEX idx_reconciliation_status_verification ON petty_cash_reconciliations(status, verified_by, verification_date);

-- =====================================================================
-- END OF MIGRATION
-- =====================================================================
