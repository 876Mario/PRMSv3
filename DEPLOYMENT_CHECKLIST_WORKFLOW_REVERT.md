# DEPLOYMENT AND VALIDATION CHECKLIST
## Workflow Revert State-Mismatch Bug Fix

**Deployment Date**: _________________  
**Deployed By**: _________________  
**Reviewer**: _________________  
**Environment**: [ ] DEV  [ ] STAGING  [ ] PRODUCTION

---

## PRE-DEPLOYMENT (24 Hours Before)

### Code Review
- [ ] Code changes reviewed by at least 2 senior developers
- [ ] No security vulnerabilities identified by CodeQL/SAST scanner
- [ ] No secrets committed (API keys, tokens, passwords)
- [ ] Changes are minimal and focused on the bug fix

**Files Modified**:
- [ ] `/config/workflow.php` - Added centralized approval chain functions
- [ ] `/procurement/revert_status.php` - Added approval chain recreation logic

**Files Created**:
- [ ] `/database_fixes/repair_workflow_revert_state_mismatch.sql` - Repair script
- [ ] `/tests/WorkflowRevertStateMatchTest.php` - Comprehensive regression tests
- [ ] `INCIDENT_RUNBOOK_WORKFLOW_REVERT.md` - Incident documentation

### Database Backup
- [ ] Full database backup completed
  - Backup location: `_________________________________`
  - Backup verified: [ ] Yes  [ ] No
  - Retention policy: Keep for 30 days minimum

- [ ] Specific tables backed up:
  - [ ] `procurement_requests` - backed up to `/backup/procurement_requests_[timestamp].sql`
  - [ ] `request_approvals` - backed up to `/backup/request_approvals_[timestamp].sql`
  - [ ] `audit_log` - backed up to `/backup/audit_log_[timestamp].sql`
  - [ ] `workflow_transition_history` - backed up to `/backup/workflow_transition_history_[timestamp].sql`

### Staging Validation
- [ ] Code deployed to STAGING environment
- [ ] All regression tests pass: `phpunit tests/WorkflowRevertStateMatchTest.php`
  - Test output: `_________________________________`
  - Result: [ ] PASS  [ ] FAIL

- [ ] Manual testing on STAGING:
  - [ ] Create a procurement request (REGULAR type, 200k value)
  - [ ] Advance it to HOD_APPROVED status
  - [ ] Revert it back to SUBMITTED
  - [ ] Verify approval tasks exist in database
  - [ ] Verify Branch Head can see it in approval queue
  - [ ] Verify Branch Head can approve without error

- [ ] Database repair script tested:
  - [ ] Run on STAGING database
  - [ ] Verify affected requests are identified correctly
  - [ ] Verify approval chains are recreated
  - [ ] Verify no duplicates created
  - [ ] Rollback from backup and re-verify script idempotency

### Approval and Sign-Off
- [ ] Code review approved by senior developer
  - Approver: `_________________________________`
  - Date: `_________________________________`

- [ ] QA testing approved
  - Tester: `_________________________________`
  - Date: `_________________________________`

- [ ] Database changes approved by DBA
  - DBA: `_________________________________`
  - Date: `_________________________________`

- [ ] Business owner/Workflow lead approval
  - Owner: `_________________________________`
  - Date: `_________________________________`

---

## DEPLOYMENT TO PRODUCTION

### Pre-Deployment Steps (30 Minutes Before)

- [ ] Notify all stakeholders of deployment window
  - Notification channel: Slack / Email / Jira
  - Message sent at: `_________________________________`

- [ ] Verify no active approvals in-progress
  ```sql
  SELECT COUNT(*) FROM request_approvals 
  WHERE status = 'pending' 
  AND updated_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE);
  ```
  Count: `_____` (should be normal, not elevated)

- [ ] Verify database connectivity and performance
  - [ ] DB response time < 100ms: `_____ ms`
  - [ ] No locks: `_________________________________`
  - [ ] Disk space available: `_____ GB (need >10GB free)`

### Code Deployment

- [ ] Copy files to production server
  ```bash
  # Update /config/workflow.php
  cp /staging/config/workflow.php /prod/config/workflow.php
  
  # Update /procurement/revert_status.php
  cp /staging/procurement/revert_status.php /prod/procurement/revert_status.php
  ```
  Result: [ ] Success  [ ] Failed

