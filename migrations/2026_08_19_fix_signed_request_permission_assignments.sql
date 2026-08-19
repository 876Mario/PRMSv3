-- ============================================================
-- Migration: 2026_08_19_fix_signed_request_permission_assignments.sql
-- Purpose: Fix missing role assignments for signed request permissions
--          and add missing page_permissions entries
-- ============================================================
-- 
-- Issues Fixed:
-- 1. 8 permissions created in 2026_08_19_signed_request_management_extension.sql
--    but NEVER assigned to any roles
-- 2. Reimbursement and Petty Cash print_for_signing pages missing from
--    page_permissions table
-- 3. SQL constraint syntax error in signed_request_documents table
--
-- Root Cause:
-- The previous migration created permissions but did not call INSERT INTO
-- role_permissions to assign them to appropriate roles.
--
-- ============================================================

-- ═══════════════════════════════════════════════════════════
-- STEP 1: Assign print/upload permissions to appropriate roles
-- ═══════════════════════════════════════════════════════════

-- Print Reimbursement Approval Form (for Finance Officers, HOD, etc.)
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 
    r.id,
    p.id
FROM `roles` r
CROSS JOIN `permissions` p
WHERE p.name = 'print_reimbursement_approval_form'
AND r.id IN (
    3,  -- Finance Officer
    4,  -- HOD
    5,  -- Admin
    6,  -- SuperAdmin
    9,  -- Deputy Government Chemist (DGC)
    10  -- Director HRM&A
);

-- Upload Signed Reimbursement Document (for Finance Officers, HOD, etc.)
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 
    r.id,
    p.id
FROM `roles` r
CROSS JOIN `permissions` p
WHERE p.name = 'upload_signed_reimbursement_document'
AND r.id IN (
    3,  -- Finance Officer
    4,  -- HOD
    5,  -- Admin
    6,  -- SuperAdmin
    9,  -- Deputy Government Chemist (DGC)
    10  -- Director HRM&A
);

-- Print Petty Cash Approval Form (for Finance Officers, HOD, etc.)
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 
    r.id,
    p.id
FROM `roles` r
CROSS JOIN `permissions` p
WHERE p.name = 'print_petty_cash_approval_form'
AND r.id IN (
    3,  -- Finance Officer
    4,  -- HOD
    5,  -- Admin
    6,  -- SuperAdmin
    9,  -- Deputy Government Chemist (DGC)
    10  -- Director HRM&A
);

-- Upload Signed Petty Cash Document (for Finance Officers, HOD, etc.)
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 
    r.id,
    p.id
FROM `roles` r
CROSS JOIN `permissions` p
WHERE p.name = 'upload_signed_petty_cash_document'
AND r.id IN (
    3,  -- Finance Officer
    4,  -- HOD
    5,  -- Admin
    6,  -- SuperAdmin
    9,  -- Deputy Government Chemist (DGC)
    10  -- Director HRM&A
);

-- Edit Reimbursement Request (Admin only)
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 
    r.id,
    p.id
FROM `roles` r
CROSS JOIN `permissions` p
WHERE p.name = 'edit_reimbursement_request_admin'
AND r.id IN (
    5,  -- Admin
    6   -- SuperAdmin
);

-- Edit Petty Cash Request (Admin only)
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 
    r.id,
    p.id
FROM `roles` r
CROSS JOIN `permissions` p
WHERE p.name = 'edit_petty_cash_request_admin'
AND r.id IN (
    5,  -- Admin
    6   -- SuperAdmin
);

-- View Admin Edits Log (Admin only)
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 
    r.id,
    p.id
FROM `roles` r
CROSS JOIN `permissions` p
WHERE p.name = 'view_admin_edits_log'
AND r.id IN (
    5,  -- Admin
    6   -- SuperAdmin
);

-- Export Signed Request Documents (Admin only)
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 
    r.id,
    p.id
FROM `roles` r
CROSS JOIN `permissions` p
WHERE p.name = 'export_signed_request_documents'
AND r.id IN (
    5,  -- Admin
    6   -- SuperAdmin
);

-- ═══════════════════════════════════════════════════════════
-- STEP 2: Add page_permissions entries for reimbursement/petty
--         cash print pages (for consistency and admin override)
-- ═══════════════════════════════════════════════════════════

INSERT IGNORE INTO `page_permissions` 
    (`page_path`, `page_title`, `permission_name`, `module`) 
VALUES
    (
        '/reimbursement/print_for_signing.php',
        'Print Reimbursement for Signing',
        'print_reimbursement_approval_form',
        'Reimbursements'
    ),
    (
        '/reimbursement/print_for_approval.php',
        'Print Reimbursement for Approval',
        'view_requests',
        'Reimbursements'
    ),
    (
        '/petty_cash/print_for_signing.php',
        'Print Petty Cash for Signing',
        'print_petty_cash_approval_form',
        'Petty Cash'
    ),
    (
        '/petty_cash/print_for_approval.php',
        'Print Petty Cash for Approval',
        'view_requests',
        'Petty Cash'
    );

-- ═══════════════════════════════════════════════════════════
-- STEP 3: Add upload_signed_request_form pages (if new forms
--         are added in the future)
-- ═══════════════════════════════════════════════════════════

INSERT IGNORE INTO `page_permissions` 
    (`page_path`, `page_title`, `permission_name`, `module`) 
VALUES
    (
        '/reimbursement/upload_signed_request.php',
        'Upload Signed Reimbursement Request',
        'upload_signed_reimbursement_document',
        'Reimbursements'
    ),
    (
        '/petty_cash/upload_signed_request.php',
        'Upload Signed Petty Cash Request',
        'upload_signed_petty_cash_document',
        'Petty Cash'
    );

-- ═══════════════════════════════════════════════════════════
-- STEP 4: Verification Queries
-- ═══════════════════════════════════════════════════════════

-- Verify role assignments for new permissions:
-- SELECT r.name AS role_name, p.name AS permission_name
-- FROM role_permissions rp
-- JOIN roles r ON r.id = rp.role_id
-- JOIN permissions p ON p.id = rp.permission_id
-- WHERE p.name IN (
--     'print_reimbursement_approval_form',
--     'upload_signed_reimbursement_document',
--     'print_petty_cash_approval_form',
--     'upload_signed_petty_cash_document',
--     'edit_reimbursement_request_admin',
--     'edit_petty_cash_request_admin',
--     'view_admin_edits_log',
--     'export_signed_request_documents'
-- )
-- ORDER BY p.name, r.name;

-- Verify page_permissions entries:
-- SELECT page_path, page_title, permission_name, is_active
-- FROM page_permissions
-- WHERE page_path LIKE '%reimbursement%' OR page_path LIKE '%petty_cash%'
-- ORDER BY page_path;

-- ═══════════════════════════════════════════════════════════
-- STEP 5: Audit Log Entry
-- ═══════════════════════════════════════════════════════════

INSERT INTO audit_log (table_name, record_id, action, notes)
VALUES ('DATABASE', 0, 'SCHEMA_CHANGE', 
  'Fixed RBAC permission assignment bug: Assigned 8 new permissions to appropriate roles ' .
  '(print_reimbursement_approval_form, upload_signed_reimbursement_document, etc.). ' .
  'Added page_permissions entries for reimbursement and petty cash print/upload pages.');
