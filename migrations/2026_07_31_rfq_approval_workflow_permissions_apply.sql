-- Migration: Apply RFQ Quote Approval Workflow Permissions
-- Purpose: Fix the double-approval workflow being completely inaccessible.
--
-- migrations/2026_07_31_rfq_approval_workflow_permissions.sql only documented the
-- required permissions/role assignments as *commented-out* SQL. Because it was never
-- actually executed, the `approve_rfq_spec_review`, `approve_rfq_branch_head` and
-- related permissions never existed in the `permissions` table, so no role could ever
-- pass the `$REQUIRE_PERMISSION` checks in rfq/spec_review_approve.php and
-- rfq/branch_head_approve.php. This migration creates those permissions for real and
-- assigns them to the appropriate roles.
--
-- Role convention used elsewhere in this system (see authorize_reimbursement /
-- authorize_petty_cash in migrations/013_comprehensive_permissions_65.sql): "Branch
-- Head" maps to the HOD and Director HRM&A roles, since there is no dedicated
-- "Branch Head" role in the `roles` table.

-- ===================================
-- 1. Create the permissions (idempotent)
-- ===================================
INSERT IGNORE INTO `permissions` (`name`, `description`) VALUES
('approve_rfq_spec_review',          'Review and approve/reject RFQ quotes for specification compliance (Requestor)'),
('approve_rfq_branch_head',          'Provide final approval for RFQ quotes after specification review (Branch Head)'),
('assign_rfq_spec_reviewer',         'Assign specification reviewers to RFQs'),
('assign_rfq_branch_head_approver',  'Assign branch head approvers to RFQs'),
('view_rfq_approval_audit',          'View RFQ quote approval history and audit trail'),
('admin_override_approvals',        'Bypass RFQ approval assignment restrictions');

-- ===================================
-- 2. Assign permissions to roles
-- ===================================

-- Stage 1 (Specification Review) is performed by the Requestor. Procurement
-- Officer/HOD are also granted the permission so they can review on behalf of a
-- requestor who no longer has access, matching the original design notes.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r
JOIN `permissions` p ON p.name = 'approve_rfq_spec_review'
WHERE r.name IN ('Requestor', 'Procurement Officer', 'HOD', 'Admin', 'SuperAdmin');

-- Stage 2 (Final Approval) is performed by the Branch Head, represented in this
-- system by the HOD / Director HRM&A roles.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r
JOIN `permissions` p ON p.name = 'approve_rfq_branch_head'
WHERE r.name IN ('HOD', 'Director HRM&A', 'Admin', 'SuperAdmin');

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r
JOIN `permissions` p ON p.name = 'assign_rfq_spec_reviewer'
WHERE r.name IN ('Procurement Officer', 'Admin', 'SuperAdmin');

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r
JOIN `permissions` p ON p.name = 'assign_rfq_branch_head_approver'
WHERE r.name IN ('Director HRM&A', 'Admin', 'SuperAdmin');

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r
JOIN `permissions` p ON p.name = 'view_rfq_approval_audit'
WHERE r.name IN ('Requestor', 'Procurement Officer', 'HOD', 'Director HRM&A', 'Director Procurement', 'Admin', 'SuperAdmin');

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r
JOIN `permissions` p ON p.name = 'admin_override_approvals'
WHERE r.name IN ('Admin', 'SuperAdmin');
