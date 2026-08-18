-- ============================================================
-- Workflow Consistency Audit Queries
-- Purpose: Verify PO requirement implementation integrity
-- ============================================================

-- ========================================================
-- AUDIT 1: Verify All Requests Have Proper PO Flag Values
-- ========================================================
-- Expected: All REGULAR requests should have work_performed 
--           and goods_delivered fields set to 0 or 1 (not NULL)
SELECT 
    COUNT(*) as total_regular_requests,
    COUNT(CASE WHEN work_performed IS NULL THEN 1 END) as null_work_performed,
    COUNT(CASE WHEN goods_delivered IS NULL THEN 1 END) as null_goods_delivered
FROM procurement_requests
WHERE request_type = 'REGULAR';

-- Detailed view of any problematic records
SELECT 
    request_id, request_number, status,
    work_performed, goods_delivered,
    created_at
FROM procurement_requests
WHERE request_type = 'REGULAR'
AND (work_performed IS NULL OR goods_delivered IS NULL)
ORDER BY request_id DESC
LIMIT 20;

-- ========================================================
-- AUDIT 2: Verify Commitment po_required Aligns With Flags
-- ========================================================
-- Expected: If work_performed=1 AND goods_delivered=1, 
--           then commitment.po_required MUST be 'NO'
--           Otherwise, po_required SHOULD be 'YES'

-- Count of misaligned commitments
SELECT 
    COUNT(*) as total_commitments,
    COUNT(CASE WHEN po_required IS NULL THEN 1 END) as null_po_required,
    SUM(CASE 
        WHEN pr.work_performed = 1 AND pr.goods_delivered = 1 AND c.po_required = 'YES' THEN 1
        WHEN NOT (pr.work_performed = 1 AND pr.goods_delivered = 1) AND c.po_required = 'NO' THEN 1
        ELSE 0
    END) as misaligned_count
FROM commitments c
JOIN procurement_requests pr ON c.request_id = pr.request_id
WHERE pr.request_type = 'REGULAR'
AND (c.is_remediated IS NULL OR c.is_remediated = 0);

-- Detailed view of misaligned records (should be EMPTY after fix)
SELECT 
    pr.request_id, pr.request_number, pr.status,
    pr.work_performed, pr.goods_delivered,
    c.commitment_id, c.po_required,
    CASE 
        WHEN pr.work_performed = 1 AND pr.goods_delivered = 1 THEN 'BOTH_TRUE'
        WHEN pr.work_performed = 1 THEN 'WORK_ONLY'
        WHEN pr.goods_delivered = 1 THEN 'GOODS_ONLY'
        ELSE 'BOTH_FALSE'
    END as flag_status,
    CASE 
        WHEN pr.work_performed = 1 AND pr.goods_delivered = 1 AND c.po_required = 'YES' THEN 'MISMATCH: Flags say NO PO, commitment says YES'
        WHEN NOT (pr.work_performed = 1 AND pr.goods_delivered = 1) AND c.po_required = 'NO' THEN 'MISMATCH: Flags say PO needed, commitment says NO'
        ELSE 'CORRECT'
    END as consistency
FROM commitments c
JOIN procurement_requests pr ON c.request_id = pr.request_id
WHERE pr.request_type = 'REGULAR'
AND (c.is_remediated IS NULL OR c.is_remediated = 0)
ORDER BY pr.request_id DESC
LIMIT 50;

-- ========================================================
-- AUDIT 3: Verify No PO Bypass Routes Exist
-- ========================================================
-- Expected: No request with po_required='NO' commitment 
--           should reach PO_PENDING status

-- Check for POs created with non-PO commitments (should be 0)
SELECT 
    COUNT(*) as po_with_no_po_commitment_count,
    COUNT(DISTINCT c.request_id) as unique_requests_with_violation
FROM purchase_orders po
JOIN commitments c ON po.commitment_id = c.commitment_id
WHERE c.po_required = 'NO'
AND (c.is_remediated IS NULL OR c.is_remediated = 0);

-- Detailed view if any violations exist
SELECT 
    po.po_id, po.po_number, c.request_id,
    pr.request_number, pr.status,
    c.commitment_id, c.po_required,
    po.created_at
FROM purchase_orders po
JOIN commitments c ON po.commitment_id = c.commitment_id
JOIN procurement_requests pr ON c.request_id = pr.request_id
WHERE c.po_required = 'NO'
AND (c.is_remediated IS NULL OR c.is_remediated = 0)
ORDER BY po.po_id DESC
LIMIT 50;

-- ========================================================
-- AUDIT 4: Verify Workflow Paths Are Consistent
-- ========================================================
-- Expected: All skip-RFQ + non-PO requests should have 
--           workflow_path='NON_PO_SKIP_RFQ'
--           All others should have workflow_path='STANDARD'

-- Count workflow path assignments
SELECT 
    workflow_path,
    COUNT(*) as count
FROM procurement_requests
WHERE request_type = 'REGULAR'
GROUP BY workflow_path
ORDER BY count DESC;

