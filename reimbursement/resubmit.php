<?php
$REQUIRE_PERMISSION = 'submit_request';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';

requirePostRequest('/reimbursement/list.php');
requireCsrfToken('/reimbursement/list.php');

$id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;

try {
    if ($id <= 0) {
        throw new Exception("Invalid request.");
    }

    $stmt = $pdo->prepare("
        SELECT request_id, request_type, created_by, status
        FROM procurement_requests
        WHERE request_id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request || strtoupper($request['request_type'] ?? '') !== 'REIMBURSEMENT') {
        throw new Exception("Reimbursement request not found.");
    }

    if (
        (int)$request['created_by'] !== (int)($_SESSION['user_id'] ?? 0)
        && !hasPermission('admin_override')
    ) {
        throw new Exception("You are not allowed to resubmit this request.");
    }

    if (strtoupper($request['status']) !== 'DECLINED') {
        throw new Exception("Only declined requests can be resubmitted.");
    }

    $pdo->beginTransaction();

    $pdo->prepare("
        UPDATE request_approvals
        SET status = CASE WHEN status = 'pending' THEN 'rejected' ELSE status END,
            comments = CASE
                WHEN status = 'pending' THEN CONCAT(COALESCE(NULLIF(comments, ''), 'No comments'), ' [Workflow reset after decline resubmission]')
                ELSE comments
            END,
            approved_by = CASE WHEN status = 'pending' THEN COALESCE(approved_by, ?) ELSE approved_by END,
            approved_at = CASE WHEN status = 'pending' THEN COALESCE(approved_at, NOW()) ELSE approved_at END
        WHERE request_id = ?
    ")->execute([$_SESSION['user_id'], $id]);

    $pdo->prepare("
        UPDATE procurement_requests
        SET status = 'DRAFT',
            approved_by = NULL,
            approved_at = NULL,
            decline_reason = NULL,
            updated_at = NOW()
        WHERE request_id = ?
    ")->execute([$id]);

    $pdo->prepare("
        INSERT INTO reimbursement_status_history (request_id, old_status, new_status, changed_by, change_notes)
        VALUES (?, 'DECLINED', 'DRAFT', ?, ?)
    ")->execute([
        $id,
        (int)($_SESSION['user_id'] ?? 0),
        'Resubmitted after decline by ' . ($_SESSION['full_name'] ?? 'Unknown')
    ]);

    logAudit(
        $pdo,
        'procurement_requests',
        $id,
        'STATUS_CHANGE',
        'Reimbursement Declined → Draft (Resubmitted by ' . ($_SESSION['full_name'] ?? 'Unknown') . ')'
    );

    logRequestTimeline(
        $pdo,
        $id,
        'RESUBMITTED',
        'Reimbursement request resubmitted after decline by ' . ($_SESSION['full_name'] ?? 'Unknown')
    );

    $pdo->commit();

    require_once $_SERVER['DOCUMENT_ROOT'].'/config/notifications.php';
    notifyRequestResubmitted($id);

    pop(
        "Reimbursement request reset to Draft. You may edit and submit again.",
        "/reimbursement/edit.php?id=" . $id,
        2000,
        "success"
    );
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    pop(
        "Error: " . extractDbMessage($e),
        "/reimbursement/view.php?request_id=" . $id,
        2500,
        "error"
    );
    exit;
}
?>
