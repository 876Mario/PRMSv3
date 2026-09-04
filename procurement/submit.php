<?php
$REQUIRE_PERMISSION = 'submit_request';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';

requirePostRequest('/procurement/list.php');
requireCsrfToken('/procurement/list.php');

$request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;

if ($request_id <= 0) {
    pop("Invalid request reference.", "/procurement/list.php");
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
    pop("Procurement request not found.", "/procurement/list.php");
    exit;
}

if (!canCurrentUserEditOrSubmitRequest($request)) {
    pop("You do not have permission to submit this procurement request.", "/procurement/list.php", POP_DEFAULT_DELAY_MS, "error");
    exit;
}

/* ================================
   Status Validation
================================ */
if (strtoupper($request['status']) !== 'DRAFT') {
    pop(
        "Only draft requests can be submitted.",
        "/procurement/view.php?id=".$request_id,
        2000,
        "error"
    );
    exit;
}

/* ================================
   Update Status + Approval Chain
================================ */
try {
    $pdo->beginTransaction();

    $update = $pdo->prepare("
        UPDATE procurement_requests
        SET status = 'SUBMITTED',
            updated_at = NOW(),
            decline_reason = NULL,
            approved_by = NULL,
            approved_at = NULL,
            funds_available = 0,
            finance_reviewed_by = NULL,
            finance_reviewed_at = NULL
        WHERE request_id = ?
    ");
    $update->execute([$request_id]);

    logAudit(
        $pdo,
        'procurement_requests',
        $request_id,
        'STATUS_CHANGE',
        'Draft → Submitted'
    );

    /* ================================
       Create Approval Chain
    ================================ */
    $requestType = $request['request_type'] ?? 'REGULAR';
    $estimatedValue = (float)($request['estimated_value'] ?? 0);
    $branchId = (int)($request['branch_id'] ?? 0);

    // Convert to JMD equivalent for threshold comparison
    $currency = strtoupper(trim($request['currency'] ?? 'JMD'));
    if ($currency === 'USD') {
        $usdRate = (float)($request['usd_rate'] ?? 0);
        if ($usdRate <= 0) {
            $rateStmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'usd_to_jmd_rate'");
            $rateStmt->execute();
            $usdRate = (float)($rateStmt->fetchColumn() ?: 155.00);
        }
        $estimatedValue = $estimatedValue * $usdRate;
    }

    // Get the approval chain based on branch, type, and amount (reads threshold from DB)
    $approvalRoles = getApprovalChain($requestType, $estimatedValue, $branchId, $pdo);

    // Create approval entries and track first approver for notification
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
                'Director HRM&A' => 'DIRECTOR_APPROVED',
                'Deputy Government Chemist' => 'GC_APPROVED',
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
        'Approval chain created: ' . implode(' → ', $approvalRoles)
    );

    $pdo->commit();

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // extractDbMessage() is defined in config/helper.php (already required above)
    pop(
        "Submission failed: " . extractDbMessage($e),
        "/procurement/view.php?id=" . $request_id,
        2500,
        "error"
    );
    exit;
}

/* ================================
   Send Notifications
================================ */
require_once $_SERVER['DOCUMENT_ROOT'].'/config/notifications.php';

// Send notification to first approver with action required
if ($firstApprovalRole) {
    $approverStmt = $pdo->prepare('
        SELECT u.user_id
        FROM users u
        INNER JOIN roles r ON u.role_id = r.id
        WHERE r.name = ? AND u.is_active = 1
        LIMIT 1
    ');
    $approverStmt->execute([$firstApprovalRole]);
    $approver = $approverStmt->fetch(PDO::FETCH_ASSOC);

    if ($approver) {
        notifyApprovalNeeded($request_id, $firstApprovalStage, $approver['user_id']);
    }
}

// Confirm submission to the requestor
notifyRequestorSubmissionConfirmed($request_id);

/* ================================
   Redirect
================================ */
pop(
    "Procurement request submitted successfully.",
    "/procurement/view.php?id=".$request_id,
    1500,
    "success"
);
