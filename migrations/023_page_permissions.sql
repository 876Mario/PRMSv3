-- ============================================================================
-- Migration 023: Page Permissions Management
-- ============================================================================
-- Purpose:
--   1. Create page_permissions table so admins can assign/override which
--      permission is required to access each page in the system.
--   2. Seed the table with all known pages and their default permissions.
--   3. Add view_pmo_dashboard permission for the Property Management Officer
--      dashboard and assign it to role 13.
--
-- How it works:
--   - page_guard.php consults this table first; if a row exists for the
--     current page path the DB value takes precedence over the PHP constant.
--   - Admins can reassign permissions via /admin/page_permissions.php.
--
-- Date: 2026-05-13
-- ============================================================================

-- ═══════════════════════════════════════════════════════════
-- STEP 1: Create page_permissions table
-- ═══════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `page_permissions` (
    `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `page_path`       VARCHAR(255)    NOT NULL COMMENT 'Relative URL, e.g. /procurement/list.php',
    `page_title`      VARCHAR(255)    NOT NULL COMMENT 'Human-readable page name',
    `permission_name` VARCHAR(100)    NOT NULL COMMENT 'permission.name required to access this page',
    `module`          VARCHAR(80)     NOT NULL DEFAULT 'general' COMMENT 'Grouping label for the admin UI',
    `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_page_path` (`page_path`),
    KEY `idx_permission_name` (`permission_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════
-- STEP 2: Add view_pmo_dashboard permission
-- ═══════════════════════════════════════════════════════════

INSERT IGNORE INTO `permissions` (`name`, `description`) VALUES
('view_pmo_dashboard', 'Access the Property Management Officer dashboard');

-- Assign to role 13 (Property Management Officer) and role 5 (Admin) / 6 (SuperAdmin)
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `roles` r
JOIN `permissions` p ON p.name = 'view_pmo_dashboard'
WHERE r.id IN (5, 6, 13);

-- ═══════════════════════════════════════════════════════════
-- STEP 3: Seed all known pages with their default permissions
-- ═══════════════════════════════════════════════════════════

INSERT IGNORE INTO `page_permissions` (`page_path`, `page_title`, `permission_name`, `module`) VALUES

-- ── Administration ────────────────────────────────────────────────────
('/admin/settings.php',                  'System Settings',              'manage_system_settings', 'Administration'),
('/admin/page_permissions.php',          'Page Permissions',             'manage_users',            'Administration'),
('/users/list.php',                      'Users List',                   'manage_users',            'Administration'),
('/users/add.php',                       'Add User',                     'manage_users',            'Administration'),
('/users/view.php',                      'View User',                    'manage_users',            'Administration'),
('/users/permissions.php',              'User Permissions',             'manage_users',            'Administration'),
('/users/update_role.php',              'Update User Role',             'manage_users',            'Administration'),
('/users/toggle_status.php',            'Toggle User Status',           'manage_users',            'Administration'),
('/users/delete.php',                   'Delete User',                  'manage_users',            'Administration'),
('/users/reset_password.php',           'Reset User Password',          'manage_users',            'Administration'),
('/users/unlock.php',                   'Unlock User Account',          'manage_users',            'Administration'),
('/users/acting_roles.php',             'Acting Roles',                 'manage_users',            'Administration'),

-- ── Dashboards ───────────────────────────────────────────────────────
('/dashboard/admin.php',                'Admin Dashboard',              'manage_users',            'Dashboards'),
('/dashboard/procurement.php',          'Procurement Dashboard',        'view_procurement_dashboard','Dashboards'),
('/dashboard/finance.php',              'Finance Dashboard',            'view_finance_dashboard',  'Dashboards'),
('/dashboard/hod.php',                  'HOD Dashboard',                'view_requests',           'Dashboards'),
('/dashboard/gc.php',                   'Deputy Govt Chemist Dashboard','view_requests',           'Dashboards'),
('/dashboard/committee.php',            'Procurement Committee',        'view_requests',           'Dashboards'),
('/dashboard/evaluation.php',           'Evaluation Dashboard',         'view_requests',           'Dashboards'),
('/dashboard/director_hrma.php',        'Director HRM&A Dashboard',     'approve_as_director_hrma','Dashboards'),
('/dashboard/director_procurement.php', 'Director Procurement Dashboard','approve_as_director_hrma','Dashboards'),
('/dashboard/property_management_officer.php','PMO Dashboard',         'view_pmo_dashboard',      'Dashboards'),
('/dashboard/requestor.php',            'Requestor Dashboard',          'view_own_requests',       'Dashboards'),
('/dashboard/viewer.php',               'Viewer Dashboard',             'view_audit_dashboard',    'Dashboards'),
('/dashboard/management.php',           'Management Dashboard',         'management_dashboard',    'Dashboards'),
('/dashboard/metrics.php',              'Finance Metrics',              'view_finance_dashboard',  'Dashboards'),
('/dashboard/monthly.php',              'Monthly Dashboard',            'monthly_metrics',         'Dashboards'),
('/dashboard/approval_analytics.php',   'Approval Analytics',           'view_approval_analytics', 'Dashboards'),
('/dashboard/approval_queue.php',       'Approval Queue',               'view_requests',           'Dashboards'),
('/dashboard/compliance.php',           'Compliance Dashboard',         'view_compliance',         'Dashboards'),

-- ── Procurement ───────────────────────────────────────────────────────
('/procurement/list.php',               'All Requests',                 'view_requests',           'Procurement'),
('/procurement/add.php',                'New Request',                  'create_request',          'Procurement'),
('/procurement/edit.php',               'Edit Request',                 'create_request',          'Procurement'),
('/procurement/view.php',               'View Request',                 'view_requests',           'Procurement'),
('/procurement/submit.php',             'Submit Request',               'submit_request',          'Procurement'),
('/procurement/resubmit.php',           'Resubmit Request',             'submit_request',          'Procurement'),
('/procurement/approve.php',            'Approve Request',              'approve_request',         'Procurement'),
('/procurement/approve_finance.php',    'Finance Approval',             'approve_request',         'Procurement'),
('/procurement/approve_hod.php',        'HOD Approval',                 'approve_request',         'Procurement'),
('/procurement/gc_approve.php',         'DGC Approval',                 'approve_request',         'Procurement'),
('/procurement/decline.php',            'Decline Request',              'approve_request',         'Procurement'),
('/procurement/recommend.php',          'Recommend Request',            'approve_request',         'Procurement'),
('/procurement/my_requests.php',        'My Requests',                  'view_own_requests',       'Procurement'),
('/procurement/start_procurement.php',  'Start Procurement',            'view_requests',           'Procurement'),
('/procurement/upload_document.php',    'Upload Document',              'view_requests',           'Procurement'),
('/procurement/upload_signed_request.php','Upload Signed Request',     'view_requests',           'Procurement'),
('/procurement/print_for_signing.php',  'Print for Signing',            'view_requests',           'Procurement'),
('/procurement/verify_funds.php',       'Verify Funds',                 'verify_funds',            'Procurement'),

-- ── Commitments ───────────────────────────────────────────────────────
('/commitments/list.php',               'Commitments List',             'view_commitments',        'Commitments'),
('/commitments/view.php',               'View Commitment',              'view_commitments',        'Commitments'),
('/commitments/add.php',                'Add Commitment',               'create_commitment',       'Commitments'),
('/commitments/add_supplementary.php',  'Add Supplementary Commitment', 'create_commitment',       'Commitments'),
('/commitments/approve.php',            'Approve Commitment',           'approve_commitment',      'Commitments'),
('/commitments/upload.php',             'Upload Commitment',            'approve_commitment',      'Commitments'),

-- ── Purchase Orders ───────────────────────────────────────────────────
('/po/list.php',                        'Purchase Orders List',         'view_purchase_orders',    'Purchase Orders'),
('/po/view.php',                        'View Purchase Order',          'view_purchase_orders',    'Purchase Orders'),
('/po/add.php',                         'Create Purchase Order',        'create_purchase_order',   'Purchase Orders'),
('/po/edit.php',                        'Edit Purchase Order',          'edit_purchase_order',     'Purchase Orders'),
('/po/approve.php',                     'Approve Purchase Order',       'approve_purchase_order',  'Purchase Orders'),
('/po/upload.php',                      'Upload PO Document',           'upload_purchase_order',   'Purchase Orders'),
('/po/excess_approve.php',              'Approve PO Excess',            'approve_po_excess',       'Purchase Orders'),
('/po/variation_queue.php',             'PO Variations Queue',          'approve_po_adjustment',   'Purchase Orders'),
('/po/variation_create.php',            'Create PO Variation',          'request_po_adjustment',   'Purchase Orders'),
('/po/variation_approve.php',           'Approve PO Variation',         'approve_po_adjustment',   'Purchase Orders'),
('/po/add_adjustment.php',              'Add PO Adjustment',            'request_po_adjustment',   'Purchase Orders'),
('/po/receive_to_inventory.php',        'Receive PO to Inventory',      'receive_goods',           'Purchase Orders'),

-- ── RFQ ───────────────────────────────────────────────────────────────
('/rfq/list.php',                       'RFQ List',                     'view_requests',           'RFQ'),
('/rfq/view.php',                       'View RFQ',                     'view_requests',           'RFQ'),
('/rfq/create.php',                     'Create RFQ',                   'create_rfq',              'RFQ'),
('/rfq/upload_rfq_letter.php',          'Upload RFQ Letter',            'create_rfq',              'RFQ'),
('/rfq/generate_rtf.php',               'Generate RFQ Document',        'create_rfq',              'RFQ'),
('/rfq/add_committee.php',              'Add Committee Member',         'manage_rfq_committee',    'RFQ'),
('/rfq/remove_committee.php',           'Remove Committee Member',      'manage_rfq_committee',    'RFQ'),
('/rfq/add_vendor.php',                 'Add Vendor to RFQ',            'add_rfq_vendor',          'RFQ'),
('/rfq/remove_vendor.php',              'Remove Vendor from RFQ',       'add_rfq_vendor',          'RFQ'),
('/rfq/send_rfq_emails.php',            'Send RFQ Emails',              'add_rfq_vendor',          'RFQ'),
('/rfq/upload_quote.php',               'Upload Vendor Quote',          'upload_rfq_quote',        'RFQ'),
('/rfq/start_evaluation.php',           'Start Evaluation',             'start_rfq_evaluation',    'RFQ'),
('/rfq/advance_evaluation.php',         'Advance Evaluation Stage',     'start_rfq_evaluation',    'RFQ'),
('/rfq/vote.php',                       'Vote on RFQ',                  'vote_rfq',                'RFQ'),
('/rfq/review_quote.php',               'Review Quote',                 'view_rfq_evaluations',    'RFQ'),
('/rfq/select_quote.php',               'Select Winning Quote',         'view_rfq_evaluations',    'RFQ'),
('/rfq/upload_report.php',              'Upload Evaluation Report',     'upload_rfq_report',       'RFQ'),
('/rfq/view_report.php',                'View Evaluation Report',       'view_rfq_evaluations',    'RFQ'),
('/rfq/generate_evaluation_summary.php','Generate Evaluation Summary',  'view_rfq_evaluations',    'RFQ'),
('/rfq/award.php',                      'Award RFQ',                    'award_vendor',            'RFQ'),
('/rfq/generate_loa.php',               'Generate LOA',                 'award_vendor',            'RFQ'),
('/rfq/accept_award.php',               'Accept/Decline Award',         'confirm_vendor_award',    'RFQ'),
('/rfq/gc_approve.php',                 'DGC Approve RFQ',              'approve_request',         'RFQ'),

-- ── Invoices & Payments ───────────────────────────────────────────────
('/invoice/list.php',                   'Invoices List',                'view_invoices',           'Finance'),
('/invoice/view.php',                   'View Invoice',                 'view_invoices',           'Finance'),
('/invoice/add.php',                    'Create Invoice',               'create_invoice',          'Finance'),
('/payment/list.php',                   'Payments List',                'view_payments',           'Finance'),
('/payment/add.php',                    'Record Payment',               'create_payment',          'Finance'),

-- ── Reimbursements ────────────────────────────────────────────────────
('/reimbursement/list.php',             'Reimbursements List',          'view_reimbursement_requests','Reimbursements'),
('/reimbursement/view.php',             'View Reimbursement',           'view_reimbursement_requests','Reimbursements'),
('/reimbursement/add.php',              'New Reimbursement',            'create_reimbursement_request','Reimbursements'),
('/reimbursement/edit.php',             'Edit Reimbursement',           'create_reimbursement_request','Reimbursements'),
('/reimbursement/submit.php',           'Submit Reimbursement',         'create_reimbursement_request','Reimbursements'),
('/reimbursement/approve.php',          'Approve Reimbursement',        'approve_reimbursement_request','Reimbursements'),
('/reimbursement/submit_invoice.php',   'Submit Reimbursement Invoice', 'view_own_requests',       'Reimbursements'),

-- ── Petty Cash ────────────────────────────────────────────────────────
('/petty_cash/list.php',                'Petty Cash List',              'view_petty_cash_requests','Petty Cash'),
('/petty_cash/view.php',                'View Petty Cash',              'view_petty_cash_requests','Petty Cash'),
('/petty_cash/add.php',                 'New Petty Cash Request',       'create_petty_cash_request','Petty Cash'),
('/petty_cash/edit.php',                'Edit Petty Cash',              'create_petty_cash_request','Petty Cash'),
('/petty_cash/submit.php',              'Submit Petty Cash',            'create_petty_cash_request','Petty Cash'),
('/petty_cash/approve.php',             'Approve Petty Cash',           'approve_petty_cash_request','Petty Cash'),

-- ── Vendors ───────────────────────────────────────────────────────────
('/vendors/list.php',                   'Vendors List',                 'view_vendors',            'Vendors'),
('/vendors/view.php',                   'View Vendor',                  'view_vendors',            'Vendors'),
('/vendors/add.php',                    'Add Vendor',                   'manage_vendors',          'Vendors'),
('/vendors/edit.php',                   'Edit Vendor',                  'manage_vendors',          'Vendors'),
('/vendors/delete.php',                 'Delete Vendor',                'manage_vendors',          'Vendors'),

-- ── Reports ───────────────────────────────────────────────────────────
('/reports/index.php',                  'Reports Home',                 'view_financial_reports',  'Reports'),
('/reports/procurement_by_status.php',  'Procurement by Status',        'view_financial_reports',  'Reports'),
('/reports/procurement_by_type.php',    'Procurement by Type',          'view_financial_reports',  'Reports'),
('/reports/procurement_by_branch.php',  'Procurement by Branch',        'view_financial_reports',  'Reports'),
('/reports/procurement_by_supplier.php','Procurement by Supplier',      'view_financial_reports',  'Reports'),
('/reports/po_status_report.php',       'PO Status Report',             'view_financial_reports',  'Reports'),
('/reports/period_reports.php',         'Period Reports',               'view_financial_reports',  'Reports'),
('/reports/amounts_paid_report.php',    'Amounts Paid Report',          'view_financial_reports',  'Reports'),
('/reports/outstanding_commitments_po.php','Outstanding Commitments',   'view_financial_reports',  'Reports'),
('/reports/branch_summary.php',         'Branch Summary',               'view_financial_reports',  'Reports'),
('/reports/branch_outstanding.php',     'Branch Outstanding',           'view_financial_reports',  'Reports'),
('/reports/po_adjustment_report.php',   'PO Adjustment Report',         'view_po_adjustments',     'Reports'),
('/reports/export_excel.php',           'Export Excel Report',          'view_management_dashboard','Reports'),
('/reports/print_request.php',          'Print Request',                'print_request',           'Reports'),
('/reports/print_po.php',               'Print Purchase Order',         'print_purchase_order',    'Reports'),
('/reports/print_invoice.php',          'Print Invoice',                'print_invoice',           'Reports'),
('/reports/export_page_pdf.php',        'Export Page PDF',              'view_financial_reports', 'Reports'),

-- ── Audit ─────────────────────────────────────────────────────────────
('/audit/list.php',                     'Audit Logs',                   'view_audit_logs',         'Audit'),
('/audit/view.php',                     'View Audit Entry',             'view_audit_logs',         'Audit'),
('/audit/export_csv.php',               'Export Audit CSV',             'view_audit_logs',         'Audit'),
('/audit/export_pdf.php',               'Export Audit PDF',             'view_audit_logs',         'Audit'),

-- ── Inventory ─────────────────────────────────────────────────────────
('/inventory/dashboard.php',            'Inventory Dashboard',          'view_inventory',          'Inventory'),
('/inventory/items/list.php',           'Inventory Items',              'view_inventory',          'Inventory'),
('/inventory/items/view.php',           'View Item',                    'view_inventory',          'Inventory'),
('/inventory/items/add.php',            'Add Item',                     'manage_inventory_items',  'Inventory'),
('/inventory/items/edit.php',           'Edit Item',                    'manage_inventory_items',  'Inventory'),
('/inventory/locations/list.php',       'Locations',                    'view_inventory',          'Inventory'),
('/inventory/locations/add.php',        'Add Location',                 'manage_inventory_locations','Inventory'),
('/inventory/requisitions/list.php',    'Stock Requisitions',           'view_inventory',          'Inventory'),
('/inventory/requisitions/view.php',    'View Requisition',             'view_inventory',          'Inventory'),
('/inventory/requisitions/add.php',     'Submit Stock Requisition',     'submit_stock_requisition','Inventory'),
('/inventory/receiving/list.php',       'Goods Received',               'receive_goods',           'Inventory'),
('/inventory/receiving/view.php',       'View GRN',                     'receive_goods',           'Inventory'),
('/inventory/receiving/add.php',        'Record GRN',                   'receive_goods',           'Inventory'),
('/inventory/issuing/list.php',         'Stock Issues',                 'issue_stock',             'Inventory'),
('/inventory/issuing/view.php',         'View Issue',                   'issue_stock',             'Inventory'),
('/inventory/issuing/add.php',          'Issue Stock',                  'issue_stock',             'Inventory'),
('/inventory/transfers/list.php',       'Stock Transfers',              'transfer_stock',          'Inventory'),
('/inventory/transfers/view.php',       'View Transfer',                'transfer_stock',          'Inventory'),
('/inventory/transfers/add.php',        'Create Transfer',              'transfer_stock',          'Inventory'),
('/inventory/adjustments/list.php',     'Stock Adjustments',            'adjust_stock',            'Inventory'),
('/inventory/adjustments/view.php',     'View Adjustment',              'adjust_stock',            'Inventory'),
('/inventory/adjustments/add.php',      'Create Adjustment',            'adjust_stock',            'Inventory'),
('/inventory/disposal/list.php',        'Disposal Requests',            'dispose_stock',           'Inventory'),
('/inventory/disposal/view.php',        'View Disposal',                'dispose_stock',           'Inventory'),
('/inventory/disposal/add.php',         'Create Disposal',              'dispose_stock',           'Inventory'),
('/inventory/stocktake/list.php',       'Stock Counts',                 'conduct_stock_count',     'Inventory'),
('/inventory/stocktake/view.php',       'View Stock Count',             'conduct_stock_count',     'Inventory'),
('/inventory/stocktake/add.php',        'Create Stock Count',           'conduct_stock_count',     'Inventory'),
('/inventory/quarantine/list.php',      'Quarantine Items',             'manage_quarantine',       'Inventory'),
('/inventory/quarantine/view.php',      'View Quarantine',              'manage_quarantine',       'Inventory'),
('/inventory/quarantine/add.php',       'Quarantine Item',              'manage_quarantine',       'Inventory'),
('/inventory/recall/list.php',          'Recalls',                      'manage_recalls',          'Inventory'),
('/inventory/recall/view.php',          'View Recall',                  'manage_recalls',          'Inventory'),
('/inventory/recall/add.php',           'Create Recall',                'manage_recalls',          'Inventory'),
('/inventory/returns/list.php',         'Returns to Supplier',          'manage_returns',          'Inventory'),
('/inventory/returns/view.php',         'View Return',                  'manage_returns',          'Inventory'),
('/inventory/returns/add.php',          'Create Return',                'manage_returns',          'Inventory'),
('/inventory/incidents/list.php',       'Incidents / Losses',           'manage_incidents',        'Inventory'),
('/inventory/incidents/view.php',       'View Incident',                'manage_incidents',        'Inventory'),
('/inventory/incidents/add.php',        'Report Incident',              'manage_incidents',        'Inventory'),
('/inventory/writedowns/list.php',      'Write-Downs',                  'manage_write_downs',      'Inventory'),
('/inventory/writedowns/view.php',      'View Write-Down',              'manage_write_downs',      'Inventory'),
('/inventory/writedowns/add.php',       'Create Write-Down',            'manage_write_downs',      'Inventory'),

-- ── Inventory Reports ─────────────────────────────────────────────────
('/inventory/reports/index.php',               'Inventory Reports Home',       'view_inventory_reports','Inventory Reports'),
('/inventory/reports/stock_valuation.php',      'Stock Valuation',              'view_inventory_reports','Inventory Reports'),
('/inventory/reports/reorder_report.php',       'Reorder Report',               'view_inventory_reports','Inventory Reports'),
('/inventory/reports/expiry_report.php',        'Expiry Report',                'view_inventory_reports','Inventory Reports'),
('/inventory/reports/slow_moving_stock.php',    'Slow Moving Stock',            'view_inventory_reports','Inventory Reports'),
('/inventory/reports/obsolete_stock.php',       'Obsolete Stock',               'view_inventory_reports','Inventory Reports'),
('/inventory/reports/transaction_history.php',  'Transaction History',          'view_inventory_reports','Inventory Reports'),
('/inventory/reports/goods_received_register.php','Goods Received Register',    'view_inventory_reports','Inventory Reports'),
('/inventory/reports/issue_register.php',       'Issue Register',               'view_inventory_reports','Inventory Reports'),
('/inventory/reports/transfer_register.php',    'Transfer Register',            'view_inventory_reports','Inventory Reports'),
('/inventory/reports/disposal_register.php',    'Disposal Register',            'view_inventory_reports','Inventory Reports'),
('/inventory/reports/inventory_expense.php',    'Inventory Expense',            'view_inventory_reports','Inventory Reports'),
('/inventory/reports/shrinkage_loss.php',       'Shrinkage / Loss',             'view_inventory_reports','Inventory Reports'),
('/inventory/reports/write_down_report.php',    'Write-Down Report',            'view_inventory_reports','Inventory Reports'),
('/inventory/reports/traceability_report.php',  'Asset Traceability',           'view_inventory_reports','Inventory Reports'),
('/inventory/reports/audit_exceptions.php',     'Audit Exceptions',             'view_inventory_reports','Inventory Reports'),
('/inventory/reports/user_activity.php',        'User Activity',                'view_inventory_reports','Inventory Reports'),
('/inventory/reports/supplier_performance.php', 'Supplier Performance',         'view_inventory_reports','Inventory Reports'),
('/inventory/reports/approval_turnaround.php',  'Approval Turnaround',          'view_inventory_reports','Inventory Reports'),
('/inventory/reports/emergency_stock.php',      'Emergency Stock',              'view_inventory_reports','Inventory Reports'),
('/inventory/reports/donation_register.php',    'Donation Register',            'view_inventory_reports','Inventory Reports'),

-- ── Compliance ────────────────────────────────────────────────────────
('/views/compliance-detail.php',        'Compliance Detail',            'view_compliance',         'Compliance');

-- ═══════════════════════════════════════════════════════════
-- STEP 4: Verification
-- ═══════════════════════════════════════════════════════════

SELECT COUNT(*) AS total_pages_seeded FROM `page_permissions`;

SELECT module, COUNT(*) AS pages
FROM `page_permissions`
GROUP BY module
ORDER BY module;
