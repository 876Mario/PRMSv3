<?php
$REQUIRE_PERMISSION = 'view_requests';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/notifications.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pop('Invalid request method.', '/procurement/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$action = strtolower(trim($_POST['action'] ?? ''));
$reason = trim($_POST['reason'] ?? '');

if ($id <= 0 || !in_array($action, ['pause', 'resume'], true)) {
    pop('Invalid pause/resume request.', '/procurement/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

if ($reason === '') {
    pop('A reason is required.', '/procurement/view.php?id='.$id, POP_DEFAULT_DELAY_MS, 'warning');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM procurement_requests WHERE request_id = ?");
$stmt->execute([$id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    pop('Request not found.', '/procurement/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$role = $_SESSION['role_name'] ?? $_SESSION['role'] ?? '';
$canPauseResume = (
    (int)($request['created_by'] ?? 0) === (int)($_SESSION['user_id'] ?? 0)
    || hasPermission('approve_request')
    || in_array($role, ['Admin', 'SuperAdmin', 'Procurement Officer'], true)
);

if (!$canPauseResume) {
    pop('You are not authorized to pause or resume this procurement.', '/procurement/view.php?id='.$id, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$currentStatus = strtoupper($request['status'] ?? '');

try {
    $pdo->beginTransaction();

    if ($action === 'pause') {
        if (!canTransition($currentStatus, 'PAUSED')) {
            throw new RuntimeException('This procurement cannot be paused from its current status.');
        }

        $pdo->prepare("
            UPDATE procurement_requests
            SET status = 'PAUSED',
                paused_previous_status = ?,
                paused_reason = ?,
                paused_by = ?,
                paused_at = NOW(),
                updated_at = NOW()
            WHERE request_id = ?
        ")->execute([$currentStatus, $reason, $_SESSION['user_id'] ?? null, $id]);

        logAudit($pdo, 'procurement_requests', $id, 'PAUSE', 'Paused from '.$currentStatus.' by '.($_SESSION['full_name'] ?? 'Unknown').'. Reason: '.$reason);
        logRequestTimeline($pdo, $id, 'PAUSED', 'Paused by '.($_SESSION['full_name'] ?? 'Unknown').'. Reason: '.$reason);
        $message = 'Procurement paused.';
    } else {
        if ($currentStatus !== 'PAUSED') {
            throw new RuntimeException('Only paused procurements can be resumed.');
        }

        $resumeStatus = strtoupper($request['paused_previous_status'] ?? '') ?: 'SUBMITTED';
        if (in_array($resumeStatus, ['PAUSED', 'CANCELLED', 'DECLINED', 'COMPLETED'], true)) {
            $resumeStatus = 'SUBMITTED';
        }

        $pdo->prepare("
            UPDATE procurement_requests
            SET status = ?,
                resume_reason = ?,
                resumed_by = ?,
                resumed_at = NOW(),
                updated_at = NOW()
            WHERE request_id = ?
        ")->execute([$resumeStatus, $reason, $_SESSION['user_id'] ?? null, $id]);

        logAudit($pdo, 'procurement_requests', $id, 'RESUME', 'Resumed to '.$resumeStatus.' by '.($_SESSION['full_name'] ?? 'Unknown').'. Reason: '.$reason);
        logRequestTimeline($pdo, $id, 'RESUMED', 'Resumed to '.$resumeStatus.' by '.($_SESSION['full_name'] ?? 'Unknown').'. Reason: '.$reason);
        $message = 'Procurement resumed.';
    }

    $pdo->commit();
    notifyProcurementPauseResume($id, $action, $reason, $_SESSION['full_name'] ?? 'Unknown', $currentStatus);
    pop($message, '/procurement/view.php?id='.$id, POP_DEFAULT_DELAY_MS, 'success');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    pop(extractDbMessage($e), '/procurement/view.php?id='.$id, POP_DEFAULT_DELAY_MS, 'error');
}
