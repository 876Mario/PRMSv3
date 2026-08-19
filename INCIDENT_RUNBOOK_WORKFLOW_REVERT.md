# INCIDENT RUNBOOK: Workflow Revert State-Mismatch

## Executive Summary

**Incident**: Request reverted to SUBMITTED status, but approval fails with "No pending approvals for this request"

**Root Cause**: When reverting a request to an earlier workflow stage, pending approval tasks were deleted but not recreated, leaving a state mismatch between UI (showing "Pending Branch Head Approval") and database (no approval records).

**Impact**: Branch Head and other approvers cannot complete their approval actions on reverted requests

**Severity**: HIGH (blocks approval workflow)

**Resolution**: Fix `revert_status.php` to recreate approval chain; repair affected requests via SQL script

---

## Immediate Containment (First Hour)

### 1. Isolate Affected Requests
```sql
-- Find all requests in approval-requiring status with NO pending approval tasks
SELECT pr.request_id, pr.request_number, pr.status, pr.created_at, pr.estimated_value
FROM procurement_requests pr
WHERE pr.status IN ('SUBMITTED', 'HOD_APPROVED', 'FUNDS_VERIFIED', 'DIRECTOR_APPROVED', 'GC_APPROVED')
  AND NOT EXISTS (
      SELECT 1 FROM request_approvals ra
      WHERE ra.request_id = pr.request_id
        AND ra.status = 'pending'
  );
```
**Action**: Document affected request IDs for review and repair

### 2. Notify Stakeholders
- Alert Branch Heads/HODs that affected requests cannot be approved temporarily
- Provide the list of affected request IDs
- Set expectation: Fix will be deployed within [X] hours

### 3. Prevent Further Occurrences
- **Immediate** (without deployment): Document the bug and disable reverting for high-value requests
- **Within 1 hour**: Deploy the code fix (see Deployment Checklist)

---

## Diagnostic Verification

### Step 1: Verify Request Status Mismatch
```sql
-- For a specific request ID (e.g., 12345):
SELECT 
    pr.request_id,
    pr.request_number,
    pr.status,
    COUNT(ra.id) AS pending_approvals,
    MAX(ra.role) AS approval_role,
    pr.updated_at
FROM procurement_requests pr
LEFT JOIN request_approvals ra ON pr.request_id = ra.request_id AND ra.status = 'pending'
WHERE pr.request_id = 12345
GROUP BY pr.request_id;
```
**Expected Result**: Status = SUBMITTED, but pending_approvals = 0 (confirms the bug)

### Step 2: Check Workflow History
```sql
-- Verify that revert action was logged
SELECT * FROM audit_log
WHERE table_name = 'procurement_requests'
  AND record_id = 12345
  AND action = 'WORKFLOW_REVERT'
ORDER BY change_date DESC
LIMIT 5;
```
**Expected**: Should see WORKFLOW_REVERT entries, typically after a status transition backward

### Step 3: Verify Approval Deletion
```sql
-- Check workflow_transition_history (if available) for backward transitions
SELECT * FROM workflow_transition_history
WHERE request_id = 12345
  AND is_backward = 1
ORDER BY created_at DESC;
```
**Expected**: Records showing backward (revert) transitions

### Step 4: Confirm Request Details
```sql
SELECT 
    pr.request_id, pr.request_number, pr.status, pr.request_type,
    pr.estimated_value, pr.branch_id, pr.created_at, pr.updated_at
FROM procurement_requests pr
WHERE pr.request_id = 12345;
```
**Verify**:
- Status matches UI display
- Request type is correct (REGULAR, REIMBURSEMENT, PETTY_CASH, SERVICE_CONTRACT)
- Estimated value and branch ID are set

---

## Root-Cause Analysis Details

### Primary Cause: Approval Deletion Without Recreation

**File**: `/procurement/revert_status.php`, lines 96-103 (before fix)

**Problem Code**:
```php
// OLD CODE (BUGGY)
$pdo->prepare("
    DELETE FROM request_approvals
    WHERE request_id = ?
      AND status = 'pending'
")->execute([$id]);
// ← No approval chain recreation → UI shows pending but database is empty
```

