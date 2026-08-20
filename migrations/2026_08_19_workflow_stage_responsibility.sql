-- ============================================================
-- Migration: 2026_08_19_workflow_stage_responsibility.sql
-- Purpose : Create workflow_stage_responsibility lookup table
--           and populate it with canonical responsibility data
--           for all four workflow types.
--
-- This table acts as the authoritative configuration store for
-- which job title / role owns each pipeline stage.  The
-- WorkflowResponsibilityService reads from it at runtime (when
-- a DB override is preferred) but also ships a PHP static map
-- as the default fallback.  Operators may update rows here to
-- adjust displayed responsibility without a code change.
--
-- Columns:
--   id               PK
--   workflow_type    REGULAR | REIMBURSEMENT | PETTY_CASH | SERVICE_CONTRACT
--   stage_status     Uppercase status constant, e.g. HOD_APPROVED
--   responsible_role Human-readable job title / role name (no numeric IDs)
--   display_label    Short label for the stage (optional override)
--   action_description  Instruction shown in tooltip Action line
--   is_active        1 = included, 0 = excluded from tooltip
--   sort_order       Display order within workflow type
-- ============================================================

CREATE TABLE IF NOT EXISTS `workflow_stage_responsibility` (
    `id`                  INT(11)      NOT NULL AUTO_INCREMENT,
    `workflow_type`       VARCHAR(30)  NOT NULL COMMENT 'REGULAR | REIMBURSEMENT | PETTY_CASH | SERVICE_CONTRACT',
    `stage_status`        VARCHAR(60)  NOT NULL COMMENT 'Uppercase status constant, e.g. HOD_APPROVED',
    `responsible_role`    VARCHAR(100) NOT NULL COMMENT 'Job title / role name (no numeric IDs)',
    `display_label`       VARCHAR(120)          DEFAULT NULL COMMENT 'Optional display label override',
    `action_description`  TEXT                  DEFAULT NULL COMMENT 'Action instruction shown in tooltip',
    `is_active`           TINYINT(1)   NOT NULL DEFAULT 1,
    `sort_order`          INT(11)      NOT NULL DEFAULT 0,
    `created_at`          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_workflow_stage` (`workflow_type`, `stage_status`),
    KEY `idx_wsr_workflow_type` (`workflow_type`),
    KEY `idx_wsr_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Canonical ownership/responsibility for each workflow pipeline stage';

-- ============================================================
-- Seed data — REGULAR procurement workflow
-- ============================================================
INSERT IGNORE INTO `workflow_stage_responsibility`
    (`workflow_type`, `stage_status`, `responsible_role`, `display_label`, `action_description`, `sort_order`)
VALUES
    ('REGULAR', 'DRAFT',                          'Requestor',               'Draft',
     'Complete all required fields and submit the procurement request.',                                    10),
    ('REGULAR', 'SUBMITTED',                      'Head of Department',      'Submitted',
     'Review the request details and approve or decline.',                                                  20),
    ('REGULAR', 'HOD_APPROVED',                   'HOD',                     'HOD Approved',
     'Provide HOD-level approval (Government Chemist for the Executive Branch).',                          30),
    ('REGULAR', 'FUNDS_VERIFIED',                 'Finance Officer',         'Funds Verified',
     'Verify that sufficient funds are available for this request.',                                        40),
    ('REGULAR', 'DIRECTOR_APPROVED',              'Director HRM&A',          'Director Approved',
     'Provide director-level approval (Deputy Government Chemist for Analytical and Advisory Branch, Director HRM&A for HRM&A Branch, HOD for Executive Branch).', 50),
    ('REGULAR', 'GC_APPROVED',                    'Procurement Officer',     'GC Approved',
     'Generate and issue the RFQ letter to shortlisted vendors.',                                           60),
    ('REGULAR', 'RFQ_LETTER_AVAILABLE',           'Procurement Officer / Director Procurement', 'RFQ Letters',
     'Collect and record vendor quotations for this request.',                                              70),
    ('REGULAR', 'QUOTE_REVIEW_PENDING',           'Requestor / Branch Head', 'Quote Review',
     'Review submitted quotations and select the preferred vendor.',                                        80),
    ('REGULAR', 'QUOTE_SPEC_REVIEW_PENDING',      'Procurement Officer',     'Spec Review',
     'Review quotations against technical specifications.',                                                 85),
    ('REGULAR', 'QUOTE_SPEC_REVIEW_APPROVED',     'Branch Head',             'Spec Approved',
     'Approve the specification-reviewed quotation.',                                                       87),
    ('REGULAR', 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING','Branch Head',          'Branch Head Approval',
     'Provide branch head approval for the selected quotation.',                                            89),
    ('REGULAR', 'QUOTE_APPROVED',                 'Requestor / Branch Head', 'Quote Selected',
     'Confirm the selected quotation before financial commitment.',                                         90),
    ('REGULAR', 'COMMITMENTS_PENDING',            'Finance Officer',         'Commitment Form',
     'Create the financial commitment for the awarded vendor.',                                            100),
    ('REGULAR', 'COMMITMENT_APPROVED',            'Procurement Officer',     'Commitment Created',
     'Generate the purchase order from the approved commitment.',                                          110),
    ('REGULAR', 'COMMITMENT_DECLINED',            'Finance Officer',         'Commitment Declined',
     'Resolve the funding issue and resubmit the commitment.',                                             115),
    ('REGULAR', 'PO_PENDING',                     'Procurement Officer / Director Procurement', 'PO Created',
     'Generate and approve the purchase order.',                                                           120),
    ('REGULAR', 'PO_APPROVED',                    'Procurement Officer / Director Procurement', 'PO Approved',
     'Generate and approve the purchase order.',                                                           125),
    ('REGULAR', 'INVOICE_RECEIVED',               'Finance Officer',         'Invoice',
     'Process payment and mark the request as complete.',                                                  130),
    ('REGULAR', 'PROCUREMENT_STAGE',              'Procurement Officer',     'Procurement',
     'Manage the open-tender procurement stage.',                                                          140),
    ('REGULAR', 'EVALUATION_STAGE',               'Procurement Officer',     'Evaluation',
     'Evaluate submitted tenders and prepare evaluation report.',                                          150),
    ('REGULAR', 'COMMITTEE_RECOMMENDED',          'Procurement Committee',   'Committee',
     'Review committee recommendation and provide approval.',                                              160),
    ('REGULAR', 'AWARDED',                        'Finance Officer',         'Awarded',
     'Create the financial commitment for the awarded vendor.',                                             170),
    ('REGULAR', 'COMPLETED',                      'System',                  'Complete',
     'This request has been fully processed and closed.',                                                  180);

-- ============================================================
-- Seed data — REIMBURSEMENT workflow
-- ============================================================
INSERT IGNORE INTO `workflow_stage_responsibility`
    (`workflow_type`, `stage_status`, `responsible_role`, `display_label`, `action_description`, `sort_order`)
VALUES
    ('REIMBURSEMENT', 'DRAFT',             'Requestor',       'Draft',
     'Complete all required fields and submit the reimbursement form.',                                     10),
    ('REIMBURSEMENT', 'SUBMITTED',         'Finance Officer', 'Submitted',
     'Verify that sufficient funds are available for this reimbursement.',                                  20),
    ('REIMBURSEMENT', 'FUNDS_VERIFIED',    'Finance Officer', 'Funds Verified',
     'Review submitted invoices and verify the amounts claimed.',                                           30),
    ('REIMBURSEMENT', 'INVOICE_SUBMITTED', 'Finance Officer', 'Invoices Submitted',
     'Verify the invoice details and confirm accuracy.',                                                    40),
    ('REIMBURSEMENT', 'INVOICE_VERIFIED',  'Finance Officer', 'Invoices Verified',
     'Approve the reimbursement after invoice verification.',                                               50),
    ('REIMBURSEMENT', 'APPROVED',          'Finance Officer', 'Approved',
     'Process the reimbursement payment to the requestor.',                                                 60),
    ('REIMBURSEMENT', 'REIMBURSED',        'Finance Officer', 'Reimbursed',
     'Confirm receipt and mark this reimbursement as complete.',                                            70),
    ('REIMBURSEMENT', 'COMPLETED',         'System',          'Complete',
     'This reimbursement has been fully processed and closed.',                                             80);

-- ============================================================
-- Seed data — PETTY_CASH workflow
-- ============================================================
INSERT IGNORE INTO `workflow_stage_responsibility`
    (`workflow_type`, `stage_status`, `responsible_role`, `display_label`, `action_description`, `sort_order`)
VALUES
    ('PETTY_CASH', 'DRAFT',                      'Requestor',          'Draft',
     'Complete all required fields and submit the petty cash request.',                                     10),
    ('PETTY_CASH', 'SUBMITTED',                  'Finance Officer',    'Submitted',
     'Verify that petty cash funds are available for this request.',                                        20),
    ('PETTY_CASH', 'FUNDS_VERIFIED',             'Finance Officer',    'Funds Verified',
     'Review and authorize the petty cash disbursement.',                                                   30),
    ('PETTY_CASH', 'FINANCE_AUTHORIZED',         'Finance Officer',    'Finance Authorized',
     'Disburse the approved petty cash amount to the requestor.',                                           40),
    ('PETTY_CASH', 'DISBURSED',                  'Requestor',          'Disbursed',
     'Submit purchase receipts and reconciliation documentation.',                                          50),
    ('PETTY_CASH', 'PENDING_RECONCILIATION',     'Procurement Officer','Awaiting Documentation',
     'Verify submitted purchase documentation.',                                                            60),
    ('PETTY_CASH', 'PROCUREMENT_VERIFIED',       'Finance Officer',    'Documents Verified',
     'Confirm reconciliation and close this petty cash request.',                                           70),
    ('PETTY_CASH', 'RECONCILIATION_DISCREPANCY', 'Finance Officer',    'Discrepancy',
     'Review the reconciliation discrepancy and resolve.',                                                  75),
    ('PETTY_CASH', 'REVIEWED',                   'Finance Officer',    'Reviewed',
     'Confirm the reviewed status and mark as complete.',                                                   77),
    ('PETTY_CASH', 'COMPLETED',                  'System',             'Complete',
     'This petty cash request has been fully processed and closed.',                                        80);

-- ============================================================
-- Seed data — SERVICE_CONTRACT workflow
-- ============================================================
INSERT IGNORE INTO `workflow_stage_responsibility`
    (`workflow_type`, `stage_status`, `responsible_role`, `display_label`, `action_description`, `sort_order`)
VALUES
    ('SERVICE_CONTRACT', 'DRAFT',               'Requestor',               'Draft',
     'Complete all required fields and submit the service contract request.',                               10),
    ('SERVICE_CONTRACT', 'SUBMITTED',           'Branch Head',             'Submitted',
     'Review and approve this service contract request.',                                                   20),
    ('SERVICE_CONTRACT', 'HOD_APPROVED',        'HOD',                     'Branch Approved',
     'Provide HOD-level approval (Government Chemist for the Executive Branch).',                           30),
    ('SERVICE_CONTRACT', 'DIRECTOR_APPROVED',   'Director HRM&A',          'Director Approved',
     'Provide director-level approval (Deputy Government Chemist for Analytical and Advisory Branch, Director HRM&A for HRM&A Branch, HOD for Executive Branch).', 40),
    ('SERVICE_CONTRACT', 'GC_APPROVED',         'Finance Officer',         'GC Approved',
     'Verify funds and create the financial commitment.',                                                   50),
    ('SERVICE_CONTRACT', 'FUNDS_VERIFIED',      'Finance Officer',         'Funds Verified',
     'Create the financial commitment for the service contract.',                                           60),
    ('SERVICE_CONTRACT', 'COMMITMENT_APPROVED', 'Finance Officer',         'Committed',
     'Upload and record the vendor invoice when received.',                                                 70),
    ('SERVICE_CONTRACT', 'INVOICE_RECEIVED',    'Finance Officer',         'Invoiced',
     'Process payment and mark the contract as complete.',                                                  80),
    ('SERVICE_CONTRACT', 'COMPLETED',           'System',                  'Paid',
     'This service contract has been fully processed and closed.',                                          90);
