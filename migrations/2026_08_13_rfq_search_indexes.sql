-- RFQ Search Optimization Indexes
-- Purpose: Add indexes on searchable fields for RFQ list search functionality

-- Index on RFQ number for fast partial matching
ALTER TABLE `rfqs` ADD INDEX `idx_rfq_number` (`rfq_number`);

-- Index on RFQ status for filtering
ALTER TABLE `rfqs` ADD INDEX `idx_rfq_status` (`status`);

-- Index on RFQ request_id and status (composite for common queries)
ALTER TABLE `rfqs` ADD INDEX `idx_rfq_request_status` (`request_id`, `status`);

-- Index on procurement request number for fast partial matching
ALTER TABLE `procurement_requests` ADD INDEX `idx_request_number` (`request_number`);

-- Index on procurement request description for text search (limited prefix index for performance)
ALTER TABLE `procurement_requests` ADD INDEX `idx_description_prefix` (`description`(255));

-- Index on procurement request created_by for requester filtering
ALTER TABLE `procurement_requests` ADD INDEX `idx_created_by` (`created_by`);

-- Index on procurement request status for filtering
ALTER TABLE `procurement_requests` ADD INDEX `idx_pr_status` (`status`);

-- Index on RFQ vendor name for vendor search
ALTER TABLE `rfq_vendors` ADD INDEX `idx_vendor_name` (`vendor_name`);

-- Index on RFQ vendor rfq_id for vendor lookup
ALTER TABLE `rfq_vendors` ADD INDEX `idx_rfq_vendor_rfq_id` (`rfq_id`);

-- Composite index for common RFQ list queries
ALTER TABLE `rfqs` ADD INDEX `idx_created_at_status` (`created_at`, `status`);
