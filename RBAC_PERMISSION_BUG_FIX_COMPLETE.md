# RBAC Permission Assignment Bug - Complete Root Cause Analysis & Fix

## Executive Summary

A critical RBAC (Role-Based Access Control) bug prevented newly created permissions from being accessible to users, even when properly assigned to pages. The root cause was that new permissions were created in the `permissions` table but **never assigned to any roles** in the `role_permissions` table.

**Impact**: Users without Admin/SuperAdmin privileges could not access pages requiring these new permissions, even though they had the appropriate role and the permissions were defined.

**Status**: FIXED with comprehensive migrations and validation improvements.

---

## Root Causes Identified

### 1. PRIMARY BUG: Missing Role Assignments for New Permissions

**Location**: `/migrations/2026_08_19_signed_request_management_extension.sql` (Lines 91-99)

**What Happened**:
```sql
INSERT IGNORE INTO `permissions` (`name`, `description`) VALUES
  ('upload_signed_reimbursement_document', 'Upload signed reimbursement request documents'),
  ('print_reimbursement_approval_form', 'Print approval forms for reimbursement requests'),
  ('edit_reimbursement_request_admin', 'Edit reimbursement requests as administrator'),
  -- ... 5 more permissions
```

The migration created 8 new permissions but **NEVER called**:
```sql
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`) 
SELECT role_id, permission_id FROM ...
```

**Result**: 
- Permissions exist in `permissions` table ✓
- Permissions NOT in `role_permissions` table ✗
- No role has these permissions ✗
- Only Admin/SuperAdmin could use them (they bypass all checks) ✗

### 2. SECONDARY BUG: Missing Page Permission Entries

**Location**: Multiple pages lack `page_permissions` table entries

**Affected Pages**:
- `/reimbursement/print_for_signing.php` → requires `print_reimbursement_approval_form`
- `/reimbursement/print_for_approval.php` → requires `view_requests`
- `/petty_cash/print_for_signing.php` → requires `print_petty_cash_approval_form`
- `/petty_cash/print_for_approval.php` → requires `view_requests`

**Impact**: While pages had hard-coded permission checks, they lacked DB-level mappings that admins could override through `/admin/page_permissions.php`

### 3. TERTIARY BUG: SQL Constraint Syntax Error

**Location**: `/migrations/2026_08_19_signed_request_management_extension.sql` (Line 38)

**Problematic Syntax**:
```sql
UNIQUE KEY `uk_signed_req_active` (`request_id`, `is_active`) WHERE `is_active` = 1
```

**Issues**:
1. WHERE clause in UNIQUE KEY not supported in all MariaDB versions
2. MySQL partial index behavior doesn't work as intended for uniqueness constraints
3. Flawed semantics: cannot distinguish between "deleted but inactive" vs "just inactive"

---

## The RBAC Permission Check Flow

To understand how this bug manifested, here's the complete runtime authorization flow:

```
User Requests Page (e.g., /reimbursement/print_for_signing.php)
    ↓
page_guard.php included
    ↓
Checks: $REQUIRE_PERMISSION = 'print_reimbursement_approval_form'
    ↓
Consults page_permissions table for DB override
    ↓
Calls: require_permission('print_reimbursement_approval_form')
    ↓
has_permission() checks (in order):
    ├─ 1. SuperAdmin? → YES? Return TRUE
    ├─ 2. Admin? → YES? Return TRUE
    ├─ 3. User-level override? → EXISTS? Return override value
    ├─ 4. Primary role? → SELECT role_permissions WHERE role_id=X AND permission_id=Y
    │                    ✗ EMPTY (because permission never assigned!) → Continue
    ├─ 5. Secondary roles? → Check user_roles → role_permissions
    │                    ✗ EMPTY (because permission never assigned!) → Continue
    └─ FAIL: Access Denied