**Why It Happens**:
1. Request is advanced through workflow (SUBMITTED → HOD_APPROVED → GC_APPROVED)
2. A revert action is triggered (e.g., "Send Back for Correction")
3. The revert logic deletes all pending approval records to clear the slate
4. **BUG**: The revert logic does NOT recreate the approval chain for the NEW status
5. Result: Status = SUBMITTED (correct) but request_approvals table is empty
6. When approver tries to approve, query finds no pending tasks → "No pending approvals" error

### Secondary Cause: No Approval Chain Generation on Status Change

**Missing**: No automatic hook/trigger to recreate approval tasks when status changes

**Why It Matters**:
- Approval chain creation is manual, done in each submit file (submit.php, resubmit.php, etc.)
- The revert endpoint only deletes, doesn't recreate
- There's no centralized function to reliably generate approval chains

### Tertiary Issue: Approval Lookup Queries Only Status Table

**File**: `/procurement/approve.php`, lines 96-105

**Problem**:
```php
// Query assumes approval records exist
$stmt = $pdo->prepare("
    SELECT *
    FROM request_approvals
    WHERE request_id = ?
      AND status = 'pending'
    ORDER BY stage_order ASC
    LIMIT 1
");
$stmt->execute([$id]);
$nextApproval = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$nextApproval) {
    // ← This is where the error is triggered
    modalPop("No Pending Approvals", ...);
}
```

---

## Controlled Repair Approach for Affected Requests

### Step 1: Back Up Current State
```bash
# Backup the affected request records and approvals
mysqldump -u[user] -p[pass] prms procurement_requests > /backup/procurement_requests_$(date +%s).sql
mysqldump -u[user] -p[pass] prms request_approvals > /backup/request_approvals_$(date +%s).sql
```

### Step 2: Run Repair Script
```bash
# Run the automated repair script (idempotent, safe to run multiple times)
mysql -u[user] -p[pass] prms < /path/to/database_fixes/repair_workflow_revert_state_mismatch.sql
```

**What the script does**:
1. Identifies all requests with status in approval-requiring states but no pending approvals
2. Logs affected requests in audit_log
3. Recreates approval chains based on request type, amount, and branch
4. Generates summary report of repairs

### Step 3: Verify Repairs
```sql
-- Run this after repair to confirm
SELECT 
    COUNT(*) AS total_affected,
    SUM(CASE WHEN has_approvals = 1 THEN 1 ELSE 0 END) AS now_fixed
FROM (
    SELECT DISTINCT pr.request_id,
        CASE WHEN EXISTS (
            SELECT 1 FROM request_approvals ra
            WHERE ra.request_id = pr.request_id AND ra.status = 'pending'
        ) THEN 1 ELSE 0 END AS has_approvals
    FROM procurement_requests pr
    WHERE pr.status IN ('SUBMITTED', 'HOD_APPROVED', 'FUNDS_VERIFIED', 'DIRECTOR_APPROVED', 'GC_APPROVED')
) repaired;
```

**Expected**: All affected requests should now have pending approvals (has_approvals = 1)

### Step 4: Manual Verification (High-Value Requests)
For any requests over 1,000,000 JMD:
1. Manually verify the recreated approval chain
2. Compare to similar, non-affected requests to ensure consistency
3. If chain seems incorrect, contact approver to manually recreate

---

## Permanent Corrective Actions

### Code Fix 1: Helper Function in workflow.php

New function `createApprovalChain()` centralizes approval chain creation:
```php
function createApprovalChain(PDO $pdo, int $requestId, string $requestType, 
                            float $estimatedValue, ?int $branchId = null): array
```

**Benefits**:
- Single source of truth for approval chain logic
- Idempotent (safe to call multiple times)
- Safely deletes stale approvals before recreating

### Code Fix 2: revert_status.php

Enhanced revert endpoint now:
1. Deletes pending approvals (as before)
2. **NEW**: Immediately recreates them based on new status
3. Logs the recreation for audit trail

### Code Fix 3: No Changes Needed to approval.php

