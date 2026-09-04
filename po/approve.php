<?php
$REQUIRE_PERMISSION = 'approve_purchase_order';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/services/ApprovalService.php';

$id = $_GET['id'] ?? null;
$user_id = $_SESSION['user_id'];
$current_role = $_SESSION['role'];

if (!$id || !is_numeric($id)) {
    modalPop("Invalid", "Invalid PO ID.", "/po/list.php", "error");
    exit;
}

/* ===============================
   Fetch PO
================================ */
$stmt = $pdo->prepare("
    SELECT 
        c.request_id, 
        po.po_number,
        po.po_total,
        po.status,
        po.created_at,
        c.commitment_number,
        pr.currency
    FROM purchase_orders po
    JOIN commitments c ON po.commitment_id = c.commitment_id
    JOIN procurement_requests pr ON c.request_id = pr.request_id
    WHERE po.po_id = ?
");
$stmt->execute([$id]);
$po = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$po) {
    modalPop("Invalid", "PO not found.", "/po/list.php", "error");
    exit;
}

/* ===============================
   Auto-seed approval chain if missing (legacy POs)
   NOTE: POs no longer require approval. If no chain exists,
   redirect back — the PO is already approved.
================================ */
$seedCheck = $pdo->prepare("
    SELECT COUNT(*) FROM request_approvals
    WHERE entity_type = 'PO' AND entity_id = ?
");
$seedCheck->execute([$id]);

if ((int)$seedCheck->fetchColumn() === 0) {
    // No approval chain — PO is auto-approved under new workflow
    // Set approved_at if not already set, advance status
    $pdo->prepare("UPDATE purchase_orders SET approved_at = COALESCE(approved_at, NOW()) WHERE po_id = ?")->execute([$id]);
    $pdo->prepare("UPDATE procurement_requests SET status = 'PO_PENDING' WHERE request_id = ? AND UPPER(status) IN ('PO_PENDING','PO_APPROVED')")->execute([$po['request_id']]);
    modalPop("Already Approved", "This PO does not require approval.", "/po/view.php?po_id=".$id, "info");
    exit;
}

/* ===============================
   Check approval stage
================================ */
$stmt = $pdo->prepare("
    SELECT id, role, stage_order
    FROM request_approvals
    WHERE entity_type = 'PO'
      AND entity_id = ?
      AND status = 'pending'
    ORDER BY stage_order ASC, id ASC
    LIMIT 1
");
$stmt->execute([$id]);
$approval = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$approval || $approval['role'] !== $current_role) {
    modalPop("Unauthorized", "Not your approval stage.", "/po/view.php?po_id=".$id, "error");
    exit;
}

/* ===============================
   Handle POST (Approve / Reject)
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    try {

    /* ===============================
       APPROVE
    ================================ */
    if ($action === 'approve') {

        $pdo->prepare("
            UPDATE request_approvals
            SET status = 'approved',
                approved_by = ?,
                approved_at = NOW()
            WHERE id = ?
        ")->execute([$user_id, $approval['id']]);

        logRequestTimeline(
            $pdo,
            $po['request_id'],
            "PO_APPROVED_STAGE",
            "PO {$po['po_number']} approved by {$current_role}"
        );

        logAudit(
            $pdo,
            'purchase_orders',
            $id,
            'APPROVE_STAGE',
            "Approved by {$current_role}"
        );

        /* Check completion */
        $remaining = $pdo->prepare("
            SELECT COUNT(*)
            FROM request_approvals
            WHERE entity_type = 'PO'
              AND entity_id = ?
              AND status = 'pending'
        ");
        $remaining->execute([$id]);
        $remainingCount = (int)$remaining->fetchColumn();

        if ($remainingCount == 0) {

            $pdo->prepare("
                UPDATE purchase_orders
                SET approved_at = NOW()
                WHERE po_id = ?
            ")->execute([$id]);

            // Advance procurement request status to PO_PENDING (no separate approval needed)
            $pdo->prepare("
                UPDATE procurement_requests
                SET status = 'PO_PENDING'
                WHERE request_id = ?
            ")->execute([$po['request_id']]);

            logRequestTimeline(
                $pdo,
                $po['request_id'],
                "PO_FULLY_APPROVED",
                "PO {$po['po_number']} fully approved"
            );

            logAudit(
                $pdo,
                'purchase_orders',
                $id,
                'PO_APPROVED',
                'All approval stages complete'
            );
        }

        /* Notify about PO approval */
        require_once $_SERVER['DOCUMENT_ROOT']."/config/notifications.php";
        if ($remainingCount == 0) {
            notifyPOAction($po['request_id'], $po['po_number'], 'APPROVED', 'Purchase Order fully approved. Ready for invoice.');
        } else {
            notifyPOAction($po['request_id'], $po['po_number'], 'STAGE_APPROVED', 'PO stage approved by ' . $current_role . '.');
        }

        modalPop("Success", "PO approved.", "/po/view.php?po_id=".$id, "success");
        exit;
    }

    /* ===============================
       REJECT
    ================================ */
    elseif ($action === 'reject') {

        $reason = trim($_POST['rejection_reason'] ?? '');

        if (empty($reason)) {
            modalPop(
                "Rejection Reason Required",
                "You must provide a reason for rejection.",
                "/po/view.php?po_id=".$id,
                "warning"
            );
            exit;
        }

        $pdo->prepare("
            UPDATE request_approvals
            SET status = 'rejected',
                rejection_reason = ?,
                approved_by = ?,
                approved_at = NOW()
            WHERE id = ?
        ")->execute([$reason, $user_id, $approval['id']]);

        logRequestTimeline(
            $pdo,
            $po['request_id'],
            "PO_REJECTED_STAGE",
            "PO {$po['po_number']} rejected by {$current_role}: {$reason}"
        );

        logAudit(
            $pdo,
            'purchase_orders',
            $id,
            'REJECT_STAGE',
            "Rejected by {$current_role} - {$reason}"
        );

        /* Notify about PO rejection */
        notifyPOAction($po['request_id'], $po['po_number'], 'REJECTED', 'PO rejected by ' . $current_role . '. Reason: ' . $reason);

        modalPop(
            "PO Rejected",
            "PO approval stage rejected.",
            "/po/view.php?po_id=".$id,
            "warning"
        );
        exit;
    }

    } catch (Throwable $e) {
        modalPop("Error", extractDbMessage($e), "/po/view.php?po_id=".$id, "error");
        exit;
    }
}

/* ===============================
   Render Simple UI
================================ */
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';

// Fetch all approval stages to show progress
$stageStmt = $pdo->prepare("
    SELECT 
        ra.id as stage_id,
        ra.role,
        ra.status,
        ra.approved_by,
        ra.approved_at,
        u.full_name as approved_by_name
    FROM request_approvals ra
    LEFT JOIN users u ON ra.approved_by = u.user_id
    WHERE ra.entity_type = 'PO'
      AND ra.entity_id = ?
    ORDER BY ra.id ASC
");
$stageStmt->execute([$id]);
$allStages = $stageStmt->fetchAll(PDO::FETCH_ASSOC);

// Count approval progress
$completedStages = array_filter($allStages, fn($s) => $s['status'] === 'approved');
$totalStages = count($allStages);
$completionPercentage = $totalStages > 0 ? round((count($completedStages) / $totalStages) * 100) : 0;
?>

<div class="container mt-4">
    <div class="row mb-4">
        <div class="col-lg-8">
            <h3 class="section-title">✅ PO Approval Required</h3>
            <p class="text-muted">Review and approve this purchase order</p>
        </div>
    </div>

    <!-- PO Details Card -->
    <div class="card mb-4 border-start border-primary border-3">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">📦 Purchase Order Details</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="mb-3">
                        <small class="text-muted d-block">PO Number</small>
                        <h6 class="fw-bold text-primary"><?= htmlspecialchars($po['po_number']) ?></h6>
                    </div>
                    <div class="mb-3">
                        <small class="text-muted d-block">Created At</small>
                        <p class="mb-0"><?= $po['created_at'] ? date('d M Y, h:i A', strtotime($po['created_at'])) : 'N/A' ?></p>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <small class="text-muted d-block">PO Total</small>
                        <p class="mb-0 fs-6">JMD <span class="badge bg-success"><?= number_format($po['po_total'], 2) ?></span></p>
                    </div>
                    <div class="mb-3">
                        <small class="text-muted d-block">Status</small>
                        <p class="mb-0">
                            <span class="badge bg-info"><?= htmlspecialchars($po['status']) ?></span>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Approval Progress Card -->
    <div class="card mb-4 border-secondary">
        <div class="card-header bg-secondary text-white">
            <h5 class="mb-0">📋 Approval Progress</h5>
        </div>
        <div class="card-body">
            <div class="progress mb-3" style="height: 25px;">
                <div class="progress-bar bg-success" role="progressbar" 
                     style="width: <?= $completionPercentage ?>%"
                     aria-valuenow="<?= $completionPercentage ?>" 
                     aria-valuemin="0" aria-valuemax="100">
                    <?= $completionPercentage ?>% (<?= count($completedStages) ?>/<?= $totalStages ?> stages)
                </div>
            </div>

            <!-- Approval Stages Timeline -->
            <div class="timeline">
                <?php foreach ($allStages as $index => $stage): ?>
                    <div class="timeline-item mb-3">
                        <div class="d-flex align-items-start">
                            <div class="timeline-marker me-3">
                                <?php if ($stage['status'] === 'approved'): ?>
                                    <span class="badge bg-success p-2"><i class="bi bi-check-circle-fill"></i></span>
                                <?php elseif ($stage['status'] === 'rejected'): ?>
                                    <span class="badge bg-danger p-2"><i class="bi bi-x-circle-fill"></i></span>
                                <?php elseif ($stage['status'] === 'pending' && $stage['stage_id'] == $approval['id']): ?>
                                    <span class="badge bg-warning p-2"><i class="bi bi-hourglass-split"></i></span>
                                <?php else: ?>
                                    <span class="badge bg-secondary p-2"><i class="bi bi-circle"></i></span>
                                <?php endif; ?>
                            </div>
                            <div class="flex-grow-1">
                                <small class="text-muted d-block">
                                    <?php if ($stage['status'] === 'pending' && $stage['stage_id'] == $approval['id']): ?>
                                        <strong class="text-warning">Your Stage (Pending)</strong>
                                    <?php else: ?>
                                        <strong><?= ucfirst($stage['status']) ?></strong>
                                    <?php endif; ?>
                                    - <?= htmlspecialchars($stage['role']) ?>
                                </small>
                                <?php if ($stage['approved_by_name']): ?>
                                    <small class="text-muted d-block">
                                        Approved by: <?= htmlspecialchars($stage['approved_by_name']) ?>
                                        <?php if ($stage['approved_at']): ?>
                                            on <?= date('d M Y, H:i', strtotime($stage['approved_at'])) ?>
                                        <?php endif; ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Approval Decision Form -->
    <div class="card border-secondary mb-4">
        <div class="card-header bg-secondary text-white">
            <h5 class="mb-0">🎯 Your Decision</h5>
        </div>
        <div class="card-body">
            <form method="post" id="approvalForm">

                <!-- Rejection Reason Field (Hidden by default) -->
                <div id="rejectionSection" class="mb-4" style="display: none;">
                    <label for="rejection_reason" class="form-label">
                        <span class="text-danger">*</span> Reason for Rejection
                    </label>
                    <textarea 
                        id="rejection_reason"
                        name="rejection_reason" 
                        class="form-control"
                        rows="4"
                        placeholder="Provide a detailed explanation for rejecting this PO..."
                    ></textarea>
                    <small class="text-muted d-block mt-1">This will be logged and communicated to the requester.</small>
                </div>

                <!-- Action Buttons -->
                <div class="d-grid gap-2 d-sm-flex justify-content-between">
                    <div class="d-flex gap-2 flex-wrap">
                        <button 
                            type="submit" 
                            name="action" 
                            value="approve" 
                            class="btn btn-success btn-lg"
                            onclick="return confirm('Approve this purchase order?')">
                            <i class="bi bi-check-circle"></i> Approve PO
                        </button>

                        <button 
                            type="button" 
                            class="btn btn-danger btn-lg"
                            id="rejectBtn"
                            onclick="toggleRejectionSection()">
                            <i class="bi bi-x-circle"></i> Reject PO
                        </button>
                    </div>

                    <a href="/po/view.php?po_id=<?= (int)$id ?>"
                       class="btn btn-outline-secondary btn-lg">
                        <i class="bi bi-arrow-left"></i> Cancel
                    </a>
                </div>

                <!-- Hidden reject button for form submission -->
                <button 
                    type="submit" 
                    name="action" 
                    value="reject" 
                    id="submitRejectBtn"
                    class="btn btn-danger"
                    style="display: none;">
                </button>
            </form>
        </div>
    </div>

    <!-- Info Alert -->
    <div class="alert alert-info alert-dismissible fade show" role="alert">
        <i class="bi bi-lightbulb"></i>
        <strong>Note:</strong> Your approval is <?= count($completedStages) < $totalStages ? 'required to proceed' : 'the final step' ?> in the PO approval workflow. Please review all details carefully before deciding.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
</div>

<style>
.timeline-item {
    padding-left: 10px;
    border-left: 2px solid #dee2e6;
}
.timeline-item:last-child {
    border-left: none;
}
.timeline-marker {
    margin-top: 2px;
}
</style>

<script>
function toggleRejectionSection() {
    const section = document.getElementById('rejectionSection');
    const rejectBtn = document.getElementById('rejectBtn');
    const isVisible = section.style.display !== 'none';
    
    if (isVisible) {
        section.style.display = 'none';
        rejectBtn.classList.remove('active');
        rejectBtn.innerHTML = '<i class="bi bi-x-circle"></i> Reject PO';
    } else {
        section.style.display = 'block';
        rejectBtn.classList.add('active');
        rejectBtn.innerHTML = '<i class="bi bi-check-circle"></i> Cancel Rejection';
        document.getElementById('rejection_reason').focus();
    }
}

// Form validation before submission
document.getElementById('approvalForm').addEventListener('submit', function(e) {
    const rejectionSection = document.getElementById('rejectionSection');
    const action = e.submitter?.value;
    
    if (action === 'reject' && rejectionSection.style.display !== 'none') {
        const reason = document.getElementById('rejection_reason').value.trim();
        if (!reason) {
            e.preventDefault();
            alert('Please provide a reason for rejection.');
            document.getElementById('rejection_reason').focus();
            return false;
        }
        if (reason.length < 10) {
            e.preventDefault();
            alert('Please provide a detailed reason (at least 10 characters).');
            document.getElementById('rejection_reason').focus();
            return false;
        }
    }
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