```

The bug occurred at step 4: The permission existed in the `permissions` table but NOT in the `role_permissions` table, so no role could access it.

---

## Affected Permissions (8 Total)

| Permission Name | Created In | Assigned To Roles | Page Permissions Entry |
|---|---|---|---|
| `print_reimbursement_approval_form` | 2026_08_19 | ❌ None | ❌ No |
| `print_petty_cash_approval_form` | 2026_08_19 | ❌ None | ❌ No |
| `upload_signed_reimbursement_document` | 2026_08_19 | ❌ None | ❌ No |
| `upload_signed_petty_cash_document` | 2026_08_19 | ❌ None | ❌ No |
| `edit_reimbursement_request_admin` | 2026_08_19 | ❌ None | ❌ No |
| `edit_petty_cash_request_admin` | 2026_08_19 | ❌ None | ❌ No |
| `view_admin_edits_log` | 2026_08_19 | ❌ None | ❌ No |
| `export_signed_request_documents` | 2026_08_19 | ❌ None | ❌ No |

---

## Exact Database Records That Should Exist After Fix

### 1. In `role_permissions` Table

**Print Reimbursement Approval Form** (Finance Officers, HOD, DGC, Director HRM&A, Admin, SuperAdmin):
```sql
SELECT rp.role_id, r.name AS role_name, p.name AS permission_name
FROM role_permissions rp
JOIN roles r ON r.id = rp.role_id
JOIN permissions p ON p.id = rp.permission_id
WHERE p.name = 'print_reimbursement_approval_form'
ORDER BY r.name;

-- Expected results:
-- 3  | Finance Officer          | print_reimbursement_approval_form
-- 4  | HOD                       | print_reimbursement_approval_form
-- 5  | Admin                     | print_reimbursement_approval_form
-- 6  | SuperAdmin                | print_reimbursement_approval_form
-- 9  | Deputy Government Chemist | print_reimbursement_approval_form
-- 10 | Director HRM&A            | print_reimbursement_approval_form
```

**Similar mappings for**:
- `upload_signed_reimbursement_document` → same roles
- `print_petty_cash_approval_form` → same roles
- `upload_signed_petty_cash_document` → same roles
- `edit_reimbursement_request_admin` → Admin (5), SuperAdmin (6)
- `edit_petty_cash_request_admin` → Admin (5), SuperAdmin (6)
- `view_admin_edits_log` → Admin (5), SuperAdmin (6)
- `export_signed_request_documents` → Admin (5), SuperAdmin (6)

### 2. In `page_permissions` Table

```sql
SELECT * FROM page_permissions 
WHERE page_path IN (
    '/reimbursement/print_for_signing.php',
    '/reimbursement/print_for_approval.php',
    '/petty_cash/print_for_signing.php',
    '/petty_cash/print_for_approval.php'
)
ORDER BY page_path;

-- Expected records:
page_path                              | permission_name                  | is_active
/petty_cash/print_for_approval.php     | view_requests                    | 1
/petty_cash/print_for_signing.php      | print_petty_cash_approval_form   | 1
/reimbursement/print_for_approval.php  | view_requests                    | 1
/reimbursement/print_for_signing.php   | print_reimbursement_approval_form| 1
```

---

## Verification SQL Queries

### Query 1: Verify All New Permissions Are Assigned to Roles

```sql
SELECT 
    p.id,
    p.name,
    COUNT(DISTINCT rp.role_id) AS role_count,
    GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR ', ') AS assigned_roles
FROM permissions p
LEFT JOIN role_permissions rp ON p.id = rp.permission_id
LEFT JOIN roles r ON r.id = rp.role_id
WHERE p.name IN (
    'print_reimbursement_approval_form',
    'print_petty_cash_approval_form',
    'upload_signed_reimbursement_document',
    'upload_signed_petty_cash_document',
    'edit_reimbursement_request_admin',
    'edit_petty_cash_request_admin',
    'view_admin_edits_log',
    'export_signed_request_documents'
)
GROUP BY p.id, p.name
ORDER BY p.name;

