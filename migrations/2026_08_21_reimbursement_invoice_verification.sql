-- =====================================================
-- PRMS DATABASE MIGRATION: Reimbursement Invoice Verification
-- Purpose: Allow Procurement Officers to view reimbursement requests
--          (required to reach the invoice verification screen) and
--          document the invoice verification workflow.
--
-- Background:
--   Procurement Officers already hold the `verify_reimbursement_goods`
--   permission (granted in migrations 013/016), but were missing the
--   `view_reimbursement_requests` permission that guards
--   /reimbursement/list.php and /reimbursement/view.php. Without it,
--   Procurement could never open a reimbursement request to verify the
--   invoice copy submitted for GC2. Finance Officers already had view
--   access but there was previously no action available on the invoice
--   verification screen for either role.
-- =====================================================

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.name = 'view_reimbursement_requests'
WHERE r.name = 'Procurement Officer';

-- Register the new invoice verification page so it can be reassigned
-- from /admin/page_permissions.php like every other page.
INSERT IGNORE INTO page_permissions (page_path, page_title, permission_name, module)
VALUES ('/reimbursement/verify_invoice.php', 'Verify Reimbursement Invoice', 'verify_reimbursement_goods', 'Reimbursements');