-- Check for misaligned paths (requests with PO requirement but non-PO path)
SELECT 
    pr.request_id, pr.request_number, pr.status,
    pr.workflow_path,
    pr.work_performed, pr.goods_delivered,
    c.commitment_id, c.po_required
FROM procurement_requests pr
LEFT JOIN commitments c ON pr.request_id = c.request_id 
    AND (c.is_remediated IS NULL OR c.is_remediated = 0)
WHERE pr.request_type = 'REGULAR'
AND pr.workflow_path = 'NON_PO_SKIP_RFQ'
AND c.po_required = 'YES'
ORDER BY pr.request_id DESC
LIMIT 50;

-- ========================================================
-- AUDIT 5: Verify No Transitions Skipping PO Requirement
-- ========================================================
-- Expected: Status should only reach AWARDED → INVOICE_RECEIVED 
--           if po_required='NO'

-- Check for requests at INVOICE_RECEIVED or beyond with po_required='YES' 
-- (should exist for standard workflow, but with prior PO_PENDING status)
SELECT 
    COUNT(*) as count,
    COUNT(CASE WHEN pr.status IN ('INVOICE_RECEIVED', 'COMPLETED') THEN 1 END) as at_invoice_or_beyond
FROM procurement_requests pr
LEFT JOIN commitments c ON pr.request_id = c.request_id 
    AND (c.is_remediated IS NULL OR c.is_remediated = 0)
WHERE pr.request_type = 'REGULAR'
AND c.po_required = 'YES'
AND pr.status IN ('INVOICE_RECEIVED', 'COMPLETED');

-- Verify all PO_PENDING requests have po_required='YES'
SELECT 
    COUNT(*) as po_pending_with_no_po_count
FROM procurement_requests pr
LEFT JOIN commitments c ON pr.request_id = c.request_id 
    AND (c.is_remediated IS NULL OR c.is_remediated = 0)
WHERE pr.request_type = 'REGULAR'
AND pr.status = 'PO_PENDING'
AND c.po_required = 'NO';

-- ========================================================
-- AUDIT 6: Summary Statistics
-- ========================================================
SELECT 
    'Total REGULAR Requests' as metric,
    COUNT(*) as value
FROM procurement_requests
WHERE request_type = 'REGULAR'
UNION ALL
SELECT 
    'Requests with PO (flags: at least one false)',
    COUNT(*) 
FROM procurement_requests
WHERE request_type = 'REGULAR'
AND NOT (work_performed = 1 AND goods_delivered = 1)
UNION ALL
SELECT 
    'Requests without PO (both flags true)',
    COUNT()
FROM procurement_requests
WHERE request_type = 'REGULAR'
AND work_performed = 1 AND goods_delivered = 1
UNION ALL
SELECT 
    'Commitments with po_required=YES',
    COUNT(*)
FROM commitments c
JOIN procurement_requests pr ON c.request_id = pr.request_id
WHERE pr.request_type = 'REGULAR'
AND c.po_required = 'YES'
AND (c.is_remediated IS NULL OR c.is_remediated = 0)
UNION ALL
SELECT 
    'Commitments with po_required=NO',
    COUNT(*)
FROM commitments c
JOIN procurement_requests pr ON c.request_id = pr.request_id
WHERE pr.request_type = 'REGULAR'
AND c.po_required = 'NO'
AND (c.is_remediated IS NULL OR c.is_remediated = 0)
UNION ALL
SELECT 
    'Purchase Orders created',
    COUNT(*)
FROM purchase_orders;

-- ========================================================
-- AUDIT 7: Verify Reversibility - Check Backward Transitions
-- ========================================================
-- Expected: COMMITMENT_APPROVED, PO_PENDING should allow 
--           reverting to earlier stages

SELECT 
    pr.status, COUNT(*) as count
FROM procurement_requests pr
WHERE pr.request_type = 'REGULAR'
AND pr.status IN ('COMMITMENT_APPROVED', 'PO_PENDING', 'INVOICE_RECEIVED')
GROUP BY pr.status
ORDER BY pr.status;

-- ========================================================
-- AUDIT 8: Edge Case - Requests With Changed Flags
-- ========================================================
-- Expected: If request flags changed after commitment creation,
--           there may be audit log entries

-- This requires audit_log table to be present
-- Check for flag changes after commitment creation
SELECT 
    pr.request_id, pr.request_number,
    pr.work_performed, pr.goods_delivered,
    c.commitment_id, c.po_required,
    c.created_at as commitment_created,
    MAX(al.created_at) as last_audit_change
FROM procurement_requests pr
LEFT JOIN commitments c ON pr.request_id = c.request_id 
    AND (c.is_remediated IS NULL OR c.is_remediated = 0)
LEFT JOIN audit_log al ON al.table_name = 'procurement_requests' 
    AND al.record_id = pr.request_id
    AND al.created_at > c.created_at
WHERE pr.request_type = 'REGULAR'
AND c.commitment_id IS NOT NULL
AND al.record_id IS NOT NULL
GROUP BY pr.request_id
ORDER BY pr.request_id DESC
LIMIT 20;
