-- ============================================================
-- Migration: 2026_08_27_advance_payments.sql
-- Purpose:   Introduce Advance Payment support for Purchase Orders.
--            Payments can now be recorded against an Open PO before
--            a supplier invoice is received, without closing the PO.
-- ============================================================

-- --------------------------------------------------------
-- NEW TABLE: po_advance_payments
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `po_advance_payments` (
  `advance_payment_id`              INT(11)        NOT NULL AUTO_INCREMENT,
  `po_id`                           INT(11)        NOT NULL COMMENT 'FK → purchase_orders.po_id',
  `payment_type`                    ENUM('ADVANCE_PAYMENT','PARTIAL_PAYMENT','FINAL_PAYMENT')
                                                   NOT NULL DEFAULT 'ADVANCE_PAYMENT',
  `payment_amount`                  DECIMAL(12,2)  NOT NULL,
  `payment_date`                    DATE           NOT NULL,
  `payment_reference`               VARCHAR(50)    NOT NULL,
  `supplier_name`                   VARCHAR(255)   DEFAULT NULL COMMENT 'Denormalized at record time',
  `payment_method`                  VARCHAR(100)   DEFAULT NULL,
  `notes`                           TEXT           DEFAULT NULL,
  `supporting_document_path`        VARCHAR(500)   DEFAULT NULL COMMENT 'Relative path from document root',
  `supporting_document_original_name` VARCHAR(255) DEFAULT NULL,
  `supporting_document_file_type`   VARCHAR(100)   DEFAULT NULL COMMENT 'MIME type',
  `supporting_document_file_size`   INT(11)        DEFAULT NULL COMMENT 'File size in bytes',
  `created_by`                      INT(11)        DEFAULT NULL COMMENT 'FK → users.user_id',
  `approved_by`                     INT(11)        DEFAULT NULL COMMENT 'FK → users.user_id',
  `approved_at`                     DATETIME       DEFAULT NULL,
  `approval_comments`               TEXT           DEFAULT NULL,
  `rejection_reason`                TEXT           DEFAULT NULL,
  `status`                          ENUM('PENDING_APPROVAL','APPROVED','REJECTED','CANCELLED')
                                                   NOT NULL DEFAULT 'PENDING_APPROVAL',
  `created_at`                      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`                      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`advance_payment_id`),
  KEY `idx_ap_po_id`     (`po_id`),
  KEY `idx_ap_status`    (`status`),
  KEY `idx_ap_created_by`(`created_by`),

  CONSTRAINT `fk_ap_po`
    FOREIGN KEY (`po_id`)        REFERENCES `purchase_orders` (`po_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ap_created_by`
    FOREIGN KEY (`created_by`)   REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ap_approved_by`
    FOREIGN KEY (`approved_by`)  REFERENCES `users` (`user_id`) ON DELETE SET NULL

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Advance/partial payments recorded against a Purchase Order before invoice receipt';

-- --------------------------------------------------------
-- NEW PERMISSIONS
-- --------------------------------------------------------

INSERT IGNORE INTO `permissions` (`name`, `description`, `module`, `operation`) VALUES
('record_advance_payment',  'Record an advance or partial payment against an Open PO', 'Finance',    'create'),
('approve_advance_payment', 'Approve or reject a pending advance payment request',      'Finance',    'update');

-- --------------------------------------------------------
-- ROLE ↔ PERMISSION ASSIGNMENTS
--   Role IDs (from roles table):
--     2  = Procurement Officer
--     3  = Finance Officer
--     4  = HOD
--     5  = Admin
--     6  = SuperAdmin
--     9  = Deputy Government Chemist
--     11 = Director Procurement
--     16 = Accounts/Finance Director
-- --------------------------------------------------------

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.role_id, p.permission_id
FROM (
    SELECT 2  AS role_id UNION ALL   -- Procurement Officer
    SELECT 3             UNION ALL   -- Finance Officer
    SELECT 4             UNION ALL   -- HOD
    SELECT 5             UNION ALL   -- Admin
    SELECT 6             UNION ALL   -- SuperAdmin
    SELECT 9             UNION ALL   -- Deputy Government Chemist
    SELECT 11            UNION ALL   -- Director Procurement
    SELECT 16                        -- Accounts/Finance Director
) r
CROSS JOIN (
    SELECT id AS permission_id FROM `permissions` WHERE name = 'record_advance_payment'
) p;

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.role_id, p.permission_id
FROM (
    SELECT 3  AS role_id UNION ALL   -- Finance Officer
    SELECT 4             UNION ALL   -- HOD
    SELECT 5             UNION ALL   -- Admin
    SELECT 6             UNION ALL   -- SuperAdmin
    SELECT 9             UNION ALL   -- Deputy Government Chemist
    SELECT 11            UNION ALL   -- Director Procurement
    SELECT 16                        -- Accounts/Finance Director
) r
CROSS JOIN (
    SELECT id AS permission_id FROM `permissions` WHERE name = 'approve_advance_payment'
) p;

-- --------------------------------------------------------
-- PAGE PERMISSIONS (for admin UI)
-- --------------------------------------------------------

INSERT IGNORE INTO `page_permissions` (`page_path`, `page_title`, `permission_name`, `module`, `is_active`) VALUES
('/po/advance_payment.php',         'Record Advance Payment',   'record_advance_payment',  'Finance', 1),
('/po/approve_advance_payment.php', 'Approve Advance Payment',  'approve_advance_payment', 'Finance', 1),
('/po/download_advance_payment_doc.php', 'Download Advance Payment Document', 'view_purchase_orders', 'Finance', 1);
