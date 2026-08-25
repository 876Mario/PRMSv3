# Quick Testing Guide for RFQ Specification Review Fix

## Overview
This guide provides step-by-step testing procedures to verify that the RFQ specification review errors have been fixed.

## Prerequisites
- Migration has been applied successfully
- You have access to the application with appropriate test accounts
- Test data is available (or can be created)

## Test Scenarios

### Test 1: Quote Upload (Critical - Tests Trigger Fix)

**Purpose**: Verify that the trigger `trg_initialize_spec_review_on_first_quote` now uses the correct column name.

**Steps**:
1. Log in as a user with procurement permissions
2. Navigate to an existing RFQ (or create a new one)
3. Add a vendor to the RFQ if not already added
4. Upload a vendor quote document
5. Submit the quote

**Expected Result**:
- ✅ Quote uploads successfully without any database errors
- ✅ No "Column not found: spec_review_status" error appears
- ✅ RFQ status should show that requestor review is pending

**Database Verification**:
```sql
-- Check that the status was set correctly
SELECT rfq_id, rfq_number, requestor_spec_review_status 
FROM rfqs 
WHERE rfq_id = [YOUR_RFQ_ID];

-- Expected: requestor_spec_review_status = 'PENDING'
```

**If Test Fails**:
- Check MySQL error log for trigger-related errors
- Verify trigger definition: `SHOW CREATE TRIGGER trg_initialize_spec_review_on_first_quote;`
- Ensure it contains `requestor_spec_review_status` (not `spec_review_status`)

---

### Test 2: Requestor Specification Approval

**Purpose**: Verify the complete requestor approval workflow.

**Steps**:
1. Complete Test 1 first (upload and select a quote)
2. Log in as the requestor (user who created the request)
3. Navigate to "Requestor Specification Review" or similar menu
4. You should see the RFQ with a pending review status
5. Click to review the selected quote
6. Review the quote details and specifications
7. Click "Approve" or "Confirm Specifications Met"
8. Enter approval comments (e.g., "All specifications are met")
9. Submit the approval

**Expected Result**:
- ✅ Approval submitted successfully without errors
- ✅ RFQ status changes to "Branch Head Approval Pending"
- ✅ Requestor can no longer modify the review
- ✅ Notification sent to Branch Head (check email/in-app notifications)

**Database Verification**:
```sql
-- Check RFQ approval status
SELECT rfq_id, requestor_spec_review_status, requestor_reviewer_id, 
       requestor_reviewed_at, branch_head_approval_status
FROM rfqs 
WHERE rfq_id = [YOUR_RFQ_ID];

-- Expected:
-- requestor_spec_review_status = 'APPROVED'
-- requestor_reviewer_id = [YOUR_USER_ID]
-- requestor_reviewed_at = [TIMESTAMP]
-- branch_head_approval_status = 'PENDING'

-- Check history table
SELECT * FROM rfq_requestor_reviews 
WHERE rfq_id = [YOUR_RFQ_ID] 
ORDER BY review_date DESC;

-- Expected: One record with review_outcome = 'MEETS_SPECIFICATIONS'

-- Check audit trail
SELECT * FROM rfq_quote_approvals 
WHERE rfq_id = [YOUR_RFQ_ID] 
  AND approval_stage = 'REQUESTOR_REVIEW'
ORDER BY created_at DESC;

-- Expected: One record with action = 'APPROVED'
```

---

### Test 3: Requestor Specification Rejection

**Purpose**: Verify the requestor rejection workflow (returning quote to procurement).

**Steps**:
1. Create a new RFQ with uploaded quote (or use existing)
2. Select a quote for review
3. Log in as the requestor
4. Navigate to the specification review interface
5. Click "Reject" or "Does Not Meet Specifications"
6. Enter detailed rejection reason (must be at least 5 characters)
7. Submit the rejection

**Expected Result**:
- ✅ Rejection submitted successfully
- ✅ RFQ status returns to "Quote Review Pending"
- ✅ Notification sent to procurement officer
- ✅ Branch Head approval workflow is NOT triggered

**Database Verification**:
```sql
-- Check RFQ rejection status
SELECT rfq_id, requestor_spec_review_status, requestor_review_comments,
       branch_head_approval_status
FROM rfqs 
WHERE rfq_id = [YOUR_RFQ_ID];

-- Expected:
-- requestor_spec_review_status = 'REJECTED'
-- requestor_review_comments = [YOUR_REJECTION_REASON]
-- branch_head_approval_status = 'PENDING' (not changed)

-- Check history
SELECT * FROM rfq_requestor_reviews 
WHERE rfq_id = [YOUR_RFQ_ID] 
ORDER BY review_date DESC;

-- Expected: One record with review_outcome = 'DOES_NOT_MEET_SPECIFICATIONS'

-- Check procurement request status
SELECT status FROM procurement_requests pr
JOIN rfqs r ON r.request_id = pr.request_id
WHERE r.rfq_id = [YOUR_RFQ_ID];

-- Expected: status = 'QUOTE_REVIEW_PENDING'
```

