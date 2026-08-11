<?php
$REQUIRE_PERMISSION = 'approve_request';
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/page_guard.php";
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/helper.php";
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/notifications.php";

$id = $_POST['id'] ?? null;
$reason = trim($_POST['reason'] ?? '');

if (!$id || !is_numeric($id)) {
    $_SESSION['error'] = "Invalid request.";
    header("Location: /procurement/list.php");
    exit;
}

if ($reason === '') {
    $_SESSION['error'] = "Decline reason is required.";
    header("Location: /procurement/view.php?id=" . $id);
    exit;
}

/* Fetch request */
$stmt = $pdo->prepare("
    SELECT request_id, status, created_by, request_number, estimated_value, request_type, branch_id
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

/* Rules */
if (strtoupper($request['status']) !== 'SUBMITTED') {
    $_SESSION['error'] = "Only submitted requests can be declined.";
    header("Location: /procurement/view.php?id=" . $id);
    exit;
}

if ($request['created_by'] == $_SESSION['user_id']) {
    $_SESSION['error'] = "You cannot decline your own request.";
    header("Location: /procurement/view.php?id=" . $id);
    exit;
}

try {
    $pdo->beginTransaction();

    /* Decline */
    $update = $pdo->prepare("
        UPDATE procurement_requests
        SET status = 'DECLINED',
            approved_by = ?,
            approved_at = NOW(),
            decline_reason = ?
        WHERE request_id = ?
    ");
    $update->execute([
        $_SESSION['user_id'],
        $reason,
        $id
    ]);

    /* Clean up approval chain for this declined request */
    $pdo->prepare("
        DELETE FROM request_approvals
        WHERE request_id = ?
    ")->execute([$id]);

    /* Audit log */
    logAudit(
        $pdo,
        'procurement_requests',
        $id,
        'STATUS_CHANGE',
        'Submitted → Declined by ' . ($_SESSION['full_name'] ?? 'Unknown')
    );

    logRequestTimeline(
        $pdo,
        $id,
        'DECLINED',
        'Request declined: ' . $reason . ' — by ' . ($_SESSION['full_name'] ?? 'Unknown')
    );

    /* Send notification to requestor about decline */
    $requestorStmt = $pdo->prepare('
        SELECT user_id, email
        FROM users
        WHERE user_id = ? AND is_active = 1
    ');
    $requestorStmt->execute([$request['created_by']]);
    $requestor = $requestorStmt->fetch(PDO::FETCH_ASSOC);

    if ($requestor) {
        // Notify requestor about decline
        notifyRequestDeclined(
            $id,
            $requestor['user_id'],
            $reason
        );
    }

    /* Send notification to procurement officers that request has been declined */
    notifyProcurementOfDecline($id, $reason);

    $pdo->commit();

    $_SESSION['success'] = "Procurement request declined and requestor notified.";
    header("Location: /procurement/list.php");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    $_SESSION['error'] = "Error declining request: " . extractDbMessage($e);
    header("Location: /procurement/view.php?id=" . $id);
    exit;
}
?>