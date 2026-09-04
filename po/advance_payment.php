<?php
/**
 * Record an Advance / Partial Payment against a Purchase Order.
 * The PO must be Open. Posting inserts a row into po_advance_payments
 * with status PENDING_APPROVAL and notifies approvers.
 */
$REQUIRE_PERMISSION = 'record_advance_payment';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/SecureFileStorage.php';

/* ================================
   Validate po_id
================================ */
$po_id = isset($_GET['po_id']) ? (int)$_GET['po_id'] : 0;
if ($po_id <= 0) {
    pop('Invalid Purchase Order reference.', '/po/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

/* ================================
   Fetch PO + Commitment + Supplier
================================ */
$stmt = $pdo->prepare("
    SELECT
        po.po_id,
        po.po_number,
        po.po_total,
        po.status,
        po.po_type,
        po.parent_po_id,
        c.commitment_number,
        pr.currency
    FROM purchase_orders po
    JOIN commitments     c  ON po.commitment_id = c.commitment_id
    JOIN procurement_requests pr ON c.request_id = pr.request_id
    WHERE po.po_id = ?
    LIMIT 1
");
$stmt->execute([$po_id]);
$po = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$po) {
    pop('Purchase Order not found.', '/po/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

if ($po['status'] !== 'Open') {
    pop(
        "Advance payments can only be recorded against an Open Purchase Order.",
        "/po/view.php?po_id={$po_id}",
        POP_DEFAULT_DELAY_MS,
        'warning'
    );
    exit;
}

/* ================================
   Calculate Remaining Balance
   (PO total + approved variations − invoiced − approved advance payments)
================================ */
$approvedPoTotal = (float)$po['po_total'];

$varStmt = $pdo->prepare("
    SELECT COALESCE(SUM(variation_amount), 0)
    FROM po_variations
    WHERE po_id = ? AND status = 'APPROVED'
");
$varStmt->execute([$po_id]);
$approvedPoTotal += (float)$varStmt->fetchColumn();

$invStmt = $pdo->prepare("
    SELECT COALESCE(SUM(invoice_amount), 0) FROM invoices WHERE po_id = ?
");
$invStmt->execute([$po_id]);
$totalInvoiced = (float)$invStmt->fetchColumn();

$advStmt = $pdo->prepare("
    SELECT COALESCE(SUM(payment_amount), 0)
    FROM po_advance_payments
    WHERE po_id = ? AND status = 'APPROVED'
");
$advStmt->execute([$po_id]);
$totalAdvanceApproved = (float)$advStmt->fetchColumn();

$remainingBalance = $approvedPoTotal - $totalInvoiced - $totalAdvanceApproved;
$currency = 'JMD';
if (!empty($po['currency'])) {
    $currency = strtoupper(trim($po['currency']));
}

/* ================================
   Handle POST
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken('/po/view.php?po_id=' . $po_id);

    $paymentType      = trim($_POST['payment_type']      ?? '');
    $paymentAmount    = isset($_POST['payment_amount'])   ? (float)$_POST['payment_amount'] : 0.0;
    $paymentDate      = trim($_POST['payment_date']      ?? '');
    $paymentReference = trim($_POST['payment_reference'] ?? '');
    $paymentMethod    = trim($_POST['payment_method']    ?? '');
    $notes            = trim($_POST['notes']             ?? '');
    $supplierName     = trim($_POST['supplier_name']     ?? '');

    /* --- Validation --- */
    $allowedTypes = ['ADVANCE_PAYMENT', 'PARTIAL_PAYMENT'];
    if (!in_array($paymentType, $allowedTypes, true)) {
        modalPop('Validation Error', 'Invalid payment type selected.', '', 'error');
        exit;
    }

    if ($paymentAmount <= 0) {
        modalPop('Validation Error', 'Payment amount must be greater than zero.', '', 'error');
        exit;
    }

    if ($paymentAmount > $remainingBalance) {
        logAudit($pdo, 'POLICY', null, 'ADVANCE_OVERPAY_ATTEMPT',
            "Advance payment of {$paymentAmount} exceeds remaining balance {$remainingBalance} for PO #{$po_id}");
        modalPop(
            'Amount Exceeds Balance',
            'The advance payment amount cannot exceed the remaining PO balance of ' .
            $currency . ' ' . number_format(max($remainingBalance, 0), 2) . '.',
            '',
            'error'
        );
        exit;
    }

    if ($paymentDate === '') {
        modalPop('Validation Error', 'Payment date is required.', '', 'error');
        exit;
    }

    if ($paymentReference === '') {
        modalPop('Validation Error', 'Payment reference is required.', '', 'error');
        exit;
    }

    /* --- File Upload (optional) --- */
    $docPath         = null;
    $docOriginalName = null;
    $docFileType     = null;
    $docFileSize     = null;

    if (!empty($_FILES['supporting_document']['tmp_name'])) {
        $file     = $_FILES['supporting_document'];
        $allowedMimes = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/gif',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];

        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedMimes, true)) {
            modalPop('Invalid File', 'Unsupported file type. Please upload a PDF, image, or Word document.', '', 'error');
            exit;
        }

        if ($file['size'] > 10 * 1024 * 1024) {
            modalPop('File Too Large', 'Supporting document must be under 10 MB.', '', 'error');
            exit;
        }

        $storedAdvanceDoc = SecureFileStorage::storeUploadedFile(
            $file,
            'advance_payments',
            'AP_' . $po_id,
            [
                'application/pdf' => 'pdf',
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'application/msword' => 'doc',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            ],
            10 * 1024 * 1024
        );

        $docPath         = $storedAdvanceDoc['storage_path'];
        $docOriginalName = $storedAdvanceDoc['original_name'];
        $docFileType     = $storedAdvanceDoc['mime_type'];
        $docFileSize     = $storedAdvanceDoc['file_size'];
    }

    /* --- Insert --- */
    try {
        $ins = $pdo->prepare("
            INSERT INTO po_advance_payments
                (po_id, payment_type, payment_amount, payment_date, payment_reference,
                 supplier_name, payment_method, notes,
                 supporting_document_path, supporting_document_original_name,
                 supporting_document_file_type, supporting_document_file_size,
                 created_by, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING_APPROVAL')
        ");
        $ins->execute([
            $po_id,
            $paymentType,
            $paymentAmount,
            $paymentDate,
            $paymentReference,
            $supplierName ?: null,
            $paymentMethod ?: null,
            $notes ?: null,
            $docPath,
            $docOriginalName,
            $docFileType,
            $docFileSize,
            $_SESSION['user_id'],
        ]);

        $newId = (int)$pdo->lastInsertId();

        logAudit($pdo, 'po_advance_payments', $newId, 'CREATE',
            "Advance payment ({$paymentType}) of {$currency} " .
            number_format($paymentAmount, 2) . " submitted for PO #{$po['po_number']}");

        /* Notify approvers */
        require_once $_SERVER['DOCUMENT_ROOT'] . '/config/notifications.php';
        notifyAdvancePaymentSubmitted($newId);

        header("Location: /po/view.php?po_id={$po_id}&msg=advance_submitted");
        exit;

    } catch (Throwable $e) {
        if (isset($storedAdvanceDoc)) {
            SecureFileStorage::deleteStoredFile($storedAdvanceDoc['storage_path']);
        }
        modalPop('Error', extractDbMessage($e), "/po/view.php?po_id={$po_id}", 'error');
        exit;
    }
}

/* ================================
   Render Form
================================ */
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="container mt-4">

    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col">
            <h3 class="section-title">
                <i class="bi bi-cash-coin me-2 text-primary"></i>Record Advance Payment
            </h3>
            <p class="text-muted mb-0">
                Record an advance or partial payment against
                <strong><?= htmlspecialchars($po['po_number']) ?></strong>
                before the official invoice is received.
            </p>
        </div>
    </div>

    <!-- PO Summary -->
    <div class="card mb-4 border-start border-primary border-3">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i>Purchase Order Summary</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <small class="text-muted d-block">PO Number</small>
                    <strong><?= htmlspecialchars($po['po_number']) ?></strong>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Commitment #</small>
                    <strong><?= htmlspecialchars($po['commitment_number']) ?></strong>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">PO Status</small>
                    <span class="badge bg-success"><?= htmlspecialchars($po['status']) ?></span>
                </div>
            </div>
            <div class="row g-3 mt-2">
                <div class="col-md-4">
                    <small class="text-muted d-block">Original PO Amount</small>
                    <span class="badge bg-secondary fs-6"><?= $currency ?> <?= number_format($po['po_total'], 2) ?></span>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Total Advance Payments (Approved)</small>
                    <span class="badge bg-info text-dark fs-6"><?= $currency ?> <?= number_format($totalAdvanceApproved, 2) ?></span>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Remaining Balance</small>
                    <span class="badge <?= $remainingBalance > 0 ? 'bg-warning text-dark' : 'bg-danger' ?> fs-6">
                        <?= $currency ?> <?= number_format(max($remainingBalance, 0), 2) ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <?php if ($remainingBalance <= 0): ?>
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <strong>No Remaining Balance.</strong>
        The full PO value has already been invoiced or advanced. No further advance payments can be recorded.
    </div>
    <?php else: ?>

    <!-- Payment Form -->
    <div class="card border-secondary mb-4">
        <div class="card-header bg-secondary text-white">
            <h5 class="mb-0"><i class="bi bi-pencil-square me-2"></i>Advance Payment Details</h5>
        </div>
        <div class="card-body">
            <form method="post" enctype="multipart/form-data" id="advancePaymentForm"
                  onsubmit="return validateAdvancePaymentForm()">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ensureCsrfToken()) ?>">

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label for="payment_type" class="form-label">
                            <i class="bi bi-tag me-1"></i><span class="text-danger">*</span> Payment Type
                        </label>
                        <select name="payment_type" id="payment_type" class="form-select form-select-lg" required>
                            <option value="">— Select Type —</option>
                            <option value="ADVANCE_PAYMENT">Advance Payment</option>
                            <option value="PARTIAL_PAYMENT">Partial Payment</option>
                        </select>
                        <small class="text-muted">Final payments are processed through the invoice workflow.</small>
                    </div>
                    <div class="col-md-6">
                        <label for="payment_method" class="form-label">
                            <i class="bi bi-credit-card me-1"></i> Payment Method
                        </label>
                        <select name="payment_method" id="payment_method" class="form-select form-select-lg">
                            <option value="">— Select Method —</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Cheque">Cheque</option>
                            <option value="Cash">Cash</option>
                            <option value="Online Transfer">Online Transfer</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label for="payment_amount" class="form-label">
                            <i class="bi bi-currency-dollar me-1"></i><span class="text-danger">*</span> Amount
                        </label>
                        <div class="input-group">
                            <span class="input-group-text"><?= $currency ?></span>
                            <input type="number" step="0.01" name="payment_amount" id="payment_amount"
                                   class="form-control form-control-lg"
                                   min="0.01" max="<?= number_format(max($remainingBalance, 0), 2, '.', '') ?>"
                                   required placeholder="0.00"
                                   onchange="updateAmountFeedback()" onkeyup="updateAmountFeedback()">
                        </div>
                        <small class="text-muted">
                            Maximum: <?= $currency ?> <?= number_format(max($remainingBalance, 0), 2) ?>
                        </small>
                    </div>
                    <div class="col-md-6">
                        <label for="payment_date" class="form-label">
                            <i class="bi bi-calendar-event me-1"></i><span class="text-danger">*</span> Payment Date
                        </label>
                        <input type="date" name="payment_date" id="payment_date"
                               class="form-control form-control-lg"
                               max="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label for="payment_reference" class="form-label">
                            <i class="bi bi-hash me-1"></i><span class="text-danger">*</span> Payment Reference
                        </label>
                        <input type="text" name="payment_reference" id="payment_reference"
                               class="form-control form-control-lg" required
                               placeholder="e.g. CHQ-001, TRF-20260827">
                    </div>
                    <div class="col-md-6">
                        <label for="supplier_name" class="form-label">
                            <i class="bi bi-building me-1"></i> Supplier / Payee
                        </label>
                        <input type="text" name="supplier_name" id="supplier_name"
                               class="form-control form-control-lg"
                               value=""
                               placeholder="Supplier name">
                    </div>
                </div>

                <div class="mb-3">
                    <label for="notes" class="form-label">
                        <i class="bi bi-journal-text me-1"></i> Notes / Reason
                    </label>
                    <textarea name="notes" id="notes" class="form-control" rows="3"
                              placeholder="Describe the reason for this advance payment…"></textarea>
                </div>

                <div class="mb-4">
                    <label for="supporting_document" class="form-label">
                        <i class="bi bi-paperclip me-1"></i> Supporting Document
                        <small class="text-muted">(optional — PDF, image, or Word; max 10 MB)</small>
                    </label>
                    <input type="file" name="supporting_document" id="supporting_document"
                           class="form-control"
                           accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx">
                </div>

                <div class="alert alert-light border border-warning mb-4" id="amountFeedback"
                     style="display:none;"></div>

                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    This payment will be submitted for approval. The Purchase Order will <strong>remain Open</strong>
                    until the final invoice is processed.
                </div>

                <div class="d-flex gap-2 justify-content-between">
                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="bi bi-send me-1"></i>Submit for Approval
                    </button>
                    <a href="/po/view.php?po_id=<?= $po_id ?>" class="btn btn-outline-secondary btn-lg">
                        <i class="bi bi-arrow-left me-1"></i>Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="mb-4">
        <a href="/po/view.php?po_id=<?= $po_id ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back to Purchase Order
        </a>
    </div>
</div>

<script>
function updateAmountFeedback() {
    const amount  = parseFloat(document.getElementById('payment_amount').value) || 0;
    const balance = <?= number_format(max($remainingBalance, 0), 2, '.', '') ?>;
    const fb      = document.getElementById('amountFeedback');
    if (amount > 0) {
        fb.style.display = 'block';
        if (amount > balance) {
            fb.className = 'alert alert-danger border border-danger mb-4';
            fb.textContent = 'Amount exceeds the remaining PO balance!';
        } else {
            fb.className = 'alert alert-light border border-warning mb-4';
            fb.textContent = 'Remaining balance after this payment: <?= htmlspecialchars($currency) ?> ' +
                (balance - amount).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
        }
    } else {
        fb.style.display = 'none';
    }
}

function validateAdvancePaymentForm() {
    const type    = document.getElementById('payment_type').value;
    const amount  = parseFloat(document.getElementById('payment_amount').value) || 0;
    const date    = document.getElementById('payment_date').value;
    const ref     = document.getElementById('payment_reference').value.trim();
    const balance = <?= number_format(max($remainingBalance, 0), 2, '.', '') ?>;

    if (!type) {
        alert('Please select a payment type.');
        document.getElementById('payment_type').focus();
        return false;
    }
    if (amount <= 0) {
        alert('Please enter a valid payment amount greater than zero.');
        document.getElementById('payment_amount').focus();
        return false;
    }
    if (amount > balance) {
        alert('Payment amount cannot exceed the remaining PO balance.');
        document.getElementById('payment_amount').focus();
        return false;
    }
    if (!date) {
        alert('Please select a payment date.');
        document.getElementById('payment_date').focus();
        return false;
    }
    if (!ref) {
        alert('Please enter a payment reference.');
        document.getElementById('payment_reference').focus();
        return false;
    }
    return confirm('Submit this advance payment for approval?');
}
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
