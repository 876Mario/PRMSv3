<?php
/**
 * Petty Cash Disbursement Handler
 * Finance Officer records actual cash disbursement
 * 
 * Handles transition:
 * - FINANCE_AUTHORIZED → DISBURSED
 */

$REQUIRE_PERMISSION = 'approve_petty_cash_request';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/notifications.php';

/* ============================================================
   VALIDATE INPUTS
   ============================================================ */
$request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
$disbursal_notes = isset($_POST['disbursal_notes']) ? trim($_POST['disbursal_notes']) : '';

if ($request_id <= 0) {
    pop("Invalid petty cash request reference.", "/petty_cash/list.php");
    exit;
}

/* ============================================================
   VERIFY USER ROLE
   ============================================================ */
$userRole = $_SESSION['role_name'] ?? '';
if (!in_array($userRole, ['Finance Officer', 'Admin', 'SuperAdmin'])) {
    pop(
        "Only Finance Officers can disburse petty cash.",
        "/petty_cash/view.php?request_id={$request_id}",
        2000,
        "error"
    );
    exit;
}

/* ============================================================
   FETCH REQUEST & DISBURSEMENT
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT r.*, b.branch_name, u.full_name as requestor_name, u.email as requestor_email
    FROM procurement_requests r
    LEFT JOIN branches b ON r.branch_id = b.branch_id
    LEFT JOIN users u ON r.created_by = u.user_id
    WHERE r.request_id = ? AND r.request_type = 'PETTY_CASH'
");
$stmt->execute([$request_id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    pop("Petty cash request not found.", "/petty_cash/list.php");
    exit;
}

// Fetch disbursement record
$disbStmt = $pdo->prepare("
    SELECT *
    FROM petty_cash_disbursements
    WHERE request_id = ?
");
$disbStmt->execute([$request_id]);
$disbursement = $disbStmt->fetch(PDO::FETCH_ASSOC);

if (!$disbursement) {
    pop(
        "No disbursement authorization found. Funds must be verified first.",
        "/petty_cash/view.php?request_id={$request_id}",
        2000,
        "error"
    );
    exit;
}

/* ============================================================
   VALIDATE REQUEST STATUS
   ============================================================ */
$currentStatus = strtoupper($request['status']);
if (!in_array($currentStatus, ['FUNDS_VERIFIED', 'FINANCE_AUTHORIZED'])) {
    pop(
        "This request cannot be disbursed. Current status: {$currentStatus}",
        "/petty_cash/view.php?request_id={$request_id}",
        2000,
        "error"
    );
    exit;
}

/* ============================================================
   PROCESS DISBURSEMENT
   ============================================================ */
try {
    $pdo->beginTransaction();

    // Update request status to DISBURSED
    $newStatus = 'DISBURSED';
    $updateRequest = $pdo->prepare("
        UPDATE procurement_requests
        SET status = ?,
            updated_at = NOW()
        WHERE request_id = ?
    ");
    $updateRequest->execute([$newStatus, $request_id]);

    // Update disbursement record status
    $updateDisb = $pdo->prepare("
        UPDATE petty_cash_disbursements
        SET status = 'DISBURSED',
            updated_at = NOW()
        WHERE disburse_id = ?
    ");
    $updateDisb->execute([(int)$disbursement['disburse_id']]);

    // Audit logging
    logAudit(
        $pdo,
        'petty_cash_disbursements',
        (int)$disbursement['disburse_id'],
        'DISBURSAL',
        "Cash disbursed by {$userRole}" . ($disbursal_notes !== '' ? ": " . $disbursal_notes : "")
    );

    logAudit(
        $pdo,
        'procurement_requests',
        $request_id,
        'STATUS_CHANGE',
        "Petty Cash Request: {$currentStatus} → {$newStatus} (Cash disbursed by Finance Officer)"
    );

    logRequestTimeline(
        $pdo,
        $request_id,
        'DISBURSAL_COMPLETED',
        "Cash disbursed by " . ($_SESSION['full_name'] ?? 'Finance Officer') . 
        ($disbursal_notes !== '' ? ": " . $disbursal_notes : "")
    );

    $pdo->commit();

    pop(
        "Petty cash successfully disbursed. Requestor notified to submit reconciliation.",
        "/petty_cash/view.php?request_id={$request_id}",
        1500,
        "success"
    );

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Petty cash disbursement error: " . $e->getMessage());
    pop(
        "Error processing disbursement: " . extractDbMessage($e),
        "/petty_cash/view.php?request_id={$request_id}",
        2000,
        "error"
    );
}
?>
