<?php
/**
 * RFQ Purchase Order Creation
 * ===========================
 * Procurement Officer creates or approves purchase order
 * Enforces: Link to RFQ/quote, amount verification, audit trail
 */

$REQUIRE_PERMISSION = 'create_rfq_purchase_order';
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
    SELECT r.*, pr.request_number, pr.description, pr.branch_id,
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

// Check if commitment has been approved
if ($rfq['commitment_status'] !== 'APPROVED') {
    pop('Commitment form must be approved before PO can be created', '/rfq/view.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

// Get selected quote and vendor
$stmt = $pdo->prepare("
    SELECT q.quote_id, q.quote_amount, q.gct_amount, 
           rv.vendor_id, v.vendor_name, v.contact_person, v.email, v.phone
    FROM rfq_quotes q
    JOIN rfq_vendors rv ON q.rfq_vendor_id = rv.rfq_vendor_id
    JOIN vendors v ON rv.vendor_id = v.vendor_id
    WHERE rv.rfq_id = ? AND q.is_selected = 1
    LIMIT 1
");
$stmt->execute([$rfq_id]);
$selectedQuote = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$selectedQuote) {
    pop('No selected quote found for this RFQ', '/rfq/view.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

// Get existing PO if any
$stmt = $pdo->prepare("
    SELECT * FROM rfq_purchase_orders
    WHERE rfq_id = ?
    ORDER BY created_at DESC
    LIMIT 1
");
$stmt->execute([$rfq_id]);
$existingPO = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle POST - Create PO
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $po_number = trim($_POST['po_number'] ?? '');
    $po_date = trim($_POST['po_date'] ?? '');
    $po_amount = (float)($_POST['po_amount'] ?? 0);
    $delivery_date = trim($_POST['delivery_date'] ?? '');
    $delivery_location = trim($_POST['delivery_location'] ?? '');
    $action = $_POST['action'] ?? '';

    // Validate
    if (strlen($po_number) < 3) {
        pop('PO number is required', '/rfq/po_create.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    if (empty($po_date) || strtotime($po_date) === false) {
        pop('Valid PO date is required', '/rfq/po_create.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    if ($po_amount <= 0) {
        pop('PO amount must be greater than zero', '/rfq/po_create.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    // Check PO amount against quote
    if ($po_amount > $selectedQuote['quote_amount'] * 1.1) {  // Allow 10% variation
        $message = 'PO amount exceeds approved quote by more than 10%. Variation approval required.';
        pop($message, '/rfq/po_create.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    if (empty($delivery_date) || strtotime($delivery_date) === false) {
        pop('Valid delivery date is required', '/rfq/po_create.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    if (strlen($delivery_location) < 3) {
        pop('Delivery location is required', '/rfq/po_create.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    try {
        $pdo->beginTransaction();

        if ($action === 'CREATE_PO') {
            // Check if PO already exists
            if ($existingPO && $existingPO['status'] !== 'REJECTED') {
                pop('A PO already exists for this RFQ', '/rfq/po_create.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
                exit;
            }

            // Create new PO
            $stmt = $pdo->prepare("
                INSERT INTO rfq_purchase_orders
                (rfq_id, po_number, po_date, vendor_id, quote_id, approved_quote_amount, po_amount, 
                 delivery_date, delivery_location, created_by, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING_APPROVAL')
            ");
            $stmt->execute([
                $rfq_id,
                $po_number,
                $po_date,
                $selectedQuote['vendor_id'],
                $selectedQuote['quote_id'],
                $selectedQuote['quote_amount'],
                $po_amount,
                $delivery_date,
                $delivery_location,
                $_SESSION['user_id']
            ]);

            $po_id = $pdo->lastInsertId();

            // Update RFQ
            $stmt = $pdo->prepare("
                UPDATE rfqs
                SET po_number = ?,
                    po_created_by = ?,
                    po_created_at = NOW()
                WHERE rfq_id = ?
            ");
            $stmt->execute([$po_number, $_SESSION['user_id'], $rfq_id]);

            // Log audit trail
            $stmt = $pdo->prepare("
                INSERT INTO audit_log (table_name, action, notes, change_date)
                VALUES ('rfq_purchase_orders', 'PO_CREATED', ?, NOW())
            ");
            $stmt->execute([
                "RFQ {$rfq_id}: PO {$po_number} created by {$_SESSION['full_name']}"
            ]);

            $pdo->commit();
            pop('Purchase Order created successfully. Awaiting HOD approval.', '/rfq/view.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'success');
            exit;
        }

    } catch (Throwable $e) {
        $pdo->rollBack();
        pop('Error creating PO: ' . extractDbMessage($e), '/rfq/po_create.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
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
            <i class="bi bi-file-text"></i> Purchase Order
        </h4>
        <p class="text-muted mb-0 small">Create or review purchase order</p>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8">
        
        <!-- Quote Summary Card -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 rounded-top-4 py-3">
                <h6 class="fw-semibold mb-0"><i class="bi bi-receipt me-1"></i> Selected Quote Summary</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Vendor Name</label>
                        <div class="fw-semibold"><?= htmlspecialchars($selectedQuote['vendor_name']) ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Contact Person</label>
                        <div class="fw-normal"><?= htmlspecialchars($selectedQuote['contact_person'] ?? 'Not provided') ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Quote Amount</label>
                        <div class="fw-semibold text-success">$<?= number_format($selectedQuote['quote_amount'], 2) ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">GCT</label>
                        <div class="fw-normal">$<?= number_format($selectedQuote['gct_amount'] ?? 0, 2) ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Contact Email</label>
                        <div class="fw-normal"><?= htmlspecialchars($selectedQuote['email'] ?? 'Not provided') ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Phone</label>
                        <div class="fw-normal"><?= htmlspecialchars($selectedQuote['phone'] ?? 'Not provided') ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- PO Form -->
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-0 rounded-top-4 py-3">
                <h6 class="fw-semibold mb-0">
                    <i class="bi bi-pencil-square me-1"></i>
                    <?= $existingPO ? 'Review Purchase Order' : 'Create Purchase Order' ?>
                </h6>
            </div>
            <div class="card-body">
                <form method="POST" class="needs-validation">
                    <!-- PO Number -->
                    <div class="mb-3">
                        <label for="po_number" class="form-label">
                            Purchase Order Number <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="po_number" name="po_number" 
                               required minlength="3"
                               value="<?= htmlspecialchars($existingPO['po_number'] ?? '') ?>"
                               placeholder="e.g., PO-2026-001" 
                               <?= $existingPO ? 'readonly' : '' ?>>
                        <div class="form-text">Unique PO reference number</div>
                    </div>

                    <!-- PO Date -->
                    <div class="mb-3">
                        <label for="po_date" class="form-label">
                            Purchase Order Date <span class="text-danger">*</span>
                        </label>
                        <input type="date" class="form-control" id="po_date" name="po_date" 
                               required
                               value="<?= htmlspecialchars($existingPO['po_date'] ?? date('Y-m-d')) ?>"
                               <?= $existingPO ? 'readonly' : '' ?>>
                    </div>

                    <!-- PO Amount -->
                    <div class="mb-3">
                        <label for="po_amount" class="form-label">
                            Purchase Order Amount <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" class="form-control" id="po_amount" name="po_amount" 
                                   step="0.01" min="0" required
                                   value="<?= htmlspecialchars($existingPO['po_amount'] ?? $selectedQuote['quote_amount']) ?>"
                                   placeholder="0.00"
                                   <?= $existingPO ? 'readonly' : '' ?>>
                        </div>
                        <div class="form-text">
                            Quote Amount: $<?= number_format($selectedQuote['quote_amount'], 2) ?>
                            (Allow up to 10% variation without approval)
                        </div>
                    </div>

                    <!-- Delivery Date -->
                    <div class="mb-3">
                        <label for="delivery_date" class="form-label">
                            Required Delivery Date <span class="text-danger">*</span>
                        </label>
                        <input type="date" class="form-control" id="delivery_date" name="delivery_date" 
                               required
                               value="<?= htmlspecialchars($existingPO['delivery_date'] ?? '') ?>"
                               <?= $existingPO ? 'readonly' : '' ?>>
                    </div>

                    <!-- Delivery Location -->
                    <div class="mb-3">
                        <label for="delivery_location" class="form-label">
                            Delivery Location <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control" id="delivery_location" name="delivery_location" 
                                  rows="2" required minlength="3"
                                  placeholder="Enter delivery address/location"
                                  <?= $existingPO ? 'readonly' : '' ?>><?= htmlspecialchars($existingPO['delivery_location'] ?? '') ?></textarea>
                    </div>

                    <!-- Information Alert -->
                    <div class="alert alert-info mb-3">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>PO Reference:</strong> This PO must reference RFQ #<?= htmlspecialchars($rfq['rfq_number']) ?> 
                        and Quote ID <?= htmlspecialchars($selectedQuote['quote_id']) ?>. 
                        Amount cannot exceed approved quotation without controlled variation approval.
                    </div>

                    <!-- Action Buttons -->
                    <div class="d-flex gap-2 justify-content-end">
                        <button type="button" class="btn btn-outline-secondary" onclick="history.back()">
                            <i class="bi bi-x-lg me-1"></i>Cancel
                        </button>
                        <?php if (!$existingPO): ?>
                        <button type="submit" name="action" value="CREATE_PO" class="btn btn-success">
                            <i class="bi bi-check-circle me-1"></i>Create Purchase Order
                        </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- PO History -->
        <?php if ($existingPO): ?>
        <div class="card border-0 shadow-sm rounded-4 mt-4">
            <div class="card-header bg-white border-0 rounded-top-4 py-3">
                <h6 class="fw-semibold mb-0"><i class="bi bi-clock-history me-1"></i> PO History</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Created By</label>
                        <div class="fw-semibold">
                            <?php
                            $stmt = $pdo->prepare("SELECT display_name FROM users WHERE user_id = ?");
                            $stmt->execute([$existingPO['created_by']]);
                            echo htmlspecialchars($stmt->fetchColumn() ?? 'Unknown');
                            ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Created At</label>
                        <div class="fw-semibold"><?= date('M d, Y H:i', strtotime($existingPO['created_at'])) ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Current Status</label>
                        <div>
                            <span class="badge bg-<?= $existingPO['status'] === 'APPROVED' ? 'success' : 'warning' ?>">
                                <?= ucfirst(str_replace('_', ' ', $existingPO['status'])) ?>
                            </span>
                        </div>
                    </div>
                    <?php if ($existingPO['approved_by']): ?>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Approved By</label>
                        <div class="fw-semibold">
                            <?php
                            $stmt = $pdo->prepare("SELECT display_name FROM users WHERE user_id = ?");
                            $stmt->execute([$existingPO['approved_by']]);
                            echo htmlspecialchars($stmt->fetchColumn() ?? 'Unknown');
                            ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/footer.php"; ?>
