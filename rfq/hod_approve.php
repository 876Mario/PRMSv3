<?php
/**
 * RFQ HOD Approval
 * ================
 * Government Chemist (Head of Department) provides final approval
 * Any rejection must include comments and return to responsible stage
 */

$REQUIRE_PERMISSION = 'approve_rfq_hod';
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
           u.display_name as created_by_name,
           v.vendor_name,
           q.quote_amount,
           po.po_number, po.po_amount,
           com.commitment_number
    FROM rfqs r
    JOIN procurement_requests pr ON r.request_id = pr.request_id
    LEFT JOIN users u ON r.created_by = u.user_id
    LEFT JOIN rfq_quotes q ON r.rfq_id = (SELECT rfq_id FROM rfq_vendors rv WHERE rv.rfq_id = r.rfq_id LIMIT 1) AND q.is_selected = 1
    LEFT JOIN vendors v ON (SELECT rv.vendor_id FROM rfq_vendors rv JOIN rfq_quotes q2 ON q2.rfq_vendor_id = rv.rfq_vendor_id WHERE rv.rfq_id = r.rfq_id AND q2.is_selected = 1 LIMIT 1) = v.vendor_id
    LEFT JOIN rfq_purchase_orders po ON r.rfq_id = po.rfq_id AND po.status = 'APPROVED'
    LEFT JOIN rfq_commitment_forms com ON r.rfq_id = com.rfq_id AND com.status = 'APPROVED'
    WHERE r.rfq_id = ?
