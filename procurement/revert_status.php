<?php
/**
 * Revert workflow status of a procurement request to a prior stage.
 * POST-only. Authorized roles only. Full audit trail.
 */
$REQUIRE_PERMISSION = 'approve_request';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pop('Invalid request method.', '/procurement/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$id         = (int)($_POST['id']         ?? 0);
$targetStatus = strtoupper(trim($_POST['target_status'] ?? ''));
$reason     = mb_substr(trim($_POST['reason'] ?? ''), 0, 1000);

if ($id <= 0) {
    modalPop('Invalid Request', 'Invalid request ID.', '/procurement/list.php', 'error');
    exit;
}
if ($targetStatus === '') {
    modalPop('Missing Status', 'Target status is required.', '/procurement/view.php?id=' . $id, 'error');
    exit;
}
if ($reason === '') {
    modalPop('Reason Required', 'A reason is required when reverting a workflow stage.', '/procurement/view.php?id=' . $id, 'warning');
    exit;
}

// Role check
$currentRole = $_SESSION['role_name'] ?? '';
if (!in_array($currentRole, allowedRevertRoles(), true)) {
    modalPop('Unauthorized', 'You are not authorized to revert workflow stages.', '/procurement/view.php?id=' . $id, 'error');
    exit;
}

// Fetch request
$stmt = $pdo->prepare("SELECT * FROM procurement_requests WHERE request_id = ?");
$stmt->execute([$id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$request) {
    modalPop('Not Found', 'Procurement request not found.', '/procurement/list.php', 'error');
    exit;
}

$currentStatus = strtoupper($request['status'] ?? '');

// Validate it is a backward transition
if (!isBackwardTransition($currentStatus, $targetStatus)) {
    modalPop(
        'Invalid Revert',
        "Cannot revert from {$currentStatus} to {$targetStatus}. Only backward transitions are permitted via this endpoint.",
        '/procurement/view.php?id=' . $id,
        'error'
    );
    exit;
}

// Validate the transition exists in allowedTransitions()
if (!canTransition($currentStatus, $targetStatus)) {
    modalPop(
        'Transition Not Allowed',
        "The transition from {$currentStatus} to {$targetStatus} is not permitted by workflow rules.",
        '/procurement/view.php?id=' . $id,
        'error'
    );
    exit;
}

// Terminal statuses cannot be reverted
if (in_array($currentStatus, ['COMPLETED', 'DECLINED', 'CANCELLED'], true)) {
    modalPop(
        'Cannot Revert',
        'Requests in terminal states (Completed, Declined, Cancelled) cannot be reverted.',
        '/procurement/view.php?id=' . $id,
        'error'
    );
    exit;
}

try {
    $pdo->beginTransaction();

    // Update request status
    $pdo->prepare("
        UPDATE procurement_requests
        SET status     = ?,
            updated_at = NOW()
        WHERE request_id = ?
    ")->execute([$targetStatus, $id]);

    // Remove only the pending approvals for this request.
    // Previously-approved stages are retained in audit_log / workflow_transition_history.
    // The approval chain will be re-seeded by the next approver action if required.
    $pdo->prepare("
        DELETE FROM request_approvals
        WHERE request_id = ?
          AND status = 'pending'
    ")->execute([$id]);

    $actor = $_SESSION['full_name'] ?? $currentRole;
    $notes = "Workflow reverted from {$currentStatus} to {$targetStatus} by {$actor} ({$currentRole}). Reason: {$reason}";

    logAudit($pdo, 'procurement_requests', $id, 'WORKFLOW_REVERT', $notes);
    logRequestTimeline($pdo, $id, 'WORKFLOW_REVERT', $notes);

    // Insert transition history record for audit
    try {
        $pdo->prepare("
            INSERT INTO workflow_transition_history
              (request_id, from_status, to_status, is_backward, actor_user_id, actor_role, reason, created_at)
            VALUES (?, ?, ?, 1, ?, ?, ?, NOW())
        ")->execute([$id, $currentStatus, $targetStatus, $_SESSION['user_id'], $currentRole, $reason]);
    } catch (Throwable $e) {
        // Table may not exist yet — already logged via audit_log; continue.
        error_log('workflow_transition_history insert failed (table may not exist): ' . $e->getMessage());
    }

    $pdo->commit();

    pop(
        "Request reverted to " . str_replace('_', ' ', $targetStatus) . ".",
        "/procurement/view.php?id={$id}",
        1500,
        'success'
    );
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('revert_status.php failed: ' . $e->getMessage());
    modalPop('Error', 'Unable to revert workflow stage. Please try again.', '/procurement/view.php?id=' . $id, 'error');
    exit;
}
