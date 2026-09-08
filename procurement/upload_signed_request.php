<?php
/**
 * Upload Signed Procurement Request
 * Handles secure file upload for signed procurement approval forms
 */

// Baseline guard mirrors the view page; SignedRequestService enforces
// creator-or-upload-permission authorization for the actual upload.
$REQUIRE_PERMISSION = 'view_requests';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/SignedRequestService.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/SignedRequestNoticeService.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/workflow.php';

$isAjax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
    || str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isAjax) {
        header('Content-Type: application/json');
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }
    header('Location: /procurement/list.php');
    exit;
}

if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
    if ($isAjax) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid request (CSRF check failed).']);
        exit;
    }
    pop('Invalid request (CSRF check failed).', '/procurement/list.php', 2500, 'error');
    exit;
}

$request_id = (int)($_POST['request_id'] ?? 0);
if ($request_id <= 0) {
    if ($isAjax) {
        header('Content-Type: application/json');
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Invalid request.']);
        exit;
    }
    pop('Invalid request.', '/procurement/list.php', 2500, 'error');
    exit;
}

SignedRequestNoticeService::seedDefaultSettings($pdo);
$uploadNoticeEnabled = SignedRequestNoticeService::isSubmitToProcurementConfirmationEnabled($pdo);
if ($uploadNoticeEnabled) {
    $acknowledged = (string)($_POST['signed_notice_upload_ack'] ?? '0') === '1';
    if (!$acknowledged) {
        if ($isAjax) {
            header('Content-Type: application/json');
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Confirmation Required']);
            exit;
        }
        modalPop(
            'Confirmation Required',
            'Please confirm that the original signed document will be submitted to Procurement first. Procurement will copy and forward the document to Finance.',
            '/procurement/view.php?id=' . $request_id,
            'warning'
        );
        exit;
    }

    $actionToken = trim((string)($_POST['signed_notice_action_token'] ?? ''));
    SignedRequestNoticeService::logEvent(
        $pdo,
        $request_id,
        'REGULAR',
        'UPLOAD',
        'DISPLAYED',
        (int)($_SESSION['user_id'] ?? 0),
        (string)($_SESSION['full_name'] ?? ''),
        $actionToken,
        'Upload reminder displayed prior to finalization'
    );
    SignedRequestNoticeService::logEvent(
        $pdo,
        $request_id,
        'REGULAR',
        'UPLOAD',
        'ACKNOWLEDGED',
        (int)($_SESSION['user_id'] ?? 0),
        (string)($_SESSION['full_name'] ?? ''),
        $actionToken,
        'Upload reminder acknowledged by user'
    );
}

if (!isset($_FILES['signed_request_file']) || $_FILES['signed_request_file']['error'] === UPLOAD_ERR_NO_FILE) {
    if ($isAjax) {
        header('Content-Type: application/json');
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'No file provided']);
        exit;
    }
    pop('No file provided', '/procurement/view.php?id=' . $request_id, 2500, 'error');
    exit;
}

$service = new SignedRequestService($pdo);
$result = $service->uploadDocument(
    $request_id,
    'REGULAR',
    $_FILES['signed_request_file'],
    (int)($_SESSION['user_id'] ?? 0)
);

if (!$result['success']) {
    error_log('Signed request upload failed in controller request_id=' . $request_id . ' message=' . ($result['message'] ?? 'unknown'));
    if ($isAjax) {
        header('Content-Type: application/json');
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => (string)$result['message']]);
        exit;
    }
    pop($result['message'], '/procurement/view.php?id=' . $request_id, 3000, 'error');
    exit;
}

try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/config/notifications.php';

    $stmt = $pdo->prepare("
        SELECT request_number
        FROM procurement_requests
        WHERE request_id = ? AND request_type = 'REGULAR'
    ");
    $stmt->execute([$request_id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($request && function_exists('notifySignedRequestReceived')) {
        notifySignedRequestReceived($request_id, $request['request_number']);
    }
} catch (Exception $e) {
    error_log('Warning: Failed to send notification for signed request ' . $request_id . ': ' . $e->getMessage());
}

if ($isAjax) {
    $stateStmt = $pdo->prepare("
        SELECT pr.request_id, pr.status, pr.estimated_value, pr.branch_id, pr.signed_request_document_path, pr.signed_request_received_date
        FROM procurement_requests pr
        WHERE pr.request_id = ? AND pr.request_type = 'REGULAR'
        LIMIT 1
    ");
    $stateStmt->execute([$request_id]);
    $requestState = $stateStmt->fetch(PDO::FETCH_ASSOC);

    $pendingStmt = $pdo->prepare("
        SELECT id, role
        FROM request_approvals
        WHERE request_id = ? AND status = 'pending'
        ORDER BY stage_order ASC, id ASC
        LIMIT 1
    ");
    $pendingStmt->execute([$request_id]);
    $pending = $pendingStmt->fetch(PDO::FETCH_ASSOC);

    $nextRole = (string)($pending['role'] ?? '');
    if ($nextRole === 'Government Chemist') {
        $nextRole = 'Deputy Government Chemist';
    }

    $estimatedValue = (float)($requestState['estimated_value'] ?? 0);
    $sessionRole = (string)($_SESSION['role_name'] ?? '');
    $userCanApprove = $nextRole !== '' && hasPermission('approve_request') && canApproveStage($sessionRole, $nextRole, $estimatedValue);
    $roleEndpointMap = [
        'HOD' => '/procurement/approve_hod.php',
        'Director HRM&A' => '/procurement/approve.php',
        'Deputy Government Chemist' => '/procurement/gc_approve.php',
        'Finance Officer' => '/procurement/approve_finance.php',
        'Branch Head' => '/procurement/approve_hod.php',
        'Procurement Officer' => '/procurement/approve_finance.php',
    ];
    $approvalEndpoint = $roleEndpointMap[$nextRole] ?? '/procurement/approve.php';
    $approvalLabel = match($nextRole) {
        'HOD' => 'Approve (HOD)',
        'Director HRM&A' => 'Approve (Director)',
        'Deputy Government Chemist' => 'Approve (GC)',
        'Finance Officer' => 'Verify Funds',
        default => 'Approve'
    };
    $approvalIcon = match($nextRole) {
        'HOD' => 'bi-person-check',
        'Director HRM&A' => 'bi-briefcase-fill',
        'Deputy Government Chemist' => 'bi-building-check',
        'Finance Officer' => 'bi-cash-coin',
        'Branch Head' => 'bi-person-check',
        'Procurement Officer' => 'bi-clipboard-check',
        default => 'bi-check-circle'
    };

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Signed request uploaded successfully (Version ' . (int)($result['version'] ?? 1) . ')',
        'version' => (int)($result['version'] ?? 1),
        'signed_request_document_path' => (string)($requestState['signed_request_document_path'] ?? ''),
        'signed_request_received_date' => (string)($requestState['signed_request_received_date'] ?? ''),
        'next_approver_role' => $nextRole,
        'user_can_approve' => $userCanApprove,
        'approval_endpoint' => $approvalEndpoint,
        'approval_label' => $approvalLabel,
        'approval_icon' => $approvalIcon,
        'request_id' => $request_id
    ]);
    exit;
}

pop(
    'Signed request uploaded successfully (Version ' . $result['version'] . ')',
    '/procurement/view.php?id=' . $request_id,
    2500,
    'success'
);
?>
