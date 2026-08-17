<?php
/**
 * Add Invoice for Service Contract (no PO required)
 * Links invoice to commitment_id and contract_id directly
 */
$REQUIRE_PERMISSION = 'create_invoice';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';

/* ================================
   Validate commitment_id
================================ */
$commitment_id = isset($_GET['commitment_id']) ? (int)$_GET['commitment_id'] : 0;
if ($commitment_id <= 0) {
    modalPop('Error', 'Invalid commitment reference.', '/commitments/list.php', 'error');
    exit;
}

/* ================================
   Fetch Commitment & related data
================================ */
$stmt = $pdo->prepare("
    SELECT c.commitment_id, c.commitment_number, c.commitment_total, c.request_id,
           c.contract_id, c.status AS commitment_status, c.po_required,
           pr.request_number, pr.request_type, pr.estimated_value, pr.currency,
           pr.contract_id AS pr_contract_id,
           sc.contract_number, sc.contract_title, sc.total_value AS contract_total,
           sc.consumed_value AS contract_consumed,
           v.vendor_name
    FROM commitments c
    JOIN procurement_requests pr ON c.request_id = pr.request_id
    LEFT JOIN service_contracts sc ON (c.contract_id = sc.contract_id OR pr.contract_id = sc.contract_id)
    LEFT JOIN vendors v ON sc.vendor_id = v.vendor_id
    WHERE c.commitment_id = ?
");
$stmt->execute([$commitment_id]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) {
    modalPop('Error', 'Commitment not found.', '/commitments/list.php', 'error');
    exit;
}

// Allow for SERVICE_CONTRACT requests OR REGULAR requests with po_required='NO'
if ($data['request_type'] !== 'SERVICE_CONTRACT' && ($data['po_required'] ?? 'YES') !== 'NO') {
    modalPop('Error', 'This page is for service contract invoices or non-PO regular procurement. Use the PO invoice page for regular procurement with PO.', '/commitments/list.php', 'error');
    exit;
}

$currency = $data['currency'] ?? 'JMD';
$contract_id = $data['contract_id'] ?: $data['pr_contract_id'];

/* ================================
   Calculate Remaining Balance
================================ */
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(invoice_amount), 0)
    FROM invoices
    WHERE commitment_id = ?
");
$stmt->execute([$commitment_id]);
$totalInvoiced = (float)$stmt->fetchColumn();

$commitmentTotal = (float)$data['commitment_total'];
$remaining = $commitmentTotal - $totalInvoiced;