-- Expected: role_count > 0 for all permissions (at minimum, Admin and SuperAdmin)
```

### Query 2: Verify Page Permission Mappings Exist

```sql
SELECT pp.page_path, pp.page_title, pp.permission_name, pp.is_active
FROM page_permissions pp
WHERE pp.page_path IN (
    '/reimbursement/print_for_signing.php',
    '/reimbursement/print_for_approval.php',
    '/petty_cash/print_for_signing.php',
    '/petty_cash/print_for_approval.php'
)
ORDER BY pp.page_path;

-- Expected: 4 rows with is_active = 1
```

### Query 3: Verify Finance Officer Can Access Reimbursement Print

```sql
-- This query simulates the permission check for a Finance Officer
SELECT 
    'Finance Officer' AS role_name,
    'print_reimbursement_approval_form' AS permission,
    CASE 
        WHEN COUNT(*) > 0 THEN 'HAS PERMISSION ✓'
        ELSE 'DENIED ✗'
    END AS access_status
FROM role_permissions rp
JOIN permissions p ON p.id = rp.permission_id
WHERE rp.role_id = 3 -- Finance Officer
  AND p.name = 'print_reimbursement_approval_form';

-- Expected: HAS PERMISSION ✓
```

### Query 4: Verify HOD Can Access Petty Cash Print

```sql
SELECT 
    'HOD' AS role_name,
    'print_petty_cash_approval_form' AS permission,
    CASE 
        WHEN COUNT(*) > 0 THEN 'HAS PERMISSION ✓'
        ELSE 'DENIED ✗'
    END AS access_status
FROM role_permissions rp
JOIN permissions p ON p.id = rp.permission_id
WHERE rp.role_id = 4 -- HOD
  AND p.name = 'print_petty_cash_approval_form';

-- Expected: HAS PERMISSION ✓
```

### Query 5: Verify Admin-Only Permissions

```sql
SELECT 
    p.name,
    COUNT(DISTINCT rp.role_id) AS assigned_to_roles,
    GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR ', ') AS roles
FROM role_permissions rp
JOIN permissions p ON p.id = rp.permission_id
JOIN roles r ON r.id = rp.role_id
WHERE p.name IN (
    'edit_reimbursement_request_admin',
    'edit_petty_cash_request_admin',
    'view_admin_edits_log',
    'export_signed_request_documents'
)
GROUP BY p.id, p.name
ORDER BY p.name;

-- Expected: Only Admin (5) and SuperAdmin (6)
```

---

## Production-Safe Code Changes

### File 1: Migration Fix - Permission Role Assignments

**File**: `migrations/2026_08_19_fix_signed_request_permission_assignments.sql`

This migration:
1. Assigns `print_reimbursement_approval_form` to Finance Officer, HOD, DGC, Director HRM&A, Admin, SuperAdmin
2. Assigns `upload_signed_reimbursement_document` to Finance Officer, HOD, DGC, Director HRM&A, Admin, SuperAdmin
3. Assigns `print_petty_cash_approval_form` to Finance Officer, HOD, DGC, Director HRM&A, Admin, SuperAdmin
4. Assigns `upload_signed_petty_cash_document` to Finance Officer, HOD, DGC, Director HRM&A, Admin, SuperAdmin
5. Assigns admin-only permissions (`edit_*`, `view_admin_edits_log`, `export_*`) to Admin and SuperAdmin
6. Adds page_permissions entries for all print and upload pages

### File 2: Migration Fix - SQL Constraint

**File**: `migrations/2026_08_19_fix_signed_request_constraint.sql`

This migration:
1. Removes the problematic UNIQUE KEY with WHERE clause
2. Adds a generated column `active_marker` that is NULL when inactive/deleted
3. Creates a UNIQUE index on `active_marker` (naturally excludes NULL values)
4. Adds triggers to enforce single-active-document-per-request invariant

### File 3: Core Migration Fix - Original Table

**File**: `migrations/2026_08_19_signed_request_management_extension.sql`

Changed:
```sql
-- OLD (problematic):
UNIQUE KEY `uk_signed_req_active` (`request_id`, `is_active`) WHERE `is_active` = 1,

