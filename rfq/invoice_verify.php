<?php
/**
 * RFQ Invoice Verification
 * ========================
 * Finance Officer checks invoice against RFQ, PO, commitment, and deliverables
 * Flags mismatches for resolution before payment approval
 */

$REQUIRE_PERMISSION = 'verify_rfq_invoice';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';

// Get RFQ ID from URL
$rfq_id = (int)($_GET['id'] ?? 0);

if ($rfq_id <= 0) {
    pop('Invalid RFQ ID', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

// Fetch RFQ details
$stmt = $pdo->prepare("
    SELECT r.*, pr.request_number, pr.description, 
           u.display_name as created_by_name
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

// Get selected quote and PO details
$stmt = $pdo->prepare("
    SELECT q.quote_amount, q.gct_amount, v.vendor_name
    FROM rfq_quotes q
    JOIN rfq_vendors rv ON q.rfq_vendor_id = rv.rfq_vendor_id
    JOIN vendors v ON rv.vendor_id = v.vendor_id
    WHERE rv.rfq_id = ? AND q.is_selected = 1
    LIMIT 1
");
$stmt->execute([$rfq_id]);
$selectedQuote = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT po_amount FROM rfq_purchase_orders
    WHERE rfq_id = ? AND status = 'APPROVED'
    LIMIT 1
");
$stmt->execute([$rfq_id]);
$po = $stmt->fetch(PDO::FETCH_ASSOC);

// Get commitment amount
$stmt = $pdo->prepare("
    SELECT commitment_amount FROM rfq_commitment_forms
    WHERE rfq_id = ? AND status = 'APPROVED'
    LIMIT 1
");
$stmt->execute([$rfq_id]);
$commitment = $stmt->fetch(PDO::FETCH_ASSOC);

// Get verification history
$stmt = $pdo->prepare("
    SELECT * FROM rfq_invoice_verifications
    WHERE rfq_id = ?
    ORDER BY verified_at DESC
");
$stmt->execute([$rfq_id]);
$verificationHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle POST - Submit verification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $invoice_number = trim($_POST['invoice_number'] ?? '');
    $invoice_amount = (float)($_POST['invoice_amount'] ?? 0);
    $amount_matches = isset($_POST['amount_matches']) ? (int)$_POST['amount_matches'] : null;
    $deliverables_received = isset($_POST['deliverables_received']) ? (int)$_POST['deliverables_received'] : null;
    $commitment_matches = isset($_POST['commitment_matches']) ? (int)$_POST['commitment_matches'] : null;
    $verification_comments = trim($_POST['verification_comments'] ?? '');
    $action = $_POST['action'] ?? '';

    // Validate
    if (strlen($invoice_number) < 3) {
        pop('Invoice number is required', '/rfq/invoice_verify.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    if ($invoice_amount <= 0) {
        pop('Invoice amount must be greater than zero', '/rfq/invoice_verify.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    if ($amount_matches === null || $deliverables_received === null || $commitment_matches === null) {
        pop('All verification checkpoints must be confirmed', '/rfq/invoice_verify.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    if (strlen($verification_comments) < 5) {
        pop('Verification comments are required (minimum 5 characters)', '/rfq/invoice_verify.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Detect mismatches
        $mismatches = [];
        if (!$amount_matches) {
            $mismatches[] = 'Invoice amount does not match PO/Quote amount';
        }
        if (!$deliverables_received) {
            $mismatches[] = 'Goods/services not fully received or verified';
        }
        if (!$commitment_matches) {
            $mismatches[] = 'Invoice does not match commitment terms';
        }

        // Determine status based on mismatches
        $verification_status = 'VERIFIED';
        if (!empty($mismatches)) {
            $verification_status = 'MISMATCH_FLAGGED';
        }

        // Record verification
        $stmt = $pdo->prepare("
            INSERT INTO rfq_invoice_verifications
            (rfq_id, invoice_number, verified_by, verification_status, invoice_amount,
             rfq_amount, po_amount, amount_matches, deliverables_received, commitment_matches,
             verification_comments, mismatch_details)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $rfq_id,
            $invoice_number,
            $_SESSION['user_id'],
            $verification_status,
            $invoice_amount,
            $selectedQuote['quote_amount'],
            $po['po_amount'] ?? null,
            $amount_matches,
            $deliverables_received,
            $commitment_matches,
            $verification_comments,
            !empty($mismatches) ? json_encode($mismatches) : null
        ]);

        // Update RFQ
        $stmt = $pdo->prepare("
            UPDATE rfqs
            SET invoice_checked_by = ?,
                invoice_checked_at = NOW(),
                invoice_mismatch_comments = ?
            WHERE rfq_id = ?
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            $verification_status === 'MISMATCH_FLAGGED' ? $verification_comments : null,
            $rfq_id
        ]);

        // Log audit trail
        $stmt = $pdo->prepare("
            INSERT INTO audit_log (table_name, action, notes, change_date)
            VALUES ('rfq_invoice_verifications', 'INVOICE_VERIFIED', ?, NOW())
        ");
        $stmt->execute([
            "RFQ {$rfq_id}: Invoice {$invoice_number} verified - Status: {$verification_status}"
        ]);

        $pdo->commit();

        $message = $verification_status === 'VERIFIED' 
            ? 'Invoice verified successfully. Ready for payment approval.' 
            : 'Invoice verification flagged mismatches. Please resolve before payment approval.';

        pop($message, '/rfq/view.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'success');
        exit;

    } catch (Throwable $e) {
        $pdo->rollBack();
        pop('Error recording verification: ' . extractDbMessage($e), '/rfq/invoice_verify.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/header.php";
?>

<!-- Page Header -->
<div class="d-flex flex-wrap align-items-center justify-content-between mb-4">
    <div>
        <a href="/rfq/view.php?id=<?= $rfq_id ?>" class="text-decoration-none text-muted small">
            <i class="bi bi-arrow-left me-1"></i>Back to RFQ
        </a>
        <h4 class="fw-bold mt-2 mb-1" style="color:#1a1a2e;">
            <i class="bi bi-check-square"></i> Invoice Verification
        </h4>
        <p class="text-muted mb-0 small">Check invoice against RFQ, PO, commitment, and deliverables</p>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8">
        
        <!-- RFQ/PO Summary -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 rounded-top-4 py-3">
                <h6 class="fw-semibold mb-0"><i class="bi bi-receipt me-1"></i> RFQ/PO Details</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Vendor</label>
                        <div class="fw-semibold"><?= htmlspecialchars($selectedQuote['vendor_name'] ?? 'Unknown') ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">RFQ Number</label>
                        <div class="fw-semibold"><?= htmlspecialchars($rfq['rfq_number']) ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Quote Amount</label>
                        <div class="fw-semibold text-info">$<?= number_format($selectedQuote['quote_amount'] ?? 0, 2) ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">PO Amount</label>
                        <div class="fw-semibold text-info">$<?= number_format($po['po_amount'] ?? 0, 2) ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Commitment Amount</label>
                        <div class="fw-semibold text-info">$<?= number_format($commitment['commitment_amount'] ?? 0, 2) ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">GCT</label>
                        <div class="fw-normal">$<?= number_format($selectedQuote['gct_amount'] ?? 0, 2) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Verification History -->
        <?php if (!empty($verificationHistory)): ?>
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 rounded-top-4 py-3">
                <h6 class="fw-semibold mb-0"><i class="bi bi-clock-history me-1"></i> Verification History</h6>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr class="text-muted small">
                                <th>Invoice #</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Verified At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($verificationHistory as $verification): ?>
                            <tr>
                                <td class="fw-semibold"><?= htmlspecialchars($verification['invoice_number']) ?></td>
                                <td class="fw-semibold">$<?= number_format($verification['invoice_amount'], 2) ?></td>
                                <td>
                                    <span class="badge bg-<?= $verification['verification_status'] === 'VERIFIED' ? 'success' : 'warning' ?>">
                                        <?= ucfirst(str_replace('_', ' ', $verification['verification_status'])) ?>
                                    </span>
                                </td>
                                <td class="small"><?= date('M d, Y', strtotime($verification['verified_at'])) ?></td>
                                <td>
                                    <button type="button" class="btn btn-link btn-sm" data-bs-toggle="modal" 
                                            data-bs-target="#details<?= $verification['verification_id'] ?>">
                                        <i class="bi bi-eye"></i> Details
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Invoice Verification Form -->
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-0 rounded-top-4 py-3">
                <h6 class="fw-semibold mb-0"><i class="bi bi-pencil-square me-1"></i> Record Invoice Verification</h6>
            </div>
            <div class="card-body">
                <form method="POST" class="needs-validation">
                    <!-- Invoice Number -->
                    <div class="mb-3">
                        <label for="invoice_number" class="form-label">
                            Invoice Number <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="invoice_number" name="invoice_number" 
                               required minlength="3"
                               placeholder="e.g., INV-2026-001">
                    </div>

                    <!-- Invoice Amount -->
                    <div class="mb-3">
                        <label for="invoice_amount" class="form-label">
                            Invoice Amount <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" class="form-control" id="invoice_amount" name="invoice_amount" 
                                   step="0.01" min="0" required
                                   placeholder="0.00">
                        </div>
                        <div class="form-text">Expected: $<?= number_format($po['po_amount'] ?? 0, 2) ?></div>
                    </div>

                    <!-- Verification Checkpoints -->
                    <div class="alert alert-warning mb-4">
                        <h6 class="fw-semibold mb-3"><i class="bi bi-exclamation-triangle me-2"></i>Verification Checkpoints</h6>
                        
                        <!-- Amount Matches -->
                        <div class="form-check mb-3">
                            <input type="radio" class="form-check-input" name="amount_matches" id="amount_yes" value="1" required>
                            <label class="form-check-label" for="amount_yes">
                                <i class="bi bi-check-circle text-success me-1"></i>
                                <strong>Amount Matches:</strong> Invoice amount matches PO and quote amount
                            </label>
                        </div>
                        <div class="form-check mb-3">
                            <input type="radio" class="form-check-input" name="amount_matches" id="amount_no" value="0" required>
                            <label class="form-check-label" for="amount_no">
                                <i class="bi bi-x-circle text-danger me-1"></i>
                                <strong>Amount Mismatch:</strong> Invoice amount differs from PO/quote
                            </label>
                        </div>

                        <!-- Deliverables Received -->
                        <div class="form-check mb-3">
                            <input type="radio" class="form-check-input" name="deliverables_received" id="deliverables_yes" value="1" required>
                            <label class="form-check-label" for="deliverables_yes">
                                <i class="bi bi-check-circle text-success me-1"></i>
                                <strong>Deliverables Received:</strong> All goods/services received and verified
                            </label>
                        </div>
                        <div class="form-check mb-3">
                            <input type="radio" class="form-check-input" name="deliverables_received" id="deliverables_no" value="0" required>
                            <label class="form-check-label" for="deliverables_no">
                                <i class="bi bi-x-circle text-danger me-1"></i>
                                <strong>Deliverables Not Received:</strong> Goods/services incomplete or not verified
                            </label>
                        </div>

                        <!-- Commitment Matches -->
                        <div class="form-check mb-3">
                            <input type="radio" class="form-check-input" name="commitment_matches" id="commitment_yes" value="1" required>
                            <label class="form-check-label" for="commitment_yes">
                                <i class="bi bi-check-circle text-success me-1"></i>
                                <strong>Commitment Matches:</strong> Invoice matches commitment form
                            </label>
                        </div>
                        <div class="form-check">
                            <input type="radio" class="form-check-input" name="commitment_matches" id="commitment_no" value="0" required>
                            <label class="form-check-label" for="commitment_no">
                                <i class="bi bi-x-circle text-danger me-1"></i>
                                <strong>Commitment Mismatch:</strong> Invoice differs from commitment
                            </label>
                        </div>
                    </div>

                    <!-- Verification Comments -->
                    <div class="mb-3">
                        <label for="verification_comments" class="form-label">
                            Verification Comments <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control" id="verification_comments" name="verification_comments" 
                                  rows="4" required minlength="5"
                                  placeholder="Provide details of verification findings and any discrepancies..."></textarea>
                        <div class="form-text">Minimum 5 characters required</div>
                    </div>

                    <!-- Information Alert -->
                    <div class="alert alert-info mb-3">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Critical Control:</strong> Flag any mismatches. Do not approve payment if invoice does not match RFQ, PO, or commitment form.
                    </div>

                    <!-- Action Buttons -->
                    <div class="d-flex gap-2 justify-content-end">
                        <button type="button" class="btn btn-outline-secondary" onclick="history.back()">
                            <i class="bi bi-x-lg me-1"></i>Cancel
                        </button>
                        <button type="submit" name="action" value="VERIFY" class="btn btn-success">
                            <i class="bi bi-check-circle me-1"></i>Submit Verification
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/footer.php"; ?>