---

### Test 4: Branch Head Approval

**Purpose**: Verify branch head can approve after requestor approval.

**Steps**:
1. Complete Test 2 first (requestor approval)
2. Log in as a Branch Head or HOD
3. Navigate to "Branch Head RFQ Approvals" or similar menu
4. Find the RFQ awaiting approval
5. Review the quote and requestor comments
6. Click "Approve Award"
7. Enter approval comments
8. Submit the approval

**Expected Result**:
- ✅ Approval submitted successfully
- ✅ RFQ status changes to "Quote Approved"
- ✅ Can proceed to commitment creation
- ✅ Notification sent to relevant parties

**Database Verification**:
```sql
-- Check branch head approval
SELECT rfq_id, branch_head_approval_status, branch_head_approver_id,
       branch_head_approved_at, branch_head_comments
FROM rfqs 
WHERE rfq_id = [YOUR_RFQ_ID];

-- Expected:
-- branch_head_approval_status = 'APPROVED'
-- branch_head_approver_id = [BRANCH_HEAD_USER_ID]
-- branch_head_approved_at = [TIMESTAMP]

-- Check approval audit trail
SELECT * FROM rfq_quote_approvals 
WHERE rfq_id = [YOUR_RFQ_ID] 
  AND approval_stage = 'BRANCH_HEAD_APPROVAL'
ORDER BY created_at DESC;

-- Expected: One record with action = 'APPROVED'
```

---

### Test 5: Commitment Creation Validation

**Purpose**: Verify that commitment creation enforces approval requirements.

**Steps**:

**Part A - Without Approvals**:
1. Create a new RFQ with a selected quote
2. Do NOT complete requestor or branch head approvals
3. Attempt to create a commitment for this RFQ

**Expected Result**:
- ❌ Commitment creation should FAIL
- ✅ Error message: "Cannot create commitment: Requestor specification confirmation not approved"

**Part B - With Requestor Approval Only**:
1. Complete requestor approval (Test 2)
2. Do NOT complete branch head approval yet
3. Attempt to create a commitment

**Expected Result**:
- ❌ Commitment creation should FAIL
- ✅ Error message: "Cannot create commitment: Branch Head approval not granted"

**Part C - With Both Approvals**:
1. Complete both requestor and branch head approvals
2. Attempt to create a commitment

**Expected Result**:
- ✅ Commitment created successfully
- ✅ No approval-related errors

**Database Verification**:
```sql
-- Try to insert a commitment (this will test the trigger)
-- Note: Run this in a transaction and rollback after testing

START TRANSACTION;

INSERT INTO commitments 
(rfq_id, selected_quote_id, commitment_amount, currency, created_by)
VALUES ([RFQ_ID], [QUOTE_ID], 10000, 'JMD', [USER_ID]);

-- If both approvals are complete: Should succeed
-- If either approval is missing: Should fail with appropriate error

ROLLBACK;  -- Don't actually create the test commitment
```

---

### Test 6: Backward-Compatible Procedure Calls

**Purpose**: Verify that the old stored procedure names still work as aliases.

**Database Test**:
```sql
-- Test the old procedure name (should work as an alias)
START TRANSACTION;

-- This should call the new procedure internally
CALL sp_approve_rfq_spec_review(
    [TEST_RFQ_ID],
    [TEST_USER_ID],
    'Test approval comment',
    [TEST_QUOTE_ID]
);

-- Verify it worked
SELECT requestor_spec_review_status, requestor_review_comments
FROM rfqs WHERE rfq_id = [TEST_RFQ_ID];

ROLLBACK;  -- Don't keep the test data
```

**Expected Result**:
- ✅ Procedure executes without errors
- ✅ Procedure does NOT return "does not exist" error
- ✅ RFQ status is updated correctly

---

### Test 7: Multiple Quote Uploads (Edge Case)

**Purpose**: Verify that the trigger only initializes status on the FIRST quote, not subsequent uploads.

**Steps**:
1. Create a new RFQ
2. Add vendor A and upload a quote
3. Check the requestor_spec_review_status (should be 'PENDING')
4. Add vendor B and upload another quote
5. Check the status again (should still be 'PENDING', not reset)
6. Add vendor C and upload a third quote
7. Verify status is still intact

**Expected Result**:
- ✅ First quote upload sets status to 'PENDING'
- ✅ Subsequent quote uploads do NOT reset the status
- ✅ If status was changed to 'APPROVED' or 'REJECTED', it remains unchanged

**Database Verification**:
```sql
-- Check quote count and status
SELECT r.rfq_id, r.rfq_number, r.requestor_spec_review_status,
       COUNT(q.quote_id) as quote_count
FROM rfqs r
LEFT JOIN rfq_vendors rv ON rv.rfq_id = r.rfq_id
LEFT JOIN rfq_quotes q ON q.rfq_vendor_id = rv.rfq_vendor_id
WHERE r.rfq_id = [YOUR_RFQ_ID]
GROUP BY r.rfq_id, r.rfq_number, r.requestor_spec_review_status;
```