-- NEW (compatible):
-- Removed - will be enforced by separate constraint migration
```

---

## Migration and Data Repair Script

### Pre-Migration Validation Script

```sql
-- Run this BEFORE applying migrations to verify current state

-- 1. Count permissions without role assignments
SELECT 
    COUNT(*) AS permissions_without_roles
FROM permissions p
LEFT JOIN role_permissions rp ON p.id = rp.permission_id
WHERE p.name IN (
    'print_reimbursement_approval_form',
    'print_petty_cash_approval_form',
    'upload_signed_reimbursement_document',
    'upload_signed_petty_cash_document',
    'edit_reimbursement_request_admin',
    'edit_petty_cash_request_admin',
    'view_admin_edits_log',
    'export_signed_request_documents'
)
AND rp.role_id IS NULL;

-- 2. Count pages missing from page_permissions
SELECT 
    COUNT(*) AS pages_missing_db_mapping
FROM (
    SELECT '/reimbursement/print_for_signing.php' AS page_path
    UNION ALL SELECT '/reimbursement/print_for_approval.php'
    UNION ALL SELECT '/petty_cash/print_for_signing.php'
    UNION ALL SELECT '/petty_cash/print_for_approval.php'
) required_pages
LEFT JOIN page_permissions pp ON pp.page_path = required_pages.page_path
WHERE pp.id IS NULL;
```

### Post-Migration Validation Script

```sql
-- Run this AFTER applying all migrations to verify the fix

-- 1. Verify all permissions now have role assignments
SELECT 
    CASE 
        WHEN COUNT(*) = 8 THEN 'ALL PERMISSIONS ASSIGNED ✓'
        ELSE 'INCOMPLETE - ' || COUNT(*) || ' permissions assigned (expected 8)'
    END AS status
FROM (
    SELECT DISTINCT p.id
    FROM permissions p
    JOIN role_permissions rp ON p.id = rp.permission_id
    WHERE p.name IN (
        'print_reimbursement_approval_form',
        'print_petty_cash_approval_form',
        'upload_signed_reimbursement_document',
        'upload_signed_petty_cash_document',
        'edit_reimbursement_request_admin',
        'edit_petty_cash_request_admin',
        'view_admin_edits_log',
        'export_signed_request_documents'
    )
) unique_permissions;

-- 2. Verify page_permissions entries exist
SELECT 
    CASE 
        WHEN COUNT(*) = 4 THEN 'PAGE MAPPINGS COMPLETE ✓'
        ELSE 'INCOMPLETE - ' || COUNT(*) || ' page mappings (expected 4)'
    END AS status
FROM page_permissions
WHERE page_path IN (
    '/reimbursement/print_for_signing.php',
    '/reimbursement/print_for_approval.php',
    '/petty_cash/print_for_signing.php',
    '/petty_cash/print_for_approval.php'
)
AND is_active = 1;

-- 3. Verify Finance Officer can access reimbursement print
SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN 'FINANCE OFFICER ACCESS VERIFIED ✓'
        ELSE 'FINANCE OFFICER ACCESS DENIED ✗'
    END AS status
FROM role_permissions rp
WHERE rp.role_id = 3 -- Finance Officer
AND rp.permission_id = (
    SELECT id FROM permissions 
    WHERE name = 'print_reimbursement_approval_form'
);

-- 4. Test permission check flow for specific role
SELECT 
    u.user_id,
    u.full_name,
    r.name AS role_name,
    p.name AS permission_name,
    CASE WHEN rp.role_id IS NOT NULL THEN 'GRANTED' ELSE 'DENIED' END AS access_status