- [ ] Verify file permissions
  ```bash
  ls -la /prod/config/workflow.php
  ls -la /prod/procurement/revert_status.php
  ```
  Permissions: [ ] 644 or 664 (readable)

- [ ] Verify no syntax errors
  ```bash
  php -l /prod/config/workflow.php
  php -l /prod/procurement/revert_status.php
  ```
  Output: [ ] No errors  [ ] Errors found

### Restart Services
- [ ] Gracefully restart PHP-FPM
  ```bash
  systemctl restart php-fpm
  ```
  Status: [ ] Successful  [ ] Failed

- [ ] Verify web server is responding
  ```bash
  curl -s https://[production-url]/procurement/list.php | head -1
  ```
  Response: [ ] HTTP 200  [ ] Other status code

- [ ] Check error logs for any new errors
  ```bash
  tail -100 /var/log/php-fpm/error.log
  tail -100 /var/log/apache2/error.log  # or nginx error.log
  ```
  Errors: [ ] None  [ ] Found: `_________________________________`

### Database Migration (If Applicable)

- [ ] Production database is online and healthy
  - [ ] Connections: Normal
  - [ ] CPU: < 70%
  - [ ] Memory: < 80%

---

## IMMEDIATE POST-DEPLOYMENT VALIDATION (1 Hour After)

### Smoke Tests

- [ ] Basic procurement workflows still work
  - [ ] Can create new request: [ ] Yes  [ ] No
  - [ ] Can list requests: [ ] Yes  [ ] No
  - [ ] Can view request details: [ ] Yes  [ ] No

- [ ] Test the bug fix specifically
  - [ ] Create request → Advance to HOD_APPROVED → Revert to SUBMITTED
  - [ ] Verify approval records exist after revert: [ ] Yes  [ ] No
  - [ ] Verify Branch Head can approve without "No pending approvals" error: [ ] Yes  [ ] No

### Database Health Checks

- [ ] No orphaned approval tasks exist
  ```sql
  SELECT COUNT(*) as orphaned FROM procurement_requests pr
  WHERE pr.status IN ('SUBMITTED', 'HOD_APPROVED', 'FUNDS_VERIFIED', 'DIRECTOR_APPROVED', 'GC_APPROVED')
    AND NOT EXISTS (SELECT 1 FROM request_approvals WHERE request_id = pr.request_id AND status = 'pending');
  ```
  Orphaned count: `_____` (should be 0 for new requests)

- [ ] No duplicate approval tasks created
  ```sql
  SELECT request_id, role, COUNT(*) FROM request_approvals 
  WHERE request_id IN (
    SELECT DISTINCT request_id FROM audit_log 
    WHERE action = 'WORKFLOW_REVERT' AND change_date > DATE_SUB(NOW(), INTERVAL 2 HOUR)
  )
  GROUP BY request_id, role HAVING COUNT(*) > 1;
  ```
  Result: [ ] No duplicates  [ ] Duplicates found: `_________________________________`

- [ ] Verify audit trail is correct
  ```sql
  SELECT action, COUNT(*) FROM audit_log 
  WHERE table_name = 'procurement_requests' AND change_date > DATE_SUB(NOW(), INTERVAL 2 HOUR)
  GROUP BY action;
  ```
  Notable actions: `_________________________________`

### Application Logs Review

- [ ] No new "No pending approvals" errors in application logs
  ```bash
  grep -i "no pending approval" /var/log/application.log | tail -10
  ```
  Found: [ ] None  [ ] Count: `_____`

- [ ] No "Failed to recreate approval chain" errors
  ```bash
  grep -i "recreate approval chain" /var/log/application.log | tail -10
  ```
  Found: [ ] None  [ ] Count: `_____`

- [ ] No database errors in PHP error log
  ```bash
  grep -i "PDO\|SQL\|database" /var/log/php-fpm/error.log | tail -10
  ```
  Found: [ ] None  [ ] Count: `_____`

### User-Facing Validation

- [ ] Can log in to application: [ ] Yes  [ ] No
- [ ] Can access procurement module: [ ] Yes  [ ] No
- [ ] Can initiate approval action on reverted request: [ ] Yes  [ ] No
- [ ] No 500 errors or blank pages observed: [ ] Correct  [ ] Issues found

---

## COMPREHENSIVE TESTING (2-4 Hours After)

### Regression Test Execution