");
$stmt->execute([$rfq_id]);
$rfq = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rfq) {
    pop('RFQ not found', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

// Check if HOD approval is required (i.e., invoice has been verified)
if ($rfq['invoice_checked_by'] === null) {
    pop('Invoice must be verified before HOD approval can proceed', '/rfq/view.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

// Get invoice verification details
$stmt = $pdo->prepare("
    SELECT * FROM rfq_invoice_verifications
    WHERE rfq_id = ?
    ORDER BY verified_at DESC
    LIMIT 1
");
$stmt->execute([$rfq_id]);
$invoiceVerification = $stmt->fetch(PDO::FETCH_ASSOC);

// Get workflow history
$stmt = $pdo->prepare("
    SELECT qa.*, u.display_name FROM rfq_quote_approvals qa
    LEFT JOIN users u ON qa.approver_id = u.user_id
    WHERE qa.rfq_id = ?
    ORDER BY qa.created_at DESC
");
$stmt->execute([$rfq_id]);
$approvalHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle POST - HOD approval or rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $comments = trim($_POST['comments'] ?? '');

    // Validate
    if (!in_array($action, ['APPROVED', 'REJECTED'])) {
        pop('Invalid action', '/rfq/hod_approve.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    if ($action === 'REJECTED' && strlen($comments) < 5) {
        pop('Rejection must include comments (minimum 5 characters)', '/rfq/hod_approve.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Check segregation of duties - HOD cannot approve if they created the request
        $stmt = $pdo->prepare("
            SELECT created_by FROM procurement_requests WHERE request_id = ?
        ");
        $stmt->execute([$rfq['request_id']]);
        $requestCreator = $stmt->fetchColumn();

        if ($requestCreator == $_SESSION['user_id']) {
            pop('Segregation of duties violation: You cannot approve a request you created', '/rfq/hod_approve.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
            exit;
        }

        // Record HOD approval
        $stmt = $pdo->prepare("
            UPDATE rfqs
            SET hod_approval_status = ?,
                hod_approved_by = ?,
                hod_approved_at = NOW(),
                hod_approval_comments = ?
            WHERE rfq_id = ?
        ");
        $stmt->execute([
            $action === 'APPROVED' ? 'APPROVED' : 'REJECTED',
            $_SESSION['user_id'],
            $comments ?: null,
            $rfq_id
        ]);

        // Log audit trail
        $stmt = $pdo->prepare("
            INSERT INTO audit_log (table_name, action, notes, approval_stage, approval_action, approval_comments, change_date)
            VALUES ('rfqs', 'HOD_APPROVAL', ?, 'HOD_APPROVAL', ?, ?, NOW())
        ");
        $stmt->execute([
            "RFQ {$rfq_id} HOD approval: {$action}",
            $action,
            $comments ?: null
        ]);

        if ($action === 'APPROVED') {
            // Mark RFQ as awarded/completed
            $stmt = $pdo->prepare("
                UPDATE procurement_requests
                SET status = 'COMPLETED'
                WHERE request_id = ?
            ");
            $stmt->execute([$rfq['request_id']]);

            $message = 'RFQ approved by HOD. Procurement process completed.';
            $redirectUrl = '/rfq/view.php?id=' . $rfq_id;
        } else {
            // Route back to appropriate stage for correction
            $message = 'RFQ rejected by HOD. Please review comments and resubmit if needed.';
            $redirectUrl = '/rfq/view.php?id=' . $rfq_id;
        }

        $pdo->commit();
        pop($message, $redirectUrl, POP_DEFAULT_DELAY_MS, 'success');
        exit;

    } catch (Throwable $e) {
        $pdo->rollBack();
        pop('Error processing HOD approval: ' . extractDbMessage($e), '/rfq/hod_approve.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
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
            <i class="bi bi-building"></i> HOD Final Approval
        </h4>
        <p class="text-muted mb-0 small">Government Chemist final approval for award</p>
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
                        <label class="form-label text-muted small">RFQ Number</label>
                        <div class="fw-semibold"><?= htmlspecialchars($rfq['rfq_number']) ?></div>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label text-muted small">Description</label>
                        <div class="fw-normal"><?= htmlspecialchars($rfq['description']) ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Selected Vendor</label>
                        <div class="fw-semibold"><?= htmlspecialchars($rfq['vendor_name'] ?? 'Not specified') ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Quote Amount</label>
                        <div class="fw-semibold text-success">
                            $<?= number_format($rfq['quote_amount'] ?? 0, 2) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- PO and Commitment Summary -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 rounded-top-4 py-3">
                <h6 class="fw-semibold mb-0"><i class="bi bi-file-text me-1"></i> PO & Commitment</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label text-muted small">PO Number</label>
                        <div class="fw-semibold"><?= htmlspecialchars($rfq['po_number'] ?? 'Pending') ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">PO Amount</label>
                        <div class="fw-semibold">$<?= number_format($rfq['po_amount'] ?? 0, 2) ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Commitment Number</label>
                        <div class="fw-semibold"><?= htmlspecialchars($rfq['commitment_number'] ?? 'Pending') ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Status</label>
                        <div>
                            <span class="badge bg-success">
                                <i class="bi bi-check-circle me-1"></i>Complete
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Invoice Verification Status -->
        <?php if ($invoiceVerification): ?>
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 rounded-top-4 py-3">
                <h6 class="fw-semibold mb-0"><i class="bi bi-check-square me-1"></i> Invoice Verification</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Invoice Number</label>
                        <div class="fw-semibold"><?= htmlspecialchars($invoiceVerification['invoice_number']) ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Invoice Amount</label>
                        <div class="fw-semibold">$<?= number_format($invoiceVerification['invoice_amount'], 2) ?></div>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label text-muted small">Verification Status</label>
                        <div>
                            <?php if ($invoiceVerification['verification_status'] === 'VERIFIED'): ?>
                                <span class="badge bg-success">
                                    <i class="bi bi-check-circle me-1"></i>Verified - No Mismatches
                                </span>
                            <?php else: ?>
                                <span class="badge bg-warning">
                                    <i class="bi bi-exclamation-triangle me-1"></i>Mismatches Flagged
                                </span>
                                <div class="alert alert-warning mt-2 mb-0" style="font-size: 0.875rem;">
                                    <?php if ($invoiceVerification['mismatch_details']): ?>
                                        <strong>Issues:</strong>
                                        <ul class="mb-0 mt-1">
                                            <?php foreach (json_decode($invoiceVerification['mismatch_details'], true) as $issue): ?>
                                            <li><?= htmlspecialchars($issue) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Workflow Approval History -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 rounded-top-4 py-3">
                <h6 class="fw-semibold mb-0"><i class="bi bi-clock-history me-1"></i> Approval History</h6>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr class="text-muted small">
                                <th>Stage</th>
                                <th>Approver</th>
                                <th>Action</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($approvalHistory, 0, 5) as $approval): ?>
                            <tr>
                                <td class="small"><?= ucfirst(str_replace('_', ' ', $approval['approval_stage'])) ?></td>
                                <td class="small"><?= htmlspecialchars($approval['display_name'] ?? 'Unknown') ?></td>
                                <td class="small">
                                    <span class="badge bg-<?= $approval['action'] === 'APPROVED' ? 'success' : 'danger' ?>">
                                        <?= ucfirst($approval['action']) ?>
                                    </span>
                                </td>
                                <td class="small"><?= date('M d, Y', strtotime($approval['created_at'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- HOD Approval Form -->
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-0 rounded-top-4 py-3">
                <h6 class="fw-semibold mb-0"><i class="bi bi-pencil-square me-1"></i> Government Chemist Approval</h6>
            </div>
            <div class="card-body">
                <form method="POST" class="needs-validation">
                    <!-- Approval Comments -->
                    <div class="mb-3">
                        <label for="comments" class="form-label">
                            Comments <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control" id="comments" name="comments" 
                                  rows="4" required minlength="5"
                                  placeholder="Provide your comments for this RFQ approval..."></textarea>
                        <div class="form-text">Required for both approval and rejection (minimum 5 characters)</div>
                    </div>

                    <!-- Information Alert -->
                    <div class="alert alert-info mb-3">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Final Approval:</strong> This is the final approval stage for this RFQ. 
                        Approval will complete the procurement process. Rejection will return the RFQ for correction.
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
                            <i class="bi bi-check-circle me-1"></i>Approve & Complete Award
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/footer.php"; ?>
