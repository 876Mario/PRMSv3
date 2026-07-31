# RFQ Quote Approval Workflow - Implementation and Deployment Guide

## Quick Start

### 1. Database Migration

Apply the database migrations to add the new tables and columns:

```bash
# Option A: Using MySQL command line
mysql -u <username> -p <database_name> < migrations/2026_07_31_rfq_quote_approval_workflow.sql

# Option B: Using PHP migration runner (if available in your system)
php /path/to/migration_runner.php 2026_07_31_rfq_quote_approval_workflow.sql
```

**What gets created:**
- `rfq_quote_approvals` table - Audit trail for approvals
- `rfq_spec_reviewers` table - Spec reviewer assignments
- `rfq_branch_head_approvers` table - Branch head approver assignments
- Columns in `rfqs` table - Track approval status
- Columns in `audit_log` table - Log approval stages
- Stored procedures - sp_approve_rfq_spec_review, etc.
- Database triggers - Auto-initialize workflows, enforce constraints

### 2. Permission Setup

Create the new permissions in your permissions table:

```bash
# Option A: Using MySQL command line
mysql -u <username> -p <database_name> < migrations/2026_07_31_rfq_approval_workflow_permissions.sql

# Option B: Manually add permissions to permissions table
# Run the INSERT statements in the permissions file
```

**Permissions to add:**
- `approve_rfq_spec_review` - Specification review approval
- `approve_rfq_branch_head` - Branch head approval
- `assign_rfq_spec_reviewer` - Assign spec reviewers
- `assign_rfq_branch_head_approver` - Assign branch head approvers
- `view_rfq_approval_audit` - View approval history
- `admin_override_approvals` - Admin bypass restrictions

### 3. Role Permission Mapping

Assign permissions to roles:

```sql
-- Example: Add spec review permission to Procurement Officer
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.permission_slug = 'approve_rfq_spec_review'
WHERE r.name = 'Procurement Officer'
AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
);
```

### 4. Configuration

Set system configuration for the workflow (optional):

```sql
-- Enable approval notifications (default: enabled)
INSERT INTO system_config (config_key, config_value) 
VALUES ('enable_rfq_approval_notifications', '1')
ON DUPLICATE KEY UPDATE config_value = '1';

-- Set default spec reviewer role
INSERT INTO system_config (config_key, config_value) 
VALUES ('default_spec_reviewer_role', 'Procurement Officer')
ON DUPLICATE KEY UPDATE config_value = 'Procurement Officer';
```

## File Changes Summary

### New Files Created

1. **Database Migrations:**
   - `migrations/2026_07_31_rfq_quote_approval_workflow.sql` - Main workflow schema
   - `migrations/2026_07_31_rfq_approval_workflow_permissions.sql` - Permission setup

2. **PHP Classes:**
   - `services/RFQQuoteApprovalService.php` - Core approval logic

3. **User Interface Pages:**
   - `rfq/spec_review_approve.php` - Specification review interface
   - `rfq/branch_head_approve.php` - Branch head approval interface
   - `rfq/approval_pending.php` - Pending actions dashboard

4. **Configuration Updates:**
   - `config/workflow.php` - Updated with new status transitions
   - `config/notifications.php` - Added approval notification functions

5. **Documentation:**
   - `RFQ_QUOTE_APPROVAL_WORKFLOW.md` - Comprehensive documentation

### Modified Files

1. **`rfq/upload_quote.php`**
   - Added auto-initialization of spec review workflow
   - Added auto-assignment of default spec reviewer
   - Enhanced audit logging with approval_stage column

2. **`config/workflow.php`**
   - Added workflow status transitions:
     - `QUOTE_SPEC_REVIEW_PENDING`
     - `QUOTE_SPEC_REVIEW_APPROVED`
     - `QUOTE_BRANCH_HEAD_APPROVAL_PENDING`
   - Updated `isBackwardTransition()` function
   - Updated status ordering array

## Workflow Activation

### Step 1: Verify Installation

After applying migrations, verify the new tables exist:

```bash
mysql -u <username> -p <database_name> -e "
SHOW TABLES LIKE 'rfq_%approval%';
DESCRIBE rfq_quote_approvals;
DESCRIBE rfq_spec_reviewers;
DESCRIBE rfq_branch_head_approvers;
"
```