- [ ] Run full test suite:
  ```bash
  phpunit tests/WorkflowRevertStateMatchTest.php -v
  ```
  Result: [ ] PASS (all 10 tests)  [ ] FAIL

  Individual test results:
  - [ ] testRevertToSubmittedRecreatesApprovalChain: PASS / FAIL
  - [ ] testRevertApprovalChainHasCorrectRoles: PASS / FAIL
  - [ ] testRevertDoesNotCreateDuplicateApprovals: PASS / FAIL
  - [ ] testReimbursementRevertCreatesFinanceOfficerApproval: PASS / FAIL
  - [ ] testPettyCashRevertCreatesFinanceOfficerApproval: PASS / FAIL
  - [ ] testHighValueRevertCreatesMultipleApprovals: PASS / FAIL
  - [ ] testRevertIdempotency: PASS / FAIL
  - [ ] testHrmaRevertGetsDirecotrHrmaApproval: PASS / FAIL
  - [ ] testAnalyticalBranchRevertsGetsDeputyGcApproval: PASS / FAIL
  - [ ] testApprovalLookupReturnsRecreatedTasks: PASS / FAIL

### Edge Case Testing

- [ ] Test with various request types:
  - [ ] REGULAR under threshold (200k): Approval chain correct? [ ] Yes  [ ] No
  - [ ] REGULAR over threshold (750k): Multiple approvals created? [ ] Yes  [ ] No
  - [ ] REIMBURSEMENT: Finance Officer approval created? [ ] Yes  [ ] No
  - [ ] PETTY_CASH: Finance Officer approval created? [ ] Yes  [ ] No
  - [ ] SERVICE_CONTRACT: Correct approval chain? [ ] Yes  [ ] No

- [ ] Test with various branches:
  - [ ] HRM&A (branch 5): Director HRM&A approval? [ ] Yes  [ ] No
  - [ ] Analytical & Advisory (branch 6): Deputy GC approval? [ ] Yes  [ ] No
  - [ ] Other branches: HOD approval? [ ] Yes  [ ] No

- [ ] Test idempotency (run revert 3 times):
  - [ ] First revert: Approval chain created? [ ] Yes  [ ] No
  - [ ] Second revert: No duplicates? [ ] Correct  [ ] Duplicates found
  - [ ] Third revert: Chain still correct? [ ] Yes  [ ] No

### Integration Testing

- [ ] End-to-end approval workflow:
  1. Create request: [ ] Success  [ ] Failed
  2. Advance to HOD_APPROVED: [ ] Success  [ ] Failed
  3. Revert to SUBMITTED: [ ] Success  [ ] Failed
  4. Verify approvals exist: [ ] Yes  [ ] No
  5. HOD approves: [ ] Success  [ ] Failed (verify no "No pending approvals" error)
  6. Next stage correctly set: [ ] Yes  [ ] No

---

## DATABASE REPAIR SCRIPT DEPLOYMENT (If Needed)

### Pre-Repair Preparation

- [ ] Identify all affected requests (use diagnostic query)
  ```sql
  SELECT COUNT(*) FROM procurement_requests pr
  WHERE pr.status IN ('SUBMITTED', 'HOD_APPROVED', 'FUNDS_VERIFIED', 'DIRECTOR_APPROVED', 'GC_APPROVED')
    AND NOT EXISTS (SELECT 1 FROM request_approvals WHERE request_id = pr.request_id AND status = 'pending');
  ```
  Affected count: `_____`

- [ ] Create pre-repair backup
  ```bash
  mysqldump -u[user] -p[pass] prms > /backup/prms_before_repair_$(date +%s).sql
  ```
  Backup created: [ ] Yes  [ ] No
  Location: `_________________________________`

### Repair Script Execution

- [ ] Execute repair script
  ```bash
  mysql -u[user] -p[pass] prms < /path/to/database_fixes/repair_workflow_revert_state_mismatch.sql
  ```
  Result: [ ] Success  [ ] Failed
  Error message: `_________________________________`

- [ ] Verify repair results
  ```sql
  SELECT COUNT(*) FROM procurement_requests pr
  WHERE pr.status IN ('SUBMITTED', 'HOD_APPROVED', 'FUNDS_VERIFIED', 'DIRECTOR_APPROVED', 'GC_APPROVED')
    AND NOT EXISTS (SELECT 1 FROM request_approvals WHERE request_id = pr.request_id AND status = 'pending');
  ```
  Still orphaned: `_____` (should be 0)

