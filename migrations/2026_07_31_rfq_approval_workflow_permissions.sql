-- Permission and Role Setup for RFQ Quote Approval Workflow
-- This file documents the new permissions required for the two-stage approval workflow

-- ===================================
-- New Permissions
-- ===================================

-- Permission: approve_rfq_spec_review
-- Description: Allows user to review and approve/reject RFQ quotes for specification compliance
-- Assigned to roles: Specification Reviewer, Procurement Officer, HOD
-- INSERT INTO permissions (permission_slug, permission_name, permission_description, module, created_at)
-- VALUES ('approve_rfq_spec_review', 'Approve RFQ Spec Review', 'Review and approve/reject RFQ quotes for specification compliance', 'RFQ', NOW());

-- Permission: approve_rfq_branch_head
-- Description: Allows branch head to provide final approval for RFQ quotes after spec review
-- Assigned to roles: Branch Head, HOD, Director HRM&A
-- INSERT INTO permissions (permission_slug, permission_name, permission_description, module, created_at)
-- VALUES ('approve_rfq_branch_head', 'Branch Head Approval of RFQ', 'Provide final approval for RFQ quotes after specification review', 'RFQ', NOW());

-- Permission: assign_rfq_spec_reviewer
-- Description: Allows admin to assign specification reviewers to RFQs
-- Assigned to roles: Admin, SuperAdmin, Procurement Officer
-- INSERT INTO permissions (permission_slug, permission_name, permission_description, module, created_at)
-- VALUES ('assign_rfq_spec_reviewer', 'Assign RFQ Spec Reviewer', 'Assign specification reviewers to RFQs', 'RFQ', NOW());

-- Permission: assign_rfq_branch_head_approver
-- Description: Allows admin to assign branch head approvers to RFQs
-- Assigned to roles: Admin, SuperAdmin, Director HRM&A
-- INSERT INTO permissions (permission_slug, permission_name, permission_description, module, created_at)
-- VALUES ('assign_rfq_branch_head_approver', 'Assign RFQ Branch Head Approver', 'Assign branch head approvers to RFQs', 'RFQ', NOW());

-- Permission: view_rfq_approval_audit
-- Description: Allows user to view RFQ approval audit trail and history
-- Assigned to roles: All users
-- INSERT INTO permissions (permission_slug, permission_name, permission_description, module, created_at)
-- VALUES ('view_rfq_approval_audit', 'View RFQ Approval Audit Trail', 'View RFQ quote approval history and audit trail', 'RFQ', NOW());

-- Permission: admin_override_approvals
-- Description: Allows admin to bypass approval restrictions (e.g., review RFQ not assigned to them)
-- Assigned to roles: Admin, SuperAdmin
-- INSERT INTO permissions (permission_slug, permission_name, permission_description, module, created_at)
-- VALUES ('admin_override_approvals', 'Override Approval Restrictions', 'Bypass RFQ approval assignment restrictions', 'RFQ', NOW());

-- ===================================
-- Role Permission Assignments
-- ===================================

-- Specification Reviewer role permissions:
-- - approve_rfq_spec_review
-- - view_rfq_approval_audit
-- - view_requests

-- Branch Head role permissions:
-- - approve_rfq_branch_head
-- - view_rfq_approval_audit
-- - view_requests

-- Procurement Officer role permissions:
-- - approve_rfq_spec_review (can also do spec review)
-- - approve_rfq_branch_head (if designated as branch head approver)
-- - assign_rfq_spec_reviewer (for admin/management)
-- - view_rfq_approval_audit
-- - view_requests
-- - upload_rfq_quote

-- HOD role permissions:
-- - approve_rfq_spec_review (can also do spec review)
-- - approve_rfq_branch_head (can also be branch head approver)
-- - assign_rfq_spec_reviewer (for admin purposes)
-- - view_rfq_approval_audit
-- - view_requests

-- Admin/SuperAdmin permissions:
-- - All permissions above
-- - admin_override_approvals
-- - assign_rfq_branch_head_approver

-- ===================================
-- Configuration Notes
-- ===================================
-- 1. Default Specification Reviewers:
--    When the first quote is uploaded to an RFQ, the system automatically assigns
--    a default specification reviewer (Procurement Officer or designated Specification Reviewer).
--    This can be overridden by admins via the rfq_spec_reviewers table.

-- 2. Branch Head Approvers:
--    After spec review approval, the system looks for assigned branch head approvers
--    in the rfq_branch_head_approvers table. Admins must assign these or the approval
--    interface will deny access.

-- 3. Approval Workflow States:
--    - QUOTE_REVIEW_PENDING: Initial state, awaiting first quote
--    - QUOTE_SPEC_REVIEW_PENDING: Spec reviewer needed
--    - QUOTE_SPEC_REVIEW_APPROVED: Spec review passed, branch head needed
--    - QUOTE_SPEC_REVIEW_REJECTED: Returned to requestor for revision
--    - QUOTE_BRANCH_HEAD_APPROVAL_PENDING: Branch head review in progress
--    - QUOTE_APPROVED: Both approvals complete, ready for supplier selection

-- 4. Notifications:
--    The system automatically sends notifications at key workflow points:
--    - When quotes are uploaded (to spec reviewer)
--    - When spec review is approved (to branch head)
--    - When spec review is rejected (to requestor)
--    - When branch head approval is complete (to procurement team)
--    - When branch head rejects (to requestor and spec reviewer)

-- 5. Audit Trail:
--    All approval actions are logged in rfq_quote_approvals table with:
--    - Who approved/rejected (user_id, role)
--    - When the action occurred (timestamp)
--    - What action was taken (approve, reject, return for clarification)
--    - Comments/reasons provided
