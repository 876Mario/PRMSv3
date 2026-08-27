<?php
/**
 * Mark Reimbursement as Paid/Disbursed
 * Finance Officer marks that payment has been made to the requestor
 */
$REQUIRE_PERMISSION = 'approve_reimbursement_request';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/notifications.php';

$request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
$payment_reference = isset($_POST['payment_reference']) ? trim($_POST['payment_reference']) : '';
$payment_notes = isset($_POST['payment_notes']) ? trim($_POST['payment_notes']) : '';

if ($request_id <= 0) {
    pop("Invalid reimbursement request reference.", "/reimbursement/list.php");
    exit;
}

/* ================================
   Authorize Finance Officer
================================ */
$userRole = $_SESSION['role_name'] ?? '';
if ($userRole !== 'Finance Officer') {
    pop(
        "Only Finance Officers can mark reimbursements as paid.",
        "/reimbursement/view.php?request_id=".$request_id,
        2000,
        "error"
    );
    exit;
}

/* ================================
   Fetch Request
================================ */
$stmt = $pdo->prepare("
    SELECT r.*, u.full_name as requestor_name, u.email as requestor_email
    FROM procurement_requests r
    LEFT JOIN users u ON r.created_by = u.user_id
    WHERE r.request_id = ? AND r.request_type = 'REIMBURSEMENT'
    LIMIT 1
");
$stmt->execute([$request_id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    pop("Reimbursement request not found.", "/reimbursement/list.php");
    exit;
}

/* ================================
   Status Validation
================================ */
if (strtoupper($request['status']) !== 'APPROVED') {
    pop(
        "This request cannot be marked as reimbursed. Current status: " . $request['status'],
        "/reimbursement/view.php?request_id=".$request_id,
        2000,
        "error"
    );
    exit;
}

try {
    $pdo->beginTransaction();

    $previousStatus = $request['status'];
    $newStatus = 'REIMBURSED';

    /* ================================
       Update Request Status
    ================================ */
    $update = $pdo->prepare("
        UPDATE procurement_requests
        SET status = ?,
            updated_at = NOW()
        WHERE request_id = ?
    ");
    $update->execute([$newStatus, $request_id]);

    /* ================================
       Record Status History
    ================================ */
    $notes = "Payment disbursed by Finance Officer";
    if (!empty($payment_reference)) {
        $notes .= " | Reference: " . $payment_reference;
    }
    if (!empty($payment_notes)) {
        $notes .= " | Notes: " . $payment_notes;
    }

    $historyStmt = $pdo->prepare("
        INSERT INTO reimbursement_status_history
        (request_id, old_status, new_status, changed_by, change_notes)
        VALUES (?, ?, ?, ?, ?)
    ");
    $historyStmt->execute([
        $request_id,
        $previousStatus,
        $newStatus,
        $_SESSION['user_id'],
        $notes
    ]);

    /* ================================
       Notify Requestor
    ================================ */
    if (function_exists('notifyReimbursementDisbursed')) {
        notifyReimbursementDisbursed($request_id, $request['request_number']);
    }

    $pdo->commit();

    pop(
        "Reimbursement marked as paid. The requestor will be notified to confirm receipt.",
        "/reimbursement/view.php?request_id=".$request_id,
        3000,
        "success"
    );
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Error marking reimbursement as paid: " . $e->getMessage());
    pop(
        "Failed to mark reimbursement as paid. Please try again.",
        "/reimbursement/view.php?request_id=".$request_id,
        3000,
        "error"
    );
}
