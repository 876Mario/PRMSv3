# RFQ Quote Review and Approval Workflow Implementation

## Overview

This implementation adds a comprehensive two-step approval process for RFQ quotes to ensure proper oversight, accountability, and compliance with procurement governance requirements.

## Workflow Stages

### Stage 1: Specification Review
- **Status**: `QUOTE_SPEC_REVIEW_PENDING`
- **Responsibility**: Designated Specification Reviewer (or Procurement Officer)
- **Actions**: 
  - **Approve**: Quotes meet all specification requirements
  - **Reject**: Return quotes for revision due to non-compliance
  - **Return**: Request clarification on specific requirements

### Stage 2: Branch Head Final Approval
- **Status**: `QUOTE_BRANCH_HEAD_APPROVAL_PENDING`
- **Responsibility**: Branch Head or designated approver
- **Prerequisites**: Specification review must be approved
- **Actions**:
  - **Approve**: Grant final approval for supplier selection
  - **Reject**: Reject the entire RFQ process
  - **Return**: Request clarification before proceeding

### Final Status
- **Status**: `QUOTE_APPROVED`
- **Meaning**: Both approvals complete; ready for supplier selection

## Database Schema Changes

### New Tables

#### `rfq_quote_approvals`
Audit trail table tracking all approval actions for each RFQ quote workflow.

**Columns:**
- `approval_id` (PK): Auto-increment approval record ID
- `rfq_id` (FK): Reference to RFQ
- `approval_stage`: 'SPEC_REVIEW' or 'BRANCH_HEAD_APPROVAL'
- `approver_id` (FK): User who performed the action
- `approver_role`: Role of the approver (Specification Reviewer, Branch Head, etc.)
- `action`: 'APPROVED', 'REJECTED', or 'RETURNED_FOR_CLARIFICATION'
- `comments`: Detailed comments or reason
- `rejection_reason`: Specific rejection/clarification reason
- `created_at`: Timestamp of action

**Indexes:**
- `idx_rfq_id`: Fast lookup by RFQ
- `idx_approval_stage`: Filter by approval stage
- `idx_approver_id`: Track approver actions
- `idx_action`: Filter by action type
- `idx_created_at`: Time-based queries

#### `rfq_spec_reviewers`
Tracks specification reviewer assignments for each RFQ.

**Columns:**
- `assignment_id` (PK): Auto-increment
- `rfq_id` (FK): Reference to RFQ
- `reviewer_id` (FK): User assigned as reviewer
- `reviewer_role`: Role of reviewer
- `assigned_by` (FK): Admin who made assignment
- `assigned_at`: Assignment timestamp
- `is_active`: Whether assignment is still active

**Unique Constraint:** One reviewer per RFQ

#### `rfq_branch_head_approvers`
Tracks branch head approver assignments for each RFQ.

**Columns:**
- `assignment_id` (PK): Auto-increment
- `rfq_id` (FK): Reference to RFQ
- `approver_id` (FK): User assigned as approver
- `approver_role`: Role of approver
- `assigned_by` (FK): Admin who made assignment
- `assigned_at`: Assignment timestamp
- `is_active`: Whether assignment is still active

**Unique Constraint:** One approver per RFQ

### Modified Tables

#### `rfqs` Table
Added columns to track approval workflow:
- `spec_review_status` (ENUM: PENDING, APPROVED, REJECTED)
- `spec_reviewer_id` (FK to users)
- `spec_reviewed_at` (DATETIME)
- `spec_review_comments` (TEXT)
- `branch_head_approval_status` (ENUM: PENDING, APPROVED, REJECTED)
- `branch_head_approver_id` (FK to users)
- `branch_head_approved_at` (DATETIME)
- `branch_head_comments` (TEXT)

**Indexes Added:**
- `idx_rfq_spec_review_status`: Fast status queries
- `idx_rfq_branch_head_approval_status`: Fast status queries
- `idx_rfq_spec_reviewer_id`: Reviewer lookup
- `idx_rfq_branch_head_approver_id`: Approver lookup

