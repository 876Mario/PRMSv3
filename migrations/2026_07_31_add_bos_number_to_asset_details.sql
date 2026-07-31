-- ============================================================================
-- Migration: Add bos_number column to inv_asset_details table
-- Date: 2026-07-31
-- Purpose:
--   Add bos_number column to track Board of Survey reference on asset details
-- ============================================================================

-- Add bos_number column to inv_asset_details table if it doesn't exist
ALTER TABLE `inv_asset_details`
ADD COLUMN `bos_number` VARCHAR(30) DEFAULT NULL COMMENT 'Board of Survey number reference' AFTER `acquisition_method`;

-- Add an index for performance on lookups by bos_number
CREATE INDEX `idx_asset_details_bos_number` ON `inv_asset_details` (`bos_number`);

-- ============================================================================
-- VERIFICATION
-- SELECT asset_detail_id, item_id, bos_number FROM inv_asset_details LIMIT 5;
-- ============================================================================
