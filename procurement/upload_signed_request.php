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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /procurement/list.php');
    exit;
}

if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
    pop('Invalid request (CSRF check failed).', '/procurement/list.php', 2500, 'error');
    exit;
}

$request_id = (int)($_POST['request_id'] ?? 0);
if ($request_id <= 0) {
    pop('Invalid request.', '/procurement/list.php', 2500, 'error');
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

pop(
    'Signed request uploaded successfully (Version ' . $result['version'] . ')',
    '/procurement/view.php?id=' . $request_id,
    2500,
    'success'
);
?>
