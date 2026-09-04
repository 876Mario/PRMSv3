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
$userRole = $_SESSION['role_name'] ?? 'Unknown';
$estimatedValue = (float)($request['estimated_value'] ?? 0);

/* ================================
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

/* ================================
   Check if approval stage exists
   and if user can approve
================================ */
$stmt = $pdo->prepare("
    SELECT *
    FROM request_approvals
    WHERE request_id = ?
      AND status = 'pending'
    ORDER BY stage_order ASC
    LIMIT 1
");
$stmt->execute([$id]);
$nextApproval = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$nextApproval) {
    pop('No pending approvals for this request', '/procurement/view.php?id='.$id, POP_DEFAULT_DELAY_MS, 'warning');
    exit;
}

// Check if user can approve this stage (including fallback)
if (!canApproveStage($userRole, $nextApproval['role'], $estimatedValue)) {
    pop(
        'You are not authorized to approve this stage. ' .
        'This stage requires: ' . htmlspecialchars($nextApproval['role']),
        '/procurement/view.php?id='.$id,
        POP_DEFAULT_DELAY_MS,
        'error'
    );
    exit;
}

/* ===============================
   Handle POST (Approve / Reject)
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken('/procurement/approve_hod.php?id=' . $id);

    $action = $_POST['action'] ?? '';

    /* ===============================
       APPROVE
    ================================ */
    if ($action === 'approve') {

        try {
        // Determine the next status dynamically based on approval chain
        $nextStatus = getNextStatusAfterApproval($pdo, $id, $nextApproval['role']);
        
        enforceTransition($request, $nextStatus);

        $pdo->prepare("
            UPDATE procurement_requests
            SET status = ?,
                approved_by = ?,
                approved_at = NOW()
            WHERE request_id = ?
        ")->execute([$nextStatus, $user_id, $id]);

        // Mark this approval stage as approved
        $pdo->prepare("
            UPDATE request_approvals
            SET status = 'approved',
                approved_by = ?,
                approved_at = NOW()
            WHERE request_id = ?
              AND id = ?
        ")->execute([$user_id, $id, $nextApproval['id']]);

        $approverName = $userRole;
        if ($userRole !== $nextApproval['role']) {
            $approverName .= ' (as fallback for ' . $nextApproval['role'] . ')';
        }

        logAudit($pdo, 'procurement_requests', $id, 'STATUS_CHANGE', 'Approved — Status changed to ' . $nextStatus . ' by ' . $approverName);
        logRequestTimeline($pdo, $id, $nextStatus, 'Approval by ' . ($_SESSION['full_name'] ?? 'Unknown') . ' - ' . $approverName);

        /* Notify next approver or requestor of finalization */
        require_once $_SERVER['DOCUMENT_ROOT']."/config/notifications.php";
        notifyNextApprover($id, $nextApproval['role']);
        
        /* Notify procurement officers that request has been approved and is ready for processing */
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
                "/procurement/approve_hod.php?id=".$id,
                "warning"
            );
            exit;
        }

        try {
        // Mark approval stage as rejected
        $pdo->prepare("
            UPDATE request_approvals
            SET status = 'rejected',
                rejection_reason = ?,
                approved_by = ?,
                approved_at = NOW()
            WHERE request_id = ?
              AND id = ?
        ")->execute([$reason, $user_id, $id, $nextApproval['id']]);

        /* Mark main request as Declined */
        $pdo->prepare("
            UPDATE procurement_requests
            SET status = 'DECLINED',
                approved_by = ?,
                approved_at = NOW(),
                decline_reason = ?
            WHERE request_id = ?
        ")->execute([$user_id, $reason, $id]);

        logAudit($pdo, 'procurement_requests', $id, 'STATUS_CHANGE', 'HOD Rejected — Status changed to DECLINED');
        logRequestTimeline($pdo, $id, 'DECLINED', 'Request declined by ' . $userRole . ': ' . $reason);

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
            <h4 class="mb-0"><i class="bi bi-person-check me-2"></i> Technical/HOD Approval</h4>
        </div>
        <div class="card-body">
            <div class="alert alert-info mb-3">
                <strong>Request:</strong> <?= htmlspecialchars($request['request_number'] ?? 'N/A') ?><br>
                <strong>Branch:</strong> <?= htmlspecialchars($request['branch_name'] ?? 'N/A') ?><br>
                <strong>Amount:</strong> <?= htmlspecialchars($request['currency'] ?? 'JMD') ?> <?= number_format($estimatedValue, 2) ?><br>
                <strong>Current Approver Role:</strong> <span class="badge bg-warning text-dark"><?= htmlspecialchars($nextApproval['role']) ?></span>
                <?php if ($userRole !== $nextApproval['role']): ?>
                    <br><strong style="color: #856404;">Your Role (Fallback):</strong> <span class="badge bg-warning text-dark"><?= htmlspecialchars($userRole) ?></span>
                <?php endif; ?>
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ensureCsrfToken()) ?>">
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
                    <?php if (in_array($userRole, ['HOD', 'Branch Head', 'Admin', 'SuperAdmin'], true)): ?>
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