-- Add soft-delete columns to rfq_vendors table
ALTER TABLE rfq_vendors ADD COLUMN `is_deleted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `created_at`;
ALTER TABLE rfq_vendors ADD COLUMN `deleted_by` VARCHAR(100) DEFAULT NULL AFTER `is_deleted` COMMENT 'Full name of user who deleted the record';
ALTER TABLE rfq_vendors ADD COLUMN `deleted_at` TIMESTAMP NULL DEFAULT NULL AFTER `deleted_by`;

-- Add soft-delete columns to rfq_quotes table
ALTER TABLE rfq_quotes ADD COLUMN `is_deleted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `submitted_at`;
ALTER TABLE rfq_quotes ADD COLUMN `deleted_by` VARCHAR(100) DEFAULT NULL AFTER `is_deleted` COMMENT 'Full name of user who deleted the record';
ALTER TABLE rfq_quotes ADD COLUMN `deleted_at` TIMESTAMP NULL DEFAULT NULL AFTER `deleted_by`;

-- Add indices for soft-delete queries
CREATE INDEX idx_rfq_vendors_is_deleted ON rfq_vendors(is_deleted);
CREATE INDEX idx_rfq_quotes_is_deleted ON rfq_quotes(is_deleted);

-- Add new permissions for delete actions
INSERT IGNORE INTO permissions (name, description) VALUES
('procurement_delete_vendor', 'Delete RFQ vendors'),
('procurement_delete_quote', 'Delete RFQ quotes');

-- Add notification type for finance actions
-- Note: NotificationService types are defined in PHP, so we ensure migration compatibility
-- Finance notification type will be added to NotificationService.php class constants
