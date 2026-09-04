<?php
$REQUIRE_PERMISSION = 'create_purchase_order';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/helper.php";
require_once $_SERVER['DOCUMENT_ROOT'] . "/services/SecureFileStorage.php";
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/workflow.php";

/* ================================
   Validate commitment_id
================================ */
$commitment_id = $_GET['commitment_id'] ?? null;

if (!is_numeric($commitment_id) || (int)$commitment_id <= 0) {
    pop("Missing Commitment ID.", "/procurement/list.php", POP_DEFAULT_DELAY_MS);
    exit;
}

$stmt = $pdo->prepare("
    SELECT po_id
    FROM purchase_orders
    WHERE commitment_id = ?
    LIMIT 1
");
$stmt->execute([$commitment_id]);
$existing_po_id = $stmt->fetchColumn();

if ($existing_po_id) {
    pop(
        "A Purchase Order already exists for this commitment.",
        "/po/view.php?po_id=$existing_po_id"
    );
    exit;
}


$commitment_id = (int)$commitment_id;

/* ================================
   Fetch commitment + request
================================ */
$stmt = $pdo->prepare("
   SELECT 
        c.commitment_id,
        c.commitment_number,
        c.commitment_total,
        c.po_required,
        c.is_remediated,
        c.request_id,
        CASE
            WHEN EXISTS (
                SELECT 1 FROM request_approvals ra
                WHERE ra.entity_type = 'COMMITMENT'
                  AND ra.entity_id = c.commitment_id
                  AND ra.role = 'Finance Officer'
                  AND ra.status = 'approved'
            ) THEN 1
            ELSE 0
        END AS finance_approved,
        pr.request_id,
        pr.request_number,
        pr.status AS request_status,
        pr.currency
   FROM commitments c
   JOIN procurement_requests pr ON c.request_id = pr.request_id
   WHERE c.commitment_id = ?
");

$stmt->execute([$commitment_id]);

$commitment = $stmt->fetch(PDO::FETCH_ASSOC);


if (!$commitment) {
    pop("Commitment not found.", "/procurement/list.php", POP_DEFAULT_DELAY_MS);
    exit;
}

// Check if commitment has been remediated (soft-deleted)
if (($commitment['is_remediated'] ?? 0) === 1) {
    pop(
        "This commitment has been remediated and is no longer part of the active workflow.",
        "/procurement/view.php?id=" . $commitment['request_id'],
        POP_DEFAULT_DELAY_MS
    );
    exit;
}

// Check if this commitment requires a PO
if (($commitment['po_required'] ?? 'YES') === 'NO') {
    pop(
        "This commitment does not require a Purchase Order. The request will proceed directly to invoicing.",
        "/procurement/view.php?id=" . $commitment['request_id'],
        POP_DEFAULT_DELAY_MS
    );
    exit;
}

// NEW: Additional safeguard - verify request flags still align with po_required decision
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/workflow.php';
$requestStmt = $pdo->prepare("
    SELECT work_performed, goods_delivered
    FROM procurement_requests
    WHERE request_id = ?
");
$requestStmt->execute([$commitment['request_id']]);
$requestFlags = $requestStmt->fetch(PDO::FETCH_ASSOC);

if ($requestFlags) {
    $derivedPoReq = getDerivedPoRequired($requestFlags);
    if ($derivedPoReq === 'NO' && $commitment['po_required'] === 'YES') {
        // Warn but allow (user may have changed flags after commitment was created)
        // This requires audit trail investigation
        logAudit(
            $pdo,
            'purchase_orders',
            0, // No PO ID yet
            'PO_CREATION_WARNING',
            'PO creation attempted but request flags indicate NO PO required. Request ID: ' . $commitment['request_id']
        );
    }
}

$currency = normalizeCurrency($commitment['currency'] ?? 'JMD');


// ✅ UPDATED: Allow PO creation after commitment approval
// For RFQ-based procurement: check commitment is linked to RFQ and has selected quote
// For direct procurement: commitment just needs to exist

$allowedPOStatus = ['COMMITMENTS_PENDING', 'COMMITMENT_APPROVED', 'PO_PENDING', 'PO_APPROVED', 'INVOICE_RECEIVED', 'AWARDED', 'COMPLETED'];
if (!in_array(strtoupper($commitment['request_status']), $allowedPOStatus)) {
    // For backward compatibility, check if there's approval record for this commitment
    $approvalCheck = $pdo->prepare("
        SELECT COUNT(*) FROM request_approvals
        WHERE entity_type = 'COMMITMENT'
        AND entity_id = ?
        AND status = 'approved'
    ");
    $approvalCheck->execute([$commitment_id]);
    
    if ($approvalCheck->fetchColumn() === 0) {
        modalPop(
            'Not Approved',
            'This commitment has not been approved. Please ensure Finance approval is complete before creating a PO.',
            '/procurement/view.php?id=' . $commitment['request_id']
        );
        exit;
    }
}

$request_id = (int)$commitment['request_id'];

$po_number = previewYearlyPONumber($pdo);

/* ================================
   Handle POST
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken('/po/add.php?commitment_id=' . $commitment_id);
    $uploadedDocumentPath = null;

    try {
        $pdo->beginTransaction();
        $po_number = generateYearlyPONumber($pdo);
        $po_date  = $_POST['po_date'] ?? '';
        $po_total = (float)($_POST['po_total'] ?? 0);
        $gfmsPoNumber = trim($_POST['gfms_po_number'] ?? '');
        
        // Handle file upload if provided
        $documentPath = null;
        if (isset($_FILES['po_document']) && $_FILES['po_document']['error'] !== UPLOAD_ERR_NO_FILE) {
            $storedFile = SecureFileStorage::storeUploadedFile(
                $_FILES['po_document'],
                'po',
                'PO_' . $commitment_id,
                [
                    'application/pdf' => 'pdf',
                    'application/msword' => 'doc',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                    'application/vnd.ms-excel' => 'xls',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
                ],
                50 * 1024 * 1024
            );

            $documentPath = $storedFile['storage_path'];
            $uploadedDocumentPath = $documentPath;
        }

        if ($po_date === '') {
            throw new Exception("PO date is required.");
        }

        if ($po_total <= 0) {
            throw new Exception("PO total must be greater than zero.");
        }
        
        $check = $pdo->prepare("
  SELECT po_id FROM purchase_orders WHERE commitment_id = ?");
$check->execute([$commitment_id]);
$existingPo = $check->fetchColumn();

if ($existingPo) {
    throw new Exception("This commitment already has a Purchase Order.");
}

        // Validate GFMS PO number if provided
        if (!empty($gfmsPoNumber)) {
            // Check for uniqueness
            $checkGfms = $pdo->prepare("SELECT po_id FROM purchase_orders WHERE gfms_po_number = ?");
            $checkGfms->execute([$gfmsPoNumber]);
            if ($checkGfms->fetchColumn()) {
                throw new Exception("GFMS PO Number '$gfmsPoNumber' already exists in the system.");
            }
            // Validate format: should be alphanumeric, no special characters
            if (!preg_match('/^[a-zA-Z0-9\-\/\.]+$/', $gfmsPoNumber)) {
                throw new Exception("GFMS PO Number can only contain letters, numbers, hyphens, slashes, and periods.");
            }
            if (strlen($gfmsPoNumber) > 50) {
                throw new Exception("GFMS PO Number cannot exceed 50 characters.");
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO purchase_orders
            (commitment_id, po_number, po_date, po_total, status, gfms_po_number, document_path)
            VALUES (?, ?, ?, ?, 'Open', ?, ?)
        ");

        $stmt->execute([
            $commitment_id,
            $po_number,
            $po_date,
            $po_total,
            !empty($gfmsPoNumber) ? $gfmsPoNumber : null,
            $documentPath
        ]);
        
        $po_id = $pdo->lastInsertId();
        
$stmt = $pdo->prepare("
    INSERT INTO po_items (po_id, description, qty, unit_price)
    SELECT 
        ?, 
        CONCAT(item_name, IF(specification IS NOT NULL AND specification != '', CONCAT(' - ', specification), '')),
        quantity,
        0
    FROM procurement_request_items
    WHERE request_id = ?
");
$stmt->execute([$po_id, $request_id]);

$countStmt = $pdo->prepare("
    SELECT COUNT(*) FROM po_items WHERE po_id = ?
");
$countStmt->execute([$po_id]);

if ((int)$countStmt->fetchColumn() === 0) {
    throw new Exception("Cannot create PO without items.");
}


/* ── PO is auto-approved (no approval chain needed) ── */
$pdo->prepare("
    UPDATE purchase_orders
    SET approved_at = NOW()
    WHERE po_id = ?
")->execute([$po_id]);

$pdo->prepare("
    UPDATE commitments
    SET status = 'closed'
    WHERE commitment_id = ?
")->execute([$commitment_id]);

// Advance procurement request status to PO_PENDING (no approval needed)
$pdo->prepare("
    UPDATE procurement_requests
    SET status = 'PO_PENDING'
    WHERE request_id = ?
")->execute([$request_id]);

$pdo->commit();

logAudit(
    $pdo,
    'purchase_orders',
    $po_id,
    'CREATE',
    'Purchase Order created'
);

logRequestTimeline(
    $pdo,
    $request_id,
    'PO_CREATED',
    "PO $po_number created and auto-approved"
);

// Notify about PO creation
require_once $_SERVER['DOCUMENT_ROOT']."/config/notifications.php";
notifyPOAction($request_id, $po_number, 'CREATED', 'Purchase Order created and approved. Ready for invoicing.');


        // ✅ Redirect to Procurement Request
        header("Location: /procurement/view.php?id=" . $request_id);
        exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    pop(
        extractDbMessage($e),
        "/po/add.php?commitment_id=" . $commitment_id,
        POP_DEFAULT_DELAY_MS,
        'error'
    );
    exit;
}

}
/* ================================
   Render page AFTER logic
================================ */
require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/header.php";

// Calculate percentage of commitment being used
$commitmentTotal = (float)$commitment['commitment_total'];
?>

<div class="container mt-4">
    <div class="row mb-4">
        <div class="col-lg-8">
            <h3 class="section-title">📋 Create Purchase Order</h3>
            <p class="text-muted">Convert this approved commitment into a purchase order</p>
        </div>
    </div>

    <!-- Request & Commitment Context Card -->
    <div class="card mb-4 border-start border-info border-3">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0">📌 Procurement Context</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="mb-3">
                        <small class="text-muted d-block">Request Number</small>
                        <h6 class="fw-bold text-primary"><?= htmlspecialchars($commitment['request_number']) ?></h6>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <small class="text-muted d-block">Commitment Number</small>
                        <h6 class="fw-bold text-primary"><?= htmlspecialchars($commitment['commitment_number']) ?></h6>
                    </div>
                </div>
                <div class="col-12">
                    <div class="p-2 bg-light rounded">
                        <small class="text-muted d-block">Committed Amount (Approved Budget)</small>
                        <p class="mb-0 fs-6 fw-bold text-success">JMD <?= number_format($commitmentTotal, 2) ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- PO Creation Form Card -->
    <div class="card border-secondary mb-4">
        <div class="card-header bg-secondary text-white">
            <h5 class="mb-0">✍️ Purchase Order Details</h5>
        </div>
        <div class="card-body">
            <form method="post" id="poForm" onsubmit="return validateForm()" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ensureCsrfToken()) ?>">

                <!-- Auto-generated PO Number -->
                <div class="mb-4">
                    <label for="po_number" class="form-label">
                        <i class="bi bi-hash"></i> PO Number
                    </label>
                    <div class="input-group">
                        <span class="input-group-text">🔒</span>
                        <input type="text"
                               id="po_number"
                               class="form-control"
                               value="<?= htmlspecialchars($po_number) ?>"
                               readonly>
                    </div>
                    <small class="text-muted d-block mt-2">
                        <i class="bi bi-info-circle"></i>
                        Auto-generated by the system
                    </small>
                </div>

                <!-- GFMS PO Number Field (Optional) -->
                <div class="mb-4">
                    <label for="gfms_po_number" class="form-label">
                        <i class="bi bi-bank"></i> GFMS PO Number (Optional)
                    </label>
                    <input type="text"
                           id="gfms_po_number"
                           name="gfms_po_number"
                           class="form-control form-control-lg"
                           placeholder="e.g., GC/2026/PO/00001 or GFMS-PO-123456"
                           maxlength="50">
                    <small class="text-muted d-block mt-2">
                        <i class="bi bi-info-circle"></i>
                        Optional: Enter the unique PO number from the GFMS system. If provided, this will enable tracking against GFMS records.
                        <br><strong>Note:</strong> GFMS numbers must be unique across the system.
                    </small>
                </div>

                <!-- PO Date Field -->
                <div class="mb-4">
                    <label for="po_date" class="form-label">
                        <i class="bi bi-calendar-event"></i>
                        <span class="text-danger">*</span> Purchase Order Date
                    </label>
                    <input type="date"
                           id="po_date"
                           name="po_date"
                           class="form-control form-control-lg"
                           required>
                    <small class="text-muted d-block mt-2">
                        <i class="bi bi-info-circle"></i>
                        The date this PO becomes effective
                    </small>
                </div>

                <!-- PO Total Field with Visual Feedback -->
                <div class="mb-4">
                    <label for="po_total" class="form-label">
                        <i class="bi bi-currency-dollar"></i>
                        <span class="text-danger">*</span> Purchase Order Total (JMD)
                    </label>
                    <div class="input-group">
                        <span class="input-group-text">JMD</span>
                        <input type="number"
                               id="po_total"
                               name="po_total"
                               class="form-control form-control-lg"
                               step="0.01"
                               value="<?= htmlspecialchars($commitment['commitment_total']) ?>"
                               max="<?= htmlspecialchars($commitment['commitment_total']) ?>"
                               required
                               onchange="updateBudgetDisplay()"
                               onkeyup="updateBudgetDisplay()">
                    </div>
                    <small class="text-muted d-block mt-2">
                        <i class="bi bi-info-circle"></i>
                        Cannot exceed committed amount: JMD <?= number_format($commitmentTotal, 2) ?>
                    </small>
                </div>

                <!-- Budget Utilization Display -->
                <div class="alert alert-light border border-primary p-3 mb-4" id="budgetBox">
                    <div class="row g-2">
                        <div class="col-6">
                            <small class="text-muted d-block">Budget Utilization</small>
                            <p class="mb-0 fs-6" id="budgetPercent">0%</p>
                        </div>
                        <div class="col-6">
                            <small class="text-muted d-block">Remaining</small>
                            <p class="mb-0 fs-6 text-success" id="budgetRemaining">JMD <?= number_format($commitmentTotal, 2) ?></p>
                        </div>
                    </div>
                    <div class="progress mt-2" style="height: 20px;">
                        <div class="progress-bar bg-info" id="budgetBar" role="progressbar" 
                             style="width: 0%"
                             aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                        </div>
                    </div>
                </div>

                <!-- PO Document Upload (Optional) -->
                <div class="mb-4">
                    <label for="po_document" class="form-label">
                        <i class="bi bi-file-pdf"></i> Purchase Order Document (Optional)
                    </label>
                    <input type="file"
                           id="po_document"
                           name="po_document"
                           class="form-control form-control-lg"
                           accept=".pdf,.doc,.docx,.xls,.xlsx">
                    <small class="text-muted d-block mt-2">
                        <i class="bi bi-info-circle"></i>
                        Upload the PO document from GFMS (PDF, DOC, DOCX, XLS, XLSX). Maximum 10 MB.
                    </small>
                </div>

                <!-- Action Buttons -->
                <div class="d-grid gap-2 d-sm-flex justify-content-between">
                    <button type="submit" class="btn btn-success btn-lg" id="submitBtn">
                        <i class="bi bi-check-circle"></i> Save Purchase Order
                    </button>

                    <a href="javascript:history.back()" class="btn btn-outline-secondary btn-lg">
                        <i class="bi bi-arrow-left"></i> Cancel
                    </a>
                </div>

                <!-- Hidden loading indicator -->
                <div id="loadingIndicator" class="alert alert-info mt-3" style="display: none;">
                    <div class="spinner-border spinner-border-sm me-2" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <strong>Processing...</strong> Please wait while your purchase order is being created.
                </div>

            </form>
        </div>
    </div>

    <!-- Information Box -->
    <div class="row">
        <div class="col-lg-8">
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <i class="bi bi-lightbulb"></i>
                <strong>What Happens Next:</strong>
                <ul class="mb-0 ms-3 mt-2">
                    <li>PO will be created with auto-generated line items from the procurement request</li>
                    <li>Commitment status will be closed after PO creation</li>
                    <li>PO will be ready for further processing and approvals</li>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        </div>
    </div>
</div>

<script>
const currencyLabel = 'JMD';
function updateBudgetDisplay() {
    const poTotal = parseFloat(document.getElementById('po_total').value) || 0;
    const commitment = <?= $commitmentTotal ?>;
    
    const percentage = commitment > 0 ? Math.round((poTotal / commitment) * 100) : 0;
    const remaining = commitment - poTotal;
    
    document.getElementById('budgetPercent').textContent = percentage + '%';
    document.getElementById('budgetRemaining').textContent = currencyLabel + ' ' + remaining.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    
    const bar = document.getElementById('budgetBar');
    bar.style.width = percentage + '%';
    bar.setAttribute('aria-valuenow', percentage);
    
    if (percentage <= 50) {
        bar.className = 'progress-bar bg-success';
    } else if (percentage <= 80) {
        bar.className = 'progress-bar bg-info';
    } else if (percentage < 100) {
        bar.className = 'progress-bar bg-warning';
    } else {
        bar.className = 'progress-bar bg-success';
    }
}

function validateForm() {
    console.log('Form validation started...');
    
    const poDate = document.getElementById('po_date').value;
    const poTotal = parseFloat(document.getElementById('po_total').value) || 0;
    const commitment = <?= $commitmentTotal ?>;
    
    console.log('PO Date:', poDate);
    console.log('PO Total:', poTotal);
    console.log('Commitment Total:', commitment);
    
    if (!poDate) {
        alert('❌ Please select a PO date');
        document.getElementById('po_date').focus();
        console.error('Validation failed: No PO date');
        return false;
    }
    
    if (poTotal <= 0) {
        alert('❌ PO total must be greater than zero');
        document.getElementById('po_total').focus();
        console.error('Validation failed: PO total is zero or negative');
        return false;
    }
    
    if (poTotal > commitment) {
        alert('❌ PO total cannot exceed the committed amount of ' + currencyLabel + ' ' + commitment.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
        document.getElementById('po_total').focus();
        console.error('Validation failed: PO total exceeds commitment');
        return false;
    }
    
    console.log('All validation checks passed. Showing confirmation dialog...');
    
    const confirmSubmit = confirm('✅ Create this purchase order?\n\nThis action will:\n• Create the PO with committed amount: ' + currencyLabel + ' ' + poTotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '\n• Close the associated commitment\n\nDo you want to proceed?');
    
    if (confirmSubmit) {
        console.log('User confirmed. Submitting form...');
        showLoadingIndicator();
        return true;
    } else {
        console.log('User cancelled form submission');
        return false;
    }
}

function showLoadingIndicator() {
    console.log('Showing loading indicator and disabling submit button');
    document.getElementById('loadingIndicator').style.display = 'block';
    document.getElementById('submitBtn').disabled = true;
    document.getElementById('submitBtn').innerHTML = '<i class="bi bi-hourglass-split"></i> Processing...';
}

// Initialize budget display on page load
window.addEventListener('load', function() {
    console.log('Page loaded. Initializing budget display...');
    updateBudgetDisplay();
    
    // Ensure form has proper submission handler
    const form = document.getElementById('poForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            console.log('Form submit event triggered');
            if (!validateForm()) {
                e.preventDefault();
                console.log('Form submission prevented by validation');
            }
        });
    }
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/footer.php"; ?>