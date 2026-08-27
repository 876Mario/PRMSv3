<?php
$REQUIRE_PERMISSION = 'create_invoice';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT']."/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/helper.php";

/* ================================
   Validate po_id
================================ */
$po_id = isset($_GET['po_id']) ? (int)$_GET['po_id'] : 0;
if ($po_id <= 0) {
    modalPop('Error', 'Invalid PO reference.', '/po/list.php', 'error');
    exit;
}

/* ================================
   Fetch PO
================================ */
$stmt = $pdo->prepare("
    SELECT po.po_id, po.commitment_id, po.po_total, po.status, po.po_type, po.parent_po_id, po.po_number, pr.currency
    FROM purchase_orders po
    JOIN commitments c ON po.commitment_id = c.commitment_id
    JOIN procurement_requests pr ON c.request_id = pr.request_id
    WHERE po.po_id = ?
");
$stmt->execute([$po_id]);
$po = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$po) {
    modalPop('Error', 'Purchase Order not found.', '/po/list.php', 'error');
    exit;
}

$currency = 'JMD';

/* ================================
   Check PO is Open before invoicing
================================ */
if ($po['status'] !== 'Open') {
    pop(
        "Purchase Order must be Open before invoicing.",
        "/po/view.php?po_id=$po_id",
        POP_DEFAULT_DELAY_MS
    );
    exit;
}



/* ================================
   Determine Original PO
================================ */
$originalPoId = $po['po_type'] === 'ADJUSTMENT'
    ? (int)$po['parent_po_id']
    : (int)$po['po_id'];


if (in_array($po['status'], ['Closed', 'Cancelled'], true)) {
    modalPop(
        'Error',
        "Invoices cannot be added to a {$po['status']} PO.",
        "/po/view.php?po_id=" . (int)$po_id,
        'error'
    );
    exit;
}

/* ================================
   Calculate Remaining Balance
================================ */

/* 1. Approved PO total (original + approved variations) */
$stmt = $pdo->prepare("
    SELECT po_total + COALESCE(
        (SELECT SUM(variation_amount)
         FROM po_variations
         WHERE po_id = ? AND status = 'APPROVED'), 0
    ) AS approved_total
    FROM purchase_orders
    WHERE po_id = ?
");
$stmt->execute([$po_id, $po_id]);
$approvedPoTotal = (float)$stmt->fetchColumn();

/* 2. Total already invoiced against this PO */
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(invoice_amount), 0)
    FROM invoices
    WHERE po_id = ?
");
$stmt->execute([$po_id]);
$totalInvoiced = (float)$stmt->fetchColumn();

/* 3. Total approved advance payments */
$apStmt = $pdo->prepare("
    SELECT COALESCE(SUM(payment_amount), 0)
    FROM po_advance_payments
    WHERE po_id = ? AND status = 'APPROVED'
");
$apStmt->execute([$po_id]);
$totalAdvancePayments = (float)$apStmt->fetchColumn();

/* 4. Remaining balance (net of advances) */
$remaining = $approvedPoTotal - $totalInvoiced - $totalAdvancePayments;


/* ================================
   Handle POST
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $invoiceNumber  = trim($_POST['invoice_number'] ?? '');
    $invoiceDateRaw = trim($_POST['invoice_date'] ?? '');
    $invoiceAmount  = isset($_POST['invoice_amount'])
        ? (float)$_POST['invoice_amount']
        : 0.0;

    if ($invoiceNumber === '') {
        modalPop('Error', 'Invoice number is required.', '', 'error');
        exit;
    }

    if ($invoiceDateRaw === '') {
        modalPop('Error', 'Invoice date is required.', '', 'error');
        exit;
    }

    $tz = new DateTimeZone(date_default_timezone_get());
    $invoiceDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $invoiceDateRaw, $tz);

    if ($invoiceDateObj === false) {
        modalPop('Error', 'Invalid invoice date format.', '', 'error');
        exit;
    }

    $invoiceDate = $invoiceDateObj->setTime(0, 0, 0);
    $today = (new DateTimeImmutable('now', $tz))->setTime(0, 0, 0);

    if ($invoiceDate > $today) {
        modalPop('Error', 'Invoice date cannot be in the future.', '', 'error');
        exit;
    }

    if ($invoiceAmount <= 0) {
        modalPop('Error', 'Invoice amount must be greater than zero.', '', 'error');
        exit;
    }

    /* 🚨 THIS IS THE CRITICAL BLOCK */
    if ($invoiceAmount > $remaining) {
        
                      $pdo->prepare("
    INSERT INTO po_warnings
    (po_id, warning_type, message, created_at)
    VALUES (?, 'PO_LIMIT_ATTEMPT', ?, NOW())
")->execute([
    $originalPoId,
    'Invoice attempt exceeded approved PO total (including variations)'
]);


        
        modalPop(
            'PO Limit Exceeded',
            'Invoice exceeds remaining PO balance. Please create a PO Variation.',
            "/po/view.php?po_id=" . (int)$po_id,
            'error'
        );
        exit; // 🔒 ABSOLUTE STOP
    }



    /* ================================
Pre-check before insert (better UX)
================================ */
 