/* ================================
   Handle POST
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $invoiceNumber  = trim($_POST['invoice_number'] ?? '');
    $invoiceDateRaw = trim($_POST['invoice_date'] ?? '');
    $invoiceAmount  = isset($_POST['invoice_amount']) ? (float)$_POST['invoice_amount'] : 0.0;

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

    if ($invoiceAmount > $remaining) {
        modalPop('Commitment Limit Exceeded', 'Invoice exceeds remaining commitment balance.', '', 'error');
        exit;
    }

    /* Duplicate check */
    $stmt = $pdo->prepare("SELECT 1 FROM invoices WHERE invoice_number = ? LIMIT 1");
    $stmt->execute([$invoiceNumber]);
    if ($stmt->fetchColumn()) {
        modalPop('Duplicate Invoice Number', "Invoice number \"$invoiceNumber\" has already been used.", '', 'error');
        exit;
    }

    /* ================================
       Insert Invoice (no po_id, uses commitment_id and contract_id)
    ================================ */
    try {
        $stmt = $pdo->prepare("
            INSERT INTO invoices
            (po_id, commitment_id, contract_id, invoice_number, invoice_date, invoice_amount)
            VALUES (NULL, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $commitment_id,
            $contract_id,
            $invoiceNumber,
            $invoiceDateRaw,
            $invoiceAmount
        ]);

        $invoice_id = $pdo->lastInsertId();

        logAudit($pdo, 'invoices', $invoice_id, 'CREATE',
            'Service contract invoice added by user ID ' . $_SESSION['user_id']);

        // Update contract consumed_value (lock row to prevent race condition)
        if ($contract_id) {
            $pdo->prepare("SELECT contract_id FROM service_contracts WHERE contract_id = ? FOR UPDATE")->execute([$contract_id]);
            $pdo->prepare("
                UPDATE service_contracts
                SET consumed_value = consumed_value + ?
                WHERE contract_id = ?
            ")->execute([$invoiceAmount, $contract_id]);
        }

        // Advance procurement request status to INVOICE_RECEIVED
        $request_id = $data['request_id'];
        if ($request_id) {
            $pdo->prepare("
                UPDATE procurement_requests
                SET status = 'INVOICE_RECEIVED'
                WHERE request_id = ? AND status = 'COMMITMENT_APPROVED'
            ")->execute([$request_id]);

            logRequestTimeline($pdo, $request_id, 'INVOICE_RECEIVED',
                "Invoice #{$invoiceNumber} received. Amount: " . number_format($invoiceAmount, 2));

            require_once $_SERVER['DOCUMENT_ROOT']."/config/notifications.php";
            notifyInvoiceReceived($request_id, $invoiceNumber, $data['commitment_number'], $invoiceAmount);
        }

        header("Location: /invoice/view.php?id=" . (int)$invoice_id);
        exit;

    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            modalPop('Duplicate Invoice Number', "Invoice number \"$invoiceNumber\" already exists.", '', 'error');
            exit;
        }
        modalPop('Database Error', extractDbMessage($e), '', 'error');
        exit;
    }
}

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
                <i class="bi bi-receipt me-2"></i>Add Invoice (Service Contract)
            </h3>
            <p class="text-muted">Record an invoice against this commitment — no PO required</p>
        </div>
    </div>

    <!-- Commitment Summary Card -->
    <div class="card mb-4 border-start border-info border-3">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i>Commitment Summary</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <small class="text-muted d-block">Commitment</small>
                    <span class="badge bg-primary fs-6"><?= htmlspecialchars($data['commitment_number']) ?></span>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Contract</small>
                    <strong><?= htmlspecialchars($data['contract_number'] ?? '—') ?></strong>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Vendor</small>
                    <strong><?= htmlspecialchars($data['vendor_name'] ?? '—') ?></strong>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Request</small>
                    <a href="/procurement/view.php?id=<?= (int)$data['request_id'] ?>"><?= htmlspecialchars($data['request_number']) ?></a>
                </div>
            </div>
            <div class="row g-3 mt-3">
                <div class="col-md-4">
                    <small class="text-muted d-block">Committed Amount</small>
                    <span class="badge bg-success fs-6"><?= htmlspecialchars($currency) ?> <?= number_format($commitmentTotal, 2) ?></span>
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
        </div>
    </div>

    <!-- Invoice Form -->
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
                           placeholder="Enter vendor invoice number">
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
                               placeholder="Enter invoice amount">
                    </div>
                    <small class="text-muted d-block mt-2">Cannot exceed remaining commitment balance.</small>
                </div>
                <div class="d-grid gap-2 d-sm-flex justify-content-between">
                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="bi bi-check-circle me-1"></i>Save Invoice
                    </button>
                    <a href="/procurement/view.php?id=<?= (int)$data['request_id'] ?>" class="btn btn-outline-secondary btn-lg">
                        <i class="bi bi-arrow-left me-1"></i>Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <i class="bi bi-lightbulb me-1"></i>
                <strong>Note:</strong> Service contract invoices do not require a Purchase Order. They are linked directly to the commitment.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        </div>
    </div>
</div>

<script>
function validateInvoiceForm() {
    const number = document.getElementById('invoice_number').value.trim();
    const date   = document.getElementById('invoice_date').value;
    const amount = parseFloat(document.getElementById('invoice_amount').value) || 0;
    const remaining = <?= number_format($remaining, 2, '.', '') ?>;

    if (!number) { alert('Please enter an invoice number.'); return false; }
    if (!date) { alert('Please select an invoice date.'); return false; }
    if (amount <= 0) { alert('Please enter a valid invoice amount.'); return false; }
    if (amount > remaining) { alert('Invoice amount cannot exceed remaining commitment balance.'); return false; }
    return confirm('Save this invoice?');
}
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT']."/includes/footer.php"; ?>
