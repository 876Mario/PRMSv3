<?php
/**
 * Revert workflow status of a petty cash request to a prior stage.
 * POST-only. Authorized roles only. Full audit trail.
 * Uses WorkflowService for dynamic revert logic.
 */
$REQUIRE_PERMISSION = 'approve_petty_cash_request';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/services/WorkflowService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pop('Invalid request method.', '/petty_cash/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$id         = (int)($_POST['id']         ?? 0);
$targetStatus = strtoupper(trim($_POST['target_status'] ?? ''));
$reason     = mb_substr(trim($_POST['reason'] ?? ''), 0, 1000);

if ($id <= 0) {
    modalPop('Invalid Request', 'Invalid request ID.', '/petty_cash/list.php', 'error');
    exit;
}
if ($targetStatus === '') {
    modalPop('Missing Status', 'Target status is required.', '/petty_cash/view.php?id=' . $id, 'error');
    exit;
}
if ($reason === '') {
    modalPop('Reason Required', 'A reason is required when reverting a workflow stage.', '/petty_cash/view.php?id=' . $id, 'warning');
    exit;
}

// Fetch request
$stmt = $pdo->prepare("SELECT * FROM procurement_requests WHERE request_id = ? AND request_type = 'PETTY_CASH'");
$stmt->execute([$id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$request) {
    modalPop('Not Found', 'Petty cash request not found.', '/petty_cash/list.php', 'error');
    exit;
}

$currentStatus = strtoupper($request['status'] ?? '');
$requestType = 'PETTY_CASH';
$currentRole = $_SESSION['role_name'] ?? '';
$userId = (int)($_SESSION['user_id'] ?? 0);
$userName = $_SESSION['full_name'] ?? $currentRole;

// Initialize workflow service
$workflowService = new WorkflowService($pdo);

// Role check
if (!$workflowService->canUserRevert($currentRole, $requestType)) {
    modalPop('Unauthorized', 'You are not authorized to revert petty cash workflow stages.', '/petty_cash/view.php?id=' . $id, 'error');
    exit;
}

// Terminal statuses cannot be reverted
if (in_array($currentStatus, ['COMPLETED', 'DECLINED'], true)) {
    modalPop(
        'Cannot Revert',
        'Requests in terminal states (Completed, Declined) cannot be reverted.',
        '/petty_cash/view.php?id=' . $id,
        'error'
    );
    exit;
}

// Validate it is a backward transition for this request type
if (!$workflowService->isBackwardTransition($requestType, $currentStatus, $targetStatus)) {
    modalPop(
        'Invalid Revert',
        "Cannot revert from {$currentStatus} to {$targetStatus}. Only backward transitions are permitted for petty cash requests.",
        '/petty_cash/view.php?id=' . $id,
        'error'
    );
    exit;
}

// Validate the transition exists in the workflow configuration
$transitions = $workflowService->getTransitionsForType($requestType);
if (!in_array($targetStatus, $transitions[$currentStatus] ?? [])) {
    modalPop(
        'Transition Not Allowed',
        "The transition from {$currentStatus} to {$targetStatus} is not permitted by petty cash workflow rules.",
        '/petty_cash/view.php?id=' . $id,
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

    // Send notification to requestor
    if (!class_exists('NotificationService')) {
        require_once $_SERVER['DOCUMENT_ROOT'].'/services/NotificationService.php';
    }
    
    if (class_exists('NotificationService')) {
        NotificationService::createNotification(
            (int)$request['created_by'],
            NotificationService::TYPE_RETURN_CORRECTION,
            [
                'title'       => "Petty Cash Returned: {$request['request_number']}",
                'body'        => "Returned to " . str_replace('_', ' ', $targetStatus) . " by {$currentRole}. Reason: " . mb_substr($reason, 0, 200),
                'request_id'  => $id,
                'request_ref' => $request['request_number'],
                'action_url'  => "/petty_cash/view.php?id={$id}",
                'stage'       => $targetStatus,
            ]
        );
    }

    pop(
        "Petty cash request reverted to " . str_replace('_', ' ', $targetStatus) . ".",
        "/petty_cash/view.php?id={$id}",
        1500,
        'success'
    );
    exit;

} catch (Throwable $e) {
    error_log('petty_cash/revert_status.php failed: ' . $e->getMessage());
    modalPop('Error', 'Unable to revert workflow stage right now.', '/petty_cash/view.php?id=' . $id, 'error');
    exit;
}