#### `audit_log` Table
Added columns for approval workflow tracking:
- `approval_stage`: Which approval stage (QUOTE_UPLOAD, SPEC_REVIEW, BRANCH_HEAD_APPROVAL)
- `approval_action`: What action was taken (APPROVED, REJECTED, etc.)
- `approval_comments`: Details of the approval action

## Workflow Transitions

### RFQ Procurement Request Status Transitions

```
RFQ_LETTER_AVAILABLE
    ↓
QUOTE_REVIEW_PENDING (first quote uploaded)
    ↓
QUOTE_SPEC_REVIEW_PENDING (initial spec review state)
    ↓
QUOTE_SPEC_REVIEW_APPROVED (spec review approved)
    ↓
QUOTE_BRANCH_HEAD_APPROVAL_PENDING (branch head review)
    ↓
QUOTE_APPROVED (both approvals complete)
    ↓
COMMITMENT_APPROVED (after supplier selection)
    ↓
PO_PENDING → INVOICE_RECEIVED → COMPLETED
```

### Rejection/Return Paths

Any stage can transition back to:
- `QUOTE_REVIEW_PENDING`: For major revisions
- `QUOTE_SPEC_REVIEW_PENDING`: For clarification requests

## New PHP Classes

### RFQQuoteApprovalService
Location: `/services/RFQQuoteApprovalService.php`

**Purpose**: Manages the quote approval workflow

**Key Methods:**
- `getPendingSpecReviews()`: Get RFQs awaiting specification review
- `getPendingBranchHeadApprovals()`: Get RFQs awaiting branch head approval
- `approveSpecReview($rfq_id, $comments)`: Approve specification review
- `rejectSpecReview($rfq_id, $reason)`: Reject specification review
- `approveBranchHeadApproval($rfq_id, $comments)`: Approve branch head
- `rejectBranchHeadApproval($rfq_id, $reason)`: Reject branch head approval
- `returnForClarification($rfq_id, $stage, $details)`: Return for clarification
- `getApprovalHistory($rfq_id)`: Get full approval history
- `getApprovalStatus($rfq_id)`: Get current approval status
- `isFullyApproved($rfq_id)`: Check if both approvals complete

**Features:**
- Transaction support for data consistency
- Automatic notification sending
- Audit trail logging
- Role-based access control

## New User Interface Pages

### 1. Specification Review Page
**Location**: `/rfq/spec_review_approve.php?id={rfq_id}`
**Permission Required**: `approve_rfq_spec_review`

**Features:**
- Display RFQ and vendor quotes awaiting review
- Show approval status and history
- Specification review decision form (approve/reject)
- Comments field for feedback
- Document download links for quotes

**Workflow Triggers:**
- Auto-assigns default spec reviewer when first quote uploaded
- Shows pending spec reviews in user dashboard
- Auto-routes to spec reviewer email notification

### 2. Branch Head Approval Page
**Location**: `/rfq/branch_head_approve.php?id={rfq_id}`
**Permission Required**: `approve_rfq_branch_head`

**Features:**
- Display RFQ with spec review findings
- Show all vendor quotes with spec review status
- Spec reviewer's detailed assessment
- Branch head decision form (approve/reject/clarify)
- Full approval history timeline
- Automatic notification to procurement team on approval

**Workflow Triggers:**
- Only accessible after spec review is approved
- Auto-routes to branch head email notification
- Prevents supplier selection until approved

## Automatic Routing and Notifications

### Quote Upload Trigger
When first quote is uploaded:
1. RFQ status changes to `QUOTE_SPEC_REVIEW_PENDING`
2. Default specification reviewer is auto-assigned (if configured)
3. Email notification sent to spec reviewer
4. Audit log entry created

### Spec Review Approval Trigger
When spec review is approved:
1. RFQ status changes to `QUOTE_SPEC_REVIEW_APPROVED`
2. Branch head approvers receive email notification
3. Approval logged in audit trail
4. Comments recorded in RFQ record

### Spec Review Rejection Trigger
When spec review is rejected:
1. RFQ status returns to `QUOTE_REVIEW_PENDING`
2. Requestor receives email with rejection reason
3. Quotes can be revised and re-submitted
4. Rejection logged in audit trail