The approval lookup code is correct; it just needs the approval records to exist (which the fix ensures)

---

## Preventive Monitoring and Alerts

### Alert 1: Orphaned Approval Tasks
**Query** (run daily):
```sql
-- Alert if any approval-requiring request has NO pending approvals
SELECT COUNT(*) as orphaned_count
FROM procurement_requests pr
WHERE pr.status IN ('SUBMITTED', 'HOD_APPROVED', 'FUNDS_VERIFIED', 'DIRECTOR_APPROVED', 'GC_APPROVED')
  AND NOT EXISTS (
      SELECT 1 FROM request_approvals ra
      WHERE ra.request_id = pr.request_id AND ra.status = 'pending'
  );
```
**Action**: If count > 0, investigate immediately

### Alert 2: Workflow Revert Activity
**Query** (after each revert):
```sql
-- Verify that revert also recreated approvals
SELECT pr.request_id, COUNT(ra.id) as pending_count
FROM procurement_requests pr
LEFT JOIN request_approvals ra ON pr.request_id = ra.request_id AND ra.status = 'pending'
WHERE pr.request_id = ? -- inserted after revert_status.php completes
GROUP BY pr.request_id
HAVING pending_count = 0;
```
**Action**: If found, run repair script immediately

### Alert 3: Approval Deletion Without Recreation Log Entry
**Monitoring** (application logs):
```
Log pattern: "Failed to recreate approval chain for request X"
Action: Manual intervention required
```

---

## Deployment and Validation Checklist

See separate file: `DEPLOYMENT_CHECKLIST.md`

---

## Rollback Instructions

### If Code Fix Causes Issues

**Quick Rollback** (within 5 minutes):
1. Revert `/procurement/revert_status.php` to previous version
2. Restart PHP-FPM/Apache: `systemctl restart php-fpm` (or Apache equivalent)
3. Verify: Re-run diagnostic query above to confirm no new orphaned tasks

**If Repair Script Applied Incorrectly**:
1. Restore from backup: `mysql -u[user] -p[pass] prms < /backup/request_approvals_[timestamp].sql`
2. Re-run repair script with corrected logic
3. Verify all requests have correct approvals

---

## Communication Template for Stakeholders

### For Affected Users
```
Dear [Branch Head/Approver],

We identified an issue where some reverted requests were not showing in your approval queue.
The issue occurred when a request was moved back to an earlier stage for correction.

FIX DEPLOYED: [Date/Time]
- Affected requests now display in your approval queue
- You can proceed with approvals as normal
- No manual action required from you

If you still see "No pending approvals" for a specific request, please contact [Support Email].

Thank you for your patience.
```

### For Approvers/Supervisors
```
INCIDENT SUMMARY:
- Issue: Workflow revert logic deleted approvals but didn't recreate them
- Impact: ~[X] requests affected; approvals blocked for 24 hours
- Root Cause: Missing approval chain recreation in revert_status.php
- Fix: Code updated; affected requests repaired via database script
- Testing: Regression tests added to prevent recurrence
- Deployment: [Date] [Time] with validation completed

MONITORING: Daily checks now verify no orphaned approvals exist.
```

---

## Related Documentation

- **Technical Deep Dive**: WORKFLOW_REVERT_STATE_MISMATCH_ANALYSIS.md
- **Test Coverage**: tests/WorkflowRevertStateMatchTest.php
- **Database Repair**: database_fixes/repair_workflow_revert_state_mismatch.sql
- **Code Changes**: WORKFLOW_REVERT_FIX_SUMMARY.md

---

## Escalation Path

| Severity | First Responder | Escalate To | Timeframe |
|----------|--|--|--|
| No pending approvals (UI vs DB mismatch) | On-call Engineer | Workflow Lead | 15 min |
| Multiple requests affected (>10) | Workflow Lead | Senior Developer + DBA | 30 min |
| Repeat occurrence after fix | Senior Developer | Architecture Review | 1 hour |

---

**Document Version**: 1.0
**Date**: 2026-08-19
**Author**: Incident Response Team
**Last Updated**: 2026-08-19
