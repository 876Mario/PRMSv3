<?php
$REQUIRE_PERMISSION = 'view_requests';

require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';

requirePostRequest('/procurement/list.php');
requireCsrfToken('/procurement/list.php');

$id = (int)($_POST['request_id'] ?? 0);
if (!$id) {
    pop('Invalid request ID', '/procurement/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM procurement_requests WHERE request_id = ?");
$stmt->execute([$id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    pop('Request not found', '/procurement/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

if (!canCurrentUserAccessRequestRecord($request)) {
    pop('You do not have permission to update this request.', '/procurement/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

enforceTransition($request, 'PROCUREMENT_STAGE');

try {
    $stmt = $pdo->prepare("
        UPDATE procurement_requests
        SET status = 'PROCUREMENT_STAGE'
        WHERE request_id = ?
    ");
    $stmt->execute([$id]);
} catch (Throwable $e) {
    pop(extractDbMessage($e), '/procurement/view.php?id=' . $id, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

header("Location: view.php?id=".$id);
exit;