### Step 2: Assign Roles and Permissions

Ensure users have appropriate permissions:

```bash
# View permissions for a user
SELECT u.display_name, p.permission_slug
FROM users u
JOIN role_permissions rp ON u.role_id = rp.role_id
JOIN permissions p ON rp.permission_id = p.id
WHERE u.email = 'user@example.com';
```

### Step 3: Test the Workflow

1. **Create a test RFQ** with vendors and setup
2. **Upload a quote** from a vendor
3. **Verify spec reviewer assignment** - Check `rfq_spec_reviewers` table
4. **Access spec review page** - Navigate to `/rfq/spec_review_approve.php?id=<rfq_id>`
5. **Approve quotes** as specification reviewer
6. **Verify branch head assignment** - Check `rfq_branch_head_approvers` table
7. **Access branch head approval page** - Navigate to `/rfq/branch_head_approve.php?id=<rfq_id>`
8. **Grant final approval** as branch head
9. **Check audit trail** - Verify entries in `rfq_quote_approvals` table

### Step 4: Configure Default Spec Reviewers (Optional)

By default, when the first quote is uploaded:
1. System checks if a spec reviewer is already assigned
2. If not, assigns first active user with "Specification Reviewer" role
3. If none found, assigns first active "Procurement Officer"
4. Sends notification email to the assigned reviewer

To customize this behavior, edit `rfq/upload_quote.php` line ~125-150.

### Step 5: Manual Assignment of Branch Head Approvers

Admins must manually assign branch head approvers to RFQs before spec review approval is granted.

**Option A: Database Insert**
```sql
INSERT INTO rfq_branch_head_approvers (rfq_id, approver_id, approver_role, assigned_by, is_active)
VALUES (
    <rfq_id>,
    <user_id>,
    'Branch Head',  -- or appropriate role
    <admin_user_id>,
    1
);
```

**Option B: Future Admin Interface** (not yet created)
- Admins will be able to assign approvers via a UI

## Testing Scenarios

### Scenario 1: Standard Approval Path

```
1. Vendor uploads quote
   → Spec reviewer gets email notification
   → RFQ status: QUOTE_SPEC_REVIEW_PENDING

2. Spec reviewer approves
   → Branch head gets email notification
   → RFQ status: QUOTE_SPEC_REVIEW_APPROVED

3. Branch head approves
   → Procurement team gets email notification
   → RFQ status: QUOTE_APPROVED
   → Ready for supplier selection
```

**Test Steps:**
1. Navigate to `/rfq/list.php`
2. Create new RFQ with estimated value > 500k
3. Add vendors and publish RFQ
4. Login as vendor and upload quote
5. Check email - Spec reviewer should receive notification
6. Login as spec reviewer
7. Go to `/rfq/approval_pending.php` or `/rfq/spec_review_approve.php?id=<id>`
8. Approve quote with comments
9. Check email - Branch head should receive notification
10. Login as branch head
11. Go to `/rfq/branch_head_approve.php?id=<id>`
12. Approve with comments
13. Verify RFQ status is `QUOTE_APPROVED`

### Scenario 2: Rejection Path

```
1. Quote uploaded
2. Spec reviewer rejects with reason
   → Requestor gets rejection notification
   → RFQ status: QUOTE_REVIEW_PENDING

3. Requestor revises and resubmits quotes
4. Spec reviewer approves revised quotes
5. Branch head approves
```

**Test Steps:**
1. Follow steps 1-4 of Scenario 1
2. Go to `/rfq/spec_review_approve.php?id=<id>` as spec reviewer
3. Select "Reject" action
4. Enter rejection reason and submit
5. Check email - Requestor should get rejection notice
6. Login as requestor
7. Upload revised quote
8. Verify RFQ returns to spec review stage
9. Complete approval process

### Scenario 3: Clarification Request Path

```
1. Spec review approved
2. Branch head requests clarification
   → Returns to appropriate stage for revision
   → Requestor gets clarification notice
3. Requestor provides clarification
4. Branch head re-reviews and approves
```

