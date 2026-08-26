<?php
$REQUIRE_PERMISSION = 'request_po_adjustment';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/helper.php";


$po_id = isset($_GET['po_id']) ? (int)$_GET['po_id'] : 0;

if ($po_id <= 0) {
    modalPop(
        'Error',
        'Missing Purchase Order ID.',
        '/po/list.php',
        'error'
    );
    exit;
}


/* ================================
   Fetch PO
================================ */
$stmt = $pdo->prepare("
SELECT 
    po.po_id,
    po.po_number,
    po.po_total,
    po.status,
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
    po.po_type,
    IFNULL(SUM(inv.invoice_amount),0) AS total_invoiced,
    pr.currency

    FROM purchase_orders po
    LEFT JOIN invoices inv
      ON po.po_id = inv.po_id
      AND inv.status = 'APPROVED'
    LEFT JOIN commitments c ON po.commitment_id = c.commitment_id
    LEFT JOIN procurement_requests pr ON c.request_id = pr.request_id
    WHERE po.po_id = ?
    GROUP BY po.po_id
");
$stmt->execute([$po_id]);
$po = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$po) {
    modalPop('Error', 'Purchase Order not found.', '/po/list.php', 'error');
    exit;
}

$currency = 'JMD'; // PO amounts are always stored in JMD

if (in_array($po['status'], ['Cancelled'], true)) {
    modalPop('Error', 'Variations cannot be created for a cancelled PO.', '/po/list.php', 'error');
    exit;
}

$remaining = (float)$po['po_total'] - (float)$po['total_invoiced'];

/* ================================
   Handle POST
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $amount = isset($_POST['variation_amount'])
        ? (float)$_POST['variation_amount']
        : 0.0;

    $reason = trim($_POST['reason'] ?? '');

    if ($amount <= 0) {
        modalPop('Error', 'Variation amount must be greater than zero.', '', 'error');
        exit;
    }

    if ($reason === '') {
        modalPop('Error', 'Reason for variation is required.', '', 'error');
        exit;
    }

    // Prevent multiple pending variations
    $check = $pdo->prepare("
        SELECT COUNT(*)
        FROM po_variations
        WHERE po_id = ?
          AND status = 'PENDING'
    ");
    $check->execute([$po_id]);

    if ((int)$check->fetchColumn() > 0) {
        modalPop(
            'Pending Variation Exists',
            'A PO Variation is already pending approval.',
            "/po/view.php?po_id=" . (int)$po_id,
            'warning'
        );
        exit;
    }
    
    
if ((int)$po['finance_approved'] !== 1) {
    modalPop(
        'Error',
        'Cannot create a variation for a PO that is not Finance-approved.',
        "/po/view.php?po_id=".$po_id,
        'error'
    );
    exit;
}

if ($po['po_type'] !== 'ORIGINAL') {
    modalPop(
        'Error',
        'Variations can only be created from the original Purchase Order.',
        "/po/view.php?po_id=".$po_id,
        'error'
    );
    exit;
}


    // Insert variation request
    try {
    $stmt = $pdo->prepare("
        INSERT INTO po_variations
        (po_id, variation_amount, reason, requested_by, status, requested_at)
        VALUES (?, ?, ?, ?, 'PENDING', NOW())
    ");
    $stmt->execute([
        $po_id,
        $amount,
        $reason,
        $_SESSION['user_id']
    ]);

    logAudit(
        $pdo,
        'po_variations',
        $pdo->lastInsertId(),
        'CREATE',
        'PO variation requested'
    );
    
    // Get request_id for timeline logging
    $reqStmt = $pdo->prepare("
        SELECT c.request_id
        FROM commitments c
        JOIN purchase_orders po ON po.commitment_id = c.commitment_id
        WHERE po.po_id = ?
        LIMIT 1
    ");
    $reqStmt->execute([$po_id]);
    $variationRequestId = $reqStmt->fetchColumn();
    
    if ($variationRequestId) {
        logRequestTimeline(
            $pdo,
            $variationRequestId,
            'PO_VARIATION_REQUESTED',
            'PO '.$po['po_number'].' variation requested for '.($currency).' '.number_format($amount, 2)
        );
        
        // Notify about PO variation
        require_once $_SERVER['DOCUMENT_ROOT']."/config/notifications.php";
        notifyPOVariation($variationRequestId, $po['po_number'], 'REQUESTED', $amount, $reason);
    }


    modalPop(
        'Variation Submitted',
        'PO Variation request has been submitted for approval.',
        "/po/view.php?po_id=" . (int)$po_id,
        'success'
    );
    } catch (Throwable $e) {
        modalPop('Error', extractDbMessage($e), "/po/view.php?po_id=" . (int)$po_id, 'error');
    }
    exit;
}

/* ================================
   Render Page
================================ */
require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/header.php";

$percentageInvoiced = ($po['total_invoiced'] > 0 && $po['po_total'] > 0) 
    ? round(($po['total_invoiced'] / $po['po_total']) * 100, 1) 
    : 0;
?>

