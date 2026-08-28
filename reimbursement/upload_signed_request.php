<?php
/**
 * Reimbursement: Upload Signed Request Document
 * Handles secure file upload for signed reimbursement approval forms
 */

$REQUIRE_PERMISSION = 'upload_signed_reimbursement_document';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/SignedRequestService.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/SignedRequestNoticeService.php';

// Verify CSRF token
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    pop('Invalid request (CSRF check failed)', '/reimbursement/list.php', 2500, 'error');
    exit;
}

$request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
if ($request_id <= 0) {
    pop('Invalid reimbursement request', '/reimbursement/list.php', 2500, 'error');
    exit;
}


SignedRequestNoticeService::seedDefaultSettings($pdo);
$uploadNoticeEnabled = SignedRequestNoticeService::isUploadNoticeEnabled($pdo);
if ($uploadNoticeEnabled) {
    $acknowledged = (string)($_POST['signed_notice_upload_ack'] ?? '0') === '1';
    if (!$acknowledged) {
        modalPop(
            'Confirmation Required',
            'Please confirm that the original signed document will be submitted to Procurement first. Procurement will copy and forward the document to Finance.',
            '/reimbursement/view.php?request_id=' . $request_id,
            'warning'
        );
        exit;
    }

    $actionToken = trim((string)($_POST['signed_notice_action_token'] ?? ''));
    SignedRequestNoticeService::logEvent(
        $pdo,
        $request_id,
        'REIMBURSEMENT',
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
        'REIMBURSEMENT',
        'UPLOAD',
        'ACKNOWLEDGED',
        (int)($_SESSION['user_id'] ?? 0),
        (string)($_SESSION['full_name'] ?? ''),
        $actionToken,
        'Upload reminder acknowledged by user'
    );
}

// Verify file was uploaded
if (!isset($_FILES['signed_request_file']) || $_FILES['signed_request_file']['error'] === UPLOAD_ERR_NO_FILE) {
    pop('No file provided', '/reimbursement/view.php?request_id=' . $request_id, 2500, 'error');
    exit;
}

// Use SignedRequestService for upload
$service = new SignedRequestService($pdo);
$result = $service->uploadDocument(
    $request_id,
    'REIMBURSEMENT',
    $_FILES['signed_request_file'],
    $_SESSION['user_id']
);

if (!$result['success']) {
    pop($result['message'], '/reimbursement/view.php?request_id=' . $request_id, 3000, 'error');
    exit;
}

// Notify relevant roles
try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/config/notifications.php';
    
    // Get request details for notification
    $stmt = $pdo->prepare("
        SELECT r.*, b.branch_name, u.full_name as requestor_name
        FROM procurement_requests r
        LEFT JOIN branches b ON r.branch_id = b.branch_id
        LEFT JOIN users u ON r.created_by = u.user_id
        WHERE r.request_id = ? AND r.request_type = 'REIMBURSEMENT'
    ");
    $stmt->execute([$request_id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($request) {
        notifySignedRequestReceived($request_id, $request['request_number']);
    }
} catch (Exception $e) {
    error_log("Warning: Failed to send notification for signed reimbursement request " . $request_id . ": " . $e->getMessage());
}

// Redirect to view page with success message
pop(
    'Signed reimbursement form uploaded successfully (Version ' . $result['version'] . ')',
    '/reimbursement/view.php?request_id=' . $request_id,
    2500,
    'success'
);
?>
