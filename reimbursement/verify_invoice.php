<?php
/**
 * Reimbursement Invoice Verification
 * ===================================
 * Allows Procurement Officers (Copy to Procurement / GC2) and Finance
 * Officers (Original to Finance / GC10A) to verify that the goods were
 * received / service was rendered satisfactorily before the reimbursement
 * request can proceed.
 */
$REQUIRE_PERMISSION = 'verify_reimbursement_goods';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/notifications.php';

$reimb_invoice_id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['reimb_invoice_id'] ?? 0);
if ($reimb_invoice_id <= 0) {
    pop('Invalid invoice reference.', '/reimbursement/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

/* Fetch invoice with its parent request */
$stmt = $pdo->prepare("
    SELECT ri.*, pr.request_id, pr.request_number, pr.status AS request_status,
           pr.currency, pr.created_by, u.full_name AS submitted_by_name
    FROM reimbursement_invoices ri
    INNER JOIN procurement_requests pr ON ri.request_id = pr.request_id
    LEFT JOIN users u ON ri.submitted_by = u.user_id
    WHERE ri.reimb_invoice_id = ? AND pr.request_type = 'REIMBURSEMENT'
");
$stmt->execute([$reimb_invoice_id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    pop('Invoice not found.', '/reimbursement/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$request_id = (int)$invoice['request_id'];

if ((int)$invoice['goods_service_verified'] === 1) {
    /* Self-heal: the invoice was verified previously but the parent request's
       status never advanced (e.g. it was stuck at FUNDS_VERIFIED). If the
       request can still move to INVOICE_VERIFIED, do it now so the pipeline
       isn't left permanently stalled. */
    if ($invoice['invoice_stage'] === 'COPY_TO_PROCUREMENT'
        && canReimbursementTransition($invoice['request_status'], 'INVOICE_VERIFIED')
    ) {
        try {
            $pdo->beginTransaction();

            $statusStmt = $pdo->prepare("
                UPDATE procurement_requests
                SET status = 'INVOICE_VERIFIED', updated_at = NOW()
                WHERE request_id = ?
            ");
            $statusStmt->execute([$request_id]);

            $historyStmt = $pdo->prepare("
                INSERT INTO reimbursement_status_history
                (request_id, old_status, new_status, changed_by, change_notes)
                VALUES (?, ?, 'INVOICE_VERIFIED', ?, ?)
            ");
            $historyStmt->execute([
                $request_id,
                $invoice['request_status'],
                $_SESSION['user_id'],
                'Pipeline advanced to Invoice Verified (invoice was already verified).',
            ]);

            $pdo->commit();

            logAudit($pdo, 'reimbursement_invoices', $reimb_invoice_id, 'VERIFY',
                "Request #{$invoice['request_number']} pipeline advanced to INVOICE_VERIFIED (invoice previously verified)");
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        modalPop(
            'Invoice Verified',
            'This invoice was already verified. The request status has been advanced to Invoices Verified.',
            '/reimbursement/view.php?request_id=' . $request_id,
            'success'
        );
        exit;
    }

    pop('This invoice has already been verified.', '/reimbursement/view.php?request_id=' . $request_id, POP_DEFAULT_DELAY_MS, 'info');
    exit;
}

/* Fetch attachments for this invoice so the verifier can inspect the invoice copy */
$attStmt = $pdo->prepare("
    SELECT * FROM reimbursement_invoice_attachments
    WHERE reimb_invoice_id = ? AND is_deleted = 0
    ORDER BY uploaded_date ASC
");
$attStmt->execute([$reimb_invoice_id]);
$attachments = $attStmt->fetchAll(PDO::FETCH_ASSOC);

/* Handle POST - record verification decision */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $decision = trim($_POST['decision'] ?? '');
        $notes = trim($_POST['verification_notes'] ?? '');

        if (!in_array($decision, ['verify', 'reject'], true)) {
            throw new Exception('Please select whether the invoice is verified or rejected.');
        }

        if ($decision === 'reject' && $notes === '') {
            throw new Exception('Please provide a reason when rejecting an invoice.');
        }

        $pdo->beginTransaction();

        if ($decision === 'verify') {
            $upd = $pdo->prepare("
                UPDATE reimbursement_invoices
                SET goods_service_verified = 1,
                    verified_by = ?,
                    procurement_verified_date = NOW(),
                    verification_notes = ?
                WHERE reimb_invoice_id = ?
            ");
            $upd->execute([$_SESSION['user_id'], $notes ?: null, $reimb_invoice_id]);

            logAudit($pdo, 'reimbursement_invoices', $reimb_invoice_id, 'VERIFY',
                "Invoice verified for Request #{$invoice['request_number']} ({$invoice['invoice_stage']})");

            /* Advance the request to INVOICE_VERIFIED once the Copy-to-Procurement
               invoice has passed verification and the request is awaiting it */
            if ($invoice['invoice_stage'] === 'COPY_TO_PROCUREMENT'
                && canReimbursementTransition($invoice['request_status'], 'INVOICE_VERIFIED')
            ) {
                $statusStmt = $pdo->prepare("
                    UPDATE procurement_requests
                    SET status = 'INVOICE_VERIFIED', updated_at = NOW()
                    WHERE request_id = ?
                ");
                $statusStmt->execute([$request_id]);

                $historyStmt = $pdo->prepare("
                    INSERT INTO reimbursement_status_history
                    (request_id, old_status, new_status, changed_by, change_notes)
                    VALUES (?, ?, 'INVOICE_VERIFIED', ?, ?)
                ");
                $historyStmt->execute([
                    $request_id,
                    $invoice['request_status'],
                    $_SESSION['user_id'],
                    $notes ?: 'Invoice verified by ' . ($_SESSION['role_name'] ?? 'Procurement/Finance'),
                ]);
            }

            $pdo->commit();

            modalPop(
                'Invoice Verified',
                'The invoice for Request ' . $invoice['request_number'] . ' has been marked as verified.',
                '/reimbursement/view.php?request_id=' . $request_id,
                'success'
            );
            exit;
        }

        /* Rejection: record notes so the requestor can resubmit a corrected invoice */
        $upd = $pdo->prepare("
            UPDATE reimbursement_invoices
            SET verified_by = ?,
                procurement_verified_date = NOW(),
                verification_notes = ?
            WHERE reimb_invoice_id = ?
        ");
        $upd->execute([$_SESSION['user_id'], 'REJECTED: ' . $notes, $reimb_invoice_id]);

        logAudit($pdo, 'reimbursement_invoices', $reimb_invoice_id, 'REJECT',
            "Invoice rejected for Request #{$invoice['request_number']}: " . $notes);

        $pdo->commit();

        modalPop(
            'Invoice Rejected',
            'The invoice has been rejected. The requestor will need to submit a corrected invoice.',
            '/reimbursement/view.php?request_id=' . $request_id,
            'warning'
        );
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error'] = extractDbMessage($e);
    }
}

/* ===== Render ===== */
require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/header.php";
?>

<div class="container mt-4">
    <div class="row mb-4">
        <div class="col-lg-8">
            <h3 class="section-title">🔍 Verify Reimbursement Invoice</h3>
            <p class="text-muted">
                Confirm that the goods were received or the service was properly rendered before the
                reimbursement can proceed.
            </p>
        </div>
    </div>

    <?php if (!empty($_SESSION['error'])): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['error']) ?></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">📌 Invoice Information</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <small class="text-muted d-block">Request Number</small>
                    <h6 class="fw-bold"><?= htmlspecialchars($invoice['request_number']) ?></h6>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Stage</small>
                    <h6><?= $invoice['invoice_stage'] === 'COPY_TO_PROCUREMENT' ? '📋 Copy to Procurement (GC2)' : '📄 Original to Finance (GC10A)' ?></h6>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Invoice Amount</small>
                    <h6 class="fw-bold"><?= htmlspecialchars(normalizeCurrency($invoice['currency'] ?? 'JMD')) ?> <?= number_format((float)$invoice['invoice_amount'], 2) ?></h6>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Submitted By</small>
                    <h6><?= htmlspecialchars($invoice['submitted_by_name'] ?? 'N/A') ?></h6>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-file-earmark-arrow-down me-2"></i>Attached Documents</h6>
        </div>
        <div class="card-body">
            <?php if (empty($attachments)): ?>
                <div class="alert alert-warning mb-0">No documents were attached to this invoice.</div>
            <?php else: ?>
                <?php foreach ($attachments as $att): ?>
                    <div class="d-flex justify-content-between align-items-center bg-light p-2 rounded mb-2">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-file-earmark text-secondary"></i>
                            <div>
                                <div class="small fw-semibold"><?= htmlspecialchars($att['original_file_name']) ?></div>
                                <small class="text-muted">
                                    <?= formatFileSize($att['file_size']) ?> • <?= date('M d, Y', strtotime($att['uploaded_date'])) ?>
                                </small>
                            </div>
                        </div>
                        <a href="/reimbursement/download_attachment.php?id=<?= (int)$att['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Download">
                            <i class="bi bi-download"></i> Download
                        </a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="card border-success mb-4">
        <div class="card-header bg-success text-white">
            <h5 class="mb-0">✅ Verification Decision</h5>
        </div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="reimb_invoice_id" value="<?= (int)$invoice['reimb_invoice_id'] ?>">
                <div class="mb-4">
                    <label class="form-label fw-bold">Were the goods received / service rendered satisfactorily?</label>
                    <div class="d-flex gap-3">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="decision" id="decision_verify" value="verify" required>
                            <label class="form-check-label text-success fw-bold" for="decision_verify">
                                ✅ Yes — Verify Invoice
                            </label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="decision" id="decision_reject" value="reject">
                            <label class="form-check-label text-danger fw-bold" for="decision_reject">
                                ❌ No — Reject Invoice
                            </label>
                        </div>
                    </div>
                </div>

                <div class="mb-4">
                    <label for="verification_notes" class="form-label">Notes (required when rejecting)</label>
                    <textarea name="verification_notes" id="verification_notes" class="form-control" rows="3"
                              placeholder="Condition of goods, reason for rejection, etc."></textarea>
                </div>

                <div class="d-grid gap-2 d-sm-flex">
                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="bi bi-check-circle"></i> Submit Verification
                    </button>
                    <a href="/reimbursement/view.php?request_id=<?= $request_id ?>" class="btn btn-outline-secondary btn-lg">
                        <i class="bi bi-arrow-left"></i> Back to Request
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/footer.php"; ?>
