<?php
$REQUIRE_PERMISSION = 'upload_rfq_quote';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';

$vendor_id = (int)($_GET['vendor_id'] ?? 0);

if ($vendor_id <= 0) {
    pop('Invalid vendor', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

/* Get Vendor + RFQ */
$stmt = $pdo->prepare("
    SELECT v.*, r.status AS rfq_status
    FROM rfq_vendors v
    JOIN rfqs r ON v.rfq_id = r.rfq_id
    WHERE v.rfq_vendor_id = ?
");
$stmt->execute([$vendor_id]);
$vendor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$vendor) {
    pop('Vendor not found', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

if ($vendor['rfq_status'] === 'AWARDED') {
    pop('RFQ already awarded. Upload locked.', '/rfq/view.php?id='.$vendor['rfq_id'], POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$rfq_id = $vendor['rfq_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $amount = $_POST['quote_amount'] ?? 0;
    $gct    = $_POST['gct_amount'] ?? 0;
    $quoteCurrency = in_array(($_POST['quote_currency'] ?? ''), ['JMD', 'USD']) ? $_POST['quote_currency'] : 'JMD';
    $quoteUsdRate = null;
    if ($quoteCurrency === 'USD') {
        $rateStmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'usd_to_jmd_rate'");
        $rateStmt->execute();
        $quoteUsdRate = (float)($rateStmt->fetchColumn() ?: 155.00);
    }

    if (!$amount || !isset($_FILES['quote_file'])) {
        pop('Missing required fields', '/rfq/upload_quote.php?vendor_id='.$vendor_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    /* Upload Handling */
    $uploadDir = $_SERVER['DOCUMENT_ROOT'] . "/uploads/quotes/";

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $fileName = time() . "_" . basename($_FILES['quote_file']['name']);
    $targetPath = $uploadDir . $fileName;

    $allowedTypes = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
    ];

    if (!in_array($_FILES['quote_file']['type'], $allowedTypes)) {
        pop('Only PDF and Excel files are allowed', '/rfq/upload_quote.php?vendor_id='.$vendor_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    if ($_FILES['quote_file']['size'] > 50 * 1024 * 1024) { // 50 MB
        pop('File size exceeds 50 MB limit', '/rfq/upload_quote.php?vendor_id='.$vendor_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    if (!move_uploaded_file($_FILES['quote_file']['tmp_name'], $targetPath)) {
        pop('Upload failed', '/rfq/upload_quote.php?vendor_id='.$vendor_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    /* Insert Quote */
    try {
    $stmt = $pdo->prepare("
        INSERT INTO rfq_quotes 
        (rfq_vendor_id, quote_amount, gct_amount, validity_days, quote_file, currency, usd_rate)
        VALUES (?, ?, ?, 30, ?, ?, ?)
    ");
    $stmt->execute([
        $vendor_id,
        $amount,
        $gct,
        $fileName,
        $quoteCurrency,
        $quoteUsdRate
    ]);

    /* Update Vendor Status */
    $pdo->prepare("
        UPDATE rfq_vendors
        SET response_status = 'SUBMITTED'
        WHERE rfq_vendor_id = ?
    ")->execute([$vendor_id]);

    /* Initialize requestor/branch-head approval state if this is the first quote */
    $stmtQuoteCount = $pdo->prepare("
        SELECT COUNT(*) as quote_count FROM rfq_quotes q
        JOIN rfq_vendors rv ON q.rfq_vendor_id = rv.rfq_vendor_id
        WHERE rv.rfq_id = ?
    ");
    $stmtQuoteCount->execute([$rfq_id]);
    $quoteCountResult = $stmtQuoteCount->fetch(PDO::FETCH_ASSOC);

    if ($quoteCountResult && $quoteCountResult['quote_count'] == 1) {
        $pdo->prepare("
            UPDATE rfqs
               SET requestor_spec_review_status = 'PENDING',
                   requestor_reviewer_id = NULL,
                   requestor_reviewed_at = NULL,
                   requestor_review_comments = NULL,
                   branch_head_approval_status = 'PENDING',
                   branch_head_approver_id = NULL,
                   branch_head_approved_at = NULL,
                   branch_head_comments = NULL
             WHERE rfq_id = ?
        ")->execute([$rfq_id]);
    }

    /* Audit */
    $pdo->prepare("
        INSERT INTO audit_log (table_name, action, notes, change_date, approval_stage)
        VALUES ('rfq_quotes','UPLOAD',?, NOW(), 'QUOTE_UPLOAD')
    ")->execute([
        "Quote uploaded for RFQ ID $rfq_id"
    ]);

    /* Notify Procurement Officer & requestor that a vendor quote was received */
    require_once $_SERVER['DOCUMENT_ROOT']."/config/notifications.php";
    notifyQuoteUploaded($rfq_id, $vendor['vendor_name']);

    
    header("Location: view.php?id=" . $rfq_id);
    exit;
    } catch (Throwable $e) {
        require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
        pop(extractDbMessage($e), '/rfq/upload_quote.php?vendor_id=' . $vendor_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/header.php";
$vendorName = htmlspecialchars($vendor['vendor_name'] ?? '');
?>

<!-- Page Header -->
<div class="d-flex flex-wrap align-items-center justify-content-between mb-4">
    <div>
        <a href="view.php?id=<?= $rfq_id ?>" class="text-decoration-none text-muted small">
            <i class="bi bi-arrow-left me-1"></i>Back to RFQ
        </a>
        <h4 class="fw-bold mt-2 mb-1" style="color:#1a1a2e;">
            <i class="bi bi-cloud-arrow-up"></i> Upload Vendor Quote
        </h4>
        <p class="text-muted mb-0 small">Submit a quotation on behalf of the selected vendor</p>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-lg-7 col-xl-6">

        <!-- Vendor Info Card -->
        <div class="card border-0 shadow-sm rounded-4 mb-4" style="border-left:4px solid #0b5e2b !important;">
            <div class="card-body d-flex align-items-center gap-3 py-3">
                <div class="rounded-circle d-flex align-items-center justify-content-center"
                     style="width:48px;height:48px;background:#d1e7dd;color:#0b5e2b;font-size:1.3rem;flex-shrink:0;">
                    <i class="bi bi-building"></i>
                </div>
                <div>
                    <div class="fw-semibold"><?= $vendorName ?></div>
                    <span class="badge bg-light text-dark border small">
                        <i class="bi bi-tag me-1"></i>Vendor ID: <?= $vendor_id ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Upload Form Card -->
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-0 rounded-top-4 pt-4 pb-2 px-4">
                <h6 class="fw-semibold mb-1"><i class="bi bi-receipt me-1"></i> Quote Details</h6>
                <p class="text-muted small mb-0">All fields are required. Only PDF files are accepted.</p>
            </div>
            <div class="card-body px-4 pb-4">
                <form method="POST" enctype="multipart/form-data">

                    <div class="row g-3 mb-3">
                        <div class="col-sm-4">
                            <label class="form-label fw-semibold small">
                                <i class="bi bi-currency-exchange me-1 text-primary"></i>Currency
                            </label>
                            <select name="quote_currency" class="form-select" id="quote_currency" onchange="updateQuoteCurrencyLabel()">
                                <option value="JMD" selected>JMD</option>
                                <option value="USD">USD</option>
                            </select>
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label fw-semibold small">
                                <i class="bi bi-cash-stack me-1 text-success"></i>Quote Amount
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0" id="quoteCurrLabel">$</span>
                                <input type="number" step="0.01" min="0" name="quote_amount"
                                       class="form-control border-start-0" placeholder="0.00" required>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label fw-semibold small">
                                <i class="bi bi-percent me-1 text-warning"></i>GCT Amount
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0" id="gctCurrLabel">$</span>
                                <input type="number" step="0.01" min="0" name="gct_amount"
                                       class="form-control border-start-0" placeholder="0.00" required>
                            </div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold small">
                            <i class="bi bi-file-earmark-pdf me-1 text-danger"></i>Quote Document
                        </label>
                        <div class="border rounded-3 p-4 text-center bg-light" id="dropZone"
                             style="border-style:dashed !important; cursor:pointer; transition:all .2s;">
                            <i class="bi bi-cloud-arrow-up fs-1 d-block mb-2" style="color:#0b5e2b;"></i>
                            <p class="mb-1 fw-semibold small">Click to browse or drag & drop</p>
                            <p class="text-muted small mb-2">PDF or Excel files (max 50 MB)</p>
                            <input type="file" name="quote_file" accept=".pdf,.xlsx,.xls,application/pdf,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel"
                                   class="form-control d-none" id="quoteFile" required>
                            <span class="text-success small fw-semibold d-none" id="fileName"></span>
                        </div>
                    </div>

                    <!-- Info box -->
                    <div class="alert alert-light border rounded-3 py-2 px-3 mb-4">
                        <i class="bi bi-info-circle text-primary me-1"></i>
                        <small class="text-muted">Quotation will remain valid for <strong>30 days</strong> from the date of submission.</small>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn text-white px-4 rounded-pill"
                                style="background:#0b5e2b;">
                            <i class="bi bi-upload me-1"></i> Submit Quote
                        </button>
                        <a href="view.php?id=<?= $rfq_id ?>"
                           class="btn btn-outline-secondary rounded-pill px-4">
                            Cancel
                        </a>
                    </div>

                </form>
            </div>
        </div>

    </div>
</div>

<script>
// Drag-and-drop & click-to-browse for the file input
const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('quoteFile');
const fileNameEl = document.getElementById('fileName');

dropZone.addEventListener('click', () => fileInput.click());

dropZone.addEventListener('dragover', e => {
    e.preventDefault();
    dropZone.classList.add('border-success');
    dropZone.style.background = '#d1e7dd';
});
dropZone.addEventListener('dragleave', () => {
    dropZone.classList.remove('border-success');
    dropZone.style.background = '';
});
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.classList.remove('border-success');
    dropZone.style.background = '';
    if (e.dataTransfer.files.length) {
        fileInput.files = e.dataTransfer.files;
        showFile(e.dataTransfer.files[0]);
    }
});

fileInput.addEventListener('change', () => {
    if (fileInput.files.length) showFile(fileInput.files[0]);
});

function showFile(file) {
    fileNameEl.textContent = '📎 ' + file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
    fileNameEl.classList.remove('d-none');
}

function updateQuoteCurrencyLabel() {
    const c = document.getElementById('quote_currency').value;
    const symbol = c === 'USD' ? 'US$' : '$';
    document.getElementById('quoteCurrLabel').textContent = symbol;
    document.getElementById('gctCurrLabel').textContent = symbol;
}
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/footer.php"; ?>
