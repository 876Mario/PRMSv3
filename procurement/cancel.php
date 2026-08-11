<?php
/**
 * Cancel Procurement Request
 * ==========================
 * Allows requests to be cancelled at any stage of the workflow.
 * Cancellation requires a mandatory reason, which is stored in the
 * audit trail/history, and relevant stakeholders are notified.
 */
$REQUIRE_PERMISSION = 'view_requests';
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/page_guard.php";
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/helper.php";
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/workflow.php";
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/notifications.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /procurement/list.php");
    exit;
}

$id = $_POST['id'] ?? null;
$reason = trim($_POST['reason'] ?? '');

if (!$id || !is_numeric($id)) {
    $_SESSION['error'] = "Invalid request.";
    header("Location: /procurement/list.php");
    exit;
}

if ($reason === '') {
    $_SESSION['error'] = "Cancellation reason is required.";
    header("Location: /procurement/view.php?id=" . $id);
    exit;
}

/* Fetch request */
$stmt = $pdo->prepare("
    SELECT request_id, status, created_by, request_number, request_type, branch_id
    FROM procurement_requests
    WHERE request_id = ?
");
$stmt->execute([$id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    $_SESSION['error'] = "Request not found.";
    header("Location: /procurement/list.php");
    exit;
}

/* Cancellation allowed from any non-terminal stage */
if (!canTransition($request['status'], 'CANCELLED')) {
    $_SESSION['error'] = "This request can no longer be cancelled (status: " . $request['status'] . ").";
    header("Location: /procurement/view.php?id=" . $id);
    exit;
}

/* Only the requestor, an approver, or an admin can cancel */
$isRequestor = ($request['created_by'] == $_SESSION['user_id']);
$canCancelRole = hasPermission('approve_request') || in_array($_SESSION['role_name'] ?? '', ['Admin', 'SuperAdmin', 'Procurement Officer'], true);
if (!$isRequestor && !$canCancelRole) {
    $_SESSION['error'] = "You don't have permission to cancel this request.";
    header("Location: /procurement/view.php?id=" . $id);
    exit;
}

try {
    $pdo->beginTransaction();

    $previousStatus = $request['status'];

    /* Cancel */
    $update = $pdo->prepare("
        UPDATE procurement_requests
        SET status = 'CANCELLED',
            cancel_reason = ?,
            cancelled_by = ?,
            cancelled_at = NOW()
        WHERE request_id = ?
    ");
    $update->execute([
        $reason,
        $_SESSION['user_id'],
        $id
    ]);

    /* Remove any remaining pending approvals */
    $pdo->prepare("
        DELETE FROM request_approvals
        WHERE request_id = ?
          AND status = 'pending'
    ")->execute([$id]);

    /* Audit log — cancellation reason stored in trail/history */
    logAudit(
        $pdo,
        'procurement_requests',
        $id,
        'STATUS_CHANGE',
        $previousStatus . ' → Cancelled by ' . ($_SESSION['full_name'] ?? 'Unknown') . ' — Reason: ' . $reason
    );

    logRequestTimeline(
        $pdo,
        $id,
        'CANCELLED',
        'Request cancelled: ' . $reason . ' — by ' . ($_SESSION['full_name'] ?? 'Unknown')
    );

    $pdo->commit();

    /* Notify relevant stakeholders of the cancellation and reason */
    notifyRequestCancelled((int)$id, $reason, $previousStatus);

    $_SESSION['success'] = "Request cancelled and stakeholders notified.";
    header("Location: /procurement/list.php");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['error'] = "Error cancelling request: " . extractDbMessage($e);
    header("Location: /procurement/view.php?id=" . $id);
    exit;
}