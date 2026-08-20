# RFQ Vendor-Award Workflow Implementation Guide
## Complete 10-Stage Procurement Workflow with Enforced Approval Gates

---

## Table of Contents
1. [Overview](#overview)
2. [Workflow Stages (10 Stages)](#workflow-stages)
3. [Key Business Rules](#key-business-rules)
4. [Segregation of Duties](#segregation-of-duties)
5. [Branch-Based Routing](#branch-based-routing)
6. [Terminology](#terminology)
7. [Database Schema](#database-schema)
8. [Permission Requirements](#permission-requirements)
9. [Notification System](#notification-system)
10. [Audit Trail](#audit-trail)

---

## Overview

This comprehensive RFQ vendor-award workflow enforces a complete procurement process with 10 sequential stages, enforced approval gates, and mandatory segregation of duties. No stage can be bypassed, and all approvals are tracked in an immutable audit trail.

**Key Objectives:**
- Enforce sequential workflow progression
- Prevent bypassing of approval gates
- Ensure segregation of duties (no self-approval)
- Maintain comprehensive audit trail
- Route approvals based on branch assignment rules
- Provide notifications and escalation alerts
- Support reversals/corrections with full traceability

---

## Workflow Stages (10 Stages)

### Stage 1: Vendor Quotation Entry
**Responsible Officer:** Vendor / Procurement Officer

**Status Marker:** `QUOTE_REVIEW_PENDING` (on procurement_requests)

**Business Logic:**
- Vendors submit quotations for RFQ
- System captures:
  - Vendor name and details
  - Quotation amount and currency
  - GCT (General Consumption Tax) amount
  - Quotation document/attachment
  - Submission date and time
  - Evaluation history (JSON)

**Validation Rules:**
- Quote amount must be positive
- Submission must be before RFQ deadline
- At least one quotation required before proceeding

**Outputs:**
- Quote recorded in `rfq_quotes` table
- Submission date tracked
- Initial evaluation history created

---

### Stage 2: Requestor Quotation Review (SPECIFICATION REVIEW)
**Responsible Officer:** Requestor

**Status Marker:** `QUOTE_SPEC_REVIEW_PENDING` → `QUOTE_SPEC_REVIEW_APPROVED`

**Business Logic:**
- Requestor compares quotation against RFQ requirements and specifications
- Requestor must select:
  - **"Meets Specification"** → Proceeds to Branch Head Approval
  - **"Does Not Meet Specification"** → Routes for clarification/rejection

**Data Captured:**
- Requestor evaluation status (MEETS_SPECIFICATION or DOES_NOT_MEET)
- Requestor evaluation comments and reasoning
- Requestor ID and timestamp
- Evaluation history (JSON array with date, evaluator, status, comments)

**Business Rules:**
- Comments are mandatory (minimum 5 characters)
- Requestor cannot bypass this stage
- If "Does Not Meet Specification" → RFQ returned to requestor for correction
- If "Meets Specification" → Automatically proceeds to Branch Head

**Outputs:**
- Quote tagged as meeting/not meeting specifications
- Updates `rfq_quotes.requestor_evaluation_status`
- Audit trail entry for evaluation
- Workflow assignment created for Branch Head (if approved)

---

### Stage 3: Quote Selection
**Responsible Officer:** Requestor

**Status Marker:** Part of `QUOTE_SPEC_REVIEW_APPROVED` workflow

**Business Logic:**
- System displays all quotes that meet specifications
- Requestor selects the recommended quotation
- System automatically marks selected quote with `is_selected = 1`
- All other quotes marked `is_selected = 0`

**Data Displayed:**
- Specifications vs. quotation compliance
- Vendor details
- Quotation value
- Justification for selection
- Prior evaluation results

**Business Rules:**
- Cannot select a quote that was marked "Does Not Meet Specification"
- Only one quote can be selected per RFQ
- Selection is reversible before Branch Head approval

---

### Stage 4: Branch Head Final Approval
**Responsible Officer:** Branch Head (routing based on branch)

**Status Marker:** `QUOTE_BRANCH_HEAD_APPROVAL_PENDING` → `QUOTE_APPROVED`

**Business Logic:**
- Branch Head reviews the selected quotation
- Branch Head must provide approval or rejection
- Cannot be bypassed
- Approval completes vendor-selection gate

**Data Displayed:**
- RFQ specification summary
- Selected quotation details
- Requestor's evaluation and recommendation
- Vendor information
- Justification from requestor

**Branch-Based Routing Rules:**
```
- Analytical & Advisory Branch    → Deputy Government Chemist
- HRM&A Branch                    → Director HRM&A
- Executive Branch                → Government Chemist (HOD)
- All Other Branches              → Director HRM&A (default)
```

**Segregation of Duties:**
- Cannot approve their own request
- Cannot approve if they approved at specification review stage

**Workflow Control:**
- Cannot proceed to Funds Verification without approval
- Rejection returns RFQ to Requestor with required comments
- Comments are mandatory for rejection

**Outputs:**
- `rfqs.branch_head_approval_status` = APPROVED or REJECTED
- `rfqs.branch_head_approver_id` = Branch Head user ID
- `rfqs.branch_head_approved_at` = Timestamp
- `rfqs.branch_head_comments` = Approval/rejection comments
- Entry in `rfq_quote_approvals` with approval history
- Workflow assignment created for Finance Officer (if approved)
- Notification sent to Finance Officer for funds verification

---

### Stage 5: Funds Verification
**Responsible Officer:** Finance Officer

**Status Marker:** `FUNDS_VERIFIED` (on procurement_requests)

**Business Logic:**
- Finance Officer verifies availability and correctness of funds
- Records verification status, date, and comments
- Rejection must return RFQ with required reason

**Data Captured:**
- Verification status (APPROVED or REJECTED)
- Available funds amount
- Quote amount for verification
- Verification comments (mandatory, minimum 5 characters)
- Verification timestamp
- Record in `rfq_funds_verification` table

**Validation Rules:**
- Available funds must equal or exceed quote amount (for approval)
- Comments mandatory (minimum 5 characters)
- Rejection requires reason

**Segregation of Duties:**
- Finance Officer can verify even if they participated earlier
- But cannot reject if they approved funds at commitment stage

**Workflow Control:**
- Cannot bypass this stage
- Rejection routes RFQ back for correction
- Approval proceeds to Commitment Form stage

**Outputs:**
- `rfqs.funds_verified_status` = APPROVED or REJECTED
- `rfqs.funds_verified_by` = Finance Officer user ID
- `rfqs.funds_verified_at` = Timestamp
- `rfqs.funds_verification_comments` = Verification details
- `rfq_funds_verification` record created
- Notification sent to Finance Officer for commitment form (if approved)

---

### Stage 6: Commitment Form
**Responsible Officer:** Finance Officer

**Status Marker:** `COMMITMENT_APPROVED` (on procurement_requests)

**Business Logic:**
- Finance Officer prepares or verifies the commitment form
- Workflow must not advance until commitment info is complete and approved
- Commitment form includes:
  - Commitment Number (unique)
  - Commitment Amount
  - Commitment Date
  - Account Code
  - Fund Source Description
  - Optional comments

**Data Captured:**
- Commitment number (unique, indexed)
- Prepared by (Finance Officer ID)
- Status (DRAFT, PENDING_APPROVAL, APPROVED, REJECTED)
- Commitment amount and date
- Account code and fund source
- Optional attachment/document
- Approval audit trail

**Validation Rules:**
- Commitment number required (minimum 3 characters)
- Commitment amount must be positive
- Account code required (minimum 2 characters)
- Fund source required (minimum 5 characters)
- Commitment date required

**Business Rules:**
- Can save as DRAFT before final submission
- Cannot proceed to PO creation without APPROVED status
- Once approved, cannot be changed (immutable)
- Record in `rfq_commitment_forms` table

**Outputs:**
- `rfqs.commitment_number` = Commitment reference
- `rfqs.commitment_status` = APPROVED
- `rfqs.commitment_verified_by` = Finance Officer ID
- `rfqs.commitment_verified_at` = Timestamp
- `rfq_commitment_forms` record created
- Notification sent to Procurement Officer for RFQ letters

---

### Stage 7: RFQ Letters & Procurement Correspondence
**Responsible Officer:** Procurement Officer or Director of Procurement

**Status Marker:** Document upload tracking

**Business Logic:**
- Assigned procurement official prepares and issues required RFQ letters
- Maintain auditable record of documents issued
- Can be RFQ Notice, Award Letter, Rejection Letter, Clarification Request, etc.

**Data Captured:**
- Letter type (RFQ_NOTICE, AWARD_LETTER, REJECTION_LETTER, CLARIFICATION_REQUEST, OTHER)
- Letter number
- Issued by (Procurement Officer ID)
- Issued to vendor (if applicable)
- Document file path
- Letter date
- Acknowledgment status and date
- Comments

**Records in `rfq_procurement_letters` table:**
- Comprehensive audit trail of all correspondence
- Who issued, when, what type, to which vendor
- Receipt acknowledgment tracking

**Workflow Control:**
- Ensures proper correspondence issued before proceeding
- Can issue multiple letters (award to selected vendor, rejection to others)

**Outputs:**
- `rfqs.rfq_letter_issued_by` = Procurement Officer ID
- `rfqs.rfq_letter_issued_at` = Timestamp
- Entries in `rfq_procurement_letters` table
- Complete audit trail of all documents

---

### Stage 8: Purchase Order Creation
**Responsible Officer:** Procurement Officer or Director of Procurement

**Status Marker:** `PO_PENDING` (on procurement_requests)

**Business Logic:**
- Procurement creates or approves the purchase order
- PO must reference RFQ and selected vendor quotation
- Amount cannot exceed approved quotation without controlled variation approval

**Data Captured:**
- PO number (unique)
- PO date
- Vendor ID (selected vendor)
- Quote ID (reference)
- Approved quote amount (from RFQ)
- PO amount (actual PO)
- Variation amount (if exceeds quote)
- Delivery date and location
- Created by (Procurement Officer ID)
- Status (DRAFT, PENDING_APPROVAL, APPROVED, REJECTED, CANCELLED)

**Validation Rules:**
- PO number required (minimum 3 characters)
- PO date required
- PO amount must be positive
- Delivery date required and must be future date
- Delivery location required
- PO amount cannot exceed quote by >10% without variation approval

**Records in `rfq_purchase_orders` table:**
- Complete PO tracking with full history
- Links to RFQ and quotation
- Variation tracking

**Workflow Control:**
- PO required before proceeding to invoice stage
- Cannot bypass or omit
- Variation approval required for amounts exceeding quote

**Outputs:**
- `rfqs.po_number` = PO reference
- `rfqs.po_created_by` = Procurement Officer ID
- `rfqs.po_created_at` = Timestamp
- `rfq_purchase_orders` record created
- Audit trail entry

---

### Stage 9: Invoice Processing & Verification
**Responsible Officer:** Finance Officer

**Status Marker:** `INVOICE_RECEIVED` (on procurement_requests)

**Business Logic:**
- Finance Officer checks invoice against RFQ, purchase order, commitment, and received deliverables
- Flag mismatches for resolution before payment approval
- Verification checkpoints:
  1. **Amount Matches:** Invoice amount matches PO/Quote amount
  2. **Deliverables Received:** All goods/services received and verified
  3. **Commitment Matches:** Invoice matches commitment form terms

**Data Captured:**
- Invoice number (required)
- Invoice amount (required)
- Verification status (PENDING, VERIFIED, MISMATCH_FLAGGED, APPROVED_FOR_PAYMENT)
- Each checkpoint result (true/false)
- Comparison amounts:
  - RFQ quote amount
  - PO amount
  - Commitment amount
- Mismatch details (JSON array if flagged)
- Verification comments (mandatory, minimum 5 characters)
- Verified by (Finance Officer ID)
- Verification date

**Records in `rfq_invoice_verifications` table:**
- Complete invoice verification history
- All checkpoint results
- Mismatch tracking
- Full audit trail

**Workflow Control:**
- Cannot proceed to payment without verification
- Mismatches must be flagged and resolved
- Cannot bypass any checkpoint
- Finance Officer cannot change invoice - must contact vendor/requestor

**Outputs:**
- `rfqs.invoice_checked_by` = Finance Officer ID
- `rfqs.invoice_checked_at` = Timestamp
- `rfqs.invoice_mismatch_comments` = Any issues found
- `rfq_invoice_verifications` record created
- Status determines if ready for payment or requires correction
- Notification sent to HOD for final approval (if verified)

---

### Stage 10: HOD Final Approval
**Responsible Officer:** Government Chemist (Head of Department)

**Status Marker:** `COMPLETED` (on procurement_requests)

**Business Logic:**
- Government Chemist provides HOD approval where departmental approval is required
- Final approval completes the entire RFQ process
- Any rejection must contain comments and return transaction to responsible stage

**Data Captured:**
- Approval status (APPROVED or REJECTED)
- Approved by (Government Chemist/HOD ID)
- Approval date and time
- Approval comments (mandatory for rejection)

**Segregation of Duties:**
- Cannot approve if they created the request
- Cannot approve if they participated in prior stages

**Workflow Control:**
- Cannot bypass this stage
- Rejection returns RFQ with comments
- Approval marks process as COMPLETED

**Outputs:**
- `rfqs.hod_approval_status` = APPROVED or REJECTED
- `rfqs.hod_approved_by` = HOD user ID
- `rfqs.hod_approved_at` = Timestamp
- `rfqs.hod_approval_comments` = Approval/rejection comments
- `procurement_requests.status` = COMPLETED (if approved)
- Audit trail entry marks final approval
- Vendor notified of award/completion

---

## Key Business Rules

### 1. **No Bypassing Stages**
- Every stage must be completed before proceeding to the next
- No "skip" or "accelerate" options
- Backward movement only for corrections/clarifications

### 2. **Segregation of Duties**
- **NO SELF-APPROVAL:** Users cannot approve their own requests
- **NO REPETITIVE APPROVAL:** If a user approved at Stage 2 (Requestor Review), they cannot approve at Stage 4 (Branch Head) on the same RFQ
- **Finance Officer:** Can verify funds even if they prepared commitment, but cannot reject

### 3. **Mandatory Comments**
- Stage 2 (Requestor Review): Comments mandatory if "Does Not Meet Specification"
- Stage 4 (Branch Head): Comments mandatory if rejection
- Stage 5 (Funds): Comments mandatory for rejection
- Stage 6 (Commitment): Optional comments
- Stage 9 (Invoice): Comments mandatory
- Stage 10 (HOD): Comments mandatory for rejection

### 4. **Amount Verification**
- Stage 5 (Funds): Available funds must ≥ Quote amount
- Stage 8 (PO): PO amount cannot exceed Quote by >10% without variation approval
- Stage 9 (Invoice): Amount must match PO/Quote (flagged if mismatch)

### 5. **Immutability of Approvals**
- Once approved at any stage, cannot be "unapproved"
- Corrections require backward movement to prior stage
- All changes tracked with reason and responsible officer

### 6. **Workflow Assignment & Escalation**
- Each stage has an assigned responsible officer
- Due dates tracked (e.g., 5 days default, 2 days if critical)
- Escalation triggered if due date passes
- Backup officer configuration for emergencies

---

## Segregation of Duties

### Enforcement Points

1. **Self-Approval Prevention**
   - System checks: `created_by` field against current user
   - Reject if same user created request and tries to approve

2. **Sequential Role Segregation**
   - Requestor at Stage 2 → Branch Head at Stage 4
   - Branch Head at Stage 4 → Finance at Stages 5-6
   - Finance at Stages 5-6 → Procurement at Stage 8
   - Procurement at Stage 8 → HOD at Stage 10

3. **Dual Approval** (where required)
   - Finance verification + Branch Head sign-off
   - HOD final approval separate from process

4. **Role-Based Access**
   - Requestor can only review quotes
   - Branch Head can only approve quotes (not funds/PO)
   - Finance Officer can only approve funds/commitment/invoice
   - Procurement can only create PO/letters
   - HOD has final authority

---

## Branch-Based Routing

### Routing Logic

The system automatically routes approvals based on branch assignment:

```
Department/Branch Name                    → Approval Role
─────────────────────────────────────────────────────────
Analytical and Advisory Branch            → Deputy Government Chemist
HRM&A Branch                              → Director HRM&A
Executive Branch                          → Government Chemist (HOD)
Any Other Branch                          → Director HRM&A (default)
```

### Configuration

Branch routing rules are configurable in `rfq_branch_routing_rules` table:
- `branch_id`: Department ID
- `approval_stage`: BRANCH_HEAD_FINAL_APPROVAL, etc.
- `responsible_role`: Role name (e.g., "Branch Head")
- `alternate_role`: Escalation role (e.g., "Deputy Government Chemist")

### Fallback Logic

If primary approver not found:
1. Try alternate role
2. If no alternate, check default (Director HRM&A)
3. If still unresolvable → **STOP WORKFLOW** and alert administrator

---

## Terminology

### Correct Terms (Use Consistently)

| Term | Usage | Example |
|------|-------|---------|
| **Stage Approval** | Approval at a workflow stage | "This stage approval is required before proceeding." |
| **Quotation** | Vendor's proposed price/terms | "The quotation meets specifications." |
| **Selected Quotation** | Chosen quote for award | "The selected quotation is from Vendor ABC." |
| **Vendor Award** | Final selection and approval | "The vendor award is subject to HOD approval." |
| **Finance Officer** | Role responsible for funds | "Finance Officer must verify funds." |
| **Government Chemist** | Head of Department (HOD role) | "Government Chemist approval required." |
| **HRM&A** | Human Resources, Management & Administration | "HRM&A Branch defaults to Director HRM&A." |

### Incorrect Terms (Avoid)

- ❌ "Stage Approvel" → Use "Stage Approval"
- ❌ "Quote" (alone) → Specify "quotation" or "quote amount"
- ❌ "Procurement Officer" alone → Specify "Procurement Officer or Director of Procurement"
- ❌ "Head Chemist" → Use "Government Chemist"

---

## Database Schema

### Core Tables

1. **`rfqs`** - Main RFQ record with all stage columns
   - Stages 1-10 columns added
   - `spec_review_status`, `branch_head_approval_status`, `funds_verified_status`, etc.
   - Timestamps for each stage

2. **`rfq_quotes`** - Vendor quotations
   - `requestor_evaluation_status` (MEETS_SPECIFICATION, DOES_NOT_MEET)
   - `evaluation_history` (JSON)
   - `is_selected` flag

3. **`rfq_quote_approvals`** - Approval audit trail
   - `approval_stage` (enum with all 10 stages)
   - `action` (APPROVED, REJECTED, RETURNED_FOR_CLARIFICATION)
   - Full history tracking

4. **`rfq_funds_verification`** - Stage 5 verification records
5. **`rfq_commitment_forms`** - Stage 6 commitment tracking
6. **`rfq_procurement_letters`** - Stage 7 correspondence tracking
7. **`rfq_purchase_orders`** - Stage 8 PO records
8. **`rfq_invoice_verifications`** - Stage 9 invoice checks
9. **`rfq_branch_routing_rules`** - Branch-based routing configuration
10. **`rfq_workflow_assignments`** - Current assignments and status
11. **`rfq_workflow_stages_config`** - Master configuration for all 10 stages

---

## Permission Requirements

### Required Permissions

```php
'approve_rfq_spec_review'          // Specification review approval
'approve_rfq_branch_head'          // Branch Head quote approval
'verify_rfq_funds'                 // Finance funds verification
'manage_rfq_commitment'            // Commitment form management
'create_rfq_purchase_order'        // PO creation
'verify_rfq_invoice'               // Invoice verification
'approve_rfq_hod'                  // HOD final approval
'upload_rfq_letter'                // RFQ letter upload
'admin_rfq_workflow'               // Admin override/management
'admin_override_approvals'         // Admin bypass (emergency only)
```

### Role-Permission Mapping

| Role | Permissions |
|------|-------------|
| Requestor | Can review quotes (Stage 2), select quotes (Stage 3) |
| Branch Head | approve_rfq_branch_head (Stage 4) |
| Finance Officer | verify_rfq_funds, manage_rfq_commitment, verify_rfq_invoice |
| Procurement Officer | create_rfq_purchase_order, upload_rfq_letter |
| Government Chemist | approve_rfq_hod |
| Admin | All permissions including admin_override_approvals |

---

## Notification System

### Notification Types

1. **Stage Assignment Notification**
   - Sent when user assigned to a stage
   - Includes RFQ details, quote amount, deadline

2. **Approval Needed Notification**
   - Sent when previous stage completed
   - Direct link to approval page
   - High priority, due date, escalation info

3. **Rejection Notification**
   - Sent when approval rejected
   - Includes reason/comments
   - Links to correction instructions

4. **Escalation Notification**
   - Sent if due date passed without action
   - Alerts approver and manager
   - Escalates to alternate approver if configured

### Delivery Methods

- **Email** notifications with action buttons
- **In-App** notifications in system dashboard
- **SMS** (optional) for critical approvals
- **Admin Alerts** for unresolvable approvers

---

## Audit Trail

### What's Tracked

1. **Every Approval**
   - Who approved/rejected
   - When
   - What stage
   - Comments/reason
   - Amount affected

2. **Every Stage Entry**
   - User assigned
   - Timestamp
   - Branch used for routing
   - Routing reason

3. **Every Reversal/Correction**
   - From stage, to stage
   - Reason for revert
   - Who initiated
   - When

4. **Every Data Change**
   - Field changed
   - Old value, new value
   - Who changed
   - When

### Audit Trail Tables

- `audit_log` - General audit entries (extended)
- `rfq_quote_approvals` - Approval-specific history
- `rfq_workflow_assignments` - Assignment tracking
- `rfq_funds_verification` - Funds verification history
- `rfq_commitment_forms` - Commitment history
- `rfq_procurement_letters` - Letter tracking
- `rfq_purchase_orders` - PO history
- `rfq_invoice_verifications` - Invoice verification history

### Viewing Audit Trail

- Users can view approval history on RFQ view page
- Timeline showing all stages and approvers
- Complete comments and reasoning

---

## Hover/Tooltip: Responsible Officer Information

For every workflow stage, display a tooltip on "Responsible Officer" that shows:

```
Stage Name:              [e.g., "Branch Head Final Approval"]
Responsible Role:        [e.g., "Branch Head"]
Resolved Individual:     [Name of assigned user]
Branch Used for Routing: [Department name]
Selection Reason:        [Why this person was selected]
Current Status:          [ASSIGNED, COMPLETED, ESCALATED]
Date Assigned:           [Timestamp]
Due Date:                [Deadline for completion]
Backup Officer:          [Name if configured]
```

---

## Implementation Status

### Completed ✅
- Database schema migration
- Core workflow service (RFQWorkflowService)
- 10 workflow stage pages
- Notification framework
- Audit trail infrastructure
- Branch routing logic
- Segregation of duties checks

### In Progress 🔄
- Integration testing
- Notification delivery
- Admin dashboard for monitoring
- Escalation/due date enforcement

### TODO 📋
- User documentation
- Admin training materials
- Performance optimization
- Bulk operation support

---

## Support & Questions

For questions or issues:
1. Check this documentation first
2. Review audit trail for workflow history
3. Contact admin for permission issues
4. Contact IT for database/technical issues

