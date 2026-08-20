<?php
/**
 * RFQ Funds Verification
 * =====================
 * Finance Officer verifies fund availability and records verification status
 * Enforces: No bypassing, segregation of duties, comprehensive audit trail
 */

$REQUIRE_PERMISSION = 'verify_rfq_funds';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/services/RFQWorkflowService.php';

// Get RFQ ID from URL
$rfq_id = (int)($_GET['id'] ?? 0);

if ($rfq_id <= 0) {
    pop('Invalid RFQ ID', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

// Fetch RFQ details
$stmt = $pdo->prepare("
    SELECT r.*, pr.request_number, pr.description, pr.estimated_value, pr.branch_id,
           u.display_name as created_by_name,
           (SELECT quote_amount FROM rfq_quotes q 
            JOIN rfq_vendors rv ON q.rfq_vendor_id = rv.rfq_vendor_id 
            WHERE rv.rfq_id = r.rfq_id AND q.is_selected = 1 LIMIT 1) as selected_quote_amount,
           (SELECT vendor_name FROM vendors v
            JOIN rfq_vendors rv ON v.vendor_id = rv.vendor_id
            JOIN rfq_quotes q ON q.rfq_vendor_id = rv.rfq_vendor_id
            WHERE rv.rfq_id = r.rfq_id AND q.is_selected = 1 LIMIT 1) as selected_vendor_name
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

// Check if RFQ is in funds verification stage
// It should have passed branch head approval
if ($rfq['branch_head_approval_status'] !== 'APPROVED') {
    pop('RFQ has not passed branch head approval yet', '/rfq/view.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

// Check if already verified
if ($rfq['funds_verified_status'] !== 'PENDING') {
    pop('Funds verification has already been completed for this RFQ', '/rfq/view.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'info');
    exit;
}

// Get verification history
$stmt = $pdo->prepare("
    SELECT * FROM rfq_funds_verification
    WHERE rfq_id = ?
    ORDER BY created_at DESC
");
$stmt->execute([$rfq_id]);
$verificationHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle POST - Submit verification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $available_funds = (float)($_POST['available_funds'] ?? 0);
    $verification_comments = trim($_POST['verification_comments'] ?? '');

    // Validate
    if (!in_array($action, ['APPROVED', 'REJECTED'])) {
        pop('Invalid verification action', '/rfq/funds_verify.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    if (strlen($verification_comments) < 5) {
        pop('Verification comments are required and must be at least 5 characters', '/rfq/funds_verify.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    if ($action === 'APPROVED' && $available_funds <= 0) {
        pop('Available funds must be greater than zero for approval', '/rfq/funds_verify.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    if ($action === 'APPROVED' && $rfq['selected_quote_amount'] && $available_funds < $rfq['selected_quote_amount']) {
        pop('Available funds (' . number_format($available_funds, 2) . ') are less than selected quote amount (' . number_format($rfq['selected_quote_amount'], 2) . ')', '/rfq/funds_verify.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    try {
        // Use workflow service
        $workflowService = new RFQWorkflowService($pdo, $_SESSION['user_id'], $_SESSION['role_name'] ?? '', $_SESSION['branch_id'] ?? 0);

        $result = $workflowService->verifyFunds(
            $rfq_id,
            (float)$rfq['selected_quote_amount'],
            $verification_comments,
            $action === 'APPROVED'
        );

        if ($result['success']) {
            pop($result['message'], '/rfq/view.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'success');
            exit;
        } else {
            pop($result['message'], '/rfq/funds_verify.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
            exit;
        }

    } catch (Throwable $e) {
        pop('Error processing verification: ' . extractDbMessage($e), '/rfq/funds_verify.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
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
            <i class="bi bi-shield-check"></i> Funds Verification
        </h4>
        <p class="text-muted mb-0 small">Verify availability and correctness of funds</p>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8">
        
        <!-- RFQ Information Card -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 rounded-top-4 py-3">
                <h6 class="fw-semibold mb-0"><i class="bi bi-receipt me-1"></i> RFQ Information</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Request Number</label>
                        <div class="fw-semibold"><?= htmlspecialchars($rfq['request_number']) ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">RFQ Number</label>
                        <div class="fw-semibold"><?= htmlspecialchars($rfq['rfq_number']) ?></div>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label text-muted small">Description</label>
                        <div class="fw-normal"><?= htmlspecialchars($rfq['description']) ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Estimated Value</label>
                        <div class="fw-semibold">$<?= number_format($rfq['estimated_value'], 2) ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Selected Vendor</label>
                        <div class="fw-semibold"><?= htmlspecialchars($rfq['selected_vendor_name'] ?? 'Not selected') ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Selected Quote Amount</label>
                        <div class="fw-semibold text-success">$<?= number_format($rfq['selected_quote_amount'], 2) ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Branch Head Approval</label>
                        <div>
                            <span class="badge bg-success">
                                <i class="bi bi-check-circle me-1"></i>Approved
                            </span>
                        </div>
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
                                <th>Date</th>
                                <th>Verified By</th>
                                <th>Status</th>
                                <th>Available Funds</th>
                                <th>Comments</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($verificationHistory as $verification): ?>
                            <tr>
                                <td class="small"><?= date('M d, Y H:i', strtotime($verification['created_at'])) ?></td>
                                <td class="small">
                                    <?php
                                    $stmt = $pdo->prepare("SELECT display_name FROM users WHERE user_id = ?");
                                    $stmt->execute([$verification['verified_by']]);
                                    echo htmlspecialchars($stmt->fetchColumn() ?? 'Unknown');
                                    ?>
                                </td>
                                <td class="small">
                                    <span class="badge bg-<?= $verification['status'] === 'APPROVED' ? 'success' : 'danger' ?>">
                                        <?= ucfirst($verification['status']) ?>
                                    </span>
                                </td>
                                <td class="small fw-semibold">
                                    <?php if ($verification['available_funds']): ?>
                                        $<?= number_format($verification['available_funds'], 2) ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-muted" style="max-width: 150px; overflow: hidden; text-overflow: ellipsis;">
                                    <?= htmlspecialchars($verification['verification_comments']) ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Verification Form -->
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-0 rounded-top-4 py-3">
                <h6 class="fw-semibold mb-0"><i class="bi bi-pencil-square me-1"></i> Record Verification</h6>
            </div>
            <div class="card-body">
                <form method="POST" class="needs-validation">
                    <!-- Available Funds -->
                    <div class="mb-3">
                        <label for="available_funds" class="form-label">
                            Available Funds <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" class="form-control" id="available_funds" name="available_funds" 
                                   step="0.01" min="0" required
                                   placeholder="0.00">
                        </div>
                        <div class="form-text">
                            Must be at least $<?= number_format($rfq['selected_quote_amount'], 2) ?> 
                            (selected quote amount)
                        </div>
                    </div>

                    <!-- Verification Comments -->
                    <div class="mb-3">
                        <label for="verification_comments" class="form-label">
                            Verification Comments <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control" id="verification_comments" name="verification_comments" 
                                  rows="4" required placeholder="Provide details of your fund verification..." 
                                  minlength="5"></textarea>
                        <div class="form-text">Minimum 5 characters required</div>
                    </div>

                    <!-- Verification Actions -->
                    <div class="alert alert-info mb-3">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Verification Required:</strong> Please verify that funds are available and correct before proceeding. 
                        This is a critical control point in the procurement process.
                    </div>

                    <!-- Action Buttons -->
                    <div class="d-flex gap-2 justify-content-end">
                        <button type="button" class="btn btn-outline-secondary" onclick="history.back()">
                            <i class="bi bi-x-lg me-1"></i>Cancel
                        </button>
                        <button type="submit" name="action" value="REJECTED" class="btn btn-outline-danger" 
                                onclick="return confirm('Are you sure? This will return the RFQ for correction.');">
                            <i class="bi bi-x-circle me-1"></i>Reject
                        </button>
                        <button type="submit" name="action" value="APPROVED" class="btn btn-success">
                            <i class="bi bi-check-circle me-1"></i>Approve & Continue
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/footer.php"; ?>