---

## Quick Verification Commands

### Check Trigger Definition
```sql
SHOW CREATE TRIGGER trg_initialize_spec_review_on_first_quote;
```
Should contain: `requestor_spec_review_status`

### List All RFQ-Related Procedures
```sql
SHOW PROCEDURE STATUS WHERE Name LIKE '%rfq%spec%' OR Name LIKE '%rfq%requestor%';
```
Should show 4 procedures:
- sp_approve_rfq_requestor_review
- sp_reject_rfq_requestor_review
- sp_approve_rfq_spec_review (alias)
- sp_reject_rfq_spec_review (alias)

### Check RFQ Approval Status
```sql
SELECT rfq_id, rfq_number, 
       requestor_spec_review_status,
       branch_head_approval_status,
       requestor_reviewer_id,
       branch_head_approver_id
FROM rfqs
WHERE rfq_id = [YOUR_RFQ_ID];
```

### View Recent Approval History
```sql
-- Requestor reviews
SELECT r.rfq_number, rr.review_outcome, u.full_name as reviewer, 
       rr.comments, rr.review_date
FROM rfq_requestor_reviews rr
JOIN rfqs r ON r.rfq_id = rr.rfq_id
JOIN users u ON u.user_id = rr.requestor_id
ORDER BY rr.review_date DESC
LIMIT 10;

-- All approvals (both stages)
SELECT r.rfq_number, qa.approval_stage, qa.action, 
       u.full_name as approver, qa.created_at
FROM rfq_quote_approvals qa
JOIN rfqs r ON r.rfq_id = qa.rfq_id
JOIN users u ON u.user_id = qa.approver_id
ORDER BY qa.created_at DESC
LIMIT 10;
```

---

## Test Results Checklist

Use this checklist to track your testing progress:

- [ ] Test 1: Quote Upload - PASSED
- [ ] Test 2: Requestor Approval - PASSED
- [ ] Test 3: Requestor Rejection - PASSED
- [ ] Test 4: Branch Head Approval - PASSED
- [ ] Test 5: Commitment Validation (Part A) - PASSED
- [ ] Test 5: Commitment Validation (Part B) - PASSED
- [ ] Test 5: Commitment Validation (Part C) - PASSED
- [ ] Test 6: Backward-Compatible Procedures - PASSED
- [ ] Test 7: Multiple Quote Uploads - PASSED

---

## Troubleshooting

### Issue: "Column not found: spec_review_status" error still appears

**Diagnosis**:
```sql
-- Check if trigger is using correct column
SHOW CREATE TRIGGER trg_initialize_spec_review_on_first_quote;
```

**Solution**:
- If trigger still uses `spec_review_status`, re-run the migration
- Check MySQL error log for trigger recreation failures

### Issue: "Procedure does not exist" error

**Diagnosis**:
```sql
-- Check if procedures exist
SELECT ROUTINE_NAME FROM INFORMATION_SCHEMA.ROUTINES 
WHERE ROUTINE_SCHEMA = DATABASE() 
  AND ROUTINE_NAME LIKE '%rfq%spec%';
```

**Solution**:
- If procedures are missing, re-run the migration
- Ensure DEFINER permissions are correct

### Issue: Approval workflow is stuck

**Diagnosis**:
```sql
-- Check current approval states
SELECT rfq_id, rfq_number, 
       requestor_spec_review_status as req_status,
       branch_head_approval_status as bh_status,
       pr.status as request_status
FROM rfqs r
JOIN procurement_requests pr ON pr.request_id = r.request_id
WHERE r.rfq_id = [YOUR_RFQ_ID];
```

**Solution**:
- Verify that `requestor_spec_review_status` is 'APPROVED' before branch head can review
- Check that request status matches the approval stage
- Review audit logs in `rfq_requestor_reviews` and `rfq_quote_approvals`

---

## Success Criteria

The fix is considered successful when:

✅ All quote uploads complete without database errors
✅ Requestor approval workflow functions correctly
✅ Branch head approval workflow functions correctly  
✅ Commitment creation properly validates both approval stages
✅ Backward-compatible procedure aliases work correctly
✅ Multiple quote uploads don't interfere with approval status
✅ All audit trails are properly recorded

---

## Contact & Support

If you encounter issues not covered in this guide:
1. Check the MySQL error log
2. Review the comprehensive documentation in `RFQ_SPEC_REVIEW_ERROR_FIX_COMPLETE.md`
3. Verify the migration was applied correctly
4. Check that all database objects (trigger, procedures) exist with correct definitions

---

**Document Version**: 1.0  
**Last Updated**: August 25, 2026  
**Related Documents**: 
- `RFQ_SPEC_REVIEW_ERROR_FIX_COMPLETE.md` - Complete analysis and deployment guide
- `migrations/2026_08_25_fix_spec_review_trigger_and_procedures.sql` - Migration script