- [ ] Verify audit trail for repairs
  ```sql
  SELECT COUNT(*) FROM audit_log 
  WHERE action = 'AUTO_REPAIR_APPROVAL_CHAIN' 
  AND change_date > DATE_SUB(NOW(), INTERVAL 1 HOUR);
  ```
  Repair records created: `_____`

---

## POST-DEPLOYMENT MONITORING (24 Hours)

### Ongoing Monitoring

- [ ] Set up daily check for orphaned approvals (add to cron)
  ```bash
  # Add to /etc/cron.d/prms_monitoring
  0 8 * * * root /usr/bin/php /home/prms/tools/check_orphaned_approvals.php
  ```
  Scheduled: [ ] Yes  [ ] No

- [ ] Monitor logs for new "No pending approvals" errors
  - [ ] Daily review: [ ] Scheduled  [ ] Not scheduled
  - [ ] Slack alert configured: [ ] Yes  [ ] No

- [ ] Track approval success rates
  - [ ] Current approval success rate: `_____ %`
  - [ ] Target: > 99.5%

### User Feedback Collection

- [ ] Email sent to approvers asking about issue resolution
  - [ ] Date sent: `_________________________________`
  - [ ] Response rate: `_____ %`

- [ ] Monitor helpdesk tickets for related issues
  - [ ] Any new "approval" related tickets: [ ] Yes  [ ] No
  - [ ] If yes, count and severity: `_________________________________`

---

## ROLLBACK PLAN (Execute Only If Issues Found)

### Condition for Rollback
- [ ] If approval workflow is broken after deployment
- [ ] If more than 5 approvals fail with "No pending approvals" error within 1 hour of deployment
- [ ] If database repair script corrupts data
- [ ] If more than 2 "Failed to recreate approval chain" errors in logs

### Rollback Procedure

**Step 1: Stop Further Damage (< 5 minutes)**
```bash
# Revert code files
cp /backup/workflow.php.pre-deployment /prod/config/workflow.php
cp /backup/revert_status.php.pre-deployment /prod/procurement/revert_status.php

# Restart services
systemctl restart php-fpm
```

**Step 2: Restore Database (5-15 minutes)**
```bash
# Restore from backup (if repair script was run)
mysql -u[user] -p[pass] prms < /backup/prms_before_repair_[timestamp].sql

# Or just restore approval records
mysql -u[user] -p[pass] prms < /backup/request_approvals_[timestamp].sql
```

**Step 3: Verify Rollback**
```sql
SELECT COUNT(*) FROM request_approvals;
SELECT COUNT(*) FROM procurement_requests WHERE status = 'SUBMITTED';
```

**Step 4: Notify Stakeholders**
- [ ] Email sent: `_________________________________`
- [ ] Slack message posted: [ ] Yes  [ ] No
- [ ] Support team notified: [ ] Yes  [ ] No

**Step 5: Post-Mortem**
- [ ] Root cause of rollback identified: `_________________________________`
- [ ] Fix applied to code: [ ] Yes  [ ] No
- [ ] Re-deployed after fix: [ ] Yes  [ ] No

---

## FINAL SIGN-OFF

### Deployment Success Confirmation

- [ ] All immediate post-deployment checks passed
- [ ] No critical errors in logs
- [ ] Users report no issues
- [ ] Approval workflows functioning correctly
- [ ] Database health is normal

### Deployment Lead Sign-Off

**Name**: `_________________________________`  
**Date**: `_________________________________`  
**Time**: `_________________________________`  
**Approval**: [ ] APPROVED  [ ] REJECTED

### Project Manager Sign-Off

**Name**: `_________________________________`  
**Date**: `_________________________________`  
**Notes**: `_________________________________`  
**Status**: [ ] DEPLOYED  [ ] NEEDS ATTENTION

---

## DOCUMENTATION REFERENCES

- Incident Runbook: `INCIDENT_RUNBOOK_WORKFLOW_REVERT.md`
- Technical Analysis: `WORKFLOW_REVERT_STATE_MISMATCH_ANALYSIS.md` (if created)
- Test Suite: `tests/WorkflowRevertStateMatchTest.php`
- Repair Script: `database_fixes/repair_workflow_revert_state_mismatch.sql`
- Code Changes: See Git commits for details

---

**Document Version**: 1.0  
**Date Created**: 2026-08-19  
**Last Updated**: 2026-08-19  
**Next Review**: 2026-08-26
