-- ============================================================================
-- Migration 2026_07_28: Warranty Management & Custodian Role
-- ============================================================================
-- Purpose:
--   1. Add full warranty management fields to inv_asset_details:
--        warranty_provider, warranty_start_date, warranty_end_date,
--        warranty_period, warranty_reference, warranty_notes, warranty_status
--   2. Add custodian_role column to inv_asset_details so the assigned
--      role can be recorded alongside the custodian name.
--   Note: warranty_expiration already exists; warranty_end_date is added as
--         a dedicated column for the new warranty workflow while
--         warranty_expiration is preserved for backward compatibility.
-- ============================================================================

ALTER TABLE `inv_asset_details`
  ADD COLUMN IF NOT EXISTS `custodian_role`      varchar(150)  DEFAULT NULL COMMENT 'Role of the primary custodian'                        AFTER `custodian_name`,
  ADD COLUMN IF NOT EXISTS `warranty_provider`   varchar(150)  DEFAULT NULL COMMENT 'Warranty: provider / vendor name'                      AFTER `warranty_expiration`,
  ADD COLUMN IF NOT EXISTS `warranty_start_date` date          DEFAULT NULL COMMENT 'Warranty: start date'                                  AFTER `warranty_provider`,
  ADD COLUMN IF NOT EXISTS `warranty_end_date`   date          DEFAULT NULL COMMENT 'Warranty: end date (authoritative for UI)'             AFTER `warranty_start_date`,
  ADD COLUMN IF NOT EXISTS `warranty_period`     varchar(100)  DEFAULT NULL COMMENT 'Warranty: period description (e.g. 2 Years)'           AFTER `warranty_end_date`,
  ADD COLUMN IF NOT EXISTS `warranty_reference`  varchar(150)  DEFAULT NULL COMMENT 'Warranty: contract / reference number'                 AFTER `warranty_period`,
  ADD COLUMN IF NOT EXISTS `warranty_notes`      text          DEFAULT NULL COMMENT 'Warranty: free-text notes'                             AFTER `warranty_reference`,
  ADD COLUMN IF NOT EXISTS `warranty_status`     varchar(30)   DEFAULT NULL COMMENT 'Warranty: Active | Expired | Void | Pending | Unknown' AFTER `warranty_notes`;

-- Backfill warranty_end_date from warranty_expiration for existing records
UPDATE `inv_asset_details`
SET `warranty_end_date` = `warranty_expiration`
WHERE `warranty_end_date` IS NULL AND `warranty_expiration` IS NOT NULL;

-- Add index to support warranty expiry queries
ALTER TABLE `inv_asset_details`
  ADD INDEX IF NOT EXISTS `idx_warranty_end_date` (`warranty_end_date`),
  ADD INDEX IF NOT EXISTS `idx_warranty_status`   (`warranty_status`);

-- ─── Verification ─────────────────────────────────────────────────────────────
-- Use SHOW COLUMNS instead of INFORMATION_SCHEMA to avoid access-denied errors
-- on shared hosting environments.
SHOW COLUMNS FROM `inv_asset_details`
WHERE Field IN ('custodian_role','warranty_provider','warranty_start_date',
                'warranty_end_date','warranty_period','warranty_reference',
                'warranty_notes','warranty_status');
