-- Add supplementary commitment linkage for PO variations.
-- This fixes runtime failures on environments where the column was never added.

ALTER TABLE `po_variations`
  ADD COLUMN IF NOT EXISTS `commitment_id` INT(11) DEFAULT NULL AFTER `status`;

ALTER TABLE `po_variations`
  ADD KEY IF NOT EXISTS `idx_po_variations_commitment_id` (`commitment_id`);