### Branch Head Approval Trigger
When branch head approves:
1. RFQ status changes to `QUOTE_APPROVED`
2. Procurement team notified - can proceed with supplier selection
3. Approval logged in audit trail
4. Both stages marked as complete

### Branch Head Rejection Trigger
When branch head rejects:
1. RFQ status marked as rejected
2. Requestor and spec reviewer notified
3. Full rejection logged with reason
4. RFQ marked as cannot proceed

## Permissions System

### New Permissions Required

| Permission | Description | Typical Roles |
|-----------|-------------|---------------|
| `approve_rfq_spec_review` | Review and approve/reject quotes for spec compliance | Specification Reviewer, Procurement Officer, HOD |
| `approve_rfq_branch_head` | Provide final approval for quotes | Branch Head, HOD, Director HRM&A |
| `assign_rfq_spec_reviewer` | Assign specification reviewers to RFQs | Admin, SuperAdmin, Procurement Officer |
| `assign_rfq_branch_head_approver` | Assign branch head approvers to RFQs | Admin, SuperAdmin, Director HRM&A |
| `view_rfq_approval_audit` | View approval audit trail | All users |
| `admin_override_approvals` | Bypass approval assignment restrictions | Admin, SuperAdmin |

### Permission Assignment by Role

**Procurement Officer:**
- approve_rfq_spec_review
- view_rfq_approval_audit
- view_requests
- upload_rfq_quote

**Specification Reviewer:**
- approve_rfq_spec_review
- view_rfq_approval_audit
- view_requests

**Branch Head:**
- approve_rfq_branch_head
- view_rfq_approval_audit
- view_requests

**HOD:**
- approve_rfq_spec_review
- approve_rfq_branch_head
- assign_rfq_spec_reviewer
- view_rfq_approval_audit
- view_requests

**Admin/SuperAdmin:**
- All permissions above
- admin_override_approvals

## Configuration

### Default Specification Reviewer Assignment
When first quote is uploaded, the system auto-assigns a specification reviewer based on this priority:
1. Pre-configured approver in `rfq_spec_reviewers` table
2. First active user with "Specification Reviewer" role
3. First active user with "Procurement Officer" role

### Branch Head Approver Assignment
Admins must manually assign branch head approvers via the `rfq_branch_head_approvers` table.

### Notification Channels
- Email notifications (via configured mailer)
- System notifications (in-application)
- Audit log entries for compliance

## Database Migrations

### Migration Files Created

1. **`2026_07_31_rfq_quote_approval_workflow.sql`**
   - Creates new approval workflow tables
   - Adds columns to existing tables
   - Creates indexes for performance
   - Defines stored procedures for approval workflows
   - Creates triggers for workflow automation

2. **`2026_07_31_rfq_approval_workflow_permissions.sql`**
   - Documents required permissions
   - Provides SQL for permission setup
   - Documents role assignments
   - Configuration notes

## Workflow Rules and Constraints

### Mandatory Rules
1. **No Direct Approval**: RFQs cannot skip spec review to go directly to branch head approval
2. **Sequential Approval**: Spec review must be approved before branch head approval begins
3. **Rejection Reverts**: Rejections at any stage return RFQ to submission state
4. **Audit Trail**: All actions are permanently recorded with user, timestamp, and details
5. **Commitment Blocking**: Commitments cannot be created until both approvals are complete

### Business Rules
1. **Requestor Notification**: Requestor is notified of rejection/clarification requests
2. **Spec Reviewer Assignment**: Must be assigned before spec review can proceed
3. **Branch Head Assignment**: Must be assigned before branch head review can proceed
4. **Approval Chain**: Cannot approve future stages until prior stages complete

## Integration Points

### RFQ Quote Upload (`/rfq/upload_quote.php`)
- Auto-initializes spec review workflow on first quote
- Auto-assigns default spec reviewer
- Sends notification to spec reviewer

### RFQ View Page (`/rfq/view.php`)
- Display current approval status
- Link to approval pages
- Show approval history
- Display relevant action buttons

### Procurement Request View (`/procurement/view.php`)
- Show approval workflow progress
- Quick links to approval pages
- Display approval status badges

