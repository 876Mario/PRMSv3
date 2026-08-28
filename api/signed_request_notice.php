<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/SignedRequestNoticeService.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF validation failed']);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthenticated']);
    exit;
}

$requestId = (int)($_POST['request_id'] ?? 0);
$requestType = strtoupper(trim((string)($_POST['request_type'] ?? '')));
$noticeContext = strtoupper(trim((string)($_POST['notice_context'] ?? '')));
$eventType = strtoupper(trim((string)($_POST['event_type'] ?? '')));
$actionToken = trim((string)($_POST['action_token'] ?? ''));
$eventNote = trim((string)($_POST['event_note'] ?? ''));

if ($requestId <= 0 || $requestType === '' || $noticeContext !== 'PRINT' || !in_array($eventType, ['DISPLAYED', 'ACKNOWLEDGED'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
    exit;
}

SignedRequestNoticeService::seedDefaultSettings($pdo);

if (!SignedRequestNoticeService::isPrintNoticeEnabled($pdo)) {
    echo json_encode(['ok' => true, 'skipped' => true]);
    exit;
}

SignedRequestNoticeService::logEvent(
    $pdo,
    $requestId,
    $requestType,
    'PRINT',
    $eventType,
    $userId,
    (string)($_SESSION['full_name'] ?? ''),
    $actionToken,
    $eventNote
);

echo json_encode(['ok' => true]);
