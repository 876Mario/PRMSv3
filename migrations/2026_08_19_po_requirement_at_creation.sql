-- ============================================================
-- Migration: 2026_08_19_po_requirement_at_creation.sql
-- Purpose : Introduce work_performed and goods_delivered flags
--           on procurement_requests table to determine PO 
--           requirement at request creation time.
--           
-- Logic   : If work_performed=1 AND goods_delivered=1,
--           then PO is not required. Otherwise, PO is required.
-- ============================================================

-- --------------------------------------------------------
-- Phase 1 – Schema changes (additive, safe to run online)
-- --------------------------------------------------------

-- A. Add work_performed and goods_delivered flags to procurement_requests.
--    DEFAULT 0 means PO IS required (conservative default)
ALTER TABLE `procurement_requests`
  ADD COLUMN IF NOT EXISTS `work_performed`
    TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Whether work has already been performed (1=yes, 0=no)'
  AFTER `usd_rate`,
  ADD COLUMN IF NOT EXISTS `goods_delivered`
    TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Whether goods have already been delivered (1=yes, 0=no)'
  AFTER `work_performed`,
  ADD COLUMN IF NOT EXISTS `po_requirement_notes`
    TEXT DEFAULT NULL
    COMMENT 'Justification or notes for PO requirement decision'
  AFTER `goods_delivered`;

-- B. Add indexes for efficient filtering
CREATE INDEX IF NOT EXISTS `idx_pr_work_performed` 
  ON `procurement_requests`(`work_performed`);

CREATE INDEX IF NOT EXISTS `idx_pr_goods_delivered` 
  ON `procurement_requests`(`goods_delivered`);

CREATE INDEX IF NOT EXISTS `idx_pr_po_requirement_flags` 
  ON `procurement_requests`(`work_performed`, `goods_delivered`);

-- --------------------------------------------------------
-- Phase 2 – Create helper view for audit queries
-- --------------------------------------------------------

CREATE OR REPLACE VIEW `v_po_requirement_audit` AS
SELECT 
    pr.request_id,
    pr.request_number,
    pr.request_type,
    pr.status,
    pr.work_performed,
    pr.goods_delivered,
    CASE 
        WHEN pr.work_performed = 1 AND pr.goods_delivered = 1 
        THEN 'NO' 
        ELSE 'YES' 
    END AS derived_po_required,
    c.commitment_id,
    c.po_required AS actual_po_required,
    CASE 
        WHEN pr.work_performed = 1 AND pr.goods_delivered = 1 AND c.po_required = 'NO'
        THEN 'CORRECT'
        WHEN pr.work_performed = 1 AND pr.goods_delivered = 1 AND c.po_required = 'YES'
        THEN 'MISMATCH: Flags say NO but commitment says YES'
        WHEN NOT (pr.work_performed = 1 AND pr.goods_delivered = 1) AND c.po_required = 'YES'
        THEN 'CORRECT'
        WHEN NOT (pr.work_performed = 1 AND pr.goods_delivered = 1) AND c.po_required = 'NO'
        THEN 'MISMATCH: Flags say YES but commitment says NO'
        ELSE 'NO_COMMITMENT_YET'
    END AS consistency_check,
    pr.created_by,
    pr.created_at,
    pr.updated_at
FROM procurement_requests pr
LEFT JOIN commitments c ON pr.request_id = c.request_id 
    AND (c.is_remediated IS NULL OR c.is_remediated = 0)
WHERE pr.request_type = 'REGULAR'
ORDER BY pr.request_id DESC;

-- --------------------------------------------------------
-- Phase 3 – Audit: Check for any existing conflicts
-- Note: This will only show results if there are already
--       commitments with po_required values. Most new
--       requests won't have commitments yet.
-- --------------------------------------------------------

-- Query to review (run manually after migration):
-- SELECT 
--   request_id, request_number, status,
--   work_performed, goods_delivered,
--   derived_po_required, actual_po_required,
--   consistency_check
-- FROM v_po_requirement_audit
-- WHERE commitment_id IS NOT NULL
--   AND consistency_check LIKE 'MISMATCH%';