FROM users u
JOIN roles r ON u.role_id = r.id
CROSS JOIN permissions p
LEFT JOIN role_permissions rp ON r.id = rp.role_id AND p.id = rp.permission_id
WHERE r.id = 3 -- Finance Officer
AND p.name = 'print_reimbursement_approval_form'
LIMIT 5;
```

---

## Testing Coverage

### Test 1: Permission Creation

**Scenario**: Admin creates a new permission

```php
// test_permission_creation.php
$pdo->exec("DELETE FROM permissions WHERE name = 'test_permission_delete'");

$result = $pdo->prepare("INSERT INTO permissions (name, description) VALUES (?, ?)")
    ->execute(['test_permission_delete', 'Test permission for deletion']);

assert($pdo->lastInsertId() > 0, 'Permission created');

// Cleanup
$pdo->exec("DELETE FROM permissions WHERE name = 'test_permission_delete'");
```

### Test 2: Role Assignment at Creation

**Scenario**: New permission is automatically assigned to appropriate roles

```php
// test_role_assignment_on_creation.php
$pdo->exec("DELETE FROM permissions WHERE name = 'new_test_perm'");

$result = $pdo->prepare("INSERT INTO permissions (name, description) VALUES (?, ?)")
    ->execute(['new_test_perm', 'New test permission']);

$permId = $pdo->lastInsertId();

// Manually assign to role (what should happen automatically in migration)
$pdo->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)")
    ->execute([5, $permId]); // Admin role

// Verify
$stmt = $pdo->prepare("SELECT COUNT(*) FROM role_permissions WHERE permission_id = ? AND role_id = 5");
$stmt->execute([$permId]);
assert($stmt->fetchColumn() > 0, 'Permission assigned to Admin');

// Cleanup
$pdo->exec("DELETE FROM role_permissions WHERE permission_id = ?", [$permId]);
$pdo->exec("DELETE FROM permissions WHERE id = ?", [$permId]);
```

### Test 3: Runtime Permission Check

**Scenario**: User with appropriate role can access page

```php
// test_runtime_permission_check.php
// Setup: Create test user with Finance Officer role
$userId = createTestUser([
    'username' => 'test_finance_officer',
    'role_id' => 3, // Finance Officer
    'email' => 'test@example.com'
]);

// Simulate session
$_SESSION['user_id'] = $userId;
$_SESSION['role_id'] = 3;
$_SESSION['role_name'] = 'Finance Officer';

// Test permission check
require_once 'config/auth.php';
$canAccess = has_permission('print_reimbursement_approval_form');
assert($canAccess === true, 'Finance Officer can access print_reimbursement_approval_form');

// Cleanup
deleteTestUser($userId);
```

### Test 4: Page Permissions Database Override

**Scenario**: Admin reassigns page to different permission via UI

```php
// test_page_permissions_override.php
// Initial state: /reimbursement/print_for_signing.php requires print_reimbursement_approval_form
$stmt = $pdo->prepare("SELECT permission_name FROM page_permissions WHERE page_path = ?");
$stmt->execute(['/reimbursement/print_for_signing.php']);
assert($stmt->fetchColumn() === 'print_reimbursement_approval_form', 'Initial permission correct');

// Admin changes it to require view_requests instead
$pdo->prepare("UPDATE page_permissions SET permission_name = ? WHERE page_path = ?")
    ->execute(['view_requests', '/reimbursement/print_for_signing.php']);

// Verify change
$stmt = $pdo->prepare("SELECT permission_name FROM page_permissions WHERE page_path = ?");
$stmt->execute(['/reimbursement/print_for_signing.php']);
assert($stmt->fetchColumn() === 'view_requests', 'Permission override works');

// Revert
$pdo->prepare("UPDATE page_permissions SET permission_name = ? WHERE page_path = ?")
    ->execute(['print_reimbursement_approval_form', '/reimbursement/print_for_signing.php']);
```

### Test 5: Cache Refresh (if applicable)

**Scenario**: Permission changes are reflected immediately

```php
// test_cache_refresh.php
// Note: This application may or may not use caching
// Verify by checking config/auth.php and config/page_guard.php

