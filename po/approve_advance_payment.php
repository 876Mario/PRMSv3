<?php
/**
 * Approve or Reject a pending Advance Payment request.
 * Requires approve_advance_payment permission.
 */
$REQUIRE_PERMISSION = 'approve_advance_payment';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';

/* ================================
   Validate advance_payment_id
================================ */
$apId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($apId <= 0) {
    pop('Invalid advance payment reference.', '/po/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

/* ================================
   Fetch Advance Payment + PO
================================ */
$stmt = $pdo->prepare("
    SELECT
        ap.*,
        po.po_number,
        po.po_total,
        po.status AS po_status,
        c.commitment_number,
        pr.currency,
        u.full_name AS created_by_name,
        u.email     AS created_by_email
    FROM po_advance_payments ap
    JOIN purchase_orders po ON ap.po_id = po.po_id
    JOIN commitments     c  ON po.commitment_id = c.commitment_id
    JOIN procurement_requests pr ON c.request_id = pr.request_id
    LEFT JOIN users      u  ON ap.created_by    = u.user_id
    WHERE ap.advance_payment_id = ?
    LIMIT 1
");
$stmt->execute([$apId]);
$ap = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ap) {
    pop('Advance payment record not found.', '/po/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$currency = (!empty($ap['currency'])) ? strtoupper(trim($ap['currency'])) : 'JMD';

/* ================================
   Handle POST
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $decision            = trim($_POST['decision']             ?? '');
    $approvalComments    = trim($_POST['approval_comments']    ?? '');
    $rejectionReason     = trim($_POST['rejection_reason']     ?? '');

    if (!in_array($decision, ['APPROVED', 'REJECTED'], true)) {
        modalPop('Validation Error', 'Please select Approve or Reject.', '', 'error');
        exit;
    }

    if ($ap['status'] !== 'PENDING_APPROVAL') {
        pop('This advance payment has already been decided.', "/po/view.php?po_id={$ap['po_id']}", POP_DEFAULT_DELAY_MS, 'warning');
        exit;
    }

    if ($decision === 'REJECTED' && $rejectionReason === '') {
        modalPop('Validation Error', 'Please provide a reason for rejection.', '', 'error');
        exit;
    }

    try {
        $pdo->beginTransaction();

        /* Re-check remaining balance before approving to guard against race conditions */
        if ($decision === 'APPROVED') {
            $poId = (int)$ap['po_id'];

            $varStmt2 = $pdo->prepare("SELECT COALESCE(SUM(variation_amount),0) FROM po_variations WHERE po_id=? AND status='APPROVED' FOR UPDATE");
            $varStmt2->execute([$poId]);
            $approvedPoTotal2 = (float)$ap['po_total'] + (float)$varStmt2->fetchColumn();

            $invStmt2 = $pdo->prepare("SELECT COALESCE(SUM(invoice_amount),0) FROM invoices WHERE po_id=? FOR UPDATE");
            $invStmt2->execute([$poId]);
            $totalInvoiced2 = (float)$invStmt2->fetchColumn();

            $advStmt2 = $pdo->prepare("SELECT COALESCE(SUM(payment_amount),0) FROM po_advance_payments WHERE po_id=? AND status='APPROVED' FOR UPDATE");
            $advStmt2->execute([$poId]);
            $totalAdvApproved2 = (float)$advStmt2->fetchColumn();

            $remainingBalance2 = $approvedPoTotal2 - $totalInvoiced2 - $totalAdvApproved2;
            if ((float)$ap['payment_amount'] > $remainingBalance2) {
                $pdo->rollBack();
                modalPop('Balance Exceeded', 'This advance payment amount exceeds the current remaining PO balance and cannot be approved.', '', 'error');
                exit;
            }
        }

        $upd = $pdo->prepare("
            UPDATE po_advance_payments
            SET status            = ?,
                approved_by       = ?,
                approved_at       = NOW(),
                approval_comments = ?,
                rejection_reason  = ?
            WHERE advance_payment_id = ?
              AND status = 'PENDING_APPROVAL'
        ");
        $upd->execute([
            $decision,
            $_SESSION['user_id'],
            $approvalComments ?: null,
            ($decision === 'REJECTED') ? $rejectionReason : null,
            $apId,
        ]);

        if ($upd->rowCount() === 0) {
            $pdo->rollBack();
            pop('This advance payment has already been decided by another user.', "/po/view.php?po_id={$ap['po_id']}", POP_DEFAULT_DELAY_MS, 'warning');
            exit;
        }

        $pdo->commit();

        $label = $decision === 'APPROVED' ? 'Approved' : 'Rejected';
        logAudit($pdo, 'po_advance_payments', $apId, $decision,
            "Advance payment {$label} for PO #{$ap['po_number']}");

        /* Notify the submitter of the decision */
        require_once $_SERVER['DOCUMENT_ROOT'] . '/config/notifications.php';
        notifyAdvancePaymentDecided($apId, $decision);

        $msg = $decision === 'APPROVED' ? 'advance_approved' : 'advance_rejected';
        header("Location: /po/view.php?po_id={$ap['po_id']}&msg={$msg}");
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        modalPop('Error', extractDbMessage($e), "/po/view.php?po_id={$ap['po_id']}", 'error');
        exit;
    }
}

/* ================================
   Render Review Page
================================ */
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';

$typeLabels = [
    'ADVANCE_PAYMENT' => ['label' => 'Advance Payment', 'badge' => 'bg-primary'],
    'PARTIAL_PAYMENT' => ['label' => 'Partial Payment',  'badge' => 'bg-info text-dark'],
    'FINAL_PAYMENT'   => ['label' => 'Final Payment',    'badge' => 'bg-success'],
];
$typeInfo = $typeLabels[$ap['payment_type']] ?? ['label' => $ap['payment_type'], 'badge' => 'bg-secondary'];

$statusBadges = [
    'PENDING_APPROVAL' => 'bg-warning text-dark',
    'APPROVED'         => 'bg-success',
    'REJECTED'         => 'bg-danger',
    'CANCELLED'        => 'bg-secondary',
];
$statusBadge = $statusBadges[$ap['status']] ?? 'bg-secondary';
?>

<div class="container mt-4">

    <div class="row mb-4">
        <div class="col">
            <h3 class="section-title">
                <i class="bi bi-check2-square me-2 text-success"></i>Review Advance Payment
            </h3>
            <p class="text-muted mb-0">
                Review and approve or reject this advance payment for
                <strong><?= htmlspecialchars($ap['po_number']) ?></strong>.
            </p>
        </div>
    </div>

    <!-- Payment Details Card -->
    <div class="card mb-4 border-start border-<?= $ap['status'] === 'PENDING_APPROVAL' ? 'warning' : ($ap['status'] === 'APPROVED' ? 'success' : 'danger') ?> border-3">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-receipt me-2"></i>Advance Payment Details</h5>
            <span class="badge <?= $statusBadge ?>"><?= htmlspecialchars($ap['status']) ?></span>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <small class="text-muted d-block">PO Number</small>
                    <strong>
                        <a href="/po/view.php?po_id=<?= (int)$ap['po_id'] ?>" class="text-decoration-none">
                            <?= htmlspecialchars($ap['po_number']) ?>
                        </a>
                    </strong>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Commitment #</small>
                    <strong><?= htmlspecialchars($ap['commitment_number']) ?></strong>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Payment Type</small>
                    <span class="badge <?= $typeInfo['badge'] ?>"><?= $typeInfo['label'] ?></span>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Amount</small>
                    <strong class="text-success fs-5"><?= htmlspecialchars($currency) ?> <?= number_format((float)$ap['payment_amount'], 2) ?></strong>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Payment Date</small>
                    <strong><?= date('d M Y', strtotime($ap['payment_date'])) ?></strong>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Reference</small>
                    <strong><?= htmlspecialchars($ap['payment_reference']) ?></strong>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Supplier</small>
                    <strong><?= htmlspecialchars($ap['supplier_name'] ?? '—') ?></strong>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Payment Method</small>
                    <strong><?= htmlspecialchars($ap['payment_method'] ?? '—') ?></strong>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Submitted By</small>
                    <strong><?= htmlspecialchars($ap['created_by_name'] ?? '—') ?></strong>
                </div>
                <?php if ($ap['notes']): ?>
                <div class="col-12">
                    <small class="text-muted d-block">Notes</small>
                    <p class="mb-0"><?= nl2br(htmlspecialchars($ap['notes'])) ?></p>
                </div>
                <?php endif; ?>
                <?php if ($ap['supporting_document_path']): ?>
                <div class="col-12">
                    <small class="text-muted d-block">Supporting Document</small>
                    <a href="/po/download_advance_payment_doc.php?id=<?= $apId ?>&action=view"
                       class="btn btn-sm btn-outline-primary me-2" target="_blank">
                        <i class="bi bi-eye me-1"></i>View
                    </a>
                    <a href="/po/download_advance_payment_doc.php?id=<?= $apId ?>"
                       class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-download me-1"></i>Download
                    </a>
                    <small class="text-muted ms-2"><?= htmlspecialchars($ap['supporting_document_original_name'] ?? '') ?></small>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($ap['status'] === 'PENDING_APPROVAL'): ?>
    <!-- Decision Form -->
    <div class="card border-secondary mb-4">
        <div class="card-header bg-secondary text-white">
            <h5 class="mb-0"><i class="bi bi-gavel me-2"></i>Decision</h5>
        </div>
        <div class="card-body">
            <form method="post" id="decisionForm" onsubmit="return validateDecisionForm()">

                <div class="mb-3">
                    <label class="form-label fw-bold">
                        <span class="text-danger">*</span> Decision
                    </label>
                    <div class="d-flex gap-3">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="decision"
                                   id="decApprove" value="APPROVED" onchange="toggleRejectionReason()">
                            <label class="form-check-label text-success fw-bold" for="decApprove">
                                <i class="bi bi-check-circle me-1"></i>Approve
                            </label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="decision"
                                   id="decReject" value="REJECTED" onchange="toggleRejectionReason()">
                            <label class="form-check-label text-danger fw-bold" for="decReject">
                                <i class="bi bi-x-circle me-1"></i>Reject
                            </label>
                        </div>
                    </div>
                </div>

                <div class="mb-3" id="rejectionReasonGroup" style="display:none;">
                    <label for="rejection_reason" class="form-label">
                        <span class="text-danger">*</span> Rejection Reason
                    </label>
                    <textarea name="rejection_reason" id="rejection_reason"
                              class="form-control" rows="3"
                              placeholder="Explain why this advance payment is being rejected…"></textarea>
                </div>

                <div class="mb-4">
                    <label for="approval_comments" class="form-label">Comments (optional)</label>
                    <textarea name="approval_comments" id="approval_comments"
                              class="form-control" rows="2"
                              placeholder="Any additional notes…"></textarea>
                </div>

                <div class="d-flex gap-2 justify-content-between">
                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="bi bi-check2 me-1"></i>Submit Decision
                    </button>
                    <a href="/po/view.php?po_id=<?= (int)$ap['po_id'] ?>" class="btn btn-outline-secondary btn-lg">
                        <i class="bi bi-arrow-left me-1"></i>Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>

    <?php else: ?>
    <!-- Already Decided -->
    <div class="alert <?= $ap['status'] === 'APPROVED' ? 'alert-success' : 'alert-danger' ?> mb-4">
        <i class="bi bi-<?= $ap['status'] === 'APPROVED' ? 'check-circle' : 'x-circle' ?> me-2"></i>
        This advance payment was <strong><?= htmlspecialchars($ap['status']) ?></strong>
        <?php if ($ap['approved_at']): ?>
            on <?= date('d M Y \a\t g:i A', strtotime($ap['approved_at'])) ?>.
        <?php endif; ?>
        <?php if ($ap['rejection_reason']): ?>
        <div class="mt-2"><strong>Reason:</strong> <?= nl2br(htmlspecialchars($ap['rejection_reason'])) ?></div>
        <?php endif; ?>
        <?php if ($ap['approval_comments']): ?>
        <div class="mt-2"><strong>Comments:</strong> <?= nl2br(htmlspecialchars($ap['approval_comments'])) ?></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="mb-4">
        <a href="/po/view.php?po_id=<?= (int)$ap['po_id'] ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back to Purchase Order
        </a>
    </div>
</div>

<script>
function toggleRejectionReason() {
    const isReject = document.getElementById('decReject').checked;
    document.getElementById('rejectionReasonGroup').style.display = isReject ? 'block' : 'none';
}

function validateDecisionForm() {
    const decision = document.querySelector('input[name="decision"]:checked');
    if (!decision) {
        alert('Please select Approve or Reject.');
        return false;
    }
    if (decision.value === 'REJECTED') {
        const reason = document.getElementById('rejection_reason').value.trim();
        if (!reason) {
            alert('Please provide a rejection reason.');
            document.getElementById('rejection_reason').focus();
            return false;
        }
        return confirm('Reject this advance payment?');
    }
    return confirm('Approve this advance payment for <?= htmlspecialchars($currency) ?> <?= number_format((float)$ap['payment_amount'], 2) ?>?');
}
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
