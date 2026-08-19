# RBAC Permission Assignment Bug - Executive Summary

## The Problem

A critical bug in the RBAC (Role-Based Access Control) system prevented users from accessing pages with newly created permissions, even though:
- The permissions were properly created in the database
- The pages had correct permission requirements
- The users had the appropriate roles
- The permission assignment UI worked correctly

**Impact**: Only Admin and SuperAdmin could access pages requiring new permissions due to their built-in bypass. All other users were denied access.

---

## Root Cause

The migration that created new signed request permissions (`2026_08_19_signed_request_management_extension.sql`) had **three critical bugs**:

### Bug #1: Missing Role Assignments (PRIMARY)

```sql
-- Migration ONLY did this:
INSERT INTO permissions (name, description) VALUES (
  'print_reimbursement_approval_form', 
  'Print approval forms for reimbursement requests'
);

-- It NEVER did this:
INSERT INTO role_permissions (role_id, permission_id) 
SELECT role_id, permission_id FROM ...;
```

**Result**: The permission existed in the database but no role had it assigned.

### Bug #2: Missing Page Permissions Entries (SECONDARY)

Four pages lacked entries in the `page_permissions` table:
- `/reimbursement/print_for_signing.php`
- `/reimbursement/print_for_approval.php`
- `/petty_cash/print_for_signing.php`
- `/petty_cash/print_for_approval.php`

### Bug #3: SQL Constraint Syntax Error (TERTIARY)

The UNIQUE KEY with WHERE clause was incompatible with some MariaDB versions:
```sql
UNIQUE KEY `uk_signed_req_active` (`request_id`, `is_active`) WHERE `is_active` = 1
```

---

## How RBAC Permission Checks Work

```
User requests /reimbursement/print_for_signing.php
    ↓
Page requires permission: 'print_reimbursement_approval_form'
    ↓
has_permission() checks (in order):
  1. Is Admin/SuperAdmin? → YES → Grant access
  2. Does user have override? → Check user_permissions table
  3. Does role have permission? → Check role_permissions table ← BUG HERE!
  4. Do additional roles have permission? → Check user_roles + role_permissions
  ↓
Result: Permission NOT found in role_permissions table → ACCESS DENIED
```

The bug occurred at step 3: the permission ID didn't exist in the `role_permissions` table for any role.

---

## The Fix

### Fix #1: Assign Permissions to Roles

**File**: `migrations/2026_08_19_fix_signed_request_permission_assignments.sql`

Assigns the 8 new permissions to appropriate roles:

| Permission | Assigned To Roles |
|---|---|
| `print_reimbursement_approval_form` | Finance Officer (3), HOD (4), DGC (9), Director HRM&A (10), Admin (5), SuperAdmin (6) |
| `upload_signed_reimbursement_document` | Finance Officer (3), HOD (4), DGC (9), Director HRM&A (10), Admin (5), SuperAdmin (6) |
| `print_petty_cash_approval_form` | Finance Officer (3), HOD (4), DGC (9), Director HRM&A (10), Admin (5), SuperAdmin (6) |
| `upload_signed_petty_cash_document` | Finance Officer (3), HOD (4), DGC (9), Director HRM&A (10), Admin (5), SuperAdmin (6) |
| `edit_reimbursement_request_admin` | Admin (5), SuperAdmin (6) |
| `edit_petty_cash_request_admin` | Admin (5), SuperAdmin (6) |
| `view_admin_edits_log` | Admin (5), SuperAdmin (6) |
| `export_signed_request_documents` | Admin (5), SuperAdmin (6) |

### Fix #2: Add Page Permission Mappings

**Same file** adds 4 entries to `page_permissions` table:

```sql
INSERT INTO page_permissions (page_path, page_title, permission_name, module) VALUES
  ('/reimbursement/print_for_signing.php', 'Print Reimbursement for Signing', 'print_reimbursement_approval_form', 'Reimbursements'),
  ('/reimbursement/print_for_approval.php', 'Print Reimbursement for Approval', 'view_requests', 'Reimbursements'),
  ('/petty_cash/print_for_signing.php', 'Print Petty Cash for Signing', 'print_petty_cash_approval_form', 'Petty Cash'),
  ('/petty_cash/print_for_approval.php', 'Print Petty Cash for Approval', 'view_requests', 'Petty Cash');
```

### Fix #3: Fix SQL Constraint

**Files**: 
- `2026_08_19_signed_request_management_extension.sql` (removed problematic constraint)
- `2026_08_19_fix_signed_request_constraint.sql` (adds proper constraint via generated column + triggers)

Uses a compatibility-friendly approach:
1. Add generated column `active_marker` that is NULL when inactive/deleted
2. Create UNIQUE index on `active_marker` (naturally excludes NULL values)
3. Add triggers to enforce single-active-document-per-request invariant

---

## Exact Database Records After Fix

### Query to Verify Finance Officer Can Now Access Print Page

```sql
SELECT 
    'Finance Officer' AS role,
    'print_reimbursement_approval_form' AS permission,
    CASE WHEN COUNT(*) > 0 THEN 'CAN ACCESS' ELSE 'DENIED' END AS status
FROM role_permissions rp
WHERE rp.role_id = 3
AND rp.permission_id = (SELECT id FROM permissions WHERE name = 'print_reimbursement_approval_form');
```

**After fix**: Returns "CAN ACCESS" ✓

### Expected Counts