### Audit Trail
- All approval actions logged
- User, role, and timestamp captured
- Comments and reasons recorded
- Searchable and reportable

## Maintenance and Administration

### Checking Approval Status
```php
$approvalService = new RFQQuoteApprovalService($pdo, $user_id, $role);
$status = $approvalService->getApprovalStatus($rfq_id);
$history = $approvalService->getApprovalHistory($rfq_id);
```

### Manual Override (Admin Only)
Admins with `admin_override_approvals` permission can:
- Bypass assignment requirements
- Approve RFQs not assigned to them
- Access approval pages for audit/correction

### Reassigning Approvers
Admins can change approver assignments:
```sql
UPDATE rfq_spec_reviewers SET is_active = 0 WHERE rfq_id = ? AND reviewer_id = ?;
INSERT INTO rfq_spec_reviewers (rfq_id, reviewer_id, reviewer_role, assigned_by, is_active)
VALUES (?, ?, ?, ?, 1);
```

## Error Handling

### Common Errors and Solutions

| Error | Cause | Solution |
|-------|-------|----------|
| "Not assigned as spec reviewer" | User not in `rfq_spec_reviewers` table | Admin assigns user to RFQ |
| "Spec review not approved" | Trying to approve branch head without spec approval | Complete spec review first |
| "Cannot create commitment" | Both approvals not complete | Ensure both stages approved |
| "No pending approvals" | Already approved/rejected | Cannot re-approve |

## Testing the Workflow

### Test Scenario 1: Full Approval Path
1. Create RFQ with quotes
2. Login as Spec Reviewer
3. Navigate to spec review approval page
4. Approve with comments
5. Login as Branch Head
6. Navigate to branch head approval page
7. Approve with comments
8. Verify RFQ status is `QUOTE_APPROVED`
9. Check audit trail has both approvals

### Test Scenario 2: Rejection Path
1. Create RFQ with quotes
2. Login as Spec Reviewer
3. Reject spec review with reason
4. Verify requestor receives notification
5. Upload revised quotes
6. Verify RFQ returns to spec review stage
7. Approve revised quotes
8. Complete branch head approval

### Test Scenario 3: Clarification Request
1. Create RFQ with quotes
2. Complete spec review approval
3. Login as Branch Head
4. Request clarification instead of approving
5. Verify requestor receives notification
6. Verify RFQ returns to appropriate stage

## Security Considerations

1. **Permission Checks**: All pages check `$REQUIRE_PERMISSION`
2. **Assignment Verification**: Users must be assigned to approve RFQs (unless admin)
3. **Audit Trail**: All actions permanently logged
4. **Transaction Support**: Database changes are atomic
5. **Email Validation**: Notification emails validated before sending
6. **Input Sanitization**: All user inputs sanitized with `he()` function

## Future Enhancements

Potential improvements for future versions:
1. **Escalation Rules**: Auto-escalate approvals if not completed within timeframe
2. **Approval Delegation**: Allow approvers to temporarily delegate authority
3. **Batch Approvals**: Approve multiple RFQs in one action
4. **Approval Templates**: Pre-defined comment templates
5. **SLA Tracking**: Track approval turnaround times
6. **Analytics Dashboard**: Approval pipeline metrics and reports

## Compliance and Governance

This implementation ensures:
- **Accountability**: All decisions tracked with user and timestamp
- **Governance**: Mandatory two-step approval before supplier selection
- **Audit Trail**: Complete history of all approvals and rejections
- **Segregation of Duties**: Spec reviewer separate from branch head approver
- **Control Points**: Cannot proceed without proper approvals
- **Documentation**: All reasons and comments recorded

## Support and Maintenance

### Logging
- Application logs: `/path/to/application/logs`
- Database logs: Server's MySQL error log
- PHP error log configured in `php.ini`

### Performance Considerations
- Indexes on approval status fields ensure fast queries
- Approval history can be archived periodically if table grows large
- Consider partitioning large rfq_quote_approvals tables by date

### Backup Strategy
- Include new tables in regular database backups
- Document approval workflow dependencies
- Test restore procedures regularly
