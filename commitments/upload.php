<?php
$REQUIRE_PERMISSION = 'approve_commitment';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';

$id = $_GET['id'] ?? null;
$user_id = $_SESSION['user_id'];
$current_role = $_SESSION['role'];

if (!$id || !is_numeric($id)) {
    modalPop("Invalid", "Invalid commitment ID.", "/commitments/list.php", "error");
    exit;
}

/* ===============================
   Fetch commitment
================================ */
$stmt = $pdo->prepare("
    SELECT request_id, commitment_number, status
    FROM commitments
    WHERE commitment_id = ?
");
$stmt->execute([$id]);
$commitment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$commitment) {
    modalPop("Invalid", "Commitment not found.", "/commitments/list.php", "error");
    exit;
}

/* ===============================
   Must be Open
================================ */
if ($commitment['status'] !== 'open') {
    modalPop(
        "Invalid Action",
        "This commitment is already finalized.",
        "/commitments/view.php?commitment_id=".$id,
        "warning"
    );
    exit;
}

/* ===============================
   Seed approval chain if missing
   (handles legacy commitments)
================================ */
$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM request_approvals
    WHERE entity_type = 'COMMITMENT'
      AND entity_id = ?
");
$countStmt->execute([$id]);

if ((int)$countStmt->fetchColumn() === 0) {
    // Get request details for threshold-based routing
    $reqDetailsStmt = $pdo->prepare("
        SELECT pr.estimated_value, pr.branch_id
        FROM commitments c
        JOIN procurement_requests pr ON c.request_id = pr.request_id
        WHERE c.commitment_id = ?
    ");
    $reqDetailsStmt->execute([$id]);
    $reqDetails = $reqDetailsStmt->fetch(PDO::FETCH_ASSOC);

    $estimated_value = (float)($reqDetails['estimated_value'] ?? 0);
    $branch_id = (int)($reqDetails['branch_id'] ?? 0);

    // Use centralized commitment approval chain (reads threshold from DB)
    $approvalStages = getCommitmentApprovalChain($pdo, $estimated_value, $branch_id);
    foreach ($approvalStages as $stage) {
        $pdo->prepare("
            INSERT INTO request_approvals
            (entity_type, entity_id, role, stage_order, status)
            VALUES ('COMMITMENT', ?, ?, ?, 'pending')
        ")->execute([
            $id,
            $stage['role'],
            $stage['stage_order']
        ]);
    }
    $firstApprover = $approvalStages[0]['role'];
    logAudit($pdo, 'commitments', $id, 'SEED_APPROVAL_CHAIN',
        "Approval chain auto-created for legacy commitment: $firstApprover → Finance Officer");
}

/* ===============================
   Check stage
================================ */
$stmt = $pdo->prepare("
    SELECT id, role, stage_order
    FROM request_approvals
    WHERE entity_type = 'COMMITMENT'
      AND entity_id = ?
      AND status = 'pending'
    ORDER BY stage_order ASC, id ASC
    LIMIT 1
");
$stmt->execute([$id]);
$approval = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$approval || $approval['role'] !== $current_role) {
    modalPop("Unauthorized", "Not your approval stage.", "/commitments/view.php?commitment_id=".$id, "error");
    exit;
}



/* ===============================
   Handle POST (Approve / Reject)
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Check RFQ compliance
$stmt = $pdo->prepare("
    SELECT rfq_id 
    FROM commitments 
    WHERE commitment_id = ?
");
$stmt->execute([$id]);
$rfq_id = $stmt->fetchColumn();

if ($rfq_id) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM rfq_quotes q
        JOIN rfq_vendors v ON q.rfq_vendor_id = v.rfq_vendor_id
        WHERE v.rfq_id = ?
    ");
    $stmt->execute([$rfq_id]);
    $count = $stmt->fetchColumn();

    if ($count < 3) {
        pop('Minimum 3 quotations required before commitment approval', '/commitments/view.php?id='.$id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }
}


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
            $commitment['request_id'],
            "COMMITMENT_APPROVED_STAGE",
            "Commitment {$commitment['commitment_number']} approved by {$current_role}"
        );

        logAudit(
            $pdo,
            'commitments',
            $id,
            'APPROVE_STAGE',
            "Approved by {$current_role}"
        );

        /* Check if all stages complete */
        $remaining = $pdo->prepare("
            SELECT COUNT(*)
            FROM request_approvals
            WHERE entity_type = 'COMMITMENT'
              AND entity_id = ?
              AND status = 'pending'
        ");
        $remaining->execute([$id]);

        if ($remaining->fetchColumn() == 0) {

            $pdo->prepare("
                UPDATE commitments
                SET status = 'closed',
                    approved_at = NOW()
                WHERE commitment_id = ?
            ")->execute([$id]);

            // Advance procurement request status to COMMITMENT_APPROVED
            $pdo->prepare("
                UPDATE procurement_requests
                SET status = 'COMMITMENT_APPROVED'
                WHERE request_id = ?
            ")->execute([$commitment['request_id']]);

            logRequestTimeline(
                $pdo,
                $commitment['request_id'],
                "COMMITMENT_FULLY_APPROVED",
                "Commitment {$commitment['commitment_number']} fully approved"
            );

            logAudit(
                $pdo,
                'commitments',
                $id,
                'COMMITMENT_APPROVED',
                'All approval stages complete'
            );
        }

        modalPop("Success", "Commitment approved.", "/commitments/view.php?commitment_id=".$id, "success");
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
                "/commitments/view.php?commitment_id=".$id,
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

        /* Optional: Keep commitment open but mark as rejected stage */
        logRequestTimeline(
            $pdo,
            $commitment['request_id'],
            "COMMITMENT_REJECTED_STAGE",
            "Commitment {$commitment['commitment_number']} rejected by {$current_role}: {$reason}"
        );

        logAudit(
            $pdo,
            'commitments',
            $id,
            'REJECT_STAGE',
            "Rejected by {$current_role} - {$reason}"
        );

        modalPop(
            "Commitment Rejected",
            "Commitment stage has been rejected.",
            "/commitments/view.php?commitment_id=".$id,
            "warning"
        );
        exit;
    }

    } catch (Throwable $e) {
        modalPop("Error", extractDbMessage($e), "/commitments/view.php?commitment_id=".$id, "error");
        exit;
    }
}

/* ===============================
   Render Simple Approval UI
================================ */
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';
?>

<div class="container mt-4">
    <div class="card shadow-sm">
        <div class="card-body">
            <h4 class="mb-3"><i class="bi bi-check-circle me-2"></i> Approve Commitment</h4>
            <form method="post">
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
                        onclick="return confirm('Are you sure you want to reject this commitment stage?')"
                    >
                        <i class="bi bi-x-circle me-1"></i> Reject
                    </button>
                    <a href="/commitments/view.php?commitment_id=<?= (int)$id ?>"
                         class="btn btn-secondary">
                        <i class="bi bi-arrow-left me-1"></i> Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
