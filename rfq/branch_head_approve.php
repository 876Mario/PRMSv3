<?php
/**
 * RFQ Branch Head Final Approval
 * Allows branch heads to provide final approval for RFQ quotes
 * after specification review has been approved
 */

$REQUIRE_PERMISSION = 'approve_rfq_branch_head';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/services/RFQQuoteApprovalService.php';

// Get RFQ ID from URL
$rfq_id = (int)($_GET['id'] ?? 0);

if ($rfq_id <= 0) {
    pop('Invalid RFQ ID', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

// Fetch RFQ details
$stmt = $pdo->prepare("
    SELECT r.*, pr.request_number, pr.description, pr.estimated_value, pr.branch_id,
           u.display_name as created_by_name,
           sr.display_name as spec_reviewer_name
    FROM rfqs r
    JOIN procurement_requests pr ON r.request_id = pr.request_id
    LEFT JOIN users u ON r.created_by = u.user_id
    LEFT JOIN users sr ON r.spec_reviewer_id = sr.user_id
    WHERE r.rfq_id = ?
");
$stmt->execute([$rfq_id]);
$rfq = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rfq) {
    pop('RFQ not found', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

// Check if user can approve this RFQ at branch head level
// Should either be assigned as branch head approver or have override permission
$stmt = $pdo->prepare("
    SELECT * FROM rfq_branch_head_approvers
    WHERE rfq_id = ? AND approver_id = ? AND is_active = 1
");
$stmt->execute([$rfq_id, $_SESSION['user_id']]);
$assignment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$assignment && !hasPermission('admin_override_approvals')) {
    pop('You are not assigned as a branch head approver for this RFQ', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

// Verify that spec review has been approved (prerequisite)
if ($rfq['spec_review_status'] !== 'APPROVED') {
    pop(
        'Specification review must be approved before branch head approval can proceed.',
        '/rfq/view.php?id='.$rfq_id,
        POP_DEFAULT_DELAY_MS,
        'error'
    );
    exit;
}

// Fetch all quotes for this RFQ
$stmt = $pdo->prepare("
    SELECT 
        q.quote_id,
        q.quote_amount,
        q.gct_amount,
        q.quote_file,
        q.review_status,
        q.review_comments,
        q.submitted_at,
        q.is_selected,
        rv.rfq_vendor_id,
        v.vendor_name,
        v.contact_person,
        v.email
    FROM rfq_quotes q
    JOIN rfq_vendors rv ON q.rfq_vendor_id = rv.rfq_vendor_id
    JOIN vendors v ON rv.vendor_id = v.vendor_id
    WHERE rv.rfq_id = ?
    ORDER BY q.quote_amount ASC
");
$stmt->execute([$rfq_id]);
$quotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get approval status and history
$approvalService = new RFQQuoteApprovalService($pdo, $_SESSION['user_id'], $_SESSION['role_name'] ?? '');
$approvalStatus = $approvalService->getApprovalStatus($rfq_id);
$approvalHistory = $approvalService->getApprovalHistory($rfq_id);

// Handle POST - Approve or Reject
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $comments = trim($_POST['comments'] ?? '');

    // Validate inputs
    if (!in_array($action, ['approve', 'reject', 'clarify'])) {
        pop('Invalid action', '/rfq/branch_head_approve.php?id='.$rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    if ($action !== 'approve' && strlen($comments) < 5) {
        pop('Reason/comments must be at least 5 characters for reject or clarification request', 
            '/rfq/branch_head_approve.php?id='.$rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    // Execute approval
    try {
        $success = false;
        if ($action === 'approve') {
            $success = $approvalService->approveBranchHeadApproval($rfq_id, $comments);
            if ($success) {
                pop('RFQ approved! Quotes are ready for supplier selection.', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'success');
            }
        } elseif ($action === 'reject') {
            $success = $approvalService->rejectBranchHeadApproval($rfq_id, $comments);
            if ($success) {
                pop('RFQ has been rejected. Requestor and specification reviewer have been notified.', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'success');
            }
        } elseif ($action === 'clarify') {
            $success = $approvalService->returnForClarification($rfq_id, 'BRANCH_HEAD_APPROVAL', $comments);
            if ($success) {
                pop('RFQ has been returned for clarification. Requestor has been notified.', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'success');
            }
        }

        if (!$success) {
            pop('An error occurred while processing your approval', '/rfq/branch_head_approve.php?id='.$rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        }
        exit;
    } catch (Exception $e) {
        pop('Error: ' . $e->getMessage(), '/rfq/branch_head_approve.php?id='.$rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }
}

?>
<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>

<div class="container-fluid my-4">
    <div class="row">
        <div class="col-md-12">
            <h2 class="mb-4">
                <i class="bi bi-clipboard-check"></i> Branch Head Final Approval - RFQ <?= he($rfq['rfq_number']) ?>
            </h2>
        </div>
    </div>

    <!-- RFQ Summary -->
    <div class="row mb-4">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header bg-light">
                    <h5 class="mb-0">Request Details</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr>
                            <th width="30%">Request Number:</th>
                            <td><?= he($rfq['request_number']) ?></td>
                        </tr>
                        <tr>
                            <th>Description:</th>
                            <td><?= he($rfq['description']) ?></td>
                        </tr>
                        <tr>
                            <th>Estimated Value:</th>
                            <td><?= formatCurrency($rfq['estimated_value']) ?></td>
                        </tr>
                        <tr>
                            <th>Request Created By:</th>
                            <td><?= he($rfq['created_by_name']) ?></td>
                        </tr>
                        <tr>
                            <th>Specification Reviewer:</th>
                            <td><?= he($rfq['spec_reviewer_name']) ?></td>
                        </tr>
                        <tr>
                            <th>Spec Review Completed:</th>
                            <td><?= formatDate($rfq['spec_reviewed_at']) ?></td>
                        </tr>
                        <tr>
                            <th>Submission Deadline:</th>
                            <td><?= he($rfq['submission_deadline']) ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- Approval Status -->
        <div class="col-md-4">
            <div class="card">
                <div class="card-header bg-light">
                    <h5 class="mb-0">Approval Status</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <strong>Specification Review:</strong>
                        <span class="badge bg-success">
                            <i class="bi bi-check-circle"></i> APPROVED
                        </span>
                    </div>
                    <div>
                        <strong>Branch Head Approval:</strong>
                        <span class="badge <?= $approvalStatus['branch_head_approval_status'] === 'APPROVED' ? 'bg-success' : ($approvalStatus['branch_head_approval_status'] === 'REJECTED' ? 'bg-danger' : 'bg-warning') ?>">
                            <?= he($approvalStatus['branch_head_approval_status']) ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quotes Review Summary -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header bg-light">
                    <h5 class="mb-0">Vendor Quotes Summary (<?= count($quotes) ?> received)</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($quotes)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Vendor</th>
                                        <th>Quote Amount</th>
                                        <th>GCT</th>
                                        <th>Submitted</th>
                                        <th>Spec Review</th>
                                        <th>Spec Comments</th>
                                        <th>Document</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($quotes as $quote): ?>
                                        <tr class="<?= $quote['review_status'] === 'MEETS_REQUIREMENTS' ? 'table-success' : 'table-warning' ?>">
                                            <td>
                                                <strong><?= he($quote['vendor_name']) ?></strong><br>
                                                <small class="text-muted"><?= he($quote['contact_person']) ?></small><br>
                                                <small class="text-muted"><?= he($quote['email']) ?></small>
                                            </td>
                                            <td><?= formatCurrency($quote['quote_amount']) ?></td>
                                            <td><?= formatCurrency($quote['gct_amount']) ?></td>
                                            <td><small><?= formatDate($quote['submitted_at']) ?></small></td>
                                            <td>
                                                <span class="badge <?= $quote['review_status'] === 'MEETS_REQUIREMENTS' ? 'bg-success' : 'bg-warning' ?>">
                                                    <?= str_replace('_', ' ', $quote['review_status']) ?>
                                                </span>
                                            </td>
                                            <td><small><?= he(substr($quote['review_comments'] ?? '', 0, 50)) ?></small></td>
                                            <td>
                                                <?php if ($quote['quote_file']): ?>
                                                    <a href="/uploads/quotes/<?= urlencode($quote['quote_file']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                        <i class="bi bi-download"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-muted">No quotes have been submitted yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Specification Review Comments -->
    <?php if ($rfq['spec_review_comments']): ?>
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card border-info">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">Specification Reviewer's Assessment</h5>
                    </div>
                    <div class="card-body">
                        <p><?= nl2br(he($rfq['spec_review_comments'])) ?></p>
                        <small class="text-muted">
                            Reviewed by: <?= he($rfq['spec_reviewer_name']) ?> on <?= formatDate($rfq['spec_reviewed_at']) ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Approval History -->
    <?php if (!empty($approvalHistory)): ?>
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">Approval History</h5>
                    </div>
                    <div class="card-body">
                        <div class="timeline">
                            <?php foreach ($approvalHistory as $history): ?>
                                <div class="timeline-item mb-3">
                                    <div class="timeline-marker <?= $history['action'] === 'APPROVED' ? 'bg-success' : 'bg-danger' ?>"></div>
                                    <div class="timeline-content">
                                        <h6 class="mb-1">
                                            <?= he($history['approver_name']) ?>
                                            <span class="badge <?= $history['action'] === 'APPROVED' ? 'bg-success' : 'bg-danger' ?>">
                                                <?= str_replace('_', ' ', $history['action']) ?>
                                            </span>
                                        </h6>
                                        <p class="text-muted mb-1"><small><?= formatDate($history['created_at']) ?></small></p>
                                        <p class="mb-0"><?= he($history['comments'] ?? $history['rejection_reason'] ?? '') ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Approval Form -->
    <?php if ($approvalStatus['branch_head_approval_status'] === 'PENDING'): ?>
        <div class="row">
            <div class="col-md-8 offset-md-2">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Provide Branch Head Final Approval Decision</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="/rfq/branch_head_approve.php?id=<?= (int)$rfq_id ?>">
                            <div class="form-group mb-3">
                                <label for="action" class="form-label"><strong>Action</strong></label>
                                <div class="btn-group-vertical w-100" role="group">
                                    <input type="radio" class="btn-check" name="action" id="action_approve" value="approve" required>
                                    <label class="btn btn-outline-success text-start" for="action_approve">
                                        <i class="bi bi-check-circle"></i> <strong>Approve</strong> - Grant final approval for supplier selection
                                    </label>
                                    
                                    <input type="radio" class="btn-check" name="action" id="action_clarify" value="clarify">
                                    <label class="btn btn-outline-warning text-start" for="action_clarify">
                                        <i class="bi bi-question-circle"></i> <strong>Request Clarification</strong> - Return for clarification
                                    </label>
                                    
                                    <input type="radio" class="btn-check" name="action" id="action_reject" value="reject">
                                    <label class="btn btn-outline-danger text-start" for="action_reject">
                                        <i class="bi bi-x-circle"></i> <strong>Reject</strong> - Reject entire RFQ process
                                    </label>
                                </div>
                            </div>

                            <div class="form-group mb-3">
                                <label for="comments" class="form-label">
                                    <strong>Comments</strong>
                                    <span class="text-danger">*</span> (Required for reject or clarification)
                                </label>
                                <textarea class="form-control" id="comments" name="comments" rows="5" 
                                    placeholder="Provide your decision details..."></textarea>
                                <small class="form-text text-muted">
                                    For approval: Optional comments confirming your recommendation
                                    For clarification: Specify what needs clarification
                                    For rejection: Explain your reasons for rejection
                                </small>
                            </div>

                            <div class="alert alert-info mb-3">
                                <i class="bi bi-info-circle"></i> 
                                <strong>Note:</strong> As Branch Head, your decision serves as the final approval gate for this RFQ. 
                                Once approved, the procurement team can proceed with supplier selection and award.
                            </div>

                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <a href="/rfq/list.php" class="btn btn-secondary">Cancel</a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check"></i> Submit Decision
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Already Approved/Rejected Messages -->
    <?php if ($approvalStatus['branch_head_approval_status'] !== 'PENDING'): ?>
        <div class="row">
            <div class="col-md-8 offset-md-2">
                <div class="alert <?= $approvalStatus['branch_head_approval_status'] === 'APPROVED' ? 'alert-success' : 'alert-danger' ?>" role="alert">
                    <h4 class="alert-heading">
                        <?= $approvalStatus['branch_head_approval_status'] === 'APPROVED' ? 'Branch Head Approval Granted' : 'Branch Head Approval Rejected' ?>
                    </h4>
                    <p>
                        This RFQ has already been reviewed and <?= strtolower($approvalStatus['branch_head_approval_status']) ?> 
                        by the branch head. The decision is final and cannot be changed.
                    </p>
                    <?php if ($approvalStatus['branch_head_approval_status'] === 'APPROVED'): ?>
                        <p><strong>The RFQ is now ready for supplier selection.</strong></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
.timeline {
    position: relative;
    padding: 0;
}

.timeline-item {
    display: flex;
    position: relative;
    padding-left: 40px;
}

.timeline-marker {
    position: absolute;
    left: 0;
    top: 0;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    border: 2px solid #fff;
}

.timeline-content {
    flex: 1;
}

.btn-group-vertical .btn {
    border-radius: 0;
    text-align: left;
    padding: 12px;
}

.btn-group-vertical .btn:first-child {
    border-radius: 4px 4px 0 0;
}

.btn-group-vertical .btn:last-child {
    border-radius: 0 0 4px 4px;
}

.btn-group-vertical .btn-check:checked + .btn {
    background-color: #f8f9fa;
    border-color: #0d6efd;
    z-index: 1;
}
</style>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
