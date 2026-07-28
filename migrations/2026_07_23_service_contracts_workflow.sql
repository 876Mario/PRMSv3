-- ============================================================================
-- Migration: Service Contracts Workflow
-- Database: PRMSv3
-- Date: 2026-07-23
-- Purpose: Add SERVICE_CONTRACT request type and supporting tables for
--          contractor payment workflow (Request → Approval → Commitment → Invoice → Payment)
-- ============================================================================

-- 1. Add SERVICE_CONTRACT to request_type enum
ALTER TABLE `procurement_requests`
MODIFY COLUMN `request_type` ENUM('REGULAR', 'REIMBURSEMENT', 'PETTY_CASH', 'SERVICE_CONTRACT')
NOT NULL DEFAULT 'REGULAR'
COMMENT 'Type of request: REGULAR procurement, REIMBURSEMENT, PETTY_CASH, or SERVICE_CONTRACT';

-- 2. Create service_contracts table
CREATE TABLE IF NOT EXISTS `service_contracts` (
    `contract_id` INT(11) NOT NULL AUTO_INCREMENT,
    `contract_number` VARCHAR(50) NOT NULL,
    `contract_title` VARCHAR(255) NOT NULL,
    `vendor_id` INT(11) NOT NULL,
    `branch_id` INT(11) NOT NULL COMMENT 'Department responsible for this contract',
    `contract_type` ENUM('FIXED_PRICE','TIME_MATERIALS','RETAINER','UNIT_RATE') NOT NULL DEFAULT 'FIXED_PRICE',
    `description` TEXT DEFAULT NULL,
    `total_value` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `consumed_value` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `currency` ENUM('JMD','USD') NOT NULL DEFAULT 'JMD',
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `payment_terms` INT(11) DEFAULT 30 COMMENT 'Payment terms in days',
    `billing_frequency` ENUM('MONTHLY','QUARTERLY','MILESTONE','ON_DELIVERY') DEFAULT 'MONTHLY',
    `status` ENUM('DRAFT','ACTIVE','EXPIRED','TERMINATED','SUSPENDED') NOT NULL DEFAULT 'DRAFT',
    `document_path` VARCHAR(500) DEFAULT NULL COMMENT 'Path to uploaded contract document',
    `notes` TEXT DEFAULT NULL,
    `created_by` INT(11) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`contract_id`),
    UNIQUE KEY `uk_contract_number` (`contract_number`),
    KEY `idx_vendor` (`vendor_id`),
    KEY `idx_branch` (`branch_id`),
    KEY `idx_status` (`status`),
    KEY `idx_dates` (`start_date`, `end_date`),
    CONSTRAINT `fk_sc_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors`(`vendor_id`),
    CONSTRAINT `fk_sc_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches`(`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Add contract_id to commitments table (optional link to service contract)
ALTER TABLE `commitments`
ADD COLUMN `contract_id` INT(11) DEFAULT NULL COMMENT 'Link to service contract (for SERVICE_CONTRACT requests)'
AFTER `document_path`;

ALTER TABLE `commitments`
ADD KEY `idx_contract` (`contract_id`);

-- 4. Modify invoices table: make po_id nullable, add commitment_id and contract_id
ALTER TABLE `invoices`
MODIFY COLUMN `po_id` INT(11) DEFAULT NULL COMMENT 'PO reference (NULL for service contract invoices)';

ALTER TABLE `invoices`
ADD COLUMN `commitment_id` INT(11) DEFAULT NULL COMMENT 'Direct commitment link (for SERVICE_CONTRACT invoices without PO)'
AFTER `po_id`;

ALTER TABLE `invoices`
ADD COLUMN `contract_id` INT(11) DEFAULT NULL COMMENT 'Link to service contract'
AFTER `commitment_id`;

ALTER TABLE `invoices`
ADD KEY `idx_commitment` (`commitment_id`),
ADD KEY `idx_contract` (`contract_id`);

-- 5. Add contract_id to procurement_requests for linking
ALTER TABLE `procurement_requests`
ADD COLUMN `contract_id` INT(11) DEFAULT NULL COMMENT 'Link to service contract (for SERVICE_CONTRACT type)'
AFTER `usd_rate`;

-- 6. Add permissions for service contracts
INSERT IGNORE INTO `permissions` (`name`, `description`) VALUES
('view_contracts', 'View service contracts'),
('manage_contracts', 'Create and edit service contracts'),
('create_service_request', 'Create service contract payment request');

-- 7. Assign permissions to roles
-- Finance Officer, Procurement Officer, Admin, SuperAdmin get manage_contracts
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.role_name IN ('Finance Officer', 'Procurement Officer', 'Admin', 'SuperAdmin')
AND p.name IN ('view_contracts', 'manage_contracts', 'create_service_request');

-- All staff can view contracts
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE p.name = 'view_contracts'
AND r.role_name NOT IN ('Finance Officer', 'Procurement Officer', 'Admin', 'SuperAdmin');

-- Requestors can create service requests
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE p.name = 'create_service_request'
AND r.role_name IN ('Requestor', 'HOD', 'Branch Head', 'Director HRM&A', 'Deputy Government Chemist');

-- 8. Add page permissions for contracts module
INSERT IGNORE INTO `page_permissions` (`page_path`, `permission_name`, `created_at`) VALUES
('/contracts/list.php', 'view_contracts', NOW()),
('/contracts/add.php', 'manage_contracts', NOW()),
('/contracts/view.php', 'view_contracts', NOW()),
('/contracts/edit.php', 'manage_contracts', NOW());

-- ============================================================================
-- VERIFICATION QUERIES (Run after migration)
-- ============================================================================
-- SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS 
-- WHERE TABLE_NAME='procurement_requests' AND COLUMN_NAME='request_type';
-- DESCRIBE service_contracts;
-- SELECT * FROM permissions WHERE name LIKE '%contract%';
