<?php
/**
 * RFQ Commitment Form Management
 * =============================
 * Finance Officer prepares or verifies the commitment form
 * Enforces: Complete commitment info required before proceeding, audit trail
 */

$REQUIRE_PERMISSION = 'manage_rfq_commitment';
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
    SELECT r.*, pr.request_number, pr.description, pr.estimated_value, pr.branch_id,
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

// Check if funds have been verified
if ($rfq['funds_verified_status'] !== 'APPROVED') {
    pop('Funds must be verified before commitment form can be created', '/rfq/view.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

// Get existing commitment form if any
$stmt = $pdo->prepare("
    SELECT * FROM rfq_commitment_forms
    WHERE rfq_id = ?
    ORDER BY created_at DESC
    LIMIT 1
");
$stmt->execute([$rfq_id]);
$existingCommitment = $stmt->fetch(PDO::FETCH_ASSOC);

// Get selected quote amount
$stmt = $pdo->prepare("
    SELECT q.quote_amount, v.vendor_name
    FROM rfq_quotes q
    JOIN rfq_vendors rv ON q.rfq_vendor_id = rv.rfq_vendor_id
    JOIN vendors v ON rv.vendor_id = v.vendor_id
    WHERE rv.rfq_id = ? AND q.is_selected = 1
    LIMIT 1
");
$stmt->execute([$rfq_id]);
$selectedQuote = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle POST - Create or update commitment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $commitment_number = trim($_POST['commitment_number'] ?? '');
    $commitment_amount = (float)($_POST['commitment_amount'] ?? 0);
    $commitment_date = trim($_POST['commitment_date'] ?? '');
    $account_code = trim($_POST['account_code'] ?? '');
    $fund_source = trim($_POST['fund_source'] ?? '');
    $comments = trim($_POST['comments'] ?? '');
    $action = $_POST['action'] ?? '';

    // Validate
    if (strlen($commitment_number) < 3) {
        pop('Commitment number is required', '/rfq/commitment_manage.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    if ($commitment_amount <= 0) {
        pop('Commitment amount must be greater than zero', '/rfq/commitment_manage.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    if (empty($commitment_date) || strtotime($commitment_date) === false) {
        pop('Valid commitment date is required', '/rfq/commitment_manage.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    if (strlen($account_code) < 2) {
        pop('Account code is required', '/rfq/commitment_manage.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    if (strlen($fund_source) < 5) {
        pop('Fund source description is required (minimum 5 characters)', '/rfq/commitment_manage.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    try {
        $pdo->beginTransaction();

        if ($action === 'SAVE_DRAFT') {
            // Save or update draft commitment
            if ($existingCommitment && $existingCommitment['status'] === 'DRAFT') {
                // Update existing draft
                $stmt = $pdo->prepare("
                    UPDATE rfq_commitment_forms
                    SET commitment_number = ?,
                        commitment_amount = ?,
                        commitment_date = ?,
                        account_code = ?,
                        fund_source = ?,
                        updated_at = NOW()
                    WHERE commitment_id = ?
                ");
                $stmt->execute([
                    $commitment_number,
                    $commitment_amount,
                    $commitment_date,
                    $account_code,
                    $fund_source,
                    $existingCommitment['commitment_id']
                ]);

                $message = 'Commitment form draft updated successfully';
            } else {
                // Create new commitment
                $stmt = $pdo->prepare("
                    INSERT INTO rfq_commitment_forms
                    (rfq_id, commitment_number, prepared_by, status, commitment_amount, commitment_date, account_code, fund_source)
                    VALUES (?, ?, ?, 'DRAFT', ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $rfq_id,
                    $commitment_number,
                    $_SESSION['user_id'],
                    $commitment_amount,
                    $commitment_date,
                    $account_code,
                    $fund_source
                ]);

                $message = 'Commitment form created as draft successfully';
            }

            $pdo->commit();
            pop($message, '/rfq/commitment_manage.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'success');
            exit;

        } elseif ($action === 'SUBMIT_FOR_APPROVAL') {
            // Submit commitment for approval
            if (!$existingCommitment) {
                pop('Commitment form must be created first', '/rfq/commitment_manage.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
                exit;
            }

            // Update commitment status to pending approval
            $stmt = $pdo->prepare("
                UPDATE rfq_commitment_forms
                SET status = 'PENDING_APPROVAL',
                    updated_at = NOW()
                WHERE commitment_id = ?
            ");
            $stmt->execute([$existingCommitment['commitment_id']]);

            // Update RFQ commitment status
            $stmt = $pdo->prepare("
                UPDATE rfqs
                SET commitment_status = 'APPROVED',
                    commitment_verified_by = ?,
                    commitment_verified_at = NOW(),
                    commitment_comments = ?,
                    commitment_number = ?
                WHERE rfq_id = ?
            ");
            $stmt->execute([
                $_SESSION['user_id'],
                $comments ?: null,
                $commitment_number,
                $rfq_id
            ]);

            // Log audit trail
            $stmt = $pdo->prepare("
                INSERT INTO audit_log (table_name, action, notes, change_date)
                VALUES ('rfq_commitment_forms', 'COMMITMENT_SUBMITTED', ?, NOW())
            ");
            $stmt->execute([
                "RFQ {$rfq_id}: Commitment {$commitment_number} submitted for approval"
            ]);

            $pdo->commit();
            pop('Commitment form submitted for approval. RFQ ready to proceed to PO creation.', '/rfq/view.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'success');
            exit;
        }

    } catch (Throwable $e) {
        $pdo->rollBack();
        pop('Error processing commitment: ' . extractDbMessage($e), '/rfq/commitment_manage.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
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
            <i class="bi bi-file-earmark-check"></i> Commitment Form
        </h4>
        <p class="text-muted mb-0 small">Prepare and verify commitment form</p>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8">
        
        <!-- RFQ Summary Card -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 rounded-top-4 py-3">
                <h6 class="fw-semibold mb-0"><i class="bi bi-receipt me-1"></i> RFQ Summary</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Request Number</label>
                        <div class="fw-semibold"><?= htmlspecialchars($rfq['request_number']) ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Selected Vendor</label>
                        <div class="fw-semibold"><?= htmlspecialchars($selectedQuote['vendor_name'] ?? 'Not selected') ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Quote Amount</label>
                        <div class="fw-semibold text-success">
                            $<?= number_format($selectedQuote['quote_amount'] ?? 0, 2) ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Funds Status</label>
                        <div>
                            <span class="badge bg-success">
                                <i class="bi bi-check-circle me-1"></i>Verified
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Commitment Form -->
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-0 rounded-top-4 py-3">
                <h6 class="fw-semibold mb-0">
                    <i class="bi bi-pencil-square me-1"></i>
                    <?= $existingCommitment ? 'Edit Commitment' : 'Create Commitment' ?>
                </h6>
            </div>
            <div class="card-body">
                <form method="POST" class="needs-validation">
                    <!-- Commitment Number -->
                    <div class="mb-3">
                        <label for="commitment_number" class="form-label">
                            Commitment Number <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="commitment_number" name="commitment_number" 
                               required minlength="3" 
                               value="<?= htmlspecialchars($existingCommitment['commitment_number'] ?? '') ?>"
                               placeholder="e.g., CMT-2026-001">
                        <div class="form-text">Unique commitment reference number</div>
                    </div>

                    <!-- Commitment Amount -->
                    <div class="mb-3">
                        <label for="commitment_amount" class="form-label">
                            Commitment Amount <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" class="form-control" id="commitment_amount" name="commitment_amount" 
                                   step="0.01" min="0" required
                                   value="<?= htmlspecialchars($existingCommitment['commitment_amount'] ?? ($selectedQuote['quote_amount'] ?? '')) ?>"
                                   placeholder="0.00">
                        </div>
                        <div class="form-text">Must match or exceed the selected quote amount</div>
                    </div>

                    <!-- Commitment Date -->
                    <div class="mb-3">
                        <label for="commitment_date" class="form-label">
                            Commitment Date <span class="text-danger">*</span>
                        </label>
                        <input type="date" class="form-control" id="commitment_date" name="commitment_date" 
                               required
                               value="<?= htmlspecialchars($existingCommitment['commitment_date'] ?? date('Y-m-d')) ?>">
                        <div class="form-text">Date the commitment is issued</div>
                    </div>

                    <!-- Account Code -->
                    <div class="mb-3">
                        <label for="account_code" class="form-label">
                            Account Code <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="account_code" name="account_code" 
                               required minlength="2"
                               value="<?= htmlspecialchars($existingCommitment['account_code'] ?? '') ?>"
                               placeholder="e.g., 1000-5000-0001">
                        <div class="form-text">Budget account/cost center code</div>
                    </div>

                    <!-- Fund Source -->
                    <div class="mb-3">
                        <label for="fund_source" class="form-label">
                            Fund Source <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control" id="fund_source" name="fund_source" 
                                  rows="2" required minlength="5"
                                  placeholder="Describe the source and availability of funds"><?= htmlspecialchars($existingCommitment['fund_source'] ?? '') ?></textarea>
                        <div class="form-text">Minimum 5 characters required</div>
                    </div>

                    <!-- Additional Comments -->
                    <div class="mb-3">
                        <label for="comments" class="form-label">
                            Additional Comments (Optional)
                        </label>
                        <textarea class="form-control" id="comments" name="comments" 
                                  rows="3" placeholder="Any additional information about this commitment"><?= htmlspecialchars($existingCommitment['approval_comments'] ?? '') ?></textarea>
                    </div>

                    <!-- Information Alert -->
                    <div class="alert alert-info mb-3">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Commitment Form Required:</strong> The commitment form must be complete and accurate. 
                        This commitment cannot be changed once submitted.
                    </div>

                    <!-- Action Buttons -->
                    <div class="d-flex gap-2 justify-content-end">
                        <button type="button" class="btn btn-outline-secondary" onclick="history.back()">
                            <i class="bi bi-x-lg me-1"></i>Cancel
                        </button>
                        <button type="submit" name="action" value="SAVE_DRAFT" class="btn btn-outline-primary">
                            <i class="bi bi-download me-1"></i>Save as Draft
                        </button>
                        <button type="submit" name="action" value="SUBMIT_FOR_APPROVAL" class="btn btn-success">
                            <i class="bi bi-check-circle me-1"></i>Submit & Complete
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Commitment History -->
        <?php if ($existingCommitment): ?>
        <div class="card border-0 shadow-sm rounded-4 mt-4">
            <div class="card-header bg-white border-0 rounded-top-4 py-3">
                <h6 class="fw-semibold mb-0"><i class="bi bi-clock-history me-1"></i> Commitment History</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Created By</label>
                        <div class="fw-semibold">
                            <?php
                            $stmt = $pdo->prepare("SELECT display_name FROM users WHERE user_id = ?");
                            $stmt->execute([$existingCommitment['prepared_by']]);
                            echo htmlspecialchars($stmt->fetchColumn() ?? 'Unknown');
                            ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Created At</label>
                        <div class="fw-semibold"><?= date('M d, Y H:i', strtotime($existingCommitment['created_at'])) ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Status</label>
                        <div>
                            <span class="badge bg-<?= $existingCommitment['status'] === 'APPROVED' ? 'success' : 'warning' ?>">
                                <?= ucfirst(str_replace('_', ' ', $existingCommitment['status'])) ?>
                            </span>
                        </div>
                    </div>
                    <?php if ($existingCommitment['approved_by']): ?>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Approved By</label>
                        <div class="fw-semibold">
                            <?php
                            $stmt = $pdo->prepare("SELECT display_name FROM users WHERE user_id = ?");
                            $stmt->execute([$existingCommitment['approved_by']]);
                            echo htmlspecialchars($stmt->fetchColumn() ?? 'Unknown');
                            ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Approved At</label>
                        <div class="fw-semibold"><?= date('M d, Y H:i', strtotime($existingCommitment['approved_at'])) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/footer.php"; ?>
