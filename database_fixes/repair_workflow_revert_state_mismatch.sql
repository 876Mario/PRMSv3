-- ============================================================================
-- WORKFLOW REVERT STATE-MISMATCH REPAIR SCRIPT
-- ============================================================================
-- 
-- ISSUE: 
--   When a request is reverted to SUBMITTED, the approval tasks are deleted
--   but not recreated, causing "No pending approvals for this request" errors.
--
-- FIX:
--   Identify requests in approval-requiring statuses with NO pending approval
--   records and recreate their approval chains.
--
-- SAFETY:
--   - This is a read-then-create operation (never destructive)
--   - Skips requests with existing pending approvals
--   - Logs all affected requests for review
--   - Can be run multiple times safely (idempotent)
--
-- ============================================================================

-- Step 1: Create temporary table to hold affected requests
CREATE TEMPORARY TABLE IF NOT EXISTS affected_requests_temp (
    request_id INT PRIMARY KEY,
    status VARCHAR(100),
    request_type VARCHAR(50),
    estimated_value DECIMAL(15,2),
    branch_id INT,
    created_at DATETIME
);

-- Step 2: Identify all requests that require approvals but have none pending
INSERT INTO affected_requests_temp
SELECT DISTINCT pr.request_id, pr.status, pr.request_type, pr.estimated_value, pr.branch_id, pr.created_at
FROM procurement_requests pr
WHERE pr.status IN ('SUBMITTED', 'HOD_APPROVED', 'FUNDS_VERIFIED', 'DIRECTOR_APPROVED', 'GC_APPROVED')
  AND NOT EXISTS (
      SELECT 1 FROM request_approvals ra
      WHERE ra.request_id = pr.request_id
        AND ra.status = 'pending'
  )
  -- Exclude requests created in the last 10 seconds (may still be in submission process)
  AND pr.created_at < DATE_SUB(NOW(), INTERVAL 10 SECOND)
ORDER BY pr.created_at DESC;

-- Step 3: Log affected requests before repair
SELECT 
    art.request_id,
    pr.request_number,
    art.status,
    art.request_type,
    art.estimated_value,
    art.created_at,
    COUNT(DISTINCT ra.id) AS existing_approvals,
    'REPAIR_PENDING' AS action
FROM affected_requests_temp art
LEFT JOIN procurement_requests pr ON art.request_id = pr.request_id
LEFT JOIN request_approvals ra ON art.request_id = ra.request_id
GROUP BY art.request_id, pr.request_number, art.status, art.request_type, art.estimated_value, art.created_at;

-- Step 4: APPROVAL CHAIN RECREATION FOR REIMBURSEMENT/PETTY_CASH REQUESTS
-- For these types, the approval chain is simple: first role from getApprovalChain()
INSERT IGNORE INTO request_approvals 
    (entity_type, entity_id, request_id, role, stage_order, status, created_at)
SELECT 
    'REQUEST',
    art.request_id,
    art.request_id,
    CASE 
        WHEN art.request_type IN ('REIMBURSEMENT', 'PETTY_CASH') THEN 'Finance Officer'
        WHEN art.request_type = 'SERVICE_CONTRACT' THEN 'HOD'
        ELSE 'HOD'
    END,
    1,
    'pending',
    NOW()
FROM affected_requests_temp art
LEFT JOIN request_approvals ra ON art.request_id = ra.request_id AND ra.status = 'pending'
WHERE ra.id IS NULL
  AND art.status IN ('SUBMITTED', 'HOD_APPROVED', 'FUNDS_VERIFIED', 'DIRECTOR_APPROVED', 'GC_APPROVED');

-- Step 5: APPROVAL CHAIN RECREATION FOR REGULAR PROCUREMENT REQUESTS
-- For REGULAR requests, calculate based on branch and amount
-- Branch-based routing takes precedence for branch-specific paths (branches 5, 6)
-- Threshold-based routing applies to generic branches (1, 2, 3, 4, etc.)
INSERT IGNORE INTO request_approvals 
    (entity_type, entity_id, request_id, role, stage_order, status, created_at)
SELECT 
    'REQUEST',
    art.request_id,
    art.request_id,
    CASE 
        -- Branch-specific routing (highest priority)
        WHEN art.branch_id = 5 THEN 'Director HRM&A'
        WHEN art.branch_id = 6 THEN 'Deputy Government Chemist'
        -- Generic threshold-based routing (applies to all other branches)
        WHEN art.estimated_value > 3000000 THEN 'Procurement Committee'
        WHEN art.estimated_value > 500000 THEN 'HOD'
        ELSE 'HOD'
    END,
    1,
    'pending',
    NOW()
FROM affected_requests_temp art
LEFT JOIN request_approvals ra ON art.request_id = ra.request_id AND ra.status = 'pending'
WHERE ra.id IS NULL
  AND art.request_type = 'REGULAR'
  AND art.status IN ('SUBMITTED', 'HOD_APPROVED', 'FUNDS_VERIFIED', 'DIRECTOR_APPROVED', 'GC_APPROVED');

-- Step 6: Add secondary approver for REGULAR requests that need them
-- Finance Officer is added for HRM&A branch (branch 5) after first stage
INSERT IGNORE INTO request_approvals 
    (entity_type, entity_id, request_id, role, stage_order, status, created_at)
SELECT 
    'REQUEST',
    art.request_id,
    art.request_id,
    'Finance Officer',
    2,
    'pending',
    NOW()
FROM affected_requests_temp art
LEFT JOIN request_approvals ra ON art.request_id = ra.request_id AND ra.status = 'pending' AND ra.role = 'Finance Officer'
WHERE ra.id IS NULL
  AND art.request_type = 'REGULAR'
  AND art.branch_id = 5
  AND art.status IN ('SUBMITTED', 'HOD_APPROVED', 'FUNDS_VERIFIED', 'DIRECTOR_APPROVED', 'GC_APPROVED');

-- Step 7: Final validation and summary
SELECT 
    COUNT(*) AS total_affected_requests,
    SUM(CASE WHEN ra.status = 'pending' THEN 1 ELSE 0 END) AS now_have_approvals,
    SUM(CASE WHEN ra.status IS NULL THEN 1 ELSE 0 END) AS still_missing_approvals
FROM affected_requests_temp art
LEFT JOIN request_approvals ra ON art.request_id = ra.request_id AND ra.status = 'pending';

-- Step 8: Audit trail - log this repair operation
INSERT INTO audit_log 
    (table_name, record_id, action, changed_by, change_date, notes)
SELECT 
    'workflow_state_mismatch_repair',
    art.request_id,
    'AUTO_REPAIR_APPROVAL_CHAIN',
    0,
    NOW(),
    CONCAT('Repaired approval chain for request status: ', art.status, 
           '; type: ', art.request_type, 
           '; value: ', art.estimated_value,
           '; automated repair from workflow_revert_state_mismatch_repair.sql')
FROM affected_requests_temp art;

-- ============================================================================
-- END OF REPAIR SCRIPT
-- ============================================================================
