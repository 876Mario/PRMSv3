-- ============================================================================
-- Non-PO Commitment Remediation - Monitoring Queries
-- ============================================================================
-- Use these queries for ongoing auditing and verification to prevent
-- regression of the non-PO commitment defect.
--
-- Run these weekly/monthly to ensure no new affected requests are created
-- after the fix was deployed (2026-08-18).
-- ============================================================================

-- Query 1: Identify historical affected requests that were remediated
-- Purpose: Verify remediation was successful
-- Expected: Returns all requests with NON_PO_SKIP_RFQ that had remediated commitments
SELECT 
    pr.request_id,
    pr.request_number,
    pr.status as current_status,
    pr.workflow_path,
    pr.created_at as request_created_at,
    c.commitment_id,
    c.commitment_number,
    c.po_required,
    c.is_remediated,
    c.remediated_at,
    c.remediation_reason,
    c.created_at as commitment_created_at
FROM procurement_requests pr
LEFT JOIN commitments c ON pr.request_id = c.request_id 
    AND c.commitment_type = 'ORIGINAL' 
    AND c.is_remediated = 1  -- Only show remediated
WHERE 
    pr.request_type = 'REGULAR'
    AND pr.workflow_path = 'NON_PO_SKIP_RFQ'
    AND NOT EXISTS (SELECT 1 FROM rfqs WHERE request_id = pr.request_id)
ORDER BY pr.created_at DESC;


-- Query 2: Detect new orphaned non-PO commitments (regression detection)
-- Purpose: Alert if new affected requests are created AFTER the fix
-- Expected: Should return 0 rows (empty result) if fix is working
-- Action if non-empty: Investigate Finance officer workflow or code regression
SELECT 
    pr.request_id,
    pr.request_number,
    pr.status,
    pr.workflow_path,
    pr.created_at,
    c.commitment_id,
    c.commitment_number,
    c.po_required,
    c.is_remediated,
    'ALERT: Potential regression - new orphaned non-PO commitment' as issue_type
FROM procurement_requests pr
INNER JOIN commitments c ON pr.request_id = c.request_id 
    AND c.commitment_type = 'ORIGINAL'
WHERE 
    pr.request_type = 'REGULAR'
    AND c.po_required = 'NO'
    -- Only flag if NOT explicitly marked as NON_PO_SKIP_RFQ
    -- (legitimate non-PO requests SHOULD have this flag set)
    AND (pr.workflow_path IS NULL OR pr.workflow_path != 'NON_PO_SKIP_RFQ')
    AND NOT EXISTS (SELECT 1 FROM rfqs WHERE request_id = pr.request_id)
    -- Only check requests created AFTER the fix was deployed
    AND DATE(pr.created_at) >= '2026-08-18'
    -- Only active (not yet remediated) commitments
    AND (c.is_remediated IS NULL OR c.is_remediated = 0)
ORDER BY pr.created_at DESC;


-- Query 3: Active non-PO commitments (should all be properly flagged)
-- Purpose: Verify all active non-PO commitments have workflow_path set correctly
-- Expected: All results should have workflow_path='NON_PO_SKIP_RFQ'
SELECT 
    pr.request_id,
    pr.request_number,
    pr.workflow_path,
    c.commitment_id,
    c.commitment_number,
    c.po_required,
    CASE 
        WHEN pr.workflow_path = 'NON_PO_SKIP_RFQ' THEN 'OK - Properly flagged'
        ELSE 'WARNING - Missing workflow_path flag'
    END as status
FROM procurement_requests pr
INNER JOIN commitments c ON pr.request_id = c.request_id 
    AND c.commitment_type = 'ORIGINAL'
WHERE 
    pr.request_type = 'REGULAR'
    AND c.po_required = 'NO'
    AND (c.is_remediated IS NULL OR c.is_remediated = 0)
    AND NOT EXISTS (SELECT 1 FROM rfqs WHERE request_id = pr.request_id)
ORDER BY 
    CASE WHEN pr.workflow_path = 'NON_PO_SKIP_RFQ' THEN 0 ELSE 1 END,
    pr.created_at DESC;


-- Query 4: Summary statistics for reporting
-- Purpose: Get high-level metrics on affected requests
-- Shows: total, remediated, active, and flagged non-PO requests
SELECT 
    'Total Non-PO Requests' as metric,
    COUNT(DISTINCT pr.request_id) as count
FROM procurement_requests pr
INNER JOIN commitments c ON pr.request_id = c.request_id 
    AND c.commitment_type = 'ORIGINAL'
WHERE 
    pr.request_type = 'REGULAR'
    AND c.po_required = 'NO'
    AND NOT EXISTS (SELECT 1 FROM rfqs WHERE request_id = pr.request_id)

UNION ALL

SELECT 
    'Remediated Non-PO Requests',
    COUNT(DISTINCT pr.request_id)
FROM procurement_requests pr
INNER JOIN commitments c ON pr.request_id = c.request_id 
    AND c.commitment_type = 'ORIGINAL'
WHERE 
    pr.request_type = 'REGULAR'
    AND c.po_required = 'NO'
    AND c.is_remediated = 1
    AND NOT EXISTS (SELECT 1 FROM rfqs WHERE request_id = pr.request_id)

UNION ALL

SELECT 
    'Active Non-PO Requests',
    COUNT(DISTINCT pr.request_id)
FROM procurement_requests pr
INNER JOIN commitments c ON pr.request_id = c.request_id 
    AND c.commitment_type = 'ORIGINAL'
WHERE 
    pr.request_type = 'REGULAR'
    AND c.po_required = 'NO'
    AND (c.is_remediated IS NULL OR c.is_remediated = 0)
    AND NOT EXISTS (SELECT 1 FROM rfqs WHERE request_id = pr.request_id)

UNION ALL

SELECT 
    'Properly Flagged (workflow_path=NON_PO_SKIP_RFQ)',
    COUNT(DISTINCT pr.request_id)
FROM procurement_requests pr
INNER JOIN commitments c ON pr.request_id = c.request_id 
    AND c.commitment_type = 'ORIGINAL'
WHERE 
    pr.request_type = 'REGULAR'
    AND c.po_required = 'NO'
    AND pr.workflow_path = 'NON_PO_SKIP_RFQ'
    AND (c.is_remediated IS NULL OR c.is_remediated = 0)
    AND NOT EXISTS (SELECT 1 FROM rfqs WHERE request_id = pr.request_id)

ORDER BY metric;


-- Query 5: Find requests that are stuck in AWARDED status due to remediated commitments
-- Purpose: Identify requests that may need manual attention
-- These are requests with remediated commitments that should be advanced
SELECT 
    pr.request_id,
    pr.request_number,
    pr.status,
    pr.workflow_path,
    c.commitment_id,
    c.remediation_reason,
    c.remediated_at,
    CASE 
        WHEN pr.status = 'AWARDED' THEN 'Can transition to INVOICE_RECEIVED'
        WHEN pr.status = 'COMMITMENT_APPROVED' THEN 'Can transition to INVOICE_RECEIVED'
        ELSE 'No action needed'
    END as recommended_action
FROM procurement_requests pr
INNER JOIN commitments c ON pr.request_id = c.request_id 
    AND c.commitment_type = 'ORIGINAL'
    AND c.is_remediated = 1
WHERE 
    pr.request_type = 'REGULAR'
    AND pr.workflow_path = 'NON_PO_SKIP_RFQ'
    AND pr.status IN ('AWARDED', 'COMMITMENT_APPROVED')
ORDER BY pr.created_at DESC;
