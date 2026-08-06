<?php
$REQUIRE_PERMISSION = 'approve_request';

require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    pop('Invalid request ID', '/procurement/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$stmt = $pdo->prepare("
    SELECT pr.*, b.branch_name 
    FROM procurement_requests pr
    LEFT JOIN branches b ON pr.branch_id = b.branch_id
    WHERE pr.request_id = ?
");
$stmt->execute([$id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    pop('Request not found', '/procurement/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

if (strtoupper($request['status'] ?? '') === 'PAUSED') {
    pop('Resume this procurement before approving it.', '/procurement/view.php?id='.$id, POP_DEFAULT_DELAY_MS, 'warning');
    exit;
}

$user_id = $_SESSION['user_id'];

/* ===============================
   Signed request form must be uploaded
   before the first approval can occur
================================ */
if (signedRequestUploadPending($request)) {
    pop(
        'This request cannot be approved yet. The requester must print, sign, and upload the signed request form first.',
        '/procurement/view.php?id='.$id,
        POP_DEFAULT_DELAY_MS,
        'warning'
    );
    exit;
}

/* ===============================
   Handle POST (Approve / Reject)
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    /* ===============================
       APPROVE
    ================================ */
    if ($action === 'approve') {

        /* Get pending Finance Officer approval */
        $financeStmt = $pdo->prepare("
            SELECT id FROM request_approvals
            WHERE request_id = ?
              AND status = 'pending'
              AND role = 'Finance Officer'
            LIMIT 1
        ");
        $financeStmt->execute([$id]);
        $financeApproval = $financeStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$financeApproval) {
            pop('No pending Finance officer approval found', '/procurement/view.php?id='.$id, POP_DEFAULT_DELAY_MS, 'error');
            exit;
        }

        // Determine next status dynamically based on approval chain
        $nextStatus = getNextStatusAfterApproval($pdo, $id, 'Finance Officer');
        
        enforceTransition($request, $nextStatus);

        try {
        /* Mark the pending Finance approval as approved */
        $pdo->prepare("
            UPDATE request_approvals
            SET status = 'approved',
                approved_by = ?,
                approved_at = NOW()
            WHERE id = ?
        ")->execute([$user_id, $financeApproval['id']]);

        $pdo->prepare("
            UPDATE procurement_requests
            SET status = ?,
                funds_available = 1,
                finance_reviewed_by = ?,
                finance_reviewed_at = NOW()
            WHERE request_id = ?
        ")->execute([$nextStatus, $user_id, $id]);

        logAudit($pdo, 'procurement_requests', $id, 'STATUS_CHANGE', 'Finance Verified Funds — Status changed to ' . $nextStatus);
        logRequestTimeline($pdo, $id, $nextStatus, 'Finance verification by ' . ($_SESSION['full_name'] ?? 'Unknown'));

        /* Notify next approver or requestor of finalization */
        require_once $_SERVER['DOCUMENT_ROOT']."/config/notifications.php";
        notifyNextApprover($id, 'Finance Officer');
        notifyProcurementOfApproval($id, $nextStatus);
        if (in_array($nextStatus, ['AWARDED', 'RFQ_LETTER_AVAILABLE', 'PROCUREMENT_STAGE'])) {
            notifyRequestFinalized($id, $nextStatus);
        }
        if ($nextStatus === 'RFQ_LETTER_AVAILABLE') {
            notifyProcurementRFQReady($id);
        }

        pop(
            "Request approved successfully.",
            "/dashboard/index.php",
            1500,
            "success"
        );
        exit;
        } catch (Throwable $e) {
            pop(extractDbMessage($e), "/procurement/view.php?id=" . $id, POP_DEFAULT_DELAY_MS, 'error');
            exit;
        }
    }

    /* ===============================
       REJECT
    ================================ */
    elseif ($action === 'reject') {

        $reason = trim($_POST['rejection_reason'] ?? '');

        if (empty($reason)) {
            pop(
                "Rejection Reason Required",
                "You must provide a reason for rejection.",
                "/procurement/approve_finance.php?id=".$id,
                "warning"
            );
            exit;
        }

        try {
        /* Mark request as Declined */
        $pdo->prepare("
            UPDATE procurement_requests
            SET status = 'DECLINED',
                finance_reviewed_by = ?,
                finance_reviewed_at = NOW()
            WHERE request_id = ?
        ")->execute([$user_id, $id]);

        logAudit(
            $pdo,
            'procurement_requests',
            $id,
            'STATUS_CHANGE',
            'Finance Rejected — Status changed to DECLINED'
        );

        logRequestTimeline(
            $pdo,
            $id,
            'FINANCE_REJECTED',
            'Finance rejected by ' . ($_SESSION['full_name'] ?? 'Unknown') . ': ' . $reason
        );

        /* Notify requestor of decline */
        require_once $_SERVER['DOCUMENT_ROOT']."/config/notifications.php";
        notifyRequestDeclined($id, (int)$request['created_by'], $reason);

        pop(
            "Request rejected. Reason: " . htmlspecialchars($reason),
            "/procurement/view.php?id=".$id,
            2000,
            "warning"
        );
        exit;
        } catch (Throwable $e) {
            pop(extractDbMessage($e), "/procurement/view.php?id=" . $id, POP_DEFAULT_DELAY_MS, 'error');
            exit;
        }
    }
}

/* ===============================
   Render Approval Form
================================ */
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';
?>

<div class="container mt-4">
    <div class="card shadow-sm">
        <div class="card-header bg-light">
            <h4 class="mb-0"><i class="bi bi-cash-coin me-2"></i> Finance Approval</h4>
        </div>
        <div class="card-body">
            <div class="alert alert-info mb-3">
                <strong>Request:</strong> <?= htmlspecialchars($request['request_number'] ?? 'N/A') ?><br>
                <strong>Branch:</strong> <?= htmlspecialchars($request['branch_name'] ?? 'N/A') ?><br>
                <strong>Amount:</strong> <?= htmlspecialchars($request['currency'] ?? 'JMD') ?> <?= number_format((float)($request['estimated_value'] ?? 0), 2) ?><br>
                <strong>Description:</strong> <?= htmlspecialchars(substr($request['description'] ?? 'N/A', 0, 100)) ?>...
            </div>
            <form method="post">
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                <div class="mb-3">
                    <label class="form-label fw-bold">Rejection Reason <span class="text-danger">*</span> (Required if rejecting)</label>
                    <textarea 
                        name="rejection_reason" 
                        class="form-control"
                        rows="3"
                        placeholder="Enter reason if rejecting..."
                    ></textarea>
                </div>
                <div class="d-flex gap-2">
                    <button name="action" value="approve" class="btn btn-success">
                        <i class="bi bi-check-lg me-1"></i> Approve
                    </button>
                    <button 
                        name="action" 
                        value="reject" 
                        class="btn btn-danger"
                        onclick="return confirm('Are you sure you want to reject this request?')"
                    >
                        <i class="bi bi-x-circle me-1"></i> Reject
                    </button>
                    <?php if (in_array(($_SESSION['role_name'] ?? ''), ['Procurement Officer', 'Admin', 'SuperAdmin'], true)): ?>
                        <button type="submit"
                            formaction="/procurement/send_back.php"
                            formmethod="post"
                            class="btn btn-warning"
                            onclick="return confirm('Send this request back for editing?')">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Send Back for Edit
                        </button>
                    <?php endif; ?>
                    <a href="/procurement/view.php?id=<?= (int)$id ?>"
                         class="btn btn-secondary">
                        <i class="bi bi-arrow-left me-1"></i> Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>