$stmt = $pdo->prepare("
    SELECT 1
    FROM invoices
    WHERE invoice_number = ?
    LIMIT 1
");
$stmt->execute([$invoiceNumber]);

if ($stmt->fetchColumn()) {
    modalPop(
        'Duplicate Invoice Number',
        'Invoice number "'.$invoiceNumber.'" has already been used.',
        "/invoice/add.php?po_id=".$po_id,
        'error'
    );
    exit;
}

    /* ================================
       Insert Invoice
    ================================ */
 
 try {
    $stmt = $pdo->prepare("
        INSERT INTO invoices
        (po_id, invoice_number, invoice_date, invoice_amount)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([
        $po_id,
        $invoiceNumber,
        $invoiceDateRaw,
        $invoiceAmount
    ]);

} catch (PDOException $e) {

    // 🔒 Duplicate invoice number
    if ($e->getCode() === '23000') {
        modalPop(
            'Duplicate Invoice Number',
            'Invoice number "'.$invoiceNumber.'" already exists. Please verify the invoice number and try again.',
            "/invoice/add.php?po_id=".$po_id,
            'error'
        );
        exit;
    }

    // 🔥 Any other DB error (e.g. trigger SIGNAL)
    modalPop(
        'Database Error',
        extractDbMessage($e),
        "/invoice/add.php?po_id=".$po_id,
        'error'
    );
    exit;
}
    $invoice_id = $pdo->lastInsertId();

    logAudit($pdo, 'invoices', $invoice_id, 'CREATE',
        'Invoice added by user ID ' . $_SESSION['user_id']);

    // Advance procurement request status to INVOICE_RECEIVED
    $stmtReq = $pdo->prepare("
        SELECT c.request_id
        FROM commitments c
        JOIN purchase_orders po ON po.commitment_id = c.commitment_id
        WHERE po.po_id = ?
        LIMIT 1
    ");
    $stmtReq->execute([$po_id]);
    $invoiceRequestId = $stmtReq->fetchColumn();
    if ($invoiceRequestId) {
        $pdo->prepare("
            UPDATE procurement_requests
            SET status = 'INVOICE_RECEIVED'
            WHERE request_id = ?
        ")->execute([$invoiceRequestId]);

        logRequestTimeline($pdo, $invoiceRequestId, 'INVOICE_RECEIVED',
            "Invoice #{$invoiceNumber} received for PO {$po['po_number']}");
        
        // Notify about invoice received
        require_once $_SERVER['DOCUMENT_ROOT']."/config/notifications.php";
        notifyInvoiceReceived($invoiceRequestId, $invoiceNumber, $po['po_number'], $invoiceAmount);
    }

    /* Recalculate — auto-close PO if fully invoiced */
    $stmt = $pdo->prepare("SELECT IFNULL(SUM(invoice_amount),0) FROM invoices WHERE po_id = ?");
    $stmt->execute([$po_id]);
    $newTotalInvoiced = (float)$stmt->fetchColumn();

    if (($approvedPoTotal - $newTotalInvoiced) <= 0.009) {
        $pdo->prepare("UPDATE purchase_orders SET status='Closed' WHERE po_id=?")->execute([$po_id]);
    }

    header("Location: /po/view.php?po_id=" . (int)$po_id);
    exit;
}

/* ================================
   Check form lock (PO warning without approved variation)
================================ */
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM po_warnings
    WHERE po_id = ? AND warning_type = 'PO_LIMIT_ATTEMPT'
");
$stmt->execute([$po_id]);
$hasLimitWarning = (int)$stmt->fetchColumn() > 0;

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM commitments
    WHERE parent_commitment_id = (
        SELECT commitment_id FROM purchase_orders WHERE po_id = ?
    )
    AND commitment_type = 'SUPPLEMENTARY'
");
$stmt->execute([$po_id]);
$hasApprovedVariation = (int)$stmt->fetchColumn() > 0;

$formLocked = ($hasLimitWarning && !$hasApprovedVariation);

/* ================================
   Render Page
================================ */
require_once $_SERVER['DOCUMENT_ROOT']."/includes/header.php";
$todayStr = (new DateTimeImmutable('today'))->format('Y-m-d');
?>

<div class="container mt-4">
    <div class="row mb-4">
        <div class="col-lg-8">
            <h3 class="section-title">
                <i class="bi bi-receipt me-2"></i>Add Invoice
            </h3>
            <p class="text-muted">Record a new invoice against this purchase order</p>
        </div>
    </div>

    <!-- PO Summary Card -->
    <div class="card mb-4 border-start border-info border-3">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0"><i class="bi bi-box-seam me-2"></i>PO Summary</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <small class="text-muted d-block">PO Number</small>
                    <span class="badge bg-primary fs-6"><?= htmlspecialchars($po['po_number']) ?></span>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">PO Total</small>
                    <span class="badge bg-success fs-6"><?= htmlspecialchars($currency) ?> <?= number_format((float)$po['po_total'], 2) ?></span>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Status</small>
                    <span class="badge bg-info fs-6"><?= htmlspecialchars($po['status']) ?></span>
                </div>
            </div>
            <div class="row g-3 mt-3">
                <div class="col-md-4">
                    <small class="text-muted d-block">Approved PO Total</small>
                    <span class="badge bg-success fs-6"><?= htmlspecialchars($currency) ?> <?= number_format($approvedPoTotal, 2) ?></span>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Total Invoiced</small>
                    <span class="badge bg-info fs-6"><?= htmlspecialchars($currency) ?> <?= number_format($totalInvoiced, 2) ?></span>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Remaining Balance</small>
                    <span class="badge bg-warning text-dark fs-6"><?= htmlspecialchars($currency) ?> <?= number_format($remaining, 2) ?></span>
                </div>
            </div>
            <?php if ($totalAdvancePayments > 0): ?>
            <div class="row g-3 mt-1">
                <div class="col-12">
                    <div class="alert alert-warning mb-0 py-2">
                        <i class="bi bi-cash-coin me-2"></i>
                        <strong>Advance Payments Applied:</strong>
                        <?= htmlspecialchars($currency) ?> <?= number_format($totalAdvancePayments, 2) ?> in approved advance payments have been deducted from the remaining balance.
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($formLocked): ?>
    <!-- Form Locked Alert -->
    <div class="alert alert-danger border-0 shadow-sm">
        <i class="bi bi-lock me-2"></i>
        <strong>Invoice entry is locked.</strong>
        A PO Variation must be approved before invoices can be added.
    </div>
    <a href="/po/variation_create.php?po_id=<?= (int)$po_id ?>" class="btn btn-warning btn-lg">
        <i class="bi bi-plus-lg me-1"></i>Create PO Variation
    </a>

    <?php else: ?>
    <!-- Invoice Form Card -->
    <div class="card border-secondary mb-4">
        <div class="card-header bg-secondary text-white">
            <h5 class="mb-0"><i class="bi bi-pencil-square me-2"></i>Invoice Details</h5>
        </div>
        <div class="card-body">
            <form method="post" id="invoiceForm" onsubmit="return validateInvoiceForm()">
                <div class="mb-4">
                    <label for="invoice_number" class="form-label">
                        <i class="bi bi-receipt"></i> <span class="text-danger">*</span> Invoice Number
                    </label>
                    <input type="text" name="invoice_number" id="invoice_number"
                           class="form-control form-control-lg" required
                           placeholder="Enter invoice number">
                </div>
                <div class="mb-4">
                    <label for="invoice_date" class="form-label">
                        <i class="bi bi-calendar-event"></i> <span class="text-danger">*</span> Invoice Date
                    </label>
                    <input type="date" name="invoice_date" id="invoice_date"
                           class="form-control form-control-lg" required
                           max="<?= $todayStr ?>">
                </div>
                <div class="mb-4">
                    <label for="invoice_amount" class="form-label">
                        <i class="bi bi-currency-dollar"></i> <span class="text-danger">*</span> Invoice Amount
                    </label>
                    <div class="input-group">
                        <span class="input-group-text"><?= htmlspecialchars($currency) ?></span>
                        <input type="number" step="0.01" name="invoice_amount" id="invoice_amount"
                               class="form-control form-control-lg" min="0.01"
                               max="<?= number_format($remaining, 2, '.', '') ?>" required
                               placeholder="Enter invoice amount"
                               onchange="updateAmountFeedback()"
                               onkeyup="updateAmountFeedback()">
                    </div>
                    <small class="text-muted d-block mt-2">Cannot exceed remaining PO balance.</small>
                </div>
                <div class="alert alert-light border border-warning p-3 mb-4" id="amountFeedback" style="display: none;"></div>
                <div class="d-grid gap-2 d-sm-flex justify-content-between">
                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="bi bi-check-circle me-1"></i>Save Invoice
                    </button>
                    <a href="/po/view.php?po_id=<?= (int)$po_id ?>" class="btn btn-outline-secondary btn-lg">
                        <i class="bi bi-arrow-left me-1"></i>Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Info Box -->
    <div class="row">
        <div class="col-lg-8">
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <i class="bi bi-lightbulb me-1"></i>
                <strong>Note:</strong> Invoices update PO status automatically. Over-invoicing is blocked.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        </div>
    </div>
</div>

<script>
function updateAmountFeedback() {
    const amount = parseFloat(document.getElementById('invoice_amount').value) || 0;
    const remaining = <?= number_format($remaining, 2, '.', '') ?>;
    const feedback = document.getElementById('amountFeedback');
    if (amount > 0) {
        feedback.style.display = 'block';
        if (amount > remaining) {
            feedback.className = 'alert alert-danger border border-danger p-3 mb-4';
            feedback.textContent = 'Amount exceeds remaining PO balance!';
        } else {
            feedback.className = 'alert alert-light border border-warning p-3 mb-4';
            feedback.textContent = 'Invoice will reduce balance to <?= htmlspecialchars($currency) ?> ' +
                (remaining - amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }
    } else {
        feedback.style.display = 'none';
    }
}

function validateInvoiceForm() {
    const number = document.getElementById('invoice_number').value.trim();
    const date   = document.getElementById('invoice_date').value;
    const amount = parseFloat(document.getElementById('invoice_amount').value) || 0;
    const remaining = <?= number_format($remaining, 2, '.', '') ?>;

    if (!number) {
        alert('Please enter an invoice number.');
        document.getElementById('invoice_number').focus();
        return false;
    }
    if (!date) {
        alert('Please select an invoice date.');
        document.getElementById('invoice_date').focus();
        return false;
    }
    if (amount <= 0) {
        alert('Please enter a valid invoice amount.');
        document.getElementById('invoice_amount').focus();
        return false;
    }
    if (amount > remaining) {
        alert('Invoice amount cannot exceed remaining PO balance.');
        document.getElementById('invoice_amount').focus();
        return false;
    }
    return confirm('Save this invoice?');
}
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT']."/includes/footer.php"; ?>