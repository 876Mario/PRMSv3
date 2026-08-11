<?php
$REQUIRE_PERMISSION = 'approve_request';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/helper.php";

$id = $_GET['id'] ?? null;

/* ===============================
   Validate ID
================================ */
if (!$id || !is_numeric($id)) {
    modalPop(
        "Invalid Request",
        "Invalid request ID.",
        "/procurement/list.php",
        "error"
    );
    exit;
}

$user_id = $_SESSION['user_id'];
$current_role = $_SESSION['role_name'] ?? 'Unknown';

/* ===============================
   Fetch request with branch and value
================================ */
$stmt = $pdo->prepare("
    SELECT pr.*, b.branch_name
    FROM procurement_requests pr
    LEFT JOIN branches b ON pr.branch_id = b.branch_id
    WHERE pr.request_id = ?
");
$stmt->execute([$id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    modalPop(
        "Invalid Request",
        "Request not found.",
        "/procurement/list.php",
        "warning"
    );
    exit;
}

if (strtoupper($request['status'] ?? '') === 'PAUSED') {
    modalPop("Procurement Paused", "Resume this procurement before approving it.", "/procurement/view.php?id=" . $id, "warning");
    exit;
}

/* ===============================
   Prevent self-approval
================================ */
if ($request['created_by'] == $user_id) {
    modalPop(
        "Not Allowed",
        "You cannot approve a request you created.",
        "/procurement/view.php?id=" . $id,
        "error"
    );
    exit;
}

/* ===============================
   Must not be finalized
================================ */
if (in_array(strtoupper($request['status']), ['COMPLETED', 'DECLINED', 'AWARDED'])) {
    modalPop(
        "Invalid Action",
        "This request is already finalized.",
        "/procurement/view.php?id=" . $id,
        "warning"
    );
    exit;
}

/* ===============================
   Signed request form must be uploaded
   before the first approval can occur
================================ */
if (signedRequestUploadPending($request)) {
    modalPop(
        "Signed Request Form Required",
        "This request cannot be approved yet. The requester must print the request form, sign it, and upload the signed copy before approval becomes available.",
        "/procurement/view.php?id=" . $id,
        "warning"
    );
    exit;
}

/* ===============================
   Get next pending approval stage
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
    modalPop(
        "No Pending Approvals",
        "All approval stages are complete or this request has no pending approvals.",
        "/procurement/view.php?id=" . $id,
        "warning"
    );
    exit;
}

/* ===============================
   Check if user can approve this stage
   (including fallback approvers)
================================ */
$estimatedValue = (float)($request['estimated_value'] ?? 0);
if (!canApproveStage($current_role, $nextApproval['role'], $estimatedValue)) {
    modalPop(
        "Unauthorized",
        "You are not authorized to approve this stage.\n" .
        "Stage requires: " . htmlspecialchars($nextApproval['role']) . "\n" .
        "Your role: " . htmlspecialchars($current_role),
        "/procurement/view.php?id=" . $id,
        "error"
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

        try {
        $pdo->prepare("
            UPDATE request_approvals
            SET status = 'approved',
                approved_by = ?,
                approved_at = NOW()
            WHERE id = ?
        ")->execute([$user_id, $nextApproval['id']]);

        $approverName = $current_role;
        if ($current_role !== $nextApproval['role']) {
            $approverName .= ' (as fallback for ' . $nextApproval['role'] . ')';
        }

        logAudit(
            $pdo,
            'request_approvals',
            $nextApproval['id'],
            'APPROVE_STAGE',
            'Approved by ' . $approverName
        );

        // Determine next status dynamically
        $nextStatus = getNextStatusAfterApproval($pdo, $id, $nextApproval['role']);
        
        enforceTransition($request, $nextStatus);

        $pdo->prepare("
            UPDATE procurement_requests
            SET status = ?,
                approved_by = ?,
                approved_at = NOW(),
                funds_available = 1,
                finance_reviewed_by = ?,
                finance_reviewed_at = NOW()
            WHERE request_id = ?
        ")->execute([$nextStatus, $user_id, $user_id, $id]);

        logAudit(
            $pdo,
            'procurement_requests',
            $id,
            'STATUS_CHANGE',
            'Approved → ' . $nextStatus . ' (funds certified) by ' . $approverName
        );

        logRequestTimeline(
            $pdo,
            $id,
            $nextStatus,
            'Approval by ' . $approverName
        );

        /* Notify next approver or requestor of finalization */
        require_once $_SERVER['DOCUMENT_ROOT']."/config/notifications.php";
        notifyNextApprover($id, $nextApproval['role']);
        if (in_array($nextStatus, ['AWARDED', 'RFQ_LETTER_AVAILABLE', 'PROCUREMENT_STAGE'])) {
            notifyRequestFinalized($id, $nextStatus);
        }
        if ($nextStatus === 'RFQ_LETTER_AVAILABLE') {
            notifyProcurementRFQReady($id);
        }

        modalPop(
            "Approval Successful",
            "Approval recorded successfully. Returning to Dashboard.",
            "/dashboard/index.php",
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
            modalPop(
                "Rejection Reason Required",
                "You must provide a reason for rejection.",
                "/procurement/view.php?id=" . $id,
                "warning"
            );
            exit;
        }

        try {
        $pdo->prepare("
            UPDATE request_approvals
            SET status = 'rejected',
                rejection_reason = ?,
                approved_by = ?,
                approved_at = NOW()
            WHERE id = ?
        ")->execute([$reason, $user_id, $nextApproval['id']]);

        /* Mark main request as Declined */
        $pdo->prepare("
            UPDATE procurement_requests
            SET status = 'DECLINED',
                approved_by = ?,
                approved_at = NOW(),
                decline_reason = ?
            WHERE request_id = ?
        ")->execute([$user_id, $reason, $id]);

        logAudit(
            $pdo,
            'procurement_requests',
            $id,
            'STATUS_CHANGE',
            'Submitted → Declined'
        );

        logAudit(
            $pdo,
            'request_approvals',
            $nextApproval['id'],
            'REJECT_STAGE',
            'Rejected by ' . $current_role . ' - ' . $reason
        );
        
        /* Send notification to requestor */
        require_once $_SERVER['DOCUMENT_ROOT']."/config/notifications.php";
        notifyRequestDeclined($id, (int)$request['created_by'], $reason);

        logRequestTimeline(
            $pdo,
            $id,
            'DECLINED',
            'Request declined by ' . $current_role . ' at ' . $nextApproval['role'] . ' stage'
        );

        modalPop(
            "Request Rejected",
            "Procurement request has been declined.",
            "/procurement/list.php",
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
   Render Buttons (Simple UI)
================================ */
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';
?>

<div class="container mt-4">
    <div class="card shadow-sm">
        <div class="card-header bg-light">
            <h4 class="mb-0"><i class="bi bi-check-circle me-2"></i> Approve Procurement Request</h4>
        </div>
        <div class="card-body">
            <div class="alert alert-info mb-3">
                <strong>Request:</strong> <?= htmlspecialchars($request['request_number'] ?? 'N/A') ?><br>
                <strong>Branch:</strong> <?= htmlspecialchars($request['branch_name'] ?? 'N/A') ?><br>
                <strong>Amount:</strong> <?= htmlspecialchars($request['currency'] ?? 'JMD') ?> <?= number_format($estimatedValue, 2) ?><br>
                <strong>Current Stage:</strong> <span class="badge bg-warning text-dark"><?= htmlspecialchars($nextApproval['role']) ?></span>
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
                    <?php if (in_array($current_role, ['Director HRM&A', 'Admin', 'SuperAdmin'], true)): ?>
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