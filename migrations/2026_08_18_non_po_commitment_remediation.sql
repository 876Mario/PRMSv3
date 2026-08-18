-- ============================================================================
-- Migration: Non-PO Commitment Remediation (Aug 18, 2026)
-- ============================================================================
-- Fixes defect where commitments with po_required='NO' were created for
-- skip-RFQ requests, creating orphaned data records stuck in AWARDED status.
--
-- This migration:
-- 1. Adds is_remediated column to commitments for soft-delete tracking
-- 2. Backfills workflow_path='NON_PO_SKIP_RFQ' for historical affected records
-- 3. Soft-deletes orphaned non-PO commitments (marks them as voided)
-- 4. Adds index for efficient monitoring queries
-- ============================================================================

-- Step 1: Add soft-delete tracking column to commitments table
ALTER TABLE commitments
ADD COLUMN IF NOT EXISTS is_remediated TINYINT(1) DEFAULT 0 COMMENT 'Soft-delete flag: 1 = remediated/voided orphaned commitment, 0 = active',
ADD COLUMN IF NOT EXISTS remediation_reason VARCHAR(255) DEFAULT NULL COMMENT 'Reason for voiding (e.g., orphaned non-PO commitment)',
ADD COLUMN IF NOT EXISTS remediated_at DATETIME DEFAULT NULL COMMENT 'Timestamp when remediation was applied';

-- Step 2: Add index for efficient tracking
CREATE INDEX IF NOT EXISTS idx_commitments_remediated ON commitments(is_remediated);
CREATE INDEX IF NOT EXISTS idx_commitments_po_required ON commitments(po_required);

-- Step 3: Backfill workflow_path for historical non-PO skip-RFQ requests
-- This identifies requests that:
-- - Have a commitment with po_required='NO'
-- - Are in a post-award status (AWARDED, COMMITMENT_APPROVED, INVOICE_RECEIVED, COMPLETED)
-- - Don't have an RFQ (skip-RFQ path)
-- - Haven't already been marked as NON_PO_SKIP_RFQ (in case they were already fixed)
UPDATE procurement_requests pr
SET workflow_path = 'NON_PO_SKIP_RFQ'
WHERE 
    pr.request_type = 'REGULAR'
    AND pr.workflow_path = 'STANDARD'
    AND pr.status IN ('AWARDED', 'COMMITMENT_APPROVED', 'INVOICE_RECEIVED', 'COMPLETED')
    AND NOT EXISTS (SELECT 1 FROM rfqs WHERE request_id = pr.request_id)
    AND EXISTS (
        SELECT 1 FROM commitments c 
        WHERE c.request_id = pr.request_id 
        AND c.commitment_type = 'ORIGINAL'
        AND c.po_required = 'NO'
        AND c.is_remediated = 0
    );

-- Step 4: Soft-delete orphaned non-PO commitments
-- Marks them as voided but keeps them for audit trail
UPDATE commitments c
SET 
    c.is_remediated = 1,
    c.remediation_reason = 'Orphaned non-PO commitment from skip-RFQ path (automated remediation)',
    c.remediated_at = NOW()
WHERE 
    c.commitment_type = 'ORIGINAL'
    AND c.po_required = 'NO'
    AND c.is_remediated = 0
    AND EXISTS (
        SELECT 1 FROM procurement_requests pr
        WHERE pr.request_id = c.request_id
        AND pr.request_type = 'REGULAR'
        AND pr.workflow_path = 'NON_PO_SKIP_RFQ'
        AND NOT EXISTS (SELECT 1 FROM rfqs WHERE request_id = pr.request_id)
    );

-- Step 5: Create view for monitoring affected records (for audit/verification)
DROP VIEW IF EXISTS v_non_po_remediation_audit;
CREATE VIEW v_non_po_remediation_audit AS
SELECT 
    pr.request_id,
    pr.request_number,
    pr.status,
    pr.workflow_path,
    pr.created_at as request_created_at,
    c.commitment_id,
    c.commitment_number,
    c.po_required,
    c.is_remediated,
    c.remediated_at,
    c.created_at as commitment_created_at
FROM procurement_requests pr
LEFT JOIN commitments c ON pr.request_id = c.request_id AND c.commitment_type = 'ORIGINAL'
WHERE 
    pr.request_type = 'REGULAR'
    AND pr.workflow_path = 'NON_PO_SKIP_RFQ'
    AND NOT EXISTS (SELECT 1 FROM rfqs WHERE request_id = pr.request_id)
ORDER BY pr.created_at DESC;

-- Step 6: Add monitoring trigger to prevent future orphaned non-PO commitments
-- This trigger ensures that if anyone tries to create a non-PO commitment
-- without proper workflow setup, they get an error
DELIMITER $$
DROP TRIGGER IF EXISTS `trg_prevent_orphaned_non_po_commitment` $$
CREATE TRIGGER `trg_prevent_orphaned_non_po_commitment` BEFORE INSERT ON `commitments` FOR EACH ROW
BEGIN
    DECLARE rfq_count INT DEFAULT 0;
    DECLARE request_workflow_path VARCHAR(50) DEFAULT NULL;
    DECLARE request_type VARCHAR(50) DEFAULT NULL;
    
    -- Only validate for ORIGINAL commitments (not supplementary)
    IF NEW.commitment_type = 'ORIGINAL' THEN
        -- Check if this is a non-PO commitment (po_required='NO')
        IF NEW.po_required = 'NO' THEN
            -- Get request details: type and workflow_path
            SELECT pr.request_type, pr.workflow_path
            INTO request_type, request_workflow_path
            FROM procurement_requests pr
            WHERE pr.request_id = NEW.request_id
            LIMIT 1;
            
            -- Count RFQs for this request
            SELECT COUNT(*)
            INTO rfq_count
            FROM rfqs
            WHERE request_id = NEW.request_id;
            
            -- For REGULAR requests in skip-RFQ path (no RFQ exists),
            -- ensure workflow_path is explicitly set to NON_PO_SKIP_RFQ
            IF request_type = 'REGULAR' 
               AND rfq_count = 0
               AND (request_workflow_path IS NULL OR request_workflow_path = 'STANDARD')
            THEN
               SIGNAL SQLSTATE '45000' 
               SET MESSAGE_TEXT = 'Cannot create non-PO commitment without setting workflow_path to NON_PO_SKIP_RFQ for skip-RFQ requests';
            END IF;
        END IF;
    END IF;
END $$
DELIMITER ;

-- Log remediation actions to audit log via comment
ALTER TABLE commitments COMMENT='Commitment records. See migration 2026_08_18_non_po_commitment_remediation for soft-delete logic.';
