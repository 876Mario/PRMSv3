-- ============================================================
-- Migration: 2026_07_28_po_required_workflow_path.sql
-- Purpose : Introduce PO-Required flag on commitments and a
--           workflow_path column on procurement_requests so the
--           system can track and display whether a request took
--           the Standard Procurement Path or the Non-PO / Skip-RFQ
--           path selected by Finance at commitment creation time.
-- ============================================================

-- --------------------------------------------------------
-- Phase 1 – Schema changes (additive, safe to run online)
-- --------------------------------------------------------

-- A. Add po_required to the commitments table.
--    DEFAULT 'YES' preserves existing rows unchanged.
ALTER TABLE `commitments`
  ADD COLUMN IF NOT EXISTS `po_required`
    ENUM('YES','NO') NOT NULL DEFAULT 'YES'
    COMMENT 'Whether a Purchase Order is required for this commitment'
  AFTER `document_path`;

-- B. Add workflow_path to procurement_requests.
--    DEFAULT 'STANDARD' preserves existing rows unchanged.
ALTER TABLE `procurement_requests`
  ADD COLUMN IF NOT EXISTS `workflow_path`
    ENUM('STANDARD','NON_PO_SKIP_RFQ') NOT NULL DEFAULT 'STANDARD'
    COMMENT 'Resolved workflow path set when commitment is created'
  AFTER `cabinet_approval_status`;

-- --------------------------------------------------------
-- Phase 2 – Backfill skip-RFQ requests
-- Mirrors the isSkipRfqPath() heuristic in config/workflow.php:
--   REGULAR type + requires_rfq = 0 + no linked RFQ record.
-- --------------------------------------------------------
UPDATE `procurement_requests` pr
SET pr.`workflow_path` = 'NON_PO_SKIP_RFQ'
WHERE pr.`request_type` = 'REGULAR'
  AND pr.`requires_rfq` = 0
  AND NOT EXISTS (
      SELECT 1 FROM `rfqs` r WHERE r.`request_id` = pr.`request_id`
  );

-- --------------------------------------------------------
-- Phase 3 – Backfill commitments that belong to Non-PO requests
-- --------------------------------------------------------
UPDATE `commitments` c
  JOIN `procurement_requests` pr ON c.`request_id` = pr.`request_id`
SET c.`po_required` = 'NO'
WHERE pr.`workflow_path` = 'NON_PO_SKIP_RFQ';

-- --------------------------------------------------------
-- Phase 4 – Validation query (run manually after migration)
-- Uncomment to check counts:
-- SELECT
--   workflow_path,
--   COUNT(*) AS cnt
-- FROM procurement_requests
-- GROUP BY workflow_path;
--
-- SELECT
--   po_required,
--   COUNT(*) AS cnt
-- FROM commitments
-- GROUP BY po_required;
-- --------------------------------------------------------
