<?php
/**
 * RFQ Specification Review and Approval
 * Allows designated reviewers to approve/reject quotations
 * for specification compliance
 */

$REQUIRE_PERMISSION = 'approve_rfq_spec_review';
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
    SELECT r.*, pr.request_number, pr.description, pr.estimated_value, u.display_name as created_by_name
    FROM rfqs r
    JOIN procurement_requests pr ON r.request_id = pr.request_id
    LEFT JOIN users u ON r.created_by = u.user_id
    WHERE r.rfq_id = ?
");
$stmt->execute([$rfq_id]);
$rfq = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rfq) {
    pop('RFQ not found', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

// Check if user can review this RFQ
// Should either be assigned as spec reviewer or have override permission
$stmt = $pdo->prepare("
    SELECT * FROM rfq_spec_reviewers
    WHERE rfq_id = ? AND reviewer_id = ? AND is_active = 1
");
$stmt->execute([$rfq_id, $_SESSION['user_id']]);
$assignment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$assignment && !hasPermission('admin_override_approvals')) {
    pop('You are not assigned as a specification reviewer for this RFQ', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
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
    if (!in_array($action, ['approve', 'reject'])) {
        pop('Invalid action', '/rfq/spec_review_approve.php?id='.$rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    if ($action === 'reject' && strlen($comments) < 5) {
        pop('Rejection reason must be at least 5 characters', '/rfq/spec_review_approve.php?id='.$rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    // Execute approval
    try {
        $success = false;
        if ($action === 'approve') {
            $success = $approvalService->approveSpecReview($rfq_id, $comments);
        } else {
            $success = $approvalService->rejectSpecReview($rfq_id, $comments);
        }

        if ($success) {
            pop(
                $action === 'approve' 
                    ? 'Specification review approved. RFQ forwarded to Branch Head for final approval.'
                    : 'Specification review rejected. Requestor has been notified.',
                '/rfq/list.php',
                POP_DEFAULT_DELAY_MS,
                'success'
            );
            exit;
        } else {
            pop('An error occurred while processing your approval', '/rfq/spec_review_approve.php?id='.$rfq_id, POP_DEFAULT_DELAY_MS, 'error');
            exit;
        }
    } catch (Exception $e) {
        pop('Error: ' . $e->getMessage(), '/rfq/spec_review_approve.php?id='.$rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }
}

?>
<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>

<div class="container-fluid my-4">
    <div class="row">
        <div class="col-md-12">
            <h2 class="mb-4">
                <i class="bi bi-clipboard-check"></i> Specification Review - RFQ <?= he($rfq['rfq_number']) ?>
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
                            <th>RFQ Created By:</th>
                            <td><?= he($rfq['created_by_name']) ?></td>
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
                        <span class="badge <?= $approvalStatus['spec_review_status'] === 'APPROVED' ? 'bg-success' : ($approvalStatus['spec_review_status'] === 'REJECTED' ? 'bg-danger' : 'bg-warning') ?>">
                            <?= he($approvalStatus['spec_review_status']) ?>
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

    <!-- Quotes for Review -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header bg-light">
                    <h5 class="mb-0">Vendor Quotes (<?= count($quotes) ?> received)</h5>
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
                                        <th>Review Status</th>
                                        <th>Comments</th>
                                        <th>Document</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($quotes as $quote): ?>
                                        <tr>
                                            <td>
                                                <strong><?= he($quote['vendor_name']) ?></strong><br>
                                                <small class="text-muted"><?= he($quote['contact_person']) ?></small><br>
                                                <small class="text-muted"><?= he($quote['email']) ?></small>
                                            </td>
                                            <td><?= formatCurrency($quote['quote_amount']) ?></td>
                                            <td><?= formatCurrency($quote['gct_amount']) ?></td>
                                            <td><small><?= formatDate($quote['submitted_at']) ?></small></td>
                                            <td>
                                                <span class="badge <?= $quote['review_status'] === 'MEETS_REQUIREMENTS' ? 'bg-success' : ($quote['review_status'] === 'DOES_NOT_MEET' ? 'bg-danger' : 'bg-secondary') ?>">
                                                    <?= str_replace('_', ' ', $quote['review_status']) ?>
                                                </span>
                                            </td>
                                            <td><?= he(substr($quote['review_comments'] ?? '', 0, 50)) ?></td>
                                            <td>
                                                <?php if ($quote['quote_file']): ?>
                                                    <a href="/uploads/quotes/<?= urlencode($quote['quote_file']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                        <i class="bi bi-download"></i> Download
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
                                                <?= he($history['action']) ?>
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
    <?php if ($approvalStatus['spec_review_status'] === 'PENDING'): ?>
        <div class="row">
            <div class="col-md-8 offset-md-2">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Provide Specification Review Decision</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="/rfq/spec_review_approve.php?id=<?= (int)$rfq_id ?>">
                            <div class="form-group mb-3">
                                <label for="action" class="form-label"><strong>Action</strong></label>
                                <div class="btn-group w-100" role="group">
                                    <input type="radio" class="btn-check" name="action" id="action_approve" value="approve" required>
                                    <label class="btn btn-outline-success" for="action_approve">
                                        <i class="bi bi-check-circle"></i> Approve - Quotes Meet Requirements
                                    </label>
                                    
                                    <input type="radio" class="btn-check" name="action" id="action_reject" value="reject">
                                    <label class="btn btn-outline-danger" for="action_reject">
                                        <i class="bi bi-x-circle"></i> Reject - Return for Revision
                                    </label>
                                </div>
                            </div>

                            <div class="form-group mb-3">
                                <label for="comments" class="form-label">
                                    <strong>Comments</strong>
                                    <span class="text-danger">*</span>
                                </label>
                                <textarea class="form-control" id="comments" name="comments" rows="5" 
                                    placeholder="Provide your review comments, findings, or reasons for rejection..." required></textarea>
                                <small class="form-text text-muted">
                                    For approvals: Summarize your findings
                                    For rejections: Explain what needs to be corrected
                                </small>
                            </div>

                            <div class="alert alert-info mb-3">
                                <i class="bi bi-info-circle"></i> 
                                <strong>Note:</strong> Once you approve this specification review, the RFQ will be automatically routed to the Branch Head for final approval.
                            </div>

                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <a href="/rfq/list.php" class="btn btn-secondary">Cancel</a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check"></i> Submit Review Decision
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Already Approved/Rejected Messages -->
    <?php if ($approvalStatus['spec_review_status'] !== 'PENDING'): ?>
        <div class="row">
            <div class="col-md-8 offset-md-2">
                <div class="alert <?= $approvalStatus['spec_review_status'] === 'APPROVED' ? 'alert-success' : 'alert-warning' ?>" role="alert">
                    <h4 class="alert-heading">
                        <?= $approvalStatus['spec_review_status'] === 'APPROVED' ? 'Specification Review Approved' : 'Specification Review Rejected' ?>
                    </h4>
                    <p>
                        This RFQ has already been reviewed and <?= strtolower($approvalStatus['spec_review_status']) ?>.
                        The decision is final and cannot be changed.
                    </p>
                    <?php if ($approvalStatus['spec_review_status'] === 'APPROVED'): ?>
                        <p>The RFQ is now awaiting Branch Head final approval.</p>
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
</style>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
