-- Ensure procurement workflow statuses fit all configured stage constants.
-- QUOTE_BRANCH_HEAD_APPROVAL_PENDING is 34 characters, so varchar(30)
-- truncates or rejects the active Branch Head Approval stage.

ALTER TABLE procurement_requests
  MODIFY status VARCHAR(50) NOT NULL DEFAULT 'DRAFT',
  MODIFY paused_previous_status VARCHAR(50) DEFAULT NULL;

UPDATE procurement_requests
   SET status = 'QUOTE_REQUESTOR_REVIEW_APPROVED'
 WHERE status = 'QUOTE_REQUESTOR_REVIEW_APPROVE';

UPDATE procurement_requests
   SET status = 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING'
 WHERE status = 'QUOTE_BRANCH_HEAD_APPROVAL_PEN';

UPDATE procurement_requests
   SET paused_previous_status = 'QUOTE_REQUESTOR_REVIEW_APPROVED'
 WHERE paused_previous_status = 'QUOTE_REQUESTOR_REVIEW_APPROVE';

UPDATE procurement_requests
   SET paused_previous_status = 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING'
 WHERE paused_previous_status = 'QUOTE_BRANCH_HEAD_APPROVAL_PEN';
