<?php
/**
 * Confirm Receipt of Reimbursement
 * Requestor confirms they have received the reimbursement payment
 */
$REQUIRE_PERMISSION = 'create_reimbursement_request';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/notifications.php';

$request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
$confirmation_notes = isset($_POST['confirmation_notes']) ? trim($_POST['confirmation_notes']) : '';

// Verify CSRF token
$sessionCsrfToken = $_SESSION['csrf_token'] ?? null;
if (
    $_SERVER['REQUEST_METHOD'] !== 'POST'
    || empty($_POST['csrf_token'])
    || !is_string($sessionCsrfToken)
    || !hash_equals($sessionCsrfToken, (string) $_POST['csrf_token'])
) {
    pop('Invalid request (CSRF check failed)', '/reimbursement/list.php', 2500, 'error');
    exit;
}

if ($request_id <= 0) {
    pop("Invalid reimbursement request reference.", "/reimbursement/list.php");
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
   Authorization Check
================================ */
// Only the requestor can confirm receipt
if ((int)$_SESSION['user_id'] !== (int)$request['created_by']) {
    pop(
        "Only the requestor can confirm receipt of reimbursement.",
        "/reimbursement/view.php?request_id=".$request_id,
        2000,
        "error"
    );
    exit;
}

/* ================================
   Status Validation
================================ */
if (strtoupper($request['status']) !== 'REIMBURSED') {
    pop(
        "This request cannot be completed. Current status: " . $request['status'],
        "/reimbursement/view.php?request_id=".$request_id,
        2000,
        "error"
    );
    exit;
}

try {
    $pdo->beginTransaction();

    $previousStatus = $request['status'];
    $newStatus = 'COMPLETED';

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
    $notes = "Reimbursement receipt confirmed by requestor";
    if (!empty($confirmation_notes)) {
        $notes .= " | Notes: " . $confirmation_notes;
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
       Notify Stakeholders
    ================================ */
    if (function_exists('notifyReimbursementCompleted')) {
        notifyReimbursementCompleted($request_id, $request['request_number']);
    }

    $pdo->commit();

    pop(
        "Reimbursement request completed successfully.",
        "/reimbursement/view.php?request_id=".$request_id,
        3000,
        "success"
    );
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Error confirming reimbursement receipt: " . $e->getMessage());
    pop(
        "Failed to confirm receipt. Please try again.",
        "/reimbursement/view.php?request_id=".$request_id,
        3000,
        "error"
    );
}