```sql
-- Should return 8
SELECT COUNT(*) FROM permissions 
WHERE name IN (
  'print_reimbursement_approval_form',
  'upload_signed_reimbursement_document',
  'print_petty_cash_approval_form',
  'upload_signed_petty_cash_document',
  'edit_reimbursement_request_admin',
  'edit_petty_cash_request_admin',
  'view_admin_edits_log',
  'export_signed_request_documents'
);

-- Should return at least 40 (8 permissions × 5-6 roles)
SELECT COUNT(*) FROM role_permissions rp
WHERE rp.permission_id IN (
  SELECT id FROM permissions WHERE name IN (
    'print_reimbursement_approval_form',
    'upload_signed_reimbursement_document',
    'print_petty_cash_approval_form',
    'upload_signed_petty_cash_document',
    'edit_reimbursement_request_admin',
    'edit_petty_cash_request_admin',
    'view_admin_edits_log',
    'export_signed_request_documents'
  )
);

-- Should return 4
SELECT COUNT(*) FROM page_permissions
WHERE page_path IN (
  '/reimbursement/print_for_signing.php',
  '/reimbursement/print_for_approval.php',
  '/petty_cash/print_for_signing.php',
  '/petty_cash/print_for_approval.php'
) AND is_active = 1;
```

---

## Deployment Instructions

### Prerequisites

- Database backup: `mysqldump -u user -p database_name > backup.sql`
- MySQL 5.7.8+ or MariaDB 10.2.2+ (for generated columns)
- Maintenance window (minimal user activity)

### Step-by-Step Deployment

```bash
# 1. Apply constraint fix (handles MySQL version compatibility)
mysql -u user -p database_name < migrations/2026_08_19_fix_signed_request_constraint.sql

# 2. Apply permission assignments fix (the main bug fix)
mysql -u user -p database_name < migrations/2026_08_19_fix_signed_request_permission_assignments.sql

# 3. Verify the fix worked
mysql -u user -p database_name << EOF
-- Should show 8 permissions with assignments
SELECT p.name, COUNT(rp.role_id) AS assigned_to_roles
FROM permissions p
LEFT JOIN role_permissions rp ON p.id = rp.permission_id
WHERE p.name IN (
  'print_reimbursement_approval_form',
  'upload_signed_reimbursement_document',
  'print_petty_cash_approval_form',
  'upload_signed_petty_cash_document',
  'edit_reimbursement_request_admin',
  'edit_petty_cash_request_admin',
  'view_admin_edits_log',
  'export_signed_request_documents'
)
GROUP BY p.id, p.name;
EOF
```

### Verification Checklist

- [ ] No SQL errors in deployment
- [ ] Permissions table shows 8 new permissions
- [ ] role_permissions table shows assignments (40+ rows)
- [ ] page_permissions table shows 4 new entries
- [ ] Test as Finance Officer: can access `/reimbursement/print_for_signing.php`
- [ ] Test as HOD: can access `/petty_cash/print_for_signing.php`
- [ ] Test as Viewer: CANNOT access print pages
- [ ] Application logs show no permission errors
- [ ] Menu shows correct pages for each role

---

## Rollback Procedure (If Needed)

```bash
# Restore from backup
mysql -u user -p database_name < backup_2026_08_19.sql

# Verify
mysql -u user -p database_name << EOF
SELECT 'ROLLBACK COMPLETE' 
FROM role_permissions rp 
WHERE rp.permission_id = (SELECT id FROM permissions WHERE name = 'print_reimbursement_approval_form')
HAVING COUNT(*) = 0;
EOF
```

---

## Impact Summary

### Users Now Able to Access Pages

- **Finance Officers**: `/reimbursement/print_for_signing.php`, `/petty_cash/print_for_signing.php`
- **HOD**: Same as Finance Officers + upload pages
- **DGC**: Same as Finance Officers + upload pages
- **Director HRM&A**: Same as Finance Officers + upload pages
- **Admin/SuperAdmin**: No change (already had access)

### Pages Now Protected

- `/reimbursement/print_for_signing.php` - Requires `print_reimbursement_approval_form`
- `/reimbursement/print_for_approval.php` - Requires `view_requests`
- `/petty_cash/print_for_signing.php` - Requires `print_petty_cash_approval_form`
- `/petty_cash/print_for_approval.php` - Requires `view_requests`

### Data Impact

- No data loss
- No data modification
- All existing signed documents preserved
- Change logged in audit_log table

---

## Files Provided

1. **Modified**
   - `migrations/2026_08_19_signed_request_management_extension.sql`
     - Removed problematic UNIQUE KEY constraint
     - Still creates table and base permissions

2. **Created**
   - `migrations/2026_08_19_fix_signed_request_constraint.sql`
     - Adds generated column + UNIQUE index + triggers for constraint enforcement
   - `migrations/2026_08_19_fix_signed_request_permission_assignments.sql`
     - Assigns 8 permissions to roles
     - Adds 4 page_permissions entries
   - `RBAC_PERMISSION_BUG_FIX_COMPLETE.md`
     - Complete technical documentation
     - Test cases
     - Verification queries
     - Deployment procedures

---

## Prevention for Future Migrations

When creating new permissions:

1. **Always include role assignments** in the same migration:
   ```sql
   INSERT IGNORE INTO permissions (name, description) VALUES (...);
   INSERT IGNORE INTO role_permissions (role_id, permission_id) 
     SELECT role_id, permission_id FROM ...;
   ```

2. **Add page_permissions entries** for consistency:
   ```sql
   INSERT IGNORE INTO page_permissions (page_path, page_title, permission_name, module)
     VALUES (...);
   ```

3. **Include verification queries** in migration comments

4. **Add to code review checklist**: "Every new permission must have at least one role assignment"

---

## Questions?

See `RBAC_PERMISSION_BUG_FIX_COMPLETE.md` for:
- Detailed root cause analysis
- Complete RBAC system architecture
- All verification SQL queries
- Full test coverage examples
- Comprehensive deployment guide
- Detailed rollback procedures