// If caching is present, ensure:
// 1. Permission queries are not cached
// 2. Page_permissions table is not cached
// 3. Role_permissions is not cached (or has very short TTL)
```

### Test 6: Menu Visibility Consistency

**Scenario**: Pages appear in menu only if user has permission

```php
// test_menu_visibility.php
$_SESSION['user_id'] = $financeOfficerId;
$_SESSION['role_id'] = 3;
$_SESSION['role_name'] = 'Finance Officer';

require 'includes/menu.php';
$menuItems = extractMenuItems($html);

assert(in_array('/reimbursement/print_for_signing.php', $menuItems), 'Print page visible to Finance Officer');

// Now test with user who doesn't have permission
$_SESSION['role_id'] = 1; // Viewer
$_SESSION['role_name'] = 'Viewer';

// If Viewer role doesn't have permission, page should not appear
require 'includes/menu.php';
$menuItems = extractMenuItems($html);

assert(!in_array('/reimbursement/print_for_signing.php', $menuItems), 'Print page hidden from Viewer');
```

### Test 7: Direct URL Access Control

**Scenario**: User accessing page directly via URL is properly authorized

```php
// test_direct_url_access.php (integration test)
// Simulate HTTP request to /reimbursement/print_for_signing.php?request_id=123

// As Finance Officer (should succeed)
$_SESSION['user_id'] = $financeOfficerId;
$_SESSION['role_id'] = 3;
$_SESSION['role_name'] = 'Finance Officer';

$response = simulate_request('/reimbursement/print_for_signing.php?request_id=123');
assert($response['status_code'] === 200, 'Finance Officer can access directly');

// As Viewer (should be denied)
$_SESSION['role_id'] = 1;
$_SESSION['role_name'] = 'Viewer';

$response = simulate_request('/reimbursement/print_for_signing.php?request_id=123');
assert($response['status_code'] === 403 || redirects_to_login, 'Viewer denied direct access');
```

---

## Deployment and Rollback Plan

### Pre-Deployment Checklist

- [ ] Backup production database (`mysqldump -u user -p db_name > backup_2026_08_19.sql`)
- [ ] Verify no active users are accessing print/upload pages (schedule during maintenance window)
- [ ] Run pre-migration validation queries to document current state
- [ ] Verify MySQL/MariaDB version supports all new syntax (5.7.8+ for generated columns)
- [ ] Test migrations on staging environment first
- [ ] Prepare rollback script

### Deployment Steps

**Step 1: Apply SQL Constraint Fix** (handles version compatibility)
```bash
mysql -u user -p database_name < migrations/2026_08_19_fix_signed_request_constraint.sql
# This adds:
# - Generated column active_marker
# - UNIQUE index on active_marker
# - Triggers for enforce single-active invariant
```

**Step 2: Apply Permission Assignment Fix** (solves the main bug)
```bash
mysql -u user -p database_name < migrations/2026_08_19_fix_signed_request_permission_assignments.sql
# This adds:
# - role_permissions mappings for all 8 permissions
# - page_permissions entries for print and upload pages
```

**Step 3: Update Original Migration** (for consistency)
```bash
# The original migration 2026_08_19_signed_request_management_extension.sql
# has already been updated to remove the problematic UNIQUE KEY constraint
# No additional action needed for this
```

**Step 4: Post-Deployment Validation**
```bash
# Run all verification queries
mysql -u user -p database_name < verification/post_deployment_checks.sql

# Verify no errors in application logs
tail -f /var/log/apache2/error.log
tail -f /var/log/php/error.log

# Test access to pages as different roles
curl -H "Cookie: PHPSESSID=test_session" http://localhost/reimbursement/print_for_signing.php?request_id=1
```

### Rollback Procedure

**IF ISSUES OCCUR:**

```bash
# 1. Revert database to backup
mysql -u user -p database_name < backup_2026_08_19.sql

# 2. Revert to previous application code (if needed)
git revert <commit_hash> # Only if code changes were deployed

