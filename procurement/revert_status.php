<?php
/**
 * Revert workflow status of a procurement request to a prior stage.
 * POST-only. Authorized roles only. Full audit trail.
 * Uses WorkflowService for dynamic, request-type-aware revert logic.
 */
$REQUIRE_PERMISSION = 'approve_request';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/services/WorkflowService.php';

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

// Fetch request
$stmt = $pdo->prepare("SELECT * FROM procurement_requests WHERE request_id = ?");
$stmt->execute([$id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$request) {
    modalPop('Not Found', 'Procurement request not found.', '/procurement/list.php', 'error');
    exit;
}

$currentStatus = strtoupper($request['status'] ?? '');
$requestType = $request['request_type'] ?? 'REGULAR';
$currentRole = $_SESSION['role_name'] ?? '';
$userId = (int)($_SESSION['user_id'] ?? 0);
$userName = $_SESSION['full_name'] ?? $currentRole;

// Initialize workflow service
$workflowService = new WorkflowService($pdo);

// Role check
if (!$workflowService->canUserRevert($currentRole, $requestType)) {
    modalPop('Unauthorized', 'You are not authorized to revert workflow stages.', '/procurement/view.php?id=' . $id, 'error');
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

// Validate it is a backward transition for this request type
if (!$workflowService->isBackwardTransition($requestType, $currentStatus, $targetStatus)) {
    modalPop(
        'Invalid Revert',
        "Cannot revert from {$currentStatus} to {$targetStatus}. Only backward transitions are permitted for {$requestType} requests.",
        '/procurement/view.php?id=' . $id,
        'error'
    );
    exit;
}

// Validate the transition exists in the workflow configuration
$transitions = $workflowService->getTransitionsForType($requestType);
if (!in_array($targetStatus, $transitions[$currentStatus] ?? [])) {
    modalPop(
        'Transition Not Allowed',
        "The transition from {$currentStatus} to {$targetStatus} is not permitted by {$requestType} workflow rules.",
        '/procurement/view.php?id=' . $id,
        'error'
    );
    exit;
}

try {
    // Use the workflow service to execute the revert with full audit trail
    $workflowService->executeRevert(
        $id,
        $requestType,
        $currentStatus,
        $targetStatus,
        $reason,
        $userId,
        $currentRole,
        $userName
    );

    pop(
        "Request reverted to " . str_replace('_', ' ', $targetStatus) . ".",
        "/procurement/view.php?id={$id}",
        1500,
        'success'
    );
    exit;

} catch (Throwable $e) {
    error_log('revert_status.php failed: ' . $e->getMessage());
    modalPop('Error', 'Unable to revert workflow stage: ' . $e->getMessage(), '/procurement/view.php?id=' . $id, 'error');
    exit;
}
