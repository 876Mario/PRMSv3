<?php
/**
 * Branch Head final approval for the selected RFQ quote.
 */

$REQUIRE_PERMISSION = 'approve_branch_head_award';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/services/RFQQuoteApprovalService.php';

$rfq_id = (int)($_GET['id'] ?? 0);
if ($rfq_id <= 0) {
    pop('Invalid RFQ ID', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$stmt = $pdo->prepare(
    "SELECT r.*, pr.request_id, pr.request_number, pr.description, pr.estimated_value,
            pr.status AS request_status, pr.created_by AS requestor_user_id, pr.branch_id,
            req.full_name AS requestor_name, rr.full_name AS requestor_reviewer_name,
            b.branch_name
     FROM rfqs r
     JOIN procurement_requests pr ON pr.request_id = r.request_id
     LEFT JOIN users req ON req.user_id = pr.created_by
     LEFT JOIN users rr ON rr.user_id = r.requestor_reviewer_id
     LEFT JOIN branches b ON b.branch_id = pr.branch_id
     WHERE r.rfq_id = ?"
);
$stmt->execute([$rfq_id]);
$rfq = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rfq) {
    pop('RFQ not found', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$isHrmaBranch = (int)($rfq['branch_id'] ?? 0) === 5 || stripos((string)($rfq['branch_name'] ?? ''), 'HRM') !== false;
$branchHeadStmt = $pdo->prepare(
    $isHrmaBranch
        ? "SELECT u.user_id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'Director HRM&A' AND u.is_active = 1"
        : "SELECT u.user_id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name IN ('HOD','Branch Head') AND u.branch_id = ? AND u.is_active = 1"
);
$branchHeadStmt->execute($isHrmaBranch ? [] : [(int)$rfq['branch_id']]);
$allowedUserIds = array_map('intval', $branchHeadStmt->fetchAll(PDO::FETCH_COLUMN));
$canOverride = hasPermission('override_branch_head_approval') || hasPermission('admin_override_approvals');
if (!in_array((int)($_SESSION['user_id'] ?? 0), $allowedUserIds, true) && !$canOverride) {
    pop('Only the auto-routed Branch Head may act on this approval.', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$quoteStmt = $pdo->prepare(
    "SELECT q.quote_id, q.quote_amount, q.gct_amount, q.quote_file, q.review_status,
            q.review_comments, q.submitted_at, q.is_selected,
            v.vendor_name, v.contact_person, v.email
     FROM rfq_quotes q
     JOIN rfq_vendors rv ON rv.rfq_vendor_id = q.rfq_vendor_id
     JOIN vendors v ON v.vendor_id = rv.vendor_id
     WHERE rv.rfq_id = ?
       AND COALESCE(q.is_deleted, 0) = 0
     ORDER BY q.is_selected DESC, q.quote_amount ASC"
);
$quoteStmt->execute([$rfq_id]);
$quotes = $quoteStmt->fetchAll(PDO::FETCH_ASSOC);
$selectedQuote = null;
foreach ($quotes as $quoteRow) {
    if ((int)($quoteRow['is_selected'] ?? 0) === 1) {
        $selectedQuote = $quoteRow;
        break;
    }
}

$approvalService = new RFQQuoteApprovalService($pdo, (int)$_SESSION['user_id'], $_SESSION['role_name'] ?? '');
$approvalStatus = $approvalService->getApprovalStatus($rfq_id);
$approvalHistory = $approvalService->getApprovalHistory($rfq_id);
$requestorReviewHistory = $approvalService->getRequestorReviewHistory($rfq_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $decision = strtoupper(trim((string)($_POST['decision'] ?? '')));
    $comments = trim((string)($_POST['comments'] ?? ''));
    $confirmApproval = isset($_POST['confirm_independent_decision']) && $_POST['confirm_independent_decision'] === '1';
    $overrideReason = trim((string)($_POST['override_reason'] ?? ''));

    try {
        $success = $approvalService->decideBranchHeadApproval(
            $rfq_id,
            $decision,
            $comments,
            $selectedQuote['quote_id'] ?? null,
            $confirmApproval,
            $overrideReason
        );

        if ($success) {
            $message = match ($decision) {
                'APPROVE' => 'Branch Head approval recorded. The RFQ is now fully approved.',
                'RETURN_FOR_CLARIFICATION' => 'RFQ returned to the requestor for clarification.',
                default => 'RFQ rejected and returned to procurement review.',
            };
            pop($message, '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'success');
            exit;
        }
    } catch (Throwable $e) {
        pop('Error: ' . $e->getMessage(), '/rfq/branch_head_approve.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }
}
?>
<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>

<div class="container-fluid my-4">
    <div class="row mb-4">
        <div class="col-md-12">
            <h2 class="mb-1"><i class="bi bi-shield-check"></i> Branch Head Final Approval - RFQ <?= he($rfq['rfq_number']) ?></h2>
            <p class="text-muted mb-0">Review the requestor's specification confirmation and record the final Branch Head decision.</p>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header bg-light"><h5 class="mb-0">Request Details</h5></div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr><th width="30%">Request Number</th><td><?= he($rfq['request_number']) ?></td></tr>
                        <tr><th>Requestor</th><td><?= he($rfq['requestor_name'] ?? 'N/A') ?></td></tr>
                        <tr><th>Branch</th><td><?= he($rfq['branch_name'] ?? 'N/A') ?></td></tr>
                        <tr><th>Description</th><td><?= nl2br(he($rfq['description'])) ?></td></tr>
                        <tr><th>Estimated Value</th><td><?= formatCurrency((float)$rfq['estimated_value']) ?></td></tr>
                        <tr><th>Selected Vendor</th><td><?= he($selectedQuote['vendor_name'] ?? 'No quote selected') ?></td></tr>
                        <tr><th>Selected Quote</th><td><?= $selectedQuote ? formatCurrency((float)$selectedQuote['quote_amount']) : '—' ?></td></tr>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header bg-light"><h5 class="mb-0">Approval Status</h5></div>
                <div class="card-body">
                    <div class="mb-3">
                        <strong>Requestor Review:</strong>
                        <span class="badge <?= ($approvalStatus['requestor_spec_review_status'] ?? 'PENDING') === 'APPROVED' ? 'bg-success' : ((($approvalStatus['requestor_spec_review_status'] ?? 'PENDING') === 'REJECTED') ? 'bg-danger' : 'bg-warning text-dark') ?>">
                            <?= he($approvalStatus['requestor_spec_review_status'] ?? 'PENDING') ?>
                        </span>
                    </div>
                    <div>
                        <strong>Branch Head Approval:</strong>
                        <span class="badge <?= ($approvalStatus['branch_head_approval_status'] ?? 'PENDING') === 'APPROVED' ? 'bg-success' : ((($approvalStatus['branch_head_approval_status'] ?? 'PENDING') === 'REJECTED') ? 'bg-danger' : 'bg-warning text-dark') ?>">
                            <?= he($approvalStatus['branch_head_approval_status'] ?? 'PENDING') ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card border-info">
                <div class="card-header bg-info text-white"><h5 class="mb-0">Requestor Specification Confirmation</h5></div>
                <div class="card-body">
                    <?php $latestRequestorReview = $requestorReviewHistory[0] ?? null; ?>
                    <?php if ($latestRequestorReview): ?>
                        <p class="mb-2"><strong>Outcome:</strong> <?= he(str_replace('_', ' ', $latestRequestorReview['review_outcome'])) ?></p>
                        <p class="mb-2"><strong>Comments:</strong><br><?= nl2br(he($latestRequestorReview['comments'] ?? '')) ?></p>
                        <small class="text-muted">Submitted by <?= he($latestRequestorReview['reviewer_name'] ?? $rfq['requestor_reviewer_name'] ?? 'N/A') ?> on <?= formatDate($latestRequestorReview['review_date']) ?></small>
                    <?php else: ?>
                        <p class="text-muted mb-0">No requestor review history has been recorded yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header bg-light"><h5 class="mb-0">Vendor Quotations</h5></div>
                <div class="card-body">
                    <?php if (!empty($quotes)): ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead class="table-light"><tr><th>Vendor</th><th>Amount</th><th>Review</th><th>Document</th></tr></thead>
                                <tbody>
                                <?php foreach ($quotes as $quote): ?>
                                    <tr class="<?= (int)($quote['is_selected'] ?? 0) === 1 ? 'table-success' : '' ?>">
                                        <td>
                                            <strong><?= he($quote['vendor_name']) ?></strong>
                                            <?php if ((int)($quote['is_selected'] ?? 0) === 1): ?><span class="badge bg-success ms-1">Selected</span><?php endif; ?>
                                        </td>
                                        <td><?= formatCurrency((float)$quote['quote_amount']) ?></td>
                                        <td><?= he(str_replace('_', ' ', $quote['review_status'] ?? 'PENDING')) ?></td>
                                        <td>
                                            <?php if (!empty($quote['quote_file'])): ?>
                                                <a href="/uploads/quotes/<?= urlencode($quote['quote_file']) ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-download"></i></a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-0">No quotations found for this RFQ.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header bg-light"><h5 class="mb-0">Approval History</h5></div>
                <div class="card-body">
                    <?php if (!empty($approvalHistory)): ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead class="table-light"><tr><th>Date</th><th>Stage</th><th>Decision</th><th>By</th><th>Comments</th></tr></thead>
                                <tbody>
                                <?php foreach ($approvalHistory as $history): ?>
                                    <tr>
                                        <td><?= formatDate($history['created_at']) ?></td>
                                        <td><?= he(str_replace('_', ' ', $history['approval_stage'])) ?></td>
                                        <td><?= he(str_replace('_', ' ', $history['action'])) ?></td>
                                        <td><?= he($history['approver_name'] ?? 'N/A') ?></td>
                                        <td><?= nl2br(he($history['comments'] ?? $history['rejection_reason'] ?? '')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-0">No approval history recorded yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if (($rfq['request_status'] ?? '') === 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING'): ?>
        <div class="row">
            <div class="col-lg-8 offset-lg-2">
                <div class="card">
                    <div class="card-header bg-primary text-white"><h5 class="mb-0">Record Branch Head Decision</h5></div>
                    <div class="card-body">
                        <form method="POST" action="/rfq/branch_head_approve.php?id=<?= (int)$rfq_id ?>" id="branchHeadDecisionForm">
                            <div class="mb-3">
                                <label class="form-label"><strong>Decision</strong></label>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="decision" id="decision_approve" value="APPROVE" required>
                                    <label class="form-check-label" for="decision_approve">Approve</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="decision" id="decision_reject" value="REJECT">
                                    <label class="form-check-label" for="decision_reject">Reject</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="decision" id="decision_return" value="RETURN_FOR_CLARIFICATION">
                                    <label class="form-check-label" for="decision_return">Return for Clarification</label>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="comments" class="form-label"><strong>Comments</strong> <span class="text-danger" id="branchCommentsMarker" style="display:none;">*</span></label>
                                <textarea class="form-control" id="comments" name="comments" rows="5" placeholder="Provide the rationale for your decision."></textarea>
                                <small class="text-muted">Comments are required for Reject and Return for Clarification.</small>
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="confirm_independent_decision" id="confirm_independent_decision" value="1">
                                <label class="form-check-label" for="confirm_independent_decision">
                                    I confirm I have reviewed the requestor's specification confirmation and the vendor quotation and am making this decision independently.
                                </label>
                            </div>
                            <?php if ($canOverride && !in_array((int)($_SESSION['user_id'] ?? 0), $allowedUserIds, true)): ?>
                                <div class="mb-3">
                                    <label for="override_reason" class="form-label"><strong>Override Reason</strong> <span class="text-danger">*</span></label>
                                    <textarea class="form-control" id="override_reason" name="override_reason" rows="3" placeholder="Explain why you are acting on behalf of the routed Branch Head." required></textarea>
                                </div>
                            <?php endif; ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> Reject returns the RFQ to procurement quote review. Return for Clarification sends the selected quotation back to the requestor for another confirmation.
                            </div>
                            <div class="d-flex justify-content-end gap-2">
                                <a href="/rfq/list.php" class="btn btn-secondary">Cancel</a>
                                <button type="submit" class="btn btn-primary">Submit Branch Head Decision</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="row"><div class="col-lg-8 offset-lg-2"><div class="alert alert-secondary">This RFQ is currently at <strong><?= he($rfq['request_status']) ?></strong> and is not awaiting Branch Head approval.</div></div></div>
    <?php endif; ?>
</div>

<script>
(function () {
    const form = document.getElementById('branchHeadDecisionForm');
    if (!form) return;
    const approve = document.getElementById('decision_approve');
    const reject = document.getElementById('decision_reject');
    const ret = document.getElementById('decision_return');
    const comments = document.getElementById('comments');
    const confirmBox = document.getElementById('confirm_independent_decision');
    const marker = document.getElementById('branchCommentsMarker');

    function syncRequirements() {
        const commentsRequired = reject.checked || ret.checked;
        comments.required = commentsRequired;
        if (marker) {
            marker.style.display = commentsRequired ? 'inline' : 'none';
        }
        if (approve.checked) {
            confirmBox.required = true;
        } else {
            confirmBox.required = false;
            if (!reject.checked && !ret.checked) {
                confirmBox.checked = false;
            }
        }
    }

    approve.addEventListener('change', syncRequirements);
    reject.addEventListener('change', syncRequirements);
    ret.addEventListener('change', syncRequirements);
    syncRequirements();
})();
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
