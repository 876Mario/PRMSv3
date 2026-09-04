<?php
$REQUIRE_PERMISSION = 'approve_request';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';

requirePostRequest('/procurement/list.php');
requireCsrfToken('/procurement/list.php');

$id = (int)($_POST['id'] ?? 0);
$reason = trim($_POST['reason'] ?? ($_POST['rejection_reason'] ?? ''));

if ($id <= 0) {
    modalPop('Invalid Request', 'Invalid request ID.', '/procurement/list.php', 'error');
}

if ($reason === '') {
    modalPop('Reason Required', 'Provide a reason before sending the request back for edit.', '/procurement/view.php?id=' . $id, 'warning');
}

$stmt = $pdo->prepare("
    SELECT pr.*, b.branch_name
    FROM procurement_requests pr
    LEFT JOIN branches b ON pr.branch_id = b.branch_id
    WHERE pr.request_id = ?
");
$stmt->execute([$id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    modalPop('Request Not Found', 'The procurement request could not be found.', '/procurement/list.php', 'error');
}

$currentStatus = strtoupper($request['status'] ?? '');
$currentRole = $_SESSION['role_name'] ?? 'Unknown';
$estimatedValue = (float)($request['estimated_value'] ?? 0);
$auditReason = mb_substr($reason, 0, 500);

if (in_array($currentStatus, ['DRAFT', 'DECLINED', 'COMPLETED'], true)) {
    modalPop('Invalid Action', 'This request cannot be sent back for editing at its current status.', '/procurement/view.php?id=' . $id, 'warning');
}

$pendingStmt = $pdo->prepare("
    SELECT id, role, stage_order
    FROM request_approvals
    WHERE request_id = ?
      AND status = 'pending'
    ORDER BY stage_order ASC
    LIMIT 1
");
$pendingStmt->execute([$id]);
$nextApproval = $pendingStmt->fetch(PDO::FETCH_ASSOC);

$approverSendBackRoles = ['HOD', 'Branch Head', 'Director HRM&A', 'Deputy Government Chemist', 'Government Chemist', 'Admin', 'SuperAdmin'];
$procurementEditableStatuses = [
    'SUBMITTED',
    'HOD_APPROVED',
    'FUNDS_VERIFIED',
    'DIRECTOR_APPROVED',
    'GC_APPROVED',
    'RFQ_LETTER_AVAILABLE',
    'PROCUREMENT_STAGE',
    'EVALUATION_STAGE',
    'QUOTE_REVIEW_PENDING',
    'QUOTE_REQUESTOR_REVIEW_PENDING',
    'QUOTE_REQUESTOR_REVIEW_APPROVED',
    'QUOTE_BRANCH_HEAD_APPROVAL_PENDING',
    'QUOTE_APPROVED',
    'COMMITMENT_DECLINED'
];

$canSendBackAsApprover = $nextApproval
    && in_array($currentRole, $approverSendBackRoles, true)
    && canApproveStage($currentRole, $nextApproval['role'], $estimatedValue);

$canSendBackAsProcurement = in_array($currentRole, ['Procurement Officer', 'Admin', 'SuperAdmin'], true)
    && in_array($currentStatus, $procurementEditableStatuses, true);

if (!$canSendBackAsApprover && !$canSendBackAsProcurement) {
    modalPop('Unauthorized', 'You are not allowed to send this request back for edit.', '/procurement/view.php?id=' . $id, 'error');
}

try {
    $pdo->beginTransaction();

    $pdo->prepare("
        DELETE FROM request_approvals
        WHERE request_id = ?
    ")->execute([$id]);

    $pdo->prepare("
        UPDATE procurement_requests
        SET status = 'DRAFT',
            approved_by = NULL,
            approved_at = NULL,
            decline_reason = ?,
            funds_available = 0,
            finance_reviewed_by = NULL,
            finance_reviewed_at = NULL,
            updated_at = NOW()
        WHERE request_id = ?
    ")->execute([$reason, $id]);

    $notes = 'Returned for edit by ' . ($_SESSION['full_name'] ?? $currentRole) . ' (' . $currentRole . '). Reason: ' . $auditReason;

    logAudit($pdo, 'procurement_requests', $id, 'RETURN_FOR_EDIT', $notes);
    logRequestTimeline($pdo, $id, 'RETURNED_FOR_EDIT', $notes);

    $pdo->commit();

    /* In-app notification to requestor */
    if (!class_exists('NotificationService')) {
        $notificationServicePath = $_SERVER['DOCUMENT_ROOT'].'/services/NotificationService.php';
        if (file_exists($notificationServicePath)) {
            require_once $notificationServicePath;
        } else {
            // Create stub class to prevent fatal errors
            class NotificationService {
                const TYPE_APPROVAL_NEEDED  = 'approval_needed';
                const TYPE_RETURN_CORRECTION= 'return_correction';
                const TYPE_CLARIFICATION    = 'clarification';
                const TYPE_REJECTION        = 'rejection';
                const TYPE_CANCELLATION     = 'cancellation';
                const TYPE_DRAFT_READY      = 'draft_ready';
                const TYPE_SUBMISSION       = 'submission';
                public static function createNotification(int $userId, string $type, array $data): bool { return false; }
                public static function getUnreadCount(int $userId): int { return 0; }
                public static function getNotifications(int $userId, int $limit = 10): array { return []; }
                public static function markAsRead(int $notificationId): bool { return false; }
                public static function deleteNotification(int $notificationId): bool { return false; }
            }
            error_log("Warning: NotificationService.php not found at {$notificationServicePath}. Using stub class.");
        }
    }
    
    if (class_exists('NotificationService')) {
        NotificationService::createNotification((int)$request['created_by'], \NotificationService::TYPE_RETURN_CORRECTION, [
            'title'       => "Request Returned for Edit: {$request['request_number']}",
            'body'        => "Returned by {$currentRole}. Reason: " . mb_substr($reason, 0, 200),
            'request_id'  => $id,
            'request_ref' => $request['request_number'],
            'action_url'  => "/procurement/edit.php?id={$id}",
            'stage'       => $currentStatus,
        ]);
    }

    // Redirect back to wherever the user came from (list or dashboard)
    $returnUrl = $_SESSION['last_list_url'] ?? '/procurement/list.php';
    unset($_SESSION['last_list_url']);

    pop(
        'Request sent back for editing.',
        $returnUrl,
        1500,
        'success'
    );
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Send back for edit failed: ' . $e->getMessage());
    modalPop('Error', 'Unable to send the request back for edit right now.', '/procurement/view.php?id=' . $id, 'error');
}