<div class="container mt-4">
    <div class="row mb-4">
        <div class="col-lg-8">
            <h3 class="section-title">📊 Request PO Variation</h3>
            <p class="text-muted">Submit a request to increase the budget for this purchase order</p>
        </div>
    </div>

    <!-- PO Overview Card -->
    <div class="card mb-4 border-start border-info border-3">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0">📦 Purchase Order Details</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <!-- Main PO Info -->
                <div class="col-md-6">
                    <div class="mb-3">
                        <small class="text-muted d-block">PO Number</small>
                        <h6 class="fw-bold text-primary"><?= htmlspecialchars($po['po_number']) ?></h6>
                    </div>
                </div>

                <!-- Financial Summary -->
                <div class="col-md-6">
                    <div class="row g-2">
                        <div class="col-12 col-sm-6">
                            <small class="text-muted d-block">PO Total</small>
                            <p class="mb-0 fs-6">JMD <span class="badge bg-primary"><?= number_format($po['po_total'], 2) ?></span></p>
                        </div>
                        <div class="col-12 col-sm-6">
                            <small class="text-muted d-block">Total Invoiced</small>
                            <p class="mb-0 fs-6">JMD <span class="badge bg-success"><?= number_format($po['total_invoiced'], 2) ?></span></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Invoice Progress Bar -->
            <div class="mt-3 pt-3 border-top">
                <small class="text-muted d-block mb-2">Invoice Progress</small>
                <div class="progress mb-2" style="height: 25px;">
                    <div class="progress-bar bg-success" role="progressbar" 
                         style="width: <?= $percentageInvoiced ?>%"
                         aria-valuenow="<?= $percentageInvoiced ?>" 
                         aria-valuemin="0" aria-valuemax="100">
                        <?= $percentageInvoiced ?>%
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-6">
                        <small class="text-muted d-block">Remaining Balance</small>
                        <p class="mb-0 fw-bold text-warning"><?= htmlspecialchars($currency) ?> <?= number_format($remaining, 2) ?></p>
                    </div>
                    <div class="col-6">
                        <small class="text-muted d-block">Status</small>
                        <p class="mb-0">
                            <span class="badge bg-success">
                                <i class="bi bi-check-circle"></i> <?= htmlspecialchars($po['status']) ?>
                            </span>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Variation Request Form -->
    <div class="card border-secondary mb-4">
        <div class="card-header bg-secondary text-white">
            <h5 class="mb-0">✍️ Variation Details</h5>
        </div>
        <div class="card-body">
            <form method="post" id="variationForm">

                <!-- Variation Amount Field -->
                <div class="mb-4">
                    <label for="variation_amount" class="form-label">
                        <i class="bi bi-currency-dollar"></i>
                        <span class="text-danger">*</span> Variation Amount (<?= htmlspecialchars($currency) ?>)
                    </label>
                    <div class="input-group">
                        <span class="input-group-text"><?= htmlspecialchars($currency) ?></span>
                        <input type="number"
                               id="variation_amount"
                               name="variation_amount"
                               class="form-control form-control-lg"
                               step="0.01"
                               placeholder="Enter amount to add to PO total"
                               min="0.01"
                               required
                               onchange="updatePreview()">
                    </div>
                    <small class="text-muted d-block mt-2">
                        <i class="bi bi-info-circle"></i>
                        Current remaining balance: <strong><?= htmlspecialchars($currency) ?> <?= number_format($remaining, 2) ?></strong>
                    </small>
                </div>

                <!-- Reason for Variation Field -->
                <div class="mb-4">
                    <label for="reason" class="form-label">
                        <i class="bi bi-chat-square-text"></i>
                        <span class="text-danger">*</span> Reason for Variation
                    </label>
                    <textarea id="reason"
                              name="reason"
                              class="form-control"
                              rows="5"
                              placeholder="Explain why this PO variation is necessary. Include details about the additional work or cost drivers."
                              required></textarea>
                    <small class="text-muted d-block mt-2">
                        <i class="bi bi-info-circle"></i>
                        Provide clear details to help approvers understand the business impact.
                    </small>
                </div>

                <!-- Preview Box -->
                <div class="alert alert-light border border-info p-3 mb-4" id="previewBox" style="display: none;">
                    <small class="text-muted d-block">If Approved, New PO Total Will Be:</small>
                    <h5 class="text-info mb-0">
                        <?= htmlspecialchars($currency) ?> <span id="previewTotal">0.00</span>
                    </h5>
                </div>

                <!-- Action Buttons -->
                <div class="d-grid gap-2 d-sm-flex justify-content-between">
                    <button type="submit" class="btn btn-warning btn-lg">
                        <i class="bi bi-send"></i> Submit Variation Request
                    </button>

                    <a href="/po/view.php?po_id=<?= (int)$po_id ?>" class="btn btn-outline-secondary btn-lg">
                        <i class="bi bi-arrow-left"></i> Cancel
                    </a>
                </div>

            </form>
        </div>
    </div>

    <!-- Information Box -->
    <div class="row">
        <div class="col-lg-8">
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <i class="bi bi-lightbulb"></i>
                <strong>Process Information:</strong>
                <ul class="mb-0 ms-3 mt-2">
                    <li>Your variation request will be submitted for approval</li>
                    <li>A supplementary commitment will be created if approved</li>
                    <li>Finance and management will review your request</li>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        </div>
    </div>
</div>

<script>
function updatePreview() {
    const amountInput = document.getElementById('variation_amount');
    const amount = parseFloat(amountInput.value) || 0;
    const currentTotal = <?= (float)$po['po_total'] ?>;
    const previewBox = document.getElementById('previewBox');
    const previewTotal = document.getElementById('previewTotal');
    
    if (amount > 0) {
        const newTotal = currentTotal + amount;
        previewTotal.textContent = newTotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        previewBox.style.display = 'block';
    } else {
        previewBox.style.display = 'none';
    }
}

// Form validation
document.getElementById('variationForm').addEventListener('submit', function(e) {
    const amount = parseFloat(document.getElementById('variation_amount').value) || 0;
    const reason = document.getElementById('reason').value.trim();
    
    if (amount <= 0) {
        e.preventDefault();
        alert('Variation amount must be greater than zero.');
        document.getElementById('variation_amount').focus();
        return false;
    }
    
    if (reason.length < 10) {
        e.preventDefault();
        alert('Please provide a detailed reason (at least 10 characters).');
        document.getElementById('reason').focus();
        return false;
    }
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/footer.php"; ?>
