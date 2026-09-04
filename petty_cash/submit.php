<?php
$REQUIRE_PERMISSION = 'create_petty_cash_request';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';

$request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
requireCsrfToken('/petty_cash/list.php');

if ($request_id <= 0) {
    pop("Invalid petty cash request reference.", "/petty_cash/list.php");
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
    pop("Petty cash request not found.", "/petty_cash/list.php");
    exit;
}

// Only the creator can submit their own draft request
if ((int)$request['created_by'] !== (int)$_SESSION['user_id']) {
    pop(
        "You can only submit your own petty cash requests.",
        "/petty_cash/view.php?request_id=".$request_id,
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
        "Only draft or returned petty cash requests can be submitted.",
        "/petty_cash/view.php?request_id=".$request_id,
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
    $previousStatus = $request['status'];
    $newStatus = 'SUBMITTED';
    
    $update = $pdo->prepare("
        UPDATE procurement_requests
        SET status = 'SUBMITTED',
            request_type = 'PETTY_CASH',
            decline_reason = NULL,
            updated_at = NOW()
        WHERE request_id = ?
    ");
    $update->execute([$request_id]);
    $pdo->prepare("DELETE FROM request_approvals WHERE request_id = ?")->execute([$request_id]);

    /* ================================
       Log Status Change to History
    ================================ */
    $historyInsert = $pdo->prepare("
        INSERT INTO petty_cash_status_history
        (request_id, old_status, new_status, changed_by, change_notes)
        VALUES (?, ?, ?, ?, ?)
    ");
    $historyInsert->execute([
        $request_id,
        $previousStatus,
        $newStatus,
        $_SESSION['user_id'],
        'Petty cash request submitted for approval'
    ]);

    /* ================================
       Audit Log
    ================================ */
    logAudit(
        $pdo,
        'procurement_requests',
        $request_id,
        'STATUS_CHANGE',
        'Petty Cash Request: Draft → Submitted'
    );

    /* ================================
       Create Approval Chain for Petty Cash
       Petty cash requests go directly to Finance for fund verification
       (Simplified workflow - no HOD or Procurement approval needed)
    ================================ */
    // Only Finance Officer approval required for petty cash
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
                'Procurement Officer' => 'PROCUREMENT_ENDORSED',
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
        'Petty cash approval chain created: ' . implode(' → ', $approvalRoles)
    );

    /* ================================
       Send Notifications
    ================================ */
    require_once $_SERVER['DOCUMENT_ROOT'].'/config/notifications.php';

    // Notify all Finance Officers about this petty cash request
    notifyFinanceForDirectApproval($request_id, 'PETTY_CASH');

    // Confirm submission to the requestor
    notifyRequestorSubmissionConfirmed($request_id);

    $pdo->commit();

    /* ================================
       Redirect
    ================================ */
    pop(
        "Petty cash request submitted successfully. It will now go through the approval workflow.",
        "/petty_cash/view.php?request_id=".$request_id,
        1500,
        "success"
    );

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Petty cash submission error: " . $e->getMessage());
    pop(
        "Error submitting petty cash request: " . extractDbMessage($e),
        "/petty_cash/view.php?request_id=".$request_id,
        2000,
        "error"
    );
}