**Test Steps:**
1. Follow steps 1-6 of Scenario 1
2. Go to `/rfq/branch_head_approve.php?id=<id>` as branch head
3. Select "Request Clarification" action
4. Enter clarification details and submit
5. Check email - Requestor should get clarification notice
6. Login as requestor
7. Re-upload clarified quote
8. Branch head re-approves
9. Verify final approval granted

## Troubleshooting

### Common Issues

**Issue: "You are not assigned as a specification reviewer for this RFQ"**
- **Cause:** User not in `rfq_spec_reviewers` table
- **Solution:** Admin assigns user manually or changes default role in config

**Issue: "Specification review must be approved before branch head approval"**
- **Cause:** Branch head trying to approve before spec review complete
- **Solution:** Spec reviewer must approve first; this is a business rule

**Issue: "Cannot create commitment - Branch Head approval not granted"**
- **Cause:** Trying to create commitment before both approvals complete
- **Solution:** Complete both approval stages first

**Issue: No email notifications being sent**
- **Cause:** Notifications disabled in system_config
- **Solution:** Check `enable_notifications` setting:
  ```sql
  SELECT * FROM system_config WHERE config_key = 'enable_notifications';
  ```

**Issue: Spec reviewer not automatically assigned**
- **Cause:** No users with "Specification Reviewer" or "Procurement Officer" roles
- **Solution:** Create users with appropriate roles or manually assign via database

### Checking Workflow Status

```sql
-- Check approval status for an RFQ
SELECT rfq_id, spec_review_status, branch_head_approval_status,
       spec_reviewer_id, branch_head_approver_id
FROM rfqs
WHERE rfq_id = <id>;

-- Check approval assignments
SELECT * FROM rfq_spec_reviewers WHERE rfq_id = <id>;
SELECT * FROM rfq_branch_head_approvers WHERE rfq_id = <id>;

-- Check approval history
SELECT * FROM rfq_quote_approvals WHERE rfq_id = <id> ORDER BY created_at DESC;
```

## Performance Optimization

### Indexes
Database indexes are automatically created by the migration:
- `idx_rfq_spec_review_status` - For status queries
- `idx_rfq_branch_head_approval_status` - For status queries
- `idx_rfq_spec_reviewer_id` - For reviewer lookups
- `idx_rfq_branch_head_approver_id` - For approver lookups
- `idx_approval_stage`, `idx_approver_id`, `idx_action` - On approval audit table

### Query Optimization

For high-volume systems, consider:
1. Partitioning `rfq_quote_approvals` table by date
2. Archiving old approval records periodically
3. Creating materialized views for dashboard queries

Example archive query:
```sql
-- Archive approvals older than 1 year
INSERT INTO rfq_quote_approvals_archive
SELECT * FROM rfq_quote_approvals
WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);

DELETE FROM rfq_quote_approvals
WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);
```

## Backup and Recovery

### Backup Strategy

Ensure the following are backed up:
1. New tables: `rfq_quote_approvals`, `rfq_spec_reviewers`, `rfq_branch_head_approvers`
2. Modified columns in `rfqs` and `audit_log` tables
3. New stored procedures in database
4. New trigger definitions in database
5. PHP source files in `services/`, `rfq/`, and `config/`

### Recovery Procedure

If restoration is needed:
1. Restore database from backup
2. Verify all new tables and columns exist
3. Verify permissions are in place
4. Verify new PHP files are in place
5. Test workflow with test RFQ

## Rollback (if needed)

To remove this feature and revert to previous workflow:

```bash
# Note: This will delete approval data and revert tables
mysql -u <username> -p <database_name> < migrations/rollback_rfq_approval_workflow.sql
```

**WARNING:** Rollback will delete all approval history records. Only use if you need to completely remove the feature.

## Support

For issues or questions:
1. Check this guide's Troubleshooting section
2. Review RFQ_QUOTE_APPROVAL_WORKFLOW.md documentation
3. Check application logs in `/var/log/`
4. Check database error logs
5. Contact system administrator

## Change Log

- **2026-07-31:** Initial implementation
  - Two-stage approval workflow
  - Database schema and permissions
  - User interfaces for spec review and branch head approval
  - Automatic notifications
  - Audit trail tracking
