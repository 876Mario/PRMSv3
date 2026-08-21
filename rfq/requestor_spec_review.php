<?php
/**
 * Requestor Specification Confirmation
 * The original procurement requestor confirms whether the selected quotation
 * meets the original requirements before Branch Head approval.
 */

$REQUIRE_PERMISSION = 'submit_requestor_spec_review';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/services/RequestorSpecificationReviewService.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/services/RFQQuoteApprovalService.php';

$rfq_id = (int)($_GET['id'] ?? 0);
if ($rfq_id <= 0) {
    pop('Invalid RFQ ID', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$stmt = $pdo->prepare(
    "SELECT r.*, pr.request_id, pr.request_number, pr.description, pr.estimated_value,
            pr.created_by AS requestor_user_id, pr.status AS request_status,
            req.full_name AS requestor_name, rfq_owner.full_name AS rfq_created_by_name,
            b.branch_name
     FROM rfqs r
     JOIN procurement_requests pr ON pr.request_id = r.request_id
     LEFT JOIN users req ON req.user_id = pr.created_by
     LEFT JOIN users rfq_owner ON rfq_owner.user_id = r.created_by
     LEFT JOIN branches b ON b.branch_id = pr.branch_id
     WHERE r.rfq_id = ?"
);
$stmt->execute([$rfq_id]);
$rfq = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rfq) {
    pop('RFQ not found', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$isRequestor = (int)($rfq['requestor_user_id'] ?? 0) === (int)($_SESSION['user_id'] ?? 0);
$canOverride = hasPermission('override_requestor_review') || hasPermission('admin_override_approvals');
if (!$isRequestor && !$canOverride) {
    pop('Only the original requestor may complete this specification confirmation.', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$itemStmt = $pdo->prepare(
    "SELECT item_name, specification, quantity, remarks
     FROM procurement_request_items
     WHERE request_id = ?
     ORDER BY item_id ASC"
);
$itemStmt->execute([(int)$rfq['request_id']]);
$requestItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

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

$reviewService = new RequestorSpecificationReviewService($pdo, (int)$_SESSION['user_id'], $_SESSION['role_name'] ?? '');
$approvalService = new RFQQuoteApprovalService($pdo, (int)$_SESSION['user_id'], $_SESSION['role_name'] ?? '');
$reviewHistory = $reviewService->getRequestorReviewHistory($rfq_id);
$approvalHistory = $approvalService->getApprovalHistory($rfq_id);
$approvalStatus = $approvalService->getApprovalStatus($rfq_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $outcome = trim((string)($_POST['review_outcome'] ?? ''));
    $comments = trim((string)($_POST['comments'] ?? ''));
    $overrideReason = trim((string)($_POST['override_reason'] ?? ''));

    try {
        $success = $reviewService->submitRequestorReview(
            $rfq_id,
            $outcome,
            $comments,
            $selectedQuote['quote_id'] ?? null,
            $overrideReason
        );

        if ($success) {
            pop(
                strtoupper($outcome) === 'MEETS_SPECIFICATIONS'
                    ? 'Requestor specification confirmation submitted. RFQ routed to the Branch Head for final approval.'
                    : 'Requestor confirmed that the selected quotation does not meet specifications. RFQ returned to procurement review.',
                '/rfq/list.php',
                POP_DEFAULT_DELAY_MS,
                'success'
            );
            exit;
        }
    } catch (Throwable $e) {
        pop('Error: ' . $e->getMessage(), '/rfq/requestor_spec_review.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }
}
?>
<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>

<div class="container-fluid my-4">
    <div class="row mb-4">
        <div class="col-md-12">
            <h2 class="mb-1"><i class="bi bi-clipboard-check"></i> Requestor Specification Confirmation - RFQ <?= he($rfq['rfq_number']) ?></h2>
            <p class="text-muted mb-0">The original requestor must confirm whether the selected quotation meets the original specifications.</p>
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
                        <tr><th>RFQ Created By</th><td><?= he($rfq['rfq_created_by_name'] ?? 'N/A') ?></td></tr>
                        <tr><th>Branch</th><td><?= he($rfq['branch_name'] ?? 'N/A') ?></td></tr>
                        <tr><th>Description</th><td><?= nl2br(he($rfq['description'])) ?></td></tr>
                        <tr><th>Estimated Value</th><td><?= formatCurrency((float)$rfq['estimated_value']) ?></td></tr>
                        <tr><th>Selected Vendor</th><td><?= he($selectedQuote['vendor_name'] ?? 'No quote selected yet') ?></td></tr>
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

    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header bg-light"><h5 class="mb-0">Original Specifications</h5></div>
                <div class="card-body">
                    <?php if (!empty($requestItems)): ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead class="table-light">
                                    <tr><th>Item</th><th>Specification</th><th>Qty</th><th>Remarks</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($requestItems as $item): ?>
                                        <tr>
                                            <td><?= he($item['item_name']) ?></td>
                                            <td><?= nl2br(he($item['specification'])) ?></td>
                                            <td><?= he((string)$item['quantity']) ?></td>
                                            <td><?= he($item['remarks'] ?? '') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-0">No line-item specification details were found. Please review the request description above.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header bg-light"><h5 class="mb-0">Vendor Quotations</h5></div>
                <div class="card-body">
                    <?php if (!empty($quotes)): ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead class="table-light">
                                    <tr><th>Vendor</th><th>Amount</th><th>Status</th><th>Document</th></tr>
                                </thead>
                                <tbody>
                                <?php foreach ($quotes as $quote): ?>
                                    <tr class="<?= (int)($quote['is_selected'] ?? 0) === 1 ? 'table-success' : '' ?>">
                                        <td>
                                            <strong><?= he($quote['vendor_name']) ?></strong>
                                            <?php if ((int)($quote['is_selected'] ?? 0) === 1): ?>
                                                <span class="badge bg-success ms-1">Selected</span>
                                            <?php endif; ?>
                                            <br><small class="text-muted"><?= he($quote['contact_person'] ?? '') ?></small>
                                        </td>
                                        <td><?= formatCurrency((float)$quote['quote_amount']) ?></td>
                                        <td>
                                            <span class="badge <?= ($quote['review_status'] ?? 'PENDING') === 'MEETS_REQUIREMENTS' ? 'bg-success' : ((($quote['review_status'] ?? 'PENDING') === 'DOES_NOT_MEET') ? 'bg-danger' : 'bg-secondary') ?>">
                                                <?= he(str_replace('_', ' ', $quote['review_status'] ?? 'PENDING')) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($quote['quote_file'])): ?>
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
                        <p class="text-muted mb-0">No vendor quotations have been uploaded yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header bg-light"><h5 class="mb-0">Prior Requestor Review Submissions</h5></div>
                <div class="card-body">
                    <?php if (!empty($reviewHistory)): ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead class="table-light"><tr><th>Date</th><th>Reviewer</th><th>Outcome</th><th>Comments</th></tr></thead>
                                <tbody>
                                <?php foreach ($reviewHistory as $history): ?>
                                    <tr>
                                        <td><?= formatDate($history['review_date']) ?></td>
                                        <td><?= he($history['reviewer_name'] ?? 'N/A') ?></td>
                                        <td><?= he(str_replace('_', ' ', $history['review_outcome'])) ?></td>
                                        <td><?= nl2br(he($history['comments'] ?? '')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-0">No requestor confirmation history yet.</p>
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
                        <p class="text-muted mb-0">No approval actions have been recorded yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if (($rfq['request_status'] ?? '') === 'QUOTE_REQUESTOR_REVIEW_PENDING'): ?>
        <div class="row">
            <div class="col-lg-8 offset-lg-2">
                <div class="card">
                    <div class="card-header bg-primary text-white"><h5 class="mb-0">Submit Requestor Specification Confirmation</h5></div>
                    <div class="card-body">
                        <?php if (!$selectedQuote): ?>
                            <div class="alert alert-warning mb-0">A selected quote is required before requestor specification confirmation can be submitted.</div>
                        <?php else: ?>
                            <form method="POST" action="/rfq/requestor_spec_review.php?id=<?= (int)$rfq_id ?>" id="requestorReviewForm">
                                <div class="mb-3">
                                    <label class="form-label"><strong>Decision</strong></label>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="review_outcome" id="outcome_meets" value="MEETS_SPECIFICATIONS" required>
                                        <label class="form-check-label" for="outcome_meets">Meets Specifications</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="review_outcome" id="outcome_not_meet" value="DOES_NOT_MEET_SPECIFICATIONS">
                                        <label class="form-check-label" for="outcome_not_meet">Does Not Meet Specifications</label>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label for="comments" class="form-label"><strong>Comments</strong> <span class="text-danger" id="commentsRequiredMarker" style="display:none;">*</span></label>
                                    <textarea class="form-control" id="comments" name="comments" rows="5" placeholder="Explain your comparison against the original requirements."></textarea>
                                    <small class="text-muted">Comments are required when the selected quotation does not meet specifications.</small>
                                </div>
                                <?php if (!$isRequestor && $canOverride): ?>
                                    <div class="mb-3">
                                        <label for="override_reason" class="form-label"><strong>Override Reason</strong> <span class="text-danger">*</span></label>
                                        <textarea class="form-control" id="override_reason" name="override_reason" rows="3" placeholder="Explain why you are acting on behalf of the original requestor." required></textarea>
                                    </div>
                                <?php endif; ?>
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle"></i> After an approval, the RFQ will be routed automatically to the Branch Head for final approval.
                                </div>
                                <div class="d-flex justify-content-end gap-2">
                                    <a href="/rfq/list.php" class="btn btn-secondary">Cancel</a>
                                    <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle"></i> Submit Confirmation</button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="row">
            <div class="col-lg-8 offset-lg-2">
                <div class="alert alert-secondary">
                    This RFQ is currently at <strong><?= he($rfq['request_status']) ?></strong> and is not awaiting requestor specification confirmation.
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    const form = document.getElementById('requestorReviewForm');
    if (!form) return;
    const outcomeMeets = document.getElementById('outcome_meets');
    const outcomeNotMeet = document.getElementById('outcome_not_meet');
    const comments = document.getElementById('comments');
    const marker = document.getElementById('commentsRequiredMarker');

    function syncCommentsRequirement() {
        const required = outcomeNotMeet.checked;
        comments.required = required;
        if (marker) {
            marker.style.display = required ? 'inline' : 'none';
        }
    }

    outcomeMeets.addEventListener('change', syncCommentsRequirement);
    outcomeNotMeet.addEventListener('change', syncCommentsRequirement);
    syncCommentsRequirement();
})();
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