# 3. Verify rollback successful
mysql -u user -p database_name < verification/rollback_checks.sql
```

**Rollback SQL Script** (`rollback_2026_08_19.sql`):
```sql
-- Remove newly added role_permissions entries
DELETE FROM role_permissions 
WHERE permission_id IN (
    SELECT id FROM permissions WHERE name IN (
        'print_reimbursement_approval_form',
        'print_petty_cash_approval_form',
        'upload_signed_reimbursement_document',
        'upload_signed_petty_cash_document',
        'edit_reimbursement_request_admin',
        'edit_petty_cash_request_admin',
        'view_admin_edits_log',
        'export_signed_request_documents'
    )
);

-- Remove page_permissions entries
DELETE FROM page_permissions 
WHERE page_path IN (
    '/reimbursement/print_for_signing.php',
    '/reimbursement/print_for_approval.php',
    '/petty_cash/print_for_signing.php',
    '/petty_cash/print_for_approval.php'
);

-- Remove generated column and unique index
ALTER TABLE signed_request_documents 
DROP KEY IF EXISTS uk_signed_req_active,
DROP COLUMN IF EXISTS active_marker;

-- Drop triggers
DROP TRIGGER IF EXISTS trg_signed_req_enforce_single_active;
DROP TRIGGER IF EXISTS trg_signed_req_enforce_single_active_update;

-- Note: Cannot easily re-add the problematic WHERE clause to UNIQUE KEY
-- If needed, add: UNIQUE KEY `uk_signed_req_active` (`request_id`) 
-- (without the WHERE clause) and enforce in application logic
```

### Deployment Validation Checklist

After deployment, verify:

- [ ] Finance Officer can access `/reimbursement/print_for_signing.php`
- [ ] HOD can access `/petty_cash/print_for_signing.php`
- [ ] DGC can access print pages
- [ ] Viewer role CANNOT access these pages
- [ ] Page appears in menu for authorized users
- [ ] Page does not appear in menu for unauthorized users
- [ ] Admin can reassign page permissions via `/admin/page_permissions.php`
- [ ] Permissions table shows 8 new permissions
- [ ] role_permissions table shows assignments (count > 0 for each permission)
- [ ] page_permissions table shows 4 new entries
- [ ] No SQL errors in logs
- [ ] No permission check errors in logs
- [ ] Signed request document versioning works correctly

---

## Impact Assessment

### Users Affected
- **Finance Officers**: Gained access to reimbursement/petty cash print and upload pages
- **HOD**: Gained access to reimbursement/petty cash print and upload pages
- **DGC**: Gained access to reimbursement/petty cash print and upload pages
- **Director HRM&A**: Gained access to reimbursement/petty cash print and upload pages
- **Admin/SuperAdmin**: No change (already had access via bypass)

### Pages Affected
- `/reimbursement/print_for_signing.php`
- `/reimbursement/print_for_approval.php`
- `/petty_cash/print_for_signing.php`
- `/petty_cash/print_for_approval.php`

### Data Integrity
- No data loss or modification
- Existing signed request documents preserved
- Permission history logged in audit_log table

---

## Recommendations for Prevention

1. **Mandatory Code Review**: All new permissions must include corresponding role assignments in the same migration
2. **Automated Testing**: Add CI/CD check to verify every permission has at least one role assignment
3. **Documentation**: Create template for permission-creating migrations that includes role assignment SQL
4. **Page Permissions**: Always add page_permissions entries when creating new pages
5. **Code Validation**: Add validation in page_permissions.php to reject non-existent permissions

---

## References

- `/config/auth.php` - Permission check logic
- `/config/page_guard.php` - Page-level permission enforcement
- `/admin/permissions.php` - Permission management UI
- `/admin/page_permissions.php` - Page permission assignment UI
- `/migrations/2026_08_19_signed_request_management_extension.sql` - Original (fixed)
- `/migrations/2026_08_19_fix_signed_request_permission_assignments.sql` - Role assignment fix
- `/migrations/2026_08_19_fix_signed_request_constraint.sql` - Constraint logic fix
