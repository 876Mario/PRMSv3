<?php
$REQUIRE_PERMISSION = 'create_reimbursement_request';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';

$request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
requireCsrfToken('/reimbursement/list.php');

if ($request_id <= 0) {
    pop("Invalid reimbursement request reference.", "/reimbursement/list.php");
    exit;
}

/* ================================
   Fetch Request
================================ */
$stmt = $pdo->prepare("
    SELECT r.*, b.branch_id
    FROM procurement_requests r
    LEFT JOIN branches b ON r.branch_id = b.branch_id
    WHERE r.request_id = ?
    LIMIT 1
");
$stmt->execute([$request_id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    pop("Reimbursement request not found.", "/reimbursement/list.php");
    exit;
}

// Only the creator can submit their own draft request
if ((int)$request['created_by'] !== (int)$_SESSION['user_id']) {
    pop(
        "You can only submit your own reimbursement requests.",
        "/reimbursement/view.php?request_id=".$request_id,
        2000,
        "error"
    );
    exit;
}

/* ================================
   Status Validation
================================ */
if (!in_array(strtoupper($request['status']), ['DRAFT', 'RETURNED_FOR_CORRECTION'], true)) {
    pop(
        "Only draft or returned reimbursement requests can be submitted.",
        "/reimbursement/view.php?request_id=".$request_id,
        2000,
        "error"
    );
    exit;
}

try {
    $pdo->beginTransaction();

    /* ================================
       Update Status & Ensure request_type
    ================================ */
    $update = $pdo->prepare("
        UPDATE procurement_requests
        SET status = 'SUBMITTED',
            request_type = 'REIMBURSEMENT',
            decline_reason = NULL,
            updated_at = NOW()
        WHERE request_id = ?
    ");
    $update->execute([$request_id]);
    if (strtoupper((string)$request['status']) === 'RETURNED_FOR_CORRECTION') {
        $pdo->prepare("
            UPDATE request_approvals
            SET status = 'rejected',
                comments = CONCAT(COALESCE(NULLIF(comments, ''), 'No comments'), ' [Superseded by resubmission]'),
                approved_by = COALESCE(approved_by, ?),
                approved_at = COALESCE(approved_at, NOW())
            WHERE request_id = ?
              AND status = 'pending'
        ")->execute([$_SESSION['user_id'], $request_id]);
    }

    /* ================================
       Audit Log
    ================================ */
    logAudit(
        $pdo,
        'procurement_requests',
        $request_id,
        'STATUS_CHANGE',
        'Reimbursement Request: Draft → Submitted'
    );

    /* ================================
       Create Approval Chain for Reimbursement
       Reimbursement requests go directly to Finance for fund verification
       (No HOD approval needed - simplified workflow)
    ================================ */
    $estimatedValue = (float)($request['estimated_value'] ?? 0);
    $branchId = (int)($request['branch_id'] ?? 0);

    // Reimbursement only requires Finance Officer approval (fund verification)
    $approvalRoles = ['Finance Officer'];

    // Create approval entries
    $stageOrder = 1;
    $firstApprovalRole = null;
    $firstApprovalStage = null;

    foreach ($approvalRoles as $role) {
        $pdo->prepare("
            INSERT INTO request_approvals
            (entity_type, entity_id, request_id, role, stage_order, status)
            VALUES ('REQUEST', ?, ?, ?, ?, 'pending')
        ")->execute([$request_id, $request_id, $role, $stageOrder]);
        
        if ($stageOrder === 1) {
            $firstApprovalRole = $role;
            // Convert role to stage name
            $firstApprovalStage = match($role) {
                'HOD' => 'HOD_APPROVED',
                'Finance Officer' => 'FUNDS_VERIFIED',
                default => 'HOD_APPROVED'
            };
        }
        
        $stageOrder++;
    }

    logAudit(
        $pdo,
        'procurement_requests',
        $request_id,
        'APPROVAL_CHAIN_CREATED',
        'Reimbursement approval chain created: ' . implode(' → ', $approvalRoles)
    );

    /* ================================
       Send Notifications
    ================================ */
    require_once $_SERVER['DOCUMENT_ROOT'].'/config/notifications.php';

    // Notify all Finance Officers about this reimbursement request
    notifyFinanceForDirectApproval($request_id, 'REIMBURSEMENT');

    // Confirm submission to the requestor
    notifyRequestorSubmissionConfirmed($request_id);

    $pdo->commit();

    /* ================================
       Redirect
    ================================ */
    pop(
        "Reimbursement request submitted successfully. It will now go through the approval workflow.",
        "/reimbursement/view.php?request_id=".$request_id,
        1500,
        "success"
    );

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Reimbursement submission error: " . $e->getMessage());
    pop(
        "Error submitting reimbursement request: " . extractDbMessage($e),
        "/reimbursement/view.php?request_id=".$request_id,
        2000,
        "error"
    );
}
