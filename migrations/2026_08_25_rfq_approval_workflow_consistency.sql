-- RFQ approval workflow consistency and recovery
-- Apply after 2026_08_21_requestor_branch_head_approval_workflow.sql.
START TRANSACTION;

INSERT IGNORE INTO permissions (name, description) VALUES
    ('submit_requestor_spec_review', 'Submit requestor specification confirmation'),
    ('view_requestor_spec_review_interface', 'View requestor specification confirmation'),
    ('approve_branch_head_award', 'Approve or reject the selected RFQ quote as Branch Head'),
    ('view_branch_head_approval_interface', 'View Branch Head RFQ approval');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
  ON p.name IN ('submit_requestor_spec_review', 'view_requestor_spec_review_interface')
 WHERE r.name = 'Requestor';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
  ON p.name IN ('approve_branch_head_award', 'view_branch_head_approval_interface')
 WHERE r.name IN ('Branch Head', 'HOD', 'Director HRM&A', 'Admin', 'SuperAdmin');

-- Recover requests left at quote review after a quote was selected.
UPDATE procurement_requests pr
JOIN rfqs r ON r.request_id = pr.request_id
JOIN rfq_quotes q ON q.is_selected = 1
JOIN rfq_vendors rv ON rv.rfq_vendor_id = q.rfq_vendor_id AND rv.rfq_id = r.rfq_id
   SET pr.status = 'QUOTE_REQUESTOR_REVIEW_PENDING',
       r.requestor_spec_review_status = 'PENDING',
       r.branch_head_approval_status = 'PENDING'
 WHERE pr.status = 'QUOTE_REVIEW_PENDING'
   AND COALESCE(q.is_deleted, 0) = 0;

COMMIT;